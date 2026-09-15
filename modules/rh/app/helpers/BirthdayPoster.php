<?php
/**
 * BirthdayPoster — cartaz A4 dos aniversariantes do mês no papel timbrado
 * (Core\DocLayout::renderHtml), com layout personalizável na Administração
 * central (aba "Aniversariantes (A4)" do módulo RH).
 *
 * O layout é personalizável de dois jeitos, escolhidos em rh.birthdays.mode:
 *
 *   'visual'  (padrão) — o cartaz é MONTADO a partir de opções simples:
 *             modelo, número de colunas, cores, fonte, formato da foto e
 *             quais informações aparecem. O HTML e o CSS são gerados; quem
 *             configura não escreve uma linha de código.
 *
 *   'avancado' — o HTML e o CSS gravados são usados como estão. É o modo de
 *             quem quer controle total (e assume as consequências). A tela
 *             oferece gerar o código a partir do visual, para servir de
 *             ponto de partida em vez de começar do zero.
 *
 * Configuração (Core\Settings):
 *   rh.birthdays.mode        'visual' | 'avancado'
 *   rh.birthdays.layout_id   layout de página (intra_layouts) — vazio = padrão
 *   rh.birthdays.title       título (aceita {{mes}}, {{ano}}, {{org}}, {{total}})
 *   rh.birthdays.intro_html  texto de introdução (HTML, mesmos placeholders)
 *   rh.birthdays.item_html   template HTML do corpo (modo avançado): {{lista}}
 *                            insere a tabela gerada; o bloco {{#cada}}...{{/cada}}
 *                            é repetido por aniversariante com {{nome}}, {{dia}},
 *                            {{data}}, {{departamento}}, {{cargo}}, {{foto}}, {{idade}}
 *   rh.birthdays.css         CSS extra (modo avançado)
 *   rh.birthdays.show_photo  1 = incluir foto (quando houver)
 *   rh.birthdays.order       'day' (dia do mês) ou 'name' (nome)
 *
 *   — modo visual —
 *   rh.birthdays.model       'cartoes' | 'lista' | 'tabela' | 'faixas'
 *   rh.birthdays.columns     2 | 3 | 4 (modelo cartões)
 *   rh.birthdays.accent      cor de destaque (#rrggbb) — vazio = cor da marca
 *   rh.birthdays.card_bg     fundo do cartão
 *   rh.birthdays.text_color  cor do texto
 *   rh.birthdays.border      1 = borda no cartão
 *   rh.birthdays.title_size  tamanho do título em pt
 *   rh.birthdays.font_size   tamanho do nome em pt
 *   rh.birthdays.photo_shape 'circulo' | 'arredondado' | 'quadrado'
 *   rh.birthdays.photo_size  diâmetro/lado da foto em mm
 *   rh.birthdays.show_*      day, date, position, department, age, emoji
 */
class BirthdayPoster
{
    public const MONTHS = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    /** Modelos prontos do modo visual. */
    public const MODELS = [
        'cartoes' => 'Cartões (grade)',
        'lista'   => 'Lista com foto',
        'tabela'  => 'Tabela',
        'faixas'  => 'Faixas destacadas',
    ];

    public const PHOTO_SHAPES = [
        'circulo'     => 'Redonda',
        'arredondado' => 'Cantos arredondados',
        'quadrado'    => 'Quadrada',
    ];

    /** Padrões do modo visual (também usados pelo botão "Restaurar padrão"). */
    public static function visualDefaults(): array
    {
        return [
            'model'       => 'cartoes',
            'columns'     => 3,
            'accent'      => '',          // vazio = cor da marca
            'card_bg'     => '#fbfcfe',
            'text_color'  => '#212529',
            'border'      => true,
            'title_size'  => 22,
            'font_size'   => 11,
            'photo_shape' => 'circulo',
            'photo_size'  => 26,
            'show_day'        => true,
            'show_date'       => false,
            'show_position'   => true,
            'show_department' => true,
            'show_age'        => false,
            'show_emoji'      => true,
        ];
    }

