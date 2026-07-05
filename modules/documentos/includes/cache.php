<?php
/**
 * Cache em arquivo (friendly para hospedagem compartilhada sem APCu/Redis).
 * TTL por chave, invalidação manual.
 */

function _cache_path($key) {
    $hash = hash('sha256', $key);
    return CACHE_PATH . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache';
}

function _cache_ensure_dir($path) {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

/**
 * Obtém valor do cache ou null se expirado/inexistente.
 */
function cache_get($key) {
    $path = _cache_path($key);
    if (!is_file($path)) return null;

    $raw = @file_get_contents($path);
    if ($raw === false) return null;

    $data = @unserialize($raw);
    if (!is_array($data) || !isset($data['expires'], $data['value'])) return null;
    if ($data['expires'] > 0 && $data['expires'] < time()) {
        @unlink($path);
        return null;
    }
    return $data['value'];
}

/**
 * Armazena valor no cache.
 * @param int $ttl Segundos. 0 = sem expiração.
 */
function cache_set($key, $value, $ttl = 300) {
    $path = _cache_path($key);
    _cache_ensure_dir($path);
    $data = [
        'expires' => $ttl > 0 ? time() + $ttl : 0,
        'value'   => $value,
    ];
    return @file_put_contents($path, serialize($data), LOCK_EX) !== false;
}

/**
 * Remove uma entrada.
 */
function cache_forget($key) {
    $path = _cache_path($key);
    if (is_file($path)) @unlink($path);
}

/**
 * Memoiza: busca no cache, senão executa callback.
 */
function cache_remember($key, $ttl, callable $cb) {
    $hit = cache_get($key);
    if ($hit !== null) return $hit;
    $value = $cb();
    cache_set($key, $value, $ttl);
    return $value;
}

/**
 * Limpa todo o cache.
 */
function cache_flush() {
    if (!is_dir(CACHE_PATH)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(CACHE_PATH, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        if ($f->isDir()) @rmdir($f->getPathname());
        else @unlink($f->getPathname());
    }
}
