<?php
/**
 * ============================================================
 * MÓDULO MANUTENÇÃO — bootstrap/adaptador do núcleo
 *
 * Este arquivo substitui o config.php legado do ManuHosp.
 * Sessão, autenticação, CSRF, notificações e auditoria são do
 * NÚCLEO da plataforma (core/); os helpers abaixo mantêm a API
 * usada pelas pages/ legadas, delegando para Core\*.
 * ============================================================
 */

// O núcleo já foi carregado pelo front controller (ou pelo cron da raiz).
// Fallback defensivo para inclusões diretas em CLI/testes.
if (!defined('CORE_BOOTSTRAPPED')) {
    require_once dirname(__DIR__, 2) . '/core/bootstrap.php';
}

// --- Constantes do módulo ---
if (!defined('APP_NAME'))    define('APP_NAME', 'Manutenção Hospitalar');
if (!defined('APP_VERSION')) define('APP_VERSION', '5.0.0');
if (!defined('MAN_MODULE_SLUG')) define('MAN_MODULE_SLUG', 'manutencao');
if (!defined('MAN_BASE_PATH'))   define('MAN_BASE_PATH', __DIR__);

// Uploads centralizados da plataforma: /uploads/manutencao
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', (defined('UPLOADS_PATH') ? UPLOADS_PATH : dirname(__DIR__, 2) . '/uploads') . '/manutencao');
}
if (!defined('MAX_UPLOAD_SIZE')) define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5 MB

// URL base do módulo. O front controller define MODULE_URL; fora dele
// (cron/CLI) derivamos do helper do núcleo.
if (!defined('MODULE_URL')) {
    define('MODULE_URL', core_url('index.php?m=' . MAN_MODULE_SLUG));
}

/*
 * Compatibilidade: as pages legadas leem $_SESSION['user_role'] diretamente.
 * O papel agora é por módulo ($GLOBALS['MODULE_ROLE'], definido pelo núcleo
 * a cada request), então espelhamos na sessão.
 */
if (isset($GLOBALS['MODULE_ROLE']) && PHP_SAPI !== 'cli') {
    $_SESSION['user_role'] = $GLOBALS['MODULE_ROLE'];
}

// ============================================================
// BANCO DE DADOS — conexão única do núcleo
// ============================================================

function db(): PDO
{
    return Core\DB::pdo();
}

// ============================================================
// AUTENTICAÇÃO / PAPÉIS (adaptadores do núcleo)
// ============================================================

function isLoggedIn(): bool
{
    return Core\Auth::check();
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        core_redirect('index.php?m=auth&a=login');
    }
}

/** Papel do usuário NESTE módulo (vocabulário legado do ManuHosp). */
function moduleRole(): string
{
    return (string) ($GLOBALS['MODULE_ROLE'] ?? ($_SESSION['user_role'] ?? 'none'));
}

function requireRole(string ...$roles): void
{
    requireLogin();
    if (!in_array(moduleRole(), $roles, true)) {
        http_response_code(403);
        die('Acesso negado.');
    }
}

/** Checa sem interromper — útil dentro de templates. */
function hasRole(string ...$roles): bool
{
    return in_array(moduleRole(), $roles, true);
}

/**
 * Permissões por módulo (submódulos internos do ManuHosp).
 *
 * Roles:
 *   admin       — acesso total
 *   manager     — acesso a todos os módulos (sem admin)
 *   maintenance — OS, equipamentos, estoque, manutenção, calibração, técnicos
 *   cleaning    — somente limpeza
 *   viewer      — somente leitura em tudo
 */
function canWrite(): bool
{
    return hasRole('admin', 'manager', 'maintenance', 'cleaning');
}

function canAccessModule(string $module): bool
{
    $role = moduleRole();
    $map = [
        'dashboard'      => ['admin','manager','maintenance','cleaning','viewer'],
        'equipment'      => ['admin','manager','maintenance'],
        'service-orders' => ['admin','manager','maintenance'],
        'stock'          => ['admin','manager','maintenance'],
        'maintenance'    => ['admin','manager','maintenance'],
        'calibration'    => ['admin','manager','maintenance'],
        'technicians'    => ['admin','manager','maintenance'],
        'cleaning'       => ['admin','manager','cleaning'],
        'indicators'     => ['admin','manager','maintenance','cleaning'],
        'notifications'  => ['admin','manager','maintenance','cleaning','viewer'],
        'admin'          => ['admin'],
        'qr-locations'   => ['admin','manager'],
        'anvisa-report'  => ['admin','manager','maintenance'],
        'heatmap'        => ['admin','manager'],
        'calendar'       => ['admin','manager','maintenance','cleaning'],
        'inspections'    => ['admin','manager','maintenance'],
        'search'         => ['admin','manager','maintenance','cleaning','viewer'],
        'export'         => ['admin','manager','maintenance','cleaning','viewer'],
    ];
    $allowed = $map[$module] ?? ['admin'];
    return in_array($role, $allowed, true);
}

