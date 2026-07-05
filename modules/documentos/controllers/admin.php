<?php
/**
 * Controller de Administração
 */

// ═══════════════════════════════════════════════════════════════════════════
//  CONFIGURAÇÕES VISUAIS (somente Admin Global)
// ═══════════════════════════════════════════════════════════════════════════

function admin_settings($param = null) {
    require_admin();

    $current  = settings_all();
    $fields   = setting_fields();
    $defaults = setting_defaults();

    // Agrupa por seção
    $groups = [];
    foreach ($fields as $key => $meta) {
        $g = $meta['group'] ?? 'Geral';
        $groups[$g][$key] = $meta;
    }

    view('admin/settings', [
        'page_title' => 'Configurações Visuais',
        'current'    => $current,
        'groups'     => $groups,
        'defaults'   => $defaults,
    ]);
}

function admin_settings_save($param = null) {
    require_admin();
    if (!is_post()) redirect('admin/settings');
    csrf_validate();

    $fields   = setting_fields();
    $defaults = setting_defaults();
    $values   = [];

    foreach ($fields as $key => $meta) {
        $raw = input($key, $defaults[$key] ?? '');
        // Sanitiza conforme tipo
        if ($meta['type'] === 'color') {
            $raw = preg_match('/^#[0-9a-fA-F]{6}$/', $raw) ? $raw : ($defaults[$key] ?? '#000000');
        } elseif ($meta['type'] === 'range') {
            $raw = (float) str_replace(',', '.', $raw);
            $min = $meta['min'] ?? 0;
            $max = $meta['max'] ?? 100;
            $raw = max($min, min($max, $raw));
            $raw = (string) $raw;
        } elseif ($meta['type'] === 'textarea') {
            // custom_css: remove tags script/php por segurança
            $raw = preg_replace('/<\s*(script|php|iframe|object|embed)/i', '&lt;$1', $raw);
        }
        $values[$key] = is_string($raw) ? trim($raw) : (string) $raw;
    }

    try {
        settings_save($values, get_user_id());
        audit_log('settings_updated', 'Configurações visuais atualizadas');
        set_flash('success', 'Configurações salvas com sucesso!');
    } catch (Exception $ex) {
        log_error('admin_settings_save', $ex);
        set_flash('error', 'Erro ao salvar: ' . e(substr($ex->getMessage(), 0, 200)));
    }

    redirect('admin/settings');
}

function admin_settings_reset($param = null) {
    require_admin();
    if (!is_post()) redirect('admin/settings');
    csrf_validate();

    try {
        settings_reset(get_user_id());
        audit_log('settings_reset', 'Configurações visuais restauradas ao padrão');
        set_flash('success', 'Configurações restauradas ao padrão.');
    } catch (Exception $ex) {
        log_error('admin_settings_reset', $ex);
        set_flash('error', 'Erro ao restaurar.');
    }
    redirect('admin/settings');
}

// ═══════════════════════════════════════════════════════════════════════════
//  SETORES
// ═══════════════════════════════════════════════════════════════════════════

function admin_sectors($param = null) {
    require_manager();
    $sectors = [];
    try {
        $sectors = sector_list_all(get_hospital_id());
    } catch (Exception $ex) {
        log_error('admin_sectors', $ex);
    }

    view('admin/sectors', [
        'page_title' => 'Gerenciar Setores',
        'sectors'    => $sectors,
    ]);
}

function admin_sector_store($param = null) {
    require_manager();
    if (!is_post()) redirect('admin/sectors');
    csrf_validate();

    $name = clean(input('name'));
    $code = clean(input('code')) ?: null;
    $description = clean(input('description'));

    if (empty($name)) {
        set_flash('error', 'Nome do setor é obrigatório.');
        redirect('admin/sectors');
    }

    try {
        $id = sector_create(get_hospital_id(), $name, $code, $description);
        audit_log('sector_created', "id=$id, name=$name");
        set_flash('success', 'Setor criado com sucesso!');
    } catch (Exception $ex) {
        log_error('admin_sector_store', $ex);
        set_flash('error', 'Erro ao criar setor.');
    }
    redirect('admin/sectors');
}

function admin_sector_update($param = null) {
    require_manager();
    if (!is_post()) redirect('admin/sectors');
    csrf_validate();

    $id   = sanitize_int(input('id'));
    $name = clean(input('name'));
    $code = clean(input('code')) ?: null;
    $desc = clean(input('description'));
    $active = sanitize_int(input('is_active', 1));

    try {
        sector_update($id, $name, $code, $desc, $active);
        audit_log('sector_updated', "id=$id");
        set_flash('success', 'Setor atualizado.');
    } catch (Exception $ex) {
        log_error('admin_sector_update', $ex);
        set_flash('error', 'Erro ao atualizar setor.');
    }
    redirect('admin/sectors');
}

