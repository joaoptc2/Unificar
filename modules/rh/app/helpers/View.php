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
 *
 * Por que os parâmetros se chamam $__view/$__data
 * ----------------------------------------------
 * extract() despeja as chaves de $__data como variáveis locais, no MESMO
 * escopo dos parâmetros do método. Enquanto o parâmetro se chamava
 * $template, quem passasse 'template' => [...] (o OnboardingController
 * passava) caía num de dois buracos, os dois silenciosos:
 *
 *   • com EXTR_SKIP, extract() preserva o que já existe — a view recebia o
 *     CAMINHO da view no lugar dos dados, e morria com "Cannot access
 *     offset of type string on string";
 *   • sem EXTR_SKIP seria pior: o dado sobrescreveria o caminho e passaria
 *     a decidir qual arquivo é incluído.
 *
 * O prefixo __ tira esses nomes do espaço que as views usam, e o caminho é
 * resolvido antes do extract para que nem uma colisão futura possa desviar
 * o include.
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
    public static function render(string $__view, array $__data = []): void
    {
        self::init();
        // O caminho é resolvido ANTES do extract: assim nenhuma chave de
        // $__data pode trocar qual arquivo será incluído.
        $__file = self::resolve($__view);
        extract($__data, EXTR_SKIP);
        require self::$viewsDir . 'layout/header.php';
        require $__file;
        require self::$viewsDir . 'layout/footer.php';
    }

    /**
     * Renderiza um template sem layout (print, público, portal etc.).
     */
    public static function renderRaw(string $__view, array $__data = []): void
    {
        self::init();
        $__file = self::resolve($__view);
        extract($__data, EXTR_SKIP);
        require $__file;
    }

    /**
     * Renderiza um template e retorna como string.
     */
    public static function capture(string $__view, array $__data = []): string
    {
        self::init();
        $__file = self::resolve($__view);
        extract($__data, EXTR_SKIP);
        ob_start();
        require $__file;
        return (string)ob_get_clean();
    }

    private static function resolve(string $__view): string
    {
        $file = self::$viewsDir . ltrim($__view, '/') . '.php';
        $real = realpath($file);
        if (!$real || !str_starts_with($real, self::$viewsDir)) {
            throw new RuntimeException('View não encontrada: ' . $__view);
        }
        return $real;
    }
}
