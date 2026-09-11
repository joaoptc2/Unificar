<?php
/**
 * PLANEJAMENTO — diagramas / fluxogramas / mapas de processo.
 *
 * Renderizador SVG servidor, validação/normalização do JSON (formato v1) e
 * padrões compartilhados com o editor visual (assets/planejamento/diagram-editor.js).
 * A geometria (formas, portas de conexão, roteamento e quebra de texto) é a
 * MESMA do editor, para que miniaturas, impressão e exportação coincidam com
 * o que o usuário vê na tela.
 *
 * Formato (v1):
 *   {"v":1,"canvas":{"w":1600,"h":1000,"grid":20,"bg":"#ffffff"},
 *    "nodes":[{"id":"n1","type":"process","x":100,"y":80,"w":200,"h":70,"text":"Etapa",
 *              "fill":"#fff","stroke":"#1565c0","color":"#212529","fontSize":14,"link":null}],
 *    "edges":[{"id":"e1","from":"n1","to":"n2","label":"Sim","fromSide":"bottom","toSide":"top",
 *              "style":"solid|dashed","arrow":"end|both|none","route":"ortho|straight","points":[]}]}
 */

declare(strict_types=1);

if (!defined('PLAN_DIAGRAM_MAX_BYTES')) {
    define('PLAN_DIAGRAM_MAX_BYTES', 2 * 1024 * 1024); // 2 MB
}

/** Tipos de diagrama (coluna plan_diagrams.kind → rótulo). */
function plan_diagram_kinds(): array
{
    return [
        'flowchart'   => 'Fluxograma',
        'process_map' => 'Mapa de processo',
        'diagram'     => 'Diagrama',
        'org_chart'   => 'Organograma',
        'mind_map'    => 'Mapa mental',
        'swot'        => 'SWOT',
        'other'       => 'Outro',
    ];
}

/**
 * Tipos de nó e seus padrões (rótulo, tamanho e cores). Mantido em sincronia
 * com TYPES em diagram-editor.js.
 */
function plan_diagram_node_types(): array
{
    return [
        'start'      => ['label' => 'Início',          'w' => 160, 'h' => 56,  'fill' => '#e8f5e9', 'stroke' => '#2e7d32'],
        'end'        => ['label' => 'Fim',             'w' => 160, 'h' => 56,  'fill' => '#ffebee', 'stroke' => '#c62828'],
        'process'    => ['label' => 'Processo',        'w' => 200, 'h' => 70,  'fill' => '#ffffff', 'stroke' => '#1565c0'],
        'decision'   => ['label' => 'Decisão',         'w' => 180, 'h' => 110, 'fill' => '#fff8e1', 'stroke' => '#ef6c00'],
        'io'         => ['label' => 'Entrada / saída', 'w' => 200, 'h' => 70,  'fill' => '#ede7f6', 'stroke' => '#5e35b1'],
        'document'   => ['label' => 'Documento',       'w' => 200, 'h' => 80,  'fill' => '#ffffff', 'stroke' => '#546e7a'],
        'database'   => ['label' => 'Banco de dados',  'w' => 160, 'h' => 90,  'fill' => '#e0f2f1', 'stroke' => '#00695c'],
        'subprocess' => ['label' => 'Subprocesso',     'w' => 220, 'h' => 70,  'fill' => '#e3f2fd', 'stroke' => '#1565c0'],
        'note'       => ['label' => 'Nota',            'w' => 200, 'h' => 90,  'fill' => '#fff9c4', 'stroke' => '#f9a825'],
        'text'       => ['label' => 'Texto',           'w' => 200, 'h' => 40,  'fill' => 'none',    'stroke' => 'none'],
        'lane'       => ['label' => 'Raia',            'w' => 800, 'h' => 220, 'fill' => '#f8f9fa', 'stroke' => '#adb5bd'],
        'circle'     => ['label' => 'Conector',        'w' => 60,  'h' => 60,  'fill' => '#ffffff', 'stroke' => '#1565c0'],
        'actor'      => ['label' => 'Ator',            'w' => 100, 'h' => 120, 'fill' => '#ffffff', 'stroke' => '#37474f'],
    ];
}

/** Diagrama em branco. */
function plan_diagram_default(): array
{
    return [
        'v'      => 1,
        'canvas' => ['w' => 1600, 'h' => 1000, 'grid' => 20, 'bg' => '#ffffff'],
        'nodes'  => [],
        'edges'  => [],
    ];
}

// ---------------------------------------------------------------------------
// Validação / normalização
// ---------------------------------------------------------------------------

/** Cor aceita: #rgb, #rrggbb, #rrggbbaa, "none" ou "transparent". */
function plan_diagram_color(mixed $v, ?string $default = null): ?string
{
    if (!is_string($v)) {
        return $default;
    }
    $v = strtolower(trim($v));
    if ($v === 'none' || $v === 'transparent') {
        return 'none';
    }
    if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/', $v)) {
        return $v;
    }
    return $default;
}

