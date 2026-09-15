<?php
/**
 * Model: Setores (doc_sectors) e o VÍNCULO usuário ↔ setor (doc_user_sectors).
 *
 * Regra de visibilidade (setores independentes):
 *
 *   • Cada usuário enxerga apenas os setores em que está incluído
 *     (doc_user_sectors). Isso vale para documentos, indicadores, planos de
 *     ação, dashboard e relatórios — não é só um filtro de tela: entra no
 *     WHERE de toda consulta que tenha setor.
 *
 *   • Quem tem a micropermissão `sectors.view_all` (ou é administrador da
 *     plataforma) enxerga todos os setores. É o perfil de quem administra a
 *     qualidade do hospital inteiro.
 *
 *   • Documento/indicador SEM setor (sector_id NULL) é institucional e
 *     aparece para todo mundo. Sem essa regra, uma instalação existente —
 *     onde o setor nunca foi preenchido — ficaria vazia no dia da
 *     atualização.
 *
 * O seletor global continua existindo, mas agora só lista os setores do
 * próprio usuário: ele ESTREITA a visão, nunca a amplia.
 */

// ═══════════════════════════════════════════════════════════════════════════
//  CRUD de setores
// ═══════════════════════════════════════════════════════════════════════════

function sector_list($hospital_id) {
    try {
        return db_query(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM doc_documents d WHERE d.sector_id = s.id AND d.deleted_at IS NULL) AS document_count
             FROM doc_sectors s
             WHERE s.hospital_id = ? AND s.deleted_at IS NULL AND s.is_active = 1
             ORDER BY s.name",
            [(int) $hospital_id]
        );
    } catch (Exception $ex) { return []; }
}

function sector_list_all($hospital_id) {
    try {
        return db_query(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM doc_documents d WHERE d.sector_id = s.id AND d.deleted_at IS NULL) AS document_count,
                    (SELECT COUNT(*) FROM doc_indicators i WHERE i.sector_id = s.id AND i.deleted_at IS NULL) AS indicator_count,
                    (SELECT COUNT(*) FROM doc_user_sectors us WHERE us.sector_id = s.id) AS user_count
             FROM doc_sectors s
             WHERE s.hospital_id = ? AND s.deleted_at IS NULL
             ORDER BY s.name",
            [(int) $hospital_id]
        );
    } catch (Exception $ex) { return []; }
}

function sector_find($id) {
    return db_query_one("SELECT * FROM doc_sectors WHERE id = ? AND deleted_at IS NULL", [(int) $id]);
}

/** Setor ativo da unidade (para validar o contexto/os formulários). */
function sector_find_active($id, $hospital_id) {
    return db_query_one(
        "SELECT * FROM doc_sectors WHERE id = ? AND hospital_id = ? AND deleted_at IS NULL AND is_active = 1",
        [(int) $id, (int) $hospital_id]
    );
}

function sector_create($hospital_id, $name, $code = null, $description = null) {
    db_execute(
        "INSERT INTO doc_sectors (hospital_id, name, code, description, is_active) VALUES (?, ?, ?, ?, 1)",
        [(int) $hospital_id, $name, $code, $description]
    );
    doc_sector_scope_reset();
    return (int) db_last_id();
}

function sector_update($id, $name, $code = null, $description = null, $is_active = 1) {
    doc_sector_scope_reset();
    return db_execute(
        "UPDATE doc_sectors SET name = ?, code = ?, description = ?, is_active = ?, updated_at = NOW() WHERE id = ?",
        [$name, $code, $description, (int) $is_active, (int) $id]
    );
}

