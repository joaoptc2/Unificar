<?php
/**
 * Model: Indicadores de Enfermagem (v2 — com variáveis, fórmula e análise)
 *
 * As funções são TOLERANTES a schema antigo: se as colunas/tabelas da
 * migration 003 não existirem, retornam defaults seguros em vez de lançar.
 */

/**
 * Preenche um registro de indicador com valores-padrão para campos
 * introduzidos na migration 003. Evita "undefined array key" nas views
 * quando a migration ainda não foi aplicada.
 */
function indicator_hydrate($row) {
    if (!is_array($row)) return $row;
    $defaults = [
        'formula'             => null,
        'goal_numeric'        => null,
        'goal_direction'      => 'higher_better',
        'goal_tolerance'      => 0,
        'chart_type'          => 'line',
        'category'            => null,
        'decimal_places'      => 2,
        'variables'           => null,
        'benchmark_value'     => null,
        'benchmark_source'    => null,
        'responsible_user_id' => null,
        'accreditation'       => null,
        'template_slug'       => null,
        'sector_id'           => null,
    ];
    foreach ($defaults as $k => $v) {
        if (!array_key_exists($k, $row)) $row[$k] = $v;
    }
    return $row;
}

/**
 * Filtros das listagens de indicadores.
 * @param array $f type, search, category, sector_id (0 = todos), accreditation,
 *                 responsible_user_id
 */
function _indicator_filter_where(array $f) {
    $sql    = " WHERE i.hospital_id = ? AND i.deleted_at IS NULL";
    $params = [(int) ($f['hospital_id'] ?? 0)];
    if (!empty($f['type']))      { $sql .= " AND i.type = ?";     $params[] = $f['type']; }
    if (!empty($f['category']))  { $sql .= " AND i.category = ?"; $params[] = $f['category']; }
    if ((int) ($f['sector_id'] ?? 0) > 0) { $sql .= " AND i.sector_id = ?"; $params[] = (int) $f['sector_id']; }
    if (!empty($f['accreditation'])) { $sql .= " AND i.accreditation LIKE ?"; $params[] = '%' . $f['accreditation'] . '%'; }
    if ((int) ($f['responsible_user_id'] ?? 0) > 0) { $sql .= " AND i.responsible_user_id = ?"; $params[] = (int) $f['responsible_user_id']; }
    if (!empty($f['search'])) {
        $sql .= " AND (i.name LIKE ? OR i.description LIKE ?)";
        $params[] = "%{$f['search']}%"; $params[] = "%{$f['search']}%";
    }
    return [$sql, $params];
}

function indicator_count($hospital_id, $type = '', $search = '', $category = '', $sector_id = 0, array $extra = []) {
    [$where, $params] = _indicator_filter_where(array_merge($extra, [
        'hospital_id' => $hospital_id, 'type' => $type, 'search' => $search,
        'category' => $category, 'sector_id' => $sector_id,
    ]));
    $row = db_query_one("SELECT COUNT(*) AS total FROM doc_indicators i" . $where, $params);
    return (int) ($row['total'] ?? 0);
}

function indicator_list($hospital_id, $type = '', $search = '', $category = '', $limit = 20, $offset = 0, $sector_id = 0, array $extra = []) {
    [$where, $params] = _indicator_filter_where(array_merge($extra, [
        'hospital_id' => $hospital_id, 'type' => $type, 'search' => $search,
        'category' => $category, 'sector_id' => $sector_id,
    ]));
    $params[] = (int) $limit;
    $params[] = (int) $offset;
    $rows = db_query(
        "SELECT i.*, s.name AS sector_name, ru.name AS responsible_name
         FROM doc_indicators i
         LEFT JOIN doc_sectors s ON s.id = i.sector_id
         LEFT JOIN users ru ON ru.id = i.responsible_user_id" . $where . "
         ORDER BY i.name ASC LIMIT ? OFFSET ?",
        $params
    );
    return array_map('indicator_hydrate', $rows);
}

