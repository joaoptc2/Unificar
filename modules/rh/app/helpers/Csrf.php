<?php
/**
 * Csrf — adaptador do token CSRF único da plataforma (Core\Csrf).
 * O mesmo token ($_SESSION['csrf_token']) vale em qualquer módulo;
 * o campo continua sendo emitido como `_csrf_token` (aceito pelo núcleo).
 */
class Csrf
{
    public static function token(): string
    {
        return Core\Csrf::token();
    }

    public static function field(): string
    {
        return Core\Csrf::field();
    }

    public static function validate(?string $token = null): bool
    {
        return Core\Csrf::validate($token);
    }

    public static function check(): void
    {
        if (!self::validate()) {
            http_response_code(403);
            die('Erro de segurança: token CSRF inválido. Recarregue a página e tente novamente.');
        }
    }

    /** O token é único por sessão no núcleo — regenerar é no-op. */
    public static function regenerate(): void
    {
    }
}
