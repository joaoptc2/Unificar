<?php
/**
 * Controller de Indicadores de Enfermagem (v2)
 */

// ═══════════════════════════════════════════════════════════════════════════
//  Listagem
// ═══════════════════════════════════════════════════════════════════════════

function indicators_index($param = null) {
    require_login();
    $hospital_id = get_hospital_id();

    $type     = (string) query('type', '');
    $search   = clean(query('search', ''));
    $category = clean(query('category', ''));

    $indicators = [];
    $total = 0;
    $categories = [];

    try {
        $total      = indicator_count($hospital_id, $type, $search, $category);
        $pagination = paginate($total);
        $indicators = indicator_list($hospital_id, $type, $search, $category,
                                     $pagination['per_page'], $pagination['offset']);
        $categories = indicator_distinct_categories($hospital_id);

        // Anexa série de valores para mini-gráficos + último status vs meta
        foreach ($indicators as &$ind) {
            $series = indicator_data_series($ind['id']);
            $vals = array_column($series, 'value');
            $ind['spark_values'] = array_slice($vals, -12);
            $ind['last_value']   = end($vals) !== false ? (float) end($vals) : null;
            $ind['goal_status']  = ($ind['last_value'] !== null && $ind['goal_numeric'] !== null)
                ? stats_goal_status($ind['last_value'], $ind['goal_numeric'],
                                    $ind['goal_direction'], $ind['goal_tolerance'])
                : null;
            $ind['count_entries'] = count($vals);
        }
        unset($ind);
    } catch (Exception $ex) {
        log_error('indicators_index', $ex);
        $pagination = paginate(0);
    }

    view('indicators/index', [
        'page_title'  => 'Indicadores de Enfermagem',
        'indicators'  => $indicators,
        'type_filter' => $type,
        'search'      => $search,
        'category'    => $category,
        'categories'  => $categories,
        'pagination'  => $pagination,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Cadastro / Edição do indicador
// ═══════════════════════════════════════════════════════════════════════════

function indicators_create($param = null) {
    require_login();

    // Pré-popula com template, se veio ?template=slug
    $template_slug = query('template', '');
    $tpl = $template_slug ? indicator_template_find($template_slug) : null;
    $indicator = null;
    $variables = [];

    if ($tpl) {
        $indicator = [
            'name' => $tpl['name'],
            'type' => $tpl['type'],
            'unit' => $tpl['unit'],
            'goal' => $tpl['goal'] ?? '',
            'goal_numeric' => $tpl['goal'] ?? null,
            'goal_direction' => $tpl['goal_direction'] ?? 'higher_better',
            'goal_tolerance' => $tpl['goal_tolerance'] ?? 0,
            'formula' => $tpl['formula'],
            'category' => $tpl['category'],
            'decimal_places' => $tpl['decimal_places'] ?? 2,
            'description' => $tpl['description'],
            'chart_type' => $tpl['chart_type'] ?? 'line',
            'benchmark_value' => $tpl['benchmark'] ?? null,
            'benchmark_source' => $tpl['benchmark_source'] ?? null,
            'accreditation' => $tpl['accreditation'] ?? null,
            'template_slug' => $template_slug,
        ];
        $variables = $tpl['variables'] ?? [];
        set_flash('info', 'Template carregado: <strong>' . e($tpl['name']) . '</strong>. Revise os campos e salve.');
    }

    view('indicators/form', [
        'page_title' => 'Novo Indicador',
        'indicator'  => $indicator,
        'variables'  => $variables,
        'editing'    => false,
    ]);
}

/**
 * Galeria de templates para criar indicadores pré-configurados.
 */
function indicators_templates($param = null) {
    require_login();

    $category_filter = (string) query('category', '');
    $search = clean(query('search', ''));
    $templates = indicator_templates();
    $categories = indicator_template_categories();

    if ($category_filter !== '') {
        $templates = array_filter($templates, fn($t) => $t['category'] === $category_filter);
    }
    if ($search !== '') {
        $needle = mb_strtolower($search);
        $templates = array_filter($templates, function($t) use ($needle) {
            return str_contains(mb_strtolower($t['name']), $needle)
                || str_contains(mb_strtolower($t['description'] ?? ''), $needle);
        });
    }

    view('indicators/templates', [
        'page_title' => 'Templates de Indicadores',
        'templates'  => $templates,
        'categories' => $categories,
        'category_filter' => $category_filter,
        'search'     => $search,
    ]);
}

/**
 * Dashboard executivo consolidado — todos os indicadores com semáforos.
 */
function indicators_dashboard($param = null) {
    require_login();
    $hospital_id = get_hospital_id();

    $category = clean(query('category', ''));
    $indicators = [];
    $categories = [];

    try {
        $indicators = indicator_list($hospital_id, '', '', $category, 500, 0);
        $categories = indicator_distinct_categories($hospital_id);

        // Enriquece cada indicador com último valor, status, tendência, histórico curto
        foreach ($indicators as &$ind) {
            $series = indicator_data_series($ind['id']);
            $vals = array_map(fn($s) => (float) $s['value'], $series);
            $ind['spark_values'] = array_slice($vals, -12);
            $ind['last_value']   = end($vals) !== false ? (float) end($vals) : null;
            $ind['entries_count'] = count($vals);

            $goal = $ind['goal_numeric'];
            $tol  = (float) ($ind['goal_tolerance'] ?? 0);
            $dir  = $ind['goal_direction'] ?? 'higher_better';
            $ind['goal_status'] = ($ind['last_value'] !== null && $goal !== null)
                ? stats_goal_status($ind['last_value'], $goal, $dir, $tol) : null;

            if (count($vals) >= 2) {
                $trend = stats_trend($vals);
                $ind['trend_dir'] = $trend['direction'];
                $ind['trend_pct'] = $trend['pct_change'];
            } else {
                $ind['trend_dir'] = null;
                $ind['trend_pct'] = 0;
            }
        }
        unset($ind);
    } catch (Exception $ex) {
        log_error('indicators_dashboard', $ex);
    }

    // Agrupa por categoria
    $grouped = [];
    foreach ($indicators as $ind) {
        $cat = $ind['category'] ?: 'Sem categoria';
        $grouped[$cat][] = $ind;
    }
    ksort($grouped);

    // KPIs globais
    $total = count($indicators);
    $met = count(array_filter($indicators, fn($i) => $i['goal_status'] === 'met'));
    $tolerance = count(array_filter($indicators, fn($i) => $i['goal_status'] === 'tolerance'));
    $missed = count(array_filter($indicators, fn($i) => $i['goal_status'] === 'missed'));
    $no_data = count(array_filter($indicators, fn($i) => $i['goal_status'] === null));

    view('indicators/dashboard', [
        'page_title' => 'Painel de Indicadores',
        'grouped'    => $grouped,
        'categories' => $categories,
        'category'   => $category,
        'kpi'        => [
            'total' => $total, 'met' => $met, 'tolerance' => $tolerance,
            'missed' => $missed, 'no_data' => $no_data,
        ],
    ]);
}

function indicators_edit($param = null) {
    require_login();
    $id = sanitize_int($param ?: query('id'));
    $hospital_id = get_hospital_id();

    try {
        $indicator = indicator_find($id, $hospital_id);
        $variables = $indicator ? indicator_variables_list($id) : [];
    } catch (Exception $ex) {
        log_error('indicators_edit', $ex);
        $indicator = null; $variables = [];
    }

    if (!$indicator) {
        set_flash('error', 'Indicador não encontrado.');
        redirect('indicators');
    }

    view('indicators/form', [
        'page_title' => 'Editar Indicador',
        'indicator'  => $indicator,
        'variables'  => $variables,
        'editing'    => true,
    ]);
}

function indicators_store($param = null) {
    require_login();
    if (!is_post()) redirect('indicators');
    csrf_validate();

    $input  = _indicators_collect_form();
    $errors = _indicators_validate($input);

    if (!empty($errors)) {
        set_flash('error', implode('<br>', $errors));
        redirect('indicators/create');
    }

    try {
        $id = indicator_create(get_hospital_id(), $input['data'], $input['variables'], get_user_id());
        audit_log('indicator_created', "id=$id, name={$input['data']['name']}");
        set_flash('success', 'Indicador criado com sucesso!');
        redirect('indicators/view?id=' . $id);
    } catch (Exception $ex) {
        log_error('indicators_store', $ex);
        set_flash('error', _indicators_format_error('Erro ao criar indicador', $ex));
        redirect('indicators/create');
    }
}

function indicators_update($param = null) {
    require_login();
    if (!is_post()) redirect('indicators');
    csrf_validate();

    $id = sanitize_int(input('id'));
    $hospital_id = get_hospital_id();

    try {
        $existing = indicator_find($id, $hospital_id);
    } catch (Exception $ex) {
        log_error('indicators_update:find', $ex);
        $existing = null;
    }
    if (!$existing) {
        set_flash('error', 'Indicador não encontrado.');
        redirect('indicators');
    }

    $input  = _indicators_collect_form();
    $errors = _indicators_validate($input);

    if (!empty($errors)) {
        set_flash('error', implode('<br>', $errors));
        redirect('indicators/edit?id=' . $id);
    }

    try {
        indicator_update($id, $hospital_id, $input['data'], $input['variables']);
        audit_log('indicator_updated', "id=$id");
        set_flash('success', 'Indicador atualizado com sucesso!');
        redirect('indicators/view?id=' . $id);
    } catch (Exception $ex) {
        log_error('indicators_update', $ex);
        set_flash('error', _indicators_format_error('Erro ao atualizar indicador', $ex));
        redirect('indicators/edit?id=' . $id);
    }
}

function indicators_delete($param = null) {
    require_login();
    if (!is_post()) redirect('indicators');
    csrf_validate();
    $id = sanitize_int(input('id'));
    try {
        indicator_soft_delete($id, get_hospital_id());
        audit_log('indicator_deleted', "id=$id");
        set_flash('success', 'Indicador removido.');
    } catch (Exception $ex) {
        log_error('indicators_delete', $ex);
        set_flash('error', 'Erro ao remover indicador.');
    }
    redirect('indicators');
}

// ═══════════════════════════════════════════════════════════════════════════
//  Visualização + análise
// ═══════════════════════════════════════════════════════════════════════════

function indicators_view($param = null) {
    require_login();
    $id = sanitize_int($param ?: query('id'));
    $hospital_id = get_hospital_id();

    $indicator    = null;
    $variables    = [];
    $data_entries = [];
    $var_series   = [];
    $analysis     = null;

    try {
        $indicator = indicator_find($id, $hospital_id);
        if ($indicator) {
            $variables    = indicator_variables_list($id);
            $data_entries = indicator_data_list($id, 200);
            $series       = indicator_data_series($id);
            $var_series   = indicator_variable_series($id);

            $flat = [];
            foreach ($series as $s) {
                $flat[] = ['date' => $s['reference_date'], 'value' => (float) $s['value']];
            }
            $analysis = analysis_full(
                $flat,
                $indicator['goal_numeric'],
                $indicator['goal_direction'],
                $indicator['goal_tolerance'],
                $indicator['type']
            );
        }
    } catch (Exception $ex) {
        log_error('indicators_view', $ex);
    }

    if (!$indicator) {
        set_flash('error', 'Indicador não encontrado.');
        redirect('indicators');
    }

    view('indicators/view', [
        'page_title'   => $indicator['name'],
        'indicator'    => $indicator,
        'variables'    => $variables,
        'data_entries' => $data_entries,
        'var_series'   => $var_series,
        'analysis'     => $analysis,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Lançamento de dados
// ═══════════════════════════════════════════════════════════════════════════

function indicators_data($param = null) {
    require_login();
    $id = sanitize_int($param ?: query('id'));
    $hospital_id = get_hospital_id();
    $data_id = sanitize_int(query('data_id'));

    $indicator = null;
    $variables = [];
    $data = null;
    $data_values = [];

    try {
        $indicator = indicator_find($id, $hospital_id);
        if ($indicator) {
            $variables = indicator_variables_list($id);
            if ($data_id) {
                $data = indicator_data_find($data_id, $id);
                if ($data) $data_values = indicator_data_variables($data_id);
            }
        }
    } catch (Exception $ex) {
        log_error('indicators_data', $ex);
    }

    if (!$indicator) {
        set_flash('error', 'Indicador não encontrado.');
        redirect('indicators');
    }

    view('indicators/data_form', [
        'page_title'  => 'Lançar Dados — ' . $indicator['name'],
        'indicator'   => $indicator,
        'variables'   => $variables,
        'data'        => $data,
        'data_values' => $data_values,
    ]);
}

function indicators_store_data($param = null) {
    require_login();
    if (!is_post()) redirect('indicators');
    csrf_validate();

    $indicator_id   = sanitize_int(input('indicator_id'));
    $reference_date = (string) input('reference_date');
    $observations   = clean(input('observations'));
    $hospital_id    = get_hospital_id();
    $raw_value      = (string) input('value', '');

    try {
        $indicator = indicator_find($indicator_id, $hospital_id);
        $variables = $indicator ? indicator_variables_list($indicator_id) : [];
    } catch (Exception $ex) {
        log_error('indicators_store_data:find', $ex);
        $indicator = null; $variables = [];
    }
    if (!$indicator) {
        set_flash('error', 'Indicador não encontrado.');
        redirect('indicators');
    }

    $errors = [];
    if (empty($reference_date) || !strtotime($reference_date)) {
        $errors[] = 'Data de referência inválida.';
    }

    // Coleta valores de variáveis
    $var_values_in = $_POST['var_values'] ?? [];
    $var_values    = [];
    foreach ($variables as $v) {
        $raw = $var_values_in[$v['code']] ?? '';
        $raw = is_string($raw) ? str_replace(',', '.', trim($raw)) : $raw;
        if ($raw === '' || !is_numeric($raw)) {
            $errors[] = "Valor numérico obrigatório para: {$v['label']}.";
        } else {
            $var_values[$v['code']] = (float) $raw;
        }
    }

    // Calcula valor final
    $calc_value = null;
    if (!empty($indicator['formula']) && !empty($var_values)) {
        try {
            $calc_value = formula_evaluate($indicator['formula'], $var_values);
        } catch (Throwable $ex) {
            $errors[] = 'Erro na fórmula: ' . $ex->getMessage();
        }
    } elseif (empty($variables)) {
        // Modo legado: valor direto
        $raw_value = str_replace(',', '.', $raw_value);
        if ($raw_value === '' || !is_numeric($raw_value)) {
            $errors[] = 'Valor numérico é obrigatório.';
        } else {
            $calc_value = (float) $raw_value;
        }
    } else {
        // Tem variáveis mas sem fórmula: somatório padrão
        $calc_value = array_sum($var_values);
    }

    if (!empty($errors)) {
        set_flash('error', implode('<br>', $errors));
        redirect('indicators/data?id=' . $indicator_id);
    }

    try {
        $data_id = indicator_data_upsert(
            $indicator_id, $reference_date, $calc_value,
            $observations, get_user_id(), $var_values
        );
        audit_log('indicator_data_saved', "indicator=$indicator_id, data=$data_id, value=$calc_value");
        set_flash('success', 'Dados lançados com sucesso!');
    } catch (Exception $ex) {
        log_error('indicators_store_data', $ex);
        set_flash('error', 'Erro ao salvar dados.');
    }
    redirect('indicators/view?id=' . $indicator_id);
}

function indicators_delete_data($param = null) {
    require_login();
    if (!is_post()) redirect('indicators');
    csrf_validate();

    $data_id      = sanitize_int(input('data_id'));
    $indicator_id = sanitize_int(input('indicator_id'));
    $hospital_id  = get_hospital_id();

    try {
        $indicator = indicator_find($indicator_id, $hospital_id);
        if (!$indicator) throw new RuntimeException('Indicador não encontrado.');
        indicator_data_soft_delete($data_id, $indicator_id);
        audit_log('indicator_data_deleted', "indicator=$indicator_id, data=$data_id");
        set_flash('success', 'Lançamento removido.');
    } catch (Exception $ex) {
        log_error('indicators_delete_data', $ex);
        set_flash('error', 'Erro ao remover lançamento.');
    }
    redirect('indicators/view?id=' . $indicator_id);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Exportação e análise via API
// ═══════════════════════════════════════════════════════════════════════════

function indicators_export($param = null) {
    require_login();
    $id = sanitize_int($param ?: query('id'));
    $hospital_id = get_hospital_id();
    $format = (string) query('format', 'json');

    try {
        $indicator = indicator_find($id, $hospital_id);
        $variables = $indicator ? indicator_variables_list($id) : [];
        $data_entries = $indicator ? indicator_data_list($id, 10000) : [];
    } catch (Exception $ex) {
        log_error('indicators_export', $ex);
        json_response(['error' => 'Erro interno'], 500);
    }

    if (!$indicator) json_response(['error' => 'Indicador não encontrado'], 404);

    if ($format === 'csv') {
        $headers = ['Data', 'Valor Calculado'];
        foreach ($variables as $v) $headers[] = $v['label'] . ($v['unit'] ? ' (' . $v['unit'] . ')' : '');
        $headers[] = 'Observações';

        $rows = [];
        foreach ($data_entries as $d) {
            $line = [format_date($d['reference_date']), format_number($d['value'], $indicator['decimal_places'])];
            foreach ($variables as $v) {
                $line[] = isset($d['variables'][$v['code']])
                    ? format_number($d['variables'][$v['code']]['value'], 4)
                    : '';
            }
            $line[] = $d['observations'];
            $rows[] = $line;
        }
        audit_log('indicator_exported_csv', "id=$id");
        csv_response('indicador_' . $id . '_' . date('Ymd_His') . '.csv', $headers, $rows);
    }

    // Formato JSON: retorna indicador + série + análise
    $series_flat = [];
    foreach (indicator_data_series($id) as $s) {
        $series_flat[] = ['date' => $s['reference_date'], 'value' => (float) $s['value']];
    }
    $analysis = analysis_full(
        $series_flat,
        $indicator['goal_numeric'],
        $indicator['goal_direction'],
        $indicator['goal_tolerance'],
        $indicator['type']
    );

    json_response([
        'indicator' => $indicator,
        'variables' => $variables,
        'data'      => $data_entries,
        'analysis'  => $analysis,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Helpers internos
// ═══════════════════════════════════════════════════════════════════════════

function _indicators_collect_form() {
    $data = [
        'name'           => clean(input('name')),
        'type'           => (string) input('type'),
        'unit'           => clean(input('unit')),
        'goal'           => clean(input('goal')),
        'goal_numeric'   => null,
        'goal_direction' => (string) input('goal_direction', 'higher_better'),
        'goal_tolerance' => 0.0,
        'formula'        => clean(input('formula')),
        'chart_type'     => (string) input('chart_type', 'line'),
        'category'       => clean(input('category')),
        'decimal_places' => max(0, min(6, sanitize_int(input('decimal_places', 2)))),
        'description'    => clean(input('description')),
        'variables_legacy' => clean(input('variables_legacy')),
        'benchmark_value'     => null,
        'benchmark_source'    => clean(input('benchmark_source')),
        'responsible_user_id' => sanitize_int(input('responsible_user_id')) ?: null,
        'accreditation'       => clean(input('accreditation')),
        'template_slug'       => clean(input('template_slug')) ?: null,
    ];

    $bench_raw = str_replace(',', '.', (string) input('benchmark_value', ''));
    if ($bench_raw !== '' && is_numeric($bench_raw)) $data['benchmark_value'] = (float) $bench_raw;

    $goal_raw = str_replace(',', '.', $data['goal']);
    if (is_numeric($goal_raw)) $data['goal_numeric'] = (float) $goal_raw;

    $tol_raw = str_replace(',', '.', (string) input('goal_tolerance', '0'));
    if (is_numeric($tol_raw)) $data['goal_tolerance'] = (float) $tol_raw;

    // Variáveis dinâmicas
    $codes  = $_POST['var_code']  ?? [];
    $labels = $_POST['var_label'] ?? [];
    $units  = $_POST['var_unit']  ?? [];
    $variables = [];
    foreach ($codes as $i => $code) {
        $c = clean($code);
        $l = clean($labels[$i] ?? '');
        $u = clean($units[$i] ?? '');
        if ($c === '' && $l === '') continue;
        $variables[] = ['code' => $c, 'label' => $l, 'unit' => $u];
    }

    return ['data' => $data, 'variables' => $variables];
}

function _indicators_validate(array $input) {
    $errors = [];
    $d = $input['data'];
    $vars = $input['variables'];

    if ($d['name'] === '') $errors[] = 'Nome do indicador é obrigatório.';
    if (!in_array($d['type'], ['daily', 'monthly', 'yearly'], true)) {
        $errors[] = 'Periodicidade inválida.';
    }
    if (!in_array($d['goal_direction'], ['higher_better', 'lower_better', 'target'], true)) {
        $errors[] = 'Direção da meta inválida.';
    }
    if (!in_array($d['chart_type'], ['line', 'bar', 'area'], true)) {
        $errors[] = 'Tipo de gráfico inválido.';
    }

    $codes_seen = [];
    foreach ($vars as $i => $v) {
        if ($v['code'] === '' || $v['label'] === '') {
            $errors[] = "Variável #" . ($i + 1) . ": código e rótulo são obrigatórios.";
            continue;
        }
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $v['code'])) {
            $errors[] = "Código '{$v['code']}' inválido (use letras, começando com letra).";
        }
        if (isset($codes_seen[$v['code']])) {
            $errors[] = "Código de variável duplicado: '{$v['code']}'.";
        }
        $codes_seen[$v['code']] = true;
    }

    // Valida fórmula
    if ($d['formula'] !== '') {
        if (empty($vars)) {
            $errors[] = 'Defina ao menos uma variável para usar a fórmula.';
        } else {
            $err = formula_validate($d['formula'], array_keys($codes_seen));
            if ($err) $errors[] = 'Fórmula inválida: ' . $err;
        }
    }

    return $errors;
}

/**
 * Monta mensagem de erro amigável, incluindo detalhe técnico para admins
 * ou quando APP_DEBUG está ligado.
 */
function _indicators_format_error($prefix, Throwable $ex) {
    $msg = $ex->getMessage();

    // Tenta detectar causa conhecida
    if (stripos($msg, 'unknown column') !== false
        || stripos($msg, 'doesn\'t exist') !== false
        || stripos($msg, 'table or view') !== false) {
        return $prefix . ': <strong>o schema do banco está desatualizado</strong>. '
             . 'Aplique o schema consolidado <code>sql/modules/documentos.sql</code>. '
             . '(Detalhe: ' . e(substr($msg, 0, 200)) . ')';
    }

    // Para admins ou em debug, mostra mensagem técnica
    if (APP_DEBUG || is_admin()) {
        return $prefix . '. Detalhe: ' . e(substr($msg, 0, 300));
    }
    return $prefix . '. Verifique os logs em logs/app_errors.log.';
}

// ═══════════════════════════════════════════════════════════════════════════
//  PDCA — Planos de Ação
// ═══════════════════════════════════════════════════════════════════════════

function indicators_actions($param = null) {
    require_login();
    $indicator_id = sanitize_int(query('indicator_id'));
    $hospital_id  = get_hospital_id();
    $indicator = null; $actions = [];

    try {
        if ($indicator_id) {
            $indicator = indicator_find($indicator_id, $hospital_id);
        }
        $actions = _action_list($indicator_id, $hospital_id);
    } catch (Exception $ex) { log_error('indicators_actions', $ex); }

    view('indicators/actions', [
        'page_title' => 'Planos de Ação',
        'indicator'  => $indicator,
        'actions'    => $actions,
    ]);
}

function indicators_action_store($param = null) {
    require_login();
    if (!is_post()) redirect('indicators/actions');
    csrf_validate();

    $indicator_id = sanitize_int(input('indicator_id'));
    $data = [
        'title'       => clean(input('title')),
        'description' => clean(input('description')),
        'action_type' => (string) input('action_type', 'corrective'),
        'root_cause'  => clean(input('root_cause')),
        'responsible' => clean(input('responsible')),
        'due_date'    => (string) input('due_date') ?: null,
    ];

    if (empty($data['title'])) {
        set_flash('error', 'Título da ação é obrigatório.');
        redirect('indicators/actions?indicator_id=' . $indicator_id);
    }

    try {
        _action_create($indicator_id, $data, get_user_id());
        audit_log('action_created', "indicator=$indicator_id");
        set_flash('success', 'Ação registrada com sucesso.');
    } catch (Exception $ex) {
        log_error('indicators_action_store', $ex);
        set_flash('error', 'Erro ao registrar ação.');
    }
    redirect('indicators/actions?indicator_id=' . $indicator_id);
}

function indicators_action_update_status($param = null) {
    require_login();
    if (!is_post()) redirect('indicators/actions');
    csrf_validate();

    $action_id    = sanitize_int(input('action_id'));
    $indicator_id = sanitize_int(input('indicator_id'));
    $new_status   = (string) input('new_status');
    $verification = clean(input('verification'));

    $valid = ['pending','in_progress','done','cancelled'];
    if (!in_array($new_status, $valid, true)) {
        set_flash('error', 'Status inválido.');
        redirect('indicators/actions?indicator_id=' . $indicator_id);
    }

    try {
        _action_set_status($action_id, $new_status, $verification);
        audit_log('action_status_changed', "action=$action_id, status=$new_status");
        set_flash('success', 'Status atualizado.');
    } catch (Exception $ex) {
        log_error('indicators_action_update_status', $ex);
        set_flash('error', 'Erro ao atualizar status.');
    }
    redirect('indicators/actions?indicator_id=' . $indicator_id);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Importação CSV em lote
// ═══════════════════════════════════════════════════════════════════════════

function indicators_import($param = null) {
    require_login();
    $id = sanitize_int(query('id'));
    $hospital_id = get_hospital_id();

    $indicator = null;
    $variables = [];
    try {
        $indicator = indicator_find($id, $hospital_id);
        if ($indicator) $variables = indicator_variables_list($id);
    } catch (Exception $ex) { log_error('indicators_import', $ex); }

    if (!$indicator) {
        set_flash('error', 'Indicador não encontrado.');
        redirect('indicators');
    }

    $result = null;
    if (is_post()) {
        csrf_validate();
        $result = _import_csv($indicator, $variables);
    }

    view('indicators/import', [
        'page_title' => 'Importar Dados — ' . $indicator['name'],
        'indicator'  => $indicator,
        'variables'  => $variables,
        'result'     => $result,
    ]);
}

// ─── Helpers PDCA ───────────────────────────────────────────────────────────

function _action_list($indicator_id = null, $hospital_id = null) {
    if (!db_has_table('doc_indicator_actions')) return [];
    $sql = "SELECT a.*, i.name AS indicator_name FROM doc_indicator_actions a
            JOIN doc_indicators i ON i.id = a.indicator_id
            WHERE a.deleted_at IS NULL";
    $params = [];
    if ($indicator_id) { $sql .= " AND a.indicator_id = ?"; $params[] = $indicator_id; }
    if ($hospital_id)  { $sql .= " AND i.hospital_id = ?";  $params[] = $hospital_id; }
    $sql .= " ORDER BY FIELD(a.status,'pending','in_progress','done','cancelled'), a.due_date ASC";
    return db_query($sql, $params);
}

function _action_create($indicator_id, $data, $user_id) {
    if (!db_has_table('doc_indicator_actions')) return 0;
    $has_type = db_has_column('doc_indicator_actions', 'action_type');
    $has_root = db_has_column('doc_indicator_actions', 'root_cause');

    $cols = ['indicator_id','title','description','responsible','due_date','status','created_by'];
    $vals = [$indicator_id, $data['title'], $data['description'],
             $data['responsible'], $data['due_date'], 'pending', $user_id];
    if ($has_type) { $cols[] = 'action_type'; $vals[] = $data['action_type']; }
    if ($has_root) { $cols[] = 'root_cause';  $vals[] = $data['root_cause']; }

    $ph = implode(',', array_fill(0, count($cols), '?'));
    db_execute("INSERT INTO doc_indicator_actions (" . implode(',', $cols) . ") VALUES ($ph)", $vals);
    return (int) db_last_id();
}

function _action_set_status($action_id, $status, $verification = '') {
    if (!db_has_table('doc_indicator_actions')) return 0;
    $sets = ['status = ?', 'updated_at = NOW()'];
    $params = [$status];
    if ($status === 'done') {
        $sets[] = 'completed_at = NOW()';
        if (db_has_column('doc_indicator_actions', 'verification') && $verification !== '') {
            $sets[] = 'verification = ?';
            $sets[] = 'verified_at = NOW()';
            $params[] = $verification;
        }
    }
    $params[] = $action_id;
    return db_execute("UPDATE doc_indicator_actions SET " . implode(', ', $sets) . " WHERE id = ?", $params);
}

// ─── Helpers importação CSV ─────────────────────────────────────────────────

function _import_csv($indicator, $variables) {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Selecione um arquivo CSV.'];
    }

    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, 'r');
    if (!$handle) return ['ok' => false, 'error' => 'Erro ao abrir arquivo.'];

    $header = fgetcsv($handle, 0, ';');
    if (!$header) { fclose($handle); return ['ok' => false, 'error' => 'Arquivo vazio ou formato inválido.']; }

    $header = array_map(function($h) { return trim(mb_strtolower($h)); }, $header);

    $date_col = array_search('data', $header);
    if ($date_col === false) $date_col = array_search('date', $header);
    if ($date_col === false) $date_col = array_search('data_referencia', $header);
    if ($date_col === false) { fclose($handle); return ['ok' => false, 'error' => 'Coluna "data" não encontrada no cabeçalho.']; }

    $var_map = [];
    foreach ($variables as $v) {
        $idx = array_search(mb_strtolower($v['code']), $header);
        if ($idx === false) $idx = array_search(mb_strtolower($v['label']), $header);
        if ($idx !== false) $var_map[$v['code']] = $idx;
    }

    $value_col = array_search('valor', $header);
    if ($value_col === false) $value_col = array_search('value', $header);

    $obs_col = array_search('observacoes', $header);
    if ($obs_col === false) $obs_col = array_search('observations', $header);

    $imported = 0; $errors = []; $line = 1;
    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        $line++;
        $ref_date = trim($row[$date_col] ?? '');

        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $ref_date, $m)) {
            $ref_date = "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (!$ref_date || !strtotime($ref_date)) {
            $errors[] = "Linha $line: data inválida.";
            continue;
        }

        $var_values = [];
        foreach ($var_map as $code => $idx) {
            $raw = str_replace(',', '.', trim($row[$idx] ?? ''));
            if (!is_numeric($raw)) { $errors[] = "Linha $line: valor inválido para '$code'."; continue 2; }
            $var_values[$code] = (float) $raw;
        }

        $calc_value = null;
        if (!empty($indicator['formula']) && !empty($var_values)) {
            try { $calc_value = formula_evaluate($indicator['formula'], $var_values); }
            catch (Throwable $ex) { $errors[] = "Linha $line: erro na fórmula."; continue; }
        } elseif ($value_col !== false) {
            $raw = str_replace(',', '.', trim($row[$value_col] ?? ''));
            $calc_value = is_numeric($raw) ? (float) $raw : null;
        } else {
            $calc_value = array_sum($var_values);
        }

        if ($calc_value === null) { $errors[] = "Linha $line: valor não calculado."; continue; }

        $obs = $obs_col !== false ? trim($row[$obs_col] ?? '') : '';

        try {
            indicator_data_upsert($indicator['id'], $ref_date, $calc_value, $obs, get_user_id(), $var_values);
            $imported++;
        } catch (Exception $ex) {
            $errors[] = "Linha $line: " . substr($ex->getMessage(), 0, 100);
        }
    }
    fclose($handle);

    audit_log('indicator_imported', "indicator={$indicator['id']}, imported=$imported, errors=" . count($errors));

    return [
        'ok'       => true,
        'imported' => $imported,
        'errors'   => $errors,
        'total'    => $line - 1,
    ];
}
