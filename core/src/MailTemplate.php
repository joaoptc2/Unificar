<?php

declare(strict_types=1);

namespace Core;

/**
 * Casca (layout) dos e-mails do portal.
 *
 * Antes cada remetente montava o seu HTML do zero — comunicado do RH,
 * redefinição de senha, aviso de vencimento —, e o hospital não tinha como
 * mudar a aparência de nada disso sem mexer em código, nem garantir que os
 * e-mails se parecessem entre si.
 *
 * Aqui mora UMA casca: cabeçalho com logo e cor, o corpo que cada remetente
 * escreve, e rodapé com assinatura e aviso. A configuração fica em
 * Administração > E-mail > Layout.
 *
 * Regras de HTML para e-mail (não é página web):
 *   • estilo INLINE, porque Gmail e Outlook descartam <style> com frequência;
 *   • tabelas no lugar de flex/grid, que o Outlook (motor do Word) não faz;
 *   • largura fixa em pixels, sem unidades relativas;
 *   • imagem por URL absoluta — o e-mail é lido fora do portal.
 */
final class MailTemplate
{
    public const DEFAULTS = [
        'enabled'      => '1',
        'header_bg'    => '',            // vazio = cor da marca
        'header_text'  => '',            // vazio = calculada por contraste
        'show_logo'    => '1',
        'show_name'    => '1',
        'body_bg'      => '#f3f5f8',
        'card_bg'      => '#ffffff',
        'text_color'   => '#222222',
        'link_color'   => '',            // vazio = cor da marca
        'font'         => 'Arial, Helvetica, sans-serif',
        'width'        => '640',
        'radius'       => '12',
        'signature'    => '',            // vazio = nome da organização
        'footer_note'  => 'Esta é uma mensagem automática do portal. Não responda a este e-mail.',
        'footer_extra' => '',
    ];

