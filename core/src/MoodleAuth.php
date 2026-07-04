<?php

declare(strict_types=1);

namespace Core;

/**
 * Ponte de autenticação com o Moodle via Web Services REST.
 *
 * Fluxo:
 *  1. POST {url}/login/token.php (username, password, service) → token;
 *  2. GET  {url}/webservice/rest/server.php core_webservice_get_site_info
 *     com o token do usuário → userid, username, fullname;
 *  3. (opcional) com moodle.admin_token, core_user_get_users_by_field
 *     → e-mail e nome completos.
 */
final class MoodleAuth
{
    /** @return array{ok: bool, error?: string, user?: array{id:int, username:string, fullname:string, email:?string, token:string}} */
    public static function attempt(string $username, string $password): array
    {
        $base = rtrim((string) Config::get('moodle.url', ''), '/');
        $service = (string) Config::get('moodle.service', 'moodle_mobile_app');
        if ($base === '') {
            return ['ok' => false, 'error' => 'Moodle não configurado.'];
        }

        $resp = self::http($base . '/login/token.php', [
            'username' => $username,
            'password' => $password,
            'service'  => $service,
        ]);
        if (!is_array($resp) || empty($resp['token'])) {
            return ['ok' => false, 'error' => $resp['error'] ?? 'Falha na autenticação Moodle.'];
        }
        $token = (string) $resp['token'];

        $info = self::ws($base, $token, 'core_webservice_get_site_info');
        if (!is_array($info) || empty($info['userid'])) {
            return ['ok' => false, 'error' => 'Não foi possível obter dados do usuário no Moodle.'];
        }

        $user = [
            'id'       => (int) $info['userid'],
            'username' => (string) ($info['username'] ?? $username),
            'fullname' => (string) ($info['fullname'] ?? $username),
            'email'    => null,
            'token'    => $token,
        ];

        // Enriquecimento opcional com token administrativo
        $adminToken = (string) Config::get('moodle.admin_token', '');
        if ($adminToken !== '') {
            $details = self::ws($base, $adminToken, 'core_user_get_users_by_field', [
                'field'     => 'id',
                'values[0]' => $user['id'],
            ]);
            if (is_array($details) && isset($details[0])) {
                $user['email']    = $details[0]['email'] ?? null;
                $user['fullname'] = $details[0]['fullname'] ?? $user['fullname'];
            }
        }

        return ['ok' => true, 'user' => $user];
    }

    /** Chamada REST genérica a uma função de web service. */
    public static function ws(string $base, string $token, string $function, array $params = []): mixed
    {
        return self::http($base . '/webservice/rest/server.php', array_merge([
            'wstoken'            => $token,
            'wsfunction'         => $function,
            'moodlewsrestformat' => 'json',
        ], $params));
    }

    private static function http(string $url, array $post): mixed
    {
        $timeout = 8;

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($post),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT        => $timeout,
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($post),
                'timeout' => $timeout,
            ]]);
            $body = @file_get_contents($url, false, $ctx);
        }

        if (!is_string($body) || $body === '') {
            return null;
        }
        $json = json_decode($body, true);

        // Moodle devolve {exception,errorcode,message} em erros de WS
        if (is_array($json) && isset($json['exception'])) {
            return ['error' => $json['message'] ?? 'Erro no Moodle.'];
        }
        return $json;
    }
}
