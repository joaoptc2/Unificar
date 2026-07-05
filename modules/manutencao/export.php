<?php
/**
 * EXPORTAÇÃO DE DADOS - ManuHosp
 *
 * Endpoints:
 *   export.php?type=calibrations[&format=csv|print][&status=overdue|due_soon|ok]
 *   export.php?type=service_orders[&format=csv]
 *   export.php?type=cleaning[&format=csv]
 *
 * Apenas usuários logados. O filtro por hospital_id é aplicado sempre.
 * "format=print" renderiza HTML otimizado para impressão (sem layout
 * do sistema) — o usuário usa Ctrl+P para salvar em PDF.
 */

require __DIR__ . '/config.php';
requireLogin();

$type   = preg_replace('/[^a-z_]/', '', strtolower($_GET['type'] ?? ''));
$format = in_array($_GET['format'] ?? 'csv', ['csv', 'print'], true) ? $_GET['format'] : 'csv';
$hid    = hospitalId();

if ($type === '') {
    http_response_code(400);
    exit('Tipo de exportação não informado.');
}

/* ------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------ */
function csvHeaders(string $filename): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    // BOM UTF-8 para o Excel abrir com acentuação correta
    echo "\xEF\xBB\xBF";
}

function writeCsvRow($fh, array $row): void
{
    fputcsv($fh, $row, ';', '"');
}

