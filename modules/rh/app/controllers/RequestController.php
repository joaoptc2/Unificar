<?php
/**
 * RequestController — solicitações dos funcionários.
 *
 *   page=requests                      lista (requests.view; quem não responde vê só as próprias)
 *   page=requests&action=respond       POST aprovar/rejeitar (requests.respond) — férias sincronizam rh_vacations
 *   page=requests&action=create|store  nova solicitação pelo portal (requests.create)
 */
class RequestController
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function index(): void
    {
        core_require('requests.view');
        $status = Sanitize::get('status');
        $type   = Sanitize::get('type');
        $conds = []; $params = [];
        if ($status) { $conds[] = 'r.status = ?'; $params[] = $status; }
        if ($type)   { $conds[] = 'r.type = ?';   $params[] = $type; }
        // Quem não pode responder (ex.: funcionário) vê apenas as próprias solicitações
        if (!core_can('requests.respond')) {
            $conds[] = 'r.employee_id = ?';
            $params[] = EmployeeAccess::employeeIdOf(Session::userId());
        }
        $wc = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
        $stmt = $this->db->prepare(
            "SELECT r.*, e.full_name AS employee_name, d.name AS department_name, u.name AS responded_by_name,
                    v.start_date AS vac_start, v.end_date AS vac_end, v.days AS vac_days, v.sold_days AS vac_sold,
                    v.period_start AS vac_pstart, v.period_end AS vac_pend, v.status AS vac_status
             FROM rh_requests r
             JOIN rh_employees e ON r.employee_id = e.id
             LEFT JOIN rh_departments d ON d.id = e.department_id
             LEFT JOIN users u ON u.id = r.responded_by
             LEFT JOIN rh_vacations v ON v.id = r.vacation_id
             $wc ORDER BY FIELD(r.status,'pendente','em_analise','aprovada','rejeitada'), r.created_at DESC LIMIT 200"
        );
        $stmt->execute($params);
        View::render('requests/index', ['pageTitle' => 'Solicitações', 'page' => 'requests', 'items' => $stmt->fetchAll(), 'status' => $status, 'type' => $type]);
    }

    public function respond(): void
    {
        core_require('requests.respond'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $status = Sanitize::post('status');
        $response = mb_substr(Sanitize::post('response'), 0, 2000);
        if (!in_array($status, ['aprovada', 'rejeitada', 'em_analise'], true)) { $status = 'em_analise'; }

        $req = EmployeeRequest::find($id);
        if (!$req) { Session::flash('error', 'Solicitação não encontrada.'); header('Location: index.php?m=rh&page=requests'); exit; }

        if (!empty($req['vacation_id']) && $status !== 'em_analise') {
            // Férias: decide nas duas tabelas (rh_vacations + rh_requests) e notifica o funcionário.
            Vacation::decide((int)$req['vacation_id'], $status, Session::userId(), $response);
            $this->db->prepare('UPDATE rh_requests SET status = ?, response = ?, responded_by = ?, responded_at = NOW() WHERE id = ?')
                     ->execute([$status, $response ?: null, Session::userId(), $id]);
        } else {
            $this->db->prepare('UPDATE rh_requests SET status = ?, response = ?, responded_by = ?, responded_at = NOW() WHERE id = ?')
                     ->execute([$status, $response ?: null, Session::userId(), $id]);
            $userId = (int)($req['requested_by'] ?? 0);
            if (!$userId) {
                $st = $this->db->prepare('SELECT user_id FROM rh_user_profile WHERE employee_id = ?');
                $st->execute([(int)$req['employee_id']]);
                $userId = (int)($st->fetchColumn() ?: 0);
            }
            if ($userId) {
                Core\Notifications::add($userId, 'Solicitação ' . str_replace('_', ' ', $status) . ': ' . $req['subject'],
                    $response !== '' ? $response : 'O RH respondeu à sua solicitação.',
                    'index.php?m=rh&page=my#solicitacoes', $status === 'aprovada' ? 'success' : ($status === 'rejeitada' ? 'warning' : 'info'), 'rh');
            }
        }
        AuditLog::log('respond', 'requests', $id, null, ['status' => $status]);
        Session::flash('success', 'Solicitação ' . str_replace('_', ' ', $status) . '.');
        header('Location: index.php?m=rh&page=requests'); exit;
    }

    // Portal do funcionário
    public function create(): void
    {
        core_require('requests.create');
        View::renderRaw('requests/create', ['pageTitle' => 'Nova Solicitação', 'hospitalName' => Core\Settings::get('org_name', 'Portal')]);
    }

    public function store(): void
    {
        core_require('requests.create'); Csrf::check();
        $userId = (int)Session::userId();
        $empId  = EmployeeAccess::employeeIdOf($userId);
        if (!$empId) { Session::flash('error', 'Seu usuário não está vinculado a um funcionário.'); header('Location: index.php?m=rh&page=my'); exit; }
        $type = Sanitize::post('type');
        if (!isset(EmployeeRequest::TYPES[$type]) || $type === 'ferias') { $type = 'outro'; }
        $subject = mb_substr(Sanitize::post('subject'), 0, 200);
        if ($subject === '') { Session::flash('error', 'Informe o assunto da solicitação.'); header('Location: index.php?m=rh&page=my#solicitacoes'); exit; }
        $id = EmployeeRequest::insert([
            'employee_id' => $empId, 'type' => $type, 'subject' => $subject,
            'body' => mb_substr(Sanitize::post('body'), 0, 4000) ?: null, 'requested_by' => $userId, 'status' => 'pendente',
        ]);
        AuditLog::log('create', 'requests', $id);
        $emp = Employee::find($empId);
        EmployeeRequest::notifyResponders($id, (string)($emp['full_name'] ?? 'Funcionário'), $subject);
        Session::flash('success', 'Solicitação enviada ao RH.'); header('Location: index.php?m=rh&page=my#solicitacoes'); exit;
    }
}
