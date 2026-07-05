<?php
/**
 * Controller de Notificações — adaptado à tabela GLOBAL `notifications`
 * da plataforma (module = 'rh', leitura marcada em read_at).
 *
 * A interface de leitura (listagem/sino) agora é do núcleo — a página do
 * módulo redireciona para ?m=auth&a=notifications. Ficam aqui apenas os
 * helpers de criação usados pelos outros controllers e pelos crons.
 */
class NotificationController
{
    public function index(): void
    {
        Auth::requireLogin();
        core_redirect('index.php?m=auth&a=notifications');
    }

    /**
     * Compat: marca como lida e segue o link (o núcleo também faz isso).
     */
    public function read(): void
    {
        Auth::requireLogin();

        $id = Sanitize::int($_GET['id'] ?? 0);
        $userId = (int)Session::userId();

        Core\Notifications::markRead($userId, $id ?: null);

        $row = Core\DB::queryOne('SELECT link FROM notifications WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row && $row['link']) {
            header('Location: ' . $row['link']);
        } else {
            core_redirect('index.php?m=auth&a=notifications');
        }
        exit;
    }

    public function mark_all_read(): void
    {
        Auth::requireLogin();
        Csrf::check();

        Core\Notifications::markRead((int)Session::userId());
        core_redirect('index.php?m=auth&a=notifications');
    }

    /**
     * API: Contar notificações não lidas do módulo (badge legado).
     */
    public function count_unread(): void
    {
        Auth::requireLogin();

        $count = Core\Notifications::unreadCount((int)Session::userId(), 'rh');

        header('Content-Type: application/json');
        echo json_encode(['count' => $count]);
        exit;
    }

    /**
     * Criar notificação (método estático para uso em outros controllers).
     * Grava na tabela global com module='rh'.
     */
    public static function create(int $userId, string $title, string $message, string $type = 'info', string $link = ''): void
    {
        Core\Notifications::add($userId, $title, $message, $link !== '' ? $link : null, $type, 'rh');
    }

    /**
     * Notificar todos os administradores do módulo RH (RBAC central) e os
     * administradores globais da plataforma.
     */
    public static function notifyAdmins(string $title, string $message, string $type = 'warning', string $link = ''): void
    {
        $admins = Core\DB::query(
            "SELECT DISTINCT u.id
             FROM users u
             LEFT JOIN user_module_access uma
               ON uma.user_id = u.id AND uma.module_slug = 'rh'
             WHERE u.active = 1 AND (u.is_admin = 1 OR uma.role = 'admin')"
        );
        foreach ($admins as $admin) {
            self::create((int)$admin['id'], $title, $message, $type, $link);
        }
    }
}
