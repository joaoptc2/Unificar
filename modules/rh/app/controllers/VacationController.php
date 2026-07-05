<?php
class VacationController
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function index(): void
    {
        Auth::requirePermission('vacations', 'view');
        $status = Sanitize::get('status');
        $currentPage = max(1, Sanitize::int($_GET['p'] ?? 1));
        $where = []; $params = [];
        if ($status) { $where[] = 'v.status = ?'; $params[] = $status; }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int)$this->db->prepare("SELECT COUNT(*) FROM rh_vacations v JOIN rh_employees e ON v.employee_id = e.id $wc")->execute($params) ? (int)$this->db->prepare("SELECT COUNT(*) FROM rh_vacations v JOIN rh_employees e ON v.employee_id = e.id $wc")->fetchColumn() : 0;
        // Fix: proper count
        $cs = $this->db->prepare("SELECT COUNT(*) FROM rh_vacations v JOIN rh_employees e ON v.employee_id = e.id $wc");
        $cs->execute($params);
        $total = (int)$cs->fetchColumn();
        $pagination = new Pagination($total, $currentPage);
        $stmt = $this->db->prepare("SELECT v.*, e.full_name AS employee_name, d.name AS department_name FROM rh_vacations v JOIN rh_employees e ON v.employee_id = e.id LEFT JOIN rh_departments d ON e.department_id = d.id $wc ORDER BY v.start_date DESC LIMIT {$pagination->perPage} OFFSET {$pagination->offset}");
        $stmt->execute($params);
        $vacations = $stmt->fetchAll();
        View::render('vacations/index', [
            'pageTitle' => 'Ferias', 'page' => 'vacations',
            'vacations' => $vacations, 'status' => $status, 'pagination' => $pagination,
        ]);
    }

    public function create(): void
    {
        Auth::requirePermission('vacations', 'create');
        $employees = $this->db->query("SELECT id, full_name FROM rh_employees WHERE status = 'ativo' ORDER BY full_name")->fetchAll();
        View::render('vacations/form', [
            'pageTitle' => 'Nova Ferias', 'page' => 'vacations',
            'employees' => $employees, 'item' => null,
        ]);
    }

    public function store(): void
    {
        Auth::requirePermission('vacations', 'create');
        Csrf::check();
        $data = $this->formData();
        if (!$data['employee_id'] || !$data['start_date'] || !$data['end_date']) {
            Session::flash('error', 'Preencha funcionario, data inicio e fim.');
            header('Location: index.php?m=rh&page=vacations&action=create'); exit;
        }
        if (Vacation::overlapping((int)$data['employee_id'], $data['start_date'], $data['end_date'])) {
            Session::flash('error', 'Ja existe ferias no periodo para este funcionario.');
            header('Location: index.php?m=rh&page=vacations&action=create'); exit;
        }
        $data['created_by'] = Session::userId();
        $id = Vacation::insert($data);
        // Cria bloqueio na agenda
        $this->syncSchedule($id, $data);
        AuditLog::log('create', 'vacations', $id, null, $data);
        Session::flash('success', 'Ferias cadastradas.');
        header('Location: index.php?m=rh&page=vacations'); exit;
    }

    public function edit(): void
    {
        Auth::requirePermission('vacations', 'edit');
        $item = Vacation::find(Sanitize::int($_GET['id'] ?? 0));
        if (!$item) { Session::flash('error', 'Nao encontrado.'); header('Location: index.php?m=rh&page=vacations'); exit; }
        $employees = $this->db->query("SELECT id, full_name FROM rh_employees WHERE status = 'ativo' ORDER BY full_name")->fetchAll();
        View::render('vacations/form', [
            'pageTitle' => 'Editar Ferias', 'page' => 'vacations',
            'employees' => $employees, 'item' => $item,
        ]);
    }

    public function update(): void
    {
        Auth::requirePermission('vacations', 'edit');
        Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $data = $this->formData();
        Vacation::update($id, $data);
        $this->syncSchedule($id, $data);
        AuditLog::log('update', 'vacations', $id);
        Session::flash('success', 'Ferias atualizadas.');
        header('Location: index.php?m=rh&page=vacations'); exit;
    }

    public function approve(): void
    {
        Auth::requirePermission('vacations', 'edit');
        Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $action = Sanitize::post('decision');
        $status = $action === 'aprovar' ? 'aprovada' : 'rejeitada';
        $this->db->prepare('UPDATE rh_vacations SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?')
                 ->execute([$status, Session::userId(), $id]);
        AuditLog::log('approve', 'vacations', $id, null, ['status' => $status]);
        Session::flash('success', 'Ferias ' . $status . '.');
        header('Location: index.php?m=rh&page=vacations'); exit;
    }

    public function delete(): void
    {
        Auth::requirePermission('vacations', 'delete');
        Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $this->db->prepare("DELETE FROM rh_schedules WHERE title LIKE ? AND event_type = 'compromisso'")->execute(['Ferias:%' . $id . '%']);
        Vacation::delete($id);
        AuditLog::log('delete', 'vacations', $id);
        Session::flash('success', 'Ferias excluidas.');
        header('Location: index.php?m=rh&page=vacations'); exit;
    }

    // Portal do funcionario: solicitar ferias
    public function request(): void
    {
        Auth::requireLogin();
        Csrf::check();
        $userId = Session::userId();
        $stmt = $this->db->prepare('SELECT employee_id FROM rh_user_profile WHERE user_id = ?');
        $stmt->execute([$userId]);
        $empId = (int)($stmt->fetchColumn() ?: 0);
        if (!$empId) { Session::flash('error', 'Sem vinculo com funcionario.'); header('Location: index.php?m=rh&page=my'); exit; }
        $start = Sanitize::date($_POST['start_date'] ?? '');
        $end = Sanitize::date($_POST['end_date'] ?? '');
        if (!$start || !$end) { Session::flash('error', 'Preencha as datas.'); header('Location: index.php?m=rh&page=my'); exit; }
        if (Vacation::overlapping($empId, $start, $end)) {
            Session::flash('error', 'Voce ja tem ferias no periodo.');
            header('Location: index.php?m=rh&page=my'); exit;
        }
        $days = (int)(new DateTime($start))->diff(new DateTime($end))->days + 1;
        Vacation::insert([
            'employee_id' => $empId, 'period_start' => $start, 'period_end' => $end,
            'start_date' => $start, 'end_date' => $end, 'days' => $days,
            'status' => 'solicitada', 'created_by' => $userId,
        ]);
        AuditLog::log('request', 'vacations', (int)$this->db->lastInsertId());
        Session::flash('success', 'Solicitacao de ferias enviada para aprovacao.');
        header('Location: index.php?m=rh&page=my'); exit;
    }

    private function formData(): array
    {
        $start = Sanitize::date($_POST['start_date'] ?? '');
        $end = Sanitize::date($_POST['end_date'] ?? '');
        $days = ($start && $end) ? max(1, (int)(new DateTime($start))->diff(new DateTime($end))->days + 1) : 0;
        return [
            'employee_id' => Sanitize::int($_POST['employee_id'] ?? 0),
            'period_start' => Sanitize::date($_POST['period_start'] ?? '') ?: $start,
            'period_end' => Sanitize::date($_POST['period_end'] ?? '') ?: $end,
            'start_date' => $start, 'end_date' => $end, 'days' => $days,
            'sold_days' => Sanitize::int($_POST['sold_days'] ?? 0),
            'installment' => max(1, min(3, Sanitize::int($_POST['installment'] ?? 1))),
            'status' => Sanitize::post('status') ?: 'planejada',
            'notes' => Sanitize::post('notes'),
        ];
    }

    private function syncSchedule(int $vacId, array $data): void
    {
        if (empty($data['start_date']) || empty($data['end_date'])) return;
        $this->db->prepare("DELETE FROM rh_schedules WHERE title LIKE ?")->execute(["Ferias: % [vac:$vacId]"]);
        $stmt = $this->db->prepare('SELECT full_name FROM rh_employees WHERE id = ?');
        $stmt->execute([$data['employee_id']]);
        $name = $stmt->fetchColumn() ?: 'Funcionario';
        $this->db->prepare(
            "INSERT INTO rh_schedules (employee_id, title, event_date, end_time, event_type, color, created_by)
             VALUES (?, ?, ?, NULL, 'compromisso', '#6610f2', ?)"
        )->execute([$data['employee_id'], "Ferias: $name [vac:$vacId]", $data['start_date'], Session::userId()]);
    }
}
