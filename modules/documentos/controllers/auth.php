<?php
/**
 * Controller de Autenticação
 *
 * Login sem seleção de hospital — o sistema auto-detecta o hospital ativo.
 * Após login, se o usuário tem setores, carrega o primeiro na sessão.
 */

function auth_login($param = null) {
    if (is_logged_in()) redirect('dashboard');
    view_standalone('auth/login', []);
}

function auth_process_login($param = null) {
    if (!is_post()) redirect('login');
    csrf_validate();

    $email    = sanitize_email(input('email'));
    $password = (string) input('password');
    $ip       = client_ip();

    $errors = [];
    if (empty($email))    $errors[] = 'Informe o e-mail.';
    if (empty($password)) $errors[] = 'Informe a senha.';
    if (!empty($email) && !is_valid_email($email)) $errors[] = 'E-mail inválido.';

    if (!empty($errors)) {
        set_flash('error', implode('<br>', $errors));
        redirect('login');
    }

    $remaining = login_attempts_check($email, $ip);
    if ($remaining > 0) {
        audit_log('login_blocked', "email=$email, ip=$ip");
        set_flash('error', "Muitas tentativas falhas. Tente novamente em $remaining minuto(s).");
        redirect('login');
    }

    // Busca o usuário por e-mail em qualquer hospital ativo
    $user = null;
    try {
        $user = user_find_by_email_any($email);
    } catch (Exception $ex) {
        log_error('auth_process_login', $ex);
        set_flash('error', 'Erro ao conectar com o banco de dados.');
        redirect('login');
    }

    if (!$user || !verify_password($password, $user['password'])) {
        login_attempts_record($email, $ip, false);
        audit_log('login_failed', "email=$email");
        set_flash('error', 'E-mail ou senha inválidos.');
        redirect('login');
    }

    login_attempts_record($email, $ip, true);
    session_login($user);

    // Carrega setores do usuário e define o primeiro na sessão
    try {
        $sectors = user_sector_list($user['id']);
        if (!empty($sectors)) {
            $_SESSION['sector_id']   = (int) $sectors[0]['id'];
            $_SESSION['sector_name'] = $sectors[0]['name'];
            $_SESSION['user_sectors'] = $sectors;
        }
    } catch (Exception $ex) {
        log_error('auth_process_login:sectors', $ex);
    }

    audit_log('login_success', "email=$email");

    try {
        user_touch_last_login($user['id']);
    } catch (Exception $ex) {}

    if (!empty($_SESSION['force_password_change'])) {
        redirect('profile/change-password');
    }
    redirect('dashboard');
}

function auth_logout($param = null) {
    if (is_logged_in()) {
        audit_log('logout', 'user_id=' . get_user_id());
    }
    session_logout();
    set_flash('success', 'Você saiu do sistema com sucesso.');
    redirect('login');
}
