<?php
/**
 * Adaptador de CSRF — delega ao token único da plataforma (Core\Csrf,
 * $_SESSION['csrf_token']). Aceita _csrf_token, csrf_token e o header
 * X-CSRF-TOKEN, exatamente como o núcleo.
 */
class Csrf
{
    public static function token(): string
    {
        return \Core\Csrf::token();
    }

    public static function field(): string
    {
        return \Core\Csrf::field();
    }

    public static function check(): void
    {
        if (!\Core\Csrf::validate()) {
            http_response_code(403);
            echo json_encode(['error' => 'Token CSRF inválido.']);
            exit;
        }
    }

    public static function checkAjax(): bool
    {
        return \Core\Csrf::validate();
    }
}
