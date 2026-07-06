<?php
/**
 * Controller de Funcionários (Módulo 1 — Gestão de Funcionários)
 */
class EmployeeController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Listagem com busca e filtros avançados
     */
    public function index(): void
    {
        core_require('employees.view');

        $search     = Sanitize::get('search');
        $status     = Sanitize::get('status');
        $department = Sanitize::int($_GET['department'] ?? 0);
        $contract   = Sanitize::get('contract');
        $currentPage = max(1, Sanitize::int($_GET['p'] ?? 1));

        // Construir query
        $where = [];
        $params = [];

        if ($search) {
            $where[] = '(e.full_name LIKE ? OR e.cpf LIKE ? OR e.email LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        if ($status) {
            $where[] = 'e.status = ?';
            $params[] = $status;
        }
        if ($department) {
            $where[] = 'e.department_id = ?';
            $params[] = $department;
        }
        if ($contract) {
            $where[] = 'e.contract_type = ?';
            $params[] = $contract;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Contar total
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM rh_employees e {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $pagination = new Pagination($total, $currentPage);

        // Buscar dados
        $sql = "SELECT e.*, d.name as department_name, j.title as position_title
                FROM rh_employees e
                LEFT JOIN rh_departments d ON e.department_id = d.id
                LEFT JOIN rh_job_positions j ON e.job_position_id = j.id
                {$whereClause}
                ORDER BY e.full_name ASC
                LIMIT {$pagination->perPage} OFFSET {$pagination->offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll();

        // Departamentos para filtro
        $departments = $this->db->query('SELECT id, name FROM rh_departments WHERE active = 1 ORDER BY name')->fetchAll();

        $pageTitle = 'Funcionários';
        $page = 'employees';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/employees/index.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    /**
     * Formulário de criação
     */
    public function create(): void
    {
        core_require('employees.create');

        $departments = $this->db->query('SELECT id, name FROM rh_departments WHERE active = 1 ORDER BY name')->fetchAll();
        $positions = $this->db->query('SELECT id, title FROM rh_job_positions WHERE active = 1 ORDER BY title')->fetchAll();
        $employee = null;

        $pageTitle = 'Novo Funcionário';
        $page = 'employees';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/employees/form.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    /**
     * Salvar novo funcionário
     */
    public function store(): void
    {
        core_require('employees.create');
        Csrf::check();

        $data = $this->getFormData();
        $errors = $this->validate($data);

        if (!empty($errors)) {
            Session::flash('error', implode('<br>', $errors));
            header('Location: index.php?m=rh&page=employees&action=create');
            exit;
        }

        // Upload de foto
        $photoPath = null;
        if (!empty($_FILES['photo']['name'])) {
            $upload = Upload::handle('photo', 'employees');
            if ($upload['success']) {
                $photoPath = $upload['path'];
            }
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO rh_employees (full_name, cpf, birth_date, gender, phone, email,
                    address_street, address_number, address_complement, address_neighborhood,
                    address_city, address_state, address_zip, job_position_id, department_id,
                    admission_date, contract_type, status, termination_date, leave_date, return_date,
                    regional_council, council_number, council_expiry,
                    aso_admissional_date, aso_next_date,
                    photo, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $data['full_name'], $data['cpf'], $data['birth_date'], $data['gender'],
                $data['phone'], $data['email'], $data['address_street'], $data['address_number'],
                $data['address_complement'], $data['address_neighborhood'], $data['address_city'],
                $data['address_state'], $data['address_zip'],
                $data['job_position_id'] ?: null, $data['department_id'] ?: null,
                $data['admission_date'], $data['contract_type'], $data['status'],
                $data['termination_date'] ?: null, $data['leave_date'] ?: null, $data['return_date'] ?: null,
                $data['regional_council'], $data['council_number'], $data['council_expiry'] ?: null,
                $data['aso_admissional_date'] ?: null, $data['aso_next_date'] ?: null,
                $photoPath, $data['notes'], Session::userId()
            ]);

            $employeeId = (int)$this->db->lastInsertId();

            // Histórico de admissão.
            $this->addRecord($employeeId, 'admissao', 'Funcionário cadastrado no sistema', $data['admission_date']);

            // Gera vencimentos automáticos (ASO e Conselho Regional, se houver).
            $this->autoCreateExpirations($employeeId, $data);

            // Cria acesso do funcionário ao sistema (login = e-mail, senha = CPF).
            $portalInfo = $this->createEmployeeUser($employeeId, $data);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            Session::flash('error', 'Falha ao cadastrar funcionário: ' . Sanitize::e($e->getMessage()));
            header('Location: index.php?m=rh&page=employees&action=create');
            exit;
        }

        AuditLog::log('create', 'employees', $employeeId, null, $data);
        FileCache::forget('dashboard.global.' . date('Y-m-d'));

        $msg = 'Funcionário cadastrado com sucesso.';
        if ($portalInfo['created']) {
            $msg .= ' Acesso ao portal criado: login <code>' . Sanitize::e($portalInfo['email'])
                  . '</code> / senha inicial: CPF sem formatação.';
        } elseif ($portalInfo['reason']) {
            $msg .= ' ' . $portalInfo['reason'];
        }
        Session::flash('success', $msg);
        header('Location: index.php?m=rh&page=employees&action=show&id=' . $employeeId);
        exit;
    }

    /**
     * Cria os vencimentos automáticos (ASO próximo + Conselho Regional) e
     * replica cada um como compromisso na agenda (schedules.event_type='vencimento').
     */
    private function autoCreateExpirations(int $employeeId, array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO rh_expirations (employee_id, type, title, description, issue_date, expiry_date, alert_days, created_by)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $description = 'Gerado automaticamente a partir do cadastro.';

        // ASO — sempre criado (a UI exige o preenchimento).
        if (!empty($data['aso_next_date'])) {
            $stmt->execute([
                $employeeId,
                'aso_periodico',
                'ASO Periódico',
                $description,
                $data['aso_admissional_date'] ?: null,
                $data['aso_next_date'],
                30,
                Session::userId(),
            ]);
            ExpirationController::syncToSchedule(
                $this->db, (int)$this->db->lastInsertId(),
                $employeeId, 'ASO Periódico', $data['aso_next_date'], $description
            );
        }

        // Conselho Regional (se aplicável).
        $council = $data['regional_council'] ?? '';
        if ($council && $council !== 'N/A' && !empty($data['council_expiry'])) {
            $title = trim('Conselho Regional — ' . $council
                     . ($data['council_number'] ? ' ' . $data['council_number'] : ''));
            $stmt->execute([
                $employeeId,
                'conselho_regional',
                $title,
                $description,
                null,
                $data['council_expiry'],
                30,
                Session::userId(),
            ]);
            ExpirationController::syncToSchedule(
                $this->db, (int)$this->db->lastInsertId(),
                $employeeId, $title, $data['council_expiry'], $description
            );
        }
    }

    /**
     * Cria o usuário do portal do funcionário (preset de micropermissões
     * "funcionario": my.view, requests.view, requests.create,
     * announcements.view).
     * Pré-condições: e-mail preenchido, CPF válido, e-mail ainda não cadastrado.
     * Retorna ['created'=>bool, 'reason'=>string, 'email'=>string].
     */
    private function createEmployeeUser(int $employeeId, array $data): array
    {
        $email = $data['email'] ?? '';
        $cpf   = $data['cpf']   ?? '';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['created' => false, 'reason' => 'Acesso ao portal não criado: e-mail inválido/ausente.', 'email' => ''];
        }
        if (!$cpf || strlen($cpf) !== 11) {
            return ['created' => false, 'reason' => 'Acesso ao portal não criado: CPF inválido.', 'email' => ''];
        }

        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
        $stmt->execute([$email, $email]);
        if ($stmt->fetch()) {
            return ['created' => false, 'reason' => 'Acesso ao portal não criado: o e-mail já está em uso por outro usuário.', 'email' => $email];
        }

        // Usuário GLOBAL da plataforma (senha inicial = CPF, troca obrigatória).
        $hash = password_hash($cpf, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $this->db->prepare(
            'INSERT INTO users (name, username, email, password_hash, is_admin, active, force_password_change)
             VALUES (?,?,?,?,0,1,1)'
        );
        $stmt->execute([$data['full_name'], $email, $email, $hash]);
        $userId = (int)$this->db->lastInsertId();

        // Perfil do módulo: vínculo usuário ↔ funcionário/departamento.
        $stmt = $this->db->prepare(
            'INSERT INTO rh_user_profile (user_id, employee_id, department_id)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE employee_id = VALUES(employee_id),
                                     department_id = VALUES(department_id)'
        );
        $stmt->execute([$userId, $employeeId, $data['department_id'] ?: null]);

        // Acesso ao módulo RH com o preset "Funcionário" (micropermissões).
        Core\Perms::setUserGrants(
            $userId,
            'rh',
            Core\Perms::expand('rh', ['my.view', 'requests.view', 'requests.create', 'announcements.view']),
            Core\Auth::id()
        );

        return ['created' => true, 'reason' => '', 'email' => $email];
    }

    /**
     * Visualizar funcionário (Ficha Funcional)
     */
    public function show(): void
    {
        core_require('employees.view');

        $id = Sanitize::int($_GET['id'] ?? 0);
        $employee = $this->getEmployee($id);

        if (!$employee) {
            Session::flash('error', 'Funcionário não encontrado.');
            header('Location: index.php?m=rh&page=employees');
            exit;
        }

        // Documentos — separados por categoria para as abas da ficha.
        $stmt = $this->db->prepare('SELECT * FROM rh_employee_documents WHERE employee_id = ? ORDER BY created_at DESC');
        $stmt->execute([$id]);
        $allDocs = $stmt->fetchAll();
        $documents = $trainings = $epis = [];
        foreach ($allDocs as $d) {
            if ($d['doc_type'] === 'Treinamento')      $trainings[] = $d;
            elseif ($d['doc_type'] === 'EPI')          $epis[]      = $d;
            else                                       $documents[] = $d;
        }

        // Histórico
        $stmt = $this->db->prepare(
            'SELECT r.*, u.name as user_name FROM rh_employee_records r
             LEFT JOIN users u ON r.created_by = u.id
             WHERE r.employee_id = ? ORDER BY r.record_date DESC, r.created_at DESC'
        );
        $stmt->execute([$id]);
        $records = $stmt->fetchAll();

        // Vencimentos
        $stmt = $this->db->prepare('SELECT * FROM rh_expirations WHERE employee_id = ? ORDER BY expiry_date ASC');
        $stmt->execute([$id]);
        $expirations = $stmt->fetchAll();

        // Atestados
        $stmt = $this->db->prepare('SELECT * FROM rh_medical_certificates WHERE employee_id = ? ORDER BY issue_date DESC');
        $stmt->execute([$id]);
        $certificates = $stmt->fetchAll();

        // Pontuação e elogios.
        $scores          = EmployeeScore::listFor($id);
        $scoresTotal     = EmployeeScore::totalFor($id);
        $compliments     = EmployeeCompliment::listFor($id);

        // Usuário vinculado (portal do funcionário) — via rh_user_profile.
        $stmt = $this->db->prepare(
            'SELECT u.id, u.email, u.active, u.last_login_at AS last_login
             FROM rh_user_profile p
             JOIN users u ON u.id = p.user_id
             WHERE p.employee_id = ?'
        );
        $stmt->execute([$id]);
        $portalUser = $stmt->fetch() ?: null;

        $pageTitle = $employee['full_name'];
        $page = 'employees';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/employees/show.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    /**
     * Formulário de edição
     */
    public function edit(): void
    {
        core_require('employees.edit');

        $id = Sanitize::int($_GET['id'] ?? 0);
        $employee = $this->getEmployee($id);

        if (!$employee) {
            Session::flash('error', 'Funcionário não encontrado.');
            header('Location: index.php?m=rh&page=employees');
            exit;
        }

        $departments = $this->db->query('SELECT id, name FROM rh_departments WHERE active = 1 ORDER BY name')->fetchAll();
        $positions = $this->db->query('SELECT id, title FROM rh_job_positions WHERE active = 1 ORDER BY title')->fetchAll();

        $pageTitle = 'Editar Funcionário';
        $page = 'employees';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/employees/form.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    /**
     * Atualizar funcionário
     */
    public function update(): void
    {
        core_require('employees.edit');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $old = $this->getEmployee($id);

        if (!$old) {
            Session::flash('error', 'Funcionário não encontrado.');
            header('Location: index.php?m=rh&page=employees');
            exit;
        }

        $data = $this->getFormData();
        $errors = $this->validate($data, $id);

        if (!empty($errors)) {
            Session::flash('error', implode('<br>', $errors));
            header('Location: index.php?m=rh&page=employees&action=edit&id=' . $id);
            exit;
        }

        // Upload de foto
        $photoPath = $old['photo'];
        if (!empty($_FILES['photo']['name'])) {
            $upload = Upload::handle('photo', 'employees');
            if ($upload['success']) {
                // Remover foto antiga
                if ($old['photo']) Upload::delete($old['photo']);
                $photoPath = $upload['path'];
            }
        }

        $stmt = $this->db->prepare(
            'UPDATE rh_employees SET full_name=?, cpf=?, birth_date=?, gender=?, phone=?, email=?,
                address_street=?, address_number=?, address_complement=?, address_neighborhood=?,
                address_city=?, address_state=?, address_zip=?, job_position_id=?, department_id=?,
                admission_date=?, contract_type=?, status=?, termination_date=?, leave_date=?, return_date=?,
                regional_council=?, council_number=?, council_expiry=?,
                aso_admissional_date=?, aso_next_date=?,
                photo=?, notes=?
             WHERE id=?'
        );
        $stmt->execute([
            $data['full_name'], $data['cpf'], $data['birth_date'], $data['gender'],
            $data['phone'], $data['email'], $data['address_street'], $data['address_number'],
            $data['address_complement'], $data['address_neighborhood'], $data['address_city'],
            $data['address_state'], $data['address_zip'],
            $data['job_position_id'] ?: null, $data['department_id'] ?: null,
            $data['admission_date'], $data['contract_type'], $data['status'],
            $data['termination_date'] ?: null, $data['leave_date'] ?: null, $data['return_date'] ?: null,
            $data['regional_council'], $data['council_number'], $data['council_expiry'] ?: null,
            $data['aso_admissional_date'] ?: null, $data['aso_next_date'] ?: null,
            $photoPath, $data['notes'], $id
        ]);

        // Registrar alterações no histórico
        $changes = $this->detectChanges($old, $data);
        if ($changes) {
            $this->addRecord($id, 'alteracao', $changes, date('Y-m-d'));
        }

        // Registrar mudança de status
        if ($old['status'] !== $data['status']) {
            $recordType = match($data['status']) {
                'afastado' => 'afastamento',
                'desligado' => 'desligamento',
                'ativo' => 'retorno',
                default => 'alteracao'
            };
            $this->addRecord($id, $recordType,
                'Status alterado de ' . $old['status'] . ' para ' . $data['status'],
                date('Y-m-d')
            );
        }

        AuditLog::log('update', 'employees', $id, $old, $data);
        FileCache::forget('dashboard.global.' . date('Y-m-d'));

        Session::flash('success', 'Funcionário atualizado com sucesso.');
        header('Location: index.php?m=rh&page=employees&action=show&id=' . $id);
        exit;
    }

    /**
     * Anonimizar funcionário (LGPD): preserva o registro mas remove PII.
     * Disponível apenas para perfis com permissão de exclusão e somente para
     * funcionários no status `desligado`.
     */
    public function anonymize(): void
    {
        core_require('employees.delete');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $employee = $this->getEmployee($id);
        if (!$employee) {
            Session::flash('error', 'Funcionário não encontrado.');
            header('Location: index.php?m=rh&page=employees');
            exit;
        }
        if ($employee['status'] !== 'desligado') {
            Session::flash('error', 'Apenas funcionários desligados podem ser anonimizados.');
            header('Location: index.php?m=rh&page=employees&action=show&id=' . $id);
            exit;
        }
        if (!empty($employee['anonymized_at'])) {
            Session::flash('error', 'Funcionário já foi anonimizado.');
            header('Location: index.php?m=rh&page=employees&action=show&id=' . $id);
            exit;
        }

        if (Lgpd::anonymizeEmployee($id)) {
            FileCache::forget('dashboard.global.' . date('Y-m-d'));
            Session::flash('success', 'Funcionário anonimizado conforme LGPD. Registros estatísticos foram preservados.');
        } else {
            Session::flash('error', 'Falha ao anonimizar funcionário.');
        }
        header('Location: index.php?m=rh&page=employees&action=show&id=' . $id);
        exit;
    }

    /**
     * Excluir funcionário
     */
    public function delete(): void
    {
        core_require('employees.delete');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $employee = $this->getEmployee($id);

        if ($employee) {
            if ($employee['photo']) Upload::delete($employee['photo']);
            $stmt = $this->db->prepare('DELETE FROM rh_employees WHERE id = ?');
            $stmt->execute([$id]);
            AuditLog::log('delete', 'employees', $id, $employee);
            FileCache::forget('dashboard.global.' . date('Y-m-d'));
            Session::flash('success', 'Funcionário excluído com sucesso.');
        }

        header('Location: index.php?m=rh&page=employees');
        exit;
    }

    /**
     * Ficha funcional em layout imprimível / exportável para PDF.
     * Uso: navegador > Imprimir > Salvar como PDF (sem dependências extras).
     */
    public function printView(): void
    {
        core_require('employees.view');

        $id = Sanitize::int($_GET['id'] ?? 0);
        $employee = $this->getEmployee($id);
        if (!$employee) {
            Session::flash('error', 'Funcionário não encontrado.');
            header('Location: index.php?m=rh&page=employees');
            exit;
        }

        $stmt = $this->db->prepare('SELECT * FROM rh_employee_documents WHERE employee_id = ? ORDER BY created_at DESC');
        $stmt->execute([$id]);
        $documents = $stmt->fetchAll();

        $stmt = $this->db->prepare(
            'SELECT r.*, u.name as user_name FROM rh_employee_records r
             LEFT JOIN users u ON r.created_by = u.id
             WHERE r.employee_id = ? ORDER BY r.record_date DESC, r.created_at DESC'
        );
        $stmt->execute([$id]);
        $records = $stmt->fetchAll();

        $stmt = $this->db->prepare('SELECT * FROM rh_expirations WHERE employee_id = ? ORDER BY expiry_date ASC');
        $stmt->execute([$id]);
        $expirations = $stmt->fetchAll();

        $stmt = $this->db->prepare('SELECT * FROM rh_medical_certificates WHERE employee_id = ? ORDER BY issue_date DESC');
        $stmt->execute([$id]);
        $certificates = $stmt->fetchAll();

        // Nome do hospital agora vem das configurações do núcleo.
        $hospitalName = Core\Settings::get('org_name', 'Hospital');

        AuditLog::log('print', 'employees', $id);

        // View sem layout padrão: template próprio imprimível.
        require __DIR__ . '/../views/employees/print.php';
    }

    /**
     * Alias amigável para roteamento: ?page=employees&action=print
     */
    public function print(): void
    {
        $this->printView();
    }

    /**
     * Exportar funcionários para CSV
     */
    public function export(): void
    {
        core_require('employees.export');

        $status = Sanitize::get('status');
        $where = $status ? 'WHERE e.status = ?' : '';
        $params = $status ? [$status] : [];

        $sql = "SELECT e.*, d.name as department_name, j.title as position_title
                FROM rh_employees e
                LEFT JOIN rh_departments d ON e.department_id = d.id
                LEFT JOIN rh_job_positions j ON e.job_position_id = j.id
                {$where}
                ORDER BY e.full_name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll();

        $headers = ['Nome', 'CPF', 'Nascimento', 'Sexo', 'Telefone', 'E-mail',
                     'Cargo', 'Departamento', 'Admissão', 'Contrato', 'Status'];
        $rows = [];
        foreach ($employees as $e) {
            $rows[] = [
                $e['full_name'],
                Sanitize::formatCpf($e['cpf']),
                Sanitize::formatDate($e['birth_date']),
                $e['gender'],
                $e['phone'],
                $e['email'],
                $e['position_title'] ?? '-',
                $e['department_name'] ?? '-',
                Sanitize::formatDate($e['admission_date']),
                $e['contract_type'],
                $e['status'],
            ];
        }

        AuditLog::log('export', 'employees');
        Export::csv('funcionarios_' . date('Y-m-d') . '.csv', $headers, $rows);
    }

    // ---- Métodos auxiliares ----

    private function getEmployee(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT e.*, d.name as department_name, j.title as position_title
             FROM rh_employees e
             LEFT JOIN rh_departments d ON e.department_id = d.id
             LEFT JOIN rh_job_positions j ON e.job_position_id = j.id
             WHERE e.id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function getFormData(): array
    {
        return [
            'full_name'            => Sanitize::post('full_name'),
            'cpf'                  => Sanitize::cpf($_POST['cpf'] ?? ''),
            'birth_date'           => Sanitize::date($_POST['birth_date'] ?? ''),
            'gender'               => Sanitize::post('gender'),
            'phone'                => Sanitize::phone($_POST['phone'] ?? ''),
            'email'                => Sanitize::email($_POST['email'] ?? ''),
            'address_street'       => Sanitize::post('address_street'),
            'address_number'       => Sanitize::post('address_number'),
            'address_complement'   => Sanitize::post('address_complement'),
            'address_neighborhood' => Sanitize::post('address_neighborhood'),
            'address_city'         => Sanitize::post('address_city'),
            'address_state'        => Sanitize::post('address_state'),
            'address_zip'          => Sanitize::post('address_zip'),
            'job_position_id'      => Sanitize::int($_POST['job_position_id'] ?? 0),
            'department_id'        => Sanitize::int($_POST['department_id'] ?? 0),
            'admission_date'       => Sanitize::date($_POST['admission_date'] ?? ''),
            'contract_type'        => Sanitize::post('contract_type'),
            'status'               => Sanitize::post('status'),
            'termination_date'     => Sanitize::date($_POST['termination_date'] ?? ''),
            'leave_date'           => Sanitize::date($_POST['leave_date'] ?? ''),
            'return_date'          => Sanitize::date($_POST['return_date'] ?? ''),
            'regional_council'     => Sanitize::post('regional_council') ?: 'N/A',
            'council_number'       => Sanitize::post('council_number'),
            'council_expiry'       => Sanitize::date($_POST['council_expiry'] ?? ''),
            'aso_admissional_date' => Sanitize::date($_POST['aso_admissional_date'] ?? ''),
            'aso_next_date'        => Sanitize::date($_POST['aso_next_date'] ?? ''),
            'notes'                => Sanitize::post('notes'),
        ];
    }

    private function validate(array $data, int $excludeId = 0): array
    {
        $errors = [];
        if (empty($data['full_name'])) $errors[] = 'Nome completo é obrigatório.';
        if (empty($data['cpf'])) $errors[] = 'CPF é obrigatório.';
        elseif (!Sanitize::isValidCpf($data['cpf'])) $errors[] = 'CPF inválido.';
        if (empty($data['birth_date'])) $errors[] = 'Data de nascimento é obrigatória.';
        if (empty($data['gender'])) $errors[] = 'Sexo é obrigatório.';
        if (empty($data['admission_date'])) $errors[] = 'Data de admissão é obrigatória.';

        // ASO obrigatório (P requisito 1/2 do funcionário).
        if (empty($data['aso_admissional_date'])) {
            $errors[] = 'Data do último exame admissional/periódico é obrigatória.';
        }
        if (empty($data['aso_next_date'])) {
            $errors[] = 'Data do próximo exame (ASO) é obrigatória.';
        } elseif (!empty($data['aso_admissional_date']) && $data['aso_next_date'] <= $data['aso_admissional_date']) {
            $errors[] = 'A data do próximo ASO deve ser posterior ao último exame.';
        }

        // Conselho Regional: valida domínio e exige número+validade quando aplicável.
        $allowedCouncils = ['N/A', 'COREN', 'CRM', 'CRF'];
        if (!in_array($data['regional_council'] ?? '', $allowedCouncils, true)) {
            $errors[] = 'Conselho Regional inválido.';
        }
        if (($data['regional_council'] ?? 'N/A') !== 'N/A') {
            if (empty($data['council_number']))  $errors[] = 'Número do conselho é obrigatório.';
            if (empty($data['council_expiry'])) $errors[] = 'Data de validade do conselho é obrigatória.';
        }

        // Verificar CPF duplicado
        if (!empty($data['cpf'])) {
            $sql = 'SELECT id FROM rh_employees WHERE cpf = ?';
            $params = [$data['cpf']];
            if ($excludeId) {
                $sql .= ' AND id != ?';
                $params[] = $excludeId;
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetch()) {
                $errors[] = 'Já existe um funcionário com este CPF.';
            }
        }

        return $errors;
    }

    private function addRecord(int $employeeId, string $type, string $description, string $date): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO rh_employee_records (employee_id, record_type, description, record_date, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$employeeId, $type, $description, $date, Session::userId()]);
    }

    private function detectChanges(array $old, array $new): string
    {
        $labels = [
            'full_name' => 'Nome', 'cpf' => 'CPF', 'phone' => 'Telefone',
            'email' => 'E-mail', 'contract_type' => 'Contrato', 'status' => 'Status',
            'department_id' => 'Departamento', 'job_position_id' => 'Cargo',
        ];
        $changes = [];
        foreach ($labels as $field => $label) {
            $oldVal = $old[$field] ?? '';
            $newVal = $new[$field] ?? '';
            if ((string)$oldVal !== (string)$newVal) {
                $changes[] = "{$label}: {$oldVal} → {$newVal}";
            }
        }
        return implode('; ', $changes);
    }
}
