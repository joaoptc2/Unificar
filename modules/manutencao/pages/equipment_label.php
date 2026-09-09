<?php
/**
 * ETIQUETAS DE EQUIPAMENTO — página standalone para impressão
 *
 * ?page=equipment&action=label&id=X            uma etiqueta
 * ?page=equipment&action=label&ids=1,2,3       lote
 * &size=50x30 | 70x40 | a4                     tamanho (padrão 50x30)
 *
 * Cada etiqueta: nome, setor, código formatado, código de barras Code 128
 * e QR code (URL de busca por código → histórico). Sem dependências
 * externas (SVG inline) — imprime fiel em impressoras térmicas de etiqueta
 * (uma etiqueta por página) ou em folha A4 com grade.
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
    '50x30' => ['label' => '50 × 30 mm (térmica)', 'w' => 50, 'h' => 30, 'grid' => false],
    '70x40' => ['label' => '70 × 40 mm (térmica)', 'w' => 70, 'h' => 40, 'grid' => false],
    'a4'    => ['label' => 'A4 — grade 3 × 8 (70 × 37 mm)', 'w' => 70, 'h' => 37, 'grid' => true],
];
$size = $_GET['size'] ?? '50x30';
if (!isset($sizes[$size])) $size = '50x30';
$cfg = $sizes[$size];

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
$selfUrl = url('equipment', ['action' => 'label'] + ($ids !== [] ? (count($ids) > 1 ? ['ids' => implode(',', $ids)] : ['id' => $ids[0]]) : []));

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
    .toolbar { background: #fff; border-bottom: 1px solid #cbd5e1; padding: 10px 16px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; font-size: 14px; }
    .toolbar a, .toolbar button { padding: 6px 12px; border: 1px solid #94a3b8; background: #f8fafc; border-radius: 6px; cursor: pointer; text-decoration: none; color: #0f172a; font-size: 14px; }
    .toolbar a.active { background: #2563eb; border-color: #2563eb; color: #fff; }
    .toolbar .btn-print { background: #16a34a; border-color: #16a34a; color: #fff; font-weight: 600; }
    .toolbar .muted { color: #64748b; margin-left: auto; }
    .sheet { margin: 12px auto; }

    /* Etiqueta */
    .label { background: #fff; width: <?php echo $cfg['w']; ?>mm; height: <?php echo $cfg['h']; ?>mm; padding: 1.5mm 2mm; overflow: hidden; display: flex; flex-direction: column; page-break-inside: avoid; break-inside: avoid; }
    .label .top { display: flex; gap: 1.5mm; flex: 1 1 auto; min-height: 0; }
    .label .txt { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; }
    .label .org { font-size: 2.1mm; color: #555; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .label .name { font-weight: bold; font-size: 3.2mm; line-height: 1.15; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    .label .sector { font-size: 2.4mm; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: auto; }
    .label .qr { flex: 0 0 auto; }
    .label .qr svg { display: block; }
    .label .bar { flex: 0 0 auto; margin-top: 0.8mm; text-align: center; }
    .label .bar svg { display: block; width: 100%; height: auto; }
    .label .code { font-family: "Courier New", Courier, monospace; font-weight: bold; font-size: 3.2mm; letter-spacing: 0.3mm; text-align: center; line-height: 1.1; }

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
    <strong>Tamanho:</strong>
    <?php foreach ($sizes as $k => $s): ?>
        <a href="<?php echo e($selfUrl . '&size=' . $k); ?>" class="<?php echo $k === $size ? 'active' : ''; ?>"><?php echo e($s['label']); ?></a>
    <?php endforeach; ?>
    <button type="button" class="btn-print" onclick="window.print()">&#9113; Imprimir</button>
    <span class="muted"><?php echo count($items); ?> etiqueta(s) — <?php echo e($orgName); ?></span>
</div>

<?php if ($items === []): ?>
    <div class="empty">Nenhum equipamento selecionado para etiqueta.</div>
<?php else: ?>
    <?php
    $qrPx  = (int) round(($cfg['h'] - 3 - 5.5) * 3.78); // lado do QR em px (≈ altura útil menos barcode)
    $chunks = $cfg['grid'] ? array_chunk($items, 24) : [$items];
    foreach ($chunks as $chunk): ?>
    <div class="sheet">
        <?php if ($cfg['grid']): ?><div class="grid"><?php endif; ?>
        <?php foreach ($chunk as $it):
            $code = (string)$it['asset_code'];
            $qr   = man_qr_svg(man_asset_code_url($code), $qrPx);
            $bar  = man_barcode_code128_svg($code, 34, false);
        ?>
        <div class="label">
            <div class="top">
                <div class="txt">
                    <div class="org"><?php echo e($orgName); ?></div>
                    <div class="name"><?php echo e($it['name']); ?></div>
                    <div class="sector"><?php echo e($it['sector_name'] ?? 'Setor não definido'); ?><?php if ($it['code']): ?> · <?php echo e($it['code']); ?><?php endif; ?></div>
                </div>
                <div class="qr" style="width:<?php echo $cfg['h'] - 8.5; ?>mm;height:<?php echo $cfg['h'] - 8.5; ?>mm">
                    <?php echo preg_replace('/^<svg /', '<svg style="width:100%;height:100%" ', $qr); ?>
                </div>
            </div>
            <div class="bar"><?php echo $bar; ?></div>
            <div class="code"><?php echo e(man_asset_code_format($code)); ?></div>
        </div>
        <?php endforeach; ?>
        <?php if ($cfg['grid']): ?></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
</body>
</html>
