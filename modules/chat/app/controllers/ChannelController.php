<?php
/**
 * ChannelController — canais (criar, editar, arquivar, membros,
 * explorar, mensagens diretas, configurações por canal).
 *
 * Cada método público corresponde a ?page=channels&action=X.
 * Gerir um canal = ter channels.edit (ou channels.delete para arquivar),
 * ser o criador ou owner/admin do próprio canal.
 */
class ChannelController
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        $this->browse();
    }

    /* ------------------------------------------------------------------
     *  create / store
     * ----------------------------------------------------------------*/
    public function create(): void
    {
        Auth::requireLogin();
        core_require('channels.create');

        View::render('channels/form', [
            'pageTitle'  => 'Novo canal',
            'channel'    => null,
            'users'      => User::active(),
            'categories' => $this->categories(),
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();
        core_require('channels.create');
        Csrf::check();

        $userId      = Session::userId();
        $name        = mb_substr(Sanitize::post('name'), 0, 200);
        $description = Sanitize::post('description');
        $type        = in_array($_POST['type'] ?? '', ['public', 'private'], true) ? $_POST['type'] : 'public';
        $categoryId  = Sanitize::int($_POST['category_id'] ?? 0) ?: null;

        if ($name === '') {
            Session::flash('error', 'O nome do canal é obrigatório.');
            core_redirect('index.php?m=chat&page=channels&action=create');
        }

        $slug = Sanitize::slug($name) ?: 'canal';
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
            'category_id' => $categoryId,
        ]);

        Channel::addMember($channelId, $userId, 'owner');

        $memberIds = array_map('intval', (array) ($_POST['members'] ?? []));
        foreach (array_unique($memberIds) as $memberId) {
            if ($memberId > 0 && $memberId !== $userId) {
                Channel::addMember($channelId, $memberId);
            }
        }

        AuditLog::log('create', 'channel', $channelId, null, ['name' => $name, 'type' => $type]);

        Session::flash('success', 'Canal criado com sucesso.');
        core_redirect('index.php?m=chat&page=chat&channel_id=' . $channelId);
    }

    /* ------------------------------------------------------------------
     *  edit / update
     * ----------------------------------------------------------------*/
    public function edit(): void
    {
        Auth::requireLogin();
        core_require('channels.view');

        $channelId = isset($_GET['id']) ? Sanitize::int($_GET['id']) : 0;
        $channel   = Channel::find($channelId);

        if (!$channel || $channel['type'] === 'direct') {
            Session::flash('error', 'Canal não encontrado.');
            core_redirect('index.php?m=chat&page=chat');
        }

        if (!$this->canManageChannel($channel, Session::userId(), 'channels.edit')) {
            Session::flash('error', 'Você não tem permissão para editar este canal.');
            core_redirect('index.php?m=chat&page=chat&channel_id=' . $channelId);
        }

        View::render('channels/form', [
            'pageTitle'  => 'Editar canal',
            'channel'    => $channel,
            'users'      => User::active(),
            'members'    => Channel::members($channelId),
            'categories' => $this->categories(),
        ]);
    }

    public function update(): void
    {
        Auth::requireLogin();
        core_require('channels.view');
        Csrf::check();

        $channelId = Sanitize::int($_POST['id'] ?? 0);
        $channel   = Channel::find($channelId);

        if (!$channel || $channel['type'] === 'direct') {
            Session::flash('error', 'Canal não encontrado.');
            core_redirect('index.php?m=chat&page=chat');
        }

        if (!$this->canManageChannel($channel, Session::userId(), 'channels.edit')) {
            Session::flash('error', 'Você não tem permissão para editar este canal.');
            core_redirect('index.php?m=chat&page=chat&channel_id=' . $channelId);
        }

        $name        = mb_substr(Sanitize::post('name'), 0, 200);
        $description = Sanitize::post('description');
        $topic       = mb_substr(Sanitize::post('topic'), 0, 500);
        $categoryId  = Sanitize::int($_POST['category_id'] ?? 0) ?: null;

        $new = [
            'name'        => $name !== '' ? $name : $channel['name'],
            'description' => $description,
            'topic'       => $topic,
            'category_id' => $categoryId,
        ];
        Channel::update($channelId, $new);

        AuditLog::log('update', 'channel', $channelId, [
            'name' => $channel['name'], 'description' => $channel['description'],
            'topic' => $channel['topic'] ?? '', 'category_id' => $channel['category_id'],
        ], $new);

        Session::flash('success', 'Canal atualizado com sucesso.');
        core_redirect('index.php?m=chat&page=chat&channel_id=' . $channelId);
    }

    /* ------------------------------------------------------------------
     *  archive — POST
     * ----------------------------------------------------------------*/
    public function archive(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $channelId = Sanitize::int($_POST['id'] ?? 0);
        $channel   = Channel::find($channelId);

        if (!$channel) {
            Session::flash('error', 'Canal não encontrado.');
            core_redirect('index.php?m=chat&page=chat');
        }
        if ((int) $channel['is_general'] === 1) {
            Session::flash('error', 'O canal geral não pode ser arquivado.');
            core_redirect('index.php?m=chat&page=chat&channel_id=' . $channelId);
        }
        if (!$this->canManageChannel($channel, Session::userId(), 'channels.delete')) {
            Session::flash('error', 'Você não tem permissão para arquivar este canal.');
            core_redirect('index.php?m=chat&page=chat&channel_id=' . $channelId);
        }

        Channel::update($channelId, ['is_archived' => 1]);
        Channel::systemMessage($channelId, 'Este canal foi arquivado por ' . (Session::userName() ?? 'Usuário') . '.');
        AuditLog::log('archive', 'channel', $channelId);

        Session::flash('success', 'Canal arquivado com sucesso.');
        core_redirect('index.php?m=chat&page=chat');
    }

    /* ------------------------------------------------------------------
     *  members — GET JSON (somente membros ou canais públicos)
     * ----------------------------------------------------------------*/
    public function members(): void
    {
        Auth::requireLogin();
        if (!core_can('chat.view')) {
            $this->jsonResponse(false, 'Sem permissão.', 403);
            return;
        }

        $channelId = isset($_GET['id']) ? Sanitize::int($_GET['id']) : 0;
        $channel   = $channelId > 0 ? Channel::find($channelId) : null;
        if (!$channel) {
            $this->jsonResponse(false, 'Canal não encontrado.', 404);
            return;
        }
        if ($channel['type'] !== 'public' && !Channel::isMember($channelId, Session::userId())) {
            $this->jsonResponse(false, 'Você não é membro deste canal.', 403);
            return;
        }

        $members = Channel::members($channelId);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'members' => $members,
            'count'   => count($members),
            'can_manage' => $this->canManageChannel($channel, Session::userId(), 'channels.edit'),
        ], JSON_UNESCAPED_UNICODE);
    }

    /* ------------------------------------------------------------------
     *  addMember / removeMember — POST JSON
     * ----------------------------------------------------------------*/
    public function addMember(): void
    {
        Auth::requireLogin();
        if (!Csrf::checkAjax()) {
            $this->jsonResponse(false, 'Token CSRF inválido.', 403);
            return;
        }

        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);
        $memberId  = Sanitize::int($_POST['user_id'] ?? 0);

        $channel = Channel::find($channelId);
        if (!$channel || $memberId <= 0 || $channel['type'] === 'direct') {
            $this->jsonResponse(false, 'Dados inválidos.', 400);
            return;
        }

        if (!$this->canManageMembers($channel, Session::userId())) {
            $this->jsonResponse(false, 'Sem permissão para gerenciar membros deste canal.', 403);
            return;
        }

        $addedUser = User::find($memberId);
        if (!$addedUser || (int) ($addedUser['active'] ?? 0) !== 1) {
            $this->jsonResponse(false, 'Usuário não encontrado.', 404);
            return;
        }

        if (Channel::isMember($channelId, $memberId)) {
            $this->jsonResponse(false, 'Usuário já é membro deste canal.', 409);
            return;
        }

        Channel::addMember($channelId, $memberId);
        Channel::systemMessage($channelId, $addedUser['name'] . ' entrou no canal.');
        Notification::create(
            $memberId, 'channel',
            (Session::userName() ?? 'Alguém') . ' adicionou você ao canal #' . $channel['name'],
            null,
            'index.php?m=chat&page=chat&channel_id=' . $channelId
        );
        AuditLog::log('add_member', 'channel', $channelId, null, ['user_id' => $memberId]);

        $this->jsonResponse(true, 'Membro adicionado com sucesso.');
    }

    public function removeMember(): void
    {
        Auth::requireLogin();
        if (!Csrf::checkAjax()) {
            $this->jsonResponse(false, 'Token CSRF inválido.', 403);
            return;
        }

        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);
        $memberId  = Sanitize::int($_POST['user_id'] ?? 0);

        $channel = Channel::find($channelId);
        if (!$channel || $memberId <= 0 || $channel['type'] === 'direct') {
            $this->jsonResponse(false, 'Dados inválidos.', 400);
            return;
        }

        if (!$this->canManageMembers($channel, Session::userId())) {
            $this->jsonResponse(false, 'Sem permissão para gerenciar membros deste canal.', 403);
            return;
        }

        if (!Channel::isMember($channelId, $memberId)) {
            $this->jsonResponse(false, 'Usuário não é membro deste canal.', 404);
            return;
        }

        Channel::removeMember($channelId, $memberId);
        $removedUser = User::find($memberId);
        Channel::systemMessage($channelId, ($removedUser['name'] ?? 'Usuário') . ' saiu do canal.');
        AuditLog::log('remove_member', 'channel', $channelId, null, ['user_id' => $memberId]);

        $this->jsonResponse(true, 'Membro removido com sucesso.');
    }

    /* ------------------------------------------------------------------
     *  join / leave — POST
     * ----------------------------------------------------------------*/
    public function join(): void
    {
        Auth::requireLogin();
        core_require('channels.view');
        Csrf::check();

        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);
        $channel   = Channel::find($channelId);

        if (!$channel || $channel['type'] !== 'public' || (int) $channel['is_archived'] === 1) {
            Session::flash('error', 'Canal não encontrado ou não é público.');
            core_redirect('index.php?m=chat&page=channels&action=browse');
        }

        $userId = Session::userId();
        if (!Channel::isMember($channelId, $userId)) {
            Channel::addMember($channelId, $userId);
            Channel::systemMessage($channelId, (Session::userName() ?? 'Usuário') . ' entrou no canal.');
            Session::flash('success', 'Você entrou no canal #' . $channel['name'] . '.');
        }
        core_redirect('index.php?m=chat&page=chat&channel_id=' . $channelId);
    }

    public function leave(): void
    {
        Auth::requireLogin();
        core_require('chat.view');
        Csrf::check();

        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);
        $channel   = Channel::find($channelId);

        if (!$channel) {
            Session::flash('error', 'Canal não encontrado.');
            core_redirect('index.php?m=chat&page=chat');
        }
        if ((int) $channel['is_general'] === 1) {
            Session::flash('error', 'Não é possível sair do canal geral.');
            core_redirect('index.php?m=chat&page=chat&channel_id=' . $channelId);
        }

        $userId = Session::userId();
        if (Channel::isMember($channelId, $userId)) {
            Channel::removeMember($channelId, $userId);
            Channel::systemMessage($channelId, (Session::userName() ?? 'Usuário') . ' saiu do canal.');
        }

        Session::flash('success', 'Você saiu do canal #' . $channel['name'] . '.');
        core_redirect('index.php?m=chat&page=chat');
    }

    /* ------------------------------------------------------------------
     *  browse — GET
     * ----------------------------------------------------------------*/
    public function browse(): void
    {
        Auth::requireLogin();
        core_require('channels.view');

        View::render('channels/browse', [
            'pageTitle' => 'Canais',
            'channels'  => Channel::publicChannelsFor(Session::userId()),
        ]);
    }

    /* ------------------------------------------------------------------
     *  direct — abre (ou cria) a conversa direta com um usuário
     * ----------------------------------------------------------------*/
    public function direct(): void
    {
        Auth::requireLogin();
        core_require('chat.view');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::check();
        }

        $userId   = Session::userId();
        $targetId = Sanitize::int($_POST['user_id'] ?? $_GET['user_id'] ?? 0);

        if ($targetId <= 0 || $targetId === $userId) {
            Session::flash('error', 'Usuário inválido.');
            core_redirect('index.php?m=chat&page=chat');
        }

        $target = User::find($targetId);
        if (!$target || (int) ($target['active'] ?? 0) !== 1) {
            Session::flash('error', 'Usuário não encontrado.');
            core_redirect('index.php?m=chat&page=chat');
        }

        $channel = Channel::directChannel($userId, $targetId);
        if (!$channel) {
            $slug = 'dm-' . min($userId, $targetId) . '-' . max($userId, $targetId);
            if (Channel::findBySlug($slug)) {
                $slug .= '-' . time();
            }
            $channelId = Channel::insert([
                'name'        => $slug,
                'slug'        => $slug,
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

        core_redirect('index.php?m=chat&page=chat&channel_id=' . $channelId);
    }

    /* ------------------------------------------------------------------
     *  settings / updateSettings
     * ----------------------------------------------------------------*/
    public function settings(): void
    {
        Auth::requireLogin();
        core_require('channels.view');

        $channelId = Sanitize::int($_GET['id'] ?? 0);
        $channel   = Channel::find($channelId);
        if (!$channel || $channel['type'] === 'direct' || !$this->canManageChannel($channel, Session::userId(), 'channels.edit')) {
            Session::flash('error', 'Sem permissão.');
            core_redirect('index.php?m=chat&page=chat');
        }
        View::render('channels/settings', [
            'pageTitle' => 'Configurações do canal',
            'channel'   => $channel,
            'canArchive' => (int) $channel['is_general'] !== 1 && $this->canManageChannel($channel, Session::userId(), 'channels.delete'),
        ]);
    }

    public function updateSettings(): void
    {
        Auth::requireLogin();
        core_require('channels.view');
        Csrf::check();

        $channelId = Sanitize::int($_POST['id'] ?? 0);
        $channel   = Channel::find($channelId);
        if (!$channel || $channel['type'] === 'direct' || !$this->canManageChannel($channel, Session::userId(), 'channels.edit')) {
            Session::flash('error', 'Sem permissão.');
            core_redirect('index.php?m=chat&page=chat');
        }

        $retention = ($_POST['retention_days'] ?? '') !== '' ? max(0, Sanitize::int($_POST['retention_days'])) : null;
        Channel::update($channelId, [
            'is_readonly'       => Sanitize::int($_POST['is_readonly'] ?? 0) ? 1 : 0,
            'retention_days'    => $retention ?: null,
            'slow_mode_seconds' => max(0, min(3600, Sanitize::int($_POST['slow_mode_seconds'] ?? 0))),
            'max_pinned'        => max(1, min(200, Sanitize::int($_POST['max_pinned'] ?? 50))),
            'allow_threads'     => Sanitize::int($_POST['allow_threads'] ?? 1) ? 1 : 0,
        ]);
        AuditLog::log('update_channel_settings', 'channel', $channelId);

        Session::flash('success', 'Configurações do canal atualizadas.');
        core_redirect('index.php?m=chat&page=channels&action=settings&id=' . $channelId);
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    private function categories(): array
    {
        return $this->db->query('SELECT id, name FROM chat_channel_categories ORDER BY order_num ASC, name ASC')->fetchAll();
    }

    /**
     * Pode gerir os MEMBROS do canal? Além da gestão normal, em canais
     * privados (e DMs) é preciso participar do canal — senão quem tem
     * channels.edit poderia se auto-adicionar a qualquer conversa privada
     * e ler todo o histórico.
     */
    private function canManageMembers(array $channel, int $userId): bool
    {
        if (!$this->canManageChannel($channel, $userId, 'channels.edit')) {
            return false;
        }
        if (($channel['type'] ?? '') === 'public') {
            return true;
        }
        return Channel::isMember((int) $channel['id'], $userId);
    }

    /**
     * Pode gerir o canal? Quem tem a micropermissão ($permKey), o criador
     * do canal e os owners/admins do próprio canal.
     */
    private function canManageChannel(array $channel, int $userId, string $permKey = 'channels.edit'): bool
    {
        if (core_can($permKey)) {
            return true;
        }
        if ((int) ($channel['created_by'] ?? 0) === $userId) {
            return true;
        }
        $role = Channel::memberRole((int) $channel['id'], $userId);
        return $role !== null && in_array($role, ['owner', 'admin'], true);
    }

    private function jsonResponse(bool $success, string $message, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    }
}
