<?php
/**
 * GERADOR DE QR CODE EM PHP PURO (sem dependências externas)
 *
 * Implementa o padrão ISO/IEC 18004: modo byte, nível de correção M,
 * versões 1–40 (escolhida automaticamente pelo tamanho do dado),
 * Reed-Solomon sobre GF(256), seleção da máscara ótima pelas 4 regras
 * de penalidade, saída em SVG (vetor) e PNG (via GD).
 *
 *   man_qr_svg(string $data, int $size = 200): string   // markup <svg>
 *   man_qr_png(string $data, int $size = 300): string   // binário PNG
 *   man_qr_matrix(string $data): array                  // matriz booleana
 *
 * Usado nas etiquetas de equipamentos (o QR codifica a URL absoluta de
 * busca por código → página de histórico).
 */

/**
 * Tabela nível M: [codewords EC por bloco, blocos grupo 1, dados/bloco g1,
 * blocos grupo 2, dados/bloco g2]. Índice = versão.
 */
function man_qr_ec_table_m(): array
{
    return [
        1  => [10, 1, 16, 0, 0],   2  => [16, 1, 28, 0, 0],   3  => [26, 1, 44, 0, 0],
        4  => [18, 2, 32, 0, 0],   5  => [24, 2, 43, 0, 0],   6  => [16, 4, 27, 0, 0],
        7  => [18, 4, 31, 0, 0],   8  => [22, 2, 38, 2, 39],  9  => [22, 3, 36, 2, 37],
        10 => [26, 4, 43, 1, 44],  11 => [30, 1, 50, 4, 51],  12 => [22, 6, 36, 2, 37],
        13 => [22, 8, 37, 1, 38],  14 => [24, 4, 40, 5, 41],  15 => [24, 5, 41, 5, 42],
        16 => [28, 7, 45, 3, 46],  17 => [28, 10, 46, 1, 47], 18 => [26, 9, 43, 4, 44],
        19 => [26, 3, 44, 11, 45], 20 => [26, 3, 41, 13, 42], 21 => [26, 17, 42, 0, 0],
        22 => [28, 17, 46, 0, 0],  23 => [28, 4, 47, 14, 48], 24 => [28, 6, 45, 14, 46],
        25 => [28, 8, 47, 13, 48], 26 => [28, 19, 46, 4, 47], 27 => [28, 22, 45, 3, 46],
        28 => [28, 3, 45, 23, 46], 29 => [28, 21, 45, 7, 46], 30 => [28, 19, 47, 10, 48],
        31 => [28, 2, 46, 29, 47], 32 => [28, 10, 46, 23, 47], 33 => [28, 14, 46, 21, 47],
        34 => [28, 14, 46, 23, 47], 35 => [28, 12, 47, 26, 48], 36 => [28, 6, 47, 34, 48],
        37 => [28, 29, 46, 14, 47], 38 => [28, 13, 46, 32, 47], 39 => [28, 40, 47, 7, 48],
        40 => [28, 18, 47, 31, 48],
    ];
}

/** Posições (linha/coluna) dos centros dos padrões de alinhamento. */
function man_qr_alignment_positions(int $version): array
{
    static $table = [
        1  => [],                   2  => [6, 18],              3  => [6, 22],
        4  => [6, 26],              5  => [6, 30],              6  => [6, 34],
        7  => [6, 22, 38],          8  => [6, 24, 42],          9  => [6, 26, 46],
        10 => [6, 28, 50],          11 => [6, 30, 54],          12 => [6, 32, 58],
        13 => [6, 34, 62],          14 => [6, 26, 46, 66],      15 => [6, 26, 48, 70],
        16 => [6, 26, 50, 74],      17 => [6, 30, 54, 78],      18 => [6, 30, 56, 82],
        19 => [6, 30, 58, 86],      20 => [6, 34, 62, 90],      21 => [6, 28, 50, 72, 94],
        22 => [6, 26, 50, 74, 98],  23 => [6, 30, 54, 78, 102], 24 => [6, 28, 54, 80, 106],
        25 => [6, 32, 58, 84, 110], 26 => [6, 30, 58, 86, 114], 27 => [6, 34, 62, 90, 118],
        28 => [6, 26, 50, 74, 98, 122],  29 => [6, 30, 54, 78, 102, 126], 30 => [6, 26, 52, 78, 104, 130],
        31 => [6, 30, 56, 82, 108, 134], 32 => [6, 34, 60, 86, 112, 138], 33 => [6, 30, 58, 86, 114, 142],
        34 => [6, 34, 62, 90, 118, 146], 35 => [6, 30, 54, 78, 102, 126, 150], 36 => [6, 24, 50, 76, 102, 128, 154],
        37 => [6, 28, 54, 80, 106, 132, 158], 38 => [6, 32, 58, 84, 110, 136, 162], 39 => [6, 26, 54, 82, 110, 138, 166],
        40 => [6, 30, 58, 86, 114, 142, 170],
    ];
    return $table[$version] ?? [];
}

