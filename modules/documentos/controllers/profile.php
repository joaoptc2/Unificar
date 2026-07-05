<?php
/**
 * Controller de Perfil do Usuário
 *
 * Permite que o usuário veja seus dados e troque a própria senha.
 */

function profile_index($param = null) {
    require_login();

    $user = null;
    try {
        $user = user_find(get_user_id());
    } catch (Exception $ex) {
        log_error('profile_index', $ex);
    }

    view('profile/index', [
        'page_title' => 'Meu Perfil',
        'user'       => $user,
    ]);
}

function profile_update($param = null) {
    require_login();
    if (!is_post()) redirect('profile');
    csrf_validate();

    $name  = clean(input('name'));
    $email = sanitize_email(input('email'));

    $errors = [];
    if (empty($name))  $errors[] = 'Nome é obrigatório.';
    if (!is_valid_email($email)) $errors[] = 'E-mail inválido.';

    // Checa e-mail duplicado no mesmo hospital
    if (empty($errors) && user_email_exists($email, get_hospital_id(), get_user_id())) {
        $errors[] = 'Este e-mail já está em uso por outro usuário.';
    }

    if (!empty($errors)) {
        set_flash('error', implode('<br>', $errors));
        redirect('profile');
    }

    try {
        user_update(get_user_id(), ['name' => $name, 'email' => $email]);
        $_SESSION['user_name']  = $name;
        $_SESSION['user_email'] = $email;
        audit_log('profile_updated', "user_id=" . get_user_id());
        set_flash('success', 'Perfil atualizado com sucesso.');
    } catch (Exception $ex) {
        log_error('profile_update', $ex);
        set_flash('error', 'Erro ao atualizar perfil.');
    }

    redirect('profile');
}

function profile_change_password($param = null) {
    require_login();

    if (is_post()) {
        csrf_validate();
        $current = (string) input('current_password');
        $new     = (string) input('new_password');
        $confirm = (string) input('new_password_confirm');

        $errors = [];

        // Se for troca voluntária (não forçada), exige senha atual
        $is_forced = !empty($_SESSION['force_password_change']);

        try {
            $user = user_find(get_user_id());
        } catch (Exception $ex) {
            log_error('profile_change_password:user', $ex);
            set_flash('error', 'Erro ao carregar usuário.');
            redirect('profile/change-password');
        }

        if (!$is_forced) {
            if (empty($current) || !verify_password($current, $user['password'])) {
                $errors[] = 'Senha atual incorreta.';
            }
        }

        $errors = array_merge($errors, validate_password_strength($new));
        if ($new !== $confirm) $errors[] = 'A confirmação não coincide com a nova senha.';
        if (!empty($current) && $current === $new) $errors[] = 'A nova senha deve ser diferente da atual.';

        if (!empty($errors)) {
            set_flash('error', implode('<br>', $errors));
            redirect('profile/change-password');
        }

        try {
            user_set_password(get_user_id(), hash_password($new), false);
            $_SESSION['force_password_change'] = false;
            audit_log('password_changed', 'user_id=' . get_user_id());
            set_flash('success', 'Senha alterada com sucesso.');
        } catch (Exception $ex) {
            log_error('profile_change_password:update', $ex);
            set_flash('error', 'Erro ao alterar senha.');
            redirect('profile/change-password');
        }

        redirect($is_forced ? 'dashboard' : 'profile');
    }

    view('profile/change_password', [
        'page_title' => 'Alterar Senha',
        'is_forced'  => !empty($_SESSION['force_password_change']),
    ]);
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
    $_SESSION['user_sectors'] = $user_sectors;
    audit_log('sector_context_switch', "sector_id=$sector_id");
    set_flash('success', 'Setor alterado para: ' . $sector_name);
    redirect('dashboard');
}

/**
 * @deprecated Mantido para não quebrar URLs antigas. Redireciona para dashboard.
 */
function profile_switch_hospital($param = null) {
    redirect('dashboard');
}