    /** Configuração efetiva (settings + padrões). */
    public static function settings(): array
    {
        $get = fn (string $k, string $d = '') => (string)(Core\Settings::get('rh.birthdays.' . $k, $d) ?? $d);
        $vd  = self::visualDefaults();
        $bool = fn (string $k) => $get($k, !empty($vd[$k]) ? '1' : '0') === '1';

        $s = [
            'mode'       => $get('mode', 'visual') === 'avancado' ? 'avancado' : 'visual',
            'layout_id'  => (int)$get('layout_id', '0'),
            'title'      => $get('title', 'Aniversariantes de {{mes}}'),
            'intro_html' => $get('intro_html', self::defaultIntro()),
            'item_html'  => $get('item_html', self::defaultItemHtml()),
            'css'        => $get('css', self::defaultCss()),
            'show_photo' => $get('show_photo', '1') === '1',
            'order'      => $get('order', 'day') === 'name' ? 'name' : 'day',

            // modo visual
            'model'       => isset(self::MODELS[$get('model', $vd['model'])]) ? $get('model', $vd['model']) : $vd['model'],
            'columns'     => max(1, min(4, (int)$get('columns', (string)$vd['columns']))),
            'accent'      => self::color($get('accent', $vd['accent']), ''),
            'card_bg'     => self::color($get('card_bg', $vd['card_bg']), $vd['card_bg']),
            'text_color'  => self::color($get('text_color', $vd['text_color']), $vd['text_color']),
            'border'      => $bool('border'),
            'title_size'  => max(8, min(48, (int)$get('title_size', (string)$vd['title_size']))),
            'font_size'   => max(6, min(24, (int)$get('font_size', (string)$vd['font_size']))),
            'photo_shape' => isset(self::PHOTO_SHAPES[$get('photo_shape', $vd['photo_shape'])]) ? $get('photo_shape', $vd['photo_shape']) : $vd['photo_shape'],
            'photo_size'  => max(8, min(60, (int)$get('photo_size', (string)$vd['photo_size']))),
            'show_day'        => $bool('show_day'),
            'show_date'       => $bool('show_date'),
            'show_position'   => $bool('show_position'),
            'show_department' => $bool('show_department'),
            'show_age'        => $bool('show_age'),
            'show_emoji'      => $bool('show_emoji'),
        ];
        if (trim($s['item_html']) === '') {
            $s['item_html'] = self::defaultItemHtml();
        }
        return $s;
    }

    /**
     * Configuração pronta para renderizar: no modo visual, item_html e css
     * são GERADOS a partir das opções — o que estiver gravado nesses dois
     * campos é ignorado, para que a tela visual não mostre uma coisa e o
     * cartaz saia outra.
     */
    public static function effective(array $override = []): array
    {
        $cfg = array_merge(self::settings(), $override);
        if (($cfg['mode'] ?? 'visual') !== 'avancado') {
            $cfg['item_html'] = self::buildItemHtml($cfg);
            $cfg['css']       = self::buildCss($cfg);
        }
        return $cfg;
    }

    /** Validação de cor: uma implementação só, em Core\Tokens. */
    private static function color(string $v, string $default): string
    {
        return Core\Tokens::color($v, $default);
    }

    /**
     * Cor de destaque efetiva. Vazio HERDA pela cadeia de tokens:
     * cartaz → impressão → marca do portal (Core\Tokens).
     */
    public static function accentColor(array $cfg): string
    {
        return Core\Tokens::resolve('birthday', 'primary', (string)($cfg['accent'] ?? ''));
    }

    // ── Modo visual: geração do HTML e do CSS ──────────────────────────────