/** Tabelas de log/antilog de GF(256) com polinômio primitivo 0x11D. */
function man_qr_gf_tables(): array
{
    static $tables = null;
    if ($tables === null) {
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        $tables = [$exp, $log];
    }
    return $tables;
}

/** Codewords de correção Reed-Solomon para um bloco de dados. */
function man_qr_rs_encode(array $data, int $ecCount): array
{
    [$exp, $log] = man_qr_gf_tables();

    // Polinômio gerador: produto de (x - α^i), i = 0..ecCount-1
    $gen = [1];
    for ($i = 0; $i < $ecCount; $i++) {
        $next = array_fill(0, count($gen) + 1, 0);
        foreach ($gen as $j => $coef) {
            $next[$j] ^= $coef;
            if ($coef !== 0) {
                $next[$j + 1] ^= $exp[($log[$coef] + $i) % 255];
            }
        }
        $gen = $next;
    }

    $rem = array_fill(0, $ecCount, 0);
    foreach ($data as $byte) {
        $factor = $byte ^ array_shift($rem);
        $rem[] = 0;
        if ($factor !== 0) {
            $lf = $log[$factor];
            for ($j = 0; $j < $ecCount; $j++) {
                $g = $gen[$j + 1];
                if ($g !== 0) {
                    $rem[$j] ^= $exp[($log[$g] + $lf) % 255];
                }
            }
        }
    }
    return $rem;
}

/** Menor versão (1–40) que comporta $len bytes em modo byte, nível M. */
function man_qr_choose_version(int $len): int
{
    foreach (man_qr_ec_table_m() as $v => [$ec, $b1, $d1, $b2, $d2]) {
        $dataCw  = $b1 * $d1 + $b2 * $d2;
        $ccBits  = $v <= 9 ? 8 : 16;
        $needed  = 4 + $ccBits + 8 * $len; // modo + contador + dados (terminador é opcional)
        if ($needed <= $dataCw * 8) {
            return $v;
        }
    }
    throw new InvalidArgumentException('Dado grande demais para um QR Code (máx. ~2300 bytes em nível M).');
}

/** Fluxo de bits → codewords de dados (com terminador e padding). */
function man_qr_data_codewords(string $data, int $version, int $dataCwCount): array
{
    $len    = strlen($data);
    $ccBits = $version <= 9 ? 8 : 16;
    $bits   = '0100' . str_pad(decbin($len), $ccBits, '0', STR_PAD_LEFT);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }
    $capacity = $dataCwCount * 8;
    $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));      // terminador
    if (strlen($bits) % 8 !== 0) {
        $bits .= str_repeat('0', 8 - strlen($bits) % 8);              // completa o byte
    }
    $codewords = [];
    for ($i = 0; $i < strlen($bits); $i += 8) {
        $codewords[] = bindec(substr($bits, $i, 8));
    }
    $pad = [0xEC, 0x11];
    for ($i = 0; count($codewords) < $dataCwCount; $i++) {
        $codewords[] = $pad[$i % 2];
    }
    return $codewords;
}

