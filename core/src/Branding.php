<?php

declare(strict_types=1);

namespace Core;

/**
 * Identidade visual da plataforma (Administração → Aparência).
 *
 * Tudo o que dá o "rosto" do sistema — nome, logotipo, favicon, cores,
 * fonte, cantos, densidade e a tela de login — fica em `settings` com o
 * prefixo `brand.` e vira variáveis CSS injetadas no <head> de todas as
 * páginas (Core\Layout). Os módulos não precisam saber de nada: basta
 * usarem as variáveis --portal-* ou as classes do Bootstrap, que já são
 * redefinidas a partir delas em assets/core/app.css.
 *
 * As cores derivadas (hover, texto sobre a cor, versões claras) são
 * calculadas aqui, então o administrador escolhe poucas cores e o resto
 * continua legível — inclusive o contraste do texto sobre a cor primária.
 */
final class Branding
{
    public const UPLOAD_DIR = 'uploads/branding';

    /** Valores padrão (o azul institucional original da plataforma). */
    public const DEFAULTS = [
        'name'            => '',            // vazio → app.name do config
        'short_name'      => '',            // vazio → name
        'logo'            => '',            // uploads/branding/...
        'logo_light'      => '',            // versão para fundo escuro (topbar)
        'favicon'         => '',
        'login_bg'        => '',
        'login_message'   => '',
        'primary'         => '#0d5c8f',
        'accent'          => '#0f9d8f',
        'topbar_style'    => 'gradient',    // gradient | solid | dark | light
        'topbar_bg'       => '',            // vazio → derivado da primária
        'sidebar_bg'      => '#ffffff',
        'sidebar_text'    => '#495057',
        'body_bg'         => '#f4f6f9',
        'font'            => 'system',      // chave de FONTS
        'radius'          => '8',           // px
        'density'         => 'normal',      // compact | normal | comfortable
        'sidebar_width'   => '248',         // px
        'topbar_height'   => '56',          // px
    ];

    /** Fontes disponíveis (sem depender de CDN: pilhas do sistema). */
    public const FONTS = [
        'system'    => ['label' => 'Padrão do sistema', 'stack' => "system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif"],
        'inter'     => ['label' => 'Inter / Segoe',     'stack' => "Inter, 'Segoe UI', system-ui, Roboto, Arial, sans-serif"],
        'roboto'    => ['label' => 'Roboto',            'stack' => "Roboto, system-ui, 'Segoe UI', Arial, sans-serif"],
        'opensans'  => ['label' => 'Open Sans',         'stack' => "'Open Sans', system-ui, 'Segoe UI', Arial, sans-serif"],
        'lato'      => ['label' => 'Lato',              'stack' => "Lato, system-ui, 'Segoe UI', Arial, sans-serif"],
        'nunito'    => ['label' => 'Nunito',            'stack' => "'Nunito Sans', Nunito, system-ui, 'Segoe UI', Arial, sans-serif"],
        'georgia'   => ['label' => 'Georgia (serifada)', 'stack' => "Georgia, 'Times New Roman', serif"],
    ];

    public const DENSITIES = [
        'compact'     => ['label' => 'Compacta',    'scale' => '0.85'],
        'normal'      => ['label' => 'Normal',      'scale' => '1'],
        'comfortable' => ['label' => 'Confortável', 'scale' => '1.15'],
    ];

    public const TOPBAR_STYLES = [
        'gradient' => 'Degradê da cor primária',
        'solid'    => 'Cor sólida',
        'dark'     => 'Escura (grafite)',
        'light'    => 'Clara (branca)',
    ];