/** Link seguro (http/https/mailto ou relativo ao portal). */
function plan_diagram_link(mixed $v): ?string
{
    if (!is_string($v)) {
        return null;
    }
    $v = trim($v);
    if ($v === '' || strlen($v) > 500 || preg_match('/[\x00-\x1f\x7f]/', $v)) {
        return null;
    }
    // "//host", "\\host" e "/\host" apontam para fora do portal (protocolo relativo)
    $p = substr(str_replace('\\', '/', $v), 0, 2);
    if ($p === '//') {
        return null;
    }
    // aspas e sinais de marcação não fazem parte de uma URL utilizável aqui
    if (strpbrk($v, "\"'<>`") !== false) {
        return null;
    }
    if (preg_match('#^(https?://|mailto:)#i', $v) || preg_match('#^(/|\./|index\.php|\?)#', $v)) {
        return $v;
    }
    return null;
}

/**
 * Texto de uma única linha (títulos, nomes, notas): sem quebras de linha nem
 * tabulações — evita quebrar atributos HTML/JS e cabeçalhos.
 */
function plan_diagram_line(mixed $v, int $max = 200): string
{
    $s = str_replace(["\n", "\t"], ' ', plan_diagram_text($v, $max * 4));
    $s = trim((string) preg_replace('/ {2,}/', ' ', $s));
    return mb_substr($s, 0, $max);
}

/** Texto plano: sem tags, sem caracteres de controle (exceto quebra de linha), com limite. */
function plan_diagram_text(mixed $v, int $max = 2000): string
{
    if (!is_scalar($v)) {
        return '';
    }
    $s = (string) $v;
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[^\P{C}\n\t]/u', '', $s) ?? '';
    if ($s === '' || !mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
    return mb_substr($s, 0, $max);
}

function plan_diagram_num(mixed $v, float $default, float $min, float $max): float
{
    if (!is_numeric($v)) {
        return $default;
    }
    $f = (float) $v;
    if (!is_finite($f)) {
        return $default;
    }
    return round(max($min, min($max, $f)), 2);
}

function plan_diagram_id(mixed $v): string
{
    if (!is_string($v) && !is_int($v)) {
        return '';
    }
    $s = (string) $v;
    return preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $s) ? $s : '';
}

/**
 * Normaliza e valida o JSON do diagrama: ids únicos, tipos conhecidos,
 * números dentro de limites, cores/links seguros, arestas órfãs removidas.
 * Nunca lança exceção — entradas inválidas resultam em valores padrão.
 */
function plan_diagram_validate(array $data): array
{
    $types = plan_diagram_node_types();
    $sides = ['top', 'right', 'bottom', 'left'];
    $out   = plan_diagram_default();

    $c = is_array($data['canvas'] ?? null) ? $data['canvas'] : [];
    $out['canvas'] = [
        'w'    => (int) plan_diagram_num($c['w'] ?? null, 1600, 200, 20000),
        'h'    => (int) plan_diagram_num($c['h'] ?? null, 1000, 200, 20000),
        'grid' => (int) plan_diagram_num($c['grid'] ?? null, 20, 0, 200),
        'bg'   => plan_diagram_color($c['bg'] ?? null, '#ffffff') ?? '#ffffff',
    ];

    // ids válidos declarados (para que ids gerados não colidam com eles)
    $reserved = [];
    foreach ((array) ($data['nodes'] ?? []) as $n) {
        if (is_array($n) && ($rid = plan_diagram_id($n['id'] ?? null)) !== '') {
            $reserved[$rid] = true;
        }
    }
    $ids   = [];
    $nodes = [];
    $seq   = 0;
    foreach ((array) ($data['nodes'] ?? []) as $n) {
        if (!is_array($n)) {
            continue;
        }
        if (count($nodes) >= 2000) {
            break;
        }
        $type = is_string($n['type'] ?? null) && isset($types[$n['type']]) ? $n['type'] : 'process';
        $def  = $types[$type];
        $id   = plan_diagram_id($n['id'] ?? null);
        while ($id === '' || isset($ids[$id])) {
            $seq++;
            $id = 'n' . $seq;
            if (isset($reserved[$id])) {
                $id = '';
            }
        }
        $ids[$id] = true;
        $node = [
            'id'   => $id,
            'type' => $type,
            'x'    => plan_diagram_num($n['x'] ?? null, 0, -100000, 100000),
            'y'    => plan_diagram_num($n['y'] ?? null, 0, -100000, 100000),
            'w'    => plan_diagram_num($n['w'] ?? null, (float) $def['w'], 10, 20000),
            'h'    => plan_diagram_num($n['h'] ?? null, (float) $def['h'], 10, 20000),
            'text' => plan_diagram_text($n['text'] ?? ''),
        ];
        foreach (['fill', 'stroke', 'color'] as $k) {
            $col = plan_diagram_color($n[$k] ?? null);
            if ($col !== null) {
                $node[$k] = $col;
            }
        }
        if (isset($n['fontSize']) && is_numeric($n['fontSize'])) {
            $node['fontSize'] = (int) plan_diagram_num($n['fontSize'], 14, 6, 120);
        }
        if (!empty($n['bold'])) {
            $node['bold'] = true;
        }
        if (isset($n['align']) && in_array($n['align'], ['left', 'center', 'right'], true)) {
            $node['align'] = $n['align'];
        }
        if (isset($n['strokeWidth']) && is_numeric($n['strokeWidth'])) {
            $node['strokeWidth'] = plan_diagram_num($n['strokeWidth'], 2, 0, 20);
        }
        $link = plan_diagram_link($n['link'] ?? null);
        if ($link !== null) {
            $node['link'] = $link;
        }
        $nodes[] = $node;
    }

    $edges = [];
    $eids  = [];
    $eseq  = 0;
    foreach ((array) ($data['edges'] ?? []) as $e) {
        if (!is_array($e)) {
            continue;
        }
        if (count($edges) >= 4000) {
            break;
        }
        $from = plan_diagram_id($e['from'] ?? null);
        $to   = plan_diagram_id($e['to'] ?? null);
        if ($from === '' || $to === '' || !isset($ids[$from]) || !isset($ids[$to])) {
            continue; // aresta órfã
        }
        $id = plan_diagram_id($e['id'] ?? null);
        while ($id === '' || isset($eids[$id])) {
            $eseq++;
            $id = 'e' . $eseq;
        }
        $eids[$id] = true;
        $edge = ['id' => $id, 'from' => $from, 'to' => $to];
        $label = plan_diagram_text($e['label'] ?? '', 200);
        if ($label !== '') {
            $edge['label'] = $label;
        }
        foreach (['fromSide', 'toSide'] as $k) {
            if (isset($e[$k]) && in_array($e[$k], $sides, true)) {
                $edge[$k] = $e[$k];
            }
        }
        if (($e['style'] ?? '') === 'dashed') {
            $edge['style'] = 'dashed';
        }
        if (isset($e['arrow']) && in_array($e['arrow'], ['end', 'both', 'none'], true)) {
            $edge['arrow'] = $e['arrow'];
        }
        if (($e['route'] ?? '') === 'straight') {
            $edge['route'] = 'straight';
        }
        $col = plan_diagram_color($e['color'] ?? null);
        if ($col !== null && $col !== 'none') {
            $edge['color'] = $col;
        }
        if (isset($e['width']) && is_numeric($e['width'])) {
            $edge['width'] = plan_diagram_num($e['width'], 2, 0.5, 12);
        }
        $pts = [];
        foreach ((array) ($e['points'] ?? []) as $p) {
            if (count($pts) >= 50) {
                break;
            }
            if (is_array($p) && isset($p['x'], $p['y']) && is_numeric($p['x']) && is_numeric($p['y'])) {
                $pts[] = ['x' => plan_diagram_num($p['x'], 0, -100000, 100000), 'y' => plan_diagram_num($p['y'], 0, -100000, 100000)];
            } elseif (is_array($p) && isset($p[0], $p[1]) && is_numeric($p[0]) && is_numeric($p[1])) {
                $pts[] = ['x' => plan_diagram_num($p[0], 0, -100000, 100000), 'y' => plan_diagram_num($p[1], 0, -100000, 100000)];
            }
        }
        if ($pts) {
            $edge['points'] = $pts;
        }
        $edges[] = $edge;
    }

    $out['nodes'] = $nodes;
    $out['edges'] = $edges;
    return $out;
}

