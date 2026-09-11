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

// ============================================================
// BANCO DE DADOS — conexão única do núcleo
// ============================================================

function db(): PDO
{
    return Core\DB::pdo();
}

// ============================================================
// AUTENTICAÇÃO / MICROPERMISSÕES (adaptadores do núcleo)
//
// O núcleo define $GLOBALS['MODULE_PERMS'] (conjunto efetivo do usuário
// neste módulo) a cada request e expõe core_can()/core_require(). Os
// wrappers abaixo só traduzem os nomes de página legados do ManuHosp
// para as chaves "<recurso>.<ação>" do catálogo do manifesto — toda a
// decisão de acesso é delegada ao núcleo (Core\Perms).
//
// Escritas NÃO usam wrapper genérico: cada bloco POST das pages chama
// core_require('<recurso>.<create|edit|delete>') da ação específica.
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

/**
 * Mapa página legada → micropermissão de visualização (recurso.view).
 * 'admin' (setores) e 'categories' são as abas do painel de configuração
 * na Administração central (admin_panel.php).
 */
function manPagePermission(string $page): ?string
{
    $map = [
        'admin'          => 'sectors.view',
        'categories'     => 'categories.view',
        'dashboard'      => 'dashboard.view',
        'equipment'      => 'equipment.view',
        'service-orders' => 'service_orders.view',
        'stock'          => 'stock.view',
        'maintenance'    => 'maintenance.view',
        'calibration'    => 'calibration.view',
        'technicians'    => 'technicians.view',
        'cleaning'       => 'cleaning.view',
        'indicators'     => 'indicators.view',
        'notifications'  => 'notifications.view',
        'qr-locations'   => 'qr_locations.view',
        'anvisa-report'  => 'anvisa.view',
        'heatmap'        => 'heatmap.view',
        'calendar'       => 'calendar.view',
        'inspections'    => 'inspections.view',
        'search'         => 'search.view',
        'export'         => 'export.view',
    ];
    return $map[$page] ?? null;
}

/** Interrompe com 403 quando o usuário não pode ver a página (via núcleo). */
function requireModule(string $page): void
{
    requireLogin();
    $perm = manPagePermission($page);
    if ($perm === null) {
        http_response_code(403);
        Core\Layout::renderError(403, 'Você não tem permissão para esta ação. Solicite ao administrador.');
        exit;
    }
    core_require($perm);
}

function addOsHistory(int $osId, string $action, string $details = ''): void
{
    try {
        db()->prepare("INSERT INTO man_os_history (os_id, user_id, user_name, action, details) VALUES (?, ?, ?, ?, ?)")
            ->execute([$osId, $_SESSION['user_id'] ?? null, $_SESSION['user_name'] ?? 'Sistema', $action, $details ?: null]);
    } catch (Exception $ex) {}
}

function hospitalId(): int
{
    return (int) ($_SESSION['hospital_id'] ?? 0);
}

/**
 * Nome da unidade/organização exibido em telas e relatórios. Vem da
 * configuração central (Administração > Configurações → org_name); a
 * antiga aba "Hospital / Dados da unidade" do módulo foi descontinuada.
 */
function manOrgName(): string
{
    $name = (string) ($_SESSION['hospital_name'] ?? '');
    if ($name === '') {
        try {
            $name = (string) (Core\Settings::get('org_name') ?? '');
        } catch (Throwable $ex) {
            $name = '';
        }
    }
    return $name !== '' ? $name : (string) core_config('app.name', APP_NAME);
}

// Bibliotecas do módulo: código de identificação (asset_code), código de
// barras Code 128 e QR Code — PHP puro, sem dependências externas.
require_once __DIR__ . '/lib/asset_code.php';
require_once __DIR__ . '/lib/barcode.php';
require_once __DIR__ . '/lib/qrcode.php';
require_once __DIR__ . '/lib/admin_actions.php';

// ============================================================
// SEGURANÇA
// ============================================================