    /** Temas prontos: preenchem as cores de uma vez. */
    public static function presets(): array
    {
        return [
            'padrao'   => ['label' => 'Azul institucional', 'values' => ['primary' => '#0d5c8f', 'accent' => '#0f9d8f', 'topbar_style' => 'gradient', 'sidebar_bg' => '#ffffff', 'sidebar_text' => '#495057', 'body_bg' => '#f4f6f9']],
            'saude'    => ['label' => 'Verde saúde',        'values' => ['primary' => '#1b7f5a', 'accent' => '#0d6efd', 'topbar_style' => 'gradient', 'sidebar_bg' => '#ffffff', 'sidebar_text' => '#495057', 'body_bg' => '#f3f8f5']],
            'teal'     => ['label' => 'Teal moderno',       'values' => ['primary' => '#0f766e', 'accent' => '#f59e0b', 'topbar_style' => 'solid',    'sidebar_bg' => '#ffffff', 'sidebar_text' => '#475569', 'body_bg' => '#f5f8f8']],
            'indigo'   => ['label' => 'Índigo',             'values' => ['primary' => '#4338ca', 'accent' => '#06b6d4', 'topbar_style' => 'gradient', 'sidebar_bg' => '#ffffff', 'sidebar_text' => '#4b5563', 'body_bg' => '#f5f5fb']],
            'bordo'    => ['label' => 'Bordô',              'values' => ['primary' => '#9d174d', 'accent' => '#b45309', 'topbar_style' => 'solid',    'sidebar_bg' => '#ffffff', 'sidebar_text' => '#4b5563', 'body_bg' => '#faf5f7']],
            'grafite'  => ['label' => 'Grafite (escuro)',   'values' => ['primary' => '#2563eb', 'accent' => '#22d3ee', 'topbar_style' => 'dark',     'sidebar_bg' => '#1f2937', 'sidebar_text' => '#cbd5e1', 'body_bg' => '#111827']],
            'contrast' => ['label' => 'Alto contraste',     'values' => ['primary' => '#00407a', 'accent' => '#b30021', 'topbar_style' => 'solid',    'sidebar_bg' => '#ffffff', 'sidebar_text' => '#1f2937', 'body_bg' => '#ffffff']],
        ];
    }

    /** @var array<string, string>|null cache por request */
    private static ?array $cache = null;

    /** @return array<string, string> configuração efetiva (padrões + salvos) */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = self::DEFAULTS;
        foreach (array_keys(self::DEFAULTS) as $key) {
            $value = Settings::get('brand.' . $key);
            if ($value !== null && $value !== '') {
                $out[$key] = (string) $value;
            }
        }
        return self::$cache = $out;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::all()[$key] ?? $default;
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    // ------------------------------------------------------------------
    // Nome, logotipo e favicon
    // ------------------------------------------------------------------

    /** Nome da organização (cai para settings.org_name e depois app.name). */
    public static function name(): string
    {
        $name = self::get('name');
        if ($name !== '') {
            return $name;
        }
        $org = trim((string) Settings::get('org_name', ''));
        if ($org !== '') {
            return $org;
        }
        $app = trim((string) core_config('app.name', ''));
        return $app !== '' ? $app : 'Portal Corporativo';
    }

    /** Nome curto (topbar). */
    public static function shortName(): string
    {
        return self::get('short_name') ?: self::name();
    }

    public static function logoUrl(): string
    {
        $p = self::get('logo');
        return $p !== '' ? core_url($p) : '';
    }

    /** Logotipo para fundo escuro (topbar); cai para o principal. */
    public static function logoLightUrl(): string
    {
        $p = self::get('logo_light');
        if ($p !== '') {
            return core_url($p);
        }
        return self::topbarIsDark() ? '' : self::logoUrl();
    }

    /** Logotipo usado na topbar, considerando o estilo dela. */
    public static function topbarLogoUrl(): string
    {
        return self::topbarIsDark() ? (self::logoLightUrl() ?: self::logoUrl()) : self::logoUrl();
    }

    public static function faviconUrl(): string
    {
        $p = self::get('favicon');
        return $p !== '' ? core_url($p) : '';
    }

    public static function loginBgUrl(): string
    {
        $p = self::get('login_bg');
        return $p !== '' ? core_url($p) : '';
    }

    public static function loginMessage(): string
    {
        return self::get('login_message');
    }

    /** <link rel="icon"> do favicon enviado (ou vazio: o navegador usa o padrão). */
    public static function faviconTag(): string
    {
        $url = self::faviconUrl();
        if ($url === '') {
            return '';
        }
        $ext  = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        $type = match ($ext) {
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'image/png',
        };
        return '<link rel="icon" type="' . $type . '" href="' . core_e($url) . '">' . "\n    "
             . '<link rel="apple-touch-icon" href="' . core_e($url) . '">';
    }

    /**
     * Famílias servidas pelo Google Fonts (as demais já existem no sistema
     * operacional). Mantido como mapa chave => família do Google.
     */
    private const WEB_FONTS = [
        'inter'    => 'Inter:wght@400;500;600;700',
        'roboto'   => 'Roboto:wght@400;500;700',
        'opensans' => 'Open+Sans:wght@400;600;700',
        'lato'     => 'Lato:wght@400;700',
        'nunito'   => 'Nunito+Sans:wght@400;600;700',
    ];