/** Divide em blocos, calcula EC e intercala conforme a norma. */
function man_qr_interleave(array $dataCw, int $version): array
{
    [$ecCount, $b1, $d1, $b2, $d2] = man_qr_ec_table_m()[$version];
    $blocks = [];
    $offset = 0;
    for ($i = 0; $i < $b1; $i++) {
        $blocks[] = array_slice($dataCw, $offset, $d1);
        $offset  += $d1;
    }
    for ($i = 0; $i < $b2; $i++) {
        $blocks[] = array_slice($dataCw, $offset, $d2);
        $offset  += $d2;
    }
    $ecBlocks = array_map(fn (array $b) => man_qr_rs_encode($b, $ecCount), $blocks);

    $out    = [];
    $maxLen = max($d1, $d2);
    for ($i = 0; $i < $maxLen; $i++) {
        foreach ($blocks as $b) {
            if (isset($b[$i])) {
                $out[] = $b[$i];
            }
        }
    }
    for ($i = 0; $i < $ecCount; $i++) {
        foreach ($ecBlocks as $b) {
            $out[] = $b[$i];
        }
    }
    return $out;
}

/** Bits BCH(15,5) da informação de formato (nível M = 00) + máscara. */
function man_qr_format_bits(int $mask): int
{
    $data = (0b00 << 3) | $mask; // nível M
    $rem  = $data << 10;
    for ($i = 14; $i >= 10; $i--) {
        if ($rem & (1 << $i)) {
            $rem ^= 0x537 << ($i - 10);
        }
    }
    return (($data << 10) | $rem) ^ 0x5412;
}

/** Bits BCH(18,6) da informação de versão (versões ≥ 7). */
function man_qr_version_bits(int $version): int
{
    $rem = $version << 12;
    for ($i = 17; $i >= 12; $i--) {
        if ($rem & (1 << $i)) {
            $rem ^= 0x1F25 << ($i - 12);
        }
    }
    return ($version << 12) | $rem;
}

/**
 * Monta a matriz. Devolve [matrix (int 0/1), isFunction (bool)] — módulos de
 * função não recebem máscara.
 */
function man_qr_build_matrix(int $version, array $codewords, int $mask): array
{
    $n      = 17 + 4 * $version;
    $m      = array_fill(0, $n, array_fill(0, $n, 0));
    $fn     = array_fill(0, $n, array_fill(0, $n, false));

    $set = function (int $r, int $c, int $v) use (&$m, &$fn): void {
        $m[$r][$c]  = $v;
        $fn[$r][$c] = true;
    };

    // Padrões localizadores + separadores
    foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$r0, $c0]) {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $r0 + $r;
                $cc = $c0 + $c;
                if ($rr < 0 || $cc < 0 || $rr >= $n || $cc >= $n) {
                    continue;
                }
                $inside = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6;
                $dark   = $inside && ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));
                $set($rr, $cc, $dark ? 1 : 0);
            }
        }
    }

    // Padrões de alinhamento
    $pos = man_qr_alignment_positions($version);
    $cnt = count($pos);
    for ($i = 0; $i < $cnt; $i++) {
        for ($j = 0; $j < $cnt; $j++) {
            // Pula os que colidem com os localizadores
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $cnt - 1) || ($i === $cnt - 1 && $j === 0)) {
                continue;
            }
            $cr = $pos[$i];
            $cc = $pos[$j];
            for ($r = -2; $r <= 2; $r++) {
                for ($c = -2; $c <= 2; $c++) {
                    $dark = max(abs($r), abs($c)) !== 1;
                    $set($cr + $r, $cc + $c, $dark ? 1 : 0);
                }
            }
        }
    }

    // Padrões de sincronismo (timing)
    for ($i = 8; $i < $n - 8; $i++) {
        if (!$fn[6][$i]) {
            $set(6, $i, $i % 2 === 0 ? 1 : 0);
        }
        if (!$fn[$i][6]) {
            $set($i, 6, $i % 2 === 0 ? 1 : 0);
        }
    }

    // Módulo escuro fixo
    $set($n - 8, 8, 1);

    // Reserva das áreas de formato
    for ($i = 0; $i <= 8; $i++) {
        if ($i !== 6) {
            $set(8, $i, 0);
            $set($i, 8, 0);
        }
    }
    for ($i = 0; $i < 8; $i++) {
        $set(8, $n - 1 - $i, 0);
        $set($n - 1 - $i, 8, 0);
    }
    $fn[$n - 8][8] = true;

    // Informação de versão (≥ 7)
    if ($version >= 7) {
        $vb = man_qr_version_bits($version);
        for ($i = 0; $i < 18; $i++) {
            $bit = ($vb >> $i) & 1;
            $r   = intdiv($i, 3);
            $c   = $n - 11 + $i % 3;
            $set($r, $c, $bit);
            $set($c, $r, $bit);
        }
    }

    // Dados em zigue-zague
    $bits  = [];
    foreach ($codewords as $cw) {
        for ($b = 7; $b >= 0; $b--) {
            $bits[] = ($cw >> $b) & 1;
        }
    }
    $total = count($bits);
    $idx   = 0;
    $up    = true;
    for ($col = $n - 1; $col >= 1; $col -= 2) {
        if ($col === 6) {
            $col = 5; // pula a coluna de timing
        }
        for ($k = 0; $k < $n; $k++) {
            $row = $up ? $n - 1 - $k : $k;
            foreach ([$col, $col - 1] as $c) {
                if ($fn[$row][$c]) {
                    continue;
                }
                $bit = $idx < $total ? $bits[$idx] : 0;
                $idx++;
                // Máscara aplicada só aos módulos de dados
                if (man_qr_mask_bit($mask, $row, $c)) {
                    $bit ^= 1;
                }
                $m[$row][$c] = $bit;
            }
        }
        $up = !$up;
    }

    // Informação de formato (após a máscara escolhida)
    $fb = man_qr_format_bits($mask);
    for ($i = 0; $i < 15; $i++) {
        $bit = ($fb >> $i) & 1;
        // Cópia 1: em volta do localizador superior esquerdo (LSB em (0,8))
        if ($i < 6) {
            $m[$i][8] = $bit;
        } elseif ($i < 8) {
            $m[$i + 1][8] = $bit;
        } elseif ($i === 8) {
            $m[8][7] = $bit;
        } else {
            $m[8][14 - $i] = $bit;
        }
        // Cópia 2: linha 8 à direita e coluna 8 abaixo
        if ($i < 8) {
            $m[8][$n - 1 - $i] = $bit;
        } else {
            $m[$n - 15 + $i][8] = $bit;
        }
    }
    $m[$n - 8][8] = 1;

    return [$m, $fn];
}