    /** Monta o template do corpo a partir das opções visuais. */
    public static function buildItemHtml(array $cfg): string
    {
        $model = (string)($cfg['model'] ?? 'cartoes');
        $foto  = !empty($cfg['show_photo']) ? "    {{foto}}\n" : '';
        $emoji = !empty($cfg['show_emoji']) ? '🎂 ' : '';

        if ($model === 'tabela') {
            // A tabela é gerada por expandItems(); as colunas seguem as
            // opções marcadas.
            return '{{lista}}';
        }

        $linhas = '';
        if (!empty($cfg['show_day']) && empty($cfg['show_date'])) {
            $linhas .= "    <div class=\"bd-day\">{$emoji}<span>{{dia}}</span></div>\n";
        } elseif (!empty($cfg['show_date'])) {
            $linhas .= "    <div class=\"bd-day\">{$emoji}<span>{{data}}</span></div>\n";
        }
        $linhas .= $foto;
        $linhas .= "    <div class=\"bd-name\">{{nome}}</div>\n";
        if (!empty($cfg['show_position']))   $linhas .= "    <div class=\"bd-meta\">{{cargo}}</div>\n";
        if (!empty($cfg['show_department'])) $linhas .= "    <div class=\"bd-meta bd-dept\">{{departamento}}</div>\n";
        if (!empty($cfg['show_age']))        $linhas .= "    <div class=\"bd-meta bd-age\">{{idade}} anos</div>\n";

        $classeGrade = 'bd-grid bd-' . $model;
        return "<div class=\"{$classeGrade}\">\n{{#cada}}\n  <div class=\"bd-card\">\n"
             . $linhas
             . "  </div>\n{{/cada}}\n</div>";
    }

    /** Monta o CSS a partir das opções visuais. */
    public static function buildCss(array $cfg): string
    {
        $accent  = self::accentColor($cfg);
        $onAcc   = Core\Tokens::contrastColor($accent);
        $bg      = (string)($cfg['card_bg'] ?? '#fbfcfe');
        $txt     = (string)($cfg['text_color'] ?? '#212529');
        $tit     = (int)($cfg['title_size'] ?? 22);
        $fs      = (int)($cfg['font_size'] ?? 11);
        $cols    = max(1, min(4, (int)($cfg['columns'] ?? 3)));
        $model   = (string)($cfg['model'] ?? 'cartoes');
        $borda   = !empty($cfg['border']) ? '1px solid #e3e8ee' : 'none';
        $pSize   = (int)($cfg['photo_size'] ?? 26);
        $raio    = match ((string)($cfg['photo_shape'] ?? 'circulo')) {
            'quadrado'    => '0',
            'arredondado' => '3mm',
            default       => '50%',
        };

        // Largura do cartão a partir do número de colunas (A4 útil ≈ 180 mm).
        $card = max(30, (int) floor((180 - ($cols - 1) * 4) / $cols));

        $css  = ".bd-title { text-align:center; color:{$accent}; font-size:{$tit}pt; margin:0 0 4mm; }\n";
        $css .= ".bd-intro { text-align:center; color:#444; margin:0 0 6mm; }\n";
        $css .= ".bd-photo { width:{$pSize}mm; height:{$pSize}mm; border-radius:{$raio}; object-fit:cover; margin:2mm auto; display:block; border:2px solid #fff; box-shadow:0 0 0 1px #dfe5ec; }\n";
        $css .= ".bd-name { font-weight:bold; font-size:{$fs}pt; margin-top:1mm; color:{$txt}; }\n";
        $css .= ".bd-meta { font-size:" . max(6, $fs - 2.5) . "pt; color:#666; }\n";
        $css .= ".bd-dept { color:{$accent}; }\n";
        $css .= ".bd-empty { text-align:center; color:#888; padding:10mm 0; }\n";

        if ($model === 'cartoes') {
            $css .= ".bd-grid { display:flex; flex-wrap:wrap; gap:4mm; justify-content:center; }\n";
            $css .= ".bd-card { width:{$card}mm; border:{$borda}; border-radius:4mm; padding:4mm 3mm; text-align:center;\n";
            $css .= "  background:{$bg}; break-inside:avoid; page-break-inside:avoid; }\n";
            $css .= ".bd-day { font-size:" . ($fs + 3) . "pt; font-weight:bold; color:{$accent}; }\n";
            $css .= ".bd-day span { display:inline-block; min-width:9mm; }\n";
        } elseif ($model === 'lista') {
            $css .= ".bd-grid { display:block; }\n";
            $css .= ".bd-card { display:flex; align-items:center; gap:4mm; border:{$borda}; border-bottom:1px solid #e3e8ee;\n";
            $css .= "  border-radius:0; padding:2.5mm 2mm; background:{$bg}; text-align:left;\n";
            $css .= "  break-inside:avoid; page-break-inside:avoid; }\n";
            $css .= ".bd-card .bd-photo { margin:0; flex:0 0 auto; }\n";
            $css .= ".bd-card .bd-name { flex:1 1 auto; margin:0; }\n";
            $css .= ".bd-day { font-size:" . ($fs + 1) . "pt; font-weight:bold; color:{$accent}; min-width:16mm; }\n";
            $css .= ".bd-meta { min-width:35mm; }\n";
        } elseif ($model === 'faixas') {
            $css .= ".bd-grid { display:block; }\n";
            $css .= ".bd-card { display:flex; align-items:center; gap:5mm; border:none; border-left:3mm solid {$accent};\n";
            $css .= "  border-radius:0 3mm 3mm 0; padding:4mm; margin-bottom:3mm; background:{$bg}; text-align:left;\n";
            $css .= "  break-inside:avoid; page-break-inside:avoid; }\n";
            $css .= ".bd-card .bd-photo { margin:0; flex:0 0 auto; }\n";
            $css .= ".bd-card .bd-name { flex:1 1 auto; margin:0; font-size:" . ($fs + 3) . "pt; }\n";
            $css .= ".bd-day { font-size:" . ($fs + 6) . "pt; font-weight:bold; color:{$accent}; min-width:20mm; text-align:center; }\n";
        } else { // tabela
            $css .= ".bd-table { width:100%; border-collapse:collapse; }\n";
            $css .= ".bd-table th { background:{$accent}; color:{$onAcc}; text-align:left; padding:2mm; font-size:" . max(7, $fs - 1.5) . "pt; }\n";
            $css .= ".bd-table td { padding:1.5mm 2mm; border-bottom:1px solid #e3e8ee; font-size:" . max(7, $fs - 1.5) . "pt; color:{$txt}; }\n";
            $css .= ".bd-table tr:nth-child(even) td { background:{$bg}; }\n";
            $css .= ".bd-photo { width:" . min($pSize, 14) . "mm; height:" . min($pSize, 14) . "mm; margin:0; }\n";
        }
        return $css;
    }

