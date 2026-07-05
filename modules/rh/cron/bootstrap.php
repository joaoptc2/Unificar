<?php
/**
 * Bootstrap comum para os CRON Jobs.
 *
 * - Garante que o script não seja executado via web (independente da SAPI).
 *   Em hospedagens compartilhadas (ex.: Hostinger) o PHP em cron pode rodar
 *   como `cli`, `cli-server`, `cgi-fcgi` ou `litespeed`. Por isso a checagem
 *   definitiva é a ausência de contexto HTTP (HTTP_HOST / REQUEST_METHOD).
 * - Opcionalmente permite execução via token secreto (cron_token definido em
 *   config/app.php) para suportar painéis que só conseguem chamar URLs.
 */

$cronAppConfig = require __DIR__ . '/../config/app.php';
$cronToken = $cronAppConfig['cron_token'] ?? '';

$isHttpRequest = !empty($_SERVER['HTTP_HOST']) || !empty($_SERVER['REQUEST_METHOD']);
$providedToken = $_SERVER['argv'][1]
    ?? ($_GET['token'] ?? ($_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));

$authorized = false;
if (!$isHttpRequest) {
    // Chamado via linha de comando / cron nativo.
    $authorized = true;
} elseif ($cronToken !== '' && hash_equals($cronToken, (string)$providedToken)) {
    // Chamado via URL com token válido (fallback para cron HTTP).
    $authorized = true;
}

if (!$authorized) {
    http_response_code(403);
    exit('Acesso negado.');
}

// Evitar timeouts em jobs longos.
@set_time_limit(0);

// Autoloader para helpers/models/controllers (elimina requires manuais nos crons).
require __DIR__ . '/../app/helpers/Autoloader.php';
Autoloader::register();
