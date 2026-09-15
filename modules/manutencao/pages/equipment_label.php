<?php
/**
 * ETIQUETAS DE EQUIPAMENTO — página standalone para impressão
 *
 * ?page=equipment&action=label&id=X            uma etiqueta
 * ?page=equipment&action=label&ids=1,2,3       lote
 * &size=50x30 | 70x40 | 100x50 | a4            tamanho (padrão 50x30)
 * &hide=org,sector,bar                         elementos ocultos
 *
 * Elementos (todos podem ser ocultados um a um, na barra de ferramentas):
 *   org     nome da organização
 *   name    nome do equipamento
 *   sector  setor + código interno
 *   qr      QR code (abre o histórico pelo código)
 *   bar     código de barras Code 128
 *   code    código em texto
 *
 * O LAYOUT SE AJUSTA ao conjunto visível: nada tem altura fixa em mm. A
 * coluna de texto e o bloco de baixo tomam o espaço de que precisam e o QR
 * fica com o que sobra, sempre quadrado (aspect-ratio). Era daí que vinha o
 * corte: o QR tinha altura fixa (h − 8,5 mm) e, somado ao código de barras e
 * ao código em texto, passava da altura útil da etiqueta — 34,8 mm de
 * conteúdo em 27 mm de papel na 50 × 30 —, e o overflow:hidden cortava
 * justamente o QR.
 *
 * Sem dependências externas (SVG inline) — imprime fiel em impressoras
 * térmicas (uma etiqueta por página) ou em folha A4 com grade.
 *
 * Incluído por equipment.php (já com equipment.view verificado).
 */

$ids = [];
if (!empty($_GET['ids'])) {
    foreach (explode(',', (string)$_GET['ids']) as $v) {
        $v = (int)trim($v);
        if ($v > 0) $ids[] = $v;
    }
} elseif (!empty($_GET['id'])) {
    $ids[] = (int)$_GET['id'];
}
$ids = array_values(array_unique(array_slice($ids, 0, 500)));

$sizes = [
    '50x30'  => ['label' => '50 × 30 mm',  'w' => 50,  'h' => 30, 'grid' => false, 'cols' => 1],
    '70x40'  => ['label' => '70 × 40 mm',  'w' => 70,  'h' => 40, 'grid' => false, 'cols' => 1],
    '100x50' => ['label' => '100 × 50 mm', 'w' => 100, 'h' => 50, 'grid' => false, 'cols' => 1],
    'a4'     => ['label' => 'A4 — grade 3 × 8', 'w' => 70, 'h' => 37, 'grid' => true, 'cols' => 3],
];
$size = $_GET['size'] ?? '50x30';
if (!isset($sizes[$size])) $size = '50x30';
$cfg = $sizes[$size];

// ── Elementos ocultos ──────────────────────────────────────────────────────
// Vêm na URL para que a escolha sobreviva à impressão e possa ser guardada
// nos favoritos; o JS mantém a URL em dia enquanto se marca e desmarca.
$elementos = [
    'org'    => 'Organização',
    'name'   => 'Nome do equipamento',
    'sector' => 'Setor e código interno',
    'qr'     => 'QR Code',
    'bar'    => 'Código de barras',
    'code'   => 'Código em texto',
];
$ocultos = [];
foreach (explode(',', (string)($_GET['hide'] ?? '')) as $k) {
    $k = trim($k);
    if ($k !== '' && isset($elementos[$k])) $ocultos[$k] = true;
}
// Etiqueta sem nada não é etiqueta: se tudo foi desmarcado, o código volta —
// é o único elemento que identifica o equipamento sozinho.
if (count($ocultos) === count($elementos)) {
    unset($ocultos['code']);
}
$mostra = fn (string $k): bool => !isset($ocultos[$k]);