/** Acreditações distintas (para o filtro da página unificada). */
function indicator_distinct_accreditations($hospital_id) {
    try {
        $rows = db_query(
            "SELECT DISTINCT accreditation FROM doc_indicators
             WHERE hospital_id = ? AND deleted_at IS NULL AND accreditation IS NOT NULL AND accreditation <> ''",
            [(int) $hospital_id]
        );
        $out = [];
        foreach ($rows as $r) {
            foreach (explode(',', (string) $r['accreditation']) as $a) {
                $a = trim($a);
                if ($a !== '') $out[$a] = true;
            }
        }
        ksort($out);
        return array_keys($out);
    } catch (Exception $ex) { return []; }
}

function indicator_find($id, $hospital_id) {
    $row = db_query_one(
        "SELECT i.*, u.name AS created_by_name, s.name AS sector_name, ru.name AS responsible_name
         FROM doc_indicators i
         LEFT JOIN users u ON u.id = i.created_by
         LEFT JOIN doc_sectors s ON s.id = i.sector_id
         LEFT JOIN users ru ON ru.id = i.responsible_user_id
         WHERE i.id = ? AND i.hospital_id = ? AND i.deleted_at IS NULL",
        [(int) $id, (int) $hospital_id]
    );
    return indicator_hydrate($row);
}

function indicator_distinct_categories($hospital_id) {
    if (!db_has_column('doc_indicators', 'category')) return [];
    try {
        return db_query(
            "SELECT DISTINCT category FROM doc_indicators
             WHERE hospital_id = ? AND deleted_at IS NULL AND category IS NOT NULL AND category <> ''
             ORDER BY category",
            [(int) $hospital_id]
        );
    } catch (Exception $ex) {
        return [];
    }
}

/**
 * Lista de colunas que existem em `indicators` no schema atual.
 * Usado para montar INSERT/UPDATE dinâmicos, tolerando schema antigo.
 */
function _indicator_available_columns() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $all = ['formula', 'goal_numeric', 'goal_direction', 'goal_tolerance',
            'chart_type', 'category', 'decimal_places',
            'benchmark_value', 'benchmark_source', 'responsible_user_id',
            'accreditation', 'template_slug', 'sector_id'];
    $cache = [];
    foreach ($all as $c) {
        if (db_has_column('doc_indicators', $c)) $cache[] = $c;
    }
    return $cache;
}

/**
 * Cria um indicador + suas variáveis, em transação.
 */
function indicator_create($hospital_id, array $data, array $variables, $created_by) {
    return db_transaction(function() use ($hospital_id, $data, $variables, $created_by) {
        // Colunas base (sempre existem)
        $cols  = ['hospital_id', 'name', 'type', 'unit', 'goal', 'variables', 'description', 'created_by'];
        $vals  = [$hospital_id, $data['name'], $data['type'], $data['unit'],
                  $data['goal'], $data['variables_legacy'] ?? null,
                  $data['description'], $created_by];

        // Colunas da migration 003 se disponíveis
        foreach (_indicator_available_columns() as $c) {
            $cols[] = $c;
            $vals[] = $data[$c] ?? null;
        }

        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $col_list     = '`' . implode('`, `', $cols) . '`';

        db_execute(
            "INSERT INTO doc_indicators ($col_list, created_at, updated_at)
             VALUES ($placeholders, NOW(), NOW())",
            $vals
        );
        $indicator_id = (int) db_last_id();

        if (db_has_table('doc_indicator_variables')) {
            _indicator_sync_variables($indicator_id, $variables);
        }
        return $indicator_id;
    });
}

function indicator_update($id, $hospital_id, array $data, array $variables) {
    db_transaction(function() use ($id, $hospital_id, $data, $variables) {
        $sets   = ['name = ?', 'type = ?', 'unit = ?', 'goal = ?',
                   'variables = ?', 'description = ?'];
        $params = [$data['name'], $data['type'], $data['unit'], $data['goal'],
                   $data['variables_legacy'] ?? null, $data['description']];

        foreach (_indicator_available_columns() as $c) {
            $sets[]   = "$c = ?";
            $params[] = $data[$c] ?? null;
        }

        $sets[]   = 'updated_at = NOW()';
        $params[] = $id;
        $params[] = $hospital_id;

        db_execute(
            "UPDATE doc_indicators SET " . implode(', ', $sets) . "
             WHERE id = ? AND hospital_id = ?",
            $params
        );

        if (db_has_table('doc_indicator_variables')) {
            _indicator_sync_variables($id, $variables);
        }
    });
}

