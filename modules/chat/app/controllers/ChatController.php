<?php
/**
 * ChatController — tela principal do chat.
 *
 * Renderiza, dentro do layout padrão da plataforma (topbar + sidebar do
 * núcleo), o painel de canais/DMs (esquerda), as mensagens do canal atual
 * e o painel lateral (threads/membros/fixados).
 * Rotas: ?page=chat (index) e ?page=chat&action=channel&id=N.
 */
class ChatController
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /* ------------------------------------------------------------------
     *  index — GET ?page=chat[&channel_id=N]
     * ----------------------------------------------------------------*/
    public function index(): void
    {
        Auth::requireLogin();
        core_require('chat.view');

        $userId = Session::userId();
        Channel::ensureGeneralMembership($userId);

        $channels    = Channel::userChannels($userId);
        $requestedId = isset($_GET['channel_id']) ? Sanitize::int($_GET['channel_id']) : 0;

        $currentChannel = null;
        if ($requestedId > 0) {
            foreach ($channels as $ch) {
                if ((int) $ch['id'] === $requestedId) {
                    $currentChannel = $ch;
                    break;
                }
            }
            if ($currentChannel === null) {
                Session::flash('warning', 'Canal não encontrado ou você não participa dele.');
            }
        }

        // Sem canal escolhido: abre o primeiro (geral/mais recente)
        if ($currentChannel === null && !empty($channels)) {
            $currentChannel = $channels[0];
        }

        $this->renderChat($channels, $currentChannel);
    }

    /* ------------------------------------------------------------------
     *  channel — GET ?page=chat&action=channel&id=N[&ajax=1]
     * ----------------------------------------------------------------*/
    public function channel(): void
    {
        Auth::requireLogin();
        if (!core_can('chat.view')) {
            $this->errorResponse(403, 'Você não tem permissão para visualizar o chat.');
            return;
        }

        $userId    = Session::userId();
        $channelId = isset($_GET['id']) ? Sanitize::int($_GET['id']) : 0;

        if ($channelId <= 0) {
            $this->errorResponse(400, 'ID de canal inválido.');
            return;
        }

        $channel = Channel::find($channelId);
        if (!$channel || (int) $channel['is_archived'] === 1) {
            $this->errorResponse(404, 'Canal não encontrado.');
            return;
        }

        if (!Channel::isMember($channelId, $userId)) {
            // Canal público: entra automaticamente ao abrir
            if ($channel['type'] === 'public' && core_can('channels.view')) {
                Channel::addMember($channelId, $userId);
                Channel::systemMessage($channelId, (Session::userName() ?? 'Usuário') . ' entrou no canal.');
            } else {
                $this->errorResponse(403, 'Você não é membro deste canal.');
                return;
            }
        }

        if (!empty($_GET['ajax'])) {
            $messages = Message::channelMessages($channelId);
            $this->markRead($channelId, $userId, $messages);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'     => true,
                'channel'     => $channel,
                'messages'    => $messages,
                'members'     => Channel::members($channelId),
                'pinnedCount' => Message::pinnedCount($channelId),
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $channels = Channel::userChannels($userId);
        $current  = null;
        foreach ($channels as $ch) {
            if ((int) $ch['id'] === $channelId) {
                $current = $ch;
                break;
            }
        }
        $this->renderChat($channels, $current ?? $channel);
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    private function renderChat(array $channels, ?array $currentChannel): void
    {
        $userId   = Session::userId();
        $messages = [];
        $members  = [];
        $pinned   = 0;
        $partner  = null;

        if ($currentChannel) {
            $channelId = (int) $currentChannel['id'];
            $messages  = Message::channelMessages($channelId);
            $members   = Channel::members($channelId);
            $pinned    = Message::pinnedCount($channelId);
            $this->markRead($channelId, $userId, $messages);
            if ($currentChannel['type'] === 'direct') {
                $partner = isset($currentChannel['partner_id']) && $currentChannel['partner_id']
                    ? [
                        'id'     => (int) $currentChannel['partner_id'],
                        'name'   => $currentChannel['partner_name'] ?? 'Usuário',
                        'status' => $currentChannel['partner_status'] ?? 'offline',
                        'title'  => $currentChannel['partner_title'] ?? '',
                        'avatar' => $currentChannel['partner_avatar'] ?? null,
                    ]
                    : Channel::dmPartner($channelId, $userId);
            }
            if (!isset($currentChannel['is_favorite'])) {
                $currentChannel['is_favorite'] = Channel::isFavorite($channelId, $userId) ? 1 : 0;
            }
            if (!isset($currentChannel['member_role'])) {
                $currentChannel['member_role'] = Channel::memberRole($channelId, $userId);
            }
        }

        // Pode gerir o canal atual? (micropermissão, criador ou owner/admin do canal)
        $canManage = false;
        if ($currentChannel && $currentChannel['type'] !== 'direct') {
            $canManage = core_can('channels.edit')
                || (int) ($currentChannel['created_by'] ?? 0) === $userId
                || in_array($currentChannel['member_role'] ?? '', ['owner', 'admin'], true);
        }

        User::updateLastSeen($userId);

        $title = $currentChannel
            ? ($currentChannel['type'] === 'direct' ? ($partner['name'] ?? 'Mensagem direta') : '#' . $currentChannel['name'])
            : 'Chat';

        View::renderChat('chat/index', [
            'pageTitle'      => $title,
            'user'           => Auth::user(),
            'channels'       => $channels,
            'currentChannel' => $currentChannel,
            'partner'        => $partner,
            'messages'       => $messages,
            'members'        => $members,
            'memberCount'    => count($members),
            'pinnedCount'    => $pinned,
            'canManage'      => $canManage,
            'customEmojis'   => $this->customEmojis(),
            'csrfToken'      => Csrf::token(),
        ]);
    }

    /** Emojis personalizados (seletor e renderização de :nome:). */
    private function customEmojis(): array
    {
        $rows = $this->db->query('SELECT name, image_path FROM chat_custom_emojis ORDER BY name ASC')->fetchAll();
        return array_map(static fn (array $e) => ['name' => $e['name'], 'url' => Upload::url($e['image_path'])], $rows);
    }

    private function markRead(int $channelId, int $userId, array $messages): void
    {
        if (!empty($messages)) {
            $last = end($messages);
            Channel::updateLastRead($channelId, $userId, (int) $last['id']);
        }
    }

    /** Erro — JSON quando a requisição é AJAX, página de erro caso contrário. */
    private function errorResponse(int $code, string $message): void
    {
        if (!empty($_GET['ajax']) || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
            return;
        }
        \Core\Layout::renderError($code, $message);
    }
}
