<?php

declare(strict_types=1);

namespace Core;

/**
 * Tokens de aparência: a base compartilhada de TODA superfície do portal.
 *
 * Por que existe
 * --------------
 * A personalização cresceu por camadas — identidade do portal (Branding),
 * papel timbrado (DocLayout), casca dos e-mails (MailTemplate), cartaz de
 * aniversário, etiqueta de equipamento. Cada uma resolveu os mesmos
 * problemas por conta própria: validar uma cor, limitar um número, decidir
 * a cor do texto sobre um fundo. Três validadores de cor ligeiramente
 * diferentes é um convite a um bug que só aparece numa das telas.
 *
 * Aqui mora UMA implementação de cada coisa:
 *
 *   • normalização   — cor, número com faixa, opção de lista, texto, booleano;
 *   • matemática de cor — mistura, clareia, luminância, contraste WCAG;
 *   • herança        — um token vazio numa peça herda do módulo, e o do
 *                      módulo herda da identidade do portal.
 *
 * A herança é o que faz "mudei a cor do hospital" valer em todo lugar sem
 * ninguém precisar repetir a cor em cinco telas — e, ao mesmo tempo, deixa
 * o cartaz do RH ter o seu rosa sem quebrar o resto.
 *
 * Branding continua sendo a fonte da identidade do portal e mantém a sua
 * API pública; internamente ele delega a conta para cá.
 */
final class Tokens
{
    /**
     * De onde cada escopo herda quando o próprio valor está vazio.
     * O caminho é sempre escopo → ... → marca do portal.
     */
    public const HERANCA = [
        'mail'      => 'brand',   // casca dos e-mails
        'print'     => 'brand',   // papel timbrado, cartazes, etiquetas
        'birthday'  => 'print',   // cartaz de aniversário herda da impressão
        'label'     => 'print',   // etiqueta de equipamento idem
    ];

    /** Tokens de marca que servem de origem para os demais escopos. */
    public const RAIZ = [
        'primary'    => 'primary',
        'accent'     => 'accent',
        'text'       => 'text',
        'surface'    => 'surface',
        'body_bg'    => 'body_bg',
        'font'       => 'font',
        'radius'     => 'radius',
    ];

    // ══════════════════════════════════════════════════════════════════
    //  Normalização — a única porta de entrada de valor vindo de fora
    // ══════════════════════════════════════════════════════════════════