/** Decodifica JSON (string) já validado; em caso de erro devolve o diagrama padrão. */
function plan_diagram_decode(?string $json): array
{
    if ($json === null || $json === '') {
        return plan_diagram_default();
    }
    try {
        $d = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (\Throwable) {
        return plan_diagram_default();
    }
    return plan_diagram_validate(is_array($d) ? $d : []);
}

/** JSON canônico (para comparar versões e gravar). */
function plan_diagram_encode(array $data): string
{
    return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ---------------------------------------------------------------------------
// Geometria compartilhada (idêntica ao editor JS)
// ---------------------------------------------------------------------------

/** Largura estimada de um caractere (em "em"). */
function plan_diagram_char_w(string $ch): float
{
    if ($ch === ' ') {
        return 0.28;
    }
    if (strpos("ijl|!.,:;'", $ch) !== false) {
        return 0.28;
    }
    if (strpos('ftrI()[]-', $ch) !== false) {
        return 0.36;
    }
    if (strpos('mwMW@', $ch) !== false) {
        return 0.88;
    }
    if (strlen($ch) > 1) { // multibyte (acentuadas etc.)
        return mb_strtoupper($ch) === $ch && mb_strtolower($ch) !== $ch ? 0.68 : 0.56;
    }
    if (ctype_upper($ch)) {
        return 0.68;
    }
    return 0.56;
}

function plan_diagram_text_width(string $s, float $fs): float
{
    $w = 0.0;
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        $w += plan_diagram_char_w($ch);
    }
    return $w * $fs;
}

/** Quebra o texto em linhas que caibam em $maxW (quebras explícitas respeitadas). */
function plan_diagram_wrap(string $text, float $maxW, float $fs): array
{
    $lines = [];
    $maxW  = max($maxW, $fs);
    foreach (explode("\n", $text) as $para) {
        $para = rtrim($para);
        if ($para === '') {
            $lines[] = '';
            continue;
        }
        $cur = '';
        foreach (preg_split('/ +/', $para) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $try = $cur === '' ? $word : $cur . ' ' . $word;
            if (plan_diagram_text_width($try, $fs) <= $maxW) {
                $cur = $try;
                continue;
            }
            if ($cur !== '') {
                $lines[] = $cur;
                $cur = '';
            }
            // palavra maior que a largura: quebra por caracteres
            if (plan_diagram_text_width($word, $fs) > $maxW) {
                $chunk = '';
                foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                    if ($chunk !== '' && plan_diagram_text_width($chunk . $ch, $fs) > $maxW) {
                        $lines[] = $chunk;
                        $chunk = '';
                    }
                    $chunk .= $ch;
                }
                $cur = $chunk;
            } else {
                $cur = $word;
            }
        }
        $lines[] = $cur;
    }
    return $lines;
}

