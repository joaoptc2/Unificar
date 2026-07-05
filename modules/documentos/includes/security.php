<?php
/**
 * Funções de Segurança do módulo DOCUMENTOS.
 *
 * - CSRF: delegado ao token único da plataforma (Core\Csrf)
 * - Auditoria: delegada ao log global (Core\Audit, module='documentos')
 * - Sanitização apenas no OUTPUT (input é armazenado cru)
 * - Validação de uploads por extensão + MIME real
 * - Download com streaming a partir de /uploads/documentos
 */

// ── CSRF (Core\Csrf) ────────────────────────────────────────────────────────

function csrf_token() {
    return Core\Csrf::token();
}

function csrf_field() {
    return Core\Csrf::field();
}

/**
 * Valida o token CSRF enviado no POST (aceita _csrf_token, csrf_token ou
 * o header X-CSRF-TOKEN — ver Core\Csrf::validate).
 */
function csrf_validate() {
    if (!Core\Csrf::validate()) {
        http_response_code(403);
        die('Token CSRF inválido. Recarregue a página e tente novamente.');
    }
}

// ── Sanitização ─────────────────────────────────────────────────────────────

/**
 * Escapa string para exibição segura em HTML (anti-XSS).
 * Este é o ÚNICO ponto de sanitização: no output.
 */
function e($string) {
    return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8');
}

/**
 * Limpa espaços e caracteres de controle. NÃO destrói HTML porque
 * a proteção contra XSS deve ser feita no output com e().
 */
function clean($value) {
    if ($value === null) return '';
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value);
    return trim($value);
}

function sanitize_email($email) {
    $email = trim((string) $email);
    return filter_var($email, FILTER_SANITIZE_EMAIL);
}

function is_valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function sanitize_int($value) {
    if (is_int($value)) return $value;
    $v = filter_var($value, FILTER_VALIDATE_INT);
    return $v === false ? 0 : $v;
}

// ── Upload de Arquivos ──────────────────────────────────────────────────────

/**
 * Valida arquivo enviado via upload.
 * Retorna array de erros (vazio se tudo OK).
 */
function validate_upload($file) {
    $errors = [];

    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Erro no envio do arquivo (código ' . ($file['error'] ?? '?') . ').';
        return $errors;
    }

    if ($file['size'] > UPLOAD_MAX_SIZE) {
        $errors[] = 'Arquivo excede o tamanho máximo permitido (' . format_bytes(UPLOAD_MAX_SIZE) . ').';
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!array_key_exists($ext, UPLOAD_ALLOWED_TYPES)) {
        $errors[] = 'Tipo de arquivo não permitido (' . e($ext) . ').';
    }

    // Verifica MIME real (mais seguro que tipo enviado pelo cliente)
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowed_mimes = array_values(UPLOAD_ALLOWED_TYPES);
        if ($mime && !in_array($mime, $allowed_mimes, true)) {
            $errors[] = 'Tipo MIME do arquivo não é permitido (' . e($mime) . ').';
        }
    }

    return $errors;
}

/**
 * Salva arquivo de upload de forma segura em /uploads/documentos.
 * Nome randomizado, organizado por hospital.
 */
function save_upload($file, $hospital_id) {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safe_name = uniqid('doc_', true) . '.' . $ext;

    $dest_dir = DOC_UPLOADS_PATH . '/hospital_' . (int) $hospital_id;
    if (!is_dir($dest_dir)) {
        mkdir($dest_dir, 0755, true);
    }

    $dest_path = $dest_dir . '/' . $safe_name;

    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        return false;
    }

    // MIME real persistido (melhor que o tipo enviado pelo cliente)
    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = (string) finfo_file($finfo, $dest_path);
        finfo_close($finfo);
    }

    return [
        'original_name' => $file['name'],
        'saved_name'    => $safe_name,
        'file_path'     => $dest_path,
        'file_size'     => $file['size'],
        'file_type'     => $ext,
        'mime_type'     => $mime ?: ($file['type'] ?? ''),
    ];
}

/**
 * Envia um arquivo para download em modo streaming (não carrega tudo em memória).
 * Protege contra path traversal garantindo que o arquivo está dentro de
 * DOC_UPLOADS_PATH (/uploads/documentos).
 *
 * @param string $file_full_path Caminho completo do arquivo no servidor
 * @param string $download_name  Nome que o usuário verá ao baixar
 * @param string $mime_type      Tipo MIME (opcional)
 */
function stream_download($file_full_path, $download_name, $mime_type = 'application/octet-stream') {
    $real = realpath($file_full_path);
    $base = realpath(DOC_UPLOADS_PATH);

    if ($real === false || $base === false || strpos($real, $base) !== 0) {
        http_response_code(404);
        exit('Arquivo não encontrado.');
    }

    if (!is_readable($real)) {
        http_response_code(404);
        exit('Arquivo não acessível.');
    }

    // Limpa buffers para não estourar memória
    while (ob_get_level() > 0) ob_end_clean();

    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: attachment; filename="' . basename($download_name) . '"');
    header('Content-Length: ' . filesize($real));
    header('Cache-Control: private, must-revalidate');
    header('Pragma: private');

    $fp = fopen($real, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit('Erro ao abrir arquivo.');
    }
    while (!feof($fp)) {
        echo fread($fp, 8192);
        @ob_flush();
        flush();
    }
    fclose($fp);
    exit;
}

// ── Logging ─────────────────────────────────────────────────────────────────

/**
 * Registra ação no log de auditoria global da plataforma (audit_log),
 * sempre com module = 'documentos'. Assinatura legada preservada;
 * $hospital_id é ignorado (contexto multi-hospital saiu da auditoria).
 */
function audit_log($action, $details = '', $user_id = null, $hospital_id = null) {
    try {
        Core\Audit::log(
            (string) $action,
            null,
            null,
            $details !== '' ? (string) $details : null,
            $user_id !== null ? (int) $user_id : null,
            'documentos'
        );
    } catch (Throwable $ex) {
        $uid  = $user_id ?? ($_SESSION['user_id'] ?? 0);
        $line = date('Y-m-d H:i:s') . " | user=$uid | $action | $details | " . $ex->getMessage() . "\n";
        @file_put_contents(LOGS_PATH . '/audit.log', $line, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Registra erro da aplicação no log.
 */
function log_error($context, Throwable $ex) {
    $line = sprintf(
        "[%s] %s | %s: %s @ %s:%d\n",
        date('Y-m-d H:i:s'),
        $context,
        get_class($ex),
        $ex->getMessage(),
        $ex->getFile(),
        $ex->getLine()
    );
    @file_put_contents(LOGS_PATH . '/app_errors.log', $line, FILE_APPEND | LOCK_EX);
    error_log($line);
}

/**
 * Retorna o IP do cliente considerando proxy.
 */
function client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
