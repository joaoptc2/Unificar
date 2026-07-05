<?php
/**
 * Controller de Notificações — módulo DOCUMENTOS.
 * Opera sobre a tabela global `notifications` (module='documentos').
 */

function notifications_index($param = null) {
    require_login();
    $user_id = get_user_id();

    $filter = (string) query('filter', 'all');

    $notifications = [];
    $total = 0;
    try {
        $total      = notification_count($user_id, $filter);
        $pagination = paginate($total);
        $notifications = notification_list($user_id, $filter,
                                           $pagination['per_page'], $pagination['offset']);
    } catch (Exception $ex) {
        log_error('notifications_index', $ex);
        $pagination = paginate(0);
    }

    view('notifications/index', [
        'page_title'    => 'Notificações',
        'notifications' => $notifications,
        'filter'        => $filter,
        'pagination'    => $pagination,
    ]);
}

function notifications_read($param = null) {
    require_login();
    if (!is_post()) redirect('notifications');
    csrf_validate();
    $id = sanitize_int(input('id'));
    try {
        notification_mark_read($id, get_user_id());
    } catch (Exception $ex) {
        log_error('notifications_read', $ex);
    }
    redirect('notifications');
}

function notifications_count($param = null) {
    if (!is_logged_in()) json_response(['count' => 0]);
    $count = 0;
    try {
        $count = notification_unread_count(get_user_id());
    } catch (Exception $ex) {
        log_error('notifications_count', $ex);
    }
    json_response(['count' => $count]);
}

function notifications_readall($param = null) {
    require_login();
    if (!is_post()) redirect('notifications');
    csrf_validate();
    try {
        notification_mark_all_read(get_user_id());
        set_flash('success', 'Todas as notificações foram marcadas como lidas.');
    } catch (Exception $ex) {
        log_error('notifications_readall', $ex);
        set_flash('error', 'Erro ao atualizar notificações.');
    }
    redirect('notifications');
}

function notifications_delete($param = null) {
    require_login();
    if (!is_post()) redirect('notifications');
    csrf_validate();
    $id = sanitize_int(input('id'));
    try {
        notification_delete($id, get_user_id());
    } catch (Exception $ex) {
        log_error('notifications_delete', $ex);
    }
    redirect('notifications');
}
