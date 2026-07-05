<?php
/**
 * FileCache — cache em arquivo com TTL.
 *
 * Opção mais simples e portável em hospedagem compartilhada: não depende de
 * APCu, Redis ou Memcached. Armazena cada chave num arquivo serializado em
 * `storage/cache/`, com checagem atômica de expiração.
 *
 * Uso:
 *   $value = FileCache::remember('dashboard.stats', 300, fn() => $this->compute());
 *   FileCache::forget('dashboard.stats');
 */
class FileCache
{
    private static ?string $dir = null;

    private static function dir(): string
    {
        if (self::$dir === null) {
            self::$dir = dirname(__DIR__, 2) . '/storage/cache/';
            if (!is_dir(self::$dir)) @mkdir(self::$dir, 0755, true);
        }
        return self::$dir;
    }

    private static function filename(string $key): string
    {
        return self::dir() . hash('sha256', $key) . '.cache';
    }

    /**
     * Retorna o valor em cache ou null se expirado/inexistente.
     */
    public static function get(string $key)
    {
        $file = self::filename($key);
        if (!is_file($file)) return null;

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') return null;

        $payload = @unserialize($raw, ['allowed_classes' => true]);
        if (!is_array($payload) || !isset($payload['expires_at'], $payload['value'])) {
            return null;
        }
        if ($payload['expires_at'] > 0 && $payload['expires_at'] < time()) {
            @unlink($file);
            return null;
        }
        return $payload['value'];
    }

    /**
     * Grava um valor com TTL em segundos (0 = não expira).
     */
    public static function put(string $key, $value, int $ttl = 300): bool
    {
        $payload = [
            'expires_at' => $ttl > 0 ? time() + $ttl : 0,
            'value'      => $value,
        ];
        $file = self::filename($key);
        // Escrita atômica via arquivo temporário no mesmo diretório.
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, serialize($payload), LOCK_EX) === false) {
            return false;
        }
        return @rename($tmp, $file);
    }

    /**
     * Retorna o valor em cache; se ausente, chama o callback, armazena e retorna.
     */
    public static function remember(string $key, int $ttl, callable $callback)
    {
        $cached = self::get($key);
        if ($cached !== null) return $cached;
        $value = $callback();
        self::put($key, $value, $ttl);
        return $value;
    }

    public static function forget(string $key): bool
    {
        $file = self::filename($key);
        return is_file($file) ? @unlink($file) : true;
    }

    /**
     * Limpa entradas expiradas (usar no cron cleanup).
     */
    public static function purgeExpired(): int
    {
        $count = 0;
        $dir = self::dir();
        foreach (glob($dir . '*.cache') ?: [] as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) continue;
            $payload = @unserialize($raw, ['allowed_classes' => false]);
            if (!is_array($payload) ||
                (isset($payload['expires_at']) && $payload['expires_at'] > 0 && $payload['expires_at'] < time())) {
                @unlink($file);
                $count++;
            }
        }
        return $count;
    }
}
