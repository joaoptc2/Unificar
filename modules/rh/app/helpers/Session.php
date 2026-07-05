<?php
/**
 * Session — adaptador da sessão única do núcleo.
 *
 * A sessão é iniciada pelo core/bootstrap.php (Core\Session::start()).
 * As chaves user_id/user_name/user_email são mantidas pelo núcleo.
 * O papel do usuário NESTE módulo vem de $GLOBALS['MODULE_ROLE']
 * (definido por request pelo front controller da plataforma).
 */
class Session
{
    /** A sessão única já foi iniciada pelo núcleo — no-op. */
    public static function start(): void
    {
    }

    public static function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Encerrar sessão é responsabilidade do núcleo (?m=auth&a=logout). */
    public static function destroy(): void
    {
        Core\Session::destroy();
    }

    public static function flash(string $key, $value = null)
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $val = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $val;
    }

    public static function isLoggedIn(): bool
    {
        return Core\Auth::check();
    }

    public static function userId(): ?int
    {
        return Core\Auth::id();
    }

    /** Papel do usuário no módulo RH (vocabulário legado do módulo). */
    public static function userRole(): ?string
    {
        return $GLOBALS['MODULE_ROLE'] ?? ($_SESSION['user_role'] ?? null);
    }

    public static function userName(): ?string
    {
        return $_SESSION['user_name'] ?? null;
    }
}