    public static function all(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $k => $padrao) {
            $out[$k] = (string) Settings::get('mail.layout.' . $k, $padrao);
        }
        return $out;
    }

    public static function get(string $key, ?array $cfg = null): string
    {
        $cfg ??= self::all();
        return (string) ($cfg[$key] ?? self::DEFAULTS[$key] ?? '');
    }

    /** O layout está ligado? Desligado, o corpo sai como o remetente escreveu. */
    public static function enabled(?array $cfg = null): bool
    {
        return self::get('enabled', $cfg) === '1';
    }

    public static function save(array $values): void
    {
        foreach (self::DEFAULTS as $k => $padrao) {
            if (!array_key_exists($k, $values)) {
                continue;
            }
            Settings::set('mail.layout.' . $k, self::normalize($k, (string) $values[$k]));
        }
        Settings::flush();
    }

    public static function reset(): void
    {
        foreach (array_keys(self::DEFAULTS) as $k) {
            Settings::forget('mail.layout.' . $k);
        }
        Settings::flush();
    }

    /**
     * Valida campo a campo. É a única porta de entrada: o que sai daqui é
     * colado dentro de um atributo style=, então um valor solto viraria CSS.
     */
    public static function normalize(string $key, string $raw): string
    {
        $v = trim($raw);
        return match ($key) {
            'enabled', 'show_logo', 'show_name' => $v === '1' ? '1' : '0',
            'header_bg', 'header_text', 'link_color' => self::cor($v, ''),
            'body_bg'    => self::cor($v, self::DEFAULTS['body_bg']),
            'card_bg'    => self::cor($v, self::DEFAULTS['card_bg']),
            'text_color' => self::cor($v, self::DEFAULTS['text_color']),
            'width'      => (string) Tokens::int($v, 320, 900, 640),
            'radius'     => (string) Tokens::int($v, 0, 24, (int) self::DEFAULTS['radius']),
            'font'       => self::fonte($v),
            'signature'  => mb_substr(strip_tags($v), 0, 120),
            'footer_note', 'footer_extra' => mb_substr(HtmlSanitizer::clean($v), 0, 500),
            default      => mb_substr($v, 0, 255),
        };
    }

    /** Validação de cor: uma implementação só, em Core\Tokens. */
    private static function cor(string $v, string $default): string
    {
        return Tokens::color($v, $default);
    }

    /** Só pilhas de fontes conhecidas: o valor vai direto para font-family. */
    private static function fonte(string $v): string
    {
        $permitidas = self::fonts();
        return isset($permitidas[$v]) ? $v : self::DEFAULTS['font'];
    }

    /** Pilhas de fontes seguras para e-mail (presentes nos clientes). */
    public static function fonts(): array
    {
        return [
            'Arial, Helvetica, sans-serif'        => 'Arial',
            "'Segoe UI', Arial, sans-serif"       => 'Segoe UI',
            'Verdana, Geneva, sans-serif'         => 'Verdana',
            'Tahoma, Geneva, sans-serif'          => 'Tahoma',
            "'Trebuchet MS', Arial, sans-serif"   => 'Trebuchet MS',
            'Georgia, Times, serif'               => 'Georgia',
            "'Times New Roman', Times, serif"     => 'Times New Roman',
            "'Courier New', Courier, monospace"   => 'Courier New',
        ];
    }

    // ── Montagem ───────────────────────────────────────────────────────────

    /** Cor do cabeçalho: a configurada ou a da marca. */
    public static function headerBg(?array $cfg = null): string
    {
        // Vazio herda: escopo 'mail' → marca do portal (Core\Tokens).
        return Tokens::resolve('mail', 'primary', self::get('header_bg', $cfg));
    }

    public static function headerText(?array $cfg = null): string
    {
        $c = self::get('header_text', $cfg);
        return $c !== '' ? $c : Tokens::contrastColor(self::headerBg($cfg));
    }

    public static function linkColor(?array $cfg = null): string
    {
        return Tokens::resolve('mail', 'primary', self::get('link_color', $cfg));
    }

    /**
     * Envolve o corpo na casca. $assunto vira o título do cabeçalho.
     *
     * $opts: 'preheader' (texto de prévia na caixa de entrada),
     *        'cta' => ['label' => ..., 'url' => ...],
     *        'accent' (cor do cabeçalho só desta mensagem — usada pelos
     *        comunicados urgentes do RH, que já tinham cor própria).
     */
    public static function wrap(string $assunto, string $corpoHtml, array $opts = [], ?array $cfg = null): string
    {
        $cfg ??= self::all();

        // Corpo já completo (<html>) ou layout desligado: não mexe. Envolver
        // um documento inteiro em outro produz e-mail quebrado.
        if (!self::enabled($cfg) || stripos($corpoHtml, '<html') !== false) {
            return $corpoHtml;
        }

        $largura = (int) self::get('width', $cfg);
        $raio    = (int) self::get('radius', $cfg);
        $fonte   = self::get('font', $cfg);
        $bodyBg  = self::get('body_bg', $cfg);
        $cardBg  = self::get('card_bg', $cfg);
        $texto   = self::get('text_color', $cfg);
        $hdrBg   = isset($opts['accent']) && $opts['accent'] !== ''
            ? self::cor((string) $opts['accent'], self::headerBg($cfg))
            : self::headerBg($cfg);
        $hdrTxt  = isset($opts['accent']) && $opts['accent'] !== ''
            ? Tokens::contrastColor($hdrBg)
            : self::headerText($cfg);
        $link    = self::linkColor($cfg);
        $org     = Branding::name();
        $assinat = self::get('signature', $cfg) ?: $org;

        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');

        // Prévia: o trecho que os aplicativos mostram ao lado do assunto.
        // Fica escondido no corpo, e é a diferença entre "…" e uma frase útil.
        $preheader = '';
        if (!empty($opts['preheader'])) {
            $preheader = '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;height:0;width:0">'
                       . $e((string) $opts['preheader']) . '</div>';
        }

        $cabecalho = '';
        if (self::get('show_logo', $cfg) === '1') {
            $logo = Branding::logoUrl();
            if ($logo !== '') {
                $cabecalho .= '<img src="' . $e(core_url($logo)) . '" alt="" height="36" style="display:block;height:36px;max-height:36px;border:0;margin:0 0 8px">';
            }
        }
        if (self::get('show_name', $cfg) === '1') {
            $cabecalho .= '<div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.85">' . $e($org) . '</div>';
        }
        $cabecalho .= '<h1 style="margin:6px 0 0;font-size:22px;line-height:1.3;font-weight:bold">' . $e($assunto) . '</h1>';

        $cta = '';
        if (!empty($opts['cta']['url']) && !empty($opts['cta']['label'])) {
            $cta = '<p style="margin:24px 0 0">'
                 . '<a href="' . $e((string) $opts['cta']['url']) . '" style="display:inline-block;background:' . $hdrBg
                 . ';color:' . $hdrTxt . ';text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:bold">'
                 . $e((string) $opts['cta']['label']) . '</a></p>';
        }

        $rodapeExtra = self::get('footer_extra', $cfg);
        $nota        = self::get('footer_note', $cfg);

        // Tabelas, e não div+flex: é o que o Outlook renderiza de forma
        // previsível.
        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $e($assunto) . '</title></head>'
            . '<body style="margin:0;padding:0;background:' . $bodyBg . ';font-family:' . $fonte . ';color:' . $texto . '">'
            . $preheader
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $bodyBg . ';padding:24px 12px">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="' . $largura . '" cellpadding="0" cellspacing="0" border="0" style="width:' . $largura . 'px;max-width:100%;background:' . $cardBg . ';border-radius:' . $raio . 'px;overflow:hidden">'
            . '<tr><td style="background:' . $hdrBg . ';color:' . $hdrTxt . ';padding:18px 24px">' . $cabecalho . '</td></tr>'
            . '<tr><td style="padding:24px;font-size:15px;line-height:1.55;color:' . $texto . '">'
            . '<style>a{color:' . $link . '}</style>'   // reforço; o inline abaixo é o que vale
            . $corpoHtml . $cta
            . '</td></tr>'
            . '<tr><td style="padding:16px 24px;border-top:1px solid #e6e9ee;font-size:12px;color:#6b7280;line-height:1.5">'
            . '<strong style="color:' . $texto . '">' . $e($assinat) . '</strong><br>'
            . ($nota !== '' ? $nota . '<br>' : '')
            . ($rodapeExtra !== '' ? $rodapeExtra : '')
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** Corpo de exemplo para a pré-visualização e o e-mail de teste. */
    public static function sampleBody(): string
    {
        return '<p>Este é um exemplo de mensagem do portal, com o layout que está configurado agora.</p>'
             . '<p>O corpo de cada e-mail é escrito por quem o envia — comunicado do RH, aviso de documento '
             . 'vencendo, redefinição de senha — e entra aqui dentro. O cabeçalho, as cores e o rodapé vêm '
             . 'desta configuração.</p>'
             . '<ul><li>Um item de lista</li><li>Outro item, com <a href="' . htmlspecialchars(core_url(''), ENT_QUOTES, 'UTF-8') . '">um link</a></li></ul>'
             . '<p>Atenciosamente.</p>';
    }
}
