<?php
/**
 * Helpers globais do núcleo.
 * Todos prefixados com core_ para não colidir com funções dos módulos
 * legados (que definem redirect(), url(), e(), etc.).
 */

declare(strict_types=1);

function core_config(string $key, mixed $default = null): mixed
{
    return Core\Config::get($key, $default);
}

/** URL absoluta a partir da raiz da plataforma. */
function core_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return BASE_URL . ($path !== '' ? '/' . $path : '');
}

/** URL para uma rota de módulo: core_module_url('chat', ['page' => 'tasks']) */
function core_module_url(string $module, array $params = []): string
{
    $query = http_build_query(array_merge(['m' => $module], $params));
    return BASE_URL . '/index.php?' . $query;
}

/**
 * URL de um arquivo de assets/ com marca de versão (data da última
 * modificação): o .htaccess pede cache longo para CSS/JS, e a marca
 * garante que uma atualização do sistema chegue ao navegador.
 */
function core_asset(string $path): string
{
    $path = ltrim($path, '/');
    $url  = BASE_URL . '/assets/' . $path;

    static $stamps = [];
    if (!array_key_exists($path, $stamps)) {
        $file = BASE_PATH . '/assets/' . $path;
        $stamps[$path] = is_file($file) ? (string) filemtime($file) : '';
    }
    if ($stamps[$path] === '') {
        return $url;
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $stamps[$path];
}

function core_redirect(string $url): never
{
    if (!preg_match('#^https?://#i', $url)) {
        $url = core_url($url);
    }
    header('Location: ' . $url);
    exit;
}

/** Escape para HTML. */
function core_e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function core_user(): ?array
{
    return Core\Auth::user();
}

function core_user_id(): ?int
{
    return Core\Auth::id();
}

/** LEGADO: nível de acesso do usuário logado no módulo (ou 'none'). */
function core_module_role(string $module): string
{
    $id = Core\Auth::id();
    return $id ? Core\Access::roleFor($id, $module) : 'none';
}

/**
 * O usuário logado tem a micropermissão? core_can('documents.edit')
 * usa o módulo atual; core_can('documents.edit', 'documentos') é explícito.
 */
function core_can(string $permKey, ?string $module = null): bool
{
    $id = Core\Auth::id();
    if ($id === null) {
        return false;
    }
    $module ??= defined('MODULE_SLUG') ? MODULE_SLUG : null;
    if ($module === null) {
        return false;
    }
    return Core\Perms::can($id, $module, $permKey);
}

/** Interrompe com 403 se o usuário logado não tem a micropermissão. */
function core_require(string $permKey, ?string $module = null): void
{
    $module ??= defined('MODULE_SLUG') ? MODULE_SLUG : '';
    Core\Perms::require($module, $permKey);
}

/** true se o usuário logado tem QUALQUER uma das micropermissões. */
function core_can_any(array $permKeys, ?string $module = null): bool
{
    foreach ($permKeys as $key) {
        if (core_can((string) $key, $module)) {
            return true;
        }
    }
    return false;
}

/** Interrompe com 403 se o usuário não tem NENHUMA das micropermissões. */
function core_require_any(array $permKeys, ?string $module = null): void
{
    if (!core_can_any($permKeys, $module)) {
        core_require((string) ($permKeys[0] ?? ''), $module);
    }
}

/**
 * URL do painel de configuração de um módulo na administração central:
 * core_admin_url('documentos', 'sectors') → index.php?m=admin&a=module&slug=documentos&tab=sectors
 */
function core_admin_url(string $module, string $tab = '', array $extra = []): string
{
    return Core\AdminPanel::url($module, $tab, $extra);
}

/** Aba ativa do painel de módulo na administração central (ou null fora dele). */
function core_admin_tab(): ?string
{
    return defined('CORE_ADMIN_TAB') ? CORE_ADMIN_TAB : null;
}
