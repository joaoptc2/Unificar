<?php
/**
 * Paridade entre a conta de contraste do SERVIDOR (Core\Tokens) e a do
 * NAVEGADOR (assets/core/contrast.js).
 *
 * A conta existe nos dois lados de propósito: o selo precisa responder
 * enquanto a pessoa arrasta o seletor de cor, e uma ida ao servidor por
 * movimento do mouse não serve. O preço de manter duas cópias é este
 * teste — se elas divergirem, ele falha e diz em qual par.
 *
 * Uso:  php scripts/test_contraste.php
 * Sai com código 1 quando encontra divergência (serve para CI).
 */

declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME']    = '/index.php';
require __DIR__ . '/../core/bootstrap.php';

use Core\Tokens;

$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    fwrite(STDERR, "node não encontrado — o teste de paridade precisa dele.\n");
    exit(2);
}

// Pares escolhidos para cobrir as bordas: extremos, limites exatos das
// faixas da WCAG (3:1, 4,5:1, 7:1), cores de estado do portal e o âmbar,
// que é o caso em que a régua de brilho erra e a de contraste acerta.
$pares = [
    ['#ffffff', '#000000'], ['#000000', '#ffffff'], ['#ffffff', '#ffffff'],
    ['#0d5c8f', '#ffffff'], ['#ffffff', '#0d5c8f'], ['#ffc107', '#ffffff'],
    ['#198754', '#ffffff'], ['#dc3545', '#ffffff'], ['#0dcaf0', '#ffffff'],
    ['#777777', '#ffffff'], ['#767676', '#ffffff'], ['#949494', '#ffffff'],
    ['#1f2937', '#f4f6f9'], ['#b3005e', '#ffffff'], ['#0f172a', '#111827'],
    ['#abc',    '#def'],    ['#AABBCC', '#123456'], ['#f0f', '#0f0'],
];

// Gera valores aleatórios e determinísticos para além dos casos escolhidos.
mt_srand(20260915);
for ($i = 0; $i < 200; $i++) {
    $pares[] = [
        sprintf('#%06x', mt_rand(0, 0xFFFFFF)),
        sprintf('#%06x', mt_rand(0, 0xFFFFFF)),
    ];
}

// ── Lado do navegador ──────────────────────────────────────────────────────
$js = <<<'JS'
const fs = require('fs');
const src = fs.readFileSync(process.argv[2], 'utf8');
global.window = {};
new Function(src)();
const pares = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
console.log(JSON.stringify(pares.map(p => {
    const r = global.window.PortalContraste.avaliar(p[0], p[1]);
    return { ratio: r.ratio, nivel: r.nivel, resumo: r.resumo, texto: r.texto };
})));
JS;

$tmpJs    = tempnam(sys_get_temp_dir(), 'contraste_') . '.js';
$tmpPares = tempnam(sys_get_temp_dir(), 'pares_') . '.json';
file_put_contents($tmpJs, $js);
file_put_contents($tmpPares, json_encode($pares));

$saida = shell_exec(sprintf(
    '%s %s %s %s 2>&1',
    escapeshellcmd($node),
    escapeshellarg($tmpJs),
    escapeshellarg(__DIR__ . '/../assets/core/contrast.js'),
    escapeshellarg($tmpPares)
));
@unlink($tmpJs);
@unlink($tmpPares);

$doNavegador = json_decode((string) $saida, true);
if (!is_array($doNavegador)) {
    fwrite(STDERR, "Não foi possível executar o lado JavaScript:\n" . $saida . "\n");
    exit(2);
}

// ── Comparação ─────────────────────────────────────────────────────────────
$falhas = 0;
foreach ($pares as $i => [$fg, $bg]) {
    $php = Tokens::contrastReport($fg, $bg);
    $js  = $doNavegador[$i] ?? null;
    if ($js === null) {
        printf("  FALTOU no JS: %s sobre %s\n", $fg, $bg);
        $falhas++;
        continue;
    }
    // Tolerância de 0,01 para o arredondamento de ponto flutuante dos dois.
    $difRatio = abs((float) $php['ratio'] - (float) $js['ratio']);
    $mesmo = $difRatio <= 0.011
        && $php['nivel'] === $js['nivel']
        && $php['resumo'] === $js['resumo']
        && $php['texto'] === $js['texto'];

    if (!$mesmo) {
        printf("  DIVERGÊNCIA %s sobre %s\n    PHP: %s %s (%s) | JS: %s %s (%s)\n",
            $fg, $bg,
            $php['texto'], $php['nivel'], $php['resumo'],
            $js['texto'], $js['nivel'], $js['resumo']);
        $falhas++;
    }
}

$total = count($pares);
if ($falhas === 0) {
    printf("Paridade de contraste: %d pares conferidos, servidor e navegador concordam em todos.\n", $total);
    exit(0);
}
printf("Paridade de contraste: %d de %d pares DIVERGIRAM.\n", $falhas, $total);
exit(1);
