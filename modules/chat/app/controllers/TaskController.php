<?php
/**
 * TaskController — Task / kanban management.
 *
 * Every public method maps to ?page=tasks&action=X.
 */
class TaskController
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /* ------------------------------------------------------------------
     *  index  — Kanban board (all tasks grouped by status)
     *  GET ?page=tasks
     * ----------------------------------------------------------------*/
    public function index(): void
    {
        Auth::requireLogin();
        core_require('tasks.view');

        // Visão administrativa (todas as tarefas) — legado: isAdmin.
        $userId = core_can('admin.view') ? null : Session::userId();
        $tasksByStatus = Task::byStatus($userId);
        $stats         = Task::stats();

        View::render('tasks/index', [
            'pageTitle'    => 'Tarefas',
            'tasksByStatus' => $tasksByStatus,
            'stats'        => $stats,
            'csrfToken'    => Csrf::token(),
        ]);
    }

    /* ------------------------------------------------------------------
     *  show  — Single task detail view
     *  GET ?page=tasks&action=show&id=N
     * ----------------------------------------------------------------*/
    public function show(): void
    {
        Auth::requireLogin();
        core_require('tasks.view');

        $taskId = isset($_GET['id']) ? Sanitize::int($_GET['id']) : 0;
        $task   = Task::withDetails($taskId);

        if (!$task) {
            Session::flash('error', 'Tarefa não encontrada.');
            header('Location: index.php?m=chat&page=tasks');
            exit;
        }

        View::render('tasks/show', [
            'pageTitle' => Sanitize::e($task['title']),
            'task'      => $task,
            'csrfToken' => Csrf::token(),
        ]);
    }

    /* ------------------------------------------------------------------
     *  create  — Show task creation form
     *  GET ?page=tasks&action=create
     * ----------------------------------------------------------------*/
    public function create(): void
    {
        Auth::requireLogin();
        core_require('tasks.create');

        $users    = User::active();
        $channels = Channel::userChannels(Session::userId());

        View::render('tasks/form', [
            'pageTitle' => 'Nova Tarefa',
            'task'      => null,
            'users'     => $users,
            'channels'  => $channels,
        ]);
    }

    /* ------------------------------------------------------------------
     *  store  — Persist a new task
     *  POST ?page=tasks&action=store
     * ----------------------------------------------------------------*/
    public function store(): void
    {
        Auth::requireLogin();
        core_require('tasks.create');
        Csrf::check();

        $userId = Session::userId();

        $title       = Sanitize::post('title');
        $description = Sanitize::post('description');
        $status      = in_array($_POST['status'] ?? '', ['todo', 'in_progress', 'review', 'done'], true)
                     ? $_POST['status']
                     : 'todo';
        $priority    = in_array($_POST['priority'] ?? '', ['low', 'medium', 'high', 'urgent'], true)
                     ? $_POST['priority']
                     : 'medium';
        $channelId   = Sanitize::int($_POST['channel_id'] ?? 0);
        $dueDate     = Sanitize::post('due_date');

        if ($title === '') {
            Session::flash('error', 'O título da tarefa é obrigatório.');
            header('Location: index.php?m=chat&page=tasks&action=create');
            exit;
        }

        $taskId = Task::insert([
            'title'       => $title,
            'description' => $description,
            'status'      => $status,
            'priority'    => $priority,
            'channel_id'  => $channelId > 0 ? $channelId : null,
            'due_date'    => $dueDate !== '' ? $dueDate : null,
            'created_by'  => $userId,
        ]);

        // Assign users
        $assigneeIds = array_map('intval', $_POST['assignees'] ?? []);
        if (!empty($assigneeIds)) {
            Task::setAssignees($taskId, $assigneeIds);

            $taskLink = 'index.php?m=chat&page=tasks&action=show&id=' . $taskId;
            $notifyIds = array_diff($assigneeIds, [$userId]);
            Notification::createForMany(
                $notifyIds,
                'task',
                'Nova tarefa atribuída: ' . $title,
                Session::userName() . ' atribuiu uma tarefa a você.',
                $taskLink
            );

            // Send DM notification to each assignee
            $senderName = Session::userName();
            foreach ($notifyIds as $assigneeId) {
                $dm = Channel::directChannel($userId, $assigneeId);
                if (!$dm) {
                    $dmId = Channel::insert([
                        'name' => 'dm-' . min($userId, $assigneeId) . '-' . max($userId, $assigneeId),
                        'slug' => 'dm-' . min($userId, $assigneeId) . '-' . max($userId, $assigneeId) . '-' . time(),
                        'type' => 'direct', 'created_by' => $userId,
                    ]);
                    Channel::addMember($dmId, $userId);
                    Channel::addMember($dmId, $assigneeId);
                } else {
                    $dmId = (int) $dm['id'];
                }
                Message::insert([
                    'channel_id' => $dmId, 'user_id' => $userId,
                    'content' => "📋 {$senderName} atribuiu a tarefa \"{$title}\" a você. Veja: {$taskLink}",
                    'type' => 'system',
                ]);
            }
        }

        AuditLog::log('create', 'task', $taskId, null, [
            'title'    => $title,
            'status'   => $status,
            'priority' => $priority,
        ]);

        Session::flash('success', 'Tarefa criada com sucesso.');
        header('Location: index.php?m=chat&page=tasks&action=show&id=' . $taskId);
        exit;
    }

    /* ------------------------------------------------------------------
     *  edit  — Show task edit form
     *  GET ?page=tasks&action=edit&id=N
     * ----------------------------------------------------------------*/
    public function edit(): void
    {
        Auth::requireLogin();
        core_require('tasks.edit');

        $taskId = isset($_GET['id']) ? Sanitize::int($_GET['id']) : 0;
        $task   = Task::withDetails($taskId);

        if (!$task) {
            Session::flash('error', 'Tarefa não encontrada.');
            header('Location: index.php?m=chat&page=tasks');
            exit;
        }

        $users    = User::active();
        $channels = Channel::userChannels(Session::userId());

        View::render('tasks/form', [
            'pageTitle' => 'Editar Tarefa',
            'task'      => $task,
            'users'     => $users,
            'channels'  => $channels,
        ]);
    }

    /* ------------------------------------------------------------------
     *  update  — Persist task changes
     *  POST ?page=tasks&action=update
     * ----------------------------------------------------------------*/
    public function update(): void
    {
        Auth::requireLogin();
        core_require('tasks.edit');
        Csrf::check();

        $taskId = Sanitize::int($_POST['id'] ?? 0);
        $task   = Task::find($taskId);

        if (!$task) {
            Session::flash('error', 'Tarefa não encontrada.');
            header('Location: index.php?m=chat&page=tasks');
            exit;
        }

        $title       = Sanitize::post('title');
        $description = Sanitize::post('description');
        $status      = in_array($_POST['status'] ?? '', ['todo', 'in_progress', 'review', 'done'], true)
                     ? $_POST['status']
                     : $task['status'];
        $priority    = in_array($_POST['priority'] ?? '', ['low', 'medium', 'high', 'urgent'], true)
                     ? $_POST['priority']
                     : $task['priority'];
        $channelId   = Sanitize::int($_POST['channel_id'] ?? 0);
        $dueDate     = Sanitize::post('due_date');

        $oldData = [
            'title'    => $task['title'],
            'status'   => $task['status'],
            'priority' => $task['priority'],
        ];

        $updateData = [
            'title'       => $title !== '' ? $title : $task['title'],
            'description' => $description,
            'status'      => $status,
            'priority'    => $priority,
            'channel_id'  => $channelId > 0 ? $channelId : null,
            'due_date'    => $dueDate !== '' ? $dueDate : null,
        ];

        // If transitioning to done, record completion time
        if ($status === 'done' && $task['status'] !== 'done') {
            $updateData['completed_at'] = date('Y-m-d H:i:s');
        }

        Task::update($taskId, $updateData);

        // Update assignees
        $assigneeIds = array_map('intval', $_POST['assignees'] ?? []);
        Task::setAssignees($taskId, $assigneeIds);

        AuditLog::log('update', 'task', $taskId, $oldData, [
            'title'    => $updateData['title'],
            'status'   => $status,
            'priority' => $priority,
        ]);

        Session::flash('success', 'Tarefa atualizada com sucesso.');
        header('Location: index.php?m=chat&page=tasks&action=show&id=' . $taskId);
        exit;
    }

    /* ------------------------------------------------------------------
     *  updateStatus  — AJAX status change (kanban drag-and-drop)
     *  POST ?page=tasks&action=updateStatus
     * ----------------------------------------------------------------*/
    public function updateStatus(): void
    {
        Auth::requireLogin();
        if (!core_can('tasks.edit')) {
            $this->jsonResponse(false, 'Sem permissão para alterar tarefas.', 403);
            return;
        }
        Csrf::check();

        $taskId = Sanitize::int($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';

        if (!in_array($status, ['todo', 'in_progress', 'review', 'done'], true)) {
            $this->jsonResponse(false, 'Status inválido.', 400);
            return;
        }

        $task = Task::find($taskId);
        if (!$task) {
            $this->jsonResponse(false, 'Tarefa não encontrada.', 404);
            return;
        }

        $updateData = ['status' => $status];

        if ($status === 'done' && $task['status'] !== 'done') {
            $updateData['completed_at'] = date('Y-m-d H:i:s');
        }

        Task::update($taskId, $updateData);

        AuditLog::log('status_change', 'task', $taskId,
            ['status' => $task['status']],
            ['status' => $status]
        );

        $this->jsonResponse(true, 'Status atualizado.', 200, [
            'task_id' => $taskId,
            'status'  => $status,
        ]);
    }

    /* ------------------------------------------------------------------
     *  comment  — Add a comment to a task
     *  POST ?page=tasks&action=comment
     * ----------------------------------------------------------------*/
    public function comment(): void
    {
        Auth::requireLogin();
        // Comentar exige apenas enxergar tarefas (legado: qualquer logado).
        core_require('tasks.view');
        Csrf::check();

        $taskId  = Sanitize::int($_POST['task_id'] ?? 0);
        $content = Sanitize::post('content');

        $task = Task::find($taskId);
        if (!$task) {
            Session::flash('error', 'Tarefa não encontrada.');
            header('Location: index.php?m=chat&page=tasks');
            exit;
        }

        if ($content === '') {
            Session::flash('error', 'O comentário não pode estar vazio.');
            header('Location: index.php?m=chat&page=tasks&action=show&id=' . $taskId);
            exit;
        }

        Task::addComment($taskId, Session::userId(), $content);

        Session::flash('success', 'Comentário adicionado.');
        header('Location: index.php?m=chat&page=tasks&action=show&id=' . $taskId);
        exit;
    }

    /* ------------------------------------------------------------------
     *  delete  — Soft-cancel a task
     *  POST ?page=tasks&action=delete
     * ----------------------------------------------------------------*/
    public function delete(): void
    {
        Auth::requireLogin();
        core_require('tasks.delete');
        Csrf::check();

        $taskId = Sanitize::int($_POST['id'] ?? 0);
        $task   = Task::find($taskId);

        if (!$task) {
            Session::flash('error', 'Tarefa não encontrada.');
            header('Location: index.php?m=chat&page=tasks');
            exit;
        }

        Task::update($taskId, ['status' => 'cancelled']);

        AuditLog::log('delete', 'task', $taskId, ['status' => $task['status']], ['status' => 'cancelled']);

        Session::flash('success', 'Tarefa cancelada com sucesso.');
        header('Location: index.php?m=chat&page=tasks');
        exit;
    }

    /* ------------------------------------------------------------------
     *  my  — Tasks assigned to the current user
     *  GET ?page=tasks&action=my
     * ----------------------------------------------------------------*/
    public function my(): void
    {
        Auth::requireLogin();
        core_require('tasks.view');

        $userId = Session::userId();
        $tasks  = Task::userTasks($userId);

        View::render('tasks/my', [
            'pageTitle' => 'Minhas Tarefas',
            'tasks'     => $tasks,
        ]);
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    /**
     * Send a JSON response.
     */
    private function jsonResponse(bool $success, string $message, int $code = 200, array $extra = []): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    }
}
