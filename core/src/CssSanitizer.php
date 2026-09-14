<?php

declare(strict_types=1);

namespace Core;

/**
 * Filtro do CSS livre do administrador (Administração > Aparência).
 *
 * O CSS é servido por uma rota própria com Content-Type: text/css
 * (index.php?m=admin&a=brand_css), e não embutido num <style> da página.
 * Isso já elimina por construção o ataque clássico — fechar o <style> e
 * abrir um <script>. O filtro aqui é a segunda camada, contra o que CSS
 * consegue fazer sozinho: buscar recurso externo (vazando quem acessou o
 * portal e quando), executar expressão em navegadores antigos, ou trocar o
 * comportamento de um elemento por um arquivo de comportamento.
 *
 * O laço roda até o texto parar de mudar. Um filtro de passe único é
 * contornável escondendo a palavra dentro dela mesma: `@@importimport`
 * vira `@import` depois de uma única remoção.
 */
final class CssSanitizer
{
    /** Trechos que nunca passam, mesmo escritos de forma torta. */
    private const BLOQUEADOS = [
        '</style',
        '<script',
        '<!--',
        '-->',
        '@import',
        'expression(',
        'javascript:',
        'vbscript:',
        'behavior:',
        '-moz-binding',
        'data:text/html',
    ];

    public static function clean(string $css): string
    {
        if (trim($css) === '') {
            return '';
        }
        // Normaliza escapes CSS (\40 import, \0040, etc.), que existem
        // justamente para escrever a mesma palavra de outro jeito.
        $css = preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', static function (array $m): string {
            $code = hexdec($m[1]);
            return $code > 0 && $code < 0x110000 ? mb_chr($code, 'UTF-8') : '';
        }, $css) ?? $css;

        // Remove caracteres de controle e o nulo (usados para partir palavras).
        $css = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $css) ?? $css;

        $antes = null;
        $limite = 0;
        while ($antes !== $css && $limite++ < 20) {
            $antes = $css;
            foreach (self::BLOQUEADOS as $proibido) {
                $css = str_ireplace($proibido, '', $css);
            }
            // url(...) só para caminhos do próprio portal ou dados embutidos
            // de imagem: url externa num CSS público conta o acesso de todo
            // mundo para um terceiro.
            $css = preg_replace_callback(
                '/url\(\s*([\'"]?)([^\'")]*)\1\s*\)/i',
                static function (array $m): string {
                    $url = trim($m[2]);
                    $ok = $url === ''
                        || str_starts_with($url, '/')
                        || str_starts_with($url, './')
                        || str_starts_with($url, '../')
                        || str_starts_with($url, 'uploads/')
                        || preg_match('#^data:image/(png|jpeg|gif|webp|svg\+xml);base64,#i', $url) === 1;
                    return $ok ? 'url(\'' . $url . '\')' : 'none';
                },
                $css
            ) ?? $css;
        }

        // Nenhum sinal de marcação sobrevive. Em CSS, '<' e '>' só aparecem
        // dentro de texto de content:, onde o escape \3c funciona igual —
        // então escapar é seguro e tira do caminho qualquer tentativa de
        // fazer o arquivo passar por HTML em algum navegador antigo.
        $css = str_replace(['<', '>'], ['\\3c ', '\\3e '], $css);

        return trim($css);
    }

    /**
     * O CSS tem chance de ter sido alterado pelo filtro? Serve para a tela
     * avisar o administrador em vez de mudar o texto dele em silêncio.
     */
    public static function wouldChange(string $css): bool
    {
        return self::clean($css) !== trim($css);
    }
}
