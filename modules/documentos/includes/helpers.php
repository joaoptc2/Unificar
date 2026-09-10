<?php
/**
 * Funções Auxiliares do módulo DOCUMENTOS.
 *
 * url()/asset()/redirect() agora produzem URLs da plataforma
 * (index.php?m=documentos&url=...) e view() renderiza o conteúdo dentro
 * do layout unificado do núcleo (Core\Layout::render).
 */

// ── Navegação ───────────────────────────────────────────────────────────────

/**
 * URL interna do módulo. Aceita o formato legado 'pagina/acao?extra=1'
 * e devolve BASE_URL/index.php?m=documentos&url=pagina/acao&extra=1
 * (querystring extra preservada).
 */
function url($path = '') {
    $path  = ltrim((string) $path, '/');
    $extra = [];
    if (($pos = strpos($path, '?')) !== false) {
        parse_str(substr($path, $pos + 1), $extra);
        $path = substr($path, 0, $pos);
    }
    return core_module_url('documentos', array_merge(['url' => $path], $extra));
}

function redirect($path = '') {
    header('Location: ' . url($path));
    exit;
}

/**
 * URL para asset do módulo, servido de /assets/documentos/.
 * Aceita os caminhos legados 'css/style.css' e 'js/app.js'.
 */
function asset($path) {
    $path = ltrim((string) $path, '/');
    foreach (['css/', 'js/'] as $prefix) {
        if (strpos($path, $prefix) === 0) {
            $path = substr($path, strlen($prefix));
            break;
        }
    }
    // core_asset() já anexa a marca de versão (data do arquivo).
    return core_asset('documentos/' . $path);
}

// ── Views ───────────────────────────────────────────────────────────────────

/**
 * Chave do item ativo do menu lateral, derivada do título da página.
 * Controllers podem sobrescrever passando 'menu_key' em $data.
 * Chaves do menu (module.php): dashboard, documents, documents-uncontrolled,
 * indicators, indicators-actions.
 */
function _doc_active_key($page_title) {
    $map = [
        'Dashboard'                  => 'dashboard',
        'Documentos'                 => 'documents',
        'Documentos controlados'     => 'documents',
        'Documentos não controlados' => 'documents-uncontrolled',
        'Indicadores'                => 'indicators',
        'Templates de Indicadores'   => 'indicators',
        'Planos de Ação'             => 'indicators-actions',
    ];
    return $map[$page_title] ?? '';
}

/**
 * HTML das flash messages do módulo (renderizadas dentro do conteúdo,
 * já que o layout agora é do núcleo).
 */
function _doc_flash_html() {
    $html = '';
    foreach (['success' => 'success', 'error' => 'danger', 'info' => 'info', 'warning' => 'warning'] as $type => $css) {
        if (!has_flash($type)) continue;
        $icon = ['success' => 'check-circle', 'danger' => 'exclamation-circle',
                 'info' => 'info-circle', 'warning' => 'exclamation-triangle'][$css];
        $html .= '<div class="alert alert-' . $css . ' alert-dismissible fade show" role="alert">'
               . '<i class="bi bi-' . $icon . ' me-2"></i>' . get_flash($type)
               . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>'
               . '</div>';
    }
    return $html;
}

/**
 * Setores ativos da unidade para o seletor global (cache por requisição).
 */
function doc_sectors_for_selector() {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $cache = sector_list(get_hospital_id());
    } catch (Exception $ex) {
        $cache = [];
    }
    return $cache;
}

/**
 * Seletor global de setor (topo do conteúdo de TODAS as páginas do módulo):
 * "Todos os setores" (0) + setores ativos da unidade. Faz POST para
 * dashboard/switch-sector e volta para a página atual.
 */
function _doc_sector_selector_html() {
    if (!is_logged_in()) return '';
    $sectors = doc_sectors_for_selector();
    $current = get_sector_id();
    $return  = (string) ($_SERVER['REQUEST_URI'] ?? '');

    $html  = '<div class="doc-sector-bar d-flex flex-wrap align-items-center gap-2 mb-3">';
    $html .= '<form method="POST" action="' . e(url('dashboard/switch-sector')) . '" class="d-flex align-items-center gap-2 flex-wrap">';
    $html .= csrf_field();
    $html .= '<input type="hidden" name="return" value="' . e($return) . '">';
    $html .= '<label class="form-label mb-0 small text-muted" for="doc-sector-select"><i class="bi bi-diagram-3 me-1"></i>Setor em foco:</label>';
    $html .= '<select name="sector_id" id="doc-sector-select" class="form-select form-select-sm" style="min-width:200px;max-width:320px" onchange="this.form.submit()">';
    $html .= '<option value="0"' . ($current === 0 ? ' selected' : '') . '>Todos os setores</option>';
    foreach ($sectors as $s) {
        $label = $s['name'] . (!empty($s['code']) ? ' (' . $s['code'] . ')' : '');
        $html .= '<option value="' . (int) $s['id'] . '"' . ((int) $s['id'] === $current ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $html .= '</select>';
    $html .= '<noscript><button type="submit" class="btn btn-sm btn-outline-primary">Aplicar</button></noscript>';
    $html .= '</form>';
    if ($current > 0) {
        $html .= '<span class="badge bg-primary-subtle text-primary border border-primary-subtle"><i class="bi bi-funnel me-1"></i>Filtrando por: ' . e(get_sector_name()) . '</span>';
    } else {
        $html .= '<span class="text-muted small"><i class="bi bi-info-circle me-1"></i>Sem filtro de setor</span>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Renderiza uma view do módulo dentro do layout unificado do núcleo.
 * $data['menu_key']    → item ativo do menu (opcional)
 * $data['head']/$data['scripts'] → HTML extra no <head>/fim da página
 * $data['no_sector_bar'] → true para não exibir o seletor global de setor
 */
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

    // Seletor global de setor: em todas as páginas do módulo, exceto dentro
    // do painel de configuração da administração central.
    $sector_bar = '';
    if (empty($data['no_sector_bar']) && !Core\Layout::embedded()) {
        $sector_bar = _doc_sector_selector_html();
    }

    Core\Layout::render([
        'title'   => $page_title,
        'content' => _doc_flash_html() . $sector_bar . $content,
        'active'  => $data['menu_key'] ?? _doc_active_key($page_title),
        // Chart.js sempre incluído: dashboard/indicadores usam gráficos
        'head'    => '<link rel="stylesheet" href="' . asset('style.css') . '">' . "\n"
                   . '    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>' . "\n"
                   . (string) ($data['head'] ?? ''),
        'scripts' => '<script src="' . asset('app.js') . '"></script>' . "\n" . (string) ($data['scripts'] ?? ''),
    ]);
}

/**
 * Renderiza uma view sem layout (mantida para telas standalone futuras).
 */
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
    fputcsv($out, $headers, ';', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($out, array_values($row), ';', '"', '\\');
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
 * Preserva todos os parâmetros GET atuais (inclusive m e url), exceto `page`.
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
