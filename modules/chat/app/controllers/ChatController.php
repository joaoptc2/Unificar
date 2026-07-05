<?php
/**
 * ChatController — Main chat interface (Slack-like SPA layout).
 *
 * Renders the full-screen messaging UI, channel sidebar, and message
 * pane.  Every public method corresponds to a ?page=chat&action=X route.
 */
class ChatController
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /* ------------------------------------------------------------------
     *  index  — Default chat view
     *  GET ?page=chat
     *  Optional: &channel_id=N to open a specific channel
     * ----------------------------------------------------------------*/
    public function index(): void
    {
        Auth::requireLogin();

        $userId  = Session::userId();
        $user    = Auth::user();

        // Sidebar data
        $channels        = Channel::userChannels($userId);
        $teams           = Team::userTeams($userId);
        $unreadCount     = Notification::unreadCount($userId);

        // Determine which channel to display
        $currentChannel = null;
        $messages       = [];
        $members        = [];

        $requestedId = isset($_GET['channel_id']) ? Sanitize::int($_GET['channel_id']) : 0;

        if ($requestedId > 0) {
            $currentChannel = Channel::find($requestedId);

            // Fall back to first channel when the requested one is
            // missing or the user is not a member.
            if (!$currentChannel || !Channel::isMember($requestedId, $userId)) {
                $currentChannel = null;
            }
        }

        // If no channel was explicitly chosen, open the first one.
        if (!$currentChannel && !empty($channels)) {
            $currentChannel = Channel::find((int) $channels[0]['id']);
        }

        if ($currentChannel) {
            $messages = Message::channelMessages((int) $currentChannel['id']);
            $members  = Channel::members((int) $currentChannel['id']);

            // Mark channel as read up to the latest message
            if (!empty($messages)) {
                $lastMsg = end($messages);
                Channel::updateLastRead(
                    (int) $currentChannel['id'],
                    $userId,
                    (int) $lastMsg['id']
                );
            }

            // Update user presence
            User::updateLastSeen($userId);
        }

        View::renderChat('chat/index', [
            'user'           => $user,
            'channels'       => $channels,
            'teams'          => $teams,
            'currentChannel' => $currentChannel,
            'messages'       => $messages,
            'members'        => $members,
            'unreadCount'    => $unreadCount,
            'csrfToken'      => Csrf::token(),
        ]);
    }

    /* ------------------------------------------------------------------
     *  channel  — Load a specific channel
     *  GET ?page=chat&action=channel&id=N
     *  If ?ajax=1 is present, return JSON instead of full HTML.
     * ----------------------------------------------------------------*/
    public function channel(): void
    {
        Auth::requireLogin();

        $userId    = Session::userId();
        $channelId = isset($_GET['id']) ? Sanitize::int($_GET['id']) : 0;

        if ($channelId <= 0) {
            $this->errorResponse(400, 'ID de canal inválido.');
            return;
        }

        $channel = Channel::find($channelId);
        if (!$channel) {
            $this->errorResponse(404, 'Canal não encontrado.');
            return;
        }

        if (!Channel::isMember($channelId, $userId)) {
            $this->errorResponse(403, 'Você não é membro deste canal.');
            return;
        }

        $messages       = Message::channelMessages($channelId);
        $members        = Channel::members($channelId);
        $pinnedCount    = count(Message::pinnedMessages($channelId));

        // Mark as read
        if (!empty($messages)) {
            $lastMsg = end($messages);
            Channel::updateLastRead($channelId, $userId, (int) $lastMsg['id']);
        }

        User::updateLastSeen($userId);

        // ---- AJAX: return JSON ----------------------------------------
        if (!empty($_GET['ajax'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'     => true,
                'channel'     => $channel,
                'messages'    => $messages,
                'members'     => $members,
                'pinnedCount' => $pinnedCount,
            ]);
            return;
        }

        // ---- Normal: full-screen layout --------------------------------
        $channels    = Channel::userChannels($userId);
        $teams       = Team::userTeams($userId);
        $unreadCount = Notification::unreadCount($userId);

        View::renderChat('chat/index', [
            'user'           => Auth::user(),
            'channels'       => $channels,
            'teams'          => $teams,
            'currentChannel' => $channel,
            'messages'       => $messages,
            'members'        => $members,
            'pinnedCount'    => $pinnedCount,
            'unreadCount'    => $unreadCount,
            'csrfToken'      => Csrf::token(),
        ]);
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    /**
     * Send an error — JSON when the request is AJAX, plain text otherwise.
     */
    private function errorResponse(int $code, string $message): void
    {
        http_response_code($code);

        if (!empty($_GET['ajax']) || $this->isAjax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $message]);
        } else {
            echo Sanitize::e($message);
        }
    }

    /**
     * Detect XMLHttpRequest.
     */
    private function isAjax(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }
}
