<?php
/**
 * AÇÕES DE CONFIGURAÇÃO DO MÓDULO (setores e categorias de equipamentos)
 *
 * Usadas pelo painel na Administração central (admin_panel.php) e pela
 * rota legada ?page=admin (pages/admin.php), que só processa POST.
 * Cada ação exige a micropermissão específica (sectors.* / categories.*).
 */

/** Setores da unidade (com contagem de equipamentos vinculados). */
function manSectorsWithCounts(int $hid): array
{
    $st = db()->prepare("
        SELECT s.*, (SELECT COUNT(*) FROM man_equipment e WHERE e.sector_id = s.id) AS equipment_count
        FROM man_sectors s
        WHERE s.hospital_id = ?
        ORDER BY s.name
    ");
    $st->execute([$hid]);
    return $st->fetchAll();
}

/** Categorias de equipamentos (com contagem de equipamentos vinculados). */
function manCategoriesWithCounts(int $hid): array
{
    $st = db()->prepare("
        SELECT c.*, (SELECT COUNT(*) FROM man_equipment e WHERE e.category_id = c.id) AS equipment_count
        FROM man_equipment_categories c
        WHERE c.hospital_id = ?
        ORDER BY c.name
    ");
    $st->execute([$hid]);
    return $st->fetchAll();
}

/** A linha (setor/categoria) existe nesta unidade? */
function manAdminRowExists(string $table, int $id, int $hid): bool
{
    if ($id <= 0 || !in_array($table, ['man_sectors', 'man_equipment_categories'], true)) {
        return false;
    }
    $st = db()->prepare("SELECT 1 FROM {$table} WHERE id = ? AND hospital_id = ?");
    $st->execute([$id, $hid]);
    return (bool) $st->fetchColumn();
}

/**
 * Processa uma ação POST de configuração. Devolve a aba do painel para a
 * qual redirecionar ('sectors' | 'categories') ou null se a ação é
 * desconhecida. O CSRF deve ter sido validado pelo chamador.
 */
function manAdminHandlePost(string $act): ?string
{
    $hid = hospitalId();

    switch ($act) {
        // ---------------- SETORES ----------------
        case 'add_sector':
            core_require('sectors.create');
            $name = trim($_POST['sector_name'] ?? '');
            $desc = trim($_POST['sector_desc'] ?? '') ?: null;
            if ($name === '') {
                flash('error', 'Informe o nome do setor.');
            } else {
                db()->prepare("INSERT INTO man_sectors (hospital_id, name, description) VALUES (?, ?, ?)")->execute([$hid, $name, $desc]);
                auditLog('create', 'man_sectors', (int) db()->lastInsertId());
                flash('success', 'Setor adicionado!');
            }
            return 'sectors';

        case 'edit_sector':
            core_require('sectors.edit');
            $id     = (int) ($_POST['sector_id'] ?? 0);
            $name   = trim($_POST['sector_name'] ?? '');
            $desc   = trim($_POST['sector_desc'] ?? '') ?: null;
            $status = ($_POST['sector_status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            if ($name === '') {
                flash('error', 'Informe o nome do setor.');
            } else {
                $st = db()->prepare("UPDATE man_sectors SET name=?, description=?, status=? WHERE id=? AND hospital_id=?");
                $st->execute([$name, $desc, $status, $id, $hid]);
                if (!manAdminRowExists('man_sectors', $id, $hid)) {
                    flash('error', 'Setor não encontrado.');
                } else {
                    auditLog('update', 'man_sectors', $id);
                    flash('success', 'Setor atualizado!');
                }
            }
            return 'sectors';

        case 'delete_sector':
            core_require('sectors.delete');
            $id = (int) ($_POST['sector_id'] ?? 0);
            $st = db()->prepare("SELECT COUNT(*) FROM man_equipment WHERE sector_id = ? AND hospital_id = ?");
            $st->execute([$id, $hid]);
            $inUse = (int) $st->fetchColumn();
            if ($inUse > 0) {
                flash('error', "Este setor possui {$inUse} equipamento(s) vinculado(s). Transfira-os para outro setor ou marque o setor como inativo.");
            } else {
                $st = db()->prepare("DELETE FROM man_sectors WHERE id = ? AND hospital_id = ?");
                $st->execute([$id, $hid]);
                if ($st->rowCount() > 0) {
                    auditLog('delete', 'man_sectors', $id);
                    flash('success', 'Setor removido.');
                } else {
                    flash('error', 'Setor não encontrado.');
                }
            }
            return 'sectors';

        // ---------------- CATEGORIAS ----------------
        case 'add_category':
            core_require('categories.create');
            $name = trim($_POST['cat_name'] ?? '');
            $desc = trim($_POST['cat_desc'] ?? '') ?: null;
            if ($name === '') {
                flash('error', 'Informe o nome da categoria.');
            } else {
                db()->prepare("INSERT INTO man_equipment_categories (hospital_id, name, description) VALUES (?, ?, ?)")->execute([$hid, $name, $desc]);
                auditLog('create', 'man_equipment_categories', (int) db()->lastInsertId());
                flash('success', 'Categoria adicionada.');
            }
            return 'categories';

        case 'edit_category':
            core_require('categories.edit');
            $id   = (int) ($_POST['cat_id'] ?? 0);
            $name = trim($_POST['cat_name'] ?? '');
            $desc = trim($_POST['cat_desc'] ?? '') ?: null;
            if ($name === '') {
                flash('error', 'Informe o nome da categoria.');
            } else {
                db()->prepare("UPDATE man_equipment_categories SET name = ?, description = ? WHERE id = ? AND hospital_id = ?")->execute([$name, $desc, $id, $hid]);
                if (!manAdminRowExists('man_equipment_categories', $id, $hid)) {
                    flash('error', 'Categoria não encontrada.');
                } else {
                    auditLog('update', 'man_equipment_categories', $id);
                    flash('success', 'Categoria atualizada.');
                }
            }
            return 'categories';

        case 'delete_category':
            core_require('categories.delete');
            $id = (int) ($_POST['cat_id'] ?? 0);
            $st = db()->prepare("SELECT COUNT(*) FROM man_equipment WHERE category_id = ? AND hospital_id = ?");
            $st->execute([$id, $hid]);
            $inUse = (int) $st->fetchColumn();
            if ($inUse > 0) {
                flash('error', "Esta categoria está em uso por {$inUse} equipamento(s) e não pode ser excluída.");
            } else {
                $st = db()->prepare("DELETE FROM man_equipment_categories WHERE id = ? AND hospital_id = ?");
                $st->execute([$id, $hid]);
                if ($st->rowCount() > 0) {
                    auditLog('delete', 'man_equipment_categories', $id);
                    flash('success', 'Categoria removida.');
                } else {
                    flash('error', 'Categoria não encontrada.');
                }
            }
            return 'categories';
    }
    return null;
}
