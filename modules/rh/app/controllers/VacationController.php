<?php
class VacationController
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function index(): void
    {
        core_require('vacations.view');
        $status = Sanitize::get('status');
        $currentPage = max(1, Sanitize::int($_GET['p'] ?? 1));
        $where = []; $params = [];
        if ($status) { $where[] = 'v.status = ?'; $params[] = $status; }
        $wc = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $cs = $this->db->prepare("SELECT COUNT(*) FROM rh_vacations v JOIN rh_employees e ON v.employee_id = e.id $wc");
        $cs->execute($params);
        $total = (int)$cs->fetchColumn();
        $pagination = new Pagination($total, $currentPage);
        $stmt = $this->db->prepare("SELECT v.*, e.full_name AS employee_name, d.name AS department_name FROM rh_vacations v JOIN rh_employees e ON v.employee_id = e.id LEFT JOIN rh_departments d ON e.department_id = d.id $wc ORDER BY v.start_date DESC LIMIT {$pagination->perPage} OFFSET {$pagination->offset}");
        $stmt->execute($params);
        $vacations = $stmt->fetchAll();
        View::render('vacations/index', [
            'pageTitle' => 'Férias', 'page' => 'vacations',
            'vacations' => $vacations, 'status' => $status, 'pagination' => $pagination,
        ]);
    }

    public function create(): void
    {
        core_require('vacations.create');
        $employees = $this->db->query("SELECT id, full_name FROM rh_employees WHERE status = 'ativo' ORDER BY full_name")->fetchAll();
        View::render('vacations/form', [
            'pageTitle' => 'Novas Férias', 'page' => 'vacations',
            'employees' => $employees, 'item' => null,
        ]);
    }

    public function store(): void
    {
        core_require('vacations.create');
        Csrf::check();
        $data = $this->formData();
        if (!$data['employee_id'] || !$data['start_date'] || !$data['end_date']) {
            Session::flash('error', 'Preencha funcionário, data de início e fim.');
            header('Location: index.php?m=rh&page=vacations&action=create'); exit;
        }
        if (Vacation::overlapping((int)$data['employee_id'], $data['start_date'], $data['end_date'])) {
            Session::flash('error', 'Já existem férias no período para este funcionário.');
            header('Location: index.php?m=rh&page=vacations&action=create'); exit;
        }
        $data['created_by'] = Session::userId();
        $id = Vacation::insert($data);
        // Cria bloqueio na agenda
        $this->syncSchedule($id, $data);
        AuditLog::log('create', 'vacations', $id, null, $data);
        Session::flash('success', 'Férias cadastradas.');
        header('Location: index.php?m=rh&page=vacations'); exit;
    }

    public function edit(): void
    {
        core_require('vacations.edit');
        $item = Vacation::find(Sanitize::int($_GET['id'] ?? 0));
        if (!$item) { Session::flash('error', 'Férias não encontradas.'); header('Location: index.php?m=rh&page=vacations'); exit; }
        $employees = $this->db->query("SELECT id, full_name FROM rh_employees WHERE status = 'ativo' ORDER BY full_name")->fetchAll();
        View::render('vacations/form', [
            'pageTitle' => 'Editar Férias', 'page' => 'vacations',
            'employees' => $employees, 'item' => $item,
        ]);
    }

    public function update(): void
    {
        core_require('vacations.edit');
        Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $data = $this->formData();
        Vacation::update($id, $data);
        $this->syncSchedule($id, $data);
        AuditLog::log('update', 'vacations', $id);
        Session::flash('success', 'Férias atualizadas.');
        header('Location: index.php?m=rh&page=vacations'); exit;
    }

    public function approve(): void
    {
        core_require('vacations.edit');
        Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $action = Sanitize::post('decision');
        $status = $action === 'aprovar' ? 'aprovada' : 'rejeitada';
        // Atualiza as férias E a solicitação vinculada (rh_requests), notificando o funcionário.
        $vac = Vacation::decide($id, $status, Session::userId(), Sanitize::post('response'));
        if (!$vac) { Session::flash('error', 'Férias não encontradas.'); header('Location: index.php?m=rh&page=vacations'); exit; }
        if ($status === 'aprovada') { $this->syncSchedule($id, $vac); }
        AuditLog::log('approve', 'vacations', $id, null, ['status' => $status]);
        Session::flash('success', 'Férias ' . $status . '.');
        header('Location: index.php?m=rh&page=vacations'); exit;
    }

    public function delete(): void
    {
        core_require('vacations.delete');
        Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $this->db->prepare("DELETE FROM rh_schedules WHERE title LIKE ? AND event_type = 'compromisso'")->execute(['Ferias:%' . $id . '%']);
        Vacation::delete($id);
        AuditLog::log('delete', 'vacations', $id);
        Session::flash('success', 'Férias excluídas.');
        header('Location: index.php?m=rh&page=vacations'); exit;
    }

    /**
     * Portal do funcionário: solicitar férias (POST de Minha Área).
     * Cria rh_vacations (status 'solicitada') E a solicitação em rh_requests
     * (type 'ferias', vinculada por vacation_id) para o RH aprovar/rejeitar
     * na aba Solicitações — as duas ficam sincronizadas (Vacation::decide).
     */
    public function request(): void
    {
        core_require('my.view');
        Csrf::check();
        $userId = (int)Session::userId();
        $empId  = EmployeeAccess::employeeIdOf($userId);
        $back   = 'index.php?m=rh&page=my#ferias';
        if (!$empId) { Session::flash('error', 'Seu usuário não está vinculado a um funcionário.'); header('Location: index.php?m=rh&page=my'); exit; }

        $start = Sanitize::date($_POST['start_date'] ?? '');
        $end   = Sanitize::date($_POST['end_date'] ?? '');
        $pStart = Sanitize::date($_POST['period_start'] ?? '');
        $pEnd   = Sanitize::date($_POST['period_end'] ?? '');
        $sold  = max(0, min(10, Sanitize::int($_POST['sold_days'] ?? 0)));
        $notes = mb_substr(Sanitize::post('notes'), 0, 1000);

        if (!$start || !$end) { Session::flash('error', 'Informe as datas de início e fim das férias.'); header("Location: $back"); exit; }
        if ($end < $start) { Session::flash('error', 'A data final deve ser posterior à inicial.'); header("Location: $back"); exit; }
        if ($start < date('Y-m-d')) { Session::flash('error', 'As férias não podem começar em data passada.'); header("Location: $back"); exit; }
        $days = (int)(new DateTime($start))->diff(new DateTime($end))->days + 1;
        if ($days > 30) { Session::flash('error', 'O período não pode exceder 30 dias.'); header("Location: $back"); exit; }
        if ($sold + $days > 30) { Session::flash('error', 'Dias de gozo + dias vendidos não podem exceder 30.'); header("Location: $back"); exit; }
        if (Vacation::overlapping($empId, $start, $end)) {
            Session::flash('error', 'Você já possui férias (solicitadas ou aprovadas) nesse período.');
            header("Location: $back"); exit;
        }

        $employee = Employee::find($empId);
        $this->db->beginTransaction();
        try {
            $vacId = Vacation::insert([
                'employee_id' => $empId,
                'period_start' => $pStart ?: date('Y-m-d', strtotime('-1 year', strtotime($start))),
                'period_end'   => $pEnd ?: $start,
                'start_date' => $start, 'end_date' => $end, 'days' => $days, 'sold_days' => $sold,
                'installment' => 1, 'status' => 'solicitada', 'notes' => $notes ?: null, 'created_by' => $userId,
            ]);
            $subject = 'Férias: ' . Sanitize::formatDate($start) . ' a ' . Sanitize::formatDate($end) . " ({$days} dias)";
            $body = "Solicitação de férias enviada pelo portal.\n"
                  . 'Período de gozo: ' . Sanitize::formatDate($start) . ' a ' . Sanitize::formatDate($end) . " ({$days} dias)\n"
                  . ($pStart || $pEnd ? 'Período aquisitivo: ' . Sanitize::formatDate($pStart) . ' a ' . Sanitize::formatDate($pEnd) . "\n" : '')
                  . 'Abono pecuniário (dias vendidos): ' . $sold . "\n"
                  . ($notes !== '' ? 'Observação: ' . $notes : '');
            $reqId = EmployeeRequest::insert([
                'employee_id' => $empId, 'type' => 'ferias', 'vacation_id' => $vacId,
                'subject' => mb_substr($subject, 0, 200), 'body' => trim($body),
                'requested_by' => $userId, 'status' => 'pendente',
            ]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('RH vacations.request: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível registrar a solicitação. Tente novamente.');
            header("Location: $back"); exit;
        }
        AuditLog::log('request', 'vacations', $vacId, null, ['request_id' => $reqId]);
        EmployeeRequest::notifyResponders($reqId, (string)($employee['full_name'] ?? 'Funcionário'), $subject);
        Session::flash('success', 'Solicitação de férias enviada para aprovação do RH.');
        header("Location: $back"); exit;
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
