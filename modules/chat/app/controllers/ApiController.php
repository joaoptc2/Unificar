<?php
/**
 * ApiController — endpoints JSON do chat em tempo real (polling).
 *
 * Cada método público é acessível por ?page=api&action=<método>.
 * Toda resposta é application/json. Métodos que alteram estado exigem
 * POST + token CSRF (_csrf_token no corpo ou header X-CSRF-TOKEN).
 *
 * Endpoints: sendMessage, getMessages, getOlderMessages, editMessage, deleteMessage,
 * toggleReaction, pinMessage, getThread, getPinnedMessages, heartbeat,
 * userStatus, searchUsers, markChannelRead, typing, downloadAttachment,
 * getCustomEmojis, toggleFavorite.
 *
 * Regra geral: nenhuma ação (ler, enviar, editar, excluir, reagir, fixar,
 * baixar anexo) é permitida em canal do qual o usuário não participa —
 * nem para quem tem chat.moderate, exceto em canais públicos.
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

    /** GET getOlderMessages — channel_id, before_id (paginação para trás) */
    public function getOlderMessages(): void
    {
        $this->requireAuth();
        $this->requirePerm('chat.view');

        $userId    = Session::userId();
        $channelId = Sanitize::int($_GET['channel_id'] ?? 0);
        $beforeId  = Sanitize::int($_GET['before_id']  ?? 0);

        if ($channelId <= 0 || $beforeId <= 0 || !Channel::isMember($channelId, $userId)) {
            $this->json(['success' => false, 'error' => 'Acesso negado.'], 403);
            return;
        }

        $limit    = (int) ((require CHAT_PATH . '/config/app.php')['pagination']['messages'] ?? 50);
        $messages = Message::channelMessages($channelId, $limit, $beforeId);

        $this->json([
            'success'  => true,
            'messages' => $messages,
            'has_more' => count($messages) >= $limit,
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
        // Deixou o canal? Não edita mais o histórico dele.
        if (!Channel::isMember((int) $message['channel_id'], $userId)) {
            $this->json(['success' => false, 'error' => 'Você não é membro deste canal.'], 403);
            return;
        }

        // NOW() do banco: mesmo relógio de created_at (evita divergência de fuso)
        $this->db->prepare('UPDATE chat_messages SET content = ?, is_edited = 1, edited_at = NOW() WHERE id = ?')
            ->execute([$content, $messageId]);

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

        $isOwner   = (int) $message['user_id'] === $userId;
        $channelId = (int) $message['channel_id'];
        $isMember  = Channel::isMember($channelId, $userId);

        if (core_can('chat.moderate') && $this->canModerateChannel($channelId, $isMember)) {
            // Moderador exclui qualquer mensagem do canal, sem janela de tempo.
        } elseif ($isOwner && $isMember && core_can('chat.delete')) {
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

        // Só um emoji de verdade (poucos code points, sem letras/dígitos) ou
        // um emoji personalizado :nome: — senão a reação vira texto livre.
        $isCustom = (bool) preg_match('/^:[a-z0-9_]{1,48}:$/', $emoji);
        $isEmoji  = $emoji !== '' && mb_strlen($emoji) <= 12
                 && !preg_match('/[<>"\'\s A-Za-z0-9]/u', $emoji);

        if ($messageId <= 0 || (!$isCustom && !$isEmoji)) {
            $this->json(['success' => false, 'error' => 'Emoji inválido.'], 422);
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
            $this->db->prepare('UPDATE chat_messages SET is_pinned = 1, pinned_by = ?, pinned_at = NOW() WHERE id = ?')
                ->execute([$userId, $messageId]);
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
     *  Download de anexos (somente membros do canal)
     * ================================================================*/

    /**
     * GET downloadAttachment — id
     * Entrega o arquivo apenas a quem participa do canal da mensagem.
     * Os arquivos ficam fora do alcance direto do servidor web
     * (uploads/chat/attachments/.htaccess), então este é o único caminho.
     */
    public function downloadAttachment(): void
    {
        $this->requireAuth();
        $this->requirePerm('chat.view');

        $id  = Sanitize::int($_GET['id'] ?? 0);
        $att = $id > 0 ? Message::attachmentWithChannel($id) : null;

        if (!$att || $att['message_deleted_at'] !== null) {
            $this->json(['success' => false, 'error' => 'Anexo não encontrado.'], 404);
            return;
        }
        if (!Channel::isMember((int) $att['channel_id'], Session::userId())) {
            $this->json(['success' => false, 'error' => 'Acesso negado.'], 403);
            return;
        }

        $path = ltrim((string) $att['file_path'], '/');
        if (!str_starts_with($path, 'uploads/chat/') || str_contains($path, '..')) {
            $this->json(['success' => false, 'error' => 'Anexo inválido.'], 400);
            return;
        }
        $full = BASE_PATH . '/' . $path;
        if (!is_file($full)) {
            $this->json(['success' => false, 'error' => 'Arquivo indisponível.'], 404);
            return;
        }

        // Só tipos da lista branca; imagens abrem no navegador, o resto baixa.
        $cfg     = (require CHAT_PATH . '/config/app.php')['upload'];
        $mime    = (string) $att['file_type'];
        $known   = isset($cfg['allowed_files'][$mime]);
        $isImage = $known && Upload::isImage($mime);
        $name    = preg_replace('/[\r\n"\\\\]+/', '_', (string) $att['original_name']) ?: 'anexo';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . ($known ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . filesize($full));
        header('Content-Disposition: ' . ($isImage ? 'inline' : 'attachment')
            . '; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode((string) $att['original_name']));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; style-src \'unsafe-inline\'');
        header('Cache-Control: private, max-age=600');
        readfile($full);
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

    /**
     * Moderação em um canal: só vale onde o moderador participa ou, no
     * máximo, em canais públicos (aos quais ele pode entrar de qualquer
     * forma). Canais privados e mensagens diretas ficam fora do alcance
     * de quem não é membro.
     */
    private function canModerateChannel(int $channelId, bool $isMember): bool
    {
        if ($isMember) {
            return true;
        }
        $channel = Channel::find($channelId);
        return $channel !== null && $channel['type'] === 'public';
    }

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
        // Envio maior que post_max_size: o PHP descarta $_POST/$_FILES e o
        // erro sairia como "CSRF inválido" — mensagem clara no lugar.
        if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $this->json([
                'success' => false,
                'error'   => 'Envio maior que o limite do servidor (' . ini_get('post_max_size') . '). Reduza o arquivo.',
            ], 413);
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
