<?php
/**
 * Controller do Dashboard
 */

function dashboard_index($param = null) {
    require_login();

    $hospital_id = get_hospital_id();

    $stats = [
        'total_documents'      => 0,
        'expired_documents'    => 0,
        'expiring_documents'   => 0,
        'valid_documents'      => 0,
        'total_indicators'     => 0,
        'unread_notifications' => 0,
    ];
    $expiring_docs = [];
    $expired_docs  = [];
    $notifications = [];

    try {
        $doc_stats = document_stats($hospital_id);
        $stats['total_documents']    = $doc_stats['total'];
        $stats['expired_documents']  = $doc_stats['expired'];
        $stats['expiring_documents'] = $doc_stats['expiring'];
        $stats['valid_documents']    = $doc_stats['valid'];

        $stats['total_indicators']     = indicator_count($hospital_id);
        $stats['unread_notifications'] = notification_unread_count($hospital_id, get_user_id());

        $expiring_docs = document_expiring_list($hospital_id, 10);
        $expired_docs  = document_expired_list($hospital_id, 10);
        $notifications = notification_recent($hospital_id, get_user_id(), 5);
    } catch (Exception $ex) {
        log_error('dashboard_index', $ex);
    }

    view('dashboard/index', [
        'page_title'    => 'Dashboard',
        'stats'         => $stats,
        'expiring_docs' => $expiring_docs,
        'expired_docs'  => $expired_docs,
        'notifications' => $notifications,
    ]);
}