/**
 * Reconstrói a lista de variáveis do indicador (usado no create/update).
 * Estratégia: remove variáveis que não constam mais; atualiza/insere o resto.
 * NOTA: se uma variável é removida, seus valores (indicator_data_values) são
 * apagados em cascata pela FK — avise o usuário na UI.
 */
function _indicator_sync_variables($indicator_id, array $variables) {
    $existing = db_query(
        "SELECT id, code FROM doc_indicator_variables WHERE indicator_id = ?",
        [$indicator_id]
    );
    $by_code = [];
    foreach ($existing as $e) $by_code[$e['code']] = $e['id'];

    $kept_codes = [];
    foreach ($variables as $order => $v) {
        $code  = $v['code'];
        $label = $v['label'];
        $unit  = $v['unit'] ?? null;
        if ($code === '' || $label === '') continue;
        $kept_codes[$code] = true;

        if (isset($by_code[$code])) {
            db_execute(
                "UPDATE doc_indicator_variables SET label = ?, unit = ?, display_order = ? WHERE id = ?",
                [$label, $unit, $order, $by_code[$code]]
            );
        } else {
            db_execute(
                "INSERT INTO doc_indicator_variables (indicator_id, code, label, unit, display_order)
                 VALUES (?, ?, ?, ?, ?)",
                [$indicator_id, $code, $label, $unit, $order]
            );
        }
    }

    // Remove as que não existem mais
    foreach ($by_code as $code => $var_id) {
        if (!isset($kept_codes[$code])) {
            db_execute("DELETE FROM doc_indicator_variables WHERE id = ?", [$var_id]);
        }
    }
}

function indicator_soft_delete($id, $hospital_id) {
    return db_execute(
        "UPDATE doc_indicators SET deleted_at = NOW() WHERE id = ? AND hospital_id = ? AND deleted_at IS NULL",
        [$id, $hospital_id]
    );
}

// ─── Variáveis ──────────────────────────────────────────────────────────────

function indicator_variables_list($indicator_id) {
    if (!db_has_table('doc_indicator_variables')) return [];
    try {
        return db_query(
            "SELECT * FROM doc_indicator_variables WHERE indicator_id = ?
             ORDER BY display_order, id",
            [(int) $indicator_id]
        );
    } catch (Exception $ex) {
        return [];
    }
}

// ─── Dados ──────────────────────────────────────────────────────────────────

function indicator_data_find($data_id, $indicator_id) {
    return db_query_one(
        "SELECT * FROM doc_indicator_data
         WHERE id = ? AND indicator_id = ? AND deleted_at IS NULL",
        [(int) $data_id, (int) $indicator_id]
    );
}

/**
 * Lista recente dos lançamentos (com valores de variáveis embutidos).
 */
