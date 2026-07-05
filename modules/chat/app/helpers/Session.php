<?php
/**
 * Adaptador de sessão — a sessão única é iniciada e mantida pelo núcleo
 * (Core\Session / Core\Auth). Este helper apenas lê as chaves garantidas
 * pelo núcleo (user_id, user_name, user_email, user_avatar) e resolve o
 * papel do usuário NESTE módulo via $GLOBALS['MODULE_ROLE'].
 */
class Session
{
    /** No-op: o núcleo já iniciou a sessão. Mantido por compatibilidade. */
    public static function start(): void
    {
        // sessão gerida pelo núcleo
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
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

    /**
     * Flash messages do módulo. Grava também no flash do núcleo
     * (Core\Flash) para que o layout unificado exiba as mensagens.
     */
    public static function flash(string $key, ?string $value = null): mixed
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            \Core\Flash::set($key, $value);
            return null;
        }
        $msg = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $msg;
    }

    public static function isLoggedIn(): bool
    {
        return \Core\Auth::check();
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    /** Papel do usuário NESTE módulo (admin|manager|member|none). */
    public static function userRole(): ?string
    {
        return $GLOBALS['MODULE_ROLE'] ?? null;
    }

    public static function userName(): ?string
    {
        return $_SESSION['user_name'] ?? null;
    }

    public static function userEmail(): ?string
    {
        return $_SESSION['user_email'] ?? null;
    }

    public static function userAvatar(): ?string
    {
        return $_SESSION['user_avatar'] ?? null;
    }

    /** No-op: encerrar sessão é responsabilidade do núcleo (?m=auth&a=logout). */
    public static function destroy(): void
    {
        // sessão gerida pelo núcleo
    }
}
