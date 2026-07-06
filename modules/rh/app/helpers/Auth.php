<?php
/**
 * Auth — adaptador de MICROPERMISSÕES do módulo RH.
 *
 * Autenticação (login/logout/2FA/reset) é responsabilidade do núcleo.
 * A antiga matriz de papéis (admin/rh/gestor/visualizador/funcionario) foi
 * substituída pelo catálogo de micropermissões do manifesto (module.php →
 * 'permissions'), resolvido pelo núcleo (Core\Perms, $GLOBALS['MODULE_PERMS'])
 * e exposto pelos helpers globais core_can()/core_require().
 *
 * Esta classe é apenas um WRAPPER FINO de compatibilidade com o vocabulário
 * legado Auth::can('<recurso>', '<ação>') — nenhuma decisão de acesso é
 * tomada aqui. Código novo deve chamar core_can()/core_require() direto.
 */
class Auth
{
    /** Recursos renomeados no catálogo de micropermissões. */
    private const RESOURCE_MAP = [
        'documents' => 'employee_documents',
        'users'     => 'user_links',
    ];

    /**
     * Exige que o utilizador esteja autenticado (delegado ao núcleo).
     */
    public static function requireLogin(): void
    {
        Core\Auth::requireLogin();
    }

    /**
     * Wrapper fino: traduz o par legado (recurso, ação) para a chave nova
     * "<recurso>.<ação>" e delega ao núcleo (core_can).
     */
    public static function can(string $resource, string $action): bool
    {
        return core_can(self::key($resource, $action));
    }

    /**
     * Exige a micropermissão ou aborta com 403 (via núcleo).
     */
    public static function requirePermission(string $resource, string $action): void
    {
        core_require(self::key($resource, $action));
    }

    /** Traduz o vocabulário legado para a chave do catálogo atual. */
    private static function key(string $resource, string $action): string
    {
        $resource = self::RESOURCE_MAP[$resource] ?? $resource;
        if ($resource === 'requests' && $action === 'edit') {
            $action = 'respond'; // no legado, responder solicitação era 'edit'
        }
        return $resource . '.' . $action;
    }
}