function requireModule(string $module): void
{
    requireLogin();
    if (!canAccessModule($module)) {
        http_response_code(403);
        die('Acesso negado. Seu perfil não tem permissão para este módulo.');
    }
}

function requireWrite(): void
{
    if (!canWrite()) {
        http_response_code(403);
        die('Acesso negado. Seu perfil não tem permissão para modificar dados.');
    }
}

function addOsHistory(int $osId, string $action, string $details = ''): void
{
    try {
        db()->prepare("INSERT INTO man_os_history (os_id, user_id, user_name, action, details) VALUES (?, ?, ?, ?, ?)")
            ->execute([$osId, $_SESSION['user_id'] ?? null, $_SESSION['user_name'] ?? 'Sistema', $action, $details ?: null]);
    } catch (Exception $ex) {}
}

/** Usuário logado (linha da tabela GLOBAL users do núcleo). */
function currentUser(): ?array
{
    return Core\Auth::user();
}

function hospitalId(): int
{
    return (int) ($_SESSION['hospital_id'] ?? 0);
}

// ============================================================
// SEGURANÇA
// ============================================================

function e($str): string
{
    return htmlspecialchars((string) ($str ?? ''), ENT_QUOTES, 'UTF-8');
}

function sanitize($input): string
{
    return e(trim((string) $input));
}

/** Token CSRF único da plataforma (Core\Csrf). */
function csrfToken(): string
{
    return Core\Csrf::token();
}

function csrfField(): string
{
    // O núcleo aceita tanto csrf_token quanto _csrf_token — o name legado
    // é mantido para não alterar os formulários das pages.
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): bool
{
    // Core\Csrf::validate() preserva a semântica booleana legada
    // (as pages usam "if ($_POST && verifyCsrf())").
    return Core\Csrf::validate();
}

function generateToken(int $length = 32): string
{
    return bin2hex(random_bytes($length));
}

// ============================================================
// HELPERS
// ============================================================

function flash(string $type, string $message): void
{
    $_SESSION['flash_manutencao'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    $flash = $_SESSION['flash_manutencao'] ?? null;
    unset($_SESSION['flash_manutencao']);
    return $flash;
}

function redirect(string $url): void
{
    // URLs relativas legadas (index.php + query 'page=x') ganham m=manutencao
    if (str_starts_with($url, 'index.php?') && strpos($url, 'm=') === false) {
        $url = MODULE_URL . '&' . substr($url, strlen('index.php?'));
    }
    header("Location: {$url}");
    exit;
}

/** URL interna do módulo: sempre com m=manutencao. */
function url(string $page, array $params = []): string
{
    $params = array_merge(['page' => $page], $params);
    return MODULE_URL . '&' . http_build_query($params);
}

/** URL pública de um arquivo enviado (uploads/manutencao/...). */
function uploadUrl(string $path): string
{
    return core_url('uploads/' . MAN_MODULE_SLUG . '/' . ltrim($path, '/'));
}

function generateOsNumber(): string
{
    $prefix = 'OS';
    $date   = date('Ymd');
    $rand   = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
    return "{$prefix}-{$date}-{$rand}";
}

function formatDate(?string $date, string $format = 'd/m/Y'): string
{
    if (empty($date)) {
        return '-';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $date)
        ?: DateTime::createFromFormat('Y-m-d', $date);
    return $dt ? $dt->format($format) : $date;
}

function uploadFile(array $file, string $subdir = ''): ?string
{
    if (empty($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > MAX_UPLOAD_SIZE) {
        return null;
    }
    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        return null;
    }

    // Mapa MIME real → extensão canônica
    $allowedMime = [
        'image/jpeg'      => 'jpg',
        'image/pjpeg'     => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    $mime = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $file['tmp_name']) ?: null;
            finfo_close($finfo);
        }
    }
    if (!$mime && function_exists('mime_content_type')) {
        $mime = mime_content_type($file['tmp_name']) ?: null;
    }
    if (!$mime || !isset($allowedMime[$mime])) {
        return null;
    }
    $ext = $allowedMime[$mime];

    // Confere também a extensão declarada — ambas precisam ser seguras
    $declared = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $declaredOk = in_array($declared, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], true);
    if (!$declaredOk) {
        return null;
    }

    $dir = UPLOAD_PATH;
    if ($subdir !== '') {
        // Whitelist de subdirs: só letras/números/underscore
        if (!preg_match('/^[a-z0-9_\-]+$/i', $subdir)) {
            return null;
        }
        $dir .= '/' . $subdir;
    }
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return null;
    }

    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest     = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return null;
    }
    @chmod($dest, 0644);

    return ($subdir !== '' ? $subdir . '/' : '') . $filename;
}

// ============================================================
// EMAIL (mail nativa + headers corretos UTF-8) — best-effort
// ============================================================

