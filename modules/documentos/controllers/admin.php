<?php
/**
 * Controller de Administração do módulo DOCUMENTOS (setores e categorias).
 *
 * As telas de configuração ficam na ADMINISTRAÇÃO CENTRAL
 * (index.php?m=admin&a=module&slug=documentos&tab=sectors|categories —
 * ver admin_panel.php). As rotas GET antigas (admin, admin/sectors,
 * admin/categories) redirecionam para lá; os POSTs continuam aqui e, ao
 * terminar, voltam para o painel central.
 *
 * "Usuários do setor" (doc_user_sectors) define QUEM enxerga o setor: os
 * setores são independentes e cada usuário só vê os documentos, indicadores
 * e planos de ação dos setores em que foi incluído (ver models/sector.php).
 * O cadastro de usuários em si continua sendo global (?m=admin&a=users);
 * aqui só se define o vínculo. "Unidades/Hospitais" segue descontinuado.
 */

// ═══════════════════════════════════════════════════════════════════════════
//  Redirecionamentos das rotas GET antigas → painel central
// ═══════════════════════════════════════════════════════════════════════════

function admin_index($param = null) {
    core_require_any(['sectors.view', 'categories.view']);
    core_redirect(core_admin_url('documentos', core_can('sectors.view') ? 'sectors' : 'categories'));
}

function admin_sectors($param = null) {
    core_require('sectors.view');
    if (core_admin_tab() === null) {
        core_redirect(core_admin_url('documentos', 'sectors'));
    }
    admin_render_sectors();
}

function admin_categories($param = null) {
    core_require('categories.view');
    if (core_admin_tab() === null) {
        core_redirect(core_admin_url('documentos', 'categories'));
    }
    admin_render_categories();
}

/** Rotas descontinuadas (favoritos antigos): usuários são do núcleo. */
function admin_users($param = null) {
    core_redirect('index.php?m=admin&a=users');
}
function admin_user_sectors($param = null) {
    core_redirect(core_admin_url('documentos', 'sectors'));
}
function admin_hospitals($param = null) {
    core_redirect(core_admin_url('documentos', 'sectors'));
}

// ═══════════════════════════════════════════════════════════════════════════
//  Telas (renderizadas dentro do painel central — admin_panel.php)
// ═══════════════════════════════════════════════════════════════════════════

function admin_render_sectors() {
    core_require('sectors.view');
    $sectors = [];
    try {
        $sectors = sector_list_all(get_hospital_id());
    } catch (Exception $ex) {
        log_error('admin_sectors', $ex);
    }

    view('admin/sectors', [
        'page_title' => 'Setores',
        'sectors'    => $sectors,
        'menu_key'   => 'module-settings',
    ]);
}

/**
 * Tela "Usuários do setor": define quem enxerga o setor. A lista de
 * candidatos é a de usuários ativos da plataforma — o vínculo é só
 * visibilidade, não cria nem altera cadastro.
 */
function admin_render_sector_users() {
    core_require('sectors.view');

    $sector_id = (int) ($_GET['sector'] ?? 0);
    $sector    = $sector_id ? sector_find($sector_id) : null;
    if (!$sector || (int) $sector['hospital_id'] !== get_hospital_id()) {
        set_flash('error', 'Setor não encontrado.');
        _admin_back('sectors');
    }

    $linked = [];
    $users  = [];
    try {
        $linked = sector_user_ids($sector_id);
        $users  = sector_candidate_users();
    } catch (Exception $ex) {
        log_error('admin_sector_users', $ex);
    }

    view('admin/sector_users', [
        'page_title' => 'Usuários do setor — ' . $sector['name'],
        'sector'     => $sector,
        'users'      => $users,
        'linked'     => $linked,
        'menu_key'   => 'module-settings',
    ]);
}

function admin_render_categories() {
    core_require('categories.view');
    $categories = [];
    try {
        $categories = document_categories_all();
    } catch (Exception $ex) {
        log_error('admin_categories', $ex);
    }

    view('admin/categories', [
        'page_title' => 'Categorias de documentos',
        'categories' => $categories,
        'menu_key'   => 'module-settings',
    ]);
}

// ═══════════════════════════════════════════════════════════════════════════
//  SETORES (POST)
// ═══════════════════════════════════════════════════════════════════════════

function _admin_back($tab) {
    core_redirect(core_admin_url('documentos', $tab));
}

function admin_sector_store($param = null) {
    core_require('sectors.create');
    if (!is_post()) _admin_back('sectors');
    csrf_validate();

    $name = clean(input('name'));
    $code = clean(input('code')) ?: null;
    $description = clean(input('description'));

    if (empty($name)) {
        set_flash('error', 'Nome do setor é obrigatório.');
        _admin_back('sectors');
    }

    try {
        $id = sector_create(get_hospital_id(), $name, $code, $description);
        audit_log('sector_created', "id=$id, name=$name");
        set_flash('success', 'Setor criado com sucesso!');
    } catch (Exception $ex) {
        log_error('admin_sector_store', $ex);
        set_flash('error', 'Erro ao criar setor.');
    }
    _admin_back('sectors');
}

