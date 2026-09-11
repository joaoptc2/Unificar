<?php
/**
 * CÓDIGO DE BARRAS CODE 128 EM PHP PURO (sem dependências externas)
 *
 *   man_barcode_code128_svg(string $text, int $height = 50, bool $showText = true): string
 *   man_barcode_code128_png(string $text, int $moduleWidth = 2, int $height = 60): string (binário)
 *
 * Códigos só com dígitos usam o subconjunto C (2 dígitos por símbolo —
 * barras mais compactas, ideal para o código de 12 dígitos do
 * equipamento). Textos com outros caracteres usam o subconjunto B
 * (ASCII 32–127). Quantidade ímpar de dígitos: começa em B com o primeiro
 * dígito e troca para C.
 *
 * Estrutura: START + dados + CHECKSUM (soma ponderada mod 103) + STOP.
 */

/** Padrões (larguras barra/espaço) dos 107 símbolos Code 128. */
function man_barcode_code128_patterns(): array
{
    return [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];
}

/**
 * Sequência de valores (0–106) do símbolo, já com START, CHECKSUM e STOP.
 *
 * @return int[]
 */
function man_barcode_code128_values(string $text): array
{
    if ($text === '') {
        throw new InvalidArgumentException('Code 128: texto vazio.');
    }
    $values = [];

    if (preg_match('/^\d+$/', $text)) {
        $digits = $text;
        if (strlen($digits) % 2 === 1) {
            // Start B, primeiro dígito em B, depois CODE C
            $values[] = 104;
            $values[] = ord($digits[0]) - 32;
            $values[] = 99;
            $digits   = substr($digits, 1);
        } else {
            $values[] = 105; // Start C
        }
        for ($i = 0; $i < strlen($digits); $i += 2) {
            $values[] = (int) substr($digits, $i, 2);
        }
    } else {
        $values[] = 104; // Start B
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $o = ord($text[$i]);
            if ($o < 32 || $o > 127) {
                throw new InvalidArgumentException('Code 128 (subconjunto B): caractere não suportado.');
            }
            $values[] = $o - 32;
        }
    }

    // Checksum: start + Σ(valor × posição) mod 103
    $sum = $values[0];
    $cnt = count($values);
    for ($i = 1; $i < $cnt; $i++) {
        $sum += $values[$i] * $i;
    }
    $values[] = $sum % 103;
    $values[] = 106; // Stop
    return $values;
}

/**
 * Lista de [x, largura] das barras escuras (em módulos) e largura total.
 *
 * @return array{bars: array<int, array{int,int}>, width: int}
 */
function man_barcode_code128_bars(string $text): array
{
    $patterns = man_barcode_code128_patterns();
    $bars     = [];
    $x        = 0;
    foreach (man_barcode_code128_values($text) as $v) {
        $p = $patterns[$v];
        $len = strlen($p);
        for ($i = 0; $i < $len; $i++) {
            $w = (int) $p[$i];
            if ($i % 2 === 0) {
                $bars[] = [$x, $w];
            }
            $x += $w;
        }
    }
    return ['bars' => $bars, 'width' => $x];
}

/**
 * SVG do código de barras. Largura = (módulos + zona de silêncio 10+10) ×
 * 1 unidade no viewBox — dimensione por CSS (width) mantendo a proporção,
 * ou use o atributo width padrão (2 px por módulo).
 */
function man_barcode_code128_svg(string $text, int $height = 50, bool $showText = true): string
{
    $data   = man_barcode_code128_bars($text);
    $quiet  = 10;
    $width  = $data['width'] + 2 * $quiet;
    $height = max(10, $height);
    $textH  = $showText ? 12 : 0;
    $totalH = $height + $textH;

    $path = '';
    foreach ($data['bars'] as [$x, $w]) {
        $path .= 'M' . ($x + $quiet) . ' 0h' . $w . 'v' . $height . 'h-' . $w . 'z';
    }

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . ($width * 2) . '" height="' . ($totalH * 2) . '" viewBox="0 0 ' . $width . ' ' . $totalH . '" shape-rendering="crispEdges" role="img" aria-label="' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '">'
        . '<rect width="' . $width . '" height="' . $totalH . '" fill="#fff"/>'
        . '<path d="' . $path . '" fill="#000"/>';
    if ($showText) {
        $label = preg_match('/^\d{12}$/', $text) ? trim(chunk_split($text, 4, ' ')) : $text;
        $svg  .= '<text x="' . ($width / 2) . '" y="' . ($height + 10) . '" text-anchor="middle" font-family="monospace" font-size="9" fill="#000">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</text>';
    }
    return $svg . '</svg>';
}

/** PNG (binário) do código de barras via GD — para download/validação. */
function man_barcode_code128_png(string $text, int $moduleWidth = 2, int $height = 60): string
{
    if (!function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('Extensão GD indisponível para gerar PNG.');
    }
    $data        = man_barcode_code128_bars($text);
    $quiet       = 10;
    $moduleWidth = max(1, $moduleWidth);
    $w           = ($data['width'] + 2 * $quiet) * $moduleWidth;
    $h           = max(10, $height);

    $img   = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $white);
    foreach ($data['bars'] as [$x, $bw]) {
        $x0 = ($x + $quiet) * $moduleWidth;
        imagefilledrectangle($img, $x0, 0, $x0 + $bw * $moduleWidth - 1, $h - 1, $black);
    }
    ob_start();
    imagepng($img);
    imagedestroy($img);
    return (string) ob_get_clean();
}
