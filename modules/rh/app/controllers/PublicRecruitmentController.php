<?php
/**
 * Controller público para candidatos se inscreverem em vagas
 * Não requer autenticação
 */
class PublicRecruitmentController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Listar vagas abertas
     */
    public function index(): void
    {
        $jobs = $this->db->query(
            "SELECT rj.*, d.name as department_name
             FROM recruitment_jobs rj
             LEFT JOIN departments d ON rj.department_id = d.id
             WHERE rj.status = 'aberta'
             ORDER BY rj.created_at DESC"
        )->fetchAll();

        require __DIR__ . '/../views/public_recruitment/index.php';
    }

    /**
     * Formulário de inscrição
     */
    public function apply(): void
    {
        $jobId = Sanitize::int($_GET['job_id'] ?? 0);
        $stmt = $this->db->prepare(
            "SELECT rj.*, d.name as department_name
             FROM recruitment_jobs rj
             LEFT JOIN departments d ON rj.department_id = d.id
             WHERE rj.id = ? AND rj.status = 'aberta'"
        );
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();

        if (!$job) {
            $error = 'Vaga não encontrada ou já encerrada.';
            require __DIR__ . '/../views/public_recruitment/index.php';
            return;
        }

        $success = Session::flash('success');
        $error = Session::flash('error');

        require __DIR__ . '/../views/public_recruitment/apply.php';
    }

    /**
     * Processar inscrição
     */
    public function submit(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?page=public_recruitment');
            exit;
        }

        Csrf::check();

        $jobId    = Sanitize::int($_POST['job_id'] ?? 0);

        // --- Proteção anti-bot ---
        // Honeypot: se preenchido, é bot. Retornamos sucesso "fake" para não
        // dar feedback sobre a proteção.
        if (!empty($_POST['website'])) {
            Session::flash('success', 'Inscrição recebida.');
            header('Location: index.php?page=public_recruitment&action=apply&job_id=' . $jobId);
            exit;
        }

        // Timestamp: submissões em menos de 3s também são suspeitas (bot).
        $ts = (int)($_POST['form_ts'] ?? 0);
        if ($ts <= 0 || (time() - $ts) < 3) {
            Session::flash('error', 'Por favor, preencha o formulário com calma e tente novamente.');
            header('Location: index.php?page=public_recruitment&action=apply&job_id=' . $jobId);
            exit;
        }

        // Rate-limit por IP.
        $config  = require __DIR__ . '/../../config/app.php';
        $maxHour = (int)($config['public_apply_max_per_hour'] ?? 3);
        $ip      = RateLimit::clientIp();
        if ($maxHour > 0 && RateLimit::recentPublicSubmissions($ip, 60) >= $maxHour) {
            Session::flash('error', 'Limite de envios excedido. Tente novamente mais tarde.');
            header('Location: index.php?page=public_recruitment&action=apply&job_id=' . $jobId);
            exit;
        }

        $fullName   = Sanitize::post('full_name');
        $email      = Sanitize::email($_POST['email'] ?? '');
        $phone      = Sanitize::phone($_POST['phone'] ?? '');
        $cpf        = Sanitize::cpf($_POST['cpf'] ?? '');
        $area       = Sanitize::post('area');
        $experience = Sanitize::post('experience');
        $lgpdGiven  = !empty($_POST['lgpd_consent']);

        // Validações básicas.
        if (!$lgpdGiven) {
            Session::flash('error', 'É necessário aceitar a Política de Privacidade (LGPD) para se inscrever.');
            header('Location: index.php?page=public_recruitment&action=apply&job_id=' . $jobId);
            exit;
        }
        if (empty($fullName) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Session::flash('error', 'Nome e e-mail válidos são obrigatórios.');
            header('Location: index.php?page=public_recruitment&action=apply&job_id=' . $jobId);
            exit;
        }
        if (!empty($cpf) && !Sanitize::isValidCpf($cpf)) {
            Session::flash('error', 'CPF inválido.');
            header('Location: index.php?page=public_recruitment&action=apply&job_id=' . $jobId);
            exit;
        }
        if (mb_strlen($fullName) > 200 || mb_strlen($experience) > 5000) {
            Session::flash('error', 'Dados enviados são muito extensos.');
            header('Location: index.php?page=public_recruitment&action=apply&job_id=' . $jobId);
            exit;
        }

        // Checa candidato duplicado na mesma vaga.
        if ($jobId && RateLimit::candidateAlreadyApplied($jobId, $email)) {
            Session::flash('error', 'Você já se candidatou a esta vaga com este e-mail.');
            header('Location: index.php?page=public_recruitment&action=apply&job_id=' . $jobId);
            exit;
        }

        // Valida a vaga.
        $stmt = $this->db->prepare("SELECT id FROM recruitment_jobs WHERE id = ? AND status = 'aberta'");
        $stmt->execute([$jobId]);
        if (!$stmt->fetch()) {
            Session::flash('error', 'Vaga não encontrada ou já encerrada.');
            header('Location: index.php?page=public_recruitment');
            exit;
        }

        // Upload do currículo (armazenado em storage/ — privado).
        $resumePath = null;
        if (!empty($_FILES['resume']['name'])) {
            $upload = Upload::handle('resume', 'resumes');
            if (!$upload['success']) {
                Session::flash('error', 'Erro no envio do currículo: ' . $upload['error']);
                header('Location: index.php?page=public_recruitment&action=apply&job_id=' . $jobId);
                exit;
            }
            $resumePath = $upload['path'];
        }

        // Token de acesso para acompanhamento (mín. 32 bytes = 64 chars).
        $accessToken = bin2hex(random_bytes(32));

        $stmt = $this->db->prepare(
            'INSERT INTO candidates
                (job_id, full_name, email, phone, cpf, area, experience, resume_path, access_token, status,
                 lgpd_consent_at, lgpd_consent_ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "inscrito", NOW(), ?)'
        );
        $stmt->execute([$jobId, $fullName, $email, $phone, $cpf, $area, $experience, $resumePath, $accessToken, $ip]);

        // Registra para rate-limiting.
        RateLimit::recordPublicSubmission($ip, $jobId, $email);

        Session::flash('success', 'Inscrição realizada com sucesso! Seu código de acompanhamento: ' . substr($accessToken, 0, 12));
        header('Location: index.php?page=public_recruitment&action=apply&job_id=' . $jobId);
        exit;
    }

    /**
     * Acompanhar status da candidatura
     */
    public function track(): void
    {
        $token = Sanitize::get('token');
        $candidate = null;
        $progress = [];

        // Exige token com tamanho mínimo (12 chars) para evitar enumeração
        // via prefixos curtos.
        if ($token && strlen($token) >= 12) {
            $stmt = $this->db->prepare(
                'SELECT c.*, rj.title as job_title, rs.name as current_step_name
                 FROM candidates c
                 LEFT JOIN recruitment_jobs rj ON c.job_id = rj.id
                 LEFT JOIN recruitment_steps rs ON c.current_step_id = rs.id
                 WHERE c.access_token LIKE ?'
            );
            $stmt->execute([$token . '%']);
            $candidate = $stmt->fetch();

            if ($candidate) {
                $stmt = $this->db->prepare(
                    'SELECT cp.*, rs.name as step_name
                     FROM candidate_progress cp
                     JOIN recruitment_steps rs ON cp.step_id = rs.id
                     WHERE cp.candidate_id = ?
                     ORDER BY cp.created_at ASC'
                );
                $stmt->execute([$candidate['id']]);
                $progress = $stmt->fetchAll();
            }
        }

        require __DIR__ . '/../views/public_recruitment/track.php';
    }
}
