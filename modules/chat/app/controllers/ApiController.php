<?php
/**
 * ApiController — endpoints JSON do chat em tempo real (polling).
 *
 * Cada método público é acessível por ?page=api&action=<método>.
 * Toda resposta é application/json. Métodos que alteram estado exigem
 * POST + token CSRF (_csrf_token no corpo ou header X-CSRF-TOKEN).
 *
 * Endpoints: sendMessage, getMessages, editMessage, deleteMessage,
 * toggleReaction, pinMessage, getThread, getPinnedMessages, heartbeat,
 * userStatus, searchUsers, markChannelRead, typing, linkPreview,
 * getCustomEmojis, toggleFavorite.
 */
class ApiController
{
    private \PDO $db;

    /** Menções coletivas aceitas (@canal/@channel/@todos → todos; @here → online). */
    private const GROUP_MENTIONS = ['canal' => 'channel', 'channel' => 'channel', 'todos' => 'channel', 'here' => 'here', 'aqui' => 'here'];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        $this->json(['success' => false, 'error' => 'Ação não informada.'], 404);
    }

    /* ==================================================================
     *  Mensagens
     * ================================================================*/

    /** POST sendMessage — channel_id, content, parent_id?, attachment? */
    public function sendMessage(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->requirePerm('chat.create');

        $userId    = Session::userId();
        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);
        $content   = mb_substr(Sanitize::string($_POST['content'] ?? ''), 0, 10000);
        $parentId  = !empty($_POST['parent_id']) ? Sanitize::int($_POST['parent_id']) : null;

        $channel = $channelId > 0 ? Channel::find($channelId) : null;
        if (!$channel || (int) $channel['is_archived'] === 1) {
            $this->json(['success' => false, 'error' => 'Canal inválido.'], 400);
            return;
        }
        if (!Channel::isMember($channelId, $userId)) {
            $this->json(['success' => false, 'error' => 'Você não é membro deste canal.'], 403);
            return;
        }

        $isModerator = core_can('chat.moderate');
        if ((int) ($channel['is_readonly'] ?? 0) === 1 && !$isModerator) {
            $this->json(['success' => false, 'error' => 'Este canal está em modo somente leitura.'], 403);
            return;
        }

        // Slow mode: intervalo mínimo entre mensagens do mesmo usuário
        $slow = (int) ($channel['slow_mode_seconds'] ?? 0);
        if ($slow > 0 && !$isModerator) {
            $since = Message::secondsSinceLastOf($channelId, $userId);
            if ($since !== null && $since < $slow) {
                $this->json(['success' => false, 'error' => 'Modo lento: aguarde ' . ($slow - $since) . 's para enviar outra mensagem.'], 429);
                return;
            }
        }

        $hasFile = !empty($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($content === '' && !$hasFile) {
            $this->json(['success' => false, 'error' => 'Mensagem vazia.'], 422);
            return;
        }

        $parent = null;
        if ($parentId) {
            $parent = Message::find($parentId);
            if (!$parent || (int) $parent['channel_id'] !== $channelId || $parent['deleted_at'] !== null) {
                $this->json(['success' => false, 'error' => 'Mensagem pai inválida.'], 422);
                return;
            }
            if (!empty($parent['parent_id'])) {
                $parentId = (int) $parent['parent_id']; // respostas sempre na raiz da thread
            }
            if ((int) ($channel['allow_threads'] ?? 1) !== 1) {
                $this->json(['success' => false, 'error' => 'Threads desativadas neste canal.'], 403);
                return;
            }
        }

        // Anexo (validado ANTES de gravar a mensagem)
        $upload = null;
        if ($hasFile) {
            $upload = Upload::handle('attachment', 'attachments');
            if (!$upload['success']) {
                $this->json(['success' => false, 'error' => $upload['error']], 422);
                return;
            }
        }

        $messageId = Message::insert([
            'channel_id' => $channelId,
            'user_id'    => $userId,
            'parent_id'  => $parentId,
            'content'    => $content,
            'type'       => ($content === '' && $upload) ? 'file' : 'text',
        ]);

        if ($parentId) {
            Message::incrementReplyCount($parentId);
        }
        if ($upload) {
            Message::addAttachment($messageId, $userId, $upload);
        }

        // Quem envia já leu a própria mensagem
        Channel::updateLastRead($channelId, $userId, $messageId);
        User::updateLastSeen($userId);

        $this->processMentions($content, $messageId, $channel, $userId);

        // Notifica o interlocutor em mensagens diretas
        if ($channel['type'] === 'direct') {
            foreach (Channel::memberIds($channelId) as $mid) {
                if ($mid !== $userId) {
                    Notification::create(
                        $mid, 'dm',
                        (Session::userName() ?? 'Alguém') . ' enviou uma mensagem',
                        mb_substr($content !== '' ? $content : 'Enviou um arquivo', 0, 100),
                        'index.php?m=chat&page=chat&channel_id=' . $channelId
                    );
                }
            }
        } elseif ($parent && (int) $parent['user_id'] !== $userId && $parent['user_id']) {
            // Resposta na thread: avisa o autor da mensagem original
            Notification::create(
                (int) $parent['user_id'], 'thread',
                (Session::userName() ?? 'Alguém') . ' respondeu sua mensagem em #' . $channel['name'],
                mb_substr($content, 0, 100),
                'index.php?m=chat&page=chat&channel_id=' . $channelId . '#msg-' . (int) $parent['id']
            );
        }

        AuditLog::log('send_message', 'message', $messageId);

        $this->json(['success' => true, 'message' => Message::findFull($messageId)]);
    }

    /**
     * GET getMessages — channel_id, after_id
     * Devolve mensagens novas, alterações recentes (edição/exclusão/
     * fixação/respostas/reações) e quem está digitando.
     */
    public function getMessages(): void
    {
        $this->requireAuth();
        $this->requirePerm('chat.view');

        $userId    = Session::userId();
        $channelId = Sanitize::int($_GET['channel_id'] ?? 0);
        $afterId   = Sanitize::int($_GET['after_id']   ?? 0);

        if ($channelId <= 0 || !Channel::isMember($channelId, $userId)) {
            $this->json(['success' => false, 'error' => 'Acesso negado.'], 403);
            return;
        }

        $messages = Message::newMessages($channelId, $afterId);

        if (!empty($messages)) {
            $last = end($messages);
            Channel::updateLastRead($channelId, $userId, (int) $last['id']);
        }
        User::updateLastSeen($userId);

        $changed   = $afterId > 0 ? Message::changedSince($channelId, $afterId) : [];
        $reactions = $changed
            ? Message::reactionsFor(array_column($changed, 'id'))
            : [];

        $this->json([
            'success'   => true,
            'messages'  => $messages,
            'changed'   => $changed,
            'reactions' => (object) $reactions,
            'typing'    => $this->typingUsers($channelId, $userId),
        ]);
    }

    /** POST editMessage — message_id, content (somente o autor, com chat.edit) */
    public function editMessage(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->requirePerm('chat.edit');

        $userId    = Session::userId();
        $messageId = Sanitize::int($_POST['message_id'] ?? 0);
        $content   = mb_substr(Sanitize::string($_POST['content'] ?? ''), 0, 10000);

        if ($messageId <= 0 || $content === '') {
            $this->json(['success' => false, 'error' => 'Dados inválidos.'], 422);
            return;
        }

        $message = Message::find($messageId);
        if (!$message || $message['deleted_at'] !== null || (int) $message['user_id'] !== $userId || $message['type'] === 'system') {
            $this->json(['success' => false, 'error' => 'Sem permissão para editar.'], 403);
            return;
        }

        Message::update($messageId, [
            'content'   => $content,
            'is_edited' => 1,
            'edited_at' => date('Y-m-d H:i:s'),
        ]);

        AuditLog::log('edit_message', 'message', $messageId, ['content' => $message['content']], ['content' => $content]);

        $this->json(['success' => true, 'message_id' => $messageId, 'content' => $content]);
    }

    /** POST deleteMessage — message_id */
    public function deleteMessage(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $userId    = Session::userId();
        $messageId = Sanitize::int($_POST['message_id'] ?? 0);

        $message = $messageId > 0 ? Message::find($messageId) : null;
        if (!$message || $message['deleted_at'] !== null) {
            $this->json(['success' => false, 'error' => 'Mensagem não encontrada.'], 404);
            return;
        }

        $isOwner = (int) $message['user_id'] === $userId;

        if (core_can('chat.moderate')) {
            // Moderador exclui qualquer mensagem, sem janela de tempo.
        } elseif ($isOwner && core_can('chat.delete')) {
            if ((time() - strtotime($message['created_at'])) > 60) {
                $this->json(['success' => false, 'error' => 'Só é possível excluir mensagens até 1 minuto após o envio.'], 403);
                return;
            }
        } else {
            $this->json(['success' => false, 'error' => 'Sem permissão para excluir.'], 403);
            return;
        }

        Message::softDelete($messageId);
        AuditLog::log('delete_message', 'message', $messageId);

        $this->json(['success' => true, 'message_id' => $messageId, 'parent_id' => $message['parent_id'] ? (int) $message['parent_id'] : null]);
    }

    /* ==================================================================
     *  Reações
     * ================================================================*/

    /** POST toggleReaction — message_id, emoji */
    public function toggleReaction(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->requirePerm('chat.view');

        $userId    = Session::userId();
        $messageId = Sanitize::int($_POST['message_id'] ?? 0);
        $emoji     = mb_substr(Sanitize::string($_POST['emoji'] ?? ''), 0, 50);

        if ($messageId <= 0 || $emoji === '' || preg_match('/[<>"\'\s]/u', $emoji)) {
            $this->json(['success' => false, 'error' => 'Dados inválidos.'], 422);
            return;
        }

        $message = Message::find($messageId);
        if (!$message || $message['deleted_at'] !== null || !Channel::isMember((int) $message['channel_id'], $userId)) {
            $this->json(['success' => false, 'error' => 'Mensagem não encontrada.'], 404);
            return;
        }

        $action = Message::toggleReaction($messageId, $userId, $emoji);

        $this->json([
            'success'   => true,
            'action'    => $action,
            'reactions' => Message::reactions($messageId),
        ]);
    }

    /* ==================================================================
     *  Fixados
     * ================================================================*/

    /** POST pinMessage — message_id (alterna; requer chat.moderate) */
    public function pinMessage(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $userId    = Session::userId();
        $messageId = Sanitize::int($_POST['message_id'] ?? 0);

        if (!core_can('chat.moderate')) {
            $this->json(['success' => false, 'error' => 'Sem permissão para fixar mensagens (requer moderação do chat).'], 403);
            return;
        }

        $message = $messageId > 0 ? Message::find($messageId) : null;
        if (!$message || $message['deleted_at'] !== null) {
            $this->json(['success' => false, 'error' => 'Mensagem não encontrada.'], 404);
            return;
        }
        $channelId = (int) $message['channel_id'];
        if (!Channel::isMember($channelId, $userId)) {
            $this->json(['success' => false, 'error' => 'Você não é membro deste canal.'], 403);
            return;
        }

        if ((int) ($message['is_pinned'] ?? 0) === 1) {
            Message::update($messageId, ['is_pinned' => 0, 'pinned_by' => null, 'pinned_at' => null]);
            $newState = false;
        } else {
            $channel = Channel::find($channelId);
            $max     = (int) ($channel['max_pinned'] ?? 50);
            if ($max > 0 && Message::pinnedCount($channelId) >= $max) {
                $this->json(['success' => false, 'error' => "Limite de {$max} mensagens fixadas atingido neste canal."], 422);
                return;
            }
            Message::update($messageId, ['is_pinned' => 1, 'pinned_by' => $userId, 'pinned_at' => date('Y-m-d H:i:s')]);
            $newState = true;
        }

        AuditLog::log($newState ? 'pin_message' : 'unpin_message', 'message', $messageId);

        $this->json([
            'success'      => true,
            'pinned'       => $newState,
            'message_id'   => $messageId,
            'pinned_count' => Message::pinnedCount($channelId),
        ]);
    }

    /** GET getPinnedMessages — channel_id */
    public function getPinnedMessages(): void
    {
        $this->requireAuth();
        $this->requirePerm('chat.view');

        $channelId = Sanitize::int($_GET['channel_id'] ?? 0);
        if ($channelId <= 0 || !Channel::isMember($channelId, Session::userId())) {
            $this->json(['success' => false, 'error' => 'Acesso negado.', 'messages' => []], 403);
            return;
        }
        $this->json(['success' => true, 'messages' => Message::pinnedMessages($channelId)]);
    }

    /* ==================================================================
     *  Threads
     * ================================================================*/

    /** GET getThread — message_id (mensagem raiz) */
    public function getThread(): void
    {
        $this->requireAuth();
        $this->requirePerm('chat.view');

        $messageId = Sanitize::int($_GET['message_id'] ?? 0);
        $parent    = $messageId > 0 ? Message::findFull($messageId) : null;

        if (!$parent || $parent['deleted_at'] !== null) {
            $this->json(['success' => false, 'error' => 'Mensagem não encontrada.'], 404);
            return;
        }
        if (!Channel::isMember((int) $parent['channel_id'], Session::userId())) {
            $this->json(['success' => false, 'error' => 'Acesso negado.'], 403);
            return;
        }

        $this->json([
            'success' => true,
            'parent'  => $parent,
            'replies' => Message::threadReplies($messageId),
        ]);
    }

    /* ==================================================================
     *  Presença / heartbeat
     * ================================================================*/

    /** GET heartbeat — não lidas por canal e presença dos contatos das DMs. */
    public function heartbeat(): void
    {
        $this->requireAuth();
        $this->requirePerm('chat.view');

        $userId = Session::userId();
        User::updateLastSeen($userId);

        $stmt = $this->db->prepare(
            'SELECT cm2.user_id, COALESCE(p.status, "offline") AS status
             FROM chat_channel_members cm
             INNER JOIN chat_channels c ON c.id = cm.channel_id AND c.type = "direct" AND c.is_archived = 0
             INNER JOIN chat_channel_members cm2 ON cm2.channel_id = cm.channel_id AND cm2.user_id <> cm.user_id
             LEFT JOIN chat_presence p ON p.user_id = cm2.user_id
             WHERE cm.user_id = ?'
        );
        $stmt->execute([$userId]);
        $presence = [];
        foreach ($stmt->fetchAll() as $row) {
            $presence[(int) $row['user_id']] = $row['status'];
        }

        $this->json([
            'success'            => true,
            'unread_channels'    => (object) Channel::unreadCounts($userId),
            'presence'           => (object) $presence,
            'notification_count' => Notification::unreadCount($userId),
        ]);
    }

    /** POST userStatus — status (online|away|dnd|offline) */
    public function userStatus(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->requirePerm('chat.view');

        $status = Sanitize::string($_POST['status'] ?? '');
        if (!in_array($status, ['online', 'away', 'dnd', 'offline'], true)) {
            $this->json(['success' => false, 'error' => 'Status inválido.'], 422);
            return;
        }

        User::updateStatus(Session::userId(), $status);
        $this->json(['success' => true, 'status' => $status]);
    }

    /** GET searchUsers — query (autocomplete de menções / nova DM / convite) */
    public function searchUsers(): void
    {
        $this->requireAuth();
        $this->requirePerm('chat.view');

        $query = mb_substr(Sanitize::string($_GET['query'] ?? ''), 0, 100);
        if ($query === '') {
            $this->json(['success' => true, 'users' => []]);
            return;
        }

        $users = array_map(static fn (array $u) => [
            'id'     => (int) $u['id'],
            'name'   => $u['name'],
            'email'  => $u['email'],
            'status' => $u['status'],
            'title'  => $u['title'],
            'avatar' => $u['avatar'] ? User::avatarUrl($u['avatar']) : '',
        ], User::search($query));

        $this->json(['success' => true, 'users' => $users]);
    }

    /** POST markChannelRead — channel_id */
    public function markChannelRead(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->requirePerm('chat.view');

        $userId    = Session::userId();
        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);

        if ($channelId <= 0 || !Channel::isMember($channelId, $userId)) {
            $this->json(['success' => false, 'error' => 'Acesso negado.'], 403);
            return;
        }

        $latestId = Message::lastIdOf($channelId);
        if ($latestId > 0) {
            Channel::updateLastRead($channelId, $userId, $latestId);
        }

        $this->json(['success' => true, 'channel_id' => $channelId, 'last_read_message_id' => $latestId]);
    }

    /** POST toggleFavorite — channel_id */
    public function toggleFavorite(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->requirePerm('chat.view');

        $userId    = Session::userId();
        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);

        if ($channelId <= 0 || !Channel::isMember($channelId, $userId)) {
            $this->json(['success' => false, 'error' => 'Acesso negado.'], 403);
            return;
        }

        $this->json(['success' => true, 'channel_id' => $channelId, 'favorite' => Channel::toggleFavorite($channelId, $userId)]);
    }

    /* ==================================================================
     *  Indicador "digitando" (arquivos em storage/cache, expiram em 5s)
     * ================================================================*/

    /** POST typing — channel_id, typing (0|1) */
    public function typing(): void
    {
        $this->requireAuth();
        $this->requireCsrf();
        $this->requirePerm('chat.view');

        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);
        $typing    = Sanitize::int($_POST['typing'] ?? 0);
        $userId    = Session::userId();

        if ($channelId <= 0 || !Channel::isMember($channelId, $userId)) {
            $this->json(['success' => true]);
            return;
        }

        $file = $this->typingFile($channelId, $userId);
        if ($typing) {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($file, json_encode([
                'user_id'   => $userId,
                'user_name' => Session::userName(),
                'expires'   => time() + 5,
            ]));
        } elseif (is_file($file)) {
            @unlink($file);
        }

        $this->json(['success' => true]);
    }

    private function typingFile(int $channelId, int $userId): string
    {
        return STORAGE_PATH . '/cache/chat_typing_' . $channelId . '_' . $userId . '.json';
    }

    /** Quem está digitando no canal (exceto o próprio usuário). */
    private function typingUsers(int $channelId, int $userId): array
    {
        $out   = [];
        $files = glob(STORAGE_PATH . '/cache/chat_typing_' . $channelId . '_*.json') ?: [];
        foreach ($files as $file) {
            $data = @json_decode((string) @file_get_contents($file), true);
            if (!$data || ($data['expires'] ?? 0) <= time()) {
                @unlink($file);
                continue;
            }
            if ((int) $data['user_id'] !== $userId) {
                $out[] = ['user_id' => (int) $data['user_id'], 'user_name' => (string) $data['user_name']];
            }
        }
        return $out;
    }

    /* ==================================================================
     *  Pré-visualização de links (cache 1h)
     * ================================================================*/

    /** GET linkPreview — url */
    public function linkPreview(): void
    {
        $this->requireAuth();
        $this->requirePerm('chat.view');

        $url = Sanitize::string($_GET['url'] ?? '');
        if (!$this->isSafeUrl($url)) {
            $this->json(['title' => null]);
            return;
        }

        $cacheFile = STORAGE_PATH . '/cache/chat_link_' . md5($url) . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $this->json(json_decode((string) file_get_contents($cacheFile), true) ?: ['title' => null]);
            return;
        }

        $result = ['title' => null, 'description' => null, 'image' => null, 'domain' => parse_url($url, PHP_URL_HOST)];

        $ctx = stream_context_create([
            'http' => ['timeout' => 3, 'user_agent' => 'Comunicacao-Chat/1.0', 'follow_location' => 1, 'max_redirects' => 2],
        ]);
        $html = @file_get_contents($url, false, $ctx, 0, 60000);
        if ($html) {
            if (preg_match('/<meta\s+(?:property|name)=["\']og:title["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
                $result['title'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            } elseif (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
                $result['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            }
            if (preg_match('/<meta\s+(?:property|name)=["\']og:description["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
                $result['description'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
            if (preg_match('/<meta\s+(?:property|name)=["\']og:image["\']\s+content=["\'](https?:[^"\']+)["\']/i', $html, $m)) {
                $result['image'] = $m[1];
            }
        }

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($cacheFile, json_encode($result));

        $this->json($result);
    }

    /** Só http(s) para hosts públicos (evita SSRF para a rede interna). */
    private function isSafeUrl(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > 2000 || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (array) @gethostbynamel($host);
        if (!$ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return true;
    }

    /* ==================================================================
     *  Emojis personalizados (seletor do chat)
     * ================================================================*/

    public function getCustomEmojis(): void
    {
        $this->requireAuth();
        $this->requirePerm('chat.view');

        $rows   = $this->db->query('SELECT name, image_path FROM chat_custom_emojis ORDER BY name ASC')->fetchAll();
        $emojis = array_map(static fn (array $e) => ['name' => $e['name'], 'url' => Upload::url($e['image_path'])], $rows);
        $this->json(['success' => true, 'emojis' => $emojis]);
    }

    /* ==================================================================
     *  Helpers
     * ================================================================*/

    private function requireAuth(): void
    {
        if (!Session::isLoggedIn()) {
            $this->json(['success' => false, 'error' => 'Autenticação necessária.'], 401);
            exit;
        }
    }

    /** 403 JSON quando falta a micropermissão (core_require renderiza HTML). */
    private function requirePerm(string $permKey): void
    {
        if (!core_can($permKey)) {
            $this->json(['success' => false, 'error' => 'Você não tem permissão para esta ação.'], 403);
            exit;
        }
    }

    /** Exige POST com token CSRF válido. */
    private function requireCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['success' => false, 'error' => 'Método não permitido.'], 405);
            exit;
        }
        if (!Csrf::checkAjax()) {
            $this->json(['success' => false, 'error' => 'Token CSRF inválido.'], 403);
            exit;
        }
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Menções: @Nome.Sobrenome / @nome (usuário), @canal|@channel|@todos
     * (todos os membros) e @here|@aqui (membros online). Grava em
     * chat_mentions e notifica.
     */
    private function processMentions(string $content, int $messageId, array $channel, int $senderId): void
    {
        if ($content === '' || !preg_match_all('/(?<![\w@])@([\p{L}\p{N}_.\-]+)/u', $content, $matches)) {
            return;
        }

        $channelId   = (int) $channel['id'];
        $senderName  = Session::userName() ?? 'Alguém';
        $channelName = $channel['type'] === 'direct' ? 'mensagem direta' : '#' . $channel['name'];
        $link        = "index.php?m=chat&page=chat&channel_id={$channelId}#msg-{$messageId}";
        $excerpt     = mb_substr($content, 0, 120);
        $notified    = [$senderId => true];
        $memberIds   = null;
        $insert      = $this->db->prepare('INSERT INTO chat_mentions (message_id, user_id, type, created_at) VALUES (?, ?, ?, NOW())');

        foreach (array_unique($matches[1]) as $token) {
            $key = mb_strtolower(rtrim($token, '.'));

            // Menções coletivas
            if (isset(self::GROUP_MENTIONS[$key])) {
                $type = self::GROUP_MENTIONS[$key];
                $insert->execute([$messageId, null, $type]);
                $ids = Channel::memberIds($channelId, $type === 'here');
                foreach ($ids as $uid) {
                    if (isset($notified[$uid])) {
                        continue;
                    }
                    $notified[$uid] = true;
                    Notification::create($uid, 'mention', "{$senderName} mencionou @{$key} em {$channelName}", $excerpt, $link);
                }
                continue;
            }

            // Usuário: nome sem espaços/pontos, comparação sem acento-sensibilidade
            $needle = str_replace('.', '', $key);
            $stmt   = $this->db->prepare(
                'SELECT id FROM users WHERE active = 1 AND (LOWER(REPLACE(name, " ", "")) = ? OR LOWER(username) = ?) LIMIT 1'
            );
            $stmt->execute([$needle, $key]);
            $row = $stmt->fetch();
            if (!$row) {
                continue;
            }
            $uid = (int) $row['id'];
            if (isset($notified[$uid])) {
                continue;
            }
            // Só notifica quem participa do canal (canais privados/DMs)
            $memberIds ??= Channel::memberIds($channelId);
            if (!in_array($uid, $memberIds, true)) {
                continue;
            }
            $notified[$uid] = true;
            $insert->execute([$messageId, $uid, 'user']);
            Notification::create($uid, 'mention', "{$senderName} mencionou você em {$channelName}", $excerpt, $link);
        }
    }
}