/** Estilo resolvido de um nó (padrões por tipo). */
function plan_diagram_node_style(array $n): array
{
    $types = plan_diagram_node_types();
    $def   = $types[$n['type']] ?? $types['process'];
    return [
        'fill'        => $n['fill'] ?? $def['fill'],
        'stroke'      => $n['stroke'] ?? $def['stroke'],
        'color'       => $n['color'] ?? '#212529',
        'fontSize'    => (float) ($n['fontSize'] ?? 14),
        'bold'        => !empty($n['bold']) || $n['type'] === 'lane',
        'align'       => $n['align'] ?? ($n['type'] === 'note' ? 'left' : 'center'),
        'strokeWidth' => (float) ($n['strokeWidth'] ?? 2),
    ];
}

/** Ponto de conexão de um nó em um lado. */
function plan_diagram_port(array $n, string $side): array
{
    $cx = $n['x'] + $n['w'] / 2;
    $cy = $n['y'] + $n['h'] / 2;
    return match ($side) {
        'top'    => [$cx, $n['y']],
        'right'  => [$n['x'] + $n['w'], $cy],
        'bottom' => [$cx, $n['y'] + $n['h']],
        default  => [$n['x'], $cy],
    };
}

/** Lados automáticos (mais próximos) entre dois nós. */
function plan_diagram_auto_sides(array $a, array $b): array
{
    $dx = ($b['x'] + $b['w'] / 2) - ($a['x'] + $a['w'] / 2);
    $dy = ($b['y'] + $b['h'] / 2) - ($a['y'] + $a['h'] / 2);
    if (abs($dx) > abs($dy)) {
        return $dx >= 0 ? ['right', 'left'] : ['left', 'right'];
    }
    return $dy >= 0 ? ['bottom', 'top'] : ['top', 'bottom'];
}

function plan_diagram_stub(array $p, string $side, float $len): array
{
    return match ($side) {
        'top'    => [$p[0], $p[1] - $len],
        'right'  => [$p[0] + $len, $p[1]],
        'bottom' => [$p[0], $p[1] + $len],
        default  => [$p[0] - $len, $p[1]],
    };
}

/** Pontos (x,y) do caminho de uma aresta. */
function plan_diagram_edge_points(array $e, array $from, array $to): array
{
    [$autoFrom, $autoTo] = plan_diagram_auto_sides($from, $to);
    $s1 = $e['fromSide'] ?? $autoFrom;
    $s2 = $e['toSide'] ?? $autoTo;
    $p1 = plan_diagram_port($from, $s1);
    $p2 = plan_diagram_port($to, $s2);

    if (!empty($e['points'])) {
        $pts = [$p1];
        foreach ($e['points'] as $p) {
            $pts[] = [(float) $p['x'], (float) $p['y']];
        }
        $pts[] = $p2;
        return plan_diagram_simplify($pts);
    }
    if (($e['route'] ?? 'ortho') === 'straight') {
        return [$p1, $p2];
    }
    $stub = 24.0;
    $a    = plan_diagram_stub($p1, $s1, $stub);
    $b    = plan_diagram_stub($p2, $s2, $stub);
    $v1   = in_array($s1, ['top', 'bottom'], true);
    $v2   = in_array($s2, ['top', 'bottom'], true);
    $pts  = [$p1, $a];
    if ($v1 && $v2) {
        $my = ($a[1] + $b[1]) / 2;
        $pts[] = [$a[0], $my];
        $pts[] = [$b[0], $my];
    } elseif (!$v1 && !$v2) {
        $mx = ($a[0] + $b[0]) / 2;
        $pts[] = [$mx, $a[1]];
        $pts[] = [$mx, $b[1]];
    } elseif ($v1) {
        $pts[] = [$a[0], $b[1]];
    } else {
        $pts[] = [$b[0], $a[1]];
    }
    $pts[] = $b;
    $pts[] = $p2;
    return plan_diagram_simplify($pts);
}

/** Remove pontos repetidos e colineares. */
function plan_diagram_simplify(array $pts): array
{
    $out = [];
    foreach ($pts as $p) {
        $n = count($out);
        if ($n > 0 && abs($out[$n - 1][0] - $p[0]) < 0.01 && abs($out[$n - 1][1] - $p[1]) < 0.01) {
            continue;
        }
        if ($n > 1) {
            $a = $out[$n - 2];
            $b = $out[$n - 1];
            $cross = ($b[0] - $a[0]) * ($p[1] - $a[1]) - ($b[1] - $a[1]) * ($p[0] - $a[0]);
            $dot   = ($b[0] - $a[0]) * ($p[0] - $b[0]) + ($b[1] - $a[1]) * ($p[1] - $b[1]);
            if (abs($cross) < 0.01 && $dot >= 0) {
                $out[$n - 1] = $p; // colinear na mesma direção
                continue;
            }
        }
        $out[] = $p;
    }
    return $out;
}

