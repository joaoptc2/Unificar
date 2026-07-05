<?php
/**
 * ProcessController — Workflow / process tracking with steps.
 *
 * Every public method corresponds to a ?page=processes&action=X route.
 */
class ProcessController
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /* ------------------------------------------------------------------
     *  index  — List active processes
     *  GET ?page=processes
     * ----------------------------------------------------------------*/
    public function index(): void
    {
        Auth::requireLogin();

        $processes = Process::active();

        View::render('processes/index', [
            'pageTitle' => 'Processos',
            'processes' => $processes,
        ]);
    }

    /* ------------------------------------------------------------------
     *  show  — Display a single process with its steps
     *  GET ?page=processes&action=show&id=N
     * ----------------------------------------------------------------*/
    public function show(): void
    {
        Auth::requireLogin();

        $id = isset($_GET['id']) ? Sanitize::int($_GET['id']) : 0;
        if ($id <= 0) {
            Session::flash('error', 'Processo inválido.');
            header('Location: index.php?m=chat&page=processes');
            exit;
        }

        $process = Process::withSteps($id);
        if (!$process) {
            Session::flash('error', 'Processo não encontrado.');
            header('Location: index.php?m=chat&page=processes');
            exit;
        }

        View::render('processes/show', [
            'pageTitle' => Sanitize::e($process['title']),
            'process'   => $process,
            'userId'    => Session::userId(),
        ]);
    }

    /* ------------------------------------------------------------------
     *  create  — Show the new-process form
     *  GET ?page=processes&action=create
     * ----------------------------------------------------------------*/
    public function create(): void
    {
        Auth::requireLogin();

        $users    = User::active();
        $channels = Channel::userChannels(Session::userId());

        View::render('processes/form', [
            'pageTitle' => 'Novo Processo',
            'process'   => null,
            'users'     => $users,
            'channels'  => $channels,
        ]);
    }

    /* ------------------------------------------------------------------
     *  store  — Persist a new process with its steps
     *  POST ?page=processes&action=store
     * ----------------------------------------------------------------*/
    public function store(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $userId = Session::userId();

        $title       = Sanitize::string($_POST['title'] ?? '');
        $description = Sanitize::string($_POST['description'] ?? '');
        $channelId   = Sanitize::int($_POST['channel_id'] ?? 0);

        if ($title === '') {
            Session::flash('error', 'O título é obrigatório.');
            header('Location: index.php?m=chat&page=processes&action=create');
            exit;
        }

        $processId = Process::insert([
            'title'       => $title,
            'description' => $description,
            'channel_id'  => $channelId > 0 ? $channelId : null,
            'status'      => 'active',
            'progress'    => 0,
            'created_by'  => $userId,
        ]);

        // Create steps from form arrays
        $stepTitles      = $_POST['step_title'] ?? [];
        $stepDescriptions = $_POST['step_description'] ?? [];
        $stepAssignedTo  = $_POST['step_assigned_to'] ?? [];
        $stepDueDates    = $_POST['step_due_date'] ?? [];
        $stepOrders      = $_POST['step_order'] ?? [];

        foreach ($stepTitles as $i => $sTitle) {
            $sTitle = Sanitize::string($sTitle);
            if ($sTitle === '') continue;

            Process::addStep($processId, [
                'title'       => $sTitle,
                'description' => Sanitize::string($stepDescriptions[$i] ?? ''),
                'assigned_to' => !empty($stepAssignedTo[$i]) ? Sanitize::int($stepAssignedTo[$i]) : null,
                'due_date'    => !empty($stepDueDates[$i]) ? Sanitize::string($stepDueDates[$i]) : null,
                'order_num'   => !empty($stepOrders[$i]) ? Sanitize::int($stepOrders[$i]) : $i + 1,
            ]);
        }

        Process::recalculateProgress($processId);

        AuditLog::log('create', 'process', $processId, null, [
            'title'      => $title,
            'step_count' => count(array_filter($stepTitles, fn($s) => trim($s) !== '')),
        ]);

        Session::flash('success', 'Processo criado com sucesso.');
        header('Location: index.php?m=chat&page=processes&action=show&id=' . $processId);
        exit;
    }

    /* ------------------------------------------------------------------
     *  edit  — Show the edit form for an existing process
     *  GET ?page=processes&action=edit&id=N
     * ----------------------------------------------------------------*/
    public function edit(): void
    {
        Auth::requireLogin();

        $id = isset($_GET['id']) ? Sanitize::int($_GET['id']) : 0;

        $process = Process::withSteps($id);
        if (!$process) {
            Session::flash('error', 'Processo não encontrado.');
            header('Location: index.php?m=chat&page=processes');
            exit;
        }

        $users    = User::active();
        $channels = Channel::userChannels(Session::userId());

        View::render('processes/form', [
            'pageTitle' => 'Editar Processo',
            'process'   => $process,
            'users'     => $users,
            'channels'  => $channels,
        ]);
    }

    /* ------------------------------------------------------------------
     *  update  — Persist changes to an existing process
     *  POST ?page=processes&action=update
     * ----------------------------------------------------------------*/
    public function update(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $process = Process::find($id);
        if (!$process) {
            Session::flash('error', 'Processo não encontrado.');
            header('Location: index.php?m=chat&page=processes');
            exit;
        }

        $title       = Sanitize::string($_POST['title'] ?? '');
        $description = Sanitize::string($_POST['description'] ?? '');
        $channelId   = Sanitize::int($_POST['channel_id'] ?? 0);

        if ($title === '') {
            Session::flash('error', 'O título é obrigatório.');
            header('Location: index.php?m=chat&page=processes&action=edit&id=' . $id);
            exit;
        }

        $oldData = $process;

        Process::update($id, [
            'title'       => $title,
            'description' => $description,
            'channel_id'  => $channelId > 0 ? $channelId : null,
        ]);

        // Delete old steps and recreate from form arrays
        $this->db->prepare('DELETE FROM chat_process_steps WHERE process_id = ?')->execute([$id]);

        $stepTitles       = $_POST['step_title'] ?? [];
        $stepDescriptions = $_POST['step_description'] ?? [];
        $stepAssignedTo   = $_POST['step_assigned_to'] ?? [];
        $stepDueDates     = $_POST['step_due_date'] ?? [];
        $stepOrders       = $_POST['step_order'] ?? [];

        foreach ($stepTitles as $i => $sTitle) {
            $sTitle = Sanitize::string($sTitle);
            if ($sTitle === '') continue;

            Process::addStep($id, [
                'title'       => $sTitle,
                'description' => Sanitize::string($stepDescriptions[$i] ?? ''),
                'assigned_to' => !empty($stepAssignedTo[$i]) ? Sanitize::int($stepAssignedTo[$i]) : null,
                'due_date'    => !empty($stepDueDates[$i]) ? Sanitize::string($stepDueDates[$i]) : null,
                'order_num'   => !empty($stepOrders[$i]) ? Sanitize::int($stepOrders[$i]) : $i + 1,
            ]);
        }

        Process::recalculateProgress($id);

        AuditLog::log('update', 'process', $id, $oldData, [
            'title'      => $title,
            'step_count' => count(array_filter($stepTitles, fn($s) => trim($s) !== '')),
        ]);

        Session::flash('success', 'Processo atualizado com sucesso.');
        header('Location: index.php?m=chat&page=processes&action=show&id=' . $id);
        exit;
    }

    /* ------------------------------------------------------------------
     *  updateStep  — Update a single step's status (AJAX)
     *  POST ?page=processes&action=updateStep  (JSON response)
     * ----------------------------------------------------------------*/
    public function updateStep(): void
    {
        Auth::requireLogin();
        Csrf::check();

        header('Content-Type: application/json; charset=utf-8');

        $stepId = Sanitize::int($_POST['step_id'] ?? 0);
        $status = Sanitize::string($_POST['status'] ?? '');

        if ($stepId <= 0 || !in_array($status, ['pending', 'in_progress', 'completed', 'skipped'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Dados inválidos.']);
            return;
        }

        // Fetch the step to get its process_id
        $stmt = $this->db->prepare('SELECT process_id FROM chat_process_steps WHERE id = ? LIMIT 1');
        $stmt->execute([$stepId]);
        $step = $stmt->fetch();

        if (!$step) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Etapa não encontrada.']);
            return;
        }

        $processId = (int) $step['process_id'];

        Process::updateStepStatus($stepId, $status, Session::userId());
        Process::recalculateProgress($processId);

        // Return the updated progress
        $process = Process::find($processId);

        echo json_encode([
            'success'  => true,
            'progress' => (int) ($process['progress'] ?? 0),
            'status'   => $status,
        ]);
    }

    /* ------------------------------------------------------------------
     *  pause  — Pause an active process
     *  POST ?page=processes&action=pause
     * ----------------------------------------------------------------*/
    public function pause(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $process = Process::find($id);

        if (!$process) {
            Session::flash('error', 'Processo não encontrado.');
            header('Location: index.php?m=chat&page=processes');
            exit;
        }

        Process::update($id, ['status' => 'paused']);

        AuditLog::log('pause', 'process', $id, ['status' => $process['status']], ['status' => 'paused']);

        Session::flash('success', 'Processo pausado.');
        header('Location: index.php?m=chat&page=processes&action=show&id=' . $id);
        exit;
    }

    /* ------------------------------------------------------------------
     *  resume  — Resume a paused process
     *  POST ?page=processes&action=resume
     * ----------------------------------------------------------------*/
    public function resume(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $process = Process::find($id);

        if (!$process) {
            Session::flash('error', 'Processo não encontrado.');
            header('Location: index.php?m=chat&page=processes');
            exit;
        }

        Process::update($id, ['status' => 'active']);

        AuditLog::log('resume', 'process', $id, ['status' => $process['status']], ['status' => 'active']);

        Session::flash('success', 'Processo retomado.');
        header('Location: index.php?m=chat&page=processes&action=show&id=' . $id);
        exit;
    }

    /* ------------------------------------------------------------------
     *  complete  — Mark a process as completed
     *  POST ?page=processes&action=complete
     * ----------------------------------------------------------------*/
    public function complete(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $process = Process::find($id);

        if (!$process) {
            Session::flash('error', 'Processo não encontrado.');
            header('Location: index.php?m=chat&page=processes');
            exit;
        }

        Process::update($id, ['status' => 'completed', 'progress' => 100]);

        AuditLog::log('complete', 'process', $id, ['status' => $process['status']], ['status' => 'completed']);

        Session::flash('success', 'Processo concluído.');
        header('Location: index.php?m=chat&page=processes&action=show&id=' . $id);
        exit;
    }

    /* ------------------------------------------------------------------
     *  delete  — Cancel (soft-delete) a process
     *  POST ?page=processes&action=delete
     * ----------------------------------------------------------------*/
    public function delete(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $process = Process::find($id);

        if (!$process) {
            Session::flash('error', 'Processo não encontrado.');
            header('Location: index.php?m=chat&page=processes');
            exit;
        }

        Process::update($id, ['status' => 'cancelled']);

        AuditLog::log('delete', 'process', $id, ['status' => $process['status']], ['status' => 'cancelled']);

        Session::flash('success', 'Processo cancelado.');
        header('Location: index.php?m=chat&page=processes');
        exit;
    }
}
