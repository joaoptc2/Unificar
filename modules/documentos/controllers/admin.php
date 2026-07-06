<?php
/**
 * Controller de Administração do módulo DOCUMENTOS.
 *
 * Usuários são GLOBAIS (núcleo): o módulo não cria/edita/exclui usuários
 * nem senhas — isso é da administração central (?m=admin&a=users). Aqui o
 * gestor apenas associa usuários (que já têm acesso ao módulo) aos setores.
 * As configurações visuais do legado foram removidas (tema é do núcleo).
 */

// ═══════════════════════════════════════════════════════════════════════════
//  PAINEL ADMIN (dashboard)
// ═══════════════════════════════════════════════════════════════════════════

function admin_index($param = null) {
    // Painel: acessível a quem enxerga QUALQUER recurso administrativo
    if (!core_can('sectors.view') && !core_can('user_sectors.view')
        && !core_can('categories.view') && !core_can('hospitals.view')) {
        core_require('sectors.view'); // interrompe com 403 do núcleo
    }

    $stats = ['total_users' => 0, 'total_sectors' => 0, 'total_categories' => 0];
    try {
        $stats['total_users'] = doc_users_with_access_count();
        $sectors = sector_list(get_hospital_id());
        $stats['total_sectors'] = count($sectors);
        $stats['total_categories'] = count(document_categories_all());
    } catch (Exception $ex) {
        log_error('admin_index', $ex);
    }

    view('admin/index', [
        'page_title' => 'Administração',
        'stats'      => $stats,
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  SETORES
// ═══════════════════════════════════════════════════════════════════════════

function admin_sectors($param = null) {
    core_require('sectors.view');
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
    core_require('sectors.create');
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
    core_require('sectors.edit');
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
    core_require('sectors.delete');
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
//  HOSPITAIS / UNIDADES (somente Admin)
// ═══════════════════════════════════════════════════════════════════════════

function admin_hospitals($param = null) {
    core_require('hospitals.view');
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
    core_require('hospitals.edit');
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
    core_require('hospitals.edit');
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
    core_require('hospitals.edit');
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
//  USUÁRIOS & SETORES
//  Lista usuários globais com acesso ao módulo e gerencia APENAS a
//  associação de setores (doc_user_sectors). CRUD de usuário/senha/papel:
//  administração central (?m=admin&a=users).
// ═══════════════════════════════════════════════════════════════════════════

function admin_users($param = null) {
    core_require('user_sectors.view');

    $search = clean(query('search', ''));

    $users = [];
    $total = 0;

    try {
        $total      = doc_users_with_access_count($search);
        $pagination = paginate($total);
        $users      = doc_users_with_access($search, $pagination['per_page'], $pagination['offset']);
    } catch (Exception $ex) {
        log_error('admin_users', $ex);
        $pagination = paginate(0);
    }

    $all_sectors = [];
    try { $all_sectors = sector_list(get_hospital_id()); } catch (Exception $ex) {}

    view('admin/users', [
        'page_title'  => 'Usuários & Setores',
        'users'       => $users,
        'all_sectors' => $all_sectors,
        'search'      => $search,
        'pagination'  => $pagination,
    ]);
}

/**
 * Atualiza APENAS os setores de um usuário com acesso ao módulo.
 */
function admin_user_sectors($param = null) {
    core_require('user_sectors.edit');
    if (!is_post()) redirect('admin/users');
    csrf_validate();

    $user_id = sanitize_int(input('user_id'));

    try {
        if (!$user_id || !doc_user_has_module_access($user_id)) {
            set_flash('error', 'Usuário não encontrado ou sem acesso a este módulo.');
            redirect('admin/users');
        }

        $sector_ids = array_map('intval', $_POST['sector_ids'] ?? []);
        user_sector_sync($user_id, $sector_ids);

        // Se o usuário editado for o logado, atualiza o contexto na sessão
        if ($user_id === get_user_id()) {
            unset($_SESSION['doc_user_sectors'], $_SESSION['doc_sector_id'], $_SESSION['doc_sector_name']);
            doc_sectors_ensure_loaded();
        }

        audit_log('user_sectors_updated', "user_id=$user_id, sectors=" . implode(',', $sector_ids));
        set_flash('success', 'Setores do usuário atualizados.');
    } catch (Exception $ex) {
        log_error('admin_user_sectors', $ex);
        set_flash('error', 'Erro ao atualizar setores do usuário.');
    }

    redirect('admin/users');
}

// ═══════════════════════════════════════════════════════════════════════════
//  CATEGORIAS DE DOCUMENTOS
// ═══════════════════════════════════════════════════════════════════════════

function admin_categories($param = null) {
    core_require('categories.view');
    $categories = [];
    try {
        $categories = document_categories_all();
    } catch (Exception $ex) {
        log_error('admin_categories', $ex);
    }

    view('admin/categories', [
        'page_title' => 'Categorias de Documentos',
        'categories' => $categories,
    ]);
}

function admin_category_store($param = null) {
    core_require('categories.create');
    if (!is_post()) redirect('admin/categories');
    csrf_validate();

    $name = clean(input('name'));
    if (empty($name)) {
        set_flash('error', 'Nome da categoria é obrigatório.');
        redirect('admin/categories');
    }

    try {
        $id = document_category_create(
            $name,
            clean(input('description')) ?: null,
            clean(input('icon')) ?: null,
            sanitize_int(input('sort_order', 0))
        );
        audit_log('category_created', "id=$id, name=$name");
        set_flash('success', 'Categoria criada com sucesso!');
    } catch (Exception $ex) {
        log_error('admin_category_store', $ex);
        set_flash('error', 'Erro ao criar categoria (nome duplicado?).');
    }
    redirect('admin/categories');
}

function admin_category_update($param = null) {
    core_require('categories.edit');
    if (!is_post()) redirect('admin/categories');
    csrf_validate();

    $id   = sanitize_int(input('id'));
    $name = clean(input('name'));
    if (!$id || empty($name)) {
        set_flash('error', 'Dados inválidos.');
        redirect('admin/categories');
    }

    try {
        document_category_update($id, [
            'name'        => $name,
            'description' => clean(input('description')) ?: null,
            'icon'        => clean(input('icon')) ?: null,
            'sort_order'  => sanitize_int(input('sort_order', 0)),
            'is_active'   => sanitize_int(input('is_active', 1)),
        ]);
        audit_log('category_updated', "id=$id");
        set_flash('success', 'Categoria atualizada.');
    } catch (Exception $ex) {
        log_error('admin_category_update', $ex);
        set_flash('error', 'Erro ao atualizar categoria.');
    }
    redirect('admin/categories');
}

function admin_category_delete($param = null) {
    core_require('categories.delete');
    if (!is_post()) redirect('admin/categories');
    csrf_validate();

    $id = sanitize_int(input('id'));
    try {
        document_category_delete($id);
        audit_log('category_deleted', "id=$id");
        set_flash('success', 'Categoria removida.');
    } catch (Exception $ex) {
        log_error('admin_category_delete', $ex);
        set_flash('error', 'Erro ao remover categoria.');
    }
    redirect('admin/categories');
}
