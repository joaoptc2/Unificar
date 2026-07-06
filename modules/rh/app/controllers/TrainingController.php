<?php
class TrainingController
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }
    public function index(): void
    {
        core_require('trainings.view');
        $catalog = $this->db->query('SELECT tc.*, d.name AS department_name FROM rh_training_catalog tc LEFT JOIN rh_departments d ON tc.department_id = d.id WHERE tc.active = 1 ORDER BY tc.title')->fetchAll();
        $expiring = TrainingRecord::expiringInDays(30);
        View::render('trainings/index', ['pageTitle' => 'Treinamentos', 'page' => 'trainings', 'catalog' => $catalog, 'expiring' => $expiring]);
    }
    public function store_catalog(): void
    {
        core_require('trainings.create'); Csrf::check();
        $title = Sanitize::post('title');
        if (!$title) { Session::flash('error', 'Titulo obrigatorio.'); header('Location: index.php?m=rh&page=trainings'); exit; }
        $this->db->prepare('INSERT INTO rh_training_catalog (title, description, category, hours, mandatory, validity_months, department_id) VALUES (?,?,?,?,?,?,?)')
                 ->execute([$title, Sanitize::post('description'), Sanitize::post('category'),
                     $_POST['hours'] ? (float)$_POST['hours'] : null, !empty($_POST['mandatory']) ? 1 : 0,
                     Sanitize::int($_POST['validity_months'] ?? 0) ?: null,
                     Sanitize::int($_POST['department_id'] ?? 0) ?: null]);
        AuditLog::log('create', 'training_catalog', (int)$this->db->lastInsertId());
        Session::flash('success', 'Treinamento adicionado ao catalogo.');
        header('Location: index.php?m=rh&page=trainings'); exit;
    }
    public function record(): void
    {
        core_require('trainings.create'); Csrf::check();
        $empId = Sanitize::int($_POST['employee_id'] ?? 0);
        $catId = Sanitize::int($_POST['catalog_id'] ?? 0);
        $title = Sanitize::post('title'); $date = Sanitize::date($_POST['completed_at'] ?? '');
        if (!$empId || !$title || !$date) { Session::flash('error', 'Preencha os campos obrigatorios.'); header('Location: index.php?m=rh&page=employees&action=show&id=' . $empId); exit; }
        $expiresAt = null;
        if ($catId) {
            $stmt = $this->db->prepare('SELECT validity_months FROM rh_training_catalog WHERE id = ?'); $stmt->execute([$catId]);
            $months = (int)$stmt->fetchColumn();
            if ($months > 0) $expiresAt = date('Y-m-d', strtotime($date . " +{$months} months"));
        }
        $certPath = null;
        if (!empty($_FILES['certificate']['name'])) { $u = Upload::handle('certificate', 'documents'); if ($u['success']) $certPath = $u['path']; }
        $id = TrainingRecord::insert([
            'employee_id' => $empId, 'catalog_id' => $catId ?: null, 'title' => $title,
            'completed_at' => $date, 'expires_at' => $expiresAt,
            'hours' => $_POST['hours'] ? (float)$_POST['hours'] : null,
            'certificate_path' => $certPath, 'notes' => Sanitize::post('notes'), 'created_by' => Session::userId(),
        ]);
        if ($expiresAt) {
            ExpirationController::syncToSchedule($this->db, 0, $empId, 'Treinamento: ' . $title, $expiresAt, 'Vencimento de treinamento');
        }
        AuditLog::log('create', 'training_records', $id);
        Session::flash('success', 'Treinamento registrado.'); header('Location: index.php?m=rh&page=employees&action=show&id=' . $empId); exit;
    }
    public function delete_record(): void
    {
        core_require('trainings.delete'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0); $empId = Sanitize::int($_POST['employee_id'] ?? 0);
        $r = TrainingRecord::find($id); if ($r && $r['certificate_path']) Upload::delete($r['certificate_path']);
        TrainingRecord::delete($id); AuditLog::log('delete', 'training_records', $id);
        Session::flash('success', 'Registro removido.'); header('Location: index.php?m=rh&page=employees&action=show&id=' . $empId); exit;
    }
}