    /** Persiste a configuração (valores já validados pelo controller). */
    public static function save(array $s): void
    {
        $vd  = self::visualDefaults();
        $set = fn (string $k, string $v) => Core\Settings::set('rh.birthdays.' . $k, $v);
        $flag = function (string $k) use ($s, $vd) {
            // Checkbox ausente no POST = desmarcado; só se o formulário
            // realmente enviou o conjunto de opções visuais.
            return !empty($s[$k]) ? '1' : '0';
        };

        $set('mode',       ($s['mode'] ?? 'visual') === 'avancado' ? 'avancado' : 'visual');
        $set('layout_id',  (string)(int)($s['layout_id'] ?? 0));
        $set('title',      trim(strip_tags((string)($s['title'] ?? ''))));
        $set('intro_html', Core\DocLayout::sanitizeHtml((string)($s['intro_html'] ?? '')));
        $set('item_html',  Core\DocLayout::sanitizeHtml((string)($s['item_html'] ?? '')));
        $set('css',        self::sanitizeCss((string)($s['css'] ?? '')));
        $set('show_photo', !empty($s['show_photo']) ? '1' : '0');
        $set('order',      ($s['order'] ?? 'day') === 'name' ? 'name' : 'day');

        // Visuais
        $set('model',       isset(self::MODELS[$s['model'] ?? '']) ? (string)$s['model'] : $vd['model']);
        $set('columns',     (string)max(1, min(4, (int)($s['columns'] ?? $vd['columns']))));
        $set('accent',      self::color((string)($s['accent'] ?? ''), ''));
        $set('card_bg',     self::color((string)($s['card_bg'] ?? ''), $vd['card_bg']));
        $set('text_color',  self::color((string)($s['text_color'] ?? ''), $vd['text_color']));
        $set('border',      $flag('border'));
        $set('title_size',  (string)max(8, min(48, (int)($s['title_size'] ?? $vd['title_size']))));
        $set('font_size',   (string)max(6, min(24, (int)($s['font_size'] ?? $vd['font_size']))));
        $set('photo_shape', isset(self::PHOTO_SHAPES[$s['photo_shape'] ?? '']) ? (string)$s['photo_shape'] : $vd['photo_shape']);
        $set('photo_size',  (string)max(8, min(60, (int)($s['photo_size'] ?? $vd['photo_size']))));
        foreach (['show_day', 'show_date', 'show_position', 'show_department', 'show_age', 'show_emoji'] as $k) {
            $set($k, $flag($k));
        }
    }