function indicator_data_list($indicator_id, $limit = 100) {
    $rows = db_query(
        "SELECT id.*, u.name AS recorded_by_name
         FROM doc_indicator_data id
         LEFT JOIN users u ON u.id = id.recorded_by
         WHERE id.indicator_id = ? AND id.deleted_at IS NULL
         ORDER BY id.reference_date DESC
         LIMIT ?",
        [(int) $indicator_id, (int) $limit]
    );
    if (empty($rows)) return $rows;

    // Anexa valores das variáveis somente se a tabela existir
    if (db_has_table('doc_indicator_data_values') && db_has_table('doc_indicator_variables')) {
        try {
            $ids = array_map(fn($r) => $r['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $vvalues = db_query(
                "SELECT dv.data_id, dv.variable_id, dv.value, v.code, v.label
                 FROM doc_indicator_data_values dv
                 JOIN doc_indicator_variables v ON v.id = dv.variable_id
                 WHERE dv.data_id IN ($placeholders)",
                $ids
            );
            $map = [];
            foreach ($vvalues as $vv) {
                $map[$vv['data_id']][$vv['code']] = $vv;
            }
            foreach ($rows as &$r) {
                $r['variables'] = $map[$r['id']] ?? [];
            }
            return $rows;
        } catch (Exception $ex) {}
    }

    // Schema antigo: sem variáveis
    foreach ($rows as &$r) $r['variables'] = [];
    return $rows;
}

/**
 * Série completa ordenada para gráfico/análise.
 */
function indicator_data_series($indicator_id) {
    return db_query(
        "SELECT id, reference_date, value, observations
         FROM doc_indicator_data
         WHERE indicator_id = ? AND deleted_at IS NULL
         ORDER BY reference_date ASC",
        [(int) $indicator_id]
    );
}

/**
 * Séries separadas de cada variável para gráficos individuais.
 * Retorna: ['a' => [['date' => ..., 'value' => ...]], 'b' => [...]]
 */
function indicator_variable_series($indicator_id) {
    if (!db_has_table('doc_indicator_data_values') || !db_has_table('doc_indicator_variables')) return [];
    try {
        $rows = db_query(
            "SELECT d.reference_date, v.code, dv.value
             FROM doc_indicator_data d
             JOIN doc_indicator_data_values dv ON dv.data_id = d.id
             JOIN doc_indicator_variables v    ON v.id = dv.variable_id
             WHERE d.indicator_id = ? AND d.deleted_at IS NULL
             ORDER BY d.reference_date ASC, v.display_order ASC",
            [(int) $indicator_id]
        );
        $series = [];
        foreach ($rows as $r) {
            $series[$r['code']][] = ['date' => $r['reference_date'], 'value' => (float) $r['value']];
        }
        return $series;
    } catch (Exception $ex) {
        return [];
    }
}

/**
 * Insere/atualiza um lançamento + valores das variáveis em transação.
 *
 * @param array $var_values ['a' => 5.0, 'b' => 20.0]  (chave = code)
 */
function indicator_data_upsert($indicator_id, $reference_date, $calc_value,
                                $observations, $recorded_by, array $var_values = []) {
    return db_transaction(function() use ($indicator_id, $reference_date, $calc_value,
                                          $observations, $recorded_by, $var_values) {
        $existing = db_query_one(
            "SELECT id FROM doc_indicator_data
             WHERE indicator_id = ? AND reference_date = ? AND deleted_at IS NULL",
            [$indicator_id, $reference_date]
        );
        if ($existing) {
            $data_id = (int) $existing['id'];
            db_execute(
                "UPDATE doc_indicator_data SET value = ?, observations = ?, recorded_by = ?
                 WHERE id = ?",
                [$calc_value, $observations, $recorded_by, $data_id]
            );
            db_execute("DELETE FROM doc_indicator_data_values WHERE data_id = ?", [$data_id]);
        } else {
            db_execute(
                "INSERT INTO doc_indicator_data
                    (indicator_id, reference_date, value, observations, recorded_by, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [$indicator_id, $reference_date, $calc_value, $observations, $recorded_by]
            );
            $data_id = (int) db_last_id();
        }

        if (!empty($var_values)) {
            // Busca mapa code → id das variáveis
            $vars = db_query(
                "SELECT id, code FROM doc_indicator_variables WHERE indicator_id = ?",
                [$indicator_id]
            );
            $code_to_id = [];
            foreach ($vars as $v) $code_to_id[$v['code']] = $v['id'];

            foreach ($var_values as $code => $value) {
                if (!isset($code_to_id[$code])) continue;
                db_execute(
                    "INSERT INTO doc_indicator_data_values (data_id, variable_id, value)
                     VALUES (?, ?, ?)",
                    [$data_id, $code_to_id[$code], (float) $value]
                );
            }
        }

        return $data_id;
    });
}

function indicator_data_soft_delete($data_id, $indicator_id) {
    return db_execute(
        "UPDATE doc_indicator_data SET deleted_at = NOW()
         WHERE id = ? AND indicator_id = ?",
        [$data_id, $indicator_id]
    );
}

/**
 * Recupera valores das variáveis de um lançamento específico.
 * Retorna array code => value.
 */
function indicator_data_variables($data_id) {
    if (!db_has_table('doc_indicator_data_values') || !db_has_table('doc_indicator_variables')) return [];
    try {
        $rows = db_query(
            "SELECT v.code, dv.value
             FROM doc_indicator_data_values dv
             JOIN doc_indicator_variables v ON v.id = dv.variable_id
             WHERE dv.data_id = ?",
            [(int) $data_id]
        );
        $out = [];
        foreach ($rows as $r) $out[$r['code']] = (float) $r['value'];
        return $out;
    } catch (Exception $ex) {
        return [];
    }
}
