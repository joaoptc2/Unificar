<?php

declare(strict_types=1);

namespace Core;

/**
 * Token CSRF único da plataforma ($_SESSION['csrf_token']).
 * Os helpers de CSRF dos módulos legados delegam para cá, de modo que
 * um mesmo token vale em qualquer formulário de qualquer módulo.
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        $t = self::token();
        return '<input type="hidden" name="_csrf_token" value="' . $t . '">';
    }

    /** Valida token vindo de _csrf_token, csrf_token ou header X-CSRF-TOKEN. */
    public static function validate(?string $token = null): bool
    {
        $token ??= $_POST['_csrf_token']
            ?? $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '';
        return is_string($token)
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function check(?string $token = null): void
    {
        if (self::validate($token)) {
            return;
        }
        // Envio maior que post_max_size: o PHP descarta o corpo inteiro (e com
        // ele o token), então sem esta checagem o usuário veria "sessão
        // expirada" ao mandar um arquivo grande demais.
        if (self::postWasDiscarded()) {
            http_response_code(413);
            $limit = (string) ini_get('post_max_size');
            exit('O envio é maior que o limite do servidor (' . htmlspecialchars($limit, ENT_QUOTES)
               . '). Volte, use um arquivo menor e tente novamente.');
        }
        http_response_code(419);
        exit('Sessão expirada ou token inválido. Volte e tente novamente.');
    }

    /** O corpo do POST chegou, mas o PHP o descartou por exceder o limite? */
    private static function postWasDiscarded(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }
        if ($_POST !== [] || $_FILES !== []) {
            return false;
        }
        return (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
    }
}