/** Ponto no meio do comprimento de uma polilinha. */
function plan_diagram_midpoint(array $pts): array
{
    $total = 0.0;
    for ($i = 1, $c = count($pts); $i < $c; $i++) {
        $total += hypot($pts[$i][0] - $pts[$i - 1][0], $pts[$i][1] - $pts[$i - 1][1]);
    }
    if ($total <= 0 || count($pts) < 2) {
        return $pts[0] ?? [0, 0];
    }
    $half = $total / 2;
    for ($i = 1, $c = count($pts); $i < $c; $i++) {
        $seg = hypot($pts[$i][0] - $pts[$i - 1][0], $pts[$i][1] - $pts[$i - 1][1]);
        if ($half <= $seg) {
            $t = $seg > 0 ? $half / $seg : 0;
            return [$pts[$i - 1][0] + ($pts[$i][0] - $pts[$i - 1][0]) * $t, $pts[$i - 1][1] + ($pts[$i][1] - $pts[$i - 1][1]) * $t];
        }
        $half -= $seg;
    }
    return end($pts);
}

/**
 * Área de texto de um nó: [x, y, w, h, align, valign].
 */
function plan_diagram_text_box(array $n): array
{
    $x = $n['x'];
    $y = $n['y'];
    $w = $n['w'];
    $h = $n['h'];
    $st = plan_diagram_node_style($n);
    $al = $st['align'];
    return match ($n['type']) {
        'decision'   => [$x + $w * 0.225, $y + $h * 0.225, $w * 0.55, $h * 0.55, $al, 'middle'],
        'io'         => (function () use ($x, $y, $w, $h, $al) {
            $off = min($w * 0.2, 30);
            return [$x + $off + 4, $y + 4, $w - 2 * $off - 8, $h - 8, $al, 'middle'];
        })(),
        'database'   => (function () use ($x, $y, $w, $h, $al) {
            $ry = min(14, $h * 0.16);
            return [$x + 8, $y + 2 * $ry, $w - 16, $h - 3 * $ry, $al, 'middle'];
        })(),
        'subprocess' => [$x + 14, $y + 4, $w - 28, $h - 8, $al, 'middle'],
        'note'       => [$x + 10, $y + 10, $w - 28, $h - 20, $al, 'top'],
        'actor'      => [$x - 20, $y + $h * 0.76, $w + 40, $h * 0.24, 'center', 'top'],
        'circle'     => [$x + $w * 0.15, $y + $h * 0.15, $w * 0.7, $h * 0.7, $al, 'middle'],
        'text'       => [$x + 4, $y + 4, $w - 8, $h - 8, $al, 'middle'],
        default      => [$x + 8, $y + 4, $w - 16, $h - 8, $al, 'middle'],
    };
}

// ---------------------------------------------------------------------------
// Renderização SVG
// ---------------------------------------------------------------------------

