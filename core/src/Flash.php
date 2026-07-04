<?php

declare(strict_types=1);

namespace Core;

/** Mensagens flash do núcleo (chave própria, não colide com os módulos). */
final class Flash
{
    public static function set(string $type, string $message): void
    {
        $_SESSION['_core_flash'][$type][] = $message;
    }

    /** @return array<string, string[]> */
    public static function pull(): array
    {
        $all = $_SESSION['_core_flash'] ?? [];
        unset($_SESSION['_core_flash']);
        return $all;
    }
}
