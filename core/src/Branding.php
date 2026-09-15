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
        'font_size'       => '16',          // px da base (todo o CSS usa rem)
        'shadow'          => 'suave',       // nenhuma | suave | destacada
        'sidebar_mode'    => 'fixo',        // fixo | recolhivel | icones
        // Cores de estado: os valores de fábrica são os do Bootstrap, para
        // atualizar não mudar nada em quem nunca abriu esta tela.
        'success'         => '#198754',
        'warning'         => '#ffc107',
        'danger'          => '#dc3545',
        'info'            => '#0dcaf0',
        // Modo escuro
        'theme_mode'      => 'claro',       // claro | escuro | auto
        'theme_toggle'    => '0',           // cada usuário pode alternar
        'dark_body_bg'    => '#0f172a',
        'dark_sidebar_bg' => '#111827',
        'dark_primary'    => '',            // vazio → clareia a primária o quanto faltar
        // Tela de login
        'login_layout'    => 'centralizado', // centralizado | lado_a_lado
        'login_card_width' => '420',        // px
        'login_footer'    => '',
        // Válvula de escape: CSS do administrador (servido em rota própria)
        'custom_css'      => '',
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

    public const SHADOWS = [
        'nenhuma'    => ['label' => 'Sem sombra',  'card' => 'none',
                         'topbar' => 'none', 'hover' => '0 0 0 1px var(--portal-border)'],
        'suave'      => ['label' => 'Suave',       'card' => '0 1px 2px rgba(16,24,40,.04)',
                         'topbar' => '0 1px 6px rgba(0,0,0,.18)', 'hover' => '0 8px 22px rgba(16,24,40,.10)'],
        'destacada'  => ['label' => 'Destacada',   'card' => '0 2px 10px rgba(16,24,40,.10)',
                         'topbar' => '0 2px 12px rgba(0,0,0,.26)', 'hover' => '0 14px 34px rgba(16,24,40,.18)'],
    ];

    public const SIDEBAR_MODES = [
        'fixo'       => 'Sempre aberto (padrão)',
        'recolhivel' => 'Aberto, com botão para recolher em ícones',
        'icones'     => 'Só ícones, abre ao passar o mouse',
    ];

    public const THEME_MODES = [
        'claro'  => 'Sempre claro',
        'escuro' => 'Sempre escuro',
        'auto'   => 'Seguir o aparelho de cada pessoa',
    ];

    public const LOGIN_LAYOUTS = [
        'centralizado' => 'Cartão centralizado',
        'lado_a_lado'  => 'Imagem de um lado, formulário do outro',
    ];

    /** Cores de estado que a tela deixa escolher. */
    public const STATES = [
        'success' => 'Sucesso / conforme',
        'warning' => 'Alerta / atenção',
        'danger'  => 'Erro / vencido',
        'info'    => 'Informação',
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
    /** Unidade a que o cache acima pertence (a identidade varia por unidade). */
    private static ?int $cacheUnit = null;

    /** @return array<string, string> configuração efetiva (padrões + salvos) */
    /**
     * Chaves que uma UNIDADE pode ter diferentes do portal. É um conjunto
     * curto de propósito: um grupo com dois hospitais quer o próprio nome e
     * o próprio logotipo no topo, não um sistema com duas caras inteiras —
     * isso confundiria quem circula entre as unidades.
     */
    public const POR_UNIDADE = ['name', 'short_name', 'logo', 'logo_light', 'favicon', 'primary', 'accent'];

    /**
     * Unidade em foco nesta requisição (0 = nenhuma/portal).
     * Vem da sessão, que o núcleo preenche no login.
     */
    public static function unitId(): int
    {
        return (int) ($_SESSION['hospital_id'] ?? 0);
    }

    /** Há mais de uma unidade ativa? (a tela só aparece nesse caso) */
    public static function multiUnidade(): bool
    {
        try {
            $r = DB::queryOne(
                "SELECT COUNT(*) AS n FROM doc_hospitals WHERE deleted_at IS NULL AND is_active = 1"
            );
            return (int) ($r['n'] ?? 0) > 1;
        } catch (\Throwable $e) {
            return false;   // instalação sem o módulo Documentos: mono-unidade
        }
    }

    /** Sobreposições gravadas para uma unidade. */
    public static function unitOverrides(int $unidade): array
    {
        if ($unidade <= 0) {
            return [];
        }
        $out = [];
        foreach (self::POR_UNIDADE as $k) {
            $v = Settings::get('brand.unit.' . $unidade . '.' . $k);
            if ($v !== null && $v !== '') {
                $out[$k] = (string) $v;
            }
        }
        return $out;
    }

    /** Grava (ou apaga, quando vazio) as sobreposições de uma unidade. */
    public static function saveUnit(int $unidade, array $values): void
    {
        if ($unidade <= 0) {
            return;
        }
        $atual = self::unitOverrides($unidade);
        foreach (self::POR_UNIDADE as $k) {
            if (!array_key_exists($k, $values)) {
                continue;
            }
            $v = self::normalizeField($k, (string) $values[$k], $atual[$k] ?? '');
            // Vazio = "herda do portal", e não "grava string vazia".
            Settings::set('brand.unit.' . $unidade . '.' . $k, $v === '' ? null : $v);
        }
        self::forget();
    }

    public static function all(): array
    {
        $unidade = self::unitId();
        if (self::$cache !== null && self::$cacheUnit === $unidade) {
            return self::$cache;
        }
        $out = self::DEFAULTS;
        foreach (array_keys(self::DEFAULTS) as $key) {
            $value = Settings::get('brand.' . $key);
            if ($value !== null && $value !== '') {
                $out[$key] = (string) $value;
            }
        }
        // A unidade sobrepõe o portal no punhado de chaves permitidas. Sem
        // nenhuma gravada — o caso de toda instalação de uma unidade só —
        // isto não muda nada e não custa consulta nenhuma extra.
        if ($unidade > 0 && self::multiUnidade()) {
            foreach (self::unitOverrides($unidade) as $k => $v) {
                $out[$k] = $v;
            }
        }
        self::$cacheUnit = $unidade;
        return self::$cache = $out;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::all()[$key] ?? $default;
    }

    public static function forget(): void
    {
        self::$cache = null;
        self::$cacheUnit = null;
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
    /**
     * Pilha de fontes em uso (a mesma que vai para --portal-font). Pública
     * porque a impressão e os e-mails também precisam dela — antes cada um
     * repetia a sua.
     */
    public static function fontStack(?string $chave = null): string
    {
        $k = $chave ?? self::get('font', 'system');
        return self::FONTS[$k]['stack'] ?? self::FONTS['system']['stack'];
    }

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

    /** O topo é escuro? (o menu suspenso do topo segue esta decisão) */
    public static function topbarIsDark(): bool
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
    /**
     * Fundo do topo. No tema escuro, o estilo "claro" seria uma faixa branca
     * cortando a tela — então ele vira a variante escura automaticamente.
     *
     * @param array<string,string>|null $b valores em uso (null = os salvos)
     */
    private static function topbarBackground(?array $b = null, bool $dark = false): string
    {
        $b     = $b ?? self::all();
        $style = $b['topbar_style'] ?? 'gradient';
        if ($dark && $style === 'light') {
            $style = 'dark';
        }
        $base = self::color($b['topbar_bg'] ?? '', '') ?: self::color($b['primary'] ?? '', self::DEFAULTS['primary']);
        if ($dark) {
            $base = self::shade($base, -0.10);
        }
        return match ($style) {
            'solid'  => $base,
            'dark'   => 'linear-gradient(90deg, #111827, #1f2937)',
            'light'  => '#ffffff',
            default  => 'linear-gradient(90deg, ' . self::shade($base, -0.22) . ', ' . $base . ')',
        };
    }

    /** @param array<string,string>|null $b valores em uso (null = os salvos) */
    private static function topbarText(?array $b = null, bool $dark = false): string
    {
        $b     = $b ?? self::all();
        $style = $b['topbar_style'] ?? 'gradient';
        if ($dark && $style === 'light') {
            $style = 'dark';
        }
        return match ($style) {
            'light' => '#1f2937',
            'dark'  => '#f8fafc',
            default => self::contrastColor(
                self::color($b['topbar_bg'] ?? '', '') ?: self::color($b['primary'] ?? '', self::DEFAULTS['primary'])
            ),
        };
    }

    /**
     * Bloco <style> com todas as variáveis da identidade visual.
     *
     * Emite ATÉ TRÊS conjuntos, porque o modo escuro tem três origens
     * possíveis e elas precisam vencer umas às outras na ordem certa:
     *   1. :root                              → o tema base (claro, ou escuro
     *                                            quando o modo é "sempre escuro");
     *   2. @media (prefers-color-scheme: dark) → o aparelho da pessoa, quando o
     *      + :root:not([data-portal-theme=claro])  modo é "seguir o aparelho";
     *   3. :root[data-portal-theme=escuro|claro] → a escolha de quem usa o
     *                                              alternador, que ganha de todas.
     *
     * @param array<string,string>|null $override valores ainda não salvos
     *        (pré-visualização), já passados por normalizeAll()
     */
    public static function cssVariables(?array $override = null): string
    {
        $b    = $override ?? self::all();
        $mode = $b['theme_mode'] ?? 'claro';

        $claro  = self::paletteCss(self::palette($b, false));
        $escuro = self::paletteCss(self::palette($b, true));

        $css = ":root{\n" . ($mode === 'escuro' ? $escuro : $claro) . "}\n";

        if ($mode === 'auto') {
            $css .= "@media (prefers-color-scheme: dark){\n"
                  . "  :root:not([data-portal-theme=\"claro\"]){\n" . $escuro . "  }\n}\n";
        }
        // O alternador grava o atributo no <html>; as duas regras existem
        // sempre que ele está ligado, para funcionar nos dois sentidos.
        if (($b['theme_toggle'] ?? '0') === '1' || $mode === 'auto') {
            $css .= ":root[data-portal-theme=\"escuro\"]{\n" . $escuro . "}\n";
            $css .= ":root[data-portal-theme=\"claro\"]{\n" . $claro . "}\n";
        }

        if (($bg = self::loginBgUrl()) !== '') {
            $css .= "body.portal-bare{background-image:linear-gradient(rgba(0,0,0,.45),rgba(0,0,0,.45)),"
                  . "url('" . core_e($bg) . "');background-size:cover;background-position:center;}\n";
        }

        return "<style id=\"portal-branding\">\n" . $css . "</style>";
    }

    /**
     * Uma paleta completa a partir das escolhas do administrador.
     *
     * No escuro, a cor da marca é clareada o quanto for preciso para continuar
     * legível sobre o fundo escuro: um azul-marinho institucional usado como
     * está sobre grafite vira um borrão.
     *
     * @param array<string,string> $b
     * @return array<string,string>
     */
    private static function palette(array $b, bool $dark): array
    {
        $bodyBg = $dark
            ? self::color($b['dark_body_bg'] ?? '', self::DEFAULTS['dark_body_bg'])
            : self::color($b['body_bg'] ?? '', self::DEFAULTS['body_bg']);
        $sideBg = $dark
            ? self::color($b['dark_sidebar_bg'] ?? '', self::DEFAULTS['dark_sidebar_bg'])
            : self::color($b['sidebar_bg'] ?? '', self::DEFAULTS['sidebar_bg']);

        $primary = self::color($b['primary'] ?? '', self::DEFAULTS['primary']);
        if ($dark) {
            $escolhida = self::color($b['dark_primary'] ?? '', '');
            $primary   = $escolhida !== '' ? $escolhida : self::lightenFor($primary, $bodyBg);
        }
        $accent = self::color($b['accent'] ?? '', self::DEFAULTS['accent']);
        if ($dark) {
            $accent = self::lightenFor($accent, $bodyBg);
        }

        $sideText = $dark ? '#cbd5e1' : self::color($b['sidebar_text'] ?? '', self::DEFAULTS['sidebar_text']);
        $sideDark = self::isDark($sideBg);
        $surface  = $dark ? self::shade($bodyBg, 0.10) : '#ffffff';
        $text     = self::isDark($bodyBg) ? '#e5e7eb' : '#212529';
        $muted    = $dark ? '#9aa4b2' : '#6c757d';
        $border   = $dark ? 'rgba(255,255,255,.14)' : 'rgba(0,0,0,.09)';

        $font   = self::FONTS[$b['font'] ?? 'system']['stack'] ?? self::FONTS['system']['stack'];
        $scale  = self::DENSITIES[$b['density'] ?? 'normal']['scale'] ?? '1';
        $shadow = self::SHADOWS[$b['shadow'] ?? 'suave'] ?? self::SHADOWS['suave'];
        $radius = max(0, min(24, (int) ($b['radius'] ?? 8)));
        $sideW  = max(180, min(360, (int) ($b['sidebar_width'] ?? 248)));
        $topH   = max(44, min(88, (int) ($b['topbar_height'] ?? 56)));
        $fsize  = max(13, min(20, (int) ($b['font_size'] ?? 16)));
        $cardW  = max(340, min(620, (int) ($b['login_card_width'] ?? 420)));

        $vars = [
            '--portal-primary'            => $primary,
            '--portal-primary-dark'       => self::shade($primary, $dark ? 0.18 : -0.18),
            '--portal-primary-light'      => self::shade($primary, $dark ? -0.25 : 0.35),
            '--portal-primary-soft'       => $dark
                ? 'rgba(' . self::rgbTriplet($primary) . ',.16)'
                : self::shade($primary, 0.88),
            '--portal-primary-rgb'        => self::rgbTriplet($primary),
            '--portal-on-primary'         => self::contrastColor($primary),
            '--portal-accent'             => $accent,
            '--portal-accent-rgb'         => self::rgbTriplet($accent),
            '--portal-on-accent'          => self::contrastColor($accent),
            '--portal-topbar-bg'          => self::topbarBackground($b, $dark),
            '--portal-topbar-text'        => self::topbarText($b, $dark),
            '--portal-topbar-h'           => $topH . 'px',
            '--portal-sidebar-bg'         => $sideBg,
            '--portal-sidebar-text'       => $sideText,
            '--portal-sidebar-hover'      => $sideDark ? 'rgba(255,255,255,.08)' : self::shade($primary, 0.92),
            '--portal-sidebar-active-bg'  => $sideDark ? 'rgba(255,255,255,.12)' : self::shade($primary, 0.88),
            '--portal-sidebar-active'     => $sideDark ? '#ffffff' : self::shade($primary, -0.18),
            '--portal-sidebar-heading'    => $sideDark ? 'rgba(255,255,255,.55)' : '#94a3b8',
            '--portal-sidebar-border'     => $sideDark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)',
            '--portal-sidebar-w'          => $sideW . 'px',
            '--portal-sidebar-w-min'      => '64px',
            '--portal-body-bg'            => $bodyBg,
            '--portal-surface'            => $surface,
            '--portal-surface-2'          => $dark ? self::shade($bodyBg, 0.18) : '#f8fafc',
            '--portal-text'               => $text,
            '--portal-muted'              => $muted,
            '--portal-border'             => $border,
            '--portal-radius'             => $radius . 'px',
            '--portal-radius-sm'          => max(0, (int) round($radius * 0.6)) . 'px',
            '--portal-font'               => $font,
            '--portal-font-size'          => $fsize . 'px',
            '--portal-density'            => $scale,
            '--portal-shadow-card'        => $shadow['card'],
            '--portal-shadow-topbar'      => $shadow['topbar'],
            '--portal-shadow-hover'       => $shadow['hover'],
            '--portal-login-card-w'       => $cardW . 'px',
        ];

        // Cores de estado. No escuro seguem o mesmo critério da marca: o
        // vermelho de "vencido" precisa continuar visível sobre o grafite.
        foreach (array_keys(self::STATES) as $estado) {
            $cor = self::color($b[$estado] ?? '', self::DEFAULTS[$estado]);
            if ($dark) {
                $cor = self::lightenFor($cor, $bodyBg, 4.0);
            }
            $vars['--portal-' . $estado]           = $cor;
            $vars['--portal-' . $estado . '-rgb']  = self::rgbTriplet($cor);
            $vars['--portal-on-' . $estado]        = self::contrastColor($cor);
            $vars['--portal-' . $estado . '-soft'] = $dark
                ? 'rgba(' . self::rgbTriplet($cor) . ',.18)'
                : self::shade($cor, 0.86);
            // Versão para TEXTO: a cor do estado escrita sobre o fundo suave
            // dela mesma é ilegível quando ela é clara — âmbar sobre âmbar
            // pálido dá 1,5:1. Escurece (ou clareia, no tema escuro) até
            // alcançar contraste de leitura.
            $vars['--portal-' . $estado . '-text'] = self::lightenFor(
                $cor,
                // No escuro o fundo do alerta é o próprio corpo com um véu;
                // no claro, o tom suave da cor.
                $dark ? $bodyBg : self::shade($cor, 0.86),
                4.5
            );
            // É daqui que .bg-success, .text-danger, .alert-warning e
            // .btn-outline-info passam a seguir a identidade, sem recompilar
            // o Bootstrap: são mais de 800 usos nos módulos.
            $vars['--bs-' . $estado]              = $cor;
            $vars['--bs-' . $estado . '-rgb']     = self::rgbTriplet($cor);
        }

        $bsLight = $dark ? self::shade($bodyBg, 0.22) : '#f8f9fa';
        $vars += [
            '--bs-primary'                => $primary,
            '--bs-primary-rgb'            => self::rgbTriplet($primary),
            '--bs-secondary'              => $muted,
            '--bs-secondary-rgb'          => self::rgbTriplet($muted),
            '--bs-light'                  => $bsLight,
            '--bs-light-rgb'              => self::rgbTriplet($bsLight),
            '--bs-dark'                   => $dark ? '#e5e7eb' : '#212529',
            '--bs-dark-rgb'               => self::rgbTriplet($dark ? '#e5e7eb' : '#212529'),
            '--bs-link-color'             => self::shade($primary, $dark ? 0.10 : -0.05),
            '--bs-link-hover-color'       => self::shade($primary, $dark ? 0.30 : -0.25),
            '--bs-link-color-rgb'         => self::rgbTriplet($primary),
            '--bs-border-radius'          => $radius . 'px',
            '--bs-border-radius-sm'       => max(0, (int) round($radius * 0.6)) . 'px',
            '--bs-border-radius-lg'       => ($radius + 4) . 'px',
            '--bs-border-color'           => $border,
            '--bs-body-font-family'       => $font,
            '--bs-body-font-size'         => $fsize . 'px',
            '--bs-body-bg'                => $bodyBg,
            '--bs-body-color'             => $text,
            // Sem o -rgb, o Bootstrap continua derivando --bs-secondary-color
            // do preto de fábrica: no tema escuro os textos de apoio e os
            // placeholders ficavam escuros sobre fundo escuro.
            '--bs-body-color-rgb'         => self::rgbTriplet($text),
            '--bs-emphasis-color'         => $text,
            // O Bootstrap 5.3 grava estas com o VALOR já resolvido (não como
            // rgba(var(--bs-body-color-rgb), …)), então redefinir a cor do
            // corpo não as alcança: no tema escuro os textos de apoio
            // (.form-text, .text-body-secondary) ficavam quase pretos sobre
            // fundo escuro.
            '--bs-secondary-color'        => 'rgba(' . self::rgbTriplet($text) . ', .75)',
            '--bs-tertiary-color'         => 'rgba(' . self::rgbTriplet($text) . ', .5)',
            '--bs-emphasis-color-rgb'     => self::rgbTriplet($text),
            '--bs-secondary-color-rgb'    => self::rgbTriplet($muted),
            '--bs-secondary-bg'           => $dark ? self::shade($bodyBg, 0.14) : '#e9ecef',
            '--bs-tertiary-bg'            => $dark ? self::shade($bodyBg, 0.10) : '#f8f9fa',
            // Ícone do botão de menu no celular: recolorido conforme o topo.
            // Fixo em branco, ele sumia por completo num topo claro.
            '--bs-navbar-toggler-icon-bg' => 'url("data:image/svg+xml,'
                . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 30">'
                    . '<path stroke="' . self::topbarText($b, $dark) . '" stroke-width="2"'
                    . ' stroke-linecap="round" d="M4 7h22M4 15h22M4 23h22"/></svg>') . '")',
        ];

        return $vars;
    }

    /** @param array<string,string> $vars */
    private static function paletteCss(array $vars): string
    {
        $css = '';
        foreach ($vars as $k => $v) {
            $css .= "    {$k}: {$v};\n";
        }
        return $css;
    }

    /**
     * Clareia (ou escurece) a cor até ela alcançar a razão de contraste pedida
     * sobre o fundo informado. É o que permite usar a mesma marca no tema
     * claro e no escuro sem o administrador escolher duas paletas.
     */
    public static function lightenFor(string $hex, string $sobre, float $alvo = 4.5): string
    {
        $fundo = self::luminance($sobre);
        $subir = self::isDark($sobre);
        $cor   = $hex;
        for ($i = 0; $i < 22; $i++) {
            $l     = self::luminance($cor);
            $ratio = (max($l, $fundo) + 0.05) / (min($l, $fundo) + 0.05);
            if ($ratio >= $alvo) {
                return $cor;
            }
            $cor = self::shade($cor, $subir ? 0.08 : -0.08);
        }
        return $cor;
    }

    /** CSS livre do administrador, já filtrado. */
    public static function customCss(): string
    {
        return self::get('custom_css');
    }

    /** Versão do CSS livre — sem ela o navegador serviria o antigo do cache. */
    public static function customCssVersion(): string
    {
        $css = self::customCss();
        return $css === '' ? '' : substr(hash('sha256', $css), 0, 10);
    }

    /**
     * <link> do CSS livre. Ele é servido por uma rota própria com
     * Content-Type: text/css em vez de ir inline num <style> — assim a fuga
     * de contexto (fechar o <style> e abrir um <script>) deixa de existir por
     * construção, e o navegador ainda ganha cache.
     */
    public static function customCssTag(): string
    {
        $v = self::customCssVersion();
        if ($v === '') {
            return '';
        }
        return '<link rel="stylesheet" href="'
             . core_e(core_url('index.php?m=auth&a=brand_css&v=' . $v)) . '">';
    }

    /**
     * Trecho inline que aplica, ANTES da primeira pintura, o tema e o estado
     * do menu escolhidos por quem está usando. No fim do <body> seria tarde:
     * a tela pintaria clara e saltaria para escura.
     */
    public static function bootScript(): string
    {
        $toggle = self::get('theme_toggle') === '1';
        $modo   = self::get('theme_mode');
        $side   = self::get('sidebar_mode');
        if (!$toggle && $modo !== 'auto' && $side !== 'recolhivel') {
            return '';
        }
        $js = '(function(){try{var d=document.documentElement;';
        if ($toggle || $modo === 'auto') {
            $js .= 'var t=localStorage.getItem("portalTema");'
                 . 'if(t==="escuro"||t==="claro"){d.setAttribute("data-portal-theme",t);}';
        }
        if ($side === 'recolhivel') {
            $js .= 'if(localStorage.getItem("portalMenu")==="recolhido"){'
                 . 'd.setAttribute("data-portal-sidebar","recolhido");}';
        }
        $js .= '}catch(e){}})();';
        return '<script>' . $js . '</script>';
    }

    /** theme-color e companhia: a barra do navegador no celular segue a marca. */
    public static function metaTags(): string
    {
        return '<meta name="theme-color" content="' . core_e(self::topbarSolidColor()) . '">' . "\n    "
             . '<meta name="mobile-web-app-capable" content="yes">' . "\n    "
             . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n    "
             . '<meta name="apple-mobile-web-app-title" content="' . core_e(self::shortName()) . '">' . "\n    "
             . '<link rel="manifest" href="' . core_e(core_url('index.php?m=auth&a=manifest')) . '">';
    }

    /** Cor sólida equivalente ao topo (um degradê não serve para theme-color). */
    public static function topbarSolidColor(): string
    {
        $b     = self::all();
        $style = $b['topbar_style'];
        if ($style === 'light') {
            return '#ffffff';
        }
        if ($style === 'dark') {
            return '#1f2937';
        }
        $base = self::color($b['topbar_bg'], '') ?: self::color($b['primary'], self::DEFAULTS['primary']);
        return $style === 'gradient' ? self::shade($base, -0.18) : $base;
    }

    /**
     * Manifesto do aplicativo (PWA), gerado pelo PHP para acompanhar a marca
     * sem ninguém precisar editar um arquivo estático a cada troca de logo.
     *
     * @return array<string,mixed>
     */
    public static function manifest(): array
    {
        $icone = self::faviconUrl() ?: self::logoUrl();
        $m = [
            'name'             => self::name(),
            'short_name'       => self::shortName(),
            'start_url'        => core_url('index.php'),
            'scope'            => core_url('index.php'),
            'display'          => 'standalone',
            'background_color' => self::get('body_bg') ?: self::DEFAULTS['body_bg'],
            'theme_color'      => self::topbarSolidColor(),
            'lang'             => 'pt-BR',
            'dir'              => 'ltr',
        ];
        if ($icone !== '') {
            $ext = strtolower(pathinfo(parse_url($icone, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
            $m['icons'] = [[
                'src'     => $icone,
                'type'    => match ($ext) {
                    'svg'         => 'image/svg+xml',
                    'jpg', 'jpeg' => 'image/jpeg',
                    'ico'         => 'image/x-icon',
                    default       => 'image/png',
                },
                'sizes'   => $ext === 'svg' ? 'any' : '512x512',
                'purpose' => 'any',
            ]];
        }
        return $m;
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
            $value = self::normalizeField($key, (string) $values[$key], $current[$key] ?? '');
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

    /** Corta o CSS no limite, recuando até a última chave de fechamento. */
    private static function cortaCss(string $css, int $limite): string
    {
        if (mb_strlen($css) <= $limite) {
            return $css;
        }
        $corte = mb_substr($css, 0, $limite);
        $ultima = mb_strrpos($corte, '}');
        return $ultima !== false ? mb_substr($corte, 0, $ultima + 1) : $corte;
    }

    /**
     * Valida UM campo. É a única fronteira de validação da identidade visual:
     * save() e a pré-visualização passam por aqui, para nada cru chegar a um
     * bloco <style> (seria injeção de CSS numa página do próprio portal).
     */
    public static function normalizeField(string $key, string $raw, string $atual = ''): string
    {
        $raw = trim($raw);
        $atual = $atual !== '' ? $atual : (self::DEFAULTS[$key] ?? '');

        return match ($key) {
            'primary', 'accent', 'sidebar_bg', 'sidebar_text', 'body_bg', 'topbar_bg',
            'success', 'warning', 'danger', 'info', 'dark_body_bg', 'dark_sidebar_bg', 'dark_primary'
                => $raw === '' ? '' : (self::isColor($raw) ? strtolower($raw) : $atual),
            'topbar_style'     => isset(self::TOPBAR_STYLES[$raw]) ? $raw : $atual,
            'font'             => isset(self::FONTS[$raw]) ? $raw : $atual,
            'density'          => isset(self::DENSITIES[$raw]) ? $raw : $atual,
            'shadow'           => isset(self::SHADOWS[$raw]) ? $raw : $atual,
            'sidebar_mode'     => isset(self::SIDEBAR_MODES[$raw]) ? $raw : $atual,
            'theme_mode'       => isset(self::THEME_MODES[$raw]) ? $raw : $atual,
            'login_layout'     => isset(self::LOGIN_LAYOUTS[$raw]) ? $raw : $atual,
            // Booleanos gravam sempre '0' ou '1': um checkbox desmarcado não é
            // enviado pelo navegador, então o formulário manda um campo oculto
            // antes dele e a chave sempre chega.
            'theme_toggle'     => $raw === '1' ? '1' : '0',
            'radius'           => (string) max(0, min(24, (int) $raw)),
            'sidebar_width'    => (string) max(180, min(360, (int) $raw)),
            'topbar_height'    => (string) max(44, min(88, (int) $raw)),
            'font_size'        => (string) max(13, min(20, (int) $raw ?: 16)),
            'login_card_width' => (string) max(340, min(620, (int) $raw ?: 420)),
            'name', 'short_name' => mb_substr($raw, 0, 120),
            'login_message'    => mb_substr($raw, 0, 300),
            'login_footer'     => mb_substr($raw, 0, 400),
            // O corte é avisado pela tela (core_admin_appearance_save compara o
            // tamanho recebido) e cai na última chave de fechamento, para não
            // deixar um seletor pela metade — que faz o navegador descartar
            // também a regra seguinte.
            'custom_css'       => CssSanitizer::clean(self::cortaCss($raw, 16384)),
            // Caminhos de imagem nunca vêm do formulário: só de uploadImage().
            'logo', 'logo_light', 'favicon', 'login_bg' => $atual,
            default            => mb_substr($raw, 0, 500),
        };
    }

    /**
     * Normaliza um conjunto inteiro, preenchendo o que faltar com o padrão.
     * Usado pela pré-visualização (valores ainda não salvos).
     *
     * @param array<string,mixed> $values
     * @return array<string,string>
     */
    public static function normalizeAll(array $values): array
    {
        $out = self::DEFAULTS;
        foreach (self::DEFAULTS as $key => $default) {
            if (array_key_exists($key, $values)) {
                $v = self::normalizeField($key, (string) $values[$key], $default);
                $out[$key] = $v === '' ? $default : $v;
            }
        }
        // As imagens vêm do que está salvo: o formulário não as transporta.
        foreach (['logo', 'logo_light', 'favicon', 'login_bg'] as $img) {
            $out[$img] = self::get($img);
        }
        return $out;
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
        $err = (int) ($file['error'] ?? 0);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            $limit = $err === UPLOAD_ERR_INI_SIZE
                ? (string) ini_get('upload_max_filesize')
                : round($maxBytes / 1048576, 1) . ' MB';
            return ['ok' => false, 'error' => 'Imagem maior que o limite de envio (' . $limit . ').'];
        }
        if ($err === UPLOAD_ERR_PARTIAL) {
            return ['ok' => false, 'error' => 'O envio foi interrompido; tente novamente.'];
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            return ['ok' => false, 'error' => 'Falha no envio da imagem.'];
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

    // ------------------------------------------------------------------
    // Matemática de cor — UMA implementação, em Core\Tokens.
    //
    // Estes métodos continuam aqui porque meio sistema os chama pelo nome
    // Branding::, mas o cálculo é um só: três cópias ligeiramente
    // diferentes da mesma conta era um bug esperando a hora de aparecer
    // em apenas uma das telas.
    // ------------------------------------------------------------------

    public static function isColor(string $value): bool
    {
        return Tokens::isColor($value);
    }

    private static function color(string $value, string $fallback): string
    {
        return Tokens::color($value, $fallback);
    }

    public static function rgbTriplet(string $hex): string
    {
        return Tokens::rgbTriplet($hex);
    }

    /** Clareia (fator > 0) ou escurece (fator < 0) uma cor. */
    public static function shade(string $hex, float $factor): string
    {
        return Tokens::shade($hex, $factor);
    }

    /** Luminância relativa (0 escuro … 1 claro). */
    public static function luminance(string $hex): float
    {
        return Tokens::luminance($hex);
    }

    /** Razão de contraste da WCAG entre duas cores (1:1 a 21:1). */
    public static function contrastRatio(string $a, string $b): float
    {
        return Tokens::contrastRatio($a, $b);
    }

    /** O fundo é escuro? (ou seja: sobre ele o texto legível é claro) */
    public static function isDark(string $hex): bool
    {
        return Tokens::isDark($hex);
    }

    /** Cor de texto legível sobre $hex (pela razão de contraste da WCAG). */
    public static function contrastColor(string $hex): string
    {
        return Tokens::contrastColor($hex);
    }
}
