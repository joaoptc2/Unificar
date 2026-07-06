<?php
/**
 * Controller de Relatórios de Conformidade
 */

function reports_index($param = null) {
    core_require('reports.view');
    $hospital_id = get_hospital_id();

    $doc_stats = ['total'=>0, 'expired'=>0, 'expiring'=>0, 'valid'=>0,
                  'draft'=>0, 'pending_review'=>0, 'review_overdue'=>0];
    $ind_total = 0; $ind_met = 0; $ind_missed = 0; $ind_tolerance = 0;
    $expiring_docs = []; $review_pending = [];

    try {
        $doc_stats = document_stats($hospital_id);

        $indicators = indicator_list($hospital_id, '', '', '', 500, 0);
        $ind_total = count($indicators);
        foreach ($indicators as $ind) {
            $series = indicator_data_series($ind['id']);
            $vals = array_column($series, 'value');
            $last = end($vals);
            $goal = $ind['goal_numeric'] ?? null;
            $dir  = $ind['goal_direction'] ?? 'higher_better';
            $tol  = (float) ($ind['goal_tolerance'] ?? 0);
            if ($last !== false && $goal !== null) {
                $st = stats_goal_status((float) $last, $goal, $dir, $tol);
                if ($st === 'met') $ind_met++;
                elseif ($st === 'tolerance') $ind_tolerance++;
                else $ind_missed++;
            }
        }

        $expiring_docs   = document_expiring_list($hospital_id, 20);
        $review_pending  = document_review_pending_list($hospital_id, 20);
    } catch (Exception $ex) {
        log_error('reports_index', $ex);
    }

    $doc_pct_ok = $doc_stats['total'] > 0
        ? round(($doc_stats['valid'] / $doc_stats['total']) * 100, 1) : 0;
    $ind_pct_ok = $ind_total > 0
        ? round((($ind_met + $ind_tolerance) / $ind_total) * 100, 1) : 0;

    view('reports/index', [
        'page_title'     => 'Relatório de Conformidade',
        'doc_stats'      => $doc_stats,
        'doc_pct_ok'     => $doc_pct_ok,
        'ind_total'      => $ind_total,
        'ind_met'        => $ind_met,
        'ind_tolerance'  => $ind_tolerance,
        'ind_missed'     => $ind_missed,
        'ind_pct_ok'     => $ind_pct_ok,
        'expiring_docs'  => $expiring_docs,
        'review_pending' => $review_pending,
    ]);
}
