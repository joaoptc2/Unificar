<?php
/**
 * Funções de Segurança
 *
 * - CSRF tokens (preservados entre requisições; rotacionados por sessão)
 * - Sanitização apenas no OUTPUT (input é armazenado cru)
 * - Hash de senhas com bcrypt cost 12
 * - Validação de uploads por extensão + MIME real
 * - Download com streaming (friendly para hospedagem compartilhada)
 * - Auditoria
 */

// ── CSRF ────────────────────────────────────────────────────────────────────

/**
 * Gera / retorna o token CSRF da sessão. NÃO destrói após uso
 * para permitir múltiplas abas. É rotacionado junto da sessão
 * (session_regenerate_id em session.php).
 */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Retorna o campo hidden HTML com o token CSRF.
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

/**
 * Valida o token CSRF enviado no POST.
 */
function csrf_validate() {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
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
    // Remove caracteres de controle exceto tab/newline/cr
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value);
    return trim($value);
}

/**
 * Sanitiza e-mail (apenas filtra caracteres inválidos).
 */
function sanitize_email($email) {
    $email = trim((string) $email);
    return filter_var($email, FILTER_SANITIZE_EMAIL);
}

/**
 * Valida e-mail.
 */
function is_valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Coerção segura para inteiro.
 */
function sanitize_int($value) {
    if (is_int($value)) return $value;
    $v = filter_var($value, FILTER_VALIDATE_INT);
    return $v === false ? 0 : $v;
}

// ── Senhas ──────────────────────────────────────────────────────────────────

/**
 * Gera hash seguro de senha.
 */
function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verifica senha contra hash.
 */
function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Valida força mínima da senha. Retorna array de erros (vazio se OK).
 */
function validate_password_strength($password) {
    $errors = [];
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'A senha deve ter no mínimo ' . PASSWORD_MIN_LENGTH . ' caracteres.';
    }
    if (!preg_match('/[A-Za-z]/', $password)) {
        $errors[] = 'A senha deve conter pelo menos uma letra.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'A senha deve conter pelo menos um número.';
    }
    return $errors;
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
 * Salva arquivo de upload de forma segura.
 * Nome randomizado, organizado por hospital.
 */
function save_upload($file, $hospital_id) {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safe_name = uniqid('doc_', true) . '.' . $ext;

    $dest_dir = UPLOADS_PATH . '/hospital_' . (int) $hospital_id;
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
 * Protege contra path traversal garantindo que o arquivo está dentro de UPLOADS_PATH.
 *
 * @param string $file_full_path Caminho completo do arquivo no servidor
 * @param string $download_name  Nome que o usuário verá ao baixar
 * @param string $mime_type      Tipo MIME (opcional)
 */
function stream_download($file_full_path, $download_name, $mime_type = 'application/octet-stream') {
    $real = realpath($file_full_path);
    $base = realpath(UPLOADS_PATH);

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
 * Registra ação no log de auditoria.
 */
function audit_log($action, $details = '', $user_id = null, $hospital_id = null) {
    if ($user_id === null)     $user_id     = $_SESSION['user_id']     ?? 0;
    if ($hospital_id === null) $hospital_id = $_SESSION['hospital_id'] ?? 0;

    try {
        db_execute(
            "INSERT INTO audit_logs (user_id, hospital_id, action, details, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [
                $user_id ?: null,
                $hospital_id ?: null,
                $action,
                $details,
                client_ip(),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]
        );
    } catch (Exception $ex) {
        $line = date('Y-m-d H:i:s') . " | user=$user_id | $action | $details | " . $ex->getMessage() . "\n";
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
 * Retorna o IP do cliente considerando proxy (Hostinger usa Cloudflare/proxy).
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
