<?php
/**
 * Controller de Notificações (Módulo 9)
 */
class NotificationController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        Auth::requireLogin();

        $userId = Session::userId();
        $currentPage = max(1, Sanitize::int($_GET['p'] ?? 1));

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
        $countStmt->execute([$userId]);
        $total = (int)$countStmt->fetchColumn();

        $pagination = new Pagination($total, $currentPage, 20);

        $stmt = $this->db->prepare(
            "SELECT * FROM notifications WHERE user_id = ?
             ORDER BY is_read ASC, created_at DESC
             LIMIT {$pagination->perPage} OFFSET {$pagination->offset}"
        );
        $stmt->execute([$userId]);
        $notifications = $stmt->fetchAll();

        $pageTitle = 'Notificações';
        $page = 'notifications';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/notifications/index.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function read(): void
    {
        Auth::requireLogin();

        $id = Sanitize::int($_GET['id'] ?? 0);
        $userId = Session::userId();

        // Marcar como lida
        $stmt = $this->db->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);

        // Redirecionar para link, se houver
        $stmt = $this->db->prepare('SELECT link FROM notifications WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $notification = $stmt->fetch();

        if ($notification && $notification['link']) {
            header('Location: ' . $notification['link']);
        } else {
            header('Location: index.php?page=notifications');
        }
        exit;
    }

    public function mark_all_read(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $userId = Session::userId();
        $this->db->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0')
                 ->execute([$userId]);

        Session::flash('success', 'Todas as notificações marcadas como lidas.');
        header('Location: index.php?page=notifications');
        exit;
    }

    public function delete(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $userId = Session::userId();

        $this->db->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?')->execute([$id, $userId]);

        Session::flash('success', 'Notificação excluída.');
        header('Location: index.php?page=notifications');
        exit;
    }

    /**
     * API: Contar notificações não lidas (para badge no header)
     */
    public function count_unread(): void
    {
        Auth::requireLogin();

        $userId = Session::userId();
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        $count = (int)$stmt->fetchColumn();

        header('Content-Type: application/json');
        echo json_encode(['count' => $count]);
        exit;
    }

    /**
     * Criar notificação (método estático para uso em outros controllers)
     */
    public static function create(int $userId, string $title, string $message, string $type = 'info', string $link = ''): void
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $title, $message, $type, $link]);
    }

    /**
     * Notificar todos os admins
     */
    public static function notifyAdmins(string $title, string $message, string $type = 'warning', string $link = ''): void
    {
        $db = Database::getInstance();
        $admins = $db->query("SELECT id FROM users WHERE role = 'admin' AND active = 1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            self::create($adminId, $title, $message, $type, $link);
        }
    }
}
