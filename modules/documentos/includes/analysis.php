<?php
/**
 * Funções de análise estatística de séries temporais de indicadores.
 */

/**
 * Calcula estatísticas descritivas de uma série numérica.
 *
 * @param array $values Valores numéricos ordenados por data (ASC)
 * @return array Com: count, mean, median, min, max, sum, stddev, last, first
 */
function stats_describe(array $values) {
    $values = array_map('floatval', array_values($values));
    $n = count($values);
    if ($n === 0) {
        return ['count' => 0, 'mean' => null, 'median' => null, 'min' => null,
                'max' => null, 'sum' => 0, 'stddev' => null, 'first' => null, 'last' => null];
    }

    $sum  = array_sum($values);
    $mean = $sum / $n;

    // Desvio padrão amostral
    $var = 0.0;
    foreach ($values as $v) $var += ($v - $mean) ** 2;
    $stddev = $n > 1 ? sqrt($var / ($n - 1)) : 0;

    // Mediana
    $sorted = $values;
    sort($sorted);
    $mid = (int) floor($n / 2);
    $median = ($n % 2) ? $sorted[$mid] : (($sorted[$mid - 1] + $sorted[$mid]) / 2);

    return [
        'count'  => $n,
        'mean'   => $mean,
        'median' => $median,
        'min'    => min($values),
        'max'    => max($values),
        'sum'    => $sum,
        'stddev' => $stddev,
        'first'  => $values[0],
        'last'   => $values[$n - 1],
    ];
}

/**
 * Determina tendência via regressão linear (slope).
 * Retorna 'up' | 'down' | 'stable' e o slope absoluto em %/período.
 */
function stats_trend(array $values) {
    $n = count($values);
    if ($n < 2) return ['direction' => 'stable', 'slope' => 0, 'pct_change' => 0];

    $x_mean = ($n - 1) / 2;
    $y_mean = array_sum($values) / $n;

    $num = 0; $den = 0;
    foreach ($values as $i => $y) {
        $num += ($i - $x_mean) * ($y - $y_mean);
        $den += ($i - $x_mean) ** 2;
    }
    $slope = $den != 0 ? $num / $den : 0;

    $first = (float) $values[0];
    $last  = (float) $values[$n - 1];
    $pct   = $first != 0 ? (($last - $first) / abs($first)) * 100 : 0;

    $threshold = abs($y_mean) * 0.01; // 1% da média como tolerância
    $dir = abs($slope) < $threshold ? 'stable' : ($slope > 0 ? 'up' : 'down');

    return [
        'direction' => $dir,
        'slope'     => $slope,
        'pct_change' => $pct,
    ];
}

/**
 * Classifica um valor contra a meta.
 * Retorna: 'met' | 'tolerance' | 'missed'
 */
function stats_goal_status($value, $goal, $direction = 'higher_better', $tolerance = 0) {
    if ($goal === null || $goal === '') return null;
    $v = (float) $value; $g = (float) $goal; $t = (float) $tolerance;

    switch ($direction) {
        case 'higher_better':
            if ($v >= $g) return 'met';
            if ($t > 0 && $v >= $g - $t) return 'tolerance';
            return 'missed';

        case 'lower_better':
            if ($v <= $g) return 'met';
            if ($t > 0 && $v <= $g + $t) return 'tolerance';
            return 'missed';

        case 'target':
            if (abs($v - $g) <= $t) return 'met';
            if (abs($v - $g) <= $t * 2) return 'tolerance';
            return 'missed';
    }
    return null;
}

/**
 * Percentual de valores que atingiram a meta.
 */
function stats_goal_attainment(array $values, $goal, $direction = 'higher_better', $tolerance = 0) {
    if (empty($values) || $goal === null || $goal === '') return null;
    $met = 0;
    foreach ($values as $v) {
        $status = stats_goal_status($v, $goal, $direction, $tolerance);
        if ($status === 'met' || $status === 'tolerance') $met++;
    }
    return ($met / count($values)) * 100;
}

/**
 * Compara o último valor (ou última média) com o período anterior equivalente.
 *
 * @param array $series [['date' => 'Y-m-d', 'value' => x], ...] ordenado ASC
 * @param string $period_type daily|monthly|yearly
 * @return array ['current' => ..., 'previous' => ..., 'delta' => ..., 'delta_pct' => ...]
 */
function stats_period_comparison(array $series, $period_type = 'monthly') {
    $n = count($series);
    if ($n < 2) return ['current' => null, 'previous' => null, 'delta' => null, 'delta_pct' => null];

    // Último valor
    $current  = (float) $series[$n - 1]['value'];
    $previous = (float) $series[$n - 2]['value'];

    $delta = $current - $previous;
    $pct   = $previous != 0 ? ($delta / abs($previous)) * 100 : null;

    return [
        'current'   => $current,
        'previous'  => $previous,
        'delta'     => $delta,
        'delta_pct' => $pct,
    ];
}

/**
 * Analisa uma série completa: estatísticas + tendência + meta + comparação.
 *
 * @param array $series [['date' => 'Y-m-d', 'value' => x], ...]
 */
function analysis_full(array $series, $goal = null, $direction = 'higher_better', $tolerance = 0, $period_type = 'monthly') {
    $values = array_column($series, 'value');
    $stats  = stats_describe($values);
    $trend  = stats_trend($values);
    $compare = stats_period_comparison($series, $period_type);

    $goal_status = null;
    $goal_pct    = null;
    if ($goal !== null && $goal !== '') {
        if ($stats['last'] !== null) {
            $goal_status = stats_goal_status($stats['last'], $goal, $direction, $tolerance);
        }
        $goal_pct = stats_goal_attainment($values, $goal, $direction, $tolerance);
    }

    return [
        'stats'       => $stats,
        'trend'       => $trend,
        'compare'     => $compare,
        'goal_status' => $goal_status,
        'goal_pct'    => $goal_pct,
    ];
}
