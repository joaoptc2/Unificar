<?php
/**
 * BirthdayPoster — cartaz A4 dos aniversariantes do mês no papel timbrado
 * (Core\DocLayout::renderHtml), com layout personalizável na Administração
 * central (aba "Aniversariantes (A4)" do módulo RH).
 *
 * Configuração (Core\Settings):
 *   rh.birthdays.layout_id   layout de página (intra_layouts) — vazio = padrão
 *   rh.birthdays.title       título (aceita {{mes}}, {{ano}}, {{org}}, {{total}})
 *   rh.birthdays.intro_html  texto de introdução (HTML, mesmos placeholders)
 *   rh.birthdays.item_html   template HTML do corpo: {{lista}} insere a tabela
 *                            gerada; o bloco {{#cada}}...{{/cada}} é repetido
 *                            por aniversariante com {{nome}}, {{dia}}, {{data}},
 *                            {{departamento}}, {{cargo}}, {{foto}}, {{idade}}
 *   rh.birthdays.css         CSS extra
 *   rh.birthdays.show_photo  1 = incluir foto (quando houver)
 *   rh.birthdays.order       'day' (dia do mês) ou 'name' (nome)
 */
class BirthdayPoster
{
    public const MONTHS = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    /** Configuração efetiva (settings + padrões). */
    public static function settings(): array
    {
        $get = fn (string $k, string $d = '') => (string)(Core\Settings::get('rh.birthdays.' . $k, $d) ?? $d);
        $s = [
            'layout_id'  => (int)$get('layout_id', '0'),
            'title'      => $get('title', 'Aniversariantes de {{mes}}'),
            'intro_html' => $get('intro_html', self::defaultIntro()),
            'item_html'  => $get('item_html', self::defaultItemHtml()),
            'css'        => $get('css', self::defaultCss()),
            'show_photo' => $get('show_photo', '1') === '1',
            'order'      => $get('order', 'day') === 'name' ? 'name' : 'day',
        ];
        if (trim($s['item_html']) === '') {
            $s['item_html'] = self::defaultItemHtml();
        }
        return $s;
    }

    /** Persiste a configuração (valores já validados pelo controller). */
    public static function save(array $s): void
    {
        Core\Settings::set('rh.birthdays.layout_id',  (string)(int)($s['layout_id'] ?? 0));
        Core\Settings::set('rh.birthdays.title',      (string)($s['title'] ?? ''));
        Core\Settings::set('rh.birthdays.intro_html', Core\DocLayout::sanitizeHtml((string)($s['intro_html'] ?? '')));
        Core\Settings::set('rh.birthdays.item_html',  Core\DocLayout::sanitizeHtml((string)($s['item_html'] ?? '')));
        Core\Settings::set('rh.birthdays.css',        self::sanitizeCss((string)($s['css'] ?? '')));
        Core\Settings::set('rh.birthdays.show_photo', !empty($s['show_photo']) ? '1' : '0');
        Core\Settings::set('rh.birthdays.order',      ($s['order'] ?? 'day') === 'name' ? 'name' : 'day');
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

    public static function defaultCss(): string
    {
        return ".bd-title { text-align:center; color:#0d5c8f; font-size:22pt; margin:0 0 4mm; }\n"
             . ".bd-intro { text-align:center; color:#444; margin:0 0 6mm; }\n"
             . ".bd-grid { display:flex; flex-wrap:wrap; gap:4mm; justify-content:center; }\n"
             . ".bd-card { width:52mm; border:1px solid #e3e8ee; border-radius:4mm; padding:4mm 3mm; text-align:center;\n"
             . "  background:#fbfcfe; break-inside:avoid; page-break-inside:avoid; }\n"
             . ".bd-day { font-size:14pt; font-weight:bold; color:#d63384; }\n"
             . ".bd-day span { display:inline-block; min-width:9mm; }\n"
             . ".bd-photo { width:26mm; height:26mm; border-radius:50%; object-fit:cover; margin:2mm auto; display:block; border:2px solid #fff; box-shadow:0 0 0 1px #dfe5ec; }\n"
             . ".bd-name { font-weight:bold; font-size:11pt; margin-top:1mm; }\n"
             . ".bd-meta { font-size:8.5pt; color:#666; }\n"
             . ".bd-dept { color:#0d5c8f; }\n"
             . ".bd-table { width:100%; border-collapse:collapse; }\n"
             . ".bd-table th { background:#0d5c8f; color:#fff; text-align:left; padding:2mm; font-size:9.5pt; }\n"
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
        $cfg   = array_merge(self::settings(), $override);
        $month = ($month >= 1 && $month <= 12) ? $month : (int)date('n');
        $year  = $year > 1900 ? $year : (int)date('Y');
        $org   = (string)(Core\Settings::get('org_name', core_config('app.name', '')) ?? '');
        $rows  = self::employees($month, $department, (string)$cfg['order']);

        $vars = [
            '{{mes}}'   => Sanitize::e(self::MONTHS[$month]),
            '{{ano}}'   => (string)$year,
            '{{org}}'   => Sanitize::e($org),
            '{{total}}' => (string)count($rows),
        ];

        $title = strtr((string)$cfg['title'], $vars);
        $intro = strtr((string)$cfg['intro_html'], $vars);
        $body  = self::expandItems((string)$cfg['item_html'], $rows, $month, $year, (bool)$cfg['show_photo']);
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
            'title'        => strip_tags($title),
            'layout'       => $layout,
            'content_html' => $content,
            'meta'         => [
                'title'    => strip_tags($title),
                'author'   => core_user()['name'] ?? 'RH',
                'date'     => date('d/m/Y'),
                'version'  => '1',
                'sector'   => 'Recursos Humanos',
                'subtitle' => 'Aniversariantes de ' . self::MONTHS[$month] . '/' . $year,
            ],
            'toolbar'      => $toolbar,
            'autoprint'    => $autoprint,
            'extra_css'    => (string)$cfg['css'],
        ]);
    }

    /** Expande {{lista}} e o bloco {{#cada}}...{{/cada}} do template. */
    private static function expandItems(string $tpl, array $rows, int $month, int $year, bool $showPhoto): string
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

        // Tabela gerada.
        if (str_contains($tpl, '{{lista}}')) {
            $table = '<table class="bd-table"><thead><tr><th>Dia</th>' . ($showPhoto ? '<th></th>' : '')
                   . '<th>Nome</th><th>Cargo</th><th>Departamento</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $v = $itemVars($r);
                $table .= '<tr><td>' . $v['{{data}}'] . '</td>' . ($showPhoto ? '<td>' . $v['{{foto}}'] . '</td>' : '')
                        . '<td>' . $v['{{nome}}'] . '</td><td>' . $v['{{cargo}}'] . '</td><td>' . $v['{{departamento}}'] . '</td></tr>';
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
