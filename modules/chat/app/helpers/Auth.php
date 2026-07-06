<?php
/**
 * Adaptador de autenticação — delega ao núcleo (Core\Auth / Core\Perms).
 *
 * - Login/logout/registro saem do módulo (telas do núcleo em ?m=auth).
 * - A antiga matriz de papéis (admin/manager/member) foi REMOVIDA: o
 *   acesso agora é por MICROPERMISSÕES do núcleo (core_can/core_require,
 *   alimentados por $GLOBALS['MODULE_PERMS']). Auth::can() e
 *   Auth::requirePermission() são wrappers finos que traduzem o par
 *   legado (module, action) para a chave nova "<recurso>.<ação>".
 * - user() devolve a linha global de `users` ENRIQUECIDA com a presença
 *   do chat (chat_presence) e aliases legados (title, department, status).
 */
class Auth
{
    private static ?array $cachedUser = null;

    /**
     * Mapa de conversão (module, action) legado → micropermissão nova.
     * Pares ausentes caem no padrão "<module>.<action>" (que já coincide
     * com o catálogo do manifesto para chat/channels/tasks/meetings/
     * teams/processes/polls/search/categories/emojis/admin).
     *
     * Mapeamentos não triviais (documentados):
     *  - channels.archive   → channels.delete  (arquivar = "excluir" no catálogo);
     *  - tasks.assign       → tasks.edit       (atribuir faz parte da edição);
     *  - meetings.calendar  → calendar.view    (calendário é recurso próprio);
     *  - polls.close        → polls.edit       (encerrar enquete);
     *  - members.view/manage→ admin.view       (a gestão de usuários saiu do
     *    módulo — resta apenas o painel administrativo do módulo);
     *  - profile.view/edit  → chat.view        (perfil é do núcleo, ?m=auth;
     *    qualquer usuário do módulo enxerga o próprio perfil).
     */
    private const PERM_MAP = [
        'channels' => ['archive' => 'channels.delete'],
        'tasks'    => ['assign' => 'tasks.edit'],
        'meetings' => ['calendar' => 'calendar.view'],
        'polls'    => ['close' => 'polls.edit'],
        'members'  => ['view' => 'admin.view', 'manage' => 'admin.view'],
        'profile'  => ['view' => 'chat.view', 'edit' => 'chat.view'],
    ];

    public static function requireLogin(): void
    {
        if (!\Core\Auth::check()) {
            core_redirect('index.php?m=auth&a=login');
        }
    }

    /** Interrompe com 403 quando falta a micropermissão equivalente. */
    public static function requirePermission(string $module, string $action): void
    {
        core_require(self::permKey($module, $action));
    }

    /** Wrapper fino sobre core_can() para call sites legados. */
    public static function can(string $module, string $action): bool
    {
        return core_can(self::permKey($module, $action));
    }

    private static function permKey(string $module, string $action): string
    {
        return self::PERM_MAP[$module][$action] ?? ($module . '.' . $action);
    }

    /**
     * Usuário logado (linha global `users`) + presença do chat.
     * Mantém as chaves que o legado espera: status, status_text,
     * status_emoji, timezone, last_seen_at, title, department.
     */
    public static function user(): ?array
    {
        if (!\Core\Auth::check()) return null;

        if (self::$cachedUser === null) {
            $db   = Database::getInstance();
            $stmt = $db->prepare(
                'SELECT u.*,
                        u.job_title AS title,
                        u.sector    AS department,
                        COALESCE(p.status, "offline") AS status,
                        p.status_text, p.status_emoji,
                        COALESCE(p.timezone, "America/Sao_Paulo") AS timezone,
                        p.last_seen_at
                 FROM users u
                 LEFT JOIN chat_presence p ON p.user_id = u.id
                 WHERE u.id = ? LIMIT 1'
            );
            $stmt->execute([Session::userId()]);
            self::$cachedUser = $stmt->fetch() ?: null;
        }
        return self::$cachedUser;
    }
}