/** Condição da máscara $mask no módulo (r, c). */
function man_qr_mask_bit(int $mask, int $r, int $c): bool
{
    return match ($mask) {
        0 => ($r + $c) % 2 === 0,
        1 => $r % 2 === 0,
        2 => $c % 3 === 0,
        3 => ($r + $c) % 3 === 0,
        4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
        5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
        6 => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0,
        default => (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0,
    };
}

/** Pontuação de penalidade (regras 1–4 da norma) — menor é melhor. */
function man_qr_penalty(array $m): int
{
    $n = count($m);
    $score = 0;

    // Regra 1: sequências ≥ 5 módulos iguais em linha/coluna
    for ($r = 0; $r < $n; $r++) {
        $runR = 1;
        $runC = 1;
        for ($c = 1; $c < $n; $c++) {
            if ($m[$r][$c] === $m[$r][$c - 1]) {
                $runR++;
                if ($runR === 5) {
                    $score += 3;
                } elseif ($runR > 5) {
                    $score++;
                }
            } else {
                $runR = 1;
            }
            if ($m[$c][$r] === $m[$c - 1][$r]) {
                $runC++;
                if ($runC === 5) {
                    $score += 3;
                } elseif ($runC > 5) {
                    $score++;
                }
            } else {
                $runC = 1;
            }
        }
    }

    // Regra 2: blocos 2×2 da mesma cor
    for ($r = 0; $r < $n - 1; $r++) {
        for ($c = 0; $c < $n - 1; $c++) {
            $v = $m[$r][$c];
            if ($v === $m[$r][$c + 1] && $v === $m[$r + 1][$c] && $v === $m[$r + 1][$c + 1]) {
                $score += 3;
            }
        }
    }

    // Regra 3: padrão 1011101 com 4 claros de um dos lados
    $p1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
    $p2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
    for ($r = 0; $r < $n; $r++) {
        for ($c = 0; $c <= $n - 11; $c++) {
            $okR1 = $okR2 = $okC1 = $okC2 = true;
            for ($k = 0; $k < 11; $k++) {
                $h = $m[$r][$c + $k];
                $v = $m[$c + $k][$r];
                if ($h !== $p1[$k]) $okR1 = false;
                if ($h !== $p2[$k]) $okR2 = false;
                if ($v !== $p1[$k]) $okC1 = false;
                if ($v !== $p2[$k]) $okC2 = false;
                if (!$okR1 && !$okR2 && !$okC1 && !$okC2) break;
            }
            if ($okR1 || $okR2) $score += 40;
            if ($okC1 || $okC2) $score += 40;
        }
    }

    // Regra 4: proporção de módulos escuros
    $dark = 0;
    foreach ($m as $row) {
        $dark += array_sum($row);
    }
    $pct  = $dark * 100 / ($n * $n);
    $prev = intdiv((int) floor($pct), 5) * 5;
    $next = $prev + 5;
    $score += (int) (min(abs($prev - 50), abs($next - 50)) / 5) * 10;

    return $score;
}

/**
 * Gera a matriz do QR Code (array de linhas; 1 = escuro), já com a
 * melhor máscara aplicada.
 */
function man_qr_matrix(string $data): array
{
    if ($data === '') {
        throw new InvalidArgumentException('QR Code: dado vazio.');
    }
    $version = man_qr_choose_version(strlen($data));
    [$ec, $b1, $d1, $b2, $d2] = man_qr_ec_table_m()[$version];
    $dataCw    = man_qr_data_codewords($data, $version, $b1 * $d1 + $b2 * $d2);
    $codewords = man_qr_interleave($dataCw, $version);

    $best      = null;
    $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        [$m] = man_qr_build_matrix($version, $codewords, $mask);
        $score = man_qr_penalty($m);
        if ($score < $bestScore) {
            $bestScore = $score;
            $best      = $m;
        }
    }
    return $best;
}