    /**
     * <link> da fonte escolhida, quando ela não é nativa do sistema.
     * Sem internet o navegador simplesmente usa a pilha de reserva do
     * --portal-font, então a página nunca quebra por causa disso.
     */
    public static function fontTag(): string
    {
        $family = self::WEB_FONTS[self::get('font')] ?? '';
        if ($family === '') {
            return '';
        }
        $href = 'https://fonts.googleapis.com/css2?family=' . $family . '&display=swap';
        return '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n    "
             . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n    "
             . '<link rel="stylesheet" href="' . core_e($href) . '">';
    }

    // ------------------------------------------------------------------
    // Cores
    // ------------------------------------------------------------------

    private static function topbarIsDark(): bool
    {
        $style = self::get('topbar_style');
        if ($style === 'light') {
            return false;
        }
        if ($style === 'dark') {
            return true;
        }
        $bg = self::get('topbar_bg') ?: self::get('primary');
        return self::isDark($bg);
    }

    /** Cor de fundo da topbar (CSS pronto: cor ou degradê). */
    private static function topbarBackground(): string
    {
        $style = self::get('topbar_style');
        $base  = self::get('topbar_bg') ?: self::get('primary');
        return match ($style) {
            'solid'  => $base,
            'dark'   => 'linear-gradient(90deg, #111827, #1f2937)',
            'light'  => '#ffffff',
            default  => 'linear-gradient(90deg, ' . self::shade($base, -0.22) . ', ' . $base . ')',
        };
    }

    private static function topbarText(): string
    {
        return match (self::get('topbar_style')) {
            'light' => '#1f2937',
            'dark'  => '#f8fafc',
            default => self::contrastColor(self::get('topbar_bg') ?: self::get('primary')),
        };
    }

    /**
     * Bloco <style> com todas as variáveis da identidade visual.
     * Vai no <head>, depois do app.css, para que os módulos herdem.
     */
    public static function cssVariables(): string
    {
        $b        = self::all();
        $primary  = self::color($b['primary'], self::DEFAULTS['primary']);
        $accent   = self::color($b['accent'], self::DEFAULTS['accent']);
        $sideBg   = self::color($b['sidebar_bg'], self::DEFAULTS['sidebar_bg']);
        $sideText = self::color($b['sidebar_text'], self::DEFAULTS['sidebar_text']);
        $bodyBg   = self::color($b['body_bg'], self::DEFAULTS['body_bg']);
        $sideDark = self::isDark($sideBg);

        $font    = self::FONTS[$b['font']]['stack'] ?? self::FONTS['system']['stack'];
        $scale   = self::DENSITIES[$b['density']]['scale'] ?? '1';
        $radius  = max(0, min(24, (int) $b['radius']));
        $sideW   = max(180, min(360, (int) $b['sidebar_width']));
        $topH    = max(44, min(88, (int) $b['topbar_height']));

        $vars = [
            '--portal-primary'            => $primary,
            '--portal-primary-dark'       => self::shade($primary, -0.18),
            '--portal-primary-light'      => self::shade($primary, 0.35),
            '--portal-primary-soft'       => self::shade($primary, 0.88),
            '--portal-primary-rgb'        => self::rgbTriplet($primary),
            '--portal-on-primary'         => self::contrastColor($primary),
            '--portal-accent'             => $accent,
            '--portal-accent-rgb'         => self::rgbTriplet($accent),
            '--portal-on-accent'          => self::contrastColor($accent),
            '--portal-topbar-bg'          => self::topbarBackground(),
            '--portal-topbar-text'        => self::topbarText(),
            '--portal-topbar-h'           => $topH . 'px',
            '--portal-sidebar-bg'         => $sideBg,
            '--portal-sidebar-text'       => $sideText,
            '--portal-sidebar-hover'      => $sideDark ? 'rgba(255,255,255,.08)' : self::shade($primary, 0.92),
            '--portal-sidebar-active-bg'  => $sideDark ? 'rgba(255,255,255,.12)' : self::shade($primary, 0.88),
            '--portal-sidebar-active'     => $sideDark ? '#ffffff' : self::shade($primary, -0.18),
            '--portal-sidebar-heading'    => $sideDark ? 'rgba(255,255,255,.55)' : '#94a3b8',
            '--portal-sidebar-border'     => $sideDark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)',
            '--portal-sidebar-w'          => $sideW . 'px',
            '--portal-body-bg'            => $bodyBg,
            '--portal-surface'            => self::isDark($bodyBg) ? '#1f2937' : '#ffffff',
            '--portal-text'               => self::isDark($bodyBg) ? '#e5e7eb' : '#212529',
            '--portal-muted'              => self::isDark($bodyBg) ? '#9ca3af' : '#6c757d',
            '--portal-border'             => self::isDark($bodyBg) ? 'rgba(255,255,255,.12)' : 'rgba(0,0,0,.09)',
            '--portal-radius'             => $radius . 'px',
            '--portal-radius-sm'          => max(0, (int) round($radius * 0.6)) . 'px',
            '--portal-font'               => $font,
            '--portal-density'            => $scale,
            // Bootstrap: componentes seguem a marca sem recompilar o framework
            '--bs-primary'                => $primary,
            '--bs-primary-rgb'            => self::rgbTriplet($primary),
            '--bs-link-color'             => self::shade($primary, -0.05),
            '--bs-link-hover-color'       => self::shade($primary, -0.25),
            '--bs-link-color-rgb'         => self::rgbTriplet($primary),
            '--bs-border-radius'          => $radius . 'px',
            '--bs-border-radius-sm'       => max(0, (int) round($radius * 0.6)) . 'px',
            '--bs-border-radius-lg'       => ($radius + 4) . 'px',
            '--bs-body-font-family'       => $font,
            '--bs-body-bg'                => $bodyBg,
            '--bs-body-color'             => self::isDark($bodyBg) ? '#e5e7eb' : '#212529',
        ];