    public static function defaultIntro(): string
    {
        return '<p class="bd-intro">Parabéns a todos os colaboradores que celebram mais um ano de vida em {{mes}} de {{ano}}. '
             . 'Que este novo ciclo seja repleto de saúde, alegria e conquistas! 🎉</p>';
    }

    public static function defaultItemHtml(): string
    {
        return "<div class=\"bd-grid\">\n"
             . "{{#cada}}\n"
             . "  <div class=\"bd-card\">\n"
             . "    <div class=\"bd-day\">🎂 <span>{{dia}}</span></div>\n"
             . "    {{foto}}\n"
             . "    <div class=\"bd-name\">{{nome}}</div>\n"
             . "    <div class=\"bd-meta\">{{cargo}}</div>\n"
             . "    <div class=\"bd-meta bd-dept\">{{departamento}}</div>\n"
             . "  </div>\n"
             . "{{/cada}}\n"
             . "</div>";
    }

    /**
     * CSS padrão do cartaz. As cores da marca (Administração > Aparência)
     * entram aqui porque o cartaz é impresso — não há variáveis CSS para
     * herdar no PDF. Quem editar o CSS na administração assume o controle:
     * o texto salvo é usado como está.
     */
    public static function defaultCss(): string
    {
        $brand = Core\Branding::get('primary');
        $onBrand = Core\Tokens::contrastColor($brand);

        return ".bd-title { text-align:center; color:{$brand}; font-size:22pt; margin:0 0 4mm; }\n"
             . ".bd-intro { text-align:center; color:#444; margin:0 0 6mm; }\n"
             . ".bd-grid { display:flex; flex-wrap:wrap; gap:4mm; justify-content:center; }\n"
             . ".bd-card { width:52mm; border:1px solid #e3e8ee; border-radius:4mm; padding:4mm 3mm; text-align:center;\n"
             . "  background:#fbfcfe; break-inside:avoid; page-break-inside:avoid; }\n"
             . ".bd-day { font-size:14pt; font-weight:bold; color:#d63384; }\n"
             . ".bd-day span { display:inline-block; min-width:9mm; }\n"
             . ".bd-photo { width:26mm; height:26mm; border-radius:50%; object-fit:cover; margin:2mm auto; display:block; border:2px solid #fff; box-shadow:0 0 0 1px #dfe5ec; }\n"
             . ".bd-name { font-weight:bold; font-size:11pt; margin-top:1mm; }\n"
             . ".bd-meta { font-size:8.5pt; color:#666; }\n"
             . ".bd-dept { color:{$brand}; }\n"
             . ".bd-table { width:100%; border-collapse:collapse; }\n"
             . ".bd-table th { background:{$brand}; color:{$onBrand}; text-align:left; padding:2mm; font-size:9.5pt; }\n"
             . ".bd-table td { padding:1.5mm 2mm; border-bottom:1px solid #e3e8ee; font-size:9.5pt; }\n"
             . ".bd-empty { text-align:center; color:#888; padding:10mm 0; }";
    }