function sector_soft_delete($id) {
    doc_sector_scope_reset();
    // O vínculo some junto: manter usuários apontando para um setor removido
    // só produziria um escopo com um id que nunca mais casa.
    try {
        db_execute("DELETE FROM doc_user_sectors WHERE sector_id = ?", [(int) $id]);
    } catch (Exception $ex) { /* tabela ausente em base legada */ }
    return db_execute("UPDATE doc_sectors SET deleted_at = NOW() WHERE id = ?", [(int) $id]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  Vínculo usuário ↔ setor
// ═══════════════════════════════════════════════════════════════════════════

/** Usuários incluídos no setor (para a tela de configuração). */
function sector_users($sector_id) {
    try {
        return db_query(
            "SELECT u.id, u.name, u.email, u.username, u.active, us.created_at AS linked_at
             FROM doc_user_sectors us
             JOIN users u ON u.id = us.user_id
             WHERE us.sector_id = ?
             ORDER BY u.name",
            [(int) $sector_id]
        );
    } catch (Exception $ex) { return []; }
}

/** Ids dos usuários incluídos no setor. */
function sector_user_ids($sector_id) {
    try {
        $rows = db_query("SELECT user_id FROM doc_user_sectors WHERE sector_id = ?", [(int) $sector_id]);
        return array_map('intval', array_column($rows, 'user_id'));
    } catch (Exception $ex) { return []; }
}

/**
 * Define a lista COMPLETA de usuários do setor (inclui os que faltam, remove
 * os que saíram). Devolve ['added' => n, 'removed' => n].
 */
function sector_set_users($sector_id, array $user_ids) {
    $sector_id = (int) $sector_id;
    $wanted = [];
    foreach ($user_ids as $uid) {
        $uid = (int) $uid;
        if ($uid > 0) $wanted[$uid] = true;
    }
    $wanted  = array_keys($wanted);
    $current = sector_user_ids($sector_id);

    $add    = array_values(array_diff($wanted, $current));
    $remove = array_values(array_diff($current, $wanted));

    foreach ($add as $uid) {
        db_execute(
            "INSERT IGNORE INTO doc_user_sectors (user_id, sector_id) VALUES (?, ?)",
            [$uid, $sector_id]
        );
    }
    if ($remove) {
        $in = implode(',', array_fill(0, count($remove), '?'));
        db_execute(
            "DELETE FROM doc_user_sectors WHERE sector_id = ? AND user_id IN ($in)",
            array_merge([$sector_id], $remove)
        );
    }
    doc_sector_scope_reset();
    return ['added' => count($add), 'removed' => count($remove)];
}

/** Setores de um usuário (linhas completas, ordenadas por nome). */
function user_sectors($user_id, $hospital_id = null) {
    try {
        $sql = "SELECT s.* FROM doc_user_sectors us
                JOIN doc_sectors s ON s.id = us.sector_id
                WHERE us.user_id = ? AND s.deleted_at IS NULL AND s.is_active = 1";
        $params = [(int) $user_id];
        if ($hospital_id !== null) {
            $sql .= " AND s.hospital_id = ?";
            $params[] = (int) $hospital_id;
        }
        return db_query($sql . " ORDER BY s.name", $params);
    } catch (Exception $ex) { return []; }
}

/** Ids dos setores de um usuário. */
function user_sector_ids($user_id, $hospital_id = null) {
    return array_map('intval', array_column(user_sectors($user_id, $hospital_id), 'id'));
}

/** Usuários candidatos ao vínculo (todos os ativos da plataforma). */
function sector_candidate_users() {
    try {
        return db_query(
            "SELECT id, name, email, username FROM users WHERE active = 1 ORDER BY name"
        );
    } catch (Exception $ex) { return []; }
}

// ═══════════════════════════════════════════════════════════════════════════
//  Escopo de acesso — o coração da independência entre setores
// ═══════════════════════════════════════════════════════════════════════════

/** Limpa o cache de escopo da requisição (após mexer em setores/vínculos). */
function doc_sector_scope_reset() {
    $GLOBALS['_doc_sector_scope'] = null;
}

/**
 * Escopo do usuário da sessão:
 *   ['all' => true]                     vê todos os setores
 *   ['all' => false, 'ids' => [1, 7]]   vê só esses (pode ser lista vazia)
 */
function doc_sector_scope() {
    if (isset($GLOBALS['_doc_sector_scope']) && $GLOBALS['_doc_sector_scope'] !== null) {
        return $GLOBALS['_doc_sector_scope'];
    }
    $scope = ['all' => true, 'ids' => []];

    if (is_logged_in()) {
        $full = false;
        try {
            $full = Core\Auth::isGlobalAdmin() || core_can('sectors.view_all');
        } catch (Throwable $ex) { $full = false; }

        if (!$full) {
            $scope = ['all' => false, 'ids' => user_sector_ids(get_user_id(), get_hospital_id())];
        }
    }
    $GLOBALS['_doc_sector_scope'] = $scope;
    return $scope;
}

/** O usuário enxerga todos os setores da unidade? */
function doc_sector_scope_is_full() {
    $s = doc_sector_scope();
    return !empty($s['all']);
}

/** O usuário pode ver este setor? */
function doc_sector_allowed($sector_id) {
    $sector_id = (int) $sector_id;
    if ($sector_id <= 0) return true;          // "todos"/institucional
    $s = doc_sector_scope();
    return !empty($s['all']) || in_array($sector_id, $s['ids'], true);
}

/**
 * Fragmento de WHERE que aplica, de uma vez:
 *   • o escopo do usuário (setores em que ele está incluído);
 *   • o setor escolhido no seletor global ($focus > 0), que só ESTREITA.
 *
 * $alias é o prefixo da coluna já com o ponto ('d.', 'i.') ou ''.
 * Devolve [sql, params] — sql começa com ' AND ' ou é ''.
 */
function doc_sector_where($alias = '', $focus = 0) {
    $col   = $alias . 'sector_id';
    $focus = (int) $focus;
    $scope = doc_sector_scope();

    if (!empty($scope['all'])) {
        return $focus > 0 ? [" AND {$col} = ?", [$focus]] : ['', []];
    }

    $ids = $scope['ids'];
    if ($focus > 0) {
        // O seletor só vale se o setor estiver no escopo; fora dele, a
        // consulta não pode devolver nada além do institucional.
        $ids = in_array($focus, $ids, true) ? [$focus] : [];
        if ($ids === []) {
            return [" AND {$col} IS NULL AND 1 = 0", []];
        }
        return [" AND {$col} = ?", [$focus]];
    }

    if ($ids === []) {
        // Sem setor algum: só o que é institucional (sem setor).
        return [" AND {$col} IS NULL", []];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    return [" AND ({$col} IN ($in) OR {$col} IS NULL)", $ids];
}

/**
 * Setores que o usuário pode usar em telas e formulários: os do seu escopo.
 * Quem vê tudo recebe a lista completa de setores ativos da unidade.
 */
function sector_list_for_user($hospital_id) {
    $all = sector_list($hospital_id);
    if (doc_sector_scope_is_full()) return $all;
    $ids = doc_sector_scope()['ids'];
    return array_values(array_filter($all, fn ($s) => in_array((int) $s['id'], $ids, true)));
}
