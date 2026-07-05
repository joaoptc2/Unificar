<?php
/**
 * Funções Auxiliares Globais
 */

// ── Navegação ───────────────────────────────────────────────────────────────

function redirect($path = '') {
    $base = APP_URL ?: '';
    header('Location: ' . $base . '/' . ltrim($path, '/'));
    exit;
}

function url($path = '') {
    $base = APP_URL ?: '';
    return $base . '/' . ltrim($path, '/');
}

/**
 * URL para asset com versão (invalida cache quando APP_VERSION muda).
 */
function asset($path) {
    return url($path) . '?v=' . ASSETS_VERSION;
}

// ── Views ───────────────────────────────────────────────────────────────────

function view($view_name, $data = []) {
    extract($data);

    if (!isset($page_title)) $page_title = APP_NAME;

    $view_file = VIEWS_PATH . '/' . $view_name . '.php';
    if (!file_exists($view_file)) {
        if (APP_DEBUG) die('View não encontrada: ' . $view_file);
        die('Erro interno.');
    }

    ob_start();
    require $view_file;
    $content = ob_get_clean();

    require VIEWS_PATH . '/layouts/main.php';
}

function view_standalone($view_name, $data = []) {
    extract($data);
    $view_file = VIEWS_PATH . '/' . $view_name . '.php';

    if (!file_exists($view_file)) {
        if (APP_DEBUG) die('View não encontrada: ' . $view_file);
        die('Erro interno.');
    }

    require $view_file;
}

// ── Formatação ──────────────────────────────────────────────────────────────

function format_date($date) {
    if (empty($date)) return '—';
    return date('d/m/Y', strtotime($date));
}

function format_datetime($datetime) {
    if (empty($datetime)) return '—';
    return date('d/m/Y H:i', strtotime($datetime));
}

function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow   = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function format_number($number, $decimals = 2) {
    return number_format((float) $number, $decimals, ',', '.');
}

// ── Requisição ──────────────────────────────────────────────────────────────

function is_post() { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }
function is_get()  { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'; }

/**
 * Valor do POST sem destruição de HTML. Use e() na hora de exibir.
 */
function input($key, $default = '') {
    return isset($_POST[$key]) ? (is_string($_POST[$key]) ? trim($_POST[$key]) : $_POST[$key]) : $default;
}

function query($key, $default = '') {
    return isset($_GET[$key]) ? (is_string($_GET[$key]) ? trim($_GET[$key]) : $_GET[$key]) : $default;
}

// ── Resposta ────────────────────────────────────────────────────────────────

function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Envia uma resposta CSV ao cliente. Adiciona BOM UTF-8 para abrir no Excel.
 *
 * @param string $filename
 * @param array  $headers Cabeçalhos (array de strings)
 * @param iterable $rows  Cada linha é um array associativo ou numérico
 */
function csv_response($filename, $headers, $rows) {
    while (ob_get_level() > 0) ob_end_clean();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Cache-Control: private, must-revalidate');

    // BOM para Excel reconhecer UTF-8
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, $headers, ';');
    foreach ($rows as $row) {
        fputcsv($out, array_values($row), ';');
    }
    fclose($out);
    exit;
}

// ── Paginação ───────────────────────────────────────────────────────────────

function paginate($total, $per_page = null) {
    $per_page = $per_page ?: PAGINATION_PER_PAGE;
    $current  = max(1, (int) query('page', 1));
    $pages    = max(1, (int) ceil($total / $per_page));
    if ($current > $pages) $current = $pages;
    $offset   = ($current - 1) * $per_page;

    return [
        'current'  => $current,
        'per_page' => $per_page,
        'total'    => (int) $total,
        'pages'    => $pages,
        'offset'   => $offset,
    ];
}

/**
 * Renderiza os controles de paginação (Bootstrap 5).
 * Preserva todos os parâmetros GET atuais, exceto `page`.
 */
function pagination_html($pagination, $base_url = null) {
    if ($pagination['pages'] <= 1) return '';

    if ($base_url === null) {
        $base_url = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    }

    $params = $_GET;
    unset($params['page']);
    $qs_base = !empty($params) ? http_build_query($params) . '&' : '';

    $current = $pagination['current'];
    $pages   = $pagination['pages'];

    $link = function($p, $label = null, $disabled = false, $active = false) use ($base_url, $qs_base) {
        $cls = 'page-item' . ($disabled ? ' disabled' : '') . ($active ? ' active' : '');
        $href = $disabled ? '#' : ($base_url . '?' . $qs_base . 'page=' . $p);
        return '<li class="' . $cls . '"><a class="page-link" href="' . e($href) . '">'
            . ($label ?? $p) . '</a></li>';
    };

    $html = '<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center mb-0">';
    $html .= $link(max(1, $current - 1), '&laquo;', $current <= 1);

    $start = max(1, $current - 2);
    $end   = min($pages, $current + 2);
    if ($start > 1) {
        $html .= $link(1);
        if ($start > 2) $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
    }
    for ($p = $start; $p <= $end; $p++) {
        $html .= $link($p, null, false, $p === $current);
    }
    if ($end < $pages) {
        if ($end < $pages - 1) $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        $html .= $link($pages);
    }
    $html .= $link(min($pages, $current + 1), '&raquo;', $current >= $pages);

    $html .= '</ul></nav>';
    return $html;
}

// ── Miscelânea ──────────────────────────────────────────────────────────────

function days_until($date) {
    $now    = new DateTime('today');
    $target = new DateTime($date);
    $diff   = $now->diff($target);
    return $diff->invert ? -$diff->days : $diff->days;
}

function expiry_class($days) {
    if ($days < 0)   return 'danger';
    if ($days <= 30) return 'warning';
    return 'success';
}

function expiry_label($days) {
    if ($days < 0)   return 'Vencido há ' . abs($days) . ' dias';
    if ($days === 0) return 'Vence hoje';
    if ($days <= 30) return 'Vence em ' . $days . ' dias';
    return 'Válido (' . $days . ' dias)';
}

function file_icon($ext) {
    $icons = [
        'pdf'  => 'bi-file-earmark-pdf text-danger',
        'doc'  => 'bi-file-earmark-word text-primary',
        'docx' => 'bi-file-earmark-word text-primary',
        'xls'  => 'bi-file-earmark-excel text-success',
        'xlsx' => 'bi-file-earmark-excel text-success',
        'jpg'  => 'bi-file-earmark-image text-info',
        'jpeg' => 'bi-file-earmark-image text-info',
        'png'  => 'bi-file-earmark-image text-info',
        'txt'  => 'bi-file-earmark-text text-secondary',
    ];
    return $icons[strtolower($ext)] ?? 'bi-file-earmark text-muted';
}

/**
 * Gera um token seguro URL-safe.
 */
function generate_token($bytes = 32) {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}
