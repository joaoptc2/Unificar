<?php
/**
 * Autoloader simples no estilo PSR-4 (sem Composer).
 *
 * Estrutura de diretórios:
 *   app/
 *     controllers/{Nome}Controller.php
 *     helpers/{Nome}.php
 *     models/{Nome}.php
 *
 * Uso: chamar Autoloader::register() no bootstrap.
 */
class Autoloader
{
    /** Mapeamento de diretórios a escanear em ordem de prioridade. */
    private static array $paths = [];

    public static function register(): void
    {
        $base = dirname(__DIR__);
        self::$paths = [
            $base . '/helpers/',
            $base . '/models/',
            $base . '/controllers/',
        ];
        spl_autoload_register([self::class, 'load']);
    }

    public static function load(string $class): void
    {
        // Ignora classes de namespaces de terceiros ou PHP internas.
        if (strpos($class, '\\') !== false) return;

        foreach (self::$paths as $path) {
            $file = $path . $class . '.php';
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    }
}
