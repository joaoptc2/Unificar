<?php
/**
 * Proteção CSRF via token por formulário
 */
class Csrf
{
    /**
     * Gera ou retorna o token CSRF da sessão
     */
    public static function token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    /**
     * Retorna o campo hidden HTML com o token
     */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . self::token() . '">';
    }

    /**
     * Valida o token enviado no formulário
     */
    public static function validate(?string $token = null): bool
    {
        $token = $token ?? ($_POST['_csrf_token'] ?? '');
        if (empty($token) || empty($_SESSION['_csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['_csrf_token'], $token);
    }

    /**
     * Valida e aborta se inválido
     */
    public static function check(): void
    {
        if (!self::validate()) {
            http_response_code(403);
            die('Erro de segurança: token CSRF inválido. Recarregue a página e tente novamente.');
        }
    }

    /**
     * Regenera o token (após uso)
     */
    public static function regenerate(): void
    {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
}
