<?php
/**
 * SearchController — Global search across messages, users, channels, and tasks.
 *
 * Every public method corresponds to a ?page=search&action=X route.
 */
class SearchController
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /* ------------------------------------------------------------------
     *  index  — Search page with results
     *  GET ?page=search[&query=X]
     * ----------------------------------------------------------------*/
    public function index(): void
    {
        Auth::requireLogin();

        $query   = Sanitize::get('query');
        $userId  = Session::userId();
        $results = [
            'messages' => [],
            'users'    => [],
            'channels' => [],
            'tasks'    => [],
        ];

        if ($query !== '') {
            // Messages the user has access to
            $results['messages'] = Message::search($query, $userId, 30);

            // Active users matching name or email
            $results['users'] = User::search($query);

            // Public channels matching the query
            $allPublic = Channel::publicChannels();
            $lower     = mb_strtolower($query);
            $results['channels'] = array_filter($allPublic, function (array $ch) use ($lower) {
                return str_contains(mb_strtolower($ch['name']), $lower)
                    || str_contains(mb_strtolower($ch['description'] ?? ''), $lower);
            });
            $results['channels'] = array_values($results['channels']);

            // Tasks matching title or description
            $like = '%' . $query . '%';
            $stmt = $this->db->prepare(
                'SELECT t.*, u.name AS creator_name
                 FROM tasks t
                 LEFT JOIN users u ON u.id = t.created_by
                 WHERE (t.title LIKE ? OR t.description LIKE ?) AND t.status != "cancelled"
                 ORDER BY t.created_at DESC
                 LIMIT 30'
            );
            $stmt->execute([$like, $like]);
            $results['tasks'] = $stmt->fetchAll();
        }

        View::render('search/index', [
            'pageTitle' => 'Pesquisar',
            'query'     => $query,
            'results'   => $results,
        ]);
    }

    /* ------------------------------------------------------------------
     *  api  — JSON endpoint for the global search modal (AJAX)
     *  GET ?page=search&action=api&query=X
     * ----------------------------------------------------------------*/
    public function api(): void
    {
        Auth::requireLogin();

        header('Content-Type: application/json; charset=utf-8');

        $query  = Sanitize::get('query');
        $userId = Session::userId();

        if ($query === '' || mb_strlen($query) < 2) {
            echo json_encode(['success' => true, 'results' => []]);
            return;
        }

        $results = [];

        // Messages
        $messages = Message::search($query, $userId, 10);
        foreach ($messages as $m) {
            $results[] = [
                'type'    => 'message',
                'id'      => (int) $m['id'],
                'title'   => mb_strimwidth(strip_tags($m['content']), 0, 80, '...'),
                'context' => ($m['channel_name'] ?? '') . ' - ' . ($m['user_name'] ?? ''),
                'link'    => 'index.php?page=chat&channel_id=' . $m['channel_id'],
            ];
        }

        // Users
        $users = User::search($query);
        foreach ($users as $u) {
            $results[] = [
                'type'    => 'user',
                'id'      => (int) $u['id'],
                'title'   => $u['name'],
                'context' => $u['email'] . ($u['title'] ? ' - ' . $u['title'] : ''),
                'link'    => 'index.php?page=profile&id=' . $u['id'],
            ];
        }

        // Channels
        $allPublic = Channel::publicChannels();
        $lower     = mb_strtolower($query);
        foreach ($allPublic as $ch) {
            if (str_contains(mb_strtolower($ch['name']), $lower)
                || str_contains(mb_strtolower($ch['description'] ?? ''), $lower)) {
                $results[] = [
                    'type'    => 'channel',
                    'id'      => (int) $ch['id'],
                    'title'   => '#' . $ch['name'],
                    'context' => $ch['description'] ?? '',
                    'link'    => 'index.php?page=chat&channel_id=' . $ch['id'],
                ];
            }
        }

        // Tasks
        $like = '%' . $query . '%';
        $stmt = $this->db->prepare(
            'SELECT t.id, t.title, t.status, t.priority
             FROM tasks t
             WHERE (t.title LIKE ? OR t.description LIKE ?) AND t.status != "cancelled"
             ORDER BY t.created_at DESC
             LIMIT 10'
        );
        $stmt->execute([$like, $like]);
        foreach ($stmt->fetchAll() as $t) {
            $results[] = [
                'type'    => 'task',
                'id'      => (int) $t['id'],
                'title'   => $t['title'],
                'context' => ucfirst(str_replace('_', ' ', $t['status'])) . ' - ' . ucfirst($t['priority']),
                'link'    => 'index.php?page=tasks&action=show&id=' . $t['id'],
            ];
        }

        echo json_encode([
            'success' => true,
            'results' => $results,
        ]);
    }
}
