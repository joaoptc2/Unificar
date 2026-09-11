<?php
/**
 * Model: Usuários — tabela GLOBAL `users` do núcleo.
 *
 * O módulo não cria/edita/exclui usuários nem senhas (administração central
 * em ?m=admin&a=users). Aqui ficam apenas consultas de leitura.
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
 * Usuários ativos (para o campo "responsável" dos indicadores).
 */
function user_list_active() {
    try {
        return db_query("SELECT id, name FROM users WHERE active = 1 ORDER BY name");
    } catch (Exception $ex) { return []; }
}

/**
 * Responsáveis pelos documentos (ativos) — para as notificações do cron.
 * "Gestores" = quem tem a micropermissão documents.approve
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