function e($str): string
{
    return htmlspecialchars((string) ($str ?? ''), ENT_QUOTES, 'UTF-8');
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

/**
 * Este request é um POST com token CSRF VÁLIDO?
 *
 * Substitui o padrão legado `if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
 * verifyCsrf())`, que descartava em SILÊNCIO qualquer POST sem token — o
 * usuário via a página recarregada como se nada tivesse acontecido e não
 * havia registro do descarte. Agora um POST sem token válido é REJEITADO
 * com mensagem explícita, registro em auditoria e redirecionamento para a
 * mesma tela (padrão POST → Redirect → GET, sem reenvio do formulário).
 */
function manPostIsValid(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return false;
    }
    if (verifyCsrf()) {
        return true;
    }
    manRejectInvalidCsrf();
}

/** Rejeita o POST atual (token CSRF ausente/inválido) e encerra o request. */
function manRejectInvalidCsrf(): never
{
    flash('error', 'Sessão expirada ou token de segurança inválido: o formulário não foi enviado. Recarregue a página e tente novamente.');
    try {
        auditLog('csrf_rejected', 'request', null, (string) ($_SERVER['REQUEST_URI'] ?? ''));
    } catch (Throwable $ignored) {
    }

    // Volta para a MESMA tela em GET. Só aceita um caminho local: uma
    // barra inicial seguida de algo que não seja outra barra (senão
    // "//evil.com" viraria redirecionamento externo) e sem CR/LF (que
    // permitiria injeção de cabeçalho).
    $back = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (!preg_match('#^/(?![/\\\\])[^\r\n]*$#', $back)) {
        $back = MODULE_URL;
    }
    header('Location: ' . $back, true, 303);
    exit;
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
 * Usuários que devem receber alertas operacionais do módulo: quem tem a
 * micropermissão indicada (via Core\Perms::usersWith — já inclui admins
 * globais e respeita negações individuais).
 *
 * A chave padrão é service_orders.edit (quem "trabalha" as OS); passe a
 * chave adequada ao assunto (calibração vencendo → calibration.edit,
 * estoque baixo → stock.edit, ...).
 *
 * @return array<int, array{id:int, name:string, email:string}>
 */
function manModuleManagers(string $permKey = 'service_orders.edit'): array
{
    try {
        $ids = Core\Perms::usersWith(MAN_MODULE_SLUG, $permKey);
        if ($ids === []) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare("SELECT id, name, email FROM users WHERE active = 1 AND id IN ({$in}) ORDER BY name");
        $stmt->execute($ids);
        return $stmt->fetchAll();
    } catch (Throwable $ex) {
        return [];
    }
}

/**
 * Cria uma notificação (global) para os responsáveis pela permissão dada.
 * Substitui as notificações "hospital-wide" (user_id NULL) do legado.
 */
function manNotifyManagers(string $type, string $title, string $message, ?string $link = null, string $permKey = 'service_orders.edit'): void
{
    foreach (manModuleManagers($permKey) as $u) {
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

/**
 * Links de paginação com JANELA em torno da página atual (± 2), mais a
 * primeira e a última. Antes eram impressos TODOS os números — em uma base
 * grande (ex.: 4.000 equipamentos = 201 páginas) o rodapé virava uma parede
 * de links, pesando a página e o HTML.
 */
function paginationLinks(int $currentPage, int $lastPage, string $baseUrl): string
{
    if ($lastPage <= 1) {
        return '';
    }
    $currentPage = max(1, min($lastPage, $currentPage));
    $sep = strpos($baseUrl, '?') !== false ? '&' : '?';

    $pages = [1, $lastPage];
    for ($i = $currentPage - 2; $i <= $currentPage + 2; $i++) {
        if ($i >= 1 && $i <= $lastPage) {
            $pages[] = $i;
        }
    }
    $pages = array_values(array_unique($pages));
    sort($pages);

    $html = '<nav class="pagination" aria-label="Paginação">';
    $prev = 0;
    foreach ($pages as $i) {
        if ($prev > 0 && $i > $prev + 1) {
            $html .= '<span class="px-1 text-muted">…</span> ';
        }
        $link = e($baseUrl . $sep . 'pg=' . $i);
        $cls  = $i === $currentPage ? 'class="active"' : '';
        $html .= "<a href=\"{$link}\" {$cls}>{$i}</a> ";
        $prev = $i;
    }
    $html .= '</nav>';
    return $html;
}
