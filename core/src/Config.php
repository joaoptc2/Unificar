<?php

declare(strict_types=1);

namespace Core;

final class Config
{
    private static array $data = [];

    public static function load(array $config): void
    {
        self::$data = $config;
    }

    /** Acesso por notação de ponto: Config::get('db.host'). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $node = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public static function all(): array
    {
        return self::$data;
    }
}