function plan_diagram_xml(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function plan_diagram_f(float $v): string
{
    $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    return $s === '-0' ? '0' : $s;
}

/** Primitivas SVG do corpo de um nó (sem texto). */
function plan_diagram_node_shape(array $n): string
{
    $x  = (float) $n['x'];
    $y  = (float) $n['y'];
    $w  = (float) $n['w'];
    $h  = (float) $n['h'];
    $st = plan_diagram_node_style($n);
    $f  = 'plan_diagram_f';
    $sty = 'fill="' . plan_diagram_xml($st['fill']) . '" stroke="' . plan_diagram_xml($st['stroke']) . '" stroke-width="' . $f($st['strokeWidth']) . '"';
    $cx = $x + $w / 2;
    $cy = $y + $h / 2;

    switch ($n['type']) {
        case 'start':
        case 'end':
            return "<rect x=\"{$f($x)}\" y=\"{$f($y)}\" width=\"{$f($w)}\" height=\"{$f($h)}\" rx=\"{$f($h / 2)}\" {$sty}/>";
        case 'decision':
            return "<polygon points=\"{$f($cx)},{$f($y)} {$f($x + $w)},{$f($cy)} {$f($cx)},{$f($y + $h)} {$f($x)},{$f($cy)}\" stroke-linejoin=\"round\" {$sty}/>";
        case 'io':
            $o = min($w * 0.2, 30);
            return "<polygon points=\"{$f($x + $o)},{$f($y)} {$f($x + $w)},{$f($y)} {$f($x + $w - $o)},{$f($y + $h)} {$f($x)},{$f($y + $h)}\" stroke-linejoin=\"round\" {$sty}/>";
        case 'document':
            $a = min(12, $h * 0.18);
            $d = "M{$f($x)},{$f($y)} L{$f($x + $w)},{$f($y)} L{$f($x + $w)},{$f($y + $h - $a)} "
               . "C{$f($x + $w * 0.7)},{$f($y + $h - 3 * $a)} {$f($x + $w * 0.3)},{$f($y + $h + $a)} {$f($x)},{$f($y + $h - $a)} Z";
            return "<path d=\"{$d}\" stroke-linejoin=\"round\" {$sty}/>";
        case 'database':
            $ry = min(14, $h * 0.16);
            $rx = $w / 2;
            $d = "M{$f($x)},{$f($y + $ry)} A{$f($rx)},{$f($ry)} 0 0 1 {$f($x + $w)},{$f($y + $ry)} L{$f($x + $w)},{$f($y + $h - $ry)} "
               . "A{$f($rx)},{$f($ry)} 0 0 1 {$f($x)},{$f($y + $h - $ry)} Z";
            return "<path d=\"{$d}\" {$sty}/><ellipse cx=\"{$f($cx)}\" cy=\"{$f($y + $ry)}\" rx=\"{$f($rx)}\" ry=\"{$f($ry)}\" {$sty}/>";
        case 'subprocess':
            return "<rect x=\"{$f($x)}\" y=\"{$f($y)}\" width=\"{$f($w)}\" height=\"{$f($h)}\" rx=\"4\" {$sty}/>"
                 . "<line x1=\"{$f($x + 10)}\" y1=\"{$f($y)}\" x2=\"{$f($x + 10)}\" y2=\"{$f($y + $h)}\" stroke=\"" . plan_diagram_xml($st['stroke']) . "\" stroke-width=\"{$f($st['strokeWidth'])}\"/>"
                 . "<line x1=\"{$f($x + $w - 10)}\" y1=\"{$f($y)}\" x2=\"{$f($x + $w - 10)}\" y2=\"{$f($y + $h)}\" stroke=\"" . plan_diagram_xml($st['stroke']) . "\" stroke-width=\"{$f($st['strokeWidth'])}\"/>";
        case 'note':
            $fo = 16;
            return "<polygon points=\"{$f($x)},{$f($y)} {$f($x + $w - $fo)},{$f($y)} {$f($x + $w)},{$f($y + $fo)} {$f($x + $w)},{$f($y + $h)} {$f($x)},{$f($y + $h)}\" stroke-linejoin=\"round\" {$sty}/>"
                 . "<polygon points=\"{$f($x + $w - $fo)},{$f($y)} {$f($x + $w - $fo)},{$f($y + $fo)} {$f($x + $w)},{$f($y + $fo)}\" fill=\"" . plan_diagram_xml($st['stroke']) . "\" fill-opacity=\"0.35\" stroke=\"" . plan_diagram_xml($st['stroke']) . "\" stroke-width=\"1\"/>";
        case 'text':
            return '';
        case 'lane':
            $band = "fill=\"" . plan_diagram_xml($st['stroke']) . "\" fill-opacity=\"0.14\" stroke=\"" . plan_diagram_xml($st['stroke']) . "\" stroke-width=\"{$f($st['strokeWidth'])}\"";
            $out  = "<rect x=\"{$f($x)}\" y=\"{$f($y)}\" width=\"{$f($w)}\" height=\"{$f($h)}\" {$sty}/>";
            if ($w >= $h * 1.5) {
                $out .= "<rect x=\"{$f($x)}\" y=\"{$f($y)}\" width=\"36\" height=\"{$f($h)}\" {$band}/>";
            } else {
                $out .= "<rect x=\"{$f($x)}\" y=\"{$f($y)}\" width=\"{$f($w)}\" height=\"36\" {$band}/>";
            }
            return $out;
        case 'circle':
            return "<ellipse cx=\"{$f($cx)}\" cy=\"{$f($cy)}\" rx=\"{$f($w / 2)}\" ry=\"{$f($h / 2)}\" {$sty}/>";
        case 'actor':
            $r    = min($w, $h) * 0.14;
            $hy   = $y + $r + 2;
            $neck = $y + 2 * $r + 2;
            $hip  = $y + $h * 0.55;
            $arm  = $neck + $h * 0.12;
            $ls   = 'stroke="' . plan_diagram_xml($st['stroke']) . '" stroke-width="' . $f($st['strokeWidth']) . '" stroke-linecap="round" fill="none"';
            return "<circle cx=\"{$f($cx)}\" cy=\"{$f($hy)}\" r=\"{$f($r)}\" {$sty}/>"
                 . "<line x1=\"{$f($cx)}\" y1=\"{$f($neck)}\" x2=\"{$f($cx)}\" y2=\"{$f($hip)}\" {$ls}/>"
                 . "<line x1=\"{$f($cx - $w * 0.25)}\" y1=\"{$f($arm)}\" x2=\"{$f($cx + $w * 0.25)}\" y2=\"{$f($arm)}\" {$ls}/>"
                 . "<line x1=\"{$f($cx)}\" y1=\"{$f($hip)}\" x2=\"{$f($cx - $w * 0.2)}\" y2=\"{$f($y + $h * 0.75)}\" {$ls}/>"
                 . "<line x1=\"{$f($cx)}\" y1=\"{$f($hip)}\" x2=\"{$f($cx + $w * 0.2)}\" y2=\"{$f($y + $h * 0.75)}\" {$ls}/>";
        default: // process
            return "<rect x=\"{$f($x)}\" y=\"{$f($y)}\" width=\"{$f($w)}\" height=\"{$f($h)}\" rx=\"6\" {$sty}/>";
    }
}

/** Texto (tspans) de um nó. */
function plan_diagram_node_text(array $n): string
{
    $st = plan_diagram_node_style($n);
    $fs = $st['fontSize'];
    $f  = 'plan_diagram_f';
    $attrs = 'fill="' . plan_diagram_xml($st['color']) . '" font-size="' . $f($fs) . '"' . ($st['bold'] ? ' font-weight="600"' : '');
    $text  = (string) ($n['text'] ?? '');
    if (trim($text) === '') {
        return '';
    }

    if ($n['type'] === 'lane') {
        $x = (float) $n['x'];
        $y = (float) $n['y'];
        $w = (float) $n['w'];
        $h = (float) $n['h'];
        if ($w >= $h * 1.5) {
            $lines = plan_diagram_wrap($text, $h - 16, $fs);
            $lh    = $fs * 1.25;
            $cx    = $x + 18;
            $cy    = $y + $h / 2;
            $out   = "<text text-anchor=\"middle\" dominant-baseline=\"central\" {$attrs} transform=\"rotate(-90 {$f($cx)} {$f($cy)})\">";
            $start = $cy - (count($lines) - 1) * $lh / 2;
            foreach ($lines as $i => $ln) {
                $out .= "<tspan x=\"{$f($cx)}\" y=\"{$f($start + $i * $lh)}\">" . plan_diagram_xml($ln) . '</tspan>';
            }
            return $out . '</text>';
        }
        $lines = plan_diagram_wrap($text, $w - 16, $fs);
        $lh    = $fs * 1.25;
        $cx    = $x + $w / 2;
        $out   = "<text text-anchor=\"middle\" dominant-baseline=\"central\" {$attrs}>";
        $start = $y + 18 - (count($lines) - 1) * $lh / 2;
        foreach ($lines as $i => $ln) {
            $out .= "<tspan x=\"{$f($cx)}\" y=\"{$f($start + $i * $lh)}\">" . plan_diagram_xml($ln) . '</tspan>';
        }
        return $out . '</text>';
    }

    [$tx, $ty, $tw, $th, $align, $valign] = plan_diagram_text_box($n);
    $lines  = plan_diagram_wrap($text, $tw, $fs);
    $lh     = $fs * 1.25;
    $anchor = $align === 'left' ? 'start' : ($align === 'right' ? 'end' : 'middle');
    $ax     = $align === 'left' ? $tx : ($align === 'right' ? $tx + $tw : $tx + $tw / 2);
    $start  = $valign === 'top' ? $ty + $lh / 2 : ($ty + $th / 2) - (count($lines) - 1) * $lh / 2;
    $out    = "<text text-anchor=\"{$anchor}\" dominant-baseline=\"central\" {$attrs}>";
    foreach ($lines as $i => $ln) {
        $out .= "<tspan x=\"{$f($ax)}\" y=\"{$f($start + $i * $lh)}\">" . ($ln === '' ? ' ' : plan_diagram_xml($ln)) . '</tspan>';
    }
    return $out . '</text>';
}

/** Retângulo envolvente do conteúdo (nós); null quando vazio. */
function plan_diagram_bbox(array $data): ?array
{
    $minX = $minY = INF;
    $maxX = $maxY = -INF;
    foreach ($data['nodes'] as $n) {
        $minX = min($minX, (float) $n['x']);
        $minY = min($minY, (float) $n['y']);
        $maxX = max($maxX, (float) $n['x'] + (float) $n['w']);
        $maxY = max($maxY, (float) $n['y'] + (float) $n['h']);
    }
    foreach ($data['edges'] as $e) {
        foreach ($e['points'] ?? [] as $p) {
            $minX = min($minX, (float) $p['x']);
            $minY = min($minY, (float) $p['y']);
            $maxX = max($maxX, (float) $p['x']);
            $maxY = max($maxY, (float) $p['y']);
        }
    }
    if (!is_finite($minX)) {
        return null;
    }
    return [$minX, $minY, $maxX - $minX, $maxY - $minY];
}

/**
 * Desenha o diagrama em SVG estático.
 *
 * @param array $opts width|height (atributos; null = automático), thumb (ajusta ao conteúdo,
 *                    100% × 100%), fit (ajusta a viewBox ao conteúdo), padding, links (<a> nos nós com link),
 *                    id (prefixo dos marcadores), class, bg (sobrepõe a cor de fundo).
 */
function plan_diagram_svg(array $data, array $opts = []): string
{
    $data  = plan_diagram_validate($data);
    $f     = 'plan_diagram_f';
    $thumb = !empty($opts['thumb']);
    $fit   = $thumb || !empty($opts['fit']);
    $pad   = (float) ($opts['padding'] ?? 20);
    $pref  = (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($opts['id'] ?? ''));
    if ($pref === '' || !preg_match('/^[A-Za-z_]/', $pref)) {
        // ids XML precisam começar por letra/underscore (senão os marcadores das setas somem)
        $pref = 'pd' . $pref . substr(md5(serialize($data)), 0, 6);
    }
    $links = $opts['links'] ?? !$thumb;
    $bg    = plan_diagram_color($opts['bg'] ?? null, $data['canvas']['bg']) ?? '#ffffff';

    $bbox = $fit ? plan_diagram_bbox($data) : null;
    if ($bbox) {
        $vx = $bbox[0] - $pad;
        $vy = $bbox[1] - $pad;
        $vw = max($bbox[2] + 2 * $pad, 40);
        $vh = max($bbox[3] + 2 * $pad, 40);
    } else {
        $vx = 0;
        $vy = 0;
        $vw = (float) $data['canvas']['w'];
        $vh = (float) $data['canvas']['h'];
    }

    $width  = $opts['width'] ?? ($thumb ? '100%' : $f($vw));
    $height = $opts['height'] ?? ($thumb ? '100%' : $f($vh));

    $byId = [];
    foreach ($data['nodes'] as $n) {
        $byId[$n['id']] = $n;
    }

    // Marcadores (uma cor por marcador)
    $colors = [];
    foreach ($data['edges'] as $e) {
        $colors[$e['color'] ?? '#495057'] = true;
    }
    $defs = '<defs>';
    foreach (array_keys($colors) as $c) {
        $cid = preg_replace('/[^0-9a-f]/', '', (string) $c);
        $col = plan_diagram_xml((string) $c);
        $defs .= "<marker id=\"{$pref}-ae-{$cid}\" markerWidth=\"11\" markerHeight=\"11\" refX=\"10\" refY=\"5.5\" orient=\"auto\" markerUnits=\"userSpaceOnUse\"><path d=\"M0,0.5 L10,5.5 L0,10.5 Z\" fill=\"{$col}\"/></marker>";
        $defs .= "<marker id=\"{$pref}-as-{$cid}\" markerWidth=\"11\" markerHeight=\"11\" refX=\"1\" refY=\"5.5\" orient=\"auto\" markerUnits=\"userSpaceOnUse\"><path d=\"M11,0.5 L1,5.5 L11,10.5 Z\" fill=\"{$col}\"/></marker>";
    }
    $defs .= '</defs>';

    $class = isset($opts['class']) ? ' class="' . plan_diagram_xml((string) $opts['class']) . '"' : '';
    $svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" xmlns:xlink=\"http://www.w3.org/1999/xlink\" "
         . "viewBox=\"{$f($vx)} {$f($vy)} {$f($vw)} {$f($vh)}\" width=\"" . plan_diagram_xml((string) $width) . "\" height=\"" . plan_diagram_xml((string) $height) . "\" "
         . "preserveAspectRatio=\"xMidYMid meet\" font-family=\"'Segoe UI', Roboto, Helvetica, Arial, sans-serif\"{$class}>";
    $svg .= $defs;
    if ($bg !== 'none') {
        $svg .= "<rect x=\"{$f($vx)}\" y=\"{$f($vy)}\" width=\"{$f($vw)}\" height=\"{$f($vh)}\" fill=\"" . plan_diagram_xml($bg) . "\"/>";
    }

    $renderNode = static function (array $n) use ($links): string {
        $inner = plan_diagram_node_shape($n) . plan_diagram_node_text($n);
        $g = '<g class="pd-node" data-id="' . plan_diagram_xml($n['id']) . '" data-type="' . plan_diagram_xml($n['type']) . '">' . $inner . '</g>';
        if ($links && !empty($n['link'])) {
            $href = plan_diagram_xml($n['link']);
            return "<a href=\"{$href}\" xlink:href=\"{$href}\" target=\"_blank\" rel=\"noopener\">{$g}</a>";
        }
        return $g;
    };

    // Raias atrás de tudo
    $svg .= '<g class="pd-lanes">';
    foreach ($data['nodes'] as $n) {
        if ($n['type'] === 'lane') {
            $svg .= $renderNode($n);
        }
    }
    $svg .= '</g><g class="pd-edges">';
    foreach ($data['edges'] as $e) {
        $from = $byId[$e['from']] ?? null;
        $to   = $byId[$e['to']] ?? null;
        if (!$from || !$to) {
            continue;
        }
        $pts   = plan_diagram_edge_points($e, $from, $to);
        $color = $e['color'] ?? '#495057';
        $cid   = preg_replace('/[^0-9a-f]/', '', $color);
        $sw    = (float) ($e['width'] ?? 2);
        $d     = '';
        foreach ($pts as $i => $p) {
            $d .= ($i === 0 ? 'M' : ' L') . $f($p[0]) . ',' . $f($p[1]);
        }
        $arrow = $e['arrow'] ?? 'end';
        $attrs = 'fill="none" stroke="' . plan_diagram_xml($color) . '" stroke-width="' . $f($sw) . '" stroke-linejoin="round"';
        if (($e['style'] ?? 'solid') === 'dashed') {
            $attrs .= ' stroke-dasharray="' . $f($sw * 4) . ',' . $f($sw * 3) . '"';
        }
        if ($arrow === 'end' || $arrow === 'both') {
            $attrs .= " marker-end=\"url(#{$pref}-ae-{$cid})\"";
        }
        if ($arrow === 'both') {
            $attrs .= " marker-start=\"url(#{$pref}-as-{$cid})\"";
        }
        $svg .= '<g class="pd-edge" data-id="' . plan_diagram_xml($e['id']) . "\"><path d=\"{$d}\" {$attrs}/>";
        if (!empty($e['label'])) {
            $fs  = 12.0;
            $lns = plan_diagram_wrap($e['label'], 220, $fs);
            $lh  = $fs * 1.25;
            $mw  = 0.0;
            foreach ($lns as $ln) {
                $mw = max($mw, plan_diagram_text_width($ln, $fs));
            }
            [$mx, $my] = plan_diagram_midpoint($pts);
            $bw = $mw + 10;
            $bh = count($lns) * $lh + 4;
            $svg .= "<rect x=\"{$f($mx - $bw / 2)}\" y=\"{$f($my - $bh / 2)}\" width=\"{$f($bw)}\" height=\"{$f($bh)}\" rx=\"3\" fill=\"#ffffff\" fill-opacity=\"0.92\"/>";
            $svg .= "<text text-anchor=\"middle\" dominant-baseline=\"central\" font-size=\"{$f($fs)}\" fill=\"" . plan_diagram_xml($color) . '">';
            $start = $my - (count($lns) - 1) * $lh / 2;
            foreach ($lns as $i => $ln) {
                $svg .= "<tspan x=\"{$f($mx)}\" y=\"{$f($start + $i * $lh)}\">" . plan_diagram_xml($ln) . '</tspan>';
            }
            $svg .= '</text>';
        }
        $svg .= '</g>';
    }
    $svg .= '</g><g class="pd-nodes">';
    foreach ($data['nodes'] as $n) {
        if ($n['type'] !== 'lane') {
            $svg .= $renderNode($n);
        }
    }
    $svg .= '</g></svg>';
    return $svg;
}
