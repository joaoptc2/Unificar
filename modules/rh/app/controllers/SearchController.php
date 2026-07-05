<?php
/**
 * SearchController — busca global (Ctrl+K).
 *
 * Endpoint JSON unificado que pesquisa em funcionários, vencimentos,
 * compromissos e candidatos, respeitando as permissões do usuário.
 */
class SearchController
{
    private PDO $db;
    private const MAX_PER_GROUP = 5;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        $this->q();
    }

    public function q(): void
    {
        Auth::requireLogin();

        $term = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($term) < 2) {
            $this->json(['groups' => []]);
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
        $groups = [];

        if (Auth::can('employees', 'view')) {
            $stmt = $this->db->prepare(
                'SELECT id, full_name, cpf, email FROM employees
                 WHERE full_name LIKE ? OR cpf LIKE ? OR email LIKE ?
                 ORDER BY full_name LIMIT ' . self::MAX_PER_GROUP
            );
            $stmt->execute([$like, $like, $like]);
            $rows = $stmt->fetchAll();
            if ($rows) {
                $groups[] = [
                    'label' => 'Funcionários',
                    'icon'  => 'bi-people',
                    'items' => array_map(fn($r) => [
                        'title'    => $r['full_name'],
                        'subtitle' => Sanitize::formatCpf($r['cpf']) . ($r['email'] ? ' · ' . $r['email'] : ''),
                        'url'      => 'index.php?page=employees&action=show&id=' . (int)$r['id'],
                    ], $rows),
                ];
            }
        }

        if (Auth::can('expirations', 'view')) {
            $stmt = $this->db->prepare(
                'SELECT ex.id, ex.title, ex.expiry_date, e.full_name AS employee_name
                 FROM expirations ex JOIN employees e ON ex.employee_id = e.id
                 WHERE ex.title LIKE ? OR e.full_name LIKE ?
                 ORDER BY ex.expiry_date LIMIT ' . self::MAX_PER_GROUP
            );
            $stmt->execute([$like, $like]);
            $rows = $stmt->fetchAll();
            if ($rows) {
                $groups[] = [
                    'label' => 'Vencimentos',
                    'icon'  => 'bi-clock-history',
                    'items' => array_map(fn($r) => [
                        'title'    => $r['title'],
                        'subtitle' => $r['employee_name'] . ' · vence em ' . Sanitize::formatDate($r['expiry_date']),
                        'url'      => 'index.php?page=expirations&action=edit&id=' . (int)$r['id'],
                    ], $rows),
                ];
            }
        }

        if (Auth::can('schedules', 'view')) {
            $stmt = $this->db->prepare(
                'SELECT s.id, s.title, s.event_date, s.event_time, e.full_name AS employee_name
                 FROM schedules s LEFT JOIN employees e ON s.employee_id = e.id
                 WHERE s.title LIKE ? OR s.description LIKE ?
                 ORDER BY s.event_date DESC LIMIT ' . self::MAX_PER_GROUP
            );
            $stmt->execute([$like, $like]);
            $rows = $stmt->fetchAll();
            if ($rows) {
                $groups[] = [
                    'label' => 'Agenda',
                    'icon'  => 'bi-calendar3',
                    'items' => array_map(fn($r) => [
                        'title'    => $r['title'],
                        'subtitle' => Sanitize::formatDate($r['event_date'])
                                      . ($r['event_time'] ? ' ' . substr($r['event_time'], 0, 5) : '')
                                      . ($r['employee_name'] ? ' · ' . $r['employee_name'] : ''),
                        'url'      => 'index.php?page=schedules&action=edit&id=' . (int)$r['id'],
                    ], $rows),
                ];
            }
        }

        if (Auth::can('recruitment', 'view') || Auth::can('talent_pool', 'view')) {
            $stmt = $this->db->prepare(
                'SELECT c.id, c.full_name, c.email, c.status, rj.title AS job_title
                 FROM candidates c LEFT JOIN recruitment_jobs rj ON c.job_id = rj.id
                 WHERE c.full_name LIKE ? OR c.email LIKE ?
                 ORDER BY c.created_at DESC LIMIT ' . self::MAX_PER_GROUP
            );
            $stmt->execute([$like, $like]);
            $rows = $stmt->fetchAll();
            if ($rows) {
                $groups[] = [
                    'label' => 'Candidatos',
                    'icon'  => 'bi-person-badge',
                    'items' => array_map(fn($r) => [
                        'title'    => $r['full_name'],
                        'subtitle' => ($r['job_title'] ? $r['job_title'] . ' · ' : '') . ucfirst(str_replace('_', ' ', $r['status'])),
                        'url'      => 'index.php?page=talent_pool&action=show&id=' . (int)$r['id'],
                    ], $rows),
                ];
            }
        }

        $this->json(['groups' => $groups, 'query' => $term]);
    }

    private function json(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
