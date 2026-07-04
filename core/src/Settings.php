<?php

declare(strict_types=1);

namespace Core;

/** Configurações persistidas do núcleo (tabela settings). */
final class Settings
{
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            try {
                $rows = DB::query('SELECT setting_key, setting_value FROM settings');
                self::$cache = array_column($rows, 'setting_value', 'setting_key');
            } catch (\Throwable) {
                self::$cache = [];
            }
        }
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        DB::execute(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$key, $value]
        );
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }
}
