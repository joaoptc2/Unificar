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

function core_asset(string $path): string
{
    return BASE_URL . '/assets/' . ltrim($path, '/');
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

/** Nível de acesso do usuário logado no módulo (ou 'none'). */
function core_module_role(string $module): string
{
    $id = Core\Auth::id();
    return $id ? Core\Access::roleFor($id, $module) : 'none';
}
