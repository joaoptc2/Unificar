<?php
class RequestController
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }
    public function index(): void
    {
        core_require('requests.view');
        $status = Sanitize::get('status');
        $conds = []; $params = [];
        if ($status) { $conds[] = 'r.status = ?'; $params[] = $status; }
        // Quem não pode responder (ex.: funcionário) vê apenas as próprias solicitações
        if (!core_can('requests.respond')) {
            $stmt = $this->db->prepare('SELECT employee_id FROM rh_user_profile WHERE user_id = ?');
            $stmt->execute([Session::userId()]);
            $conds[] = 'r.employee_id = ?';
            $params[] = (int) $stmt->fetchColumn();
        }
        $wc = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
        $stmt = $this->db->prepare("SELECT r.*, e.full_name AS employee_name FROM rh_requests r JOIN rh_employees e ON r.employee_id = e.id $wc ORDER BY r.created_at DESC LIMIT 100");
        $stmt->execute($params);
        View::render('requests/index', ['pageTitle' => 'Solicitacoes', 'page' => 'requests', 'items' => $stmt->fetchAll(), 'status' => $status]);
    }
    public function respond(): void
    {
        core_require('requests.respond'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0); $status = Sanitize::post('status'); $response = Sanitize::post('response');
        $this->db->prepare('UPDATE rh_requests SET status = ?, response = ?, responded_by = ?, responded_at = NOW() WHERE id = ?')
                 ->execute([$status, $response, Session::userId(), $id]);
        AuditLog::log('respond', 'requests', $id);
        Session::flash('success', 'Solicitacao respondida.'); header('Location: index.php?m=rh&page=requests'); exit;
    }
    // Portal do funcionario
    public function create(): void
    {
        core_require('requests.create');
        View::renderRaw('requests/create', ['pageTitle' => 'Nova Solicitacao']);
    }
    public function store(): void
    {
        core_require('requests.create'); Csrf::check();
        $userId = Session::userId();
        $stmt = $this->db->prepare('SELECT employee_id FROM rh_user_profile WHERE user_id = ?'); $stmt->execute([$userId]);
        $empId = (int)$stmt->fetchColumn();
        if (!$empId) { Session::flash('error', 'Sem vinculo.'); header('Location: index.php?m=rh&page=my'); exit; }
        EmployeeRequest::insert([
            'employee_id' => $empId, 'type' => Sanitize::post('type') ?: 'outro',
            'subject' => Sanitize::post('subject'), 'body' => Sanitize::post('body'),
        ]);
        AuditLog::log('create', 'requests', (int)$this->db->lastInsertId());
        Session::flash('success', 'Solicitacao enviada.'); header('Location: index.php?m=rh&page=my'); exit;
    }
}