    /** Aniversariantes ativos do mês (uma consulta). */
    public static function employees(int $month, int $department = 0, string $order = 'day'): array
    {
        $where  = ["MONTH(e.birth_date) = ?", "e.status = 'ativo'", 'e.anonymized_at IS NULL'];
        $params = [$month];
        if ($department > 0) {
            $where[]  = 'e.department_id = ?';
            $params[] = $department;
        }
        $orderBy = $order === 'name' ? 'e.full_name ASC' : 'DAY(e.birth_date) ASC, e.full_name ASC';
        return Core\DB::query(
            "SELECT e.id, e.full_name, e.birth_date, e.photo, DAY(e.birth_date) AS birth_day,
                    d.name AS department_name, j.title AS position_title
             FROM rh_employees e
             LEFT JOIN rh_departments d ON e.department_id = d.id
             LEFT JOIN rh_job_positions j ON e.job_position_id = j.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY {$orderBy}",
            $params
        );
    }

    /**
     * HTML completo (página standalone imprimível) do cartaz.
     * @param array $override configuração alternativa (pré-visualização sem salvar)
     */
    public static function render(int $month, int $year, int $department = 0, array $override = [], bool $autoprint = false): string
    {
        $cfg   = self::effective($override);
        $month = ($month >= 1 && $month <= 12) ? $month : (int)date('n');
        $year  = $year > 1900 ? $year : (int)date('Y');
        $org   = Core\Branding::name();
        $rows  = self::employees($month, $department, (string)$cfg['order']);

        $vars = [
            '{{mes}}'   => Sanitize::e(self::MONTHS[$month]),
            '{{ano}}'   => (string)$year,
            '{{org}}'   => Sanitize::e($org),
            '{{total}}' => (string)count($rows),
        ];

        // Título é texto puro: escapa ANTES de substituir os placeholders (já escapados).
        $title = strtr(Sanitize::e(strip_tags((string)$cfg['title'])), $vars);
        $intro = strtr((string)$cfg['intro_html'], $vars);
        $body  = self::expandItems((string)$cfg['item_html'], $rows, $month, $year, (bool)$cfg['show_photo'], $cfg);
        $body  = strtr($body, $vars);
        if ($rows === []) {
            $body = '<div class="bd-empty">Nenhum aniversariante neste mês.</div>';
        }

        $layout = Core\DocLayout::findOrDefault((int)$cfg['layout_id']) ?? self::fallbackLayout();

        $content = '<h1 class="bd-title">' . $title . '</h1>' . $intro . $body;

        $toolbar = '<strong>' . $title . '</strong><span class="spacer"></span>'
                 . '<button onclick="window.print()">Imprimir / Salvar em PDF</button>'
                 . '<a href="' . Sanitize::e(core_module_url('rh', ['page' => 'birthdays', 'month' => $month])) . '">Voltar</a>';

        return Core\DocLayout::renderHtml([
            'title'        => html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'layout'       => $layout,
            'content_html' => $content,
            'meta'         => [
                'title'    => html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'author'   => core_user()['name'] ?? 'RH',
                'date'     => date('d/m/Y'),
                'version'  => '1',
                'sector'   => 'Recursos Humanos',
                'subtitle' => 'Aniversariantes de ' . self::MONTHS[$month] . '/' . $year,
            ],
            'toolbar'      => $toolbar,
            'autoprint'    => $autoprint,
            // CSS sempre sanitizado aqui (vale também para a pré-visualização, que não passa por save()).
            'extra_css'    => self::sanitizeCss((string)$cfg['css']),
        ]);
    }

