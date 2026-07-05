<?php
class ShiftController
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function index(): void
    {
        Auth::requirePermission('shifts', 'view');
        $deptId = Sanitize::int($_GET['department'] ?? 0);
        $weekStart = Sanitize::date($_GET['week'] ?? '') ?: date('Y-m-d', strtotime('monday this week'));
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
        $departments = $this->db->query('SELECT id, name FROM departments WHERE active = 1 ORDER BY name')->fetchAll();
        $templates = ShiftTemplate::allActive();
        $shifts = $deptId ? Shift::forDepartmentRange($deptId, $weekStart, $weekEnd) : [];
        $employees = $deptId
            ? $this->db->prepare("SELECT id, full_name FROM employees WHERE department_id = ? AND status = 'ativo' ORDER BY full_name")
            : null;
        if ($employees) { $employees->execute([$deptId]); $employees = $employees->fetchAll(); } else { $employees = []; }
        $prevWeek = date('Y-m-d', strtotime($weekStart . ' -7 days'));
        $nextWeek = date('Y-m-d', strtotime($weekStart . ' +7 days'));
        View::render('shifts/index', [
            'pageTitle' => 'Escalas de Plantao', 'page' => 'shifts',
            'departments' => $departments, 'templates' => $templates,
            'deptId' => $deptId, 'weekStart' => $weekStart, 'weekEnd' => $weekEnd,
            'prevWeek' => $prevWeek, 'nextWeek' => $nextWeek,
            'shifts' => $shifts, 'employees' => $employees,
        ]);
    }

    public function store(): void
    {
        Auth::requirePermission('shifts', 'create');
        Csrf::check();
        $empId = Sanitize::int($_POST['employee_id'] ?? 0);
        $date = Sanitize::date($_POST['shift_date'] ?? '');
        $start = Sanitize::post('start_time');
        $end = Sanitize::post('end_time');
        $deptId = Sanitize::int($_POST['department_id'] ?? 0);
        $tplId = Sanitize::int($_POST['template_id'] ?? 0);
        if (!$empId || !$date || !$start || !$end) {
            Session::flash('error', 'Preencha todos os campos obrigatorios.');
            header('Location: index.php?page=shifts&department=' . $deptId); exit;
        }
        if (Shift::hasConflict($empId, $date, $start, $end)) {
            Session::flash('error', 'Conflito: funcionario ja tem escala neste horario.');
            header('Location: index.php?page=shifts&department=' . $deptId); exit;
        }
        $id = Shift::insert([
            'employee_id' => $empId, 'department_id' => $deptId ?: null,
            'template_id' => $tplId ?: null, 'shift_date' => $date,
            'start_time' => $start, 'end_time' => $end,
            'type' => Sanitize::post('type') ?: 'regular',
            'notes' => Sanitize::post('notes'), 'created_by' => Session::userId(),
        ]);
        AuditLog::log('create', 'shifts', $id);
        Session::flash('success', 'Escala cadastrada.');
        header('Location: index.php?page=shifts&department=' . $deptId . '&week=' . $date); exit;
    }

    public function delete(): void
    {
        Auth::requirePermission('shifts', 'delete');
        Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $shift = Shift::find($id);
        Shift::delete($id);
        AuditLog::log('delete', 'shifts', $id);
        Session::flash('success', 'Escala removida.');
        $dept = $shift['department_id'] ?? 0;
        header('Location: index.php?page=shifts&department=' . $dept); exit;
    }

    public function swap(): void
    {
        Auth::requireLogin();
        Csrf::check();
        $shiftId = Sanitize::int($_POST['shift_id'] ?? 0);
        $withEmpId = Sanitize::int($_POST['swap_employee_id'] ?? 0);
        $shift = Shift::find($shiftId);
        if (!$shift) { Session::flash('error', 'Escala nao encontrada.'); header('Location: index.php?page=shifts'); exit; }
        Shift::update($shiftId, ['status' => 'troca_pendente', 'swap_with_id' => $withEmpId]);
        AuditLog::log('swap_request', 'shifts', $shiftId);
        Session::flash('success', 'Solicitacao de troca enviada para aprovacao.');
        header('Location: index.php?page=shifts&department=' . ($shift['department_id'] ?? 0)); exit;
    }

    public function approve_swap(): void
    {
        Auth::requirePermission('shifts', 'edit');
        Csrf::check();
        $shiftId = Sanitize::int($_POST['shift_id'] ?? 0);
        $shift = Shift::find($shiftId);
        if (!$shift || $shift['status'] !== 'troca_pendente') {
            Session::flash('error', 'Troca invalida.'); header('Location: index.php?page=shifts'); exit;
        }
        $newEmp = (int)$shift['swap_with_id'];
        Shift::update($shiftId, ['employee_id' => $newEmp, 'status' => 'trocado', 'swap_with_id' => null]);
        AuditLog::log('swap_approved', 'shifts', $shiftId);
        Session::flash('success', 'Troca aprovada.');
        header('Location: index.php?page=shifts&department=' . ($shift['department_id'] ?? 0)); exit;
    }

    public function events(): void
    {
        Auth::requirePermission('shifts', 'view');
        $deptId = Sanitize::int($_GET['department'] ?? 0);
        $start = Sanitize::date(substr($_GET['start'] ?? '', 0, 10)) ?: date('Y-m-01');
        $end = Sanitize::date(substr($_GET['end'] ?? '', 0, 10)) ?: date('Y-m-t');
        $shifts = $deptId ? Shift::forDepartmentRange($deptId, $start, $end) : [];
        $out = [];
        foreach ($shifts as $s) {
            $out[] = [
                'id' => (int)$s['id'],
                'title' => $s['employee_name'] . ($s['template_name'] ? ' (' . $s['template_name'] . ')' : ''),
                'start' => $s['shift_date'] . 'T' . $s['start_time'],
                'end' => $s['shift_date'] . 'T' . $s['end_time'],
                'color' => $s['color'] ?? '#0d6efd',
                'extendedProps' => ['type' => $s['type'], 'status' => $s['status']],
            ];
        }
        header('Content-Type: application/json');
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
