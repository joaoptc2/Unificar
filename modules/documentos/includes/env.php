<?php
/**
 * Carregador de variáveis de ambiente (.env).
 *
 * Procura o .env em duas localizações, nesta ordem:
 *   1) UMA PASTA ACIMA do public_html (preferencial — fora da raiz web)
 *   2) Dentro da raiz do projeto (fallback; .htaccess bloqueia download)
 *
 * Formato:
 *   CHAVE=valor
 *   OUTRA="valor com espaços"
 *   # comentário
 */

function env_load() {
    $candidates = [
        dirname(dirname(__DIR__)) . '/.env',    // ../ public_html / ..
        dirname(__DIR__) . '/.env',
    ];

    $loaded_from = null;
    foreach ($candidates as $path) {
        if (is_file($path) && is_readable($path)) {
            _env_parse($path);
            $loaded_from = $path;
            break;
        }
    }

    return $loaded_from;
}

function _env_parse($path) {
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;

        $pos = strpos($line, '=');
        if ($pos === false) continue;

        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));

        // Remove aspas envolventes
        if (strlen($val) >= 2
            && (($val[0] === '"' && $val[-1] === '"')
                || ($val[0] === "'" && $val[-1] === "'"))) {
            $val = substr($val, 1, -1);
        }

        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key]    = $val;
            $_SERVER[$key] = $val;
            putenv("$key=$val");
        }
    }
}

/**
 * Lê uma variável de ambiente com fallback.
 * Converte strings "true"/"false"/"null" automaticamente.
 */
function env($key, $default = null) {
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') {
        return $default;
    }
    $lower = strtolower($v);
    if ($lower === 'true')  return true;
    if ($lower === 'false') return false;
    if ($lower === 'null')  return null;
    return $v;
}