function sendMail(string $to, string $subject, string $bodyHtml, string $bodyText = ''): bool
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $fromEmail = (string) core_config('mail.from', 'no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $fromName  = (string) core_config('mail.from_name', APP_NAME);

    if ($bodyText === '') {
        $bodyText = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $bodyHtml)));
    }

    $boundary = '=_b_' . bin2hex(random_bytes(8));
    $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $fromEnc    = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>';

    $headers   = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'From: ' . $fromEnc;
    $headers[] = 'Reply-To: ' . $fromEmail;
    $headers[] = 'X-Mailer: ManuHosp/' . APP_VERSION;
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $eol  = "\r\n";
    $body = '--' . $boundary . $eol
          . 'Content-Type: text/plain; charset=UTF-8' . $eol
          . 'Content-Transfer-Encoding: 8bit' . $eol . $eol
          . $bodyText . $eol . $eol
          . '--' . $boundary . $eol
          . 'Content-Type: text/html; charset=UTF-8' . $eol
          . 'Content-Transfer-Encoding: 8bit' . $eol . $eol
          . $bodyHtml . $eol . $eol
          . '--' . $boundary . '--' . $eol;

    $ok = @mail($to, $subjectEnc, $body, implode($eol, $headers));
    if (!$ok) {
        error_log('sendMail failed to ' . $to);
    }
    return (bool) $ok;
}

/** Auditoria unificada do núcleo (tabela global audit_log, module=manutencao). */
function auditLog(string $action, string $entity = '', ?int $entityId = null, string $details = ''): void
{
    Core\Audit::log(
        $action,
        $entity !== '' ? $entity : null,
        $entityId !== null ? (string) $entityId : null,
        $details !== '' ? $details : null,
        null,
        MAN_MODULE_SLUG
    );
}

// ============================================================
// NOTIFICAÇÕES (tabela GLOBAL notifications, module=manutencao)
// ============================================================

/**
 * Usuários que devem receber alertas operacionais do módulo:
 * admins/gestores do módulo (user_module_access) + admins globais.
 *
 * @return array<int, array{id:int, name:string, email:string}>
 */
function manModuleManagers(): array
{
    try {
        return db()->query("
            SELECT DISTINCT u.id, u.name, u.email
            FROM users u
            LEFT JOIN user_module_access uma
                   ON uma.user_id = u.id
                  AND uma.module_slug = '" . MAN_MODULE_SLUG . "'
            WHERE u.active = 1
              AND (u.is_admin = 1 OR uma.role IN ('admin','manager'))
        ")->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }
}

/**
 * Cria uma notificação (global) para todos os gestores do módulo.
 * Substitui as notificações "hospital-wide" (user_id NULL) do legado.
 */
function manNotifyManagers(string $type, string $title, string $message, ?string $link = null): void
{
    foreach (manModuleManagers() as $u) {
        try {
            Core\Notifications::add((int) $u['id'], $title, $message, $link, $type, MAN_MODULE_SLUG);
        } catch (Throwable $ex) {
            error_log('manNotifyManagers: ' . $ex->getMessage());
        }
    }
}

// ============================================================
// PAGINAÇÃO
// ============================================================

function paginate(string $baseQuery, array $params, int $perPage = 20): array
{
    // Valores sanitizados como inteiro — evita SQL injection na interpolação
    $perPage = max(1, min(200, (int)$perPage));
    $page    = max(1, (int)($_GET['pg'] ?? 1));
    $offset  = ($page - 1) * $perPage;

    // contar total (mesmos params do baseQuery, sem LIMIT)
    $countSql = "SELECT COUNT(*) FROM ({$baseQuery}) AS _t";
    $stmt = db()->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // buscar registros. Interpolação segura porque $perPage e $offset
    // foram forçados a inteiro acima.
    $dataSql = "{$baseQuery} LIMIT {$perPage} OFFSET {$offset}";
    $stmt = db()->prepare($dataSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    return [
        'rows'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'perPage'  => $perPage,
        'lastPage' => max(1, (int)ceil($total / $perPage)),
    ];
}

function calcNextDate(string $currentDate, string $frequency): string
{
    $map = [
        'daily'      => '+1 day',
        'weekly'     => '+1 week',
        'biweekly'   => '+2 weeks',
        'monthly'    => '+1 month',
        'quarterly'  => '+3 months',
        'semiannual' => '+6 months',
        'annual'     => '+1 year',
    ];
    $interval = $map[$frequency] ?? '+1 month';
    $dt = new DateTime($currentDate);
    $dt->modify($interval);
    return $dt->format('Y-m-d');
}

function paginationLinks(int $currentPage, int $lastPage, string $baseUrl): string
{
    if ($lastPage <= 1) {
        return '';
    }
    $html = '<nav class="pagination">';
    for ($i = 1; $i <= $lastPage; $i++) {
        $sep  = strpos($baseUrl, '?') !== false ? '&' : '?';
        $link = $baseUrl . $sep . 'pg=' . $i;
        $cls  = $i === $currentPage ? 'class="active"' : '';
        $html .= "<a href=\"{$link}\" {$cls}>{$i}</a> ";
    }
    $html .= '</nav>';
    return $html;
}