function renderPrint(string $title, array $columns, array $rows): void
{
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:"Segoe UI",system-ui,sans-serif;color:#0f172a;padding:24px}
        h1{font-size:18px;margin-bottom:4px}
        .sub{color:#64748b;font-size:12px;margin-bottom:16px}
        table{width:100%;border-collapse:collapse;font-size:12px}
        th,td{padding:6px 8px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}
        th{background:#f1f5f9;text-transform:uppercase;letter-spacing:.3px;font-size:10px}
        .foot{margin-top:24px;font-size:11px;color:#64748b}
        @media print { .no-print{display:none} body{padding:0} }
    </style></head><body>';
    echo '<div class="no-print" style="margin-bottom:12px"><button onclick="window.print()" style="padding:8px 14px;background:#3b82f6;color:#fff;border:none;border-radius:6px;cursor:pointer">Imprimir / Salvar como PDF</button></div>';
    echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p class="sub">Gerado em ' . date('d/m/Y H:i') . ' — ' . count($rows) . ' registro(s)</p>';
    echo '<table><thead><tr>';
    foreach ($columns as $c) echo '<th>' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($r as $cell) echo '<td>' . nl2br(htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8')) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p class="foot">ManuHosp v' . APP_VERSION . '</p>';
    echo '</body></html>';
}

/* ------------------------------------------------------------------
 * 1. Calibrações
 * ------------------------------------------------------------------ */
if ($type === 'calibrations') {
    $filterStatus = $_GET['status'] ?? 'all';
    $where  = ['c.hospital_id = ?'];
    $params = [$hid];
    if ($filterStatus === 'overdue') {
        $where[] = 'c.next_date < CURDATE()';
    } elseif ($filterStatus === 'due_soon') {
        $where[] = 'c.next_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
    } elseif ($filterStatus === 'ok') {
        $where[] = 'c.next_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
    }

    $sql = "SELECT e.name AS equipment, e.code AS equipment_code,
                   c.calibration_date, c.next_date, c.result, c.responsible_body,
                   c.responsible_person, c.cost, c.observations
            FROM equipment_calibrations c
            JOIN equipment e ON e.id = c.equipment_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY c.next_date ASC";
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    $resultLabels = [
        'conforme' => 'Conforme',
        'nao_conforme' => 'Não conforme',
        'conforme_com_ressalvas' => 'Com ressalvas',
    ];

    $columns = ['Equipamento', 'Código', 'Data', 'Próxima', 'Resultado', 'Órgão', 'Responsável', 'Custo', 'Observações'];
    $mapped = array_map(function ($r) use ($resultLabels) {
        return [
            $r['equipment'],
            $r['equipment_code'],
            date('d/m/Y', strtotime($r['calibration_date'])),
            date('d/m/Y', strtotime($r['next_date'])),
            $resultLabels[$r['result']] ?? $r['result'],
            $r['responsible_body'],
            $r['responsible_person'],
            'R$ ' . number_format((float)$r['cost'], 2, ',', '.'),
            $r['observations'],
        ];
    }, $rows);

    auditLog('export', 'equipment_calibrations', null, 'count=' . count($mapped) . ' format=' . $format);

    if ($format === 'print') {
        renderPrint('Histórico de Calibrações', $columns, $mapped);
        exit;
    }

    csvHeaders('calibracoes_' . date('Ymd_His') . '.csv');
    $fh = fopen('php://output', 'w');
    writeCsvRow($fh, $columns);
    foreach ($mapped as $r) writeCsvRow($fh, $r);
    fclose($fh);
    exit;
}

/* ------------------------------------------------------------------
 * 2. Ordens de serviço
 * ------------------------------------------------------------------ */
if ($type === 'service_orders') {
    $sql = "SELECT so.os_number, so.title, so.type, so.priority, so.status,
                   e.name AS equipment, u.name AS assigned,
                   so.scheduled_date, so.created_at, so.completed_at, so.cost
            FROM service_orders so
            LEFT JOIN equipment e ON e.id = so.equipment_id
            LEFT JOIN users u     ON u.id = so.assigned_to
            WHERE so.hospital_id = ?
            ORDER BY so.created_at DESC";
    $st = db()->prepare($sql);
    $st->execute([$hid]);
    $rows = $st->fetchAll();

    $columns = ['OS', 'Título', 'Tipo', 'Prioridade', 'Status', 'Equipamento', 'Responsável', 'Agendada', 'Criada', 'Concluída', 'Custo'];
    $mapped = array_map(function ($r) {
        return [
            $r['os_number'], $r['title'], $r['type'], $r['priority'], $r['status'],
            $r['equipment'] ?? '', $r['assigned'] ?? '',
            $r['scheduled_date'] ? date('d/m/Y', strtotime($r['scheduled_date'])) : '',
            $r['created_at']    ? date('d/m/Y H:i', strtotime($r['created_at'])) : '',
            $r['completed_at']  ? date('d/m/Y H:i', strtotime($r['completed_at'])) : '',
            'R$ ' . number_format((float)$r['cost'], 2, ',', '.'),
        ];
    }, $rows);

    auditLog('export', 'service_orders', null, 'count=' . count($mapped) . ' format=' . $format);

    if ($format === 'print') {
        renderPrint('Ordens de Serviço', $columns, $mapped);
        exit;
    }

    csvHeaders('ordens_servico_' . date('Ymd_His') . '.csv');
    $fh = fopen('php://output', 'w');
    writeCsvRow($fh, $columns);
    foreach ($mapped as $r) writeCsvRow($fh, $r);
    fclose($fh);
    exit;
}

/* ------------------------------------------------------------------
 * 3. Execuções de limpeza
 * ------------------------------------------------------------------ */
if ($type === 'cleaning') {
    $sql = "SELECT ce.executed_at, s.name AS sector, ce.type, cs.title AS checklist,
                   ce.executed_by_name, ce.compliance_pct, ce.observation
            FROM cleaning_executions ce
            LEFT JOIN sectors s            ON s.id  = ce.sector_id
            LEFT JOIN cleaning_schedules cs ON cs.id = ce.schedule_id
            WHERE ce.hospital_id = ?
            ORDER BY ce.executed_at DESC";
    $st = db()->prepare($sql);
    $st->execute([$hid]);
    $rows = $st->fetchAll();

    $typeLabels = ['concurrent' => 'Concorrente', 'terminal' => 'Terminal', 'preparatory' => 'Preparatória'];

    $columns = ['Data/Hora', 'Setor', 'Tipo', 'Checklist', 'Responsável', 'Conformidade', 'Observação'];
    $mapped = array_map(function ($r) use ($typeLabels) {
        return [
            date('d/m/Y H:i', strtotime($r['executed_at'])),
            $r['sector'] ?? '',
            $typeLabels[$r['type']] ?? $r['type'],
            $r['checklist'] ?? '',
            $r['executed_by_name'],
            $r['compliance_pct'] !== null ? $r['compliance_pct'] . '%' : '',
            $r['observation'] ?? '',
        ];
    }, $rows);

    auditLog('export', 'cleaning_executions', null, 'count=' . count($mapped) . ' format=' . $format);

    if ($format === 'print') {
        renderPrint('Execuções de Limpeza', $columns, $mapped);
        exit;
    }

    csvHeaders('limpeza_' . date('Ymd_His') . '.csv');
    $fh = fopen('php://output', 'w');
    writeCsvRow($fh, $columns);
    foreach ($mapped as $r) writeCsvRow($fh, $r);
    fclose($fh);
    exit;
}

http_response_code(400);
exit('Tipo de exportação inválido.');
