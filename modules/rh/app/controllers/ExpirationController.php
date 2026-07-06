<?php
/**
 * Controller de Vencimentos e Obrigações (Módulo 3)
 */
class ExpirationController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        core_require('expirations.view');

        $search     = Sanitize::get('search');
        $type       = Sanitize::get('type');
        $status     = Sanitize::get('status');
        $currentPage = max(1, Sanitize::int($_GET['p'] ?? 1));

        $where = [];
        $params = [];

        if ($search) {
            $where[] = '(e.full_name LIKE ? OR ex.title LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        if ($type) {
            $where[] = 'ex.type = ?';
            $params[] = $type;
        }

        // Filtro de status calculado
        $today = date('Y-m-d');
        if ($status === 'vencido') {
            $where[] = 'ex.expiry_date < ?';
            $params[] = $today;
        } elseif ($status === 'proximo') {
            $where[] = 'ex.expiry_date >= ? AND ex.expiry_date <= DATE_ADD(?, INTERVAL ex.alert_days DAY)';
            $params[] = $today;
            $params[] = $today;
        } elseif ($status === 'valido') {
            $where[] = 'ex.expiry_date > DATE_ADD(?, INTERVAL ex.alert_days DAY)';
            $params[] = $today;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM rh_expirations ex JOIN rh_employees e ON ex.employee_id = e.id {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $pagination = new Pagination($total, $currentPage);

        $sql = "SELECT ex.*, e.full_name as employee_name
                FROM rh_expirations ex
                JOIN rh_employees e ON ex.employee_id = e.id
                {$whereClause}
                ORDER BY ex.expiry_date ASC
                LIMIT {$pagination->perPage} OFFSET {$pagination->offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $expirations = $stmt->fetchAll();

        $pageTitle = 'Vencimentos';
        $page = 'expirations';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/expirations/index.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function create(): void
    {
        core_require('expirations.create');

        $employeeId = Sanitize::int($_GET['employee_id'] ?? 0);
        $employees = $this->db->query("SELECT id, full_name FROM rh_employees WHERE status = 'ativo' ORDER BY full_name")->fetchAll();
        $expiration = null;

        $pageTitle = 'Novo Vencimento';
        $page = 'expirations';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/expirations/form.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function store(): void
    {
        core_require('expirations.create');
        Csrf::check();

        $data = $this->getFormData();

        if (empty($data['employee_id']) || empty($data['title']) || empty($data['expiry_date'])) {
            Session::flash('error', 'Preencha todos os campos obrigatórios.');
            header('Location: index.php?m=rh&page=expirations&action=create');
            exit;
        }

        $filePath = null;
        if (!empty($_FILES['file']['name'])) {
            $upload = Upload::handle('file', 'documents');
            if ($upload['success']) $filePath = $upload['path'];
        }

        $stmt = $this->db->prepare(
            'INSERT INTO rh_expirations (employee_id, type, title, description, issue_date, expiry_date, alert_days, file_path, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['employee_id'], $data['type'], $data['title'], $data['description'],
            $data['issue_date'] ?: null, $data['expiry_date'], $data['alert_days'],
            $filePath, Session::userId()
        ]);

        $expId = (int)$this->db->lastInsertId();

        // Replica o vencimento como compromisso na agenda (evento_type=vencimento).
        self::syncToSchedule($this->db, $expId, (int)$data['employee_id'], $data['title'], $data['expiry_date'], $data['description'] ?? '');

        AuditLog::log('create', 'expirations', $expId);

        Session::flash('success', 'Vencimento cadastrado com sucesso.');
        header('Location: index.php?m=rh&page=expirations');
        exit;
    }

    /**
     * Garante que existe um registro correspondente em `schedules` para um
     * vencimento. Cria ou atualiza (sempre aponta para a data de expiração).
     * Compartilhado entre store/update e a auto-criação do EmployeeController.
     */
    public static function syncToSchedule(
        PDO $db, int $expirationId, int $employeeId, string $title, string $expiryDate, string $description = ''
    ): void {
        $stmt = $db->prepare('SELECT id FROM rh_schedules WHERE expiration_id = ? LIMIT 1');
        $stmt->execute([$expirationId]);
        $existing = $stmt->fetchColumn();

        $eventTitle = 'Vencimento: ' . $title;
        $color      = '#dc3545'; // vermelho — é um prazo

        if ($existing) {
            $stmt = $db->prepare(
                'UPDATE rh_schedules
                    SET employee_id = ?, title = ?, description = ?,
                        event_date = ?, event_type = "vencimento", color = ?
                  WHERE id = ?'
            );
            $stmt->execute([$employeeId, $eventTitle, $description, $expiryDate, $color, $existing]);
        } else {
            $stmt = $db->prepare(
                'INSERT INTO rh_schedules
                    (employee_id, title, description, event_date, event_type, expiration_id, color, created_by)
                 VALUES (?, ?, ?, ?, "vencimento", ?, ?, ?)'
            );
            $stmt->execute([
                $employeeId, $eventTitle, $description, $expiryDate,
                $expirationId, $color, Session::userId(),
            ]);
        }
    }

    public function edit(): void
    {
        core_require('expirations.edit');

        $id = Sanitize::int($_GET['id'] ?? 0);
        $stmt = $this->db->prepare('SELECT * FROM rh_expirations WHERE id = ?');
        $stmt->execute([$id]);
        $expiration = $stmt->fetch();

        if (!$expiration) {
            Session::flash('error', 'Vencimento não encontrado.');
            header('Location: index.php?m=rh&page=expirations');
            exit;
        }

        $employeeId = $expiration['employee_id'];
        $employees = $this->db->query("SELECT id, full_name FROM rh_employees ORDER BY full_name")->fetchAll();

        // Histórico de renovações
        $stmt = $this->db->prepare(
            'SELECT h.*, u.name as user_name FROM rh_expiration_history h
             LEFT JOIN users u ON h.renewed_by = u.id
             WHERE h.expiration_id = ? ORDER BY h.created_at DESC'
        );
        $stmt->execute([$id]);
        $history = $stmt->fetchAll();

        $pageTitle = 'Editar Vencimento';
        $page = 'expirations';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/expirations/form.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function update(): void
    {
        core_require('expirations.edit');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $stmt = $this->db->prepare('SELECT * FROM rh_expirations WHERE id = ?');
        $stmt->execute([$id]);
        $old = $stmt->fetch();

        if (!$old) {
            Session::flash('error', 'Vencimento não encontrado.');
            header('Location: index.php?m=rh&page=expirations');
            exit;
        }

        $data = $this->getFormData();

        // Verificar se houve renovação (mudança de data de vencimento)
        if ($old['expiry_date'] !== $data['expiry_date']) {
            $stmt = $this->db->prepare(
                'INSERT INTO rh_expiration_history (expiration_id, old_expiry_date, new_expiry_date, notes, renewed_by)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$id, $old['expiry_date'], $data['expiry_date'], 'Renovação', Session::userId()]);
        }

        $filePath = $old['file_path'];
        if (!empty($_FILES['file']['name'])) {
            $upload = Upload::handle('file', 'documents');
            if ($upload['success']) {
                if ($old['file_path']) Upload::delete($old['file_path']);
                $filePath = $upload['path'];
            }
        }

        $stmt = $this->db->prepare(
            'UPDATE rh_expirations SET employee_id=?, type=?, title=?, description=?, issue_date=?, expiry_date=?, alert_days=?, file_path=?
             WHERE id=?'
        );
        $stmt->execute([
            $data['employee_id'], $data['type'], $data['title'], $data['description'],
            $data['issue_date'] ?: null, $data['expiry_date'], $data['alert_days'],
            $filePath, $id
        ]);

        // Atualiza o compromisso espelhado na agenda.
        self::syncToSchedule($this->db, $id, (int)$data['employee_id'], $data['title'], $data['expiry_date'], $data['description'] ?? '');

        AuditLog::log('update', 'expirations', $id, $old, $data);

        Session::flash('success', 'Vencimento atualizado com sucesso.');
        header('Location: index.php?m=rh&page=expirations');
        exit;
    }

    public function delete(): void
    {
        core_require('expirations.delete');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $stmt = $this->db->prepare('SELECT * FROM rh_expirations WHERE id = ?');
        $stmt->execute([$id]);
        $exp = $stmt->fetch();

        if ($exp) {
            if ($exp['file_path']) Upload::delete($exp['file_path']);
            // Remove o compromisso espelhado na agenda (se houver).
            $this->db->prepare('DELETE FROM rh_schedules WHERE expiration_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM rh_expirations WHERE id = ?')->execute([$id]);
            AuditLog::log('delete', 'expirations', $id);
            Session::flash('success', 'Vencimento excluído.');
        }

        header('Location: index.php?m=rh&page=expirations');
        exit;
    }

    public function export(): void
    {
        core_require('expirations.export');

        $sql = "SELECT ex.*, e.full_name as employee_name
                FROM rh_expirations ex
                JOIN rh_employees e ON ex.employee_id = e.id
                ORDER BY ex.expiry_date ASC";
        $expirations = $this->db->query($sql)->fetchAll();

        $headers = ['Funcionário', 'Tipo', 'Título', 'Emissão', 'Vencimento', 'Status'];
        $rows = [];
        $today = new DateTime();
        foreach ($expirations as $ex) {
            $expDate = new DateTime($ex['expiry_date']);
            $diff = $today->diff($expDate)->days;
            $isPast = $expDate < $today;
            $statusText = $isPast ? 'Vencido' : ($diff <= $ex['alert_days'] ? 'Próximo' : 'Válido');

            $rows[] = [
                $ex['employee_name'],
                str_replace('_', ' ', $ex['type']),
                $ex['title'],
                Sanitize::formatDate($ex['issue_date']),
                Sanitize::formatDate($ex['expiry_date']),
                $statusText,
            ];
        }

        Export::csv('vencimentos_' . date('Y-m-d') . '.csv', $headers, $rows);
    }

    private function getFormData(): array
    {
        return [
            'employee_id' => Sanitize::int($_POST['employee_id'] ?? 0),
            'type'        => Sanitize::post('type'),
            'title'       => Sanitize::post('title'),
            'description' => Sanitize::post('description'),
            'issue_date'  => Sanitize::date($_POST['issue_date'] ?? ''),
            'expiry_date' => Sanitize::date($_POST['expiry_date'] ?? ''),
            'alert_days'  => max(1, Sanitize::int($_POST['alert_days'] ?? 30)),
        ];
    }
}