    /** Expande {{lista}} e o bloco {{#cada}}...{{/cada}} do template. */
    private static function expandItems(string $tpl, array $rows, int $month, int $year, bool $showPhoto, array $cfg = []): string
    {
        $itemVars = function (array $r) use ($month, $year, $showPhoto): array {
            $birth = strtotime((string)$r['birth_date']) ?: time();
            $age   = $year - (int)date('Y', $birth);
            $photo = '';
            if ($showPhoto && !empty($r['photo'])) {
                $photo = '<img class="bd-photo" src="' . Sanitize::e(Upload::publicUrl($r['photo'])) . '" alt="">';
            }
            return [
                '{{nome}}'         => Sanitize::e($r['full_name']),
                '{{dia}}'          => sprintf('%02d', (int)$r['birth_day']),
                '{{data}}'         => sprintf('%02d/%02d', (int)$r['birth_day'], $month),
                '{{departamento}}' => Sanitize::e($r['department_name'] ?? ''),
                '{{cargo}}'        => Sanitize::e($r['position_title'] ?? ''),
                '{{foto}}'         => $photo,
                '{{idade}}'        => (string)$age,
            ];
        };

        // Bloco repetido por aniversariante.
        $tpl = preg_replace_callback('/\{\{#cada\}\}(.*?)\{\{\/cada\}\}/s', function ($m) use ($rows, $itemVars) {
            $out = '';
            foreach ($rows as $r) {
                $out .= strtr($m[1], $itemVars($r));
            }
            return $out;
        }, $tpl) ?? $tpl;

        // Tabela gerada. As colunas seguem o que o modo visual marcou; no
        // modo avançado, sem $cfg, mantém-se o conjunto completo de antes.
        if (str_contains($tpl, '{{lista}}')) {
            $tem = fn (string $k, bool $padrao = true) => $cfg === [] ? $padrao : !empty($cfg[$k]);
            $colData = $tem('show_date', true) || $tem('show_day', true);

            $cabec = '';
            if ($colData)              $cabec .= '<th>Dia</th>';
            if ($showPhoto)            $cabec .= '<th></th>';
            $cabec .= '<th>Nome</th>';
            if ($tem('show_position'))   $cabec .= '<th>Cargo</th>';
            if ($tem('show_department')) $cabec .= '<th>Departamento</th>';
            if ($tem('show_age', false)) $cabec .= '<th>Idade</th>';

            $table = '<table class="bd-table"><thead><tr>' . $cabec . '</tr></thead><tbody>';
            foreach ($rows as $r) {
                $v = $itemVars($r);
                $table .= '<tr>';
                if ($colData)   $table .= '<td>' . ($tem('show_date', false) ? $v['{{data}}'] : $v['{{dia}}']) . '</td>';
                if ($showPhoto) $table .= '<td>' . $v['{{foto}}'] . '</td>';
                $table .= '<td>' . $v['{{nome}}'] . '</td>';
                if ($tem('show_position'))   $table .= '<td>' . $v['{{cargo}}'] . '</td>';
                if ($tem('show_department')) $table .= '<td>' . $v['{{departamento}}'] . '</td>';
                if ($tem('show_age', false)) $table .= '<td>' . $v['{{idade}}'] . '</td>';
                $table .= '</tr>';
            }
            $table .= '</tbody></table>';
            $tpl = str_replace('{{lista}}', $table, $tpl);
        }
        return $tpl;
    }

    /** Layout mínimo quando não há nenhum cadastrado em intra_layouts. */
    private static function fallbackLayout(): array
    {
        return [
            'id' => 0, 'name' => 'Padrão', 'page_size' => 'A4', 'orientation' => 'portrait',
            'margin_top' => 20, 'margin_right' => 15, 'margin_bottom' => 20, 'margin_left' => 15,
            'header_html' => '', 'footer_html' => '', 'header_height' => 0, 'footer_height' => 0,
            'custom_css' => '', 'fonts' => null, 'font_sizes' => null, 'default_font' => 'Arial', 'default_font_size' => '11pt',
            'logo_path' => null, 'background_path' => null, 'cover_html' => null, 'cover_background_path' => null,
        ];
    }

    /** Remove construções perigosas do CSS (expression/url javascript/@import). */
    private static function sanitizeCss(string $css): string
    {
        $css = str_replace(['</style', '<script'], '', $css);
        $css = preg_replace('/expression\s*\(|@import|javascript:/i', '', $css) ?? $css;
        return trim($css);
    }
}