$items = [];
if ($ids !== []) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT e.id, e.name, e.asset_code, e.code, e.model, e.manufacturer, s.name AS sector_name FROM man_equipment e LEFT JOIN man_sectors s ON s.id = e.sector_id WHERE e.hospital_id = ? AND e.id IN ({$in}) ORDER BY FIELD(e.id, {$in})");
    $st->execute(array_merge([$hid], $ids, $ids));
    foreach ($st->fetchAll() as $row) {
        if (empty($row['asset_code'])) {
            try { $row['asset_code'] = man_asset_code_ensure((int)$row['id']); } catch (Throwable $ignored) { continue; }
        }
        $items[] = $row;
    }
}

$orgName = manOrgName();
$baseParams = ($ids !== [] ? (count($ids) > 1 ? ['ids' => implode(',', $ids)] : ['id' => $ids[0]]) : []);
$selfUrl = url('equipment', ['action' => 'label'] + $baseParams);
/** URL desta mesma página com outro tamanho, preservando os elementos ocultos. */
$urlCom = function (array $extra) use ($selfUrl, $size, $ocultos): string {
    $q = ['size' => $size];
    if ($ocultos) $q['hide'] = implode(',', array_keys($ocultos));
    return $selfUrl . '&' . http_build_query(array_merge($q, $extra));
};