/**
 * SVG do QR Code (com zona de silêncio de 4 módulos). $size = lado em px
 * do atributo width/height; o viewBox é em módulos, então escala sem perda.
 */
function man_qr_svg(string $data, int $size = 200): string
{
    $m     = man_qr_matrix($data);
    $n     = count($m);
    $quiet = 4;
    $total = $n + 2 * $quiet;
    $path  = '';
    for ($r = 0; $r < $n; $r++) {
        $c = 0;
        while ($c < $n) {
            if ($m[$r][$c] === 1) {
                $start = $c;
                while ($c < $n && $m[$r][$c] === 1) {
                    $c++;
                }
                $path .= 'M' . ($start + $quiet) . ' ' . ($r + $quiet) . 'h' . ($c - $start) . 'v1h-' . ($c - $start) . 'z';
            } else {
                $c++;
            }
        }
    }
    $size = max(16, $size);
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $total . ' ' . $total . '" shape-rendering="crispEdges" role="img" aria-label="QR Code">'
        . '<rect width="' . $total . '" height="' . $total . '" fill="#fff"/>'
        . '<path d="' . $path . '" fill="#000"/>'
        . '</svg>';
}

/** PNG (binário) do QR Code via GD — $size = lado aproximado em px. */
function man_qr_png(string $data, int $size = 300): string
{
    if (!function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('Extensão GD indisponível para gerar PNG.');
    }
    $m     = man_qr_matrix($data);
    $n     = count($m);
    $quiet = 4;
    $total = $n + 2 * $quiet;
    $scale = max(1, (int) floor(max(16, $size) / $total));
    $px    = $total * $scale;

    $img   = imagecreatetruecolor($px, $px);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefilledrectangle($img, 0, 0, $px - 1, $px - 1, $white);
    for ($r = 0; $r < $n; $r++) {
        for ($c = 0; $c < $n; $c++) {
            if ($m[$r][$c] === 1) {
                $x = ($c + $quiet) * $scale;
                $y = ($r + $quiet) * $scale;
                imagefilledrectangle($img, $x, $y, $x + $scale - 1, $y + $scale - 1, $black);
            }
        }
    }
    ob_start();
    imagepng($img);
    imagedestroy($img);
    return (string) ob_get_clean();
}
