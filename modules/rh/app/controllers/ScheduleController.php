<?php
/**
 * Controller de Agenda e Compromissos (Módulo 4)
 */
class ScheduleController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        core_require('schedules.view');

        $employees = $this->db->query(
            "SELECT id, full_name FROM rh_employees WHERE status = 'ativo' ORDER BY full_name"
        )->fetchAll();

        View::render('schedules/index', [
            'pageTitle' => 'Agenda',
            'page'      => 'schedules',
            'employees' => $employees,
        ]);
    }

    /**
     * Endpoint JSON para o FullCalendar carregar eventos no range visível.
     * Compatível com `eventSources: { url: ..., method: 'GET' }`.
     */
    public function events(): void
    {
        core_require('schedules.view');

        $start = Sanitize::date(substr((string)($_GET['start'] ?? ''), 0, 10)) ?? date('Y-m-01');
        $end   = Sanitize::date(substr((string)($_GET['end']   ?? ''), 0, 10)) ?? date('Y-m-t');

        $stmt = $this->db->prepare(
            'SELECT s.*, e.full_name AS employee_name
             FROM rh_schedules s
             LEFT JOIN rh_employees e ON s.employee_id = e.id
             WHERE s.event_date BETWEEN ? AND ?
             ORDER BY s.event_date ASC, s.event_time ASC'
        );
        $stmt->execute([$start, $end]);

        $out = [];
        foreach ($stmt->fetchAll() as $ev) {
            $startDt = $ev['event_date'] . ($ev['event_time'] ? 'T' . $ev['event_time'] : '');
            $endDt   = $ev['end_time']   ? $ev['event_date'] . 'T' . $ev['end_time'] : null;
            $title   = $ev['title'];
            if ($ev['employee_name']) $title .= ' — ' . $ev['employee_name'];
            $out[] = [
                'id'          => (int)$ev['id'],
                'title'       => $title,
                'start'       => $startDt,
                'end'         => $endDt,
                'allDay'      => empty($ev['event_time']),
                'color'       => $ev['color'] ?: '#0d6efd',
                'extendedProps' => [
                    'type'        => $ev['event_type'],
                    'description' => $ev['description'],
                    'employee'    => $ev['employee_name'],
                    'editUrl'     => 'index.php?m=rh&page=schedules&action=edit&id=' . (int)$ev['id'],
                ],
            ];
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function create(): void
    {
        core_require('schedules.create');

        $employees = $this->db->query("SELECT id, full_name FROM rh_employees WHERE status = 'ativo' ORDER BY full_name")->fetchAll();
        $defaultDate = Sanitize::date($_GET['date'] ?? '') ?: date('Y-m-d');
        $schedule = ['event_date' => $defaultDate];

        View::render('schedules/form', [
            'pageTitle' => 'Novo Compromisso',
            'page'      => 'schedules',
            'employees' => $employees,
            'schedule'  => $schedule,
            'isEdit'    => false,
        ]);
    }

    public function store(): void
    {
        core_require('schedules.create');
        Csrf::check();

        $data = $this->getFormData();

        if (empty($data['title']) || empty($data['event_date'])) {
            Session::flash('error', 'Preencha os campos obrigatórios.');
            header('Location: index.php?m=rh&page=schedules&action=create');
            exit;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO rh_schedules (employee_id, title, description, event_date, event_time, end_time, event_type, color, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['employee_id'] ?: null, $data['title'], $data['description'],
            $data['event_date'], $data['event_time'] ?: null, $data['end_time'] ?: null,
            $data['event_type'], $data['color'], Session::userId()
        ]);

        AuditLog::log('create', 'schedules', (int)$this->db->lastInsertId());

        Session::flash('success', 'Compromisso cadastrado com sucesso.');
        header('Location: index.php?m=rh&page=schedules&date=' . $data['event_date']);
        exit;
    }

    public function edit(): void
    {
        core_require('schedules.edit');

        $id = Sanitize::int($_GET['id'] ?? 0);
        $stmt = $this->db->prepare('SELECT * FROM rh_schedules WHERE id = ?');
        $stmt->execute([$id]);
        $schedule = $stmt->fetch();

        if (!$schedule) {
            Session::flash('error', 'Compromisso não encontrado.');
            header('Location: index.php?m=rh&page=schedules');
            exit;
        }

        $employees = $this->db->query("SELECT id, full_name FROM rh_employees WHERE status = 'ativo' ORDER BY full_name")->fetchAll();

        View::render('schedules/form', [
            'pageTitle' => 'Editar Compromisso',
            'page'      => 'schedules',
            'employees' => $employees,
            'schedule'  => $schedule,
            'isEdit'    => true,
        ]);
    }

    public function update(): void
    {
        core_require('schedules.edit');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $data = $this->getFormData();

        $stmt = $this->db->prepare(
            'UPDATE rh_schedules SET employee_id=?, title=?, description=?, event_date=?, event_time=?, end_time=?, event_type=?, color=?
             WHERE id=?'
        );
        $stmt->execute([
            $data['employee_id'] ?: null, $data['title'], $data['description'],
            $data['event_date'], $data['event_time'] ?: null, $data['end_time'] ?: null,
            $data['event_type'], $data['color'], $id
        ]);

        AuditLog::log('update', 'schedules', $id);

        Session::flash('success', 'Compromisso atualizado.');
        header('Location: index.php?m=rh&page=schedules&date=' . $data['event_date']);
        exit;
    }

    public function delete(): void
    {
        core_require('schedules.delete');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $this->db->prepare('DELETE FROM rh_schedules WHERE id = ?')->execute([$id]);
        AuditLog::log('delete', 'schedules', $id);

        Session::flash('success', 'Compromisso excluído.');
        header('Location: index.php?m=rh&page=schedules');
        exit;
    }

    private function getFormData(): array
    {
        return [
            'employee_id' => Sanitize::int($_POST['employee_id'] ?? 0),
            'title'       => Sanitize::post('title'),
            'description' => Sanitize::post('description'),
            'event_date'  => Sanitize::date($_POST['event_date'] ?? ''),
            'event_time'  => Sanitize::post('event_time'),
            'end_time'    => Sanitize::post('end_time'),
            'event_type'  => Sanitize::post('event_type') ?: 'compromisso',
            'color'       => Sanitize::post('color') ?: '#0d6efd',
        ];
    }
}
