<?php
/**
 * Controller de Atestados Médicos
 */
class CertificateController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        header('Location: index.php?m=rh&page=employees');
        exit;
    }

    public function create(): void
    {
        Auth::requirePermission('certificates', 'create');
        $employeeId = Sanitize::int($_GET['employee_id'] ?? 0);

        $stmt = $this->db->prepare('SELECT id, full_name FROM rh_employees WHERE id = ?');
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch();

        if (!$employee) {
            Session::flash('error', 'Funcionário não encontrado.');
            header('Location: index.php?m=rh&page=employees');
            exit;
        }

        $pageTitle = 'Novo Atestado';
        $page = 'employees';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/employees/certificate_form.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function store(): void
    {
        Auth::requirePermission('certificates', 'create');
        Csrf::check();

        $employeeId = Sanitize::int($_POST['employee_id'] ?? 0);
        $issueDate  = Sanitize::date($_POST['issue_date'] ?? '');
        $days       = Sanitize::int($_POST['days'] ?? 1);
        $cid        = Sanitize::post('cid');
        $doctorName = Sanitize::post('doctor_name');
        $doctorCrm  = Sanitize::post('doctor_crm');
        $notes      = Sanitize::post('notes');

        if (!$employeeId || !$issueDate) {
            Session::flash('error', 'Preencha os campos obrigatórios.');
            header('Location: index.php?m=rh&page=certificates&action=create&employee_id=' . $employeeId);
            exit;
        }
        if ($days < 1) $days = 1;

        $filePath = null;
        if (!empty($_FILES['file']['name'])) {
            $upload = Upload::handle('file', 'certificates');
            if ($upload['success']) $filePath = $upload['path'];
        }

        // Data prevista de retorno = issue_date + days.
        $returnDate = date('Y-m-d', strtotime($issueDate . " +{$days} days"));

        $this->db->beginTransaction();
        try {
            // 1) Grava o atestado.
            $stmt = $this->db->prepare(
                'INSERT INTO rh_medical_certificates (employee_id, issue_date, days, cid, doctor_name, doctor_crm, notes, file_path, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$employeeId, $issueDate, $days, $cid, $doctorName, $doctorCrm, $notes, $filePath, Session::userId()]);
            $certId = (int)$this->db->lastInsertId();

            // 2) Carrega status atual do funcionário (para saber se houve mudança).
            $stmt = $this->db->prepare('SELECT status FROM rh_employees WHERE id = ?');
            $stmt->execute([$employeeId]);
            $previousStatus = $stmt->fetchColumn();

            // 3) Atualiza status para "afastado", preenchendo leave_date e
            //    return_date (baseado na duração do atestado).
            $stmt = $this->db->prepare(
                "UPDATE rh_employees
                    SET status = 'afastado',
                        leave_date = ?,
                        return_date = ?
                  WHERE id = ?"
            );
            $stmt->execute([$issueDate, $returnDate, $employeeId]);

            // 4) Registra no histórico do funcionário a mudança de status.
            if ($previousStatus !== 'afastado') {
                $stmt = $this->db->prepare(
                    'INSERT INTO rh_employee_records (employee_id, record_type, description, record_date, created_by)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $employeeId,
                    'afastamento',
                    'Afastado por atestado médico de ' . $days . ' dia(s)'
                        . ($cid ? ' (CID ' . $cid . ')' : '')
                        . '. Retorno previsto: ' . date('d/m/Y', strtotime($returnDate)) . '.',
                    $issueDate,
                    Session::userId(),
                ]);
            }

            // 5) Agenda um compromisso de retorno ao trabalho.
            $stmt = $this->db->prepare(
                'INSERT INTO rh_schedules (employee_id, title, description, event_date, event_type, color, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $employeeId,
                'Retorno de afastamento',
                'Fim previsto do atestado médico (' . $days . ' dia(s))'
                    . ($cid ? ' — CID ' . $cid : ''),
                $returnDate,
                'compromisso',
                '#fd7e14',
                Session::userId(),
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            Session::flash('error', 'Falha ao cadastrar atestado: ' . Sanitize::e($e->getMessage()));
            header('Location: index.php?m=rh&page=certificates&action=create&employee_id=' . $employeeId);
            exit;
        }

        AuditLog::log('create', 'medical_certificates', $certId, null, [
            'employee_id' => $employeeId,
            'issue_date'  => $issueDate,
            'days'        => $days,
            'return_date' => $returnDate,
            'new_status'  => 'afastado',
        ]);
        FileCache::forget('dashboard.global.' . date('Y-m-d'));

        Session::flash('success', 'Atestado cadastrado. Funcionário marcado como afastado até ' . date('d/m/Y', strtotime($returnDate)) . '.');
        header('Location: index.php?m=rh&page=employees&action=show&id=' . $employeeId);
        exit;
    }
}
