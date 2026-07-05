<?php
/**
 * ApiController — JSON API endpoints for real-time chat functionality.
 *
 * Every public method is reachable via ?page=api&action=<method>.
 * All responses are application/json.  POST/PUT/DELETE methods require
 * a valid CSRF token sent in the X-CSRF-TOKEN header.
 */
class ApiController
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /* ==================================================================
     *  Messages
     * ================================================================*/

    /**
     * POST  sendMessage
     * Params: channel_id, content, parent_id (optional), file (optional)
     */
    public function sendMessage(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $userId    = Session::userId();
        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);
        $content   = Sanitize::string($_POST['content'] ?? '');
        $parentId  = !empty($_POST['parent_id']) ? Sanitize::int($_POST['parent_id']) : null;

        if ($channelId <= 0) {
            $this->json(['success' => false, 'error' => 'Canal inválido.'], 400);
            return;
        }

        if (!Channel::isMember($channelId, $userId)) {
            $this->json(['success' => false, 'error' => 'Você não é membro deste canal.'], 403);
            return;
        }

        $channel = Channel::find($channelId);
        if ($channel && (int)($channel['is_readonly'] ?? 0) === 1 && !Auth::isAdmin()) {
            $this->json(['success' => false, 'error' => 'Este canal está em modo somente leitura.'], 403);
            return;
        }

        if ($content === '' && empty($_FILES['file'])) {
            $this->json(['success' => false, 'error' => 'Mensagem vazia.'], 422);
            return;
        }

        // --- Insert message --------------------------------------------
        $messageData = [
            'channel_id' => $channelId,
            'user_id'    => $userId,
            'content'    => $content,
            'type'       => 'text',
        ];

        if ($parentId) {
            $parent = Message::find($parentId);
            if (!$parent || (int) $parent['channel_id'] !== $channelId) {
                $this->json(['success' => false, 'error' => 'Mensagem pai inválida.'], 422);
                return;
            }
            $messageData['parent_id'] = $parentId;
        }

        $messageId = Message::insert($messageData);

        // Increment reply count on the parent when this is a thread reply
        if ($parentId) {
            Message::incrementReplyCount($parentId);
        }

        // --- File attachment -------------------------------------------
        $attachment = null;
        if (!empty($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $upload = Upload::handle('attachment', 'attachments');
            if ($upload['success']) {
                Message::addAttachment($messageId, $userId, $upload);
                $attachment = $upload;

                // If the message body is empty, mark type as file
                if ($content === '') {
                    Message::update($messageId, ['type' => 'file']);
                }
            }
        }

        // --- Parse @mentions -------------------------------------------
        $this->processMentions($content, $messageId, $channelId, $userId);

        // --- Build response with user info -----------------------------
        $message = Message::find($messageId);
        $user    = User::findWithPresence($userId);

        $message['user_name']   = $user['name']   ?? '';
        $message['user_avatar'] = $user['avatar']  ?? '';
        $message['user_status'] = $user['status']  ?? 'online';
        $message['attachment']  = $attachment;

        // --- Notify DM recipients ---
        $ch = Channel::find($channelId);
        if ($ch && $ch['type'] === 'direct') {
            $members = Channel::members($channelId);
            foreach ($members as $m) {
                if ((int)$m['id'] !== $userId) {
                    Notification::create(
                        (int)$m['id'], 'dm',
                        ($user['name'] ?? 'Alguém') . ' enviou uma mensagem',
                        mb_substr($content, 0, 100),
                        'index.php?m=chat&page=chat&channel_id=' . $channelId
                    );
                }
            }
        }

        AuditLog::log('send_message', 'message', $messageId);

        $this->json(['success' => true, 'message' => $message]);
    }

    /**
     * GET  getMessages
     * Params: channel_id, after_id
     */
    public function getMessages(): void
    {
        $this->requireAuth();

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

        // Deleted messages since after_id (for real-time sync)
        $deleted = [];
        if ($afterId > 0) {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT id FROM chat_messages WHERE channel_id = ? AND id > ? AND deleted_at IS NOT NULL AND parent_id IS NULL'
            );
            $stmt->execute([$channelId, $afterId]);
            $deleted = array_column($stmt->fetchAll(), 'id');
        }

        // Edited messages since last poll (updated_at within last poll interval)
        $edited = [];
        if ($afterId > 0) {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT m.id, m.content, m.is_edited FROM chat_messages m
                 WHERE m.channel_id = ? AND m.id <= ? AND m.is_edited = 1
                 AND m.edited_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND) AND m.deleted_at IS NULL'
            );
            $stmt->execute([$channelId, $afterId]);
            $edited = $stmt->fetchAll();
        }

        // Typing indicators
        $typing = [];
        $cacheDir = STORAGE_PATH . '/cache/';
        foreach (glob($cacheDir . '*.typing') as $file) {
            $data = @json_decode(@file_get_contents($file), true);
            if ($data && ($data['expires'] ?? 0) > time()) {
                $typing[] = $data;
            } else {
                @unlink($file);
            }
        }

        // Reactions changed recently
        $reactionsUpdated = [];
        if ($afterId > 0) {
            $db = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT DISTINCT mr.message_id FROM chat_message_reactions mr
                 INNER JOIN chat_messages m ON m.id = mr.message_id
                 WHERE m.channel_id = ? AND mr.created_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)'
            );
            $stmt->execute([$channelId]);
            $changedMsgIds = array_column($stmt->fetchAll(), 'message_id');
            foreach ($changedMsgIds as $mid) {
                $reactionsUpdated[$mid] = Message::reactions((int)$mid);
            }
        }

        $this->json([
            'success'    => true,
            'messages'   => $messages,
            'deleted'    => $deleted,
            'edited'     => $edited,
            'reactions'  => $reactionsUpdated,
            'typing'     => $typing,
        ]);
    }

    /**
     * POST  editMessage
     * Params: message_id, content
     */
    public function editMessage(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $userId    = Session::userId();
        $messageId = Sanitize::int($_POST['message_id'] ?? 0);
        $content   = Sanitize::string($_POST['content']  ?? '');

        if ($messageId <= 0 || $content === '') {
            $this->json(['success' => false, 'error' => 'Dados inválidos.'], 422);
            return;
        }

        $message = Message::find($messageId);
        if (!$message || (int) $message['user_id'] !== $userId) {
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

    /**
     * POST  deleteMessage
     * Params: message_id
     */
    public function deleteMessage(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $userId    = Session::userId();
        $messageId = Sanitize::int($_POST['message_id'] ?? 0);

        if ($messageId <= 0) {
            $this->json(['success' => false, 'error' => 'ID inválido.'], 422);
            return;
        }

        $message = Message::find($messageId);
        if (!$message) {
            $this->json(['success' => false, 'error' => 'Mensagem não encontrada.'], 404);
            return;
        }

        $isOwner = (int) $message['user_id'] === $userId;

        if (Auth::isAdmin()) {
            // Admin can delete any message
        } elseif ($isOwner) {
            $sentAt = strtotime($message['created_at']);
            if ((time() - $sentAt) > 60) {
                $this->json(['success' => false, 'error' => 'Só é possível excluir mensagens até 1 minuto após o envio.'], 403);
                return;
            }
        } else {
            $this->json(['success' => false, 'error' => 'Sem permissão para excluir.'], 403);
            return;
        }

        Message::softDelete($messageId);

        AuditLog::log('delete_message', 'message', $messageId);

        $this->json(['success' => true, 'message_id' => $messageId]);
    }

    /* ==================================================================
     *  Reactions
     * ================================================================*/

    /**
     * POST  toggleReaction
     * Params: message_id, emoji
     */
    public function toggleReaction(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $userId    = Session::userId();
        $messageId = Sanitize::int($_POST['message_id'] ?? 0);
        $emoji     = Sanitize::string($_POST['emoji'] ?? '');

        if ($messageId <= 0 || $emoji === '') {
            $this->json(['success' => false, 'error' => 'Dados inválidos.'], 422);
            return;
        }

        $message = Message::find($messageId);
        if (!$message) {
            $this->json(['success' => false, 'error' => 'Mensagem não encontrada.'], 404);
            return;
        }

        $action    = Message::toggleReaction($messageId, $userId, $emoji);
        $reactions = Message::reactions($messageId);

        $this->json([
            'success'   => true,
            'action'    => $action,
            'reactions' => $reactions,
        ]);
    }

    public function getReactions(): void
    {
        $this->requireAuth();
        $messageId = Sanitize::int($_GET['message_id'] ?? 0);
        if ($messageId <= 0) { $this->json(['reactions' => []]); return; }
        $reactions = Message::reactions($messageId);
        $this->json(['reactions' => $reactions]);
    }

    public function getReactionsBatch(): void
    {
        $this->requireAuth();
        $idsRaw = Sanitize::string($_GET['ids'] ?? '');
        if (!$idsRaw) { $this->json(['reactions' => []]); return; }

        $ids = array_filter(array_map('intval', explode(',', $idsRaw)));
        if (!$ids) { $this->json(['reactions' => []]); return; }

        $result = [];
        foreach ($ids as $id) {
            $r = Message::reactions($id);
            if ($r) $result[$id] = $r;
        }
        $this->json(['reactions' => $result]);
    }

    /* ==================================================================
     *  Pins
     * ================================================================*/

    /**
     * POST  pinMessage
     * Params: message_id  (toggles pin status)
     */
    public function pinMessage(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $userId    = Session::userId();
        $messageId = Sanitize::int($_POST['message_id'] ?? 0);

        if ($messageId <= 0) {
            $this->json(['success' => false, 'error' => 'ID inválido.'], 422);
            return;
        }

        if (!Auth::isAdmin()) {
            $this->json(['success' => false, 'error' => 'Apenas administradores podem fixar mensagens.'], 403);
            return;
        }

        $message = Message::find($messageId);
        if (!$message) {
            $this->json(['success' => false, 'error' => 'Mensagem não encontrada.'], 404);
            return;
        }

        $isPinned = (int) ($message['is_pinned'] ?? 0);

        if ($isPinned) {
            // Unpin
            Message::update($messageId, [
                'is_pinned' => 0,
                'pinned_by' => null,
                'pinned_at' => null,
            ]);
            $newState = false;
        } else {
            // Pin
            Message::update($messageId, [
                'is_pinned' => 1,
                'pinned_by' => $userId,
                'pinned_at' => date('Y-m-d H:i:s'),
            ]);
            $newState = true;
        }

        AuditLog::log($newState ? 'pin_message' : 'unpin_message', 'message', $messageId);

        $this->json(['success' => true, 'pinned' => $newState, 'message_id' => $messageId]);
    }

    /* ==================================================================
     *  Threads
     * ================================================================*/

    /**
     * GET  getThread
     * Params: message_id (the parent message)
     */
    public function getThread(): void
    {
        $this->requireAuth();

        $messageId = Sanitize::int($_GET['message_id'] ?? 0);

        if ($messageId <= 0) {
            $this->json(['success' => false, 'error' => 'ID inválido.'], 422);
            return;
        }

        $parent = Message::find($messageId);
        if (!$parent) {
            $this->json(['success' => false, 'error' => 'Mensagem não encontrada.'], 404);
            return;
        }

        // Verify the requester is a member of the channel
        if (!Channel::isMember((int) $parent['channel_id'], Session::userId())) {
            $this->json(['success' => false, 'error' => 'Acesso negado.'], 403);
            return;
        }

        $replies = Message::threadReplies($messageId);

        // Attach user data on the parent for consistency
        $parentUser = User::find((int) $parent['user_id']);
        $parent['user_name']   = $parentUser['name']   ?? '';
        $parent['user_avatar'] = $parentUser['avatar']  ?? '';

        $this->json([
            'success' => true,
            'parent'  => $parent,
            'replies' => $replies,
        ]);
    }

    /* ==================================================================
     *  File uploads
     * ================================================================*/

    /**
     * POST  uploadFile
     * Params: channel_id, file
     */
    public function uploadFile(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $userId    = Session::userId();
        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);

        if ($channelId <= 0 || !Channel::isMember($channelId, $userId)) {
            $this->json(['success' => false, 'error' => 'Acesso negado.'], 403);
            return;
        }

        $upload = Upload::handle('file', 'attachments');
        if (!$upload['success']) {
            $this->json(['success' => false, 'error' => $upload['error']], 422);
            return;
        }

        // Create a file-type message
        $messageId = Message::insert([
            'channel_id' => $channelId,
            'user_id'    => $userId,
            'content'    => $upload['original_name'],
            'type'       => 'file',
        ]);

        $attachmentId = Message::addAttachment($messageId, $userId, $upload);

        $message = Message::find($messageId);
        $user    = User::findWithPresence($userId);

        $message['user_name']   = $user['name']   ?? '';
        $message['user_avatar'] = $user['avatar']  ?? '';
        $message['user_status'] = $user['status']  ?? 'online';
        $message['attachment']  = $upload;

        AuditLog::log('upload_file', 'message', $messageId);

        $this->json(['success' => true, 'message' => $message, 'attachment_id' => $attachmentId]);
    }

    /* ==================================================================
     *  Search
     * ================================================================*/

    /**
     * GET  searchMessages
     * Params: query
     */
    public function searchMessages(): void
    {
        $this->requireAuth();

        $query = Sanitize::string($_GET['query'] ?? '');
        if (mb_strlen($query) < 2) {
            $this->json(['success' => true, 'messages' => []]);
            return;
        }

        $results = Message::search($query, Session::userId());

        $this->json(['success' => true, 'messages' => $results]);
    }

    /* ==================================================================
     *  Notifications
     * ================================================================*/

    /**
     * GET  getNotifications
     */
    public function getNotifications(): void
    {
        $this->requireAuth();

        $notifications = Notification::recent(Session::userId());

        $this->json(['success' => true, 'notifications' => $notifications]);
    }

    /**
     * POST  markNotificationRead
     * Params: notification_id
     */
    public function markNotificationRead(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $notificationId = Sanitize::int($_POST['notification_id'] ?? 0);

        if ($notificationId <= 0) {
            $this->json(['success' => false, 'error' => 'ID inválido.'], 422);
            return;
        }

        Notification::markRead($notificationId, Session::userId());

        $this->json(['success' => true]);
    }

    /* ==================================================================
     *  Presence / heartbeat
     * ================================================================*/

    /**
     * GET  heartbeat
     * Polled by the client every few seconds to maintain presence and
     * fetch lightweight counters.
     */
    public function heartbeat(): void
    {
        $this->requireAuth();

        $userId = Session::userId();
        User::updateLastSeen($userId);

        // Unread counts per channel
        $channels       = Channel::userChannels($userId);
        $unreadChannels = [];
        foreach ($channels as $ch) {
            $unreadChannels[] = [
                'channel_id'  => (int) $ch['id'],
                'unread_count' => (int) ($ch['unread_count'] ?? 0),
            ];
        }

        $notificationCount = Notification::unreadCount($userId);

        $this->json([
            'success'           => true,
            'unread_channels'   => $unreadChannels,
            'notification_count' => $notificationCount,
        ]);
    }

    /* ==================================================================
     *  User status
     * ================================================================*/

    /**
     * POST  userStatus
     * Params: status (online|away|dnd)
     */
    public function userStatus(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $status = Sanitize::string($_POST['status'] ?? '');
        $allowed = ['online', 'away', 'dnd'];

        if (!in_array($status, $allowed, true)) {
            $this->json(['success' => false, 'error' => 'Status inválido.'], 422);
            return;
        }

        User::updateStatus(Session::userId(), $status);

        $this->json(['success' => true, 'status' => $status]);
    }

    /* ==================================================================
     *  User search (for @mention autocomplete etc.)
     * ================================================================*/

    /**
     * GET  searchUsers
     * Params: query
     */
    public function searchUsers(): void
    {
        $this->requireAuth();

        $query = Sanitize::string($_GET['query'] ?? '');
        if (mb_strlen($query) < 1) {
            $this->json(['success' => true, 'users' => []]);
            return;
        }

        $users = User::search($query);

        $this->json(['success' => true, 'users' => $users]);
    }

    /* ==================================================================
     *  Mark channel read
     * ================================================================*/

    /**
     * POST  markChannelRead
     * Params: channel_id
     */
    public function markChannelRead(): void
    {
        $this->requireAuth();
        $this->requireCsrf();

        $userId    = Session::userId();
        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);

        if ($channelId <= 0 || !Channel::isMember($channelId, $userId)) {
            $this->json(['success' => false, 'error' => 'Acesso negado.'], 403);
            return;
        }

        // Find latest message id in the channel
        $stmt = $this->db->prepare(
            'SELECT MAX(id) FROM chat_messages WHERE channel_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$channelId]);
        $latestId = (int) $stmt->fetchColumn();

        if ($latestId > 0) {
            Channel::updateLastRead($channelId, $userId, $latestId);
        }

        $this->json(['success' => true, 'channel_id' => $channelId, 'last_read_message_id' => $latestId]);
    }

    /* ==================================================================
     *  Typing indicator (#5)
     * ================================================================*/

    public function typing(): void
    {
        $this->requireAuth();
        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);
        $typing    = Sanitize::int($_POST['typing'] ?? 0);
        $userId    = Session::userId();

        if ($channelId <= 0) { $this->json(['success' => true]); return; }

        $key = "typing_{$channelId}_{$userId}";
        $cacheFile = STORAGE_PATH . '/cache/' . md5($key) . '.typing';

        if ($typing) {
            file_put_contents($cacheFile, json_encode([
                'user_id' => $userId,
                'user_name' => Session::userName(),
                'expires' => time() + 5,
            ]));
        } else {
            @unlink($cacheFile);
        }

        $this->json(['success' => true]);
    }

    /* ==================================================================
     *  Link preview (#6)
     * ================================================================*/

    public function linkPreview(): void
    {
        $this->requireAuth();
        $url = Sanitize::string($_GET['url'] ?? '');

        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            $this->json(['title' => null]);
            return;
        }

        $cacheKey = md5($url);
        $cacheFile = STORAGE_PATH . '/cache/link_' . $cacheKey . '.json';

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $this->json(json_decode(file_get_contents($cacheFile), true));
            return;
        }

        $result = ['title' => null, 'description' => null, 'image' => null, 'domain' => parse_url($url, PHP_URL_HOST)];

        $ctx = stream_context_create([
            'http' => ['timeout' => 3, 'user_agent' => 'TeamChat/1.0', 'follow_location' => 1, 'max_redirects' => 3],
            'ssl'  => ['verify_peer' => false],
        ]);

        $html = @file_get_contents($url, false, $ctx, 0, 50000);
        if ($html) {
            if (preg_match('/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
                $result['title'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            } elseif (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
                $result['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            }
            if (preg_match('/<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
                $result['description'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
            if (preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
                $result['image'] = $m[1];
            }
        }

        if ($result['title']) {
            file_put_contents($cacheFile, json_encode($result));
        }

        $this->json($result);
    }

    /* ==================================================================
     *  Pinned messages panel (fixed)
     * ================================================================*/

    public function getPinnedMessages(): void
    {
        $this->requireAuth();
        $channelId = Sanitize::int($_GET['channel_id'] ?? 0);
        if ($channelId <= 0) { $this->json(['messages' => []]); return; }

        $messages = Message::pinnedMessages($channelId);
        $this->json(['success' => true, 'messages' => $messages]);
    }

    /* ==================================================================
     *  Custom emojis list (#29)
     * ================================================================*/

    public function getCustomEmojis(): void
    {
        $this->requireAuth();
        $stmt = $this->db->query('SELECT name, image_path FROM chat_custom_emojis ORDER BY name ASC');
        $emojis = $stmt->fetchAll();
        $this->json(['emojis' => $emojis]);
    }

    /* ==================================================================
     *  Private helpers
     * ================================================================*/

    /**
     * Enforce authentication — redirect is pointless for an API, so we
     * return a 401 JSON payload instead.
     */
    private function requireAuth(): void
    {
        if (!Session::isLoggedIn()) {
            $this->json(['success' => false, 'error' => 'Autenticação necessária.'], 401);
            exit;
        }
    }

    /**
     * Enforce CSRF token for state-changing requests.
     */
    private function requireCsrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !Csrf::checkAjax()) {
            $this->json(['success' => false, 'error' => 'Token CSRF inválido.'], 403);
            exit;
        }
    }

    /**
     * Send a JSON response.
     */
    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Parse @mentions out of message content, create mention records,
     * and notify each mentioned user.
     */
    private function processMentions(string $content, int $messageId, int $channelId, int $senderId): void
    {
        if (!preg_match_all('/@([a-zA-Z0-9_.\-]+)/', $content, $matches)) {
            return;
        }

        $senderName = Session::userName() ?? 'Alguém';
        $channel    = Channel::find($channelId);
        $channelName = $channel['name'] ?? 'canal';
        $mentioned  = [];

        foreach (array_unique($matches[1]) as $username) {
            // Resolve username by name match (case-insensitive)
            $stmt = $this->db->prepare(
                'SELECT id FROM users WHERE LOWER(REPLACE(name, " ", "")) = LOWER(?) AND active = 1 LIMIT 1'
            );
            $stmt->execute([str_replace('.', '', $username)]);
            $row = $stmt->fetch();

            if (!$row || (int) $row['id'] === $senderId) {
                continue;
            }

            $mentionedUserId = (int) $row['id'];

            // Avoid duplicate mention records for the same user/message
            if (in_array($mentionedUserId, $mentioned, true)) {
                continue;
            }
            $mentioned[] = $mentionedUserId;

            // Persist the mention record
            $this->db->prepare(
                'INSERT INTO chat_mentions (message_id, user_id, type, created_at) VALUES (?, ?, "user", NOW())'
            )->execute([$messageId, $mentionedUserId]);

            // Notify the mentioned user
            Notification::create(
                $mentionedUserId,
                'mention',
                "{$senderName} mencionou você em #{$channelName}",
                mb_substr($content, 0, 120),
                "index.php?m=chat&page=chat&channel_id={$channelId}#msg-{$messageId}"
            );
        }
    }
}