    /** É uma cor hexadecimal (#abc ou #aabbcc)? */
    public static function isColor(string $value): bool
    {
        return (bool) preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim($value));
    }

    /**
     * Cor normalizada em #rrggbb minúsculo. Valor inválido devolve o
     * $fallback — nunca o texto original, porque o resultado é colado
     * dentro de um atributo style= e viraria CSS.
     */
    public static function color(string $value, string $fallback = ''): string
    {
        $v = trim($value);
        if (!self::isColor($v)) {
            return $fallback;
        }
        $v = strtolower($v);
        if (strlen($v) === 4) {                       // #abc → #aabbcc
            return '#' . $v[1] . $v[1] . $v[2] . $v[2] . $v[3] . $v[3];
        }
        return $v;
    }

    /** Número inteiro preso a uma faixa. */
    public static function int(mixed $value, int $min, int $max, int $default): int
    {
        if (is_string($value) && trim($value) === '') {
            return $default;
        }
        $n = (int) $value;
        if ($n === 0 && !is_numeric($value)) {
            return $default;
        }
        return max($min, min($max, $n));
    }

    /** Opção que precisa estar numa lista conhecida. */
    public static function choice(string $value, array $permitidos, string $default): string
    {
        $v = trim($value);
        // Aceita tanto ['a','b'] quanto ['a' => 'Rótulo', ...]
        $chaves = array_is_list($permitidos) ? $permitidos : array_keys($permitidos);
        return in_array($v, $chaves, true) ? $v : $default;
    }

    /** Booleano guardado como '1'/'0' em settings. */
    public static function flag(mixed $value): string
    {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'sim'], true) ? '1' : '0';
        }
        return !empty($value) ? '1' : '0';
    }

    /** Texto simples, sem marcação e sem quebra de linha, com limite. */
    public static function text(string $value, int $max = 200): string
    {
        $v = preg_replace('/[\r\n\t]+/', ' ', strip_tags($value)) ?? '';
        return mb_substr(trim($v), 0, $max);
    }

    // ══════════════════════════════════════════════════════════════════
    //  Matemática de cor
    // ══════════════════════════════════════════════════════════════════

    /** @return array{0:int,1:int,2:int} */
    public static function rgb(string $hex): array
    {
        $h = ltrim(trim($hex), '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        if (strlen($h) !== 6 || !ctype_xdigit($h)) {
            $h = '0d5c8f';
        }
        return [
            (int) hexdec(substr($h, 0, 2)),
            (int) hexdec(substr($h, 2, 2)),
            (int) hexdec(substr($h, 4, 2)),
        ];
    }

    public static function rgbTriplet(string $hex): string
    {
        return implode(', ', self::rgb($hex));
    }

    /** Clareia (fator > 0) ou escurece (fator < 0) uma cor. */
    public static function shade(string $hex, float $factor): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $f = static function (int $c) use ($factor): int {
            $alvo = $factor > 0 ? 255 : 0;
            return (int) round($c + ($alvo - $c) * abs($factor));
        };
        return sprintf('#%02x%02x%02x', $f($r), $f($g), $f($b));
    }

    /** Mistura duas cores; $p = 0 devolve $a, $p = 1 devolve $b. */
    public static function mix(string $a, string $b, float $p): string
    {
        $p = max(0.0, min(1.0, $p));
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);
        return sprintf('#%02x%02x%02x',
            (int) round($r1 + ($r2 - $r1) * $p),
            (int) round($g1 + ($g2 - $g1) * $p),
            (int) round($b1 + ($b2 - $b1) * $p));
    }

    /** Luminância relativa (0 escuro … 1 claro). */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);
        $f = static function (int $c): float {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }

    /** Razão de contraste da WCAG entre duas cores (1:1 a 21:1). */
    public static function contrastRatio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Cor de texto legível sobre $hex. Decide pela razão de contraste, e
     * não pelo brilho médio — o brilho erra em tons como o âmbar, onde o
     * branco fica ilegível apesar de a cor "parecer" escura.
     */
    public static function contrastColor(string $hex): string
    {
        $bg    = self::luminance($hex);
        $claro = (max($bg, 1.0) + 0.05) / (min($bg, 1.0) + 0.05);
        $escL  = self::luminance('#1f2937');
        $esc   = (max($bg, $escL) + 0.05) / (min($bg, $escL) + 0.05);
        return $esc > $claro ? '#1f2937' : '#ffffff';
    }

    /** O fundo é escuro (ou seja: sobre ele o texto legível é claro)? */
    public static function isDark(string $hex): bool
    {
        return self::contrastColor($hex) === '#ffffff';
    }

    /**
     * Clareia $hex o quanto for preciso para atingir $alvo de contraste
     * sobre $sobre. Usado onde a cor escolhida precisa continuar legível
     * num fundo que o administrador também escolheu.
     */
    public static function lightenFor(string $hex, string $sobre, float $alvo = 4.5): string
    {
        $cor = self::color($hex, '#0d5c8f');
        if (self::contrastRatio($cor, $sobre) >= $alvo) {
            return $cor;
        }
        $paraClaro = self::isDark($sobre);
        for ($i = 1; $i <= 20; $i++) {
            $tent = self::shade($cor, ($paraClaro ? 1 : -1) * ($i * 0.05));
            if (self::contrastRatio($tent, $sobre) >= $alvo) {
                return $tent;
            }
        }
        return $paraClaro ? '#ffffff' : '#1f2937';
    }

    // ══════════════════════════════════════════════════════════════════
    //  Contraste para a interface (o selo ao lado do seletor de cor)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Avalia um par texto/fundo pelas réguas da WCAG 2.1.
     *
     * AA exige 4,5:1 para texto normal e 3:1 para texto grande (≥ 18,66px
     * em negrito ou ≥ 24px). AAA exige 7:1. O nível devolvido é o que a
     * tela pinta: 'ok' passa em AA, 'aviso' passa só em texto grande,
     * 'erro' não passa em nada.
     *
     * @return array{ratio:float, texto:string, aa:bool, aa_large:bool,
     *                aaa:bool, nivel:string, resumo:string}
     */
    public static function contrastReport(string $fg, string $bg): array
    {
        $r = self::contrastRatio(self::color($fg, '#000000'), self::color($bg, '#ffffff'));
        $aa      = $r >= 4.5;
        $aaLarge = $r >= 3.0;
        $aaa     = $r >= 7.0;

        $nivel = $aa ? 'ok' : ($aaLarge ? 'aviso' : 'erro');
        $resumo = match ($nivel) {
            'ok'    => $aaa ? 'AAA' : 'AA',
            'aviso' => 'AA só em texto grande',
            default => 'não legível',
        };

        return [
            'ratio'    => round($r, 2),
            'texto'    => number_format($r, 1, ',', '.') . ':1',
            'aa'       => $aa,
            'aa_large' => $aaLarge,
            'aaa'      => $aaa,
            'nivel'    => $nivel,
            'resumo'   => $resumo,
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  Herança de tokens
    // ══════════════════════════════════════════════════════════════════

    /**
     * Valor efetivo de um token, subindo a cadeia de herança quando o
     * próprio está vazio.
     *
     *   Tokens::resolve('mail', 'primary', $cfg['header_bg'])
     *     → a cor gravada na casca do e-mail; se vazia, a primária da marca.
     *
     * $proprio é o valor da peça (o que o administrador digitou ali).
     */
    public static function resolve(string $escopo, string $chave, string $proprio = ''): string
    {
        $v = trim($proprio);
        if ($v !== '') {
            return $v;
        }
        // Sobe até a marca. O laço tem teto por segurança: uma herança
        // circular mal configurada não pode travar a página.
        $atual = $escopo;
        for ($i = 0; $i < 5; $i++) {
            $pai = self::HERANCA[$atual] ?? 'brand';
            if ($pai === 'brand') {
                return self::brand($chave);
            }
            $v = trim((string) Settings::get($pai . '.' . $chave, ''));
            if ($v !== '') {
                return $v;
            }
            $atual = $pai;
        }
        return self::brand($chave);
    }

    /** Token da identidade do portal (a raiz de toda herança). */
    public static function brand(string $chave): string
    {
        return match ($chave) {
            'primary'  => Branding::get('primary', '#0d5c8f'),
            'accent'   => Branding::get('accent', '#0f9d8f'),
            'text'     => '#212529',
            'surface'  => '#ffffff',
            'body_bg'  => Branding::get('body_bg', '#f4f6f9'),
            'font'     => Branding::fontStack(),
            'radius'   => Branding::get('radius', '8'),
            default    => Branding::get($chave, ''),
        };
    }

    /**
     * Paleta de impressão: as cores que qualquer papel gerado pelo portal
     * usa (documento, cartaz, etiqueta). Sai da identidade, mas pode ser
     * sobrescrita no escopo 'print'.
     *
     * @return array{primary:string, on_primary:string, text:string,
     *                muted:string, border:string, surface:string, font:string}
     */
    public static function printPalette(array $override = []): array
    {
        $primary = self::color((string) ($override['primary'] ?? ''), self::resolve('print', 'primary'));
        $text    = self::color((string) ($override['text'] ?? ''), self::resolve('print', 'text'));
        $surface = self::color((string) ($override['surface'] ?? ''), '#ffffff');

        return [
            'primary'    => $primary,
            'on_primary' => self::contrastColor($primary),
            'text'       => $text,
            // Cinza derivado do texto: acompanha um texto escolhido escuro
            // ou claro sem virar um cinza fixo que some no papel.
            'muted'      => self::mix($text, $surface, 0.45),
            'border'     => self::mix($text, $surface, 0.85),
            'surface'    => $surface,
            'font'       => (string) ($override['font'] ?? self::resolve('print', 'font')),
        ];
    }
}
