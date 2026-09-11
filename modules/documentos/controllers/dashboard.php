<?php
/**
 * Controller do Dashboard (KPIs, alertas, notificações e a seção
 * "Conformidade" — antigo Relatório de Conformidade) + troca do setor
 * global em foco.
 */

function dashboard_index($param = null) {
    core_require('dashboard.view');

    $hospital_id = get_hospital_id();
    $sector_id   = get_sector_id();

    $stats = [
        'total_documents'      => 0,
        'expired_documents'    => 0,
        'expiring_documents'   => 0,
        'valid_documents'      => 0,
        'uncontrolled_documents' => 0,
        'total_indicators'     => 0,
        'unread_notifications' => 0,
    ];
    $doc_stats = ['total'=>0, 'expired'=>0, 'expiring'=>0, 'valid'=>0,
                  'draft'=>0, 'pending_review'=>0, 'review_overdue'=>0, 'uncontrolled'=>0];
    $expiring_docs = [];
    $expired_docs  = [];
    $review_pending = [];
    $notifications = [];
    $ind_total = 0; $ind_met = 0; $ind_missed = 0; $ind_tolerance = 0; $ind_no_data = 0;
    $ind_missed_list = [];
    $actions_open = 0; $actions_overdue = 0;

    try {
        $doc_stats = document_stats($hospital_id, $sector_id);
        $stats['total_documents']    = $doc_stats['total'];
        $stats['expired_documents']  = $doc_stats['expired'];
        $stats['expiring_documents'] = $doc_stats['expiring'];
        $stats['valid_documents']    = $doc_stats['valid'];
        $stats['uncontrolled_documents'] = $doc_stats['uncontrolled'];

        $stats['total_indicators']     = indicator_count($hospital_id, '', '', '', $sector_id);
        $stats['unread_notifications'] = notification_unread_count(get_user_id());

        $expiring_docs  = document_expiring_list($hospital_id, 10, $sector_id);
        $expired_docs   = document_expired_list($hospital_id, 10, $sector_id);
        $review_pending = document_review_pending_list($hospital_id, 20, $sector_id);
        $notifications  = notification_recent(get_user_id(), 5);

        // Conformidade dos indicadores (último valor × meta)
        $indicators = indicator_list($hospital_id, '', '', '', 500, 0, $sector_id);
        $ind_total = count($indicators);
        $all_series = indicator_data_series_many(array_column($indicators, 'id')); // 1 consulta (sem N+1)
        foreach ($indicators as $ind) {
            $series = $all_series[(int) $ind['id']] ?? [];
            $vals = array_column($series, 'value');
            $last = end($vals);
            $goal = $ind['goal_numeric'] ?? null;
            $dir  = $ind['goal_direction'] ?? 'higher_better';
            $tol  = (float) ($ind['goal_tolerance'] ?? 0);
            if ($last !== false && $goal !== null) {
                $st = stats_goal_status((float) $last, $goal, $dir, $tol);
                if ($st === 'met') $ind_met++;
                elseif ($st === 'tolerance') $ind_tolerance++;
                else {
                    $ind_missed++;
                    $ind_missed_list[] = [
                        'id' => $ind['id'], 'name' => $ind['name'], 'category' => $ind['category'],
                        'last' => (float) $last, 'goal' => (float) $goal, 'unit' => $ind['unit'],
                        'decimal_places' => (int) ($ind['decimal_places'] ?? 2),
                    ];
                }
            } else {
                $ind_no_data++;
            }
        }

        // Planos de ação abertos / atrasados
        $ar = db_query_one(
            "SELECT SUM(CASE WHEN a.status IN ('pending','in_progress') THEN 1 ELSE 0 END) AS open_n,
                    SUM(CASE WHEN a.status IN ('pending','in_progress') AND a.due_date IS NOT NULL AND a.due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_n
             FROM doc_indicator_actions a
             JOIN doc_indicators i ON i.id = a.indicator_id
             WHERE a.deleted_at IS NULL AND i.deleted_at IS NULL AND i.hospital_id = ?"
             . ($sector_id > 0 ? " AND i.sector_id = ?" : ""),
            $sector_id > 0 ? [$hospital_id, $sector_id] : [$hospital_id]
        );
        $actions_open    = (int) ($ar['open_n'] ?? 0);
        $actions_overdue = (int) ($ar['overdue_n'] ?? 0);
    } catch (Exception $ex) {
        log_error('dashboard_index', $ex);
    }

    $doc_pct_ok = $doc_stats['total'] > 0
        ? round(($doc_stats['valid'] / $doc_stats['total']) * 100, 1) : 0;
    $ind_pct_ok = ($ind_met + $ind_tolerance + $ind_missed) > 0
        ? round((($ind_met + $ind_tolerance) / ($ind_met + $ind_tolerance + $ind_missed)) * 100, 1) : 0;

    view('dashboard/index', [
        'page_title'    => 'Dashboard',
        'stats'         => $stats,
        'expiring_docs' => $expiring_docs,
        'expired_docs'  => $expired_docs,
        'notifications' => $notifications,
        // Conformidade
        'doc_stats'      => $doc_stats,
        'doc_pct_ok'     => $doc_pct_ok,
        'ind_total'      => $ind_total,
        'ind_met'        => $ind_met,
        'ind_tolerance'  => $ind_tolerance,
        'ind_missed'     => $ind_missed,
        'ind_no_data'    => $ind_no_data,
        'ind_pct_ok'     => $ind_pct_ok,
        'ind_missed_list'=> $ind_missed_list,
        'review_pending' => $review_pending,
        'actions_open'   => $actions_open,
        'actions_overdue'=> $actions_overdue,
    ]);
}

/**
 * Troca o setor global em foco (0 = "Todos os setores") e volta para a
 * página de origem (campo `return`, restrito ao próprio sistema).
 */
function dashboard_switch_sector($param = null) {
    require_login();
    if (!is_post()) redirect('dashboard');
    csrf_validate();

    $sector_id = sanitize_int(input('sector_id'));
    $name = '';
    if ($sector_id > 0) {
        $s = sector_find_active($sector_id, get_hospital_id());
        if (!$s) {
            set_flash('error', 'Setor não encontrado ou inativo.');
            $sector_id = 0;
        } else {
            $name = $s['name'];
        }
    }
    switch_sector_context($sector_id, $name);
    audit_log('sector_context_switch', "sector_id=$sector_id");

    // Volta para a página de origem (somente caminhos internos)
    // Somente caminho absoluto interno: começa com "/" (não "//" nem "/\", que
    // os navegadores tratam como URL protocolo-relativa) e sem quebras de linha.
    $return = (string) input('return', '');
    if ($return !== '' && preg_match('#^/(?![/\\\\])[^\r\n\\\\]*$#', $return)) {
        header('Location: ' . $return);
        exit;
    }
    redirect('dashboard');
}
