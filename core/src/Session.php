<?php

declare(strict_types=1);

namespace Core;

/**
 * Sessão única da plataforma (compartilhada por todos os módulos).
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetime = (int) Config::get('security.session_lifetime', 28800);
        $secure   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                 || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_name((string) Config::get('security.session_name', 'portal_sess'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $lifetime);

        session_start();

        // Expiração por inatividade
        $now  = time();
        $last = $_SESSION['_last_activity'] ?? $now;
        if (($now - $last) > $lifetime) {
            self::destroy();
            session_start();
        }
        $_SESSION['_last_activity'] = $now;

        // Regeneração periódica do ID (a cada 15 min)
        if (($now - ($_SESSION['_last_regen'] ?? 0)) > 900) {
            session_regenerate_id(true);
            $_SESSION['_last_regen'] = $now;
        }
    }

    public static function regenerate(): void
    {
        session_regenerate_id(true);
        $_SESSION['_last_regen'] = time();
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
