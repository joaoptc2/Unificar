<?php
/**
 * Controller de API (endpoints AJAX) — módulo DOCUMENTOS.
 */

function api_hospitals($param = null) {
    if (!core_can('hospitals.view')) json_response(['error' => 'Sem permissão'], 403);
    $hospitals = [];
    try {
        $hospitals = hospital_list_active();
    } catch (Exception $ex) {
        log_error('api_hospitals', $ex);
    }
    json_response($hospitals);
}

/**
 * Contagem de não lidas DESTE módulo (tabela global notifications,
 * filtrada por module='documentos').
 */
function api_notifications_count($param = null) {
    if (!is_logged_in()) json_response(['count' => 0]);
    $count = 0;
    try {
        $count = notification_unread_count(get_user_id());
    } catch (Exception $ex) {
        log_error('api_notifications_count', $ex);
    }
    json_response(['count' => $count]);
}

function api_indicator_data($param = null) {
    if (!is_logged_in()) json_response(['error' => 'Não autenticado'], 401);
    if (!core_can('indicators.view')) json_response(['error' => 'Sem permissão'], 403);

    $id = sanitize_int(query('id'));
    $hospital_id = get_hospital_id();

    $indicator = null;
    $data = [];

    try {
        $indicator = indicator_find($id, $hospital_id);
        if ($indicator) $data = indicator_data_series($id);
    } catch (Exception $ex) {
        log_error('api_indicator_data', $ex);
    }

    json_response(['indicator' => $indicator, 'data' => $data]);
}

function api_index($param = null) {
    json_response([
        'app'     => APP_NAME,
        'version' => APP_VERSION,
        'status'  => 'ok',
    ]);
}