// Só há linha de baixo se algo dela estiver visível.
$temRodape = $mostra('bar') || $mostra('code');
$temTexto  = $mostra('org') || $mostra('name') || $mostra('sector');

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Etiquetas de equipamento (<?php echo count($items); ?>)</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { background: #e5e7eb; font-family: Arial, Helvetica, sans-serif; color: #000; }

    .toolbar { background: #fff; border-bottom: 1px solid #cbd5e1; padding: 10px 16px; display: flex; flex-wrap: wrap; gap: 10px 14px; align-items: center; font-size: 14px; }
    .toolbar a, .toolbar button { padding: 6px 12px; border: 1px solid #94a3b8; background: #f8fafc; border-radius: 6px; cursor: pointer; text-decoration: none; color: #0f172a; font-size: 14px; }
    .toolbar a.active { background: #2563eb; border-color: #2563eb; color: #fff; }
    .toolbar .btn-print { background: #16a34a; border-color: #16a34a; color: #fff; font-weight: 600; }
    .toolbar .muted { color: #64748b; }
    .toolbar .grupo { display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: center; }
    .toolbar .sep { width: 100%; height: 0; }
    .toolbar label.chk { display: inline-flex; align-items: center; gap: 5px; padding: 4px 8px; border: 1px solid #cbd5e1; border-radius: 6px; cursor: pointer; user-select: none; background: #f8fafc; }
    .toolbar label.chk input { margin: 0; cursor: pointer; }
    .toolbar label.chk:has(input:checked) { background: #eff6ff; border-color: #93c5fd; }
    .sheet { margin: 12px auto; }

    /* ── Etiqueta ───────────────────────────────────────────────────────────
       Nada tem altura fixa: .top toma o espaço que sobra (min-height:0 deixa
       o flex encolher de verdade) e o QR é quadrado por aspect-ratio, então
       ele acompanha o que restar em vez de estourar a etiqueta.            */
    .label { background: #fff; width: <?php echo $cfg['w']; ?>mm; height: <?php echo $cfg['h']; ?>mm; padding: 1.2mm 1.6mm; overflow: hidden; display: flex; flex-direction: column; gap: 0.6mm; page-break-inside: avoid; break-inside: avoid; }

    .label .top { display: flex; gap: 1.5mm; flex: 1 1 auto; min-height: 0; align-items: stretch; }
    .label .txt { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; justify-content: center; gap: 0.3mm; }
    .label .org    { font-size: 2.1mm; color: #555; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .label .name   { font-weight: bold; font-size: 3.2mm; line-height: 1.15; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    .label .sector { font-size: 2.4mm; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    /* O QR ocupa a altura disponível e fica quadrado. Sem texto ao lado ele
       centraliza e cresce; com texto, divide a linha.                      */
    .label .qr { flex: 0 0 auto; height: 100%; aspect-ratio: 1 / 1; max-width: 100%; }
    .label .qr svg { display: block; width: 100%; height: 100%; }

    .label .bottom { flex: 0 0 auto; display: flex; flex-direction: column; align-items: center; gap: 0.3mm; }
    .label .bar { width: 100%; max-height: <?php echo max(4, $cfg['h'] * 0.24); ?>mm; }
    .label .bar svg { display: block; width: 100%; height: 100%; max-height: <?php echo max(4, $cfg['h'] * 0.24); ?>mm; }
    .label .code { font-family: "Courier New", Courier, monospace; font-weight: bold; font-size: <?php echo $cfg['h'] >= 40 ? '4' : '3.2'; ?>mm; letter-spacing: 0.3mm; text-align: center; line-height: 1.05; white-space: nowrap; }

    /* Só o QR: ele toma a etiqueta inteira, centralizado. */
    .label.qr-only .top { justify-content: center; }
    .label.qr-only .qr  { height: 100%; }

    /* Sem QR: o texto usa a largura toda. */
    .label.no-qr .txt { text-align: left; }

    <?php if ($cfg['grid']): ?>
    /* A4 com grade 3 × 8 */
    .sheet { width: 210mm; min-height: 297mm; background: #fff; padding: 0; page-break-after: always; break-after: page; }
    .grid { display: grid; grid-template-columns: repeat(3, 70mm); grid-auto-rows: 37mm; }
    .grid .label { border: 0.2mm dashed #bbb; width: 70mm; height: 37mm; }
    @page { size: A4 portrait; margin: 0; }
    @media print { .sheet { margin: 0; } .grid .label { border-color: #ddd; } }
    <?php else: ?>
    /* Térmica: uma etiqueta por página */
    .sheet { display: flex; flex-wrap: wrap; gap: 6mm; justify-content: center; max-width: 900px; }
    .label { box-shadow: 0 1px 4px rgba(0,0,0,.25); }
    @page { size: <?php echo $cfg['w']; ?>mm <?php echo $cfg['h']; ?>mm; margin: 0; }
    @media print {
        .sheet { display: block; margin: 0; max-width: none; }
        .label { box-shadow: none; page-break-after: always; break-after: page; margin: 0; }
        .label:last-child { page-break-after: auto; break-after: auto; }
    }
    <?php endif; ?>

    @media print { html, body { background: #fff; } .toolbar { display: none; } }
    .empty { background: #fff; padding: 40px; text-align: center; color: #64748b; margin: 40px auto; max-width: 500px; border-radius: 8px; }
</style>
</head>
<body>
<div class="toolbar">
    <div class="grupo">
        <strong>Tamanho:</strong>
        <?php foreach ($sizes as $k => $s): ?>
            <a href="<?php echo e($urlCom(['size' => $k])); ?>" class="<?php echo $k === $size ? 'active' : ''; ?>"><?php echo e($s['label']); ?></a>
        <?php endforeach; ?>
    </div>
    <div class="sep"></div>
    <div class="grupo">
        <strong>Mostrar:</strong>
        <?php foreach ($elementos as $k => $rotulo): ?>
            <label class="chk">
                <input type="checkbox" data-el="<?php echo e($k); ?>" <?php echo $mostra($k) ? 'checked' : ''; ?>>
                <?php echo e($rotulo); ?>
            </label>
        <?php endforeach; ?>
    </div>
    <div class="sep"></div>
    <div class="grupo" style="width:100%">
        <button type="button" class="btn-print" onclick="window.print()">&#9113; Imprimir</button>
        <button type="button" id="btnTudo">Mostrar tudo</button>
        <span class="muted" style="margin-left:auto"><?php echo count($items); ?> etiqueta(s) — <?php echo e($orgName); ?></span>
    </div>
</div>

<?php if ($items === []): ?>
    <div class="empty">Nenhum equipamento selecionado para etiqueta.</div>
<?php else: ?>
    <?php
    // Lado do QR em px: só para a nitidez do SVG gerado (ele escala pelo
    // viewBox). Um valor generoso evita QR borrado nas etiquetas maiores.
    $qrPx   = (int) round($cfg['h'] * 4);
    $barPx  = (int) max(20, round($cfg['h'] * 1.1));
    $chunks = $cfg['grid'] ? array_chunk($items, 24) : [$items];

    $classes = 'label';
    if (!$mostra('qr'))                 $classes .= ' no-qr';
    elseif (!$temTexto)                 $classes .= ' qr-only';

    foreach ($chunks as $chunk): ?>
    <div class="sheet">
        <?php if ($cfg['grid']): ?><div class="grid"><?php endif; ?>
        <?php foreach ($chunk as $it):
            $code = (string)$it['asset_code'];
            $qr   = $mostra('qr')  ? man_qr_svg(man_asset_code_url($code), $qrPx) : '';
            $bar  = $mostra('bar') ? man_barcode_code128_svg($code, $barPx, false) : '';
        ?>
        <div class="<?php echo $classes; ?>">
            <?php if ($temTexto || $mostra('qr')): ?>
            <div class="top">
                <?php if ($temTexto): ?>
                <div class="txt">
                    <?php if ($mostra('org')): ?>
                        <div class="org"><?php echo e($orgName); ?></div>
                    <?php endif; ?>
                    <?php if ($mostra('name')): ?>
                        <div class="name"><?php echo e($it['name']); ?></div>
                    <?php endif; ?>
                    <?php if ($mostra('sector')): ?>
                        <div class="sector"><?php echo e($it['sector_name'] ?? 'Setor não definido'); ?><?php if ($it['code']): ?> · <?php echo e($it['code']); ?><?php endif; ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($mostra('qr')): ?>
                    <div class="qr"><?php echo $qr; ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($temRodape): ?>
            <div class="bottom">
                <?php if ($mostra('bar')): ?><div class="bar"><?php echo $bar; ?></div><?php endif; ?>
                <?php if ($mostra('code')): ?><div class="code"><?php echo e(man_asset_code_format($code)); ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if ($cfg['grid']): ?></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
(function () {
    'use strict';
    // Marcar/desmarcar recarrega com o novo ?hide=: o layout é montado no
    // servidor (é ele que decide o que gerar), e assim o que está na tela é
    // exatamente o que sai na impressora.
    var caixas = document.querySelectorAll('.toolbar input[data-el]');

    function aplicar(ocultos) {
        var u = new URL(window.location.href);
        if (ocultos.length) { u.searchParams.set('hide', ocultos.join(',')); }
        else                { u.searchParams.delete('hide'); }
        window.location.href = u.toString();
    }

    function ocultosAtuais() {
        var fora = [];
        caixas.forEach(function (c) { if (!c.checked) fora.push(c.dataset.el); });
        return fora;
    }

    caixas.forEach(function (c) {
        c.addEventListener('change', function () {
            var fora = ocultosAtuais();
            // Desmarcar tudo deixaria uma etiqueta em branco: o código volta.
            if (fora.length === caixas.length) {
                fora = fora.filter(function (k) { return k !== 'code'; });
            }
            try { localStorage.setItem('manEtiquetaOcultos', fora.join(',')); } catch (e) { /* sem storage */ }
            aplicar(fora);
        });
    });

    document.getElementById('btnTudo').addEventListener('click', function () {
        try { localStorage.removeItem('manEtiquetaOcultos'); } catch (e) { /* sem storage */ }
        aplicar([]);
    });

    // Primeira visita nesta sessão sem ?hide=: repete a escolha anterior.
    if (!new URL(window.location.href).searchParams.has('hide')) {
        var salvo = null;
        try { salvo = localStorage.getItem('manEtiquetaOcultos'); } catch (e) { /* sem storage */ }
        if (salvo) { aplicar(salvo.split(',').filter(Boolean)); }
    }
})();
</script>
</body>
</html>
