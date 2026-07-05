<?php
/**
 * Model: Usuários — tabela GLOBAL `users` do núcleo.
 *
 * O módulo não cria/edita/exclui usuários nem senhas (administração central
 * em ?m=admin&a=users). Aqui ficam apenas consultas de leitura e a listagem
 * de quem tem acesso ao módulo (user_module_access, slug 'documentos').
 * O papel no módulo vem do RBAC do núcleo; admins globais (users.is_admin)
 * têm acesso implícito com papel 'admin'.
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
 * Usuários com acesso ao módulo documentos: vínculo em user_module_access
 * (module_slug = 'documentos') OU admin global (acesso implícito).
 * Retorna module_role ('admin' | 'gestor' | 'operador').
 */
function doc_users_with_access($search = '', $limit = 20, $offset = 0) {
    $sql = "SELECT u.id, u.name, u.email, u.active, u.last_login_at, u.is_admin,
                   COALESCE(uma.role, CASE WHEN u.is_admin = 1 THEN 'admin' ELSE NULL END) AS module_role
            FROM users u
            LEFT JOIN user_module_access uma
                   ON uma.user_id = u.id AND uma.module_slug = 'documentos'
            WHERE (uma.id IS NOT NULL OR u.is_admin = 1)";
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
            LEFT JOIN user_module_access uma
                   ON uma.user_id = u.id AND uma.module_slug = 'documentos'
            WHERE (uma.id IS NOT NULL OR u.is_admin = 1)";
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
 * Um usuário tem acesso a este módulo? (vínculo RBAC ou admin global)
 */
function doc_user_has_module_access($user_id) {
    $row = db_query_one(
        "SELECT u.id
         FROM users u
         LEFT JOIN user_module_access uma
                ON uma.user_id = u.id AND uma.module_slug = 'documentos'
         WHERE u.id = ? AND (uma.id IS NOT NULL OR u.is_admin = 1)",
        [(int) $user_id]
    );
    return !empty($row);
}

/**
 * Gestores + admins do módulo (ativos) — para envio de notificações do cron.
 */
function user_managers_of() {
    return db_query(
        "SELECT DISTINCT u.id, u.name, u.email
         FROM users u
         LEFT JOIN user_module_access uma
                ON uma.user_id = u.id AND uma.module_slug = 'documentos'
         WHERE u.active = 1
           AND (u.is_admin = 1 OR uma.role IN ('admin', 'gestor'))"
    );
}
