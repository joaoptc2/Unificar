<?php
/**
 * Controller de Recuperação de Senha
 *
 * Rotas:
 *   GET  /forgot-password         -> formulário
 *   POST /forgot-password/send    -> envia e-mail
 *   GET  /reset-password?token=X  -> formulário com nova senha
 *   POST /reset-password/update   -> atualiza senha
 */

function password_reset_forgot($param = null) {
    if (is_post()) {
        csrf_validate();
        $email = sanitize_email(input('email'));
        $ip    = client_ip();

        if (!is_valid_email($email)) {
            set_flash('error', 'Informe um e-mail válido.');
            redirect('forgot-password');
        }

        // Resposta genérica por segurança (não vazar se e-mail existe)
        $user = null;
        try {
            $user = user_find_by_email_any($email);
        } catch (Exception $ex) {
            log_error('password_reset_forgot:lookup', $ex);
        }

        if ($user) {
            try {
                $token      = generate_token(32);
                $token_hash = hash('sha256', $token);
                $expires_at = date('Y-m-d H:i:s', time() + PASSWORD_RESET_TTL_MIN * 60);

                // Invalida tokens anteriores
                db_execute(
                    "UPDATE password_resets SET used_at = NOW()
                     WHERE user_id = ? AND used_at IS NULL",
                    [$user['id']]
                );

                db_execute(
                    "INSERT INTO password_resets (user_id, token_hash, ip_address, expires_at, created_at)
                     VALUES (?, ?, ?, ?, NOW())",
                    [$user['id'], $token_hash, $ip, $expires_at]
                );

                $link = url('reset-password?token=' . urlencode($token));
                $body = '<p>Olá ' . e($user['name']) . ',</p>'
                      . '<p>Recebemos uma solicitação para redefinir sua senha no '
                      . e(APP_NAME) . '.</p>'
                      . '<p>Clique no botão abaixo para escolher uma nova senha. '
                      . 'O link expira em ' . PASSWORD_RESET_TTL_MIN . ' minutos.</p>';

                send_mail(
                    $user['email'],
                    'Redefinição de senha — ' . APP_NAME,
                    mail_template('Redefinir senha', $body, $link, 'Redefinir minha senha')
                );

                audit_log('password_reset_requested', "user_id={$user['id']}", $user['id'], $user['hospital_id']);
            } catch (Exception $ex) {
                log_error('password_reset_forgot:send', $ex);
            }
        }

        set_flash('success', 'Se o e-mail estiver cadastrado, você receberá um link em instantes.');
        redirect('login');
    }

    view_standalone('auth/forgot_password', []);
}

function password_reset_reset($param = null) {
    $token = (string) query('token', input('token'));
    if (empty($token)) {
        set_flash('error', 'Token inválido.');
        redirect('login');
    }

    $token_hash = hash('sha256', $token);
    $row = null;
    try {
        $row = db_query_one(
            "SELECT pr.*, u.email, u.name, u.hospital_id
             FROM password_resets pr
             JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = ?
               AND pr.used_at IS NULL
               AND pr.expires_at > NOW()
             LIMIT 1",
            [$token_hash]
        );
    } catch (Exception $ex) {
        log_error('password_reset_reset:lookup', $ex);
    }

    if (!$row) {
        set_flash('error', 'O link expirou ou é inválido. Solicite um novo.');
        redirect('forgot-password');
    }

    if (is_post()) {
        csrf_validate();
        $password = (string) input('password');
        $confirm  = (string) input('password_confirm');

        $errors = validate_password_strength($password);
        if ($password !== $confirm) $errors[] = 'As senhas não coincidem.';

        if (!empty($errors)) {
            set_flash('error', implode('<br>', $errors));
            redirect('reset-password?token=' . urlencode($token));
        }

        try {
            user_set_password($row['user_id'], hash_password($password), false);
            db_execute("UPDATE password_resets SET used_at = NOW() WHERE id = ?", [$row['id']]);
            audit_log('password_reset_used', "user_id={$row['user_id']}", $row['user_id'], $row['hospital_id']);
        } catch (Exception $ex) {
            log_error('password_reset_reset:update', $ex);
            set_flash('error', 'Erro ao atualizar senha. Tente novamente.');
            redirect('reset-password?token=' . urlencode($token));
        }

        set_flash('success', 'Senha redefinida com sucesso! Faça login com a nova senha.');
        redirect('login');
    }

    view_standalone('auth/reset_password', ['token' => $token, 'email' => $row['email']]);
}
