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

    /**
     * A chave existe com valor (não nulo)?
     *
     * Diferente de get() !== null quando quem chama precisa distinguir
     * "gravado como vazio" de "nunca gravado" — é o caso da configuração de
     * e-mail, onde vazio significa "sem usuário/senha" e ausente significa
     * "herda do config/config.php".
     */
    public static function has(string $key): bool
    {
        self::get($key); // aquece o cache
        return isset(self::$cache[$key]);
    }

    /** Apaga a chave (volta a herdar o padrão de quem lê). */
    public static function forget(string $key): void
    {
        DB::execute('DELETE FROM settings WHERE setting_key = ?', [$key]);
        if (self::$cache !== null) {
            unset(self::$cache[$key]);
        }
    }

    /** Limpa o cache por requisição (usado depois de gravar em lote). */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
