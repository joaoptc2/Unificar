<?php
/**
 * CALENDÁRIO DE MANUTENÇÕES E CALIBRAÇÕES — Design System "RH Hospital"
 *
 * Grade mensal (Seg-Dom) gerada em PHP.
 * Eventos de: maintenance_plans.next_date (azul), equipment_calibrations.next_date (laranja).
 * Navegação: ?month=2026-04 para mês anterior/próximo.
 */
requireModule('calendar');

$hid = hospitalId();

// ============================================================
// MÊS CORRENTE
// ============================================================
$monthParam = trim($_GET['month'] ?? '');
if (preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $year  = (int)substr($monthParam, 0, 4);
    $month = (int)substr($monthParam, 5, 2);
} else {
    $year  = (int)date('Y');
    $month = (int)date('n');
}
if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
    $year  = (int)date('Y');
    $month = (int)date('n');
}

$firstDay   = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth = (int)date('t', $firstDay);
$startWeekday = (int)date('N', $firstDay); // 1=Mon, 7=Sun
// Nomes dos meses em PT-BR (strftime() está obsoleto no PHP 8.1+)
$monthNames = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
];
$monthLabel = $monthNames[$month] . ' ' . $year;

$prevMonth = $month - 1;
$prevYear  = $year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1;
$nextYear  = $year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$prevUrl = url('calendar', ['month' => sprintf('%04d-%02d', $prevYear, $prevMonth)]);
$nextUrl = url('calendar', ['month' => sprintf('%04d-%02d', $nextYear, $nextMonth)]);
$todayUrl = url('calendar');

$today = date('Y-m-d');
$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd   = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

// ============================================================
// EVENTOS
// ============================================================
$events = []; // key = 'YYYY-MM-DD' => [array of events]

// Manutenções preventivas (azul)
try {
    $st = db()->prepare("
        SELECT mp.title, mp.next_date, e.name AS equip_name
        FROM man_maintenance_plans mp
        LEFT JOIN man_equipment e ON e.id = mp.equipment_id
        WHERE mp.hospital_id = ? AND mp.status = 'active'
          AND mp.next_date BETWEEN ? AND ?
        ORDER BY mp.next_date
    ");
    $st->execute([$hid, $monthStart, $monthEnd]);
    foreach ($st->fetchAll() as $r) {
        $d = $r['next_date'];
        $events[$d][] = [
            'title' => $r['title'] . ($r['equip_name'] ? ' - ' . $r['equip_name'] : ''),
            'type'  => 'maintenance',
            'color' => 'primary',
        ];
    }
} catch (\Throwable $ex) {}

// Calibrações (laranja)
try {
    $st = db()->prepare("
        SELECT ec.next_date, e.name AS equip_name, ec.responsible_body
        FROM man_equipment_calibrations ec
        INNER JOIN man_equipment e ON e.id = ec.equipment_id
        WHERE ec.hospital_id = ? AND ec.next_date BETWEEN ? AND ?
        ORDER BY ec.next_date
    ");
    $st->execute([$hid, $monthStart, $monthEnd]);
    foreach ($st->fetchAll() as $r) {
        $d = $r['next_date'];
        $events[$d][] = [
            'title' => 'Calibração: ' . $r['equip_name'],
            'type'  => 'calibration',
            'color' => 'warning',
        ];
    }
} catch (\Throwable $ex) {}

$pageTitle = 'Calendário';
ob_start();
?>

<div class="page-header">
    <h1><i class="bi bi-calendar3 me-2"></i>Calendário — <?php echo e($monthLabel); ?></h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo $prevUrl; ?>"><i class="bi bi-chevron-left"></i></a>
        <a class="btn btn-outline-primary btn-sm" href="<?php echo $todayUrl; ?>">Hoje</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo $nextUrl; ?>"><i class="bi bi-chevron-right"></i></a>
    </div>
</div>

<!-- Legenda -->
<div class="d-flex gap-3 mb-3">
    <span><span class="badge bg-primary">&nbsp;</span> Manutenção preventiva</span>
    <span><span class="badge bg-warning text-dark">&nbsp;</span> Calibração</span>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-0">
        <table class="table table-bordered mb-0" style="table-layout:fixed">
            <thead>
                <tr class="text-center bg-light">
                    <th>Seg</th><th>Ter</th><th>Qua</th><th>Qui</th><th>Sex</th><th class="text-muted">Sáb</th><th class="text-muted">Dom</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $dayCounter = 1;
            $cellCount  = 0;
            $totalCells = ceil(($startWeekday - 1 + $daysInMonth) / 7) * 7;

            for ($cell = 0; $cell < $totalCells; $cell++):
                if ($cell % 7 === 0) echo '<tr>';

                $dayNum = $cell - ($startWeekday - 1) + 1;
                $isValid = ($dayNum >= 1 && $dayNum <= $daysInMonth);
                $dateStr = $isValid ? sprintf('%04d-%02d-%02d', $year, $month, $dayNum) : '';
                $isToday = ($dateStr === $today);
                $dayEvents = $isValid ? ($events[$dateStr] ?? []) : [];
                $cssClass  = 'calendar-day';
                if ($isToday) $cssClass .= ' today';
            ?>
                <td class="<?php echo $cssClass; ?>" style="height:90px;vertical-align:top;padding:4px 6px;<?php echo !$isValid ? 'background:#f8f9fa;' : ''; ?>">
                    <?php if ($isValid): ?>
                        <div class="fw-semibold small <?php echo $isToday ? 'text-primary' : ''; ?>"><?php echo $dayNum; ?></div>
                        <?php foreach (array_slice($dayEvents, 0, 3) as $ev): ?>
                            <div class="calendar-event small text-truncate mb-1" style="border-left:3px solid var(--bs-<?php echo e($ev['color']); ?>);padding-left:4px;font-size:.72rem;" title="<?php echo e($ev['title']); ?>">
                                <?php echo e(mb_strimwidth($ev['title'], 0, 25, '...')); ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($dayEvents) > 3): ?>
                            <div class="text-muted small" style="font-size:.7rem">+<?php echo count($dayEvents) - 3; ?> mais</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            <?php
                if ($cell % 7 === 6) echo '</tr>';
            endfor;
            ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Lista de eventos do mês -->
<?php
$allEvents = [];
foreach ($events as $date => $evts) {
    foreach ($evts as $ev) {
        $allEvents[] = array_merge($ev, ['date' => $date]);
    }
}
usort($allEvents, fn($a, $b) => strcmp($a['date'], $b['date']));
?>
<?php if (!empty($allEvents)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-list-ul me-1"></i> Eventos de <?php echo e($monthLabel); ?> (<?php echo count($allEvents); ?>)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Data</th><th>Tipo</th><th>Descrição</th></tr></thead>
                <tbody>
                <?php foreach ($allEvents as $ev): ?>
                <tr>
                    <td class="text-nowrap"><?php echo formatDate($ev['date']); ?></td>
                    <td><span class="badge bg-<?php echo e($ev['color']); ?> <?php echo $ev['color']==='warning'?'text-dark':''; ?>"><?php echo $ev['type'] === 'maintenance' ? 'Manutenção' : 'Calibração'; ?></span></td>
                    <td><?php echo e($ev['title']); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
