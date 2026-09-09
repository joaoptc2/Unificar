<?php
/**
 * SearchController — busca de mensagens (nos canais em que o usuário
 * participa), pessoas e canais públicos.
 *
 * Rotas: ?page=search[&query=X] (página) e ?page=search&action=api (JSON).
 */
class SearchController
{
    public function index(): void
    {
        Auth::requireLogin();
        core_require('search.view');

        $query   = mb_substr(Sanitize::get('query'), 0, 200);
        $userId  = Session::userId();
        $results = ['messages' => [], 'users' => [], 'channels' => []];

        if ($query !== '') {
            $results['messages'] = Message::search($query, $userId, 50);
            $results['users']    = User::search($query);
            $results['channels'] = $this->matchChannels($query);
        }

        View::render('search/index', [
            'pageTitle' => 'Buscar mensagens',
            'query'     => $query,
            'results'   => $results,
        ]);
    }

    /** GET ?page=search&action=api&query=X — JSON para buscas rápidas. */
    public function api(): void
    {
        Auth::requireLogin();
        header('Content-Type: application/json; charset=utf-8');

        if (!core_can('search.view')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Sem permissão para buscar.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $query  = mb_substr(Sanitize::get('query'), 0, 200);
        $userId = Session::userId();

        if ($query === '' || mb_strlen($query) < 2) {
            echo json_encode(['success' => true, 'results' => []]);
            return;
        }

        $results = [];

        foreach (Message::search($query, $userId, 10) as $m) {
            $results[] = [
                'type'    => 'message',
                'id'      => (int) $m['id'],
                'title'   => mb_strimwidth(strip_tags((string) $m['content']), 0, 80, '...'),
                'context' => ($m['channel_type'] === 'direct' ? 'Mensagem direta' : '#' . ($m['channel_name'] ?? '')) . ' — ' . ($m['user_name'] ?? ''),
                'link'    => 'index.php?m=chat&page=chat&channel_id=' . (int) $m['channel_id'] . '#msg-' . (int) $m['id'],
            ];
        }

        foreach (User::search($query) as $u) {
            if ((int) $u['id'] === $userId) {
                continue;
            }
            $results[] = [
                'type'    => 'user',
                'id'      => (int) $u['id'],
                'title'   => $u['name'],
                'context' => $u['email'] . ($u['title'] ? ' — ' . $u['title'] : ''),
                'link'    => 'index.php?m=chat&page=channels&action=direct&user_id=' . (int) $u['id'],
            ];
        }

        foreach ($this->matchChannels($query) as $ch) {
            $results[] = [
                'type'    => 'channel',
                'id'      => (int) $ch['id'],
                'title'   => '#' . $ch['name'],
                'context' => $ch['description'] ?? '',
                'link'    => 'index.php?m=chat&page=chat&action=channel&id=' . (int) $ch['id'],
            ];
        }

        echo json_encode(['success' => true, 'results' => $results], JSON_UNESCAPED_UNICODE);
    }

    private function matchChannels(string $query): array
    {
        $lower = mb_strtolower($query);
        $out   = [];
        foreach (Channel::publicChannels() as $ch) {
            if (str_contains(mb_strtolower((string) $ch['name']), $lower)
                || str_contains(mb_strtolower((string) ($ch['description'] ?? '')), $lower)) {
                $out[] = $ch;
            }
        }
        return $out;
    }
}
