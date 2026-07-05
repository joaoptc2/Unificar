<?php
/**
 * ChannelController — Channel management (create, edit, archive, members).
 *
 * Every public method maps to ?page=channels&action=X.
 */
class ChannelController
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /* ------------------------------------------------------------------
     *  create  — Show channel creation form
     *  GET ?page=channels&action=create
     * ----------------------------------------------------------------*/
    public function create(): void
    {
        Auth::requireLogin();

        $users = User::active();

        View::render('channels/form', [
            'pageTitle' => 'Novo Canal',
            'channel'   => null,
            'users'     => $users,
        ]);
    }

    /* ------------------------------------------------------------------
     *  store  — Persist a new channel
     *  POST ?page=channels&action=store
     * ----------------------------------------------------------------*/
    public function store(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $userId      = Session::userId();
        $name        = Sanitize::post('name');
        $description = Sanitize::post('description');
        $type        = in_array($_POST['type'] ?? '', ['public', 'private'], true)
                     ? $_POST['type']
                     : 'public';

        if ($name === '') {
            Session::flash('error', 'O nome do canal é obrigatório.');
            header('Location: index.php?page=channels&action=create');
            exit;
        }

        $slug = Sanitize::slug($name);

        // Ensure slug uniqueness
        if (Channel::findBySlug($slug)) {
            $slug .= '-' . time();
        }

        $channelId = Channel::insert([
            'name'        => $name,
            'slug'        => $slug,
            'description' => $description,
            'type'        => $type,
            'created_by'  => $userId,
            'is_archived' => 0,
        ]);

        // Creator becomes owner
        Channel::addMember($channelId, $userId, 'owner');

        // Add selected members
        $memberIds = array_map('intval', $_POST['members'] ?? []);
        foreach ($memberIds as $memberId) {
            if ($memberId > 0 && $memberId !== $userId) {
                Channel::addMember($channelId, $memberId);
            }
        }

        AuditLog::log('create', 'channel', $channelId, null, [
            'name' => $name,
            'type' => $type,
        ]);

        Session::flash('success', 'Canal criado com sucesso.');
        header('Location: index.php?page=chat&channel_id=' . $channelId);
        exit;
    }

    /* ------------------------------------------------------------------
     *  edit  — Show channel edit form
     *  GET ?page=channels&action=edit&id=N
     * ----------------------------------------------------------------*/
    public function edit(): void
    {
        Auth::requireLogin();

        $channelId = isset($_GET['id']) ? Sanitize::int($_GET['id']) : 0;
        $channel   = Channel::find($channelId);

        if (!$channel) {
            Session::flash('error', 'Canal não encontrado.');
            header('Location: index.php?page=chat');
            exit;
        }

        // Only channel owner or system admin may edit
        $userId = Session::userId();
        if (!$this->canManageChannel($channel, $userId)) {
            Session::flash('error', 'Você não tem permissão para editar este canal.');
            header('Location: index.php?page=chat&channel_id=' . $channelId);
            exit;
        }

        $users = User::active();

        View::render('channels/form', [
            'pageTitle' => 'Editar Canal',
            'channel'   => $channel,
            'users'     => $users,
            'members'   => Channel::members($channelId),
        ]);
    }

    /* ------------------------------------------------------------------
     *  update  — Persist channel changes
     *  POST ?page=channels&action=update
     * ----------------------------------------------------------------*/
    public function update(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $channelId = Sanitize::int($_POST['id'] ?? 0);
        $channel   = Channel::find($channelId);

        if (!$channel) {
            Session::flash('error', 'Canal não encontrado.');
            header('Location: index.php?page=chat');
            exit;
        }

        $userId = Session::userId();
        if (!$this->canManageChannel($channel, $userId)) {
            Session::flash('error', 'Você não tem permissão para editar este canal.');
            header('Location: index.php?page=chat&channel_id=' . $channelId);
            exit;
        }

        $name        = Sanitize::post('name');
        $description = Sanitize::post('description');
        $topic       = Sanitize::post('topic');

        $oldData = ['name' => $channel['name'], 'description' => $channel['description'], 'topic' => $channel['topic'] ?? ''];

        Channel::update($channelId, [
            'name'        => $name !== '' ? $name : $channel['name'],
            'description' => $description,
            'topic'       => $topic,
        ]);

        AuditLog::log('update', 'channel', $channelId, $oldData, [
            'name'        => $name !== '' ? $name : $channel['name'],
            'description' => $description,
            'topic'       => $topic,
        ]);

        Session::flash('success', 'Canal atualizado com sucesso.');
        header('Location: index.php?page=chat&channel_id=' . $channelId);
        exit;
    }

    /* ------------------------------------------------------------------
     *  archive  — Soft-archive a channel
     *  POST ?page=channels&action=archive
     * ----------------------------------------------------------------*/
    public function archive(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $channelId = Sanitize::int($_POST['id'] ?? 0);
        $channel   = Channel::find($channelId);

        if (!$channel) {
            Session::flash('error', 'Canal não encontrado.');
            header('Location: index.php?page=chat');
            exit;
        }

        $userId = Session::userId();
        if (!$this->canManageChannel($channel, $userId)) {
            Session::flash('error', 'Você não tem permissão para arquivar este canal.');
            header('Location: index.php?page=chat&channel_id=' . $channelId);
            exit;
        }

        Channel::update($channelId, ['is_archived' => 1]);

        // Send system message
        Message::insert([
            'channel_id' => $channelId,
            'user_id'    => null,
            'content'    => 'Este canal foi arquivado por ' . Session::userName() . '.',
            'type'       => 'system',
        ]);

        AuditLog::log('archive', 'channel', $channelId);

        Session::flash('success', 'Canal arquivado com sucesso.');
        header('Location: index.php?page=chat');
        exit;
    }

    /* ------------------------------------------------------------------
     *  members  — Return JSON list of channel members
     *  GET ?page=channels&action=members&id=N
     * ----------------------------------------------------------------*/
    public function members(): void
    {
        Auth::requireLogin();

        $channelId = isset($_GET['id']) ? Sanitize::int($_GET['id']) : 0;

        if ($channelId <= 0) {
            $this->jsonResponse(false, 'ID de canal inválido.', 400);
            return;
        }

        $members = Channel::members($channelId);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'members' => $members,
            'count'   => count($members),
        ]);
    }

    /* ------------------------------------------------------------------
     *  addMember  — Add a user to a channel
     *  POST ?page=channels&action=addMember
     * ----------------------------------------------------------------*/
    public function addMember(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);
        $memberId  = Sanitize::int($_POST['user_id'] ?? 0);

        $channel = Channel::find($channelId);
        if (!$channel || $memberId <= 0) {
            $this->jsonResponse(false, 'Dados inválidos.', 400);
            return;
        }

        if (Channel::isMember($channelId, $memberId)) {
            $this->jsonResponse(false, 'Usuário já é membro deste canal.', 409);
            return;
        }

        Channel::addMember($channelId, $memberId);

        // System message
        $addedUser = User::find($memberId);
        $userName  = $addedUser ? $addedUser['name'] : 'Usuário';
        Message::insert([
            'channel_id' => $channelId,
            'user_id'    => null,
            'content'    => $userName . ' entrou no canal.',
            'type'       => 'system',
        ]);

        $this->jsonResponse(true, 'Membro adicionado com sucesso.');
    }

    /* ------------------------------------------------------------------
     *  removeMember  — Remove a user from a channel
     *  POST ?page=channels&action=removeMember
     * ----------------------------------------------------------------*/
    public function removeMember(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);
        $memberId  = Sanitize::int($_POST['user_id'] ?? 0);

        $channel = Channel::find($channelId);
        if (!$channel || $memberId <= 0) {
            $this->jsonResponse(false, 'Dados inválidos.', 400);
            return;
        }

        Channel::removeMember($channelId, $memberId);

        // System message
        $removedUser = User::find($memberId);
        $userName    = $removedUser ? $removedUser['name'] : 'Usuário';
        Message::insert([
            'channel_id' => $channelId,
            'user_id'    => null,
            'content'    => $userName . ' saiu do canal.',
            'type'       => 'system',
        ]);

        $this->jsonResponse(true, 'Membro removido com sucesso.');
    }

    /* ------------------------------------------------------------------
     *  join  — Current user joins a public channel
     *  POST ?page=channels&action=join
     * ----------------------------------------------------------------*/
    public function join(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);
        $channel   = Channel::find($channelId);

        if (!$channel || $channel['type'] !== 'public') {
            Session::flash('error', 'Canal não encontrado ou não é público.');
            header('Location: index.php?page=channels&action=browse');
            exit;
        }

        $userId = Session::userId();

        if (Channel::isMember($channelId, $userId)) {
            header('Location: index.php?page=chat&channel_id=' . $channelId);
            exit;
        }

        Channel::addMember($channelId, $userId);

        // System message
        Message::insert([
            'channel_id' => $channelId,
            'user_id'    => null,
            'content'    => Session::userName() . ' entrou no canal.',
            'type'       => 'system',
        ]);

        Session::flash('success', 'Você entrou no canal #' . Sanitize::e($channel['name']) . '.');
        header('Location: index.php?page=chat&channel_id=' . $channelId);
        exit;
    }

    /* ------------------------------------------------------------------
     *  leave  — Current user leaves a channel
     *  POST ?page=channels&action=leave
     * ----------------------------------------------------------------*/
    public function leave(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);
        $channel   = Channel::find($channelId);

        if (!$channel) {
            Session::flash('error', 'Canal não encontrado.');
            header('Location: index.php?page=chat');
            exit;
        }

        $userId = Session::userId();
        Channel::removeMember($channelId, $userId);

        // System message
        Message::insert([
            'channel_id' => $channelId,
            'user_id'    => null,
            'content'    => Session::userName() . ' saiu do canal.',
            'type'       => 'system',
        ]);

        Session::flash('success', 'Você saiu do canal #' . Sanitize::e($channel['name']) . '.');
        header('Location: index.php?page=chat');
        exit;
    }

    /* ------------------------------------------------------------------
     *  browse  — List all public channels the user can join
     *  GET ?page=channels&action=browse
     * ----------------------------------------------------------------*/
    public function browse(): void
    {
        Auth::requireLogin();

        $userId         = Session::userId();
        $publicChannels = Channel::publicChannels();

        // Annotate each channel with membership status and member count
        foreach ($publicChannels as &$ch) {
            $ch['is_member']    = Channel::isMember((int) $ch['id'], $userId);
            $ch['member_count'] = Channel::memberCount((int) $ch['id']);
        }
        unset($ch);

        View::render('channels/browse', [
            'pageTitle' => 'Explorar Canais',
            'channels'  => $publicChannels,
        ]);
    }

    /* ------------------------------------------------------------------
     *  direct  — Find or create a direct-message channel
     *  POST ?page=channels&action=direct
     * ----------------------------------------------------------------*/
    public function direct(): void
    {
        Auth::requireLogin();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::check();
        }

        $userId   = Session::userId();
        $targetId = Sanitize::int($_POST['user_id'] ?? $_GET['user_id'] ?? 0);

        if ($targetId <= 0 || $targetId === $userId) {
            Session::flash('error', 'Usuário inválido.');
            header('Location: index.php?page=chat');
            exit;
        }

        $target = User::find($targetId);
        if (!$target) {
            Session::flash('error', 'Usuário não encontrado.');
            header('Location: index.php?page=chat');
            exit;
        }

        // Check for existing DM channel
        $channel = Channel::directChannel($userId, $targetId);

        if (!$channel) {
            // Create a new direct-message channel
            $channelId = Channel::insert([
                'name'        => 'dm-' . min($userId, $targetId) . '-' . max($userId, $targetId),
                'slug'        => 'dm-' . min($userId, $targetId) . '-' . max($userId, $targetId),
                'description' => '',
                'type'        => 'direct',
                'created_by'  => $userId,
                'is_archived' => 0,
            ]);

            Channel::addMember($channelId, $userId, 'member');
            Channel::addMember($channelId, $targetId, 'member');
        } else {
            $channelId = (int) $channel['id'];
        }

        header('Location: index.php?page=chat&channel_id=' . $channelId);
        exit;
    }

    public function settings(): void
    {
        Auth::requireLogin();
        $channelId = Sanitize::int($_GET['id'] ?? 0);
        $channel = Channel::find($channelId);
        if (!$channel || !$this->canManageChannel($channel, Session::userId())) {
            Session::flash('error', 'Sem permissão.');
            header('Location: index.php?page=chat');
            exit;
        }
        View::render('channels/settings', [
            'pageTitle' => 'Configurações do Canal',
            'page' => 'channels',
            'channel' => $channel,
        ]);
    }

    public function updateSettings(): void
    {
        Auth::requireLogin();
        Csrf::check();
        $channelId = Sanitize::int($_POST['id'] ?? 0);
        $channel = Channel::find($channelId);
        if (!$channel || !$this->canManageChannel($channel, Session::userId())) {
            Session::flash('error', 'Sem permissão.');
            header('Location: index.php?page=chat');
            exit;
        }
        Channel::update($channelId, [
            'is_readonly'       => Sanitize::int($_POST['is_readonly'] ?? 0),
            'retention_days'    => ($_POST['retention_days'] ?? '') !== '' ? Sanitize::int($_POST['retention_days']) : null,
            'slow_mode_seconds' => Sanitize::int($_POST['slow_mode_seconds'] ?? 0),
            'max_pinned'        => Sanitize::int($_POST['max_pinned'] ?? 50),
            'allow_threads'     => Sanitize::int($_POST['allow_threads'] ?? 1),
        ]);
        AuditLog::log('update_channel_settings', 'channel', $channelId);
        Session::flash('success', 'Configurações do canal atualizadas.');
        header('Location: index.php?page=channels&action=settings&id=' . $channelId);
        exit;
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    /**
     * Check whether the current user may manage (edit/archive) a channel.
     * Owners, channel admins, and system admins are allowed.
     */
    private function canManageChannel(array $channel, int $userId): bool
    {
        if (Auth::isAdmin()) {
            return true;
        }

        if ((int) ($channel['created_by'] ?? 0) === $userId) {
            return true;
        }

        // Check channel-level role
        $stmt = $this->db->prepare(
            'SELECT role FROM channel_members WHERE channel_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([(int) $channel['id'], $userId]);
        $row = $stmt->fetch();

        return $row && in_array($row['role'], ['owner', 'admin'], true);
    }

    /**
     * Send a JSON response and exit.
     */
    private function jsonResponse(bool $success, string $message, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $success, 'message' => $message]);
    }
}