function admin_sector_delete($param = null) {
    require_manager();
    if (!is_post()) redirect('admin/sectors');
    csrf_validate();

    $id = sanitize_int(input('id'));
    try {
        sector_soft_delete($id);
        audit_log('sector_deleted', "id=$id");
        set_flash('success', 'Setor removido.');
    } catch (Exception $ex) {
        log_error('admin_sector_delete', $ex);
        set_flash('error', 'Erro ao remover setor.');
    }
    redirect('admin/sectors');
}

// ═══════════════════════════════════════════════════════════════════════════
//  PAINEL ADMIN (dashboard)
// ═══════════════════════════════════════════════════════════════════════════

function admin_index($param = null) {
    require_manager();

    $stats = ['total_users' => 0, 'total_sectors' => 0];
    try {
        $stats['total_users'] = is_admin() ? user_count() : user_count(get_hospital_id());
        $sectors = sector_list(get_hospital_id());
        $stats['total_sectors'] = count($sectors);
    } catch (Exception $ex) {
        log_error('admin_index', $ex);
    }

    view('admin/index', [
        'page_title' => 'Administração',
        'stats'      => $stats,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  HOSPITAIS (somente Admin Global)
// ═══════════════════════════════════════════════════════════════════════════

function admin_hospitals($param = null) {
    require_admin();
    $hospitals = [];
    try {
        $hospitals = hospital_list_all();
    } catch (Exception $ex) {
        log_error('admin_hospitals', $ex);
    }

    view('admin/hospitals', [
        'page_title' => 'Gerenciar Hospitais',
        'hospitals'  => $hospitals,
    ]);
}

function admin_hospital_store($param = null) {
    require_admin();
    if (!is_post()) redirect('admin/hospitals');
    csrf_validate();

    $data = [
        'name'    => clean(input('name')),
        'cnpj'    => clean(input('cnpj')),
        'address' => clean(input('address')),
        'phone'   => clean(input('phone')),
        'email'   => sanitize_email(input('email')),
    ];

    if (empty($data['name'])) {
        set_flash('error', 'Nome do hospital é obrigatório.');
        redirect('admin/hospitals');
    }

    try {
        $id = hospital_create($data);
        audit_log('hospital_created', "id=$id, name={$data['name']}");
        set_flash('success', 'Hospital cadastrado com sucesso!');
    } catch (Exception $ex) {
        log_error('admin_hospital_store', $ex);
        set_flash('error', 'Erro ao cadastrar hospital.');
    }

    redirect('admin/hospitals');
}

function admin_hospital_update($param = null) {
    require_admin();
    if (!is_post()) redirect('admin/hospitals');
    csrf_validate();

    $id = sanitize_int(input('id'));
    $data = [
        'name'      => clean(input('name')),
        'cnpj'      => clean(input('cnpj')),
        'address'   => clean(input('address')),
        'phone'     => clean(input('phone')),
        'email'     => sanitize_email(input('email')),
        'is_active' => sanitize_int(input('is_active', 1)),
    ];

    try {
        hospital_update($id, $data);
        audit_log('hospital_updated', "id=$id");
        set_flash('success', 'Hospital atualizado com sucesso!');
    } catch (Exception $ex) {
        log_error('admin_hospital_update', $ex);
        set_flash('error', 'Erro ao atualizar hospital.');
    }
    redirect('admin/hospitals');
}

function admin_hospital_delete($param = null) {
    require_admin();
    if (!is_post()) redirect('admin/hospitals');
    csrf_validate();

    $id = sanitize_int(input('id'));
    try {
        hospital_soft_delete($id);
        audit_log('hospital_deleted', "id=$id");
        set_flash('success', 'Hospital removido com sucesso.');
    } catch (Exception $ex) {
        log_error('admin_hospital_delete', $ex);
        set_flash('error', 'Erro ao remover hospital.');
    }
    redirect('admin/hospitals');
}

// ═══════════════════════════════════════════════════════════════════════════
//  USUÁRIOS
// ═══════════════════════════════════════════════════════════════════════════

function admin_users($param = null) {
    require_manager();

    $search = clean(query('search', ''));
    $hospital_filter = is_admin() ? null : get_hospital_id();

    $users = [];
    $hospitals = [];
    $total = 0;

    try {
        $total      = user_count($hospital_filter, $search);
        $pagination = paginate($total);
        $users      = user_list($hospital_filter, $search, $pagination['per_page'], $pagination['offset']);
        if (is_admin()) {
            $hospitals = hospital_list_active();
        }
    } catch (Exception $ex) {
        log_error('admin_users', $ex);
        $pagination = paginate(0);
    }

    $all_sectors = [];
    try { $all_sectors = sector_list(get_hospital_id()); } catch (Exception $ex) {}

    view('admin/users', [
        'page_title'  => 'Gerenciar Usuários',
        'users'       => $users,
        'hospitals'   => $hospitals,
        'all_sectors' => $all_sectors,
        'search'      => $search,
        'pagination'  => $pagination,
    ]);
}

function admin_user_store($param = null) {
    require_manager();
    if (!is_post()) redirect('admin/users');
    csrf_validate();

    $name        = clean(input('name'));
    $email       = sanitize_email(input('email'));
    $password    = (string) input('password');
    $role_id     = sanitize_int(input('role_id', 3));
    $hospital_id = is_admin() ? sanitize_int(input('hospital_id')) : get_hospital_id();

    $errors = [];
    if (empty($name))  $errors[] = 'Nome é obrigatório.';
    if (!is_valid_email($email)) $errors[] = 'E-mail inválido.';
    $errors = array_merge($errors, validate_password_strength($password));
    if (empty($hospital_id)) $errors[] = 'Hospital é obrigatório.';

    try {
        if (user_email_exists($email, $hospital_id)) {
            $errors[] = 'E-mail já cadastrado neste hospital.';
        }
    } catch (Exception $ex) {
        log_error('admin_user_store:check_email', $ex);
    }

    if (!empty($errors)) {
        set_flash('error', implode('<br>', $errors));
        redirect('admin/users');
    }

    // Gestor não pode criar admin global
    if ($role_id === 1 && !is_admin()) $role_id = 2;

    try {
        $id = user_create($hospital_id, $name, $email, hash_password($password), $role_id, true);
        $sector_ids = array_map('intval', $_POST['sector_ids'] ?? []);
        if (!empty($sector_ids)) user_sector_sync($id, $sector_ids);
        audit_log('user_created', "id=$id, email=$email, hospital=$hospital_id");
        set_flash('success', 'Usuário criado. Ele será solicitado a trocar a senha no primeiro acesso.');
    } catch (Exception $ex) {
        log_error('admin_user_store', $ex);
        set_flash('error', 'Erro ao criar usuário.');
    }

    redirect('admin/users');
}

function admin_user_update($param = null) {
    require_manager();
    if (!is_post()) redirect('admin/users');
    csrf_validate();

    $id       = sanitize_int(input('id'));
    $name     = clean(input('name'));
    $email    = sanitize_email(input('email'));
    $password = (string) input('password');
    $role_id  = sanitize_int(input('role_id', 3));
    $active   = sanitize_int(input('is_active', 1));

    try {
        $target = user_find($id);
    } catch (Exception $ex) {
        log_error('admin_user_update:find', $ex);
        $target = null;
    }

    if (!$target) {
        set_flash('error', 'Usuário não encontrado.');
        redirect('admin/users');
    }

    if (!is_admin() && $target['hospital_id'] != get_hospital_id()) {
        set_flash('error', 'Acesso negado.');
        redirect('admin/users');
    }
    if ($role_id === 1 && !is_admin()) $role_id = $target['role_id'];

    $errors = [];
    if (empty($name))            $errors[] = 'Nome é obrigatório.';
    if (!is_valid_email($email)) $errors[] = 'E-mail inválido.';
    if (!empty($password)) $errors = array_merge($errors, validate_password_strength($password));

    if (!empty($errors)) {
        set_flash('error', implode('<br>', $errors));
        redirect('admin/users');
    }

    try {
        user_update($id, [
            'name' => $name, 'email' => $email,
            'role_id' => $role_id, 'is_active' => $active,
        ]);
        if (!empty($password)) {
            user_set_password($id, hash_password($password), true);
        }
        $sector_ids = array_map('intval', $_POST['sector_ids'] ?? []);
        user_sector_sync($id, $sector_ids);
        audit_log('user_updated', "id=$id");
        set_flash('success', 'Usuário atualizado com sucesso!');
    } catch (Exception $ex) {
        log_error('admin_user_update', $ex);
        set_flash('error', 'Erro ao atualizar usuário.');
    }

    redirect('admin/users');
}

function admin_user_delete($param = null) {
    require_manager();
    if (!is_post()) redirect('admin/users');
    csrf_validate();

    $id = sanitize_int(input('id'));
    if ($id === get_user_id()) {
        set_flash('error', 'Você não pode excluir seu próprio usuário.');
        redirect('admin/users');
    }

    try {
        if (!is_admin()) {
            user_soft_delete($id, get_hospital_id());
        } else {
            user_soft_delete($id);
        }
        audit_log('user_deleted', "id=$id");
        set_flash('success', 'Usuário removido com sucesso.');
    } catch (Exception $ex) {
        log_error('admin_user_delete', $ex);
        set_flash('error', 'Erro ao remover usuário.');
    }

    redirect('admin/users');
}