        $css = ":root{\n";
        foreach ($vars as $k => $v) {
            $css .= "    {$k}: {$v};\n";
        }
        $css .= "}\n";

        if (($bg = self::loginBgUrl()) !== '') {
            $css .= "body.portal-bare{background-image:linear-gradient(rgba(0,0,0,.45),rgba(0,0,0,.45)),url('" . core_e($bg) . "');background-size:cover;background-position:center;}\n";
        }

        return "<style id=\"portal-branding\">\n" . $css . "</style>";
    }

    // ------------------------------------------------------------------
    // Gravação
    // ------------------------------------------------------------------

    /**
     * Grava a identidade visual. Só aceita chaves conhecidas e valida
     * cores/enums/números; o que vier fora do padrão volta ao valor atual.
     * @param array<string, string> $values
     */
    public static function save(array $values): void
    {
        $current = self::all();
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $raw = trim((string) $values[$key]);
            $value = match ($key) {
                'primary', 'accent', 'sidebar_bg', 'sidebar_text', 'body_bg', 'topbar_bg'
                    => $raw === '' ? '' : (self::isColor($raw) ? strtolower($raw) : $current[$key]),
                'topbar_style'  => isset(self::TOPBAR_STYLES[$raw]) ? $raw : $current[$key],
                'font'          => isset(self::FONTS[$raw]) ? $raw : $current[$key],
                'density'       => isset(self::DENSITIES[$raw]) ? $raw : $current[$key],
                'radius'        => (string) max(0, min(24, (int) $raw)),
                'sidebar_width' => (string) max(180, min(360, (int) $raw)),
                'topbar_height' => (string) max(44, min(88, (int) $raw)),
                'name', 'short_name' => mb_substr($raw, 0, 120),
                'login_message' => mb_substr($raw, 0, 300),
                default         => $raw,
            };
            Settings::set('brand.' . $key, $value === '' ? null : $value);
        }
        // Compatibilidade: o nome da organização continua em org_name
        if (array_key_exists('name', $values)) {
            $brandName = self::get('name');
            if ($brandName !== '') {
                Settings::set('org_name', $brandName);
            }
        }
        self::forget();
    }

    /** Aplica um tema pronto (só as cores). */
    public static function applyPreset(string $key): bool
    {
        $preset = self::presets()[$key] ?? null;
        if (!$preset) {
            return false;
        }
        self::save($preset['values']);
        Settings::set('brand.preset', $key);
        self::forget();
        return true;
    }

    /** Volta tudo ao padrão (inclusive removendo as imagens enviadas). */
    public static function reset(bool $keepImages = false): void
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            if ($keepImages && in_array($key, ['logo', 'logo_light', 'favicon', 'login_bg'], true)) {
                continue;
            }
            if (!$keepImages && in_array($key, ['logo', 'logo_light', 'favicon', 'login_bg'], true)) {
                self::deleteImage($key);
            }
            Settings::set('brand.' . $key, null);
        }
        Settings::set('brand.preset', null);
        self::forget();
    }

    /**
     * Recebe uma imagem enviada pelo formulário e grava em uploads/branding.
     * @return array{ok: bool, error?: string}
     */
    public static function uploadImage(string $key, array $file, int $maxBytes = 2097152): array
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            return ['ok' => false, 'error' => 'Campo de imagem desconhecido.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['ok' => true];
        }
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            return ['ok' => false, 'error' => 'Falha no envio da imagem (limite do servidor?).'];
        }
        if (($file['size'] ?? 0) > $maxBytes) {
            return ['ok' => false, 'error' => 'Imagem maior que ' . round($maxBytes / 1048576, 1) . ' MB.'];
        }
        $mime = (string) (mime_content_type($file['tmp_name']) ?: '');
        $allowed = [
            'image/png'     => 'png',
            'image/jpeg'    => 'jpg',
            'image/gif'     => 'gif',
            'image/webp'    => 'webp',
            'image/svg+xml' => 'svg',
            'image/x-icon'  => 'ico',
            'image/vnd.microsoft.icon' => 'ico',
        ];
        if ($key !== 'favicon') {
            unset($allowed['image/x-icon'], $allowed['image/vnd.microsoft.icon']);
        }
        $ext = $allowed[$mime] ?? null;
        if ($ext === null) {
            return ['ok' => false, 'error' => 'Formato não aceito: envie PNG, JPG, GIF, WEBP ou SVG' . ($key === 'favicon' ? '/ICO.' : '.')];
        }
        // SVG pode conter script: só entra depois de limpo.
        $svg = null;
        if ($ext === 'svg') {
            $svg = self::sanitizeSvg((string) file_get_contents($file['tmp_name']));
            if ($svg === null) {
                return ['ok' => false, 'error' => 'SVG inválido ou com conteúdo não permitido.'];
            }
        }

        $dir = BASE_PATH . '/' . self::UPLOAD_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'Não foi possível criar a pasta de imagens.'];
        }
        $name = $key . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        $saved = $svg !== null
            ? (file_put_contents($dest, $svg) !== false)
            : move_uploaded_file($file['tmp_name'], $dest);
        if (!$saved) {
            return ['ok' => false, 'error' => 'Não foi possível gravar a imagem.'];
        }

        self::deleteImage($key);
        Settings::set('brand.' . $key, self::UPLOAD_DIR . '/' . $name);
        self::forget();
        return ['ok' => true];
    }

    /** Remove a imagem de um campo (arquivo + configuração). */
    public static function deleteImage(string $key): void
    {
        $path = (string) Settings::get('brand.' . $key, '');
        if ($path !== '' && str_starts_with($path, self::UPLOAD_DIR . '/')) {
            @unlink(BASE_PATH . '/' . $path);
        }
        Settings::set('brand.' . $key, null);
        self::forget();
    }

    /** SVG sem script/handler/uso externo (null quando não dá para confiar). */
    private static function sanitizeSvg(string $svg): ?string
    {
        if (stripos($svg, '<svg') === false) {
            return null;
        }
        $clean = HtmlSanitizer::quickFilter($svg);
        $clean = preg_replace('#<\s*(script|foreignObject|iframe|use|image|a)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $clean) ?? $clean;
        $clean = preg_replace('#<\s*(script|foreignObject|iframe|use|image)\b[^>]*/?>#i', '', $clean) ?? $clean;
        $clean = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? $clean;
        $clean = preg_replace('/(xlink:href|href)\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $clean) ?? $clean;
        return stripos($clean, '<svg') !== false ? $clean : null;
    }

    // ------------------------------------------------------------------
    // Utilidades de cor
    // ------------------------------------------------------------------

    public static function isColor(string $value): bool
    {
        return (bool) preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value);
    }

    private static function color(string $value, string $fallback): string
    {
        return self::isColor($value) ? strtolower($value) : $fallback;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            $hex = '0d5c8f';
        }
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
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
        $mix = static function (int $c) use ($factor): int {
            $target = $factor > 0 ? 255 : 0;
            return (int) round($c + ($target - $c) * abs($factor));
        };
        return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
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

    /** Texto legível sobre a cor informada (branco ou quase preto). */
    /**
     * O fundo é escuro? (ou seja: sobre ele o texto legível é claro)
     * Mesma régua de contraste usada em contrastColor(), para a superfície,
     * o texto e as bordas acompanharem a cor escolhida sem surpresas.
     */
    public static function isDark(string $hex): bool
    {
        return self::contrastColor($hex) === '#ffffff';
    }

    /**
     * Cor de texto legível sobre $hex: escolhe entre claro e escuro pela
     * razão de contraste da WCAG (e não pelo brilho médio, que erra em
     * tons como o âmbar, onde o branco fica ilegível).
     */
    public static function contrastColor(string $hex): string
    {
        $bg    = self::luminance($hex);
        $light = (max($bg, 1.0) + 0.05) / (min($bg, 1.0) + 0.05);
        $darkL = self::luminance('#1f2937');
        $dark  = (max($bg, $darkL) + 0.05) / (min($bg, $darkL) + 0.05);
        return $dark > $light ? '#1f2937' : '#ffffff';
    }
}
