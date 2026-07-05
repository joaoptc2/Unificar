<?php
/**
 * View — renderiza templates com o layout padrão (header + footer).
 *
 * Elimina a triplicata:
 *   require __DIR__ . '/../views/layout/header.php';
 *   require __DIR__ . '/../views/{modulo}/{tpl}.php';
 *   require __DIR__ . '/../views/layout/footer.php';
 *
 * Uso típico em um controller:
 *   View::render('employees/index', [
 *       'pageTitle'   => 'Funcionários',
 *       'page'        => 'employees',
 *       'employees'   => $employees,
 *       'pagination'  => $pagination,
 *   ]);
 *
 * Para páginas sem layout padrão (ex.: login, print, público):
 *   View::renderRaw('auth/login', ['error' => $error]);
 */
class View
{
    private static string $viewsDir;

    private static function init(): void
    {
        if (!isset(self::$viewsDir)) {
            self::$viewsDir = realpath(__DIR__ . '/../views') . '/';
        }
    }

    /**
     * Renderiza um template dentro do layout padrão.
     *
     * @param string              $template  Caminho relativo sem .php (ex.: 'employees/index')
     * @param array<string,mixed> $data      Variáveis expostas no template
     */
    public static function render(string $template, array $data = []): void
    {
        self::init();
        extract($data, EXTR_SKIP);
        require self::$viewsDir . 'layout/header.php';
        require self::resolve($template);
        require self::$viewsDir . 'layout/footer.php';
    }

    /**
     * Renderiza um template sem layout (login, print, público etc.).
     */
    public static function renderRaw(string $template, array $data = []): void
    {
        self::init();
        extract($data, EXTR_SKIP);
        require self::resolve($template);
    }

    /**
     * Renderiza um template e retorna como string.
     */
    public static function capture(string $template, array $data = []): string
    {
        self::init();
        extract($data, EXTR_SKIP);
        ob_start();
        require self::resolve($template);
        return (string)ob_get_clean();
    }

    private static function resolve(string $template): string
    {
        $file = self::$viewsDir . ltrim($template, '/') . '.php';
        $real = realpath($file);
        if (!$real || !str_starts_with($real, self::$viewsDir)) {
            throw new RuntimeException('View não encontrada: ' . $template);
        }
        return $real;
    }
}
