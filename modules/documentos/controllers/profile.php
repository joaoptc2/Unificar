<?php
/**
 * Controller de Perfil — módulo DOCUMENTOS.
 *
 * Perfil e troca de senha agora são do núcleo (?m=auth&a=profile|security).
 * Aqui permanece apenas a troca de SETOR em foco (contexto do módulo).
 */

/** Ver perfil → tela central do núcleo. */
function profile_index($param = null) {
    core_redirect('index.php?m=auth&a=profile');
}

/** Alterar senha → tela central do núcleo. */
function profile_change_password($param = null) {
    core_redirect('index.php?m=auth&a=security');
}

/**
 * Trocar setor em foco (qualquer usuário com múltiplos setores).
 */
function profile_switch_sector($param = null) {
    require_login();
    if (!is_post()) redirect('dashboard');
    csrf_validate();

    $sector_id = sanitize_int(input('sector_id'));

    // Verifica se o usuário tem acesso a este setor
    $user_sectors = user_sector_list(get_user_id());
    $allowed = false;
    $sector_name = '';
    foreach ($user_sectors as $s) {
        if ((int) $s['id'] === $sector_id) {
            $allowed = true;
            $sector_name = $s['name'];
            break;
        }
    }
    // Admin pode trocar para qualquer setor
    if (!$allowed && is_admin()) {
        $s = sector_find($sector_id);
        if ($s) { $allowed = true; $sector_name = $s['name']; }
    }

    if (!$allowed) {
        set_flash('error', 'Setor não encontrado ou sem permissão.');
        redirect('dashboard');
    }

    switch_sector_context($sector_id, $sector_name);
    $_SESSION['doc_user_sectors'] = $user_sectors;
    audit_log('sector_context_switch', "sector_id=$sector_id");
    set_flash('success', 'Setor alterado para: ' . $sector_name);
    redirect('dashboard');
}
