<?php
/**
 * View — renderiza templates dentro do layout UNIFICADO da plataforma.
 *
 * O header/footer legados (navbar + sidebar próprias) foram substituídos
 * por arquivos de COMPATIBILIDADE em views/layout/: o header abre um
 * buffer de captura (e emite as flash messages) e o footer fecha o buffer
 * e delega ao Core\Layout::render(). Assim, tanto View::render() quanto os
 * controllers que fazem require manual de header + view + footer continuam
 * funcionando sem reescrita.
 *
 * Uso típico em um controller:
 *   View::render('employees/index', [
 *       'pageTitle'   => 'Funcionários',
 *       'page'        => 'employees',   // marca o item ativo da sidebar
 *   ]);
 *
 * Para páginas sem layout (print, público, portal do funcionário):
 *   View::renderRaw('my/index', [...]);
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
     * Renderiza um template dentro do layout unificado.
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
     * Renderiza um template sem layout (print, público, portal etc.).
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
