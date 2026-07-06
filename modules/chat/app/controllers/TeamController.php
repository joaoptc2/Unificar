<?php
/**
 * TeamController — Team management (create, edit, members).
 *
 * Every public method maps to ?page=teams&action=X.
 */
class TeamController
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /* ------------------------------------------------------------------
     *  index  — List teams
     *  GET ?page=teams
     *  Admins/managers see all active teams; regular members see their own.
     * ----------------------------------------------------------------*/
    public function index(): void
    {
        Auth::requireLogin();
        core_require('teams.view');

        $userId = Session::userId();

        // Quem gerencia equipes (teams.edit) vê todas; demais, as suas.
        if (core_can('teams.edit')) {
            $teams = Team::allActive();
        } else {
            $teams = Team::userTeams($userId);
        }

        View::render('teams/index', [
            'pageTitle' => 'Equipes',
            'teams'     => $teams,
        ]);
    }

    /* ------------------------------------------------------------------
     *  show  — Single team detail with members
     *  GET ?page=teams&action=show&id=N
     * ----------------------------------------------------------------*/
    public function show(): void
    {
        Auth::requireLogin();
        core_require('teams.view');

        $teamId = isset($_GET['id']) ? Sanitize::int($_GET['id']) : 0;
        $team   = Team::withMembers($teamId);

        if (!$team) {
            Session::flash('error', 'Equipe não encontrada.');
            header('Location: index.php?m=chat&page=teams');
            exit;
        }

        View::render('teams/show', [
            'pageTitle' => Sanitize::e($team['name']),
            'team'      => $team,
            'csrfToken' => Csrf::token(),
        ]);
    }

    /* ------------------------------------------------------------------
     *  create  — Show team creation form
     *  GET ?page=teams&action=create
     * ----------------------------------------------------------------*/
    public function create(): void
    {
        Auth::requireLogin();
        core_require('teams.create');

        $users = User::active();

        View::render('teams/form', [
            'pageTitle' => 'Nova Equipe',
            'team'      => null,
            'users'     => $users,
        ]);
    }

    /* ------------------------------------------------------------------
     *  store  — Persist a new team
     *  POST ?page=teams&action=store
     * ----------------------------------------------------------------*/
    public function store(): void
    {
        Auth::requireLogin();
        core_require('teams.create');
        Csrf::check();

        $userId      = Session::userId();
        $name        = Sanitize::post('name');
        $description = Sanitize::post('description');
        $color       = Sanitize::post('color');

        if ($name === '') {
            Session::flash('error', 'O nome da equipe é obrigatório.');
            header('Location: index.php?m=chat&page=teams&action=create');
            exit;
        }

        $slug = Sanitize::slug($name);

        // Ensure slug uniqueness
        if (Team::findBySlug($slug)) {
            $slug .= '-' . time();
        }

        $teamId = Team::insert([
            'name'        => $name,
            'slug'        => $slug,
            'description' => $description,
            'color'       => $color !== '' ? $color : '#6c757d',
            'created_by'  => $userId,
            'is_active'   => 1,
        ]);

        // Creator is the team leader
        Team::addMember($teamId, $userId, 'leader');

        // Add selected members
        $memberIds = array_map('intval', $_POST['members'] ?? []);
        foreach ($memberIds as $memberId) {
            if ($memberId > 0 && $memberId !== $userId) {
                Team::addMember($teamId, $memberId);
            }
        }

        // Create a private channel for this team
        $channelSlug = 'team-' . $slug;
        $channelId = Channel::insert([
            'name'        => $name,
            'slug'        => $channelSlug,
            'description' => 'Canal da equipe ' . $name,
            'type'        => 'private',
            'created_by'  => $userId,
        ]);

        // Add team leader to channel
        Channel::addMember($channelId, $userId, 'owner');

        // Add all team members to channel
        foreach ($memberIds as $memberId) {
            if ($memberId > 0 && $memberId !== $userId) {
                Channel::addMember($channelId, $memberId);
            }
        }

        // Post welcome system message
        Message::insert([
            'channel_id' => $channelId,
            'user_id'    => $userId,
            'content'    => 'Canal da equipe "' . $name . '" criado.',
            'type'       => 'system',
        ]);

        AuditLog::log('create', 'team', $teamId, null, [
            'name' => $name,
            'slug' => $slug,
            'channel_id' => $channelId,
        ]);

        Session::flash('success', 'Equipe criada com sucesso.');
        header('Location: index.php?m=chat&page=teams&action=show&id=' . $teamId);
        exit;
    }

    /* ------------------------------------------------------------------
     *  edit  — Show team edit form
     *  GET ?page=teams&action=edit&id=N
     * ----------------------------------------------------------------*/
    public function edit(): void
    {
        Auth::requireLogin();

        $teamId = isset($_GET['id']) ? Sanitize::int($_GET['id']) : 0;
        $team   = Team::withMembers($teamId);

        if (!$team) {
            Session::flash('error', 'Equipe não encontrada.');
            header('Location: index.php?m=chat&page=teams');
            exit;
        }

        if (!$this->canManageTeam($team, 'teams.edit')) {
            Session::flash('error', 'Você não tem permissão para editar esta equipe.');
            header('Location: index.php?m=chat&page=teams&action=show&id=' . $teamId);
            exit;
        }

        $users = User::active();

        View::render('teams/form', [
            'pageTitle' => 'Editar Equipe',
            'team'      => $team,
            'users'     => $users,
        ]);
    }

    /* ------------------------------------------------------------------
     *  update  — Persist team changes
     *  POST ?page=teams&action=update
     * ----------------------------------------------------------------*/
    public function update(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $teamId = Sanitize::int($_POST['id'] ?? 0);
        $team   = Team::find($teamId);

        if (!$team) {
            Session::flash('error', 'Equipe não encontrada.');
            header('Location: index.php?m=chat&page=teams');
            exit;
        }

        if (!$this->canManageTeam($team, 'teams.edit')) {
            Session::flash('error', 'Você não tem permissão para editar esta equipe.');
            header('Location: index.php?m=chat&page=teams&action=show&id=' . $teamId);
            exit;
        }

        $name        = Sanitize::post('name');
        $description = Sanitize::post('description');
        $color       = Sanitize::post('color');

        $oldData = [
            'name'        => $team['name'],
            'description' => $team['description'] ?? '',
            'color'       => $team['color'] ?? '',
        ];

        Team::update($teamId, [
            'name'        => $name !== '' ? $name : $team['name'],
            'description' => $description,
            'color'       => $color !== '' ? $color : ($team['color'] ?? '#6c757d'),
        ]);

        AuditLog::log('update', 'team', $teamId, $oldData, [
            'name'        => $name !== '' ? $name : $team['name'],
            'description' => $description,
            'color'       => $color !== '' ? $color : ($team['color'] ?? '#6c757d'),
        ]);

        Session::flash('success', 'Equipe atualizada com sucesso.');
        header('Location: index.php?m=chat&page=teams&action=show&id=' . $teamId);
        exit;
    }

    /* ------------------------------------------------------------------
     *  addMember  — Add a user to the team (AJAX)
     *  POST ?page=teams&action=addMember
     * ----------------------------------------------------------------*/
    public function addMember(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $teamId = Sanitize::int($_POST['team_id'] ?? 0);
        $userId = Sanitize::int($_POST['user_id'] ?? 0);
        $role   = in_array($_POST['role'] ?? '', ['member', 'leader'], true)
                ? $_POST['role']
                : 'member';

        $team = Team::find($teamId);
        if (!$team || $userId <= 0) {
            $this->jsonResponse(false, 'Dados inválidos.', 400);
            return;
        }

        // Gerir membros = teams.edit (criador/líder continuam podendo).
        if (!$this->canManageTeam($team, 'teams.edit')) {
            $this->jsonResponse(false, 'Sem permissão para gerenciar membros desta equipe.', 403);
            return;
        }

        if (Team::isMember($teamId, $userId)) {
            $this->jsonResponse(false, 'Usuário já é membro desta equipe.', 409);
            return;
        }

        Team::addMember($teamId, $userId, $role);

        $addedUser = User::find($userId);

        $this->jsonResponse(true, 'Membro adicionado com sucesso.', 200, [
            'user' => $addedUser ? [
                'id'     => $addedUser['id'],
                'name'   => $addedUser['name'],
                'avatar' => $addedUser['avatar'],
            ] : null,
        ]);
    }

    /* ------------------------------------------------------------------
     *  removeMember  — Remove a user from the team (AJAX)
     *  POST ?page=teams&action=removeMember
     * ----------------------------------------------------------------*/
    public function removeMember(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $teamId = Sanitize::int($_POST['team_id'] ?? 0);
        $userId = Sanitize::int($_POST['user_id'] ?? 0);

        $team = Team::find($teamId);
        if (!$team || $userId <= 0) {
            $this->jsonResponse(false, 'Dados inválidos.', 400);
            return;
        }

        // Gerir membros = teams.edit (criador/líder continuam podendo).
        if (!$this->canManageTeam($team, 'teams.edit')) {
            $this->jsonResponse(false, 'Sem permissão para gerenciar membros desta equipe.', 403);
            return;
        }

        if (!Team::isMember($teamId, $userId)) {
            $this->jsonResponse(false, 'Usuário não é membro desta equipe.', 404);
            return;
        }

        Team::removeMember($teamId, $userId);

        $this->jsonResponse(true, 'Membro removido com sucesso.');
    }

    /* ------------------------------------------------------------------
     *  delete  — Soft-deactivate a team
     *  POST ?page=teams&action=delete
     * ----------------------------------------------------------------*/
    public function delete(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $teamId = Sanitize::int($_POST['id'] ?? 0);
        $team   = Team::find($teamId);

        if (!$team) {
            Session::flash('error', 'Equipe não encontrada.');
            header('Location: index.php?m=chat&page=teams');
            exit;
        }

        if (!$this->canManageTeam($team, 'teams.delete')) {
            Session::flash('error', 'Você não tem permissão para excluir esta equipe.');
            header('Location: index.php?m=chat&page=teams');
            exit;
        }

        Team::update($teamId, ['is_active' => 0]);

        AuditLog::log('delete', 'team', $teamId, ['is_active' => 1], ['is_active' => 0]);

        Session::flash('success', 'Equipe desativada com sucesso.');
        header('Location: index.php?m=chat&page=teams');
        exit;
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    /**
     * Check whether the current user may manage (edit/delete) a team.
     * Permitidos: quem tem a micropermissão ($permKey — teams.edit ou
     * teams.delete), o criador da equipe e os líderes da equipe.
     */
    private function canManageTeam(array $team, string $permKey = 'teams.edit'): bool
    {
        if (core_can($permKey)) {
            return true;
        }

        $userId = Session::userId();

        if ((int) ($team['created_by'] ?? 0) === $userId) {
            return true;
        }

        // Check team-level role (leader)
        $stmt = $this->db->prepare(
            'SELECT role FROM chat_team_members WHERE team_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([(int) $team['id'], $userId]);
        $row = $stmt->fetch();

        return $row && $row['role'] === 'leader';
    }

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
