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

    /**
     * Limpa o CSS. Devolve '' quando o texto não estabiliza dentro do limite
     * de voltas: entregar um texto "quase limpo" seria pior do que recusar —
     * foi assim que um @import aninhado vinte vezes sobrou inteiro.
     */
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

        $antes  = null;
        $limite = 0;
        while ($antes !== $css && $limite++ < 60) {
            $antes = $css;

            // A regra INTEIRA do @import sai primeiro — inclusive a forma em
            // string, @import "url", que não passa por url(). Se a palavra
            // fosse apagada antes, sobraria o endereço solto no arquivo.
            $css = preg_replace('/@\s*import\b[^;{}]*;?/i', '', $css) ?? $css;

            foreach (self::BLOQUEADOS as $proibido) {
                $css = str_ireplace($proibido, '', $css);
            }

            // url(...) só para caminhos do próprio portal ou dados embutidos
            // de imagem: url externa num CSS público conta o acesso de todo
            // mundo para um terceiro.
            $css = preg_replace_callback(
                '/url\(\s*([\'"]?)([^\'")]*)\1\s*\)/i',
                static fn (array $m): string => self::urlPermitida(trim($m[2]))
                    ? 'url(\'' . trim($m[2]) . '\')'
                    : 'none',
                $css
            ) ?? $css;
        }

        // Nenhum sinal de marcação sobrevive. Em CSS, '<' e '>' só aparecem
        // dentro de texto de content:, onde o escape \3c funciona igual —
        // então escapar é seguro e tira do caminho qualquer tentativa de
        // fazer o arquivo passar por HTML em algum navegador antigo.
        $css = str_replace(['<', '>'], ['\\3c ', '\\3e '], $css);

        // Não estabilizou: o texto foi construído para enganar o filtro
        // (palavra proibida aninhada em si mesma). Recusa tudo.
        if ($antes !== $css) {
            return '';
        }

        return trim($css);
    }

    /**
     * O endereço aponta para dentro da própria instalação?
     *
     * Atenção ao "//host/arquivo": o navegador o resolve como https://host, e
     * ele começa com '/', então uma checagem ingênua o aceitaria — foi o furo
     * encontrado na revisão. Um CSS público carregado em todas as páginas
     * (inclusive a de login) pedindo um arquivo a terceiro entrega o IP e o
     * horário de cada visitante do hospital.
     */
    private static function urlPermitida(string $url): bool
    {
        if ($url === '') {
            return true;
        }
        // Barras invertidas e espaços no começo são truques para contornar a
        // comparação; normaliza antes de decidir.
        $u = ltrim(str_replace('\\', '/', $url));
        if (str_starts_with($u, '//')) {
            return false;   // protocolo-relativo = externo
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $u)) {
            // Tem esquema: só imagem embutida passa.
            return preg_match('#^data:image/(png|jpeg|gif|webp|svg\+xml);base64,#i', $u) === 1;
        }
        return str_starts_with($u, '/')
            || str_starts_with($u, './')
            || str_starts_with($u, '../')
            || str_starts_with($u, 'uploads/')
            || str_starts_with($u, 'assets/')
            || !str_contains($u, '/');   // arquivo ao lado, ex.: url(fundo.png)
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
