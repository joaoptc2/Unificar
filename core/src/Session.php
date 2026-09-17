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
        // Mesma detecção do resto do sistema (Core\Https): antes esta cópia
        // aceitava X-Forwarded-Proto de qualquer origem, o que deixava um
        // cliente afirmar "vim por https" — e divergia do que o checkup via.
        $secure   = Https::requestIsSecure();

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

        // Uma restauração de backup derruba as sessões abertas antes dela: o
        // usuário logado pode não existir no banco que acabou de voltar, e as
        // permissões dele são as de antes. BackupRestore avança a época a
        // cada restauração de produção; ela vivia num "trecho para colar no
        // bootstrap" que nenhuma instalação tinha colado — inerte. Mora aqui,
        // que roda em toda requisição, sem depender de edição manual.
        try {
            $epoca = BackupRestore::sessionEpoch();
            if ($epoca > 0 && (int) ($_SESSION['_backup_epoch'] ?? 0) < $epoca) {
                $tinhaUsuario = isset($_SESSION['user_id']);
                self::destroy();
                session_start();
                $_SESSION['_backup_epoch'] = $epoca;
                $_SESSION['_last_activity'] = $now;
                if ($tinhaUsuario) {
                    $_SESSION['_flash']['warning'] = 'O sistema foi restaurado de um backup; entre de novo.';
                }
            } elseif (!isset($_SESSION['_backup_epoch'])) {
                $_SESSION['_backup_epoch'] = $epoca;
            }
        } catch (\Throwable) {
            // sem banco ainda (instalador): não há época a conferir.
        }

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
