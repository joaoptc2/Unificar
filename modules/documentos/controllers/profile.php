<?php
/**
 * Controller de Perfil — módulo DOCUMENTOS.
 *
 * Perfil e troca de senha são do núcleo (?m=auth&a=profile|security).
 * A troca de setor em foco passou para dashboard/switch-sector (seletor
 * global); a rota antiga profile/switch-sector é mantida por compatibilidade.
 */

/** Ver perfil → tela central do núcleo. */
function profile_index($param = null) {
    core_redirect('index.php?m=auth&a=profile');
}

/** Alterar senha → tela central do núcleo. */
function profile_change_password($param = null) {
    core_redirect('index.php?m=auth&a=security');
}

/** Compatibilidade: delega ao seletor global. */
function profile_switch_sector($param = null) {
    require_once CONTROLLERS_PATH . '/dashboard.php';
    dashboard_switch_sector($param);
}