function admin_sector_update($param = null) {
    core_require('sectors.edit');
    if (!is_post()) _admin_back('sectors');
    csrf_validate();

    $id   = sanitize_int(input('id'));
    $name = clean(input('name'));
    $code = clean(input('code')) ?: null;
    $desc = clean(input('description'));
    $active = sanitize_int(input('is_active', 1)) ? 1 : 0;

    $sector = $id ? sector_find($id) : null;
    if (!$sector || (int) $sector['hospital_id'] !== get_hospital_id() || $name === '') {
        set_flash('error', 'Dados inválidos.');
        _admin_back('sectors');
    }

    try {
        sector_update($id, $name, $code, $desc, $active);
        // Se o setor em foco foi renomeado/desativado, atualiza o contexto
        if (get_sector_id() === $id) {
            switch_sector_context($active ? $id : 0, $active ? $name : '');
        }
        audit_log('sector_updated', "id=$id");
        set_flash('success', 'Setor atualizado.');
    } catch (Exception $ex) {
        log_error('admin_sector_update', $ex);
        set_flash('error', 'Erro ao atualizar setor.');
    }
    _admin_back('sectors');
}

function admin_sector_delete($param = null) {
    core_require('sectors.delete');
    if (!is_post()) _admin_back('sectors');
    csrf_validate();

    $id = sanitize_int(input('id'));
    $sector = $id ? sector_find($id) : null;
    if (!$sector || (int) $sector['hospital_id'] !== get_hospital_id()) {
        set_flash('error', 'Setor não encontrado.');
        _admin_back('sectors');
    }
    try {
        sector_soft_delete($id);
        if (get_sector_id() === $id) switch_sector_context(0, '');
        audit_log('sector_deleted', "id=$id");
        set_flash('success', 'Setor removido.');
    } catch (Exception $ex) {
        log_error('admin_sector_delete', $ex);
        set_flash('error', 'Erro ao remover setor.');
    }
    _admin_back('sectors');
}

/**
 * Grava a lista completa de usuários do setor. Vem da tela acima: os
 * marcados ficam, os desmarcados saem — por isso a ausência do campo
 * significa "nenhum usuário", e não "não mexer".
 */
function admin_sector_users_save($param = null) {
    core_require('sectors.assign');
    if (!is_post()) _admin_back('sectors');
    csrf_validate();

    $sector_id = sanitize_int(input('sector_id'));
    $sector    = $sector_id ? sector_find($sector_id) : null;
    if (!$sector || (int) $sector['hospital_id'] !== get_hospital_id()) {
        set_flash('error', 'Setor não encontrado.');
        _admin_back('sectors');
    }

    $ids = $_POST['user_ids'] ?? [];
    if (!is_array($ids)) $ids = [];

    try {
        $r = sector_set_users($sector_id, $ids);
        audit_log('sector_users_saved', "sector=$sector_id, add={$r['added']}, remove={$r['removed']}");
        set_flash('success', sprintf(
            'Usuários do setor atualizados (%d incluído(s), %d removido(s)).',
            $r['added'], $r['removed']
        ));
    } catch (Exception $ex) {
        log_error('admin_sector_users_save', $ex);
        set_flash('error', 'Erro ao gravar os usuários do setor.');
    }
    core_redirect(core_admin_url('documentos', 'sector_users', ['sector' => $sector_id]));
}

// ═══════════════════════════════════════════════════════════════════════════
//  CATEGORIAS DE DOCUMENTOS (POST)
// ═══════════════════════════════════════════════════════════════════════════

function admin_category_store($param = null) {
    core_require('categories.create');
    if (!is_post()) _admin_back('categories');
    csrf_validate();

    $name = clean(input('name'));
    if (empty($name)) {
        set_flash('error', 'Nome da categoria é obrigatório.');
        _admin_back('categories');
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
    _admin_back('categories');
}

function admin_category_update($param = null) {
    core_require('categories.edit');
    if (!is_post()) _admin_back('categories');
    csrf_validate();

    $id   = sanitize_int(input('id'));
    $name = clean(input('name'));
    if (!$id || empty($name)) {
        set_flash('error', 'Dados inválidos.');
        _admin_back('categories');
    }

    try {
        document_category_update($id, [
            'name'        => $name,
            'description' => clean(input('description')) ?: null,
            'icon'        => clean(input('icon')) ?: null,
            'sort_order'  => sanitize_int(input('sort_order', 0)),
            'is_active'   => sanitize_int(input('is_active', 1)) ? 1 : 0,
        ]);
        audit_log('category_updated', "id=$id");
        set_flash('success', 'Categoria atualizada.');
    } catch (Exception $ex) {
        log_error('admin_category_update', $ex);
        set_flash('error', 'Erro ao atualizar categoria.');
    }
    _admin_back('categories');
}

function admin_category_delete($param = null) {
    core_require('categories.delete');
    if (!is_post()) _admin_back('categories');
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
    _admin_back('categories');
}
