<?php
/**
 * Model: Usuários — tabela GLOBAL `users` do núcleo.
 *
 * O módulo não cria/edita/exclui usuários nem senhas (administração central
 * em ?m=admin&a=users). Aqui ficam apenas consultas de leitura e a listagem
 * de quem tem acesso ao módulo.
 *
 * Acesso ao módulo = ter QUALQUER micropermissão nele (permission_grants,
 * direta ou via grupo). Admins globais (users.is_admin) têm acesso implícito
 * com todas as permissões. A antiga tabela de papéis por módulo é legado
 * e NÃO é mais lida aqui.
 */

function user_find($id) {
    return db_query_one(
        "SELECT u.id, u.name, u.username, u.email, u.active, u.last_login_at, u.created_at
         FROM users u
         WHERE u.id = ?",
        [(int) $id]
    );
}

function user_find_by_email($email) {
    return db_query_one(
        "SELECT u.id, u.name, u.username, u.email, u.active
         FROM users u
         WHERE u.email = ? AND u.active = 1
         LIMIT 1",
        [$email]
    );
}

/**
 * Fragmento SQL: usuário `u` tem alguma micropermissão neste módulo
 * (grant individual allow, grant de grupo, ou admin global).
 */
function _doc_access_condition() {
    return "(u.is_admin = 1
             OR EXISTS (SELECT 1 FROM permission_grants pg
                        WHERE pg.subject_type = 'user' AND pg.subject_id = u.id
                          AND pg.module_slug = 'documentos' AND pg.allowed = 1)
             OR EXISTS (SELECT 1 FROM permission_grants pg
                        JOIN user_group_members ugm ON ugm.group_id = pg.subject_id
                        WHERE pg.subject_type = 'group' AND ugm.user_id = u.id
                          AND pg.module_slug = 'documentos' AND pg.allowed = 1))";
}

/**
 * Usuários com acesso ao módulo documentos (qualquer micropermissão
 * concedida, direta ou por grupo) ou admins globais (acesso implícito).
 */
function doc_users_with_access($search = '', $limit = 20, $offset = 0) {
    $sql = "SELECT u.id, u.name, u.email, u.active, u.last_login_at, u.is_admin
            FROM users u
            WHERE " . _doc_access_condition();
    $params = [];
    if ($search !== '') {
        $sql .= " AND (u.name LIKE ? OR u.email LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $sql .= " ORDER BY u.name LIMIT ? OFFSET ?";
    $params[] = (int) $limit;
    $params[] = (int) $offset;
    return db_query($sql, $params);
}

function doc_users_with_access_count($search = '') {
    $sql = "SELECT COUNT(*) AS total
            FROM users u
            WHERE " . _doc_access_condition();
    $params = [];
    if ($search !== '') {
        $sql .= " AND (u.name LIKE ? OR u.email LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $row = db_query_one($sql, $params);
    return (int) ($row['total'] ?? 0);
}

/**
 * Um usuário tem acesso a este módulo? (alguma micropermissão ou admin global)
 */
function doc_user_has_module_access($user_id) {
    $row = db_query_one(
        "SELECT u.id FROM users u
         WHERE u.id = ? AND " . _doc_access_condition(),
        [(int) $user_id]
    );
    return !empty($row);
}

/**
 * Responsáveis pelos documentos (ativos) — para as notificações do cron.
 * "Gestores" agora = quem tem a micropermissão documents.approve
 * (inclui admins globais), via Core\Perms::usersWith().
 */
function user_managers_of() {
    $ids = Core\Perms::usersWith('documentos', 'documents.approve');
    if (empty($ids)) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    return db_query(
        "SELECT u.id, u.name, u.email
         FROM users u
         WHERE u.active = 1 AND u.id IN ($in)
         ORDER BY u.name",
        array_map('intval', $ids)
    );
}
