<?php
/**
 * Controller de Processos Seletivos (Módulo 7)
 */
class RecruitmentController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        Auth::requirePermission('recruitment', 'view');

        $status = Sanitize::get('status');
        $where = $status ? 'WHERE rj.status = ?' : '';
        $params = $status ? [$status] : [];

        $sql = "SELECT rj.*, d.name as department_name,
                       (SELECT COUNT(*) FROM rh_candidates c WHERE c.job_id = rj.id) as candidate_count
                FROM rh_recruitment_jobs rj
                LEFT JOIN rh_departments d ON rj.department_id = d.id
                {$where}
                ORDER BY rj.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $jobs = $stmt->fetchAll();

        $pageTitle = 'Processos Seletivos';
        $page = 'recruitment';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/recruitment/index.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function create(): void
    {
        Auth::requirePermission('recruitment', 'create');

        $departments = $this->db->query('SELECT id, name FROM rh_departments WHERE active = 1 ORDER BY name')->fetchAll();
        $job = null;

        $pageTitle = 'Nova Vaga';
        $page = 'recruitment';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/recruitment/form.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function store(): void
    {
        Auth::requirePermission('recruitment', 'create');
        Csrf::check();

        $title        = Sanitize::post('title');
        $description  = Sanitize::post('description');
        $requirements = Sanitize::post('requirements');
        $departmentId = Sanitize::int($_POST['department_id'] ?? 0);
        $status       = Sanitize::post('status') ?: 'aberta';

        if (empty($title)) {
            Session::flash('error', 'Título é obrigatório.');
            header('Location: index.php?m=rh&page=recruitment&action=create');
            exit;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO rh_recruitment_jobs (title, description, requirements, department_id, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$title, $description, $requirements, $departmentId ?: null, $status, Session::userId()]);
        $jobId = (int)$this->db->lastInsertId();

        // Criar etapas padrão
        $defaultSteps = ['Triagem', 'Entrevista RH', 'Teste Prático', 'Entrevista Final', 'Contratação'];
        foreach ($defaultSteps as $order => $stepName) {
            $stmt = $this->db->prepare('INSERT INTO rh_recruitment_steps (job_id, name, step_order) VALUES (?, ?, ?)');
            $stmt->execute([$jobId, $stepName, $order + 1]);
        }

        AuditLog::log('create', 'recruitment_jobs', $jobId);
        Session::flash('success', 'Vaga criada com sucesso.');
        header('Location: index.php?m=rh&page=recruitment&action=show&id=' . $jobId);
        exit;
    }

    public function show(): void
    {
        Auth::requirePermission('recruitment', 'view');

        $id = Sanitize::int($_GET['id'] ?? 0);
        $stmt = $this->db->prepare(
            'SELECT rj.*, d.name as department_name
             FROM rh_recruitment_jobs rj
             LEFT JOIN rh_departments d ON rj.department_id = d.id
             WHERE rj.id = ?'
        );
        $stmt->execute([$id]);
        $job = $stmt->fetch();

        if (!$job) {
            Session::flash('error', 'Vaga não encontrada.');
            header('Location: index.php?m=rh&page=recruitment');
            exit;
        }

        // Etapas
        $steps = $this->db->prepare('SELECT * FROM rh_recruitment_steps WHERE job_id = ? ORDER BY step_order');
        $steps->execute([$id]);
        $steps = $steps->fetchAll();

        // Candidatos por etapa (Kanban)
        $candidatesByStep = [];
        foreach ($steps as $step) {
            $stmt = $this->db->prepare(
                'SELECT c.* FROM rh_candidates c WHERE c.job_id = ? AND c.current_step_id = ? ORDER BY c.created_at DESC'
            );
            $stmt->execute([$id, $step['id']]);
            $candidatesByStep[$step['id']] = $stmt->fetchAll();
        }

        // Candidatos sem etapa (recém inscritos)
        $stmt = $this->db->prepare(
            'SELECT c.* FROM rh_candidates c WHERE c.job_id = ? AND c.current_step_id IS NULL ORDER BY c.created_at DESC'
        );
        $stmt->execute([$id]);
        $newCandidates = $stmt->fetchAll();

        $pageTitle = $job['title'];
        $page = 'recruitment';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/recruitment/show.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function edit(): void
    {
        Auth::requirePermission('recruitment', 'edit');

        $id = Sanitize::int($_GET['id'] ?? 0);
        $stmt = $this->db->prepare('SELECT * FROM rh_recruitment_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $job = $stmt->fetch();

        if (!$job) {
            Session::flash('error', 'Vaga não encontrada.');
            header('Location: index.php?m=rh&page=recruitment');
            exit;
        }

        $departments = $this->db->query('SELECT id, name FROM rh_departments WHERE active = 1 ORDER BY name')->fetchAll();

        $pageTitle = 'Editar Vaga';
        $page = 'recruitment';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/recruitment/form.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function update(): void
    {
        Auth::requirePermission('recruitment', 'edit');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);

        $stmt = $this->db->prepare(
            'UPDATE rh_recruitment_jobs SET title=?, description=?, requirements=?, department_id=?, status=? WHERE id=?'
        );
        $stmt->execute([
            Sanitize::post('title'),
            Sanitize::post('description'),
            Sanitize::post('requirements'),
            Sanitize::int($_POST['department_id'] ?? 0) ?: null,
            Sanitize::post('status'),
            $id
        ]);

        AuditLog::log('update', 'recruitment_jobs', $id);
        Session::flash('success', 'Vaga atualizada.');
        header('Location: index.php?m=rh&page=recruitment&action=show&id=' . $id);
        exit;
    }

    /**
     * Mover candidato para outra etapa.
     *
     * Suporta dois fluxos:
     *   - Form HTML clássico (legacy): redireciona com flash.
     *   - AJAX (X-Requested-With): retorna JSON sem redirecionar.
     */
    public function move_candidate(): void
    {
        Auth::requirePermission('recruitment', 'edit');
        Csrf::check();

        $candidateId = Sanitize::int($_POST['candidate_id'] ?? 0);
        $stepId      = Sanitize::int($_POST['step_id'] ?? 0);
        $jobId       = Sanitize::int($_POST['job_id'] ?? 0);
        $notes       = Sanitize::post('notes');

        // Validações: etapa deve pertencer à vaga, candidato à vaga.
        $stmt = $this->db->prepare(
            'SELECT (SELECT job_id FROM rh_recruitment_steps WHERE id = ?) AS step_job,
                    (SELECT job_id FROM rh_candidates WHERE id = ?)        AS cand_job'
        );
        $stmt->execute([$stepId, $candidateId]);
        $check = $stmt->fetch();
        if (!$check || (int)$check['step_job'] !== $jobId || (int)$check['cand_job'] !== $jobId) {
            $this->moveResponse(false, 'Vaga, candidato ou etapa inválidos.', $jobId);
        }

        $stmt = $this->db->prepare('UPDATE rh_candidates SET current_step_id = ?, status = "em_andamento" WHERE id = ?');
        $stmt->execute([$stepId, $candidateId]);

        $stmt = $this->db->prepare(
            'INSERT INTO rh_candidate_progress (candidate_id, step_id, status, notes, evaluated_by, evaluated_at)
             VALUES (?, ?, "pendente", ?, ?, NOW())'
        );
        $stmt->execute([$candidateId, $stepId, $notes, Session::userId()]);

        AuditLog::log('update', 'candidates', $candidateId);

        $this->moveResponse(true, 'Candidato movido com sucesso.', $jobId);
    }

    private function moveResponse(bool $ok, string $msg, int $jobId): void
    {
        if ($this->isAjax()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($ok ? 200 : 400);
            echo json_encode(['ok' => $ok, 'message' => $msg], JSON_UNESCAPED_UNICODE);
            exit;
        }
        Session::flash($ok ? 'success' : 'error', $msg);
        header('Location: index.php?m=rh&page=recruitment&action=show&id=' . $jobId);
        exit;
    }

    private function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    /**
     * Aprovar/Reprovar candidato
     */
    public function evaluate_candidate(): void
    {
        Auth::requirePermission('recruitment', 'edit');
        Csrf::check();

        $candidateId = Sanitize::int($_POST['candidate_id'] ?? 0);
        $status      = Sanitize::post('candidate_status'); // aprovado ou reprovado
        $jobId       = Sanitize::int($_POST['job_id'] ?? 0);
        $notes       = Sanitize::post('notes');

        $stmt = $this->db->prepare('UPDATE rh_candidates SET status = ?, notes = CONCAT(IFNULL(notes,""), "\n", ?) WHERE id = ?');
        $stmt->execute([$status, date('d/m/Y') . ': ' . $notes, $candidateId]);

        // Se reprovado, enviar para banco de talentos
        if ($status === 'reprovado') {
            $stmt = $this->db->prepare('UPDATE rh_candidates SET in_talent_pool = 1 WHERE id = ?');
            $stmt->execute([$candidateId]);
        }

        AuditLog::log('evaluate', 'candidates', $candidateId);
        Session::flash('success', 'Candidato avaliado com sucesso.');
        header('Location: index.php?m=rh&page=recruitment&action=show&id=' . $jobId);
        exit;
    }

    public function delete(): void
    {
        Auth::requirePermission('recruitment', 'delete');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $this->db->prepare('DELETE FROM rh_recruitment_jobs WHERE id = ?')->execute([$id]);
        AuditLog::log('delete', 'recruitment_jobs', $id);
        Session::flash('success', 'Vaga excluída.');
        header('Location: index.php?m=rh&page=recruitment');
        exit;
    }
}
