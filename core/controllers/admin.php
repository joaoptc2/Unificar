<?php
/**
 * Administração central da plataforma (somente admins globais):
 *  - usuários (CRUD) e suas micropermissões;
 *  - grupos de usuários (permissões replicadas aos membros);
 *  - configurações gerais e log de auditoria unificado.
 * Rotas: index.php?m=admin&a=<ação>
 */

declare(strict_types=1);

use Core\Audit;
use Core\Auth;
use Core\Csrf;
use Core\DB;
use Core\Flash;
use Core\Layout;
use Core\Modules;
use Core\Perms;
use Core\Settings;

Auth::requireGlobalAdmin();

$action = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_GET['a'] ?? 'index')));

function admin_sidebar(): array
{
    return [[
        'heading' => 'Administração',
        'items'   => [
            ['label' => 'Visão geral', 'url' => core_module_url('admin'), 'icon' => 'bi-speedometer2', 'key' => 'index'],
            ['label' => 'Usuários', 'url' => core_module_url('admin', ['a' => 'users']), 'icon' => 'bi-people', 'key' => 'users'],
            ['label' => 'Grupos de permissões', 'url' => core_module_url('admin', ['a' => 'groups']), 'icon' => 'bi-diagram-3', 'key' => 'groups'],
            ['label' => 'Módulos', 'url' => core_module_url('admin', ['a' => 'modules']), 'icon' => 'bi-grid', 'key' => 'modules'],
            ['label' => 'Configurações', 'url' => core_module_url('admin', ['a' => 'settings']), 'icon' => 'bi-sliders', 'key' => 'settings'],
            ['label' => 'Auditoria', 'url' => core_module_url('admin', ['a' => 'audit']), 'icon' => 'bi-journal-text', 'key' => 'audit'],
        ],
    ]];
}

function admin_render(string $title, string $content, string $active): void
{
    Layout::render([
        'title'   => $title,
        'content' => $content,
        'module'  => null,
        'sidebar' => admin_sidebar(),
        'active'  => $active,
    ]);
}

/**
 * Árvore de micropermissões (todos os módulos) com checkboxes.
 *
 * @param array $checked   chaves efetivamente marcadas: [module => [key => true]]
 * @param array $inherited chaves herdadas de grupos (editor de usuário): [module => [key => true]]
 * @param bool  $showInheritance exibe badges de herança (editor de usuário)
 */
function admin_perm_tree(array $checked, array $inherited = [], bool $showInheritance = false): string
{
    ob_start(); ?>
    <?php foreach (Modules::all() as $slug => $manifest):
        $catalog = Perms::catalog($slug);
        if (!$catalog) {
            continue;
        }
        $presets = (array) ($manifest['presets'] ?? []); ?>
        <div class="card mb-3 perm-module" data-module="<?= core_e($slug) ?>">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <strong class="me-auto"><i class="bi <?= core_e($manifest['icon'] ?? 'bi-app') ?> me-1"></i><?= core_e($manifest['name']) ?></strong>
                <?php foreach ($presets as $presetKey => $preset):
                    $keys = Perms::expand($slug, (array) ($preset['keys'] ?? [])); ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary preset-btn"
                            data-keys='<?= core_e(json_encode($keys)) ?>'>
                        <i class="bi bi-magic me-1"></i><?= core_e($preset['label'] ?? $presetKey) ?>
                    </button>
                <?php endforeach; ?>
                <button type="button" class="btn btn-sm btn-outline-primary check-all">Marcar tudo</button>
                <button type="button" class="btn btn-sm btn-outline-secondary uncheck-all">Limpar</button>
            </div>
            <div class="card-body py-2">
                <div class="row">
                <?php foreach ($catalog as $resource => $def): ?>
                    <div class="col-12 col-md-6 col-xl-4 py-2 perm-resource">
                        <div class="fw-semibold small text-uppercase text-muted mb-1 d-flex align-items-center">
                            <?= core_e($def['label'] ?? $resource) ?>
                            <a href="#" class="ms-2 small fw-normal text-decoration-none res-all">todas</a>
                        </div>
                        <?php foreach (($def['actions'] ?? []) as $act => $actLabel):
                            $key   = $resource . '.' . $act;
                            $isChk = isset($checked[$slug][$key]);
                            $isInh = isset($inherited[$slug][$key]); ?>
                            <div class="form-check form-check-inline me-3">
                                <input class="form-check-input" type="checkbox" id="p_<?= core_e($slug . '_' . $resource . '_' . $act) ?>"
                                       name="perms[<?= core_e($slug) ?>][]" value="<?= core_e($key) ?>"
                                       <?= $isChk ? 'checked' : '' ?> <?= $isInh ? 'data-inherited="1"' : '' ?>>
                                <label class="form-check-label" for="p_<?= core_e($slug . '_' . $resource . '_' . $act) ?>">
                                    <?= core_e($actLabel) ?>
                                    <?php if ($showInheritance && $isInh): ?>
                                        <i class="bi bi-diagram-3 text-info" title="Herdada de grupo"></i>
                                    <?php endif; ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <!-- garante que o módulo apareça no POST mesmo sem nenhuma caixa marcada -->
            <input type="hidden" name="perms[<?= core_e($slug) ?>][]" value="">
        </div>
    <?php endforeach; ?>
    <?php if ($showInheritance): ?>
        <p class="small text-muted"><i class="bi bi-diagram-3 text-info"></i> = herdada de grupo.
            Desmarcar uma permissão herdada cria uma exceção individual (negação) para este usuário;
            marcar uma não herdada cria uma concessão individual.</p>
    <?php endif; ?>
    <script>
    document.addEventListener('click', function (e) {
        var mod = e.target.closest('.perm-module');
        if (e.target.closest('.check-all')) {
            mod.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = true; });
        } else if (e.target.closest('.uncheck-all')) {
            mod.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; });
        } else if (e.target.closest('.preset-btn')) {
            var keys = JSON.parse(e.target.closest('.preset-btn').dataset.keys || '[]');
            mod.querySelectorAll('input[type=checkbox]').forEach(function (c) {
                if (c.value) c.checked = keys.indexOf(c.value) !== -1;
            });
        } else if (e.target.closest('.res-all')) {
            e.preventDefault();
            e.target.closest('.perm-resource').querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = true; });
        }
    });
    </script>
    <?php
    return (string) ob_get_clean();
}

/** Lê o POST perms[module][] e devolve [module => keys[]] saneado. */
function admin_read_perms_post(): array
{
    $out = [];
    foreach ((array) ($_POST['perms'] ?? []) as $slug => $keys) {
        $slug = (string) $slug;
        if (!Modules::manifest($slug)) {
            continue;
        }
        $out[$slug] = array_values(array_filter(array_map('strval', (array) $keys), fn ($k) => $k !== ''));
    }
    return $out;
}

switch ($action) {

    case 'index':
        $stats = [
            'users'   => (int) (DB::queryOne('SELECT COUNT(*) n FROM users')['n'] ?? 0),
            'active'  => (int) (DB::queryOne('SELECT COUNT(*) n FROM users WHERE active = 1')['n'] ?? 0),
            'groups'  => (int) (DB::queryOne('SELECT COUNT(*) n FROM user_groups')['n'] ?? 0),
            'moodle'  => (int) (DB::queryOne('SELECT COUNT(*) n FROM users WHERE auth_source = "moodle"')['n'] ?? 0),
        ];
        $recent = DB::query('SELECT a.*, u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 12');
        ob_start(); ?>
        <h1 class="h4 mb-3"><i class="bi bi-gear me-2"></i>Administração da plataforma</h1>
        <div class="row g-3 mb-4">
            <?php foreach ([
                ['Usuários', $stats['users'], 'bi-people', 'primary'],
                ['Ativos', $stats['active'], 'bi-person-check', 'success'],
                ['Grupos', $stats['groups'], 'bi-diagram-3', 'info'],
                ['Contas Moodle', $stats['moodle'], 'bi-mortarboard', 'secondary'],
            ] as [$label, $value, $icon, $color]): ?>
                <div class="col-6 col-lg-3">
                    <div class="card h-100">
                        <div class="card-body d-flex align-items-center gap-3">
                            <span class="fs-3 text-<?= $color ?>"><i class="bi <?= $icon ?>"></i></span>
                            <div>
                                <div class="fs-4 fw-semibold"><?= (int) $value ?></div>
                                <div class="small text-muted"><?= core_e($label) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <div class="card-header">Atividade recente</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Quando</th><th>Usuário</th><th>Módulo</th><th>Ação</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td class="text-nowrap"><?= core_e(date('d/m H:i', strtotime((string) $r['created_at']))) ?></td>
                            <td><?= core_e($r['user_name'] ?? '—') ?></td>
                            <td><span class="badge text-bg-light border"><?= core_e($r['module'] ?? 'core') ?></span></td>
                            <td><?= core_e($r['action']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        admin_render('Administração', (string) ob_get_clean(), 'index');
        break;

    // ================= USUÁRIOS =================

    case 'users':
        $q   = trim((string) ($_GET['q'] ?? ''));
        $sql = 'SELECT * FROM users';
        $par = [];
        if ($q !== '') {
            $sql .= ' WHERE name LIKE ? OR email LIKE ? OR username LIKE ?';
            $par  = ["%{$q}%", "%{$q}%", "%{$q}%"];
        }
        $users   = DB::query($sql . ' ORDER BY name LIMIT 500', $par);
        $modules = Modules::all();
        $groupsByUser = [];
        foreach (DB::query('SELECT m.user_id, g.name FROM user_group_members m JOIN user_groups g ON g.id = m.group_id') as $row) {
            $groupsByUser[(int) $row['user_id']][] = $row['name'];
        }
        ob_start(); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0"><i class="bi bi-people me-2"></i>Usuários</h1>
            <a class="btn btn-primary" href="<?= core_module_url('admin', ['a' => 'user_form']) ?>"><i class="bi bi-plus-lg me-1"></i>Novo usuário</a>
        </div>
        <form class="mb-3" method="get">
            <input type="hidden" name="m" value="admin"><input type="hidden" name="a" value="users">
            <div class="input-group" style="max-width: 420px">
                <input class="form-control" name="q" value="<?= core_e($q) ?>" placeholder="Buscar por nome, e-mail ou usuário">
                <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
            </div>
        </form>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Usuário</th>
                            <th>Grupos</th>
                            <?php foreach ($modules as $slug => $m): ?>
                                <th class="text-center small" title="<?= core_e($m['name']) ?>"><i class="bi <?= core_e($m['icon'] ?? '') ?>"></i></th>
                            <?php endforeach; ?>
                            <th class="text-center">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= core_e($u['name']) ?>
                                    <?php if ($u['is_admin']): ?><span class="badge text-bg-primary">Admin</span><?php endif; ?>
                                    <?php if ($u['auth_source'] === 'moodle'): ?><span class="badge text-bg-info" title="Autentica via Moodle"><i class="bi bi-mortarboard"></i></span><?php endif; ?>
                                </div>
                                <div class="small text-muted"><?= core_e($u['email']) ?></div>
                            </td>
                            <td>
                                <?php foreach ($groupsByUser[(int) $u['id']] ?? [] as $gName): ?>
                                    <span class="badge text-bg-light border"><?= core_e($gName) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <?php foreach ($modules as $slug => $m):
                                $n = $u['is_admin'] ? count(Perms::allKeys($slug)) : count(Perms::effective((int) $u['id'], $slug)); ?>
                                <td class="text-center">
                                    <?php if ($n > 0): ?>
                                        <span class="badge text-bg-success" title="<?= $n ?> permissões"><?= $n ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-center">
                                <?php if ($u['active']): ?>
                                    <span class="badge text-bg-success">ativo</span>
                                <?php else: ?>
                                    <span class="badge text-bg-danger">inativo</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" title="Permissões"
                                   href="<?= core_module_url('admin', ['a' => 'user_perms', 'id' => $u['id']]) ?>"><i class="bi bi-shield-check"></i></a>
                                <a class="btn btn-sm btn-outline-primary" title="Editar"
                                   href="<?= core_module_url('admin', ['a' => 'user_form', 'id' => $u['id']]) ?>"><i class="bi bi-pencil"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        admin_render('Usuários', (string) ob_get_clean(), 'users');
        break;

    case 'user_form':
        $id     = (int) ($_GET['id'] ?? 0);
        $user   = $id ? DB::queryOne('SELECT * FROM users WHERE id = ?', [$id]) : null;
        $groups = DB::query('SELECT * FROM user_groups ORDER BY name');
        $memberOf = $id
            ? array_map('intval', array_column(DB::query('SELECT group_id FROM user_group_members WHERE user_id = ?', [$id]), 'group_id'))
            : [];
        ob_start(); ?>
        <h1 class="h4 mb-3"><i class="bi bi-person-gear me-2"></i><?= $user ? 'Editar usuário' : 'Novo usuário' ?></h1>
        <form method="post" action="<?= core_module_url('admin', ['a' => 'user_save']) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) ($user['id'] ?? 0) ?>">
            <div class="row g-3">
                <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">Dados do usuário</div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Nome *</label>
                                <input class="form-control" name="name" value="<?= core_e($user['name'] ?? '') ?>" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Usuário *</label>
                                    <input class="form-control" name="username" value="<?= core_e($user['username'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">E-mail *</label>
                                    <input type="email" class="form-control" name="email" value="<?= core_e($user['email'] ?? '') ?>" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Senha <?= $user ? '(em branco = não alterar)' : '*' ?></label>
                                <input type="password" class="form-control" name="password" minlength="8" <?= $user ? '' : 'required' ?>>
                                <?php if (($user['auth_source'] ?? '') === 'moodle'): ?>
                                    <div class="form-text"><i class="bi bi-mortarboard me-1"></i>Conta vinculada ao Moodle — senha local é opcional.</div>
                                <?php endif; ?>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="force_password_change" id="fpc" value="1" <?= !empty($user['force_password_change']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="fpc">Exigir troca de senha no próximo login</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="active" id="act" value="1" <?= ($user['active'] ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="act">Ativo</label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_admin" id="adm" value="1" <?= !empty($user['is_admin']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="adm">Administrador global <span class="text-muted small">(todas as permissões em todos os módulos)</span></label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">Grupos</div>
                        <div class="card-body">
                            <p class="small text-muted">O usuário herda todas as permissões dos grupos marcados.
                                Ajustes finos são feitos em <strong>Permissões</strong> após salvar.</p>
                            <?php if (!$groups): ?>
                                <p class="text-muted small">Nenhum grupo criado ainda —
                                    <a href="<?= core_module_url('admin', ['a' => 'group_form']) ?>">criar grupo</a>.</p>
                            <?php endif; ?>
                            <?php foreach ($groups as $g): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="groups[]" value="<?= (int) $g['id'] ?>"
                                           id="g<?= (int) $g['id'] ?>" <?= in_array((int) $g['id'], $memberOf, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="g<?= (int) $g['id'] ?>">
                                        <?= core_e($g['name']) ?>
                                        <?php if ($g['description']): ?><span class="text-muted small">— <?= core_e($g['description']) ?></span><?php endif; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($user): ?>
                                <hr>
                                <a class="btn btn-outline-secondary btn-sm" href="<?= core_module_url('admin', ['a' => 'user_perms', 'id' => $user['id']]) ?>">
                                    <i class="bi bi-shield-check me-1"></i>Editar permissões individuais
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar</button>
                <a class="btn btn-outline-secondary" href="<?= core_module_url('admin', ['a' => 'users']) ?>">Cancelar</a>
            </div>
        </form>
        <?php if ($user && (int) $user['id'] !== (int) Auth::id()): ?>
            <form method="post" action="<?= core_module_url('admin', ['a' => 'user_delete']) ?>" class="mt-2"
                  onsubmit="return confirm('Desativar este usuário?')">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                <button class="btn btn-outline-danger btn-sm"><i class="bi bi-person-x me-1"></i>Desativar usuário</button>
            </form>
        <?php endif; ?>
        <?php
        admin_render($user ? 'Editar usuário' : 'Novo usuário', (string) ob_get_clean(), 'users');
        break;

    case 'user_save':
        Csrf::check();
        $id       = (int) ($_POST['id'] ?? 0);
        $name     = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $isAdmin  = !empty($_POST['is_admin']) ? 1 : 0;
        $active   = !empty($_POST['active']) ? 1 : 0;
        $forcePw  = !empty($_POST['force_password_change']) ? 1 : 0;

        if ($name === '' || $username === '' || $email === '') {
            Flash::set('error', 'Preencha nome, usuário e e-mail.');
            core_redirect('index.php?m=admin&a=users');
        }

        $dupe = DB::queryOne('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ?', [$username, $email, $id]);
        if ($dupe) {
            Flash::set('error', 'Já existe um usuário com esse e-mail ou nome de usuário.');
            core_redirect('index.php?m=admin&a=' . ($id ? 'user_form&id=' . $id : 'user_form'));
        }

        if ($id) {
            DB::execute(
                'UPDATE users SET name = ?, username = ?, email = ?, is_admin = ?, active = ?, force_password_change = ? WHERE id = ?',
                [$name, $username, $email, $isAdmin, $active, $forcePw, $id]
            );
            if ($password !== '') {
                DB::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $id]);
            }
            Audit::log('user.update', 'users', (string) $id, null, null, 'admin');
        } else {
            if ($password === '') {
                Flash::set('error', 'Defina uma senha inicial.');
                core_redirect('index.php?m=admin&a=user_form');
            }
            DB::execute(
                'INSERT INTO users (name, username, email, password_hash, is_admin, active, force_password_change) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$name, $username, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $isAdmin, $active, $forcePw]
            );
            $id = DB::lastId();
            Audit::log('user.create', 'users', (string) $id, null, null, 'admin');
        }

        // Grupos
        $wanted = array_map('intval', (array) ($_POST['groups'] ?? []));
        DB::execute('DELETE FROM user_group_members WHERE user_id = ?', [$id]);
        foreach (array_unique($wanted) as $gid) {
            if ($gid > 0 && DB::queryOne('SELECT id FROM user_groups WHERE id = ?', [$gid])) {
                DB::execute('INSERT INTO user_group_members (group_id, user_id) VALUES (?, ?)', [$gid, $id]);
            }
        }
        Perms::flush();

        Flash::set('success', 'Usuário salvo.');
        core_redirect('index.php?m=admin&a=' . ((int) ($_POST['id'] ?? 0) === 0 ? 'user_perms&id=' . $id : 'users'));
        break;

    case 'user_delete':
        Csrf::check();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id && $id !== (int) Auth::id()) {
            DB::execute('UPDATE users SET active = 0 WHERE id = ?', [$id]);
            Audit::log('user.deactivate', 'users', (string) $id, null, null, 'admin');
            Flash::set('success', 'Usuário desativado.');
        }
        core_redirect('index.php?m=admin&a=users');
        break;

    case 'user_perms':
        $id   = (int) ($_GET['id'] ?? 0);
        $user = DB::queryOne('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$user) {
            core_redirect('index.php?m=admin&a=users');
        }
        $checked = $inherited = [];
        foreach (array_keys(Modules::all()) as $slug) {
            $checked[$slug]   = Perms::effective($id, $slug);
            $inherited[$slug] = Perms::inheritedFor($id, $slug);
        }
        $groupNames = array_column(
            DB::query('SELECT g.name FROM user_groups g JOIN user_group_members m ON m.group_id = g.id WHERE m.user_id = ?', [$id]),
            'name'
        );
        ob_start(); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0"><i class="bi bi-shield-check me-2"></i>Permissões — <?= core_e($user['name']) ?></h1>
            <a class="btn btn-outline-secondary btn-sm" href="<?= core_module_url('admin', ['a' => 'users']) ?>">Voltar</a>
        </div>
        <?php if (!empty($user['is_admin'])): ?>
            <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>
                Este usuário é <strong>administrador global</strong> e possui todas as permissões automaticamente —
                a matriz abaixo não se aplica enquanto essa opção estiver ativa.</div>
        <?php endif; ?>
        <?php if ($groupNames): ?>
            <p class="small text-muted">Grupos do usuário:
                <?php foreach ($groupNames as $gn): ?><span class="badge text-bg-light border"><?= core_e($gn) ?></span><?php endforeach; ?>
            </p>
        <?php endif; ?>
        <form method="post" action="<?= core_module_url('admin', ['a' => 'user_perms_save']) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
            <?= admin_perm_tree($checked, $inherited, true) ?>
            <div class="d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar permissões</button>
                <a class="btn btn-outline-secondary" href="<?= core_module_url('admin', ['a' => 'users']) ?>">Cancelar</a>
            </div>
        </form>
        <?php
        admin_render('Permissões do usuário', (string) ob_get_clean(), 'users');
        break;

    case 'user_perms_save':
        Csrf::check();
        $id = (int) ($_POST['id'] ?? 0);
        if (!DB::queryOne('SELECT id FROM users WHERE id = ?', [$id])) {
            core_redirect('index.php?m=admin&a=users');
        }
        foreach (admin_read_perms_post() as $slug => $keys) {
            Perms::setUserGrants($id, $slug, $keys, Auth::id());
        }
        Audit::log('perms.user_update', 'users', (string) $id, null, null, 'admin');
        Flash::set('success', 'Permissões do usuário atualizadas.');
        core_redirect('index.php?m=admin&a=user_perms&id=' . $id);
        break;

    // ================= GRUPOS =================

    case 'groups':
        $groups = DB::query(
            'SELECT g.*, (SELECT COUNT(*) FROM user_group_members m WHERE m.group_id = g.id) AS members,
                    (SELECT COUNT(*) FROM permission_grants p WHERE p.subject_type = "group" AND p.subject_id = g.id AND p.allowed = 1) AS grants_n
             FROM user_groups g ORDER BY g.name'
        );
        ob_start(); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0"><i class="bi bi-diagram-3 me-2"></i>Grupos de permissões</h1>
            <a class="btn btn-primary" href="<?= core_module_url('admin', ['a' => 'group_form']) ?>"><i class="bi bi-plus-lg me-1"></i>Novo grupo</a>
        </div>
        <p class="text-muted small">Defina as permissões uma vez no grupo e todos os membros as herdam.
            Exceções individuais podem ser feitas na tela de permissões de cada usuário.</p>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead><tr><th>Grupo</th><th class="text-center">Membros</th><th class="text-center">Permissões</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$groups): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">Nenhum grupo criado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($groups as $g): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= core_e($g['name']) ?></div>
                                <div class="small text-muted"><?= core_e($g['description'] ?? '') ?></div>
                            </td>
                            <td class="text-center"><span class="badge text-bg-secondary"><?= (int) $g['members'] ?></span></td>
                            <td class="text-center"><span class="badge text-bg-light border"><?= (int) $g['grants_n'] ?></span></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="<?= core_module_url('admin', ['a' => 'group_form', 'id' => $g['id']]) ?>"><i class="bi bi-pencil"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        admin_render('Grupos', (string) ob_get_clean(), 'groups');
        break;

    case 'group_form':
        $id    = (int) ($_GET['id'] ?? 0);
        $group = $id ? DB::queryOne('SELECT * FROM user_groups WHERE id = ?', [$id]) : null;
        $checked = [];
        if ($group) {
            foreach (Perms::grantsOf('group', $id) as $slug => $keys) {
                foreach ($keys as $key => $allowed) {
                    if ($allowed) {
                        $checked[$slug][$key] = true;
                    }
                }
            }
        }
        $allUsers = DB::query('SELECT id, name, email FROM users WHERE active = 1 ORDER BY name');
        $memberIds = $group
            ? array_map('intval', array_column(DB::query('SELECT user_id FROM user_group_members WHERE group_id = ?', [$id]), 'user_id'))
            : [];
        ob_start(); ?>
        <h1 class="h4 mb-3"><i class="bi bi-diagram-3 me-2"></i><?= $group ? 'Editar grupo' : 'Novo grupo' ?></h1>
        <form method="post" action="<?= core_module_url('admin', ['a' => 'group_save']) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) ($group['id'] ?? 0) ?>">
            <div class="row g-3 mb-3">
                <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">Dados do grupo</div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Nome *</label>
                                <input class="form-control" name="name" value="<?= core_e($group['name'] ?? '') ?>" required
                                       placeholder="ex.: Gestores de RH">
                            </div>
                            <div class="mb-0">
                                <label class="form-label">Descrição</label>
                                <input class="form-control" name="description" value="<?= core_e($group['description'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-header d-flex align-items-center">
                            Membros
                            <input class="form-control form-control-sm ms-auto" style="max-width:220px" placeholder="filtrar..."
                                   oninput="var f=this.value.toLowerCase();document.querySelectorAll('#memberList .form-check').forEach(function(d){d.style.display=d.textContent.toLowerCase().indexOf(f)!==-1?'':'none';});">
                        </div>
                        <div class="card-body" id="memberList" style="max-height: 240px; overflow-y: auto">
                            <?php foreach ($allUsers as $u): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="members[]" value="<?= (int) $u['id'] ?>"
                                           id="mu<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $memberIds, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="mu<?= (int) $u['id'] ?>">
                                        <?= core_e($u['name']) ?> <span class="text-muted small"><?= core_e($u['email']) ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <h2 class="h6 text-uppercase text-muted">Permissões do grupo</h2>
            <?= admin_perm_tree($checked) ?>
            <div class="d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar grupo</button>
                <a class="btn btn-outline-secondary" href="<?= core_module_url('admin', ['a' => 'groups']) ?>">Cancelar</a>
            </div>
        </form>
        <?php if ($group): ?>
            <form method="post" action="<?= core_module_url('admin', ['a' => 'group_delete']) ?>" class="mt-2"
                  onsubmit="return confirm('Excluir este grupo? Os membros perdem as permissões herdadas dele.')">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $group['id'] ?>">
                <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Excluir grupo</button>
            </form>
        <?php endif; ?>
        <?php
        admin_render($group ? 'Editar grupo' : 'Novo grupo', (string) ob_get_clean(), 'groups');
        break;

    case 'group_save':
        Csrf::check();
        $id   = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $desc = trim((string) ($_POST['description'] ?? ''));
        if ($name === '') {
            Flash::set('error', 'Informe o nome do grupo.');
            core_redirect('index.php?m=admin&a=groups');
        }
        $dupe = DB::queryOne('SELECT id FROM user_groups WHERE name = ? AND id <> ?', [$name, $id]);
        if ($dupe) {
            Flash::set('error', 'Já existe um grupo com esse nome.');
            core_redirect('index.php?m=admin&a=groups');
        }
        if ($id) {
            DB::execute('UPDATE user_groups SET name = ?, description = ? WHERE id = ?', [$name, $desc, $id]);
        } else {
            DB::execute('INSERT INTO user_groups (name, description) VALUES (?, ?)', [$name, $desc]);
            $id = DB::lastId();
        }

        // Membros
        DB::execute('DELETE FROM user_group_members WHERE group_id = ?', [$id]);
        foreach (array_unique(array_map('intval', (array) ($_POST['members'] ?? []))) as $uid) {
            if ($uid > 0 && DB::queryOne('SELECT id FROM users WHERE id = ?', [$uid])) {
                DB::execute('INSERT INTO user_group_members (group_id, user_id) VALUES (?, ?)', [$id, $uid]);
            }
        }

        // Permissões
        foreach (admin_read_perms_post() as $slug => $keys) {
            Perms::setGroupGrants($id, $slug, $keys, Auth::id());
        }

        Audit::log('perms.group_save', 'user_groups', (string) $id, ['name' => $name], null, 'admin');
        Flash::set('success', 'Grupo salvo.');
        core_redirect('index.php?m=admin&a=group_form&id=' . $id);
        break;

    case 'group_delete':
        Csrf::check();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            DB::execute("DELETE FROM permission_grants WHERE subject_type = 'group' AND subject_id = ?", [$id]);
            DB::execute('DELETE FROM user_groups WHERE id = ?', [$id]);
            Perms::flush();
            Audit::log('perms.group_delete', 'user_groups', (string) $id, null, null, 'admin');
            Flash::set('success', 'Grupo excluído.');
        }
        core_redirect('index.php?m=admin&a=groups');
        break;

    // ================= MÓDULOS / CONFIG / AUDITORIA =================

    case 'modules':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::check();
            foreach ((array) ($_POST['mod'] ?? []) as $slug => $data) {
                DB::execute(
                    'UPDATE modules SET active = ?, sort_order = ? WHERE slug = ?',
                    [!empty($data['active']) ? 1 : 0, (int) ($data['sort'] ?? 0), (string) $slug]
                );
            }
            Flash::set('success', 'Módulos atualizados.');
            core_redirect('index.php?m=admin&a=modules');
        }
        $rows = DB::query('SELECT * FROM modules ORDER BY sort_order');
        ob_start(); ?>
        <h1 class="h4 mb-3"><i class="bi bi-grid me-2"></i>Módulos</h1>
        <form method="post">
            <?= Csrf::field() ?>
            <div class="card">
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead><tr><th>Módulo</th><th style="width:120px">Ordem</th><th class="text-center" style="width:100px">Ativo</th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): $m = Modules::manifest((string) $r['slug']); ?>
                            <tr>
                                <td><i class="bi <?= core_e($r['icon'] ?? '') ?> me-2"></i><?= core_e($r['name']) ?>
                                    <?php if (!$m): ?><span class="badge text-bg-warning">arquivos ausentes</span><?php endif; ?>
                                </td>
                                <td><input type="number" class="form-control form-control-sm" name="mod[<?= core_e($r['slug']) ?>][sort]" value="<?= (int) $r['sort_order'] ?>"></td>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input" name="mod[<?= core_e($r['slug']) ?>][active]" value="1" <?= $r['active'] ? 'checked' : '' ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <button class="btn btn-primary mt-3">Salvar</button>
        </form>
        <?php
        admin_render('Módulos', (string) ob_get_clean(), 'modules');
        break;

    case 'settings':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::check();
            Settings::set('org_name', trim((string) ($_POST['org_name'] ?? '')));
            Settings::set('default_hospital_id', (string) max(1, (int) ($_POST['default_hospital_id'] ?? 1)));
            Flash::set('success', 'Configurações salvas.');
            core_redirect('index.php?m=admin&a=settings');
        }
        ob_start(); ?>
        <h1 class="h4 mb-3"><i class="bi bi-sliders me-2"></i>Configurações</h1>
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <div class="card">
                    <div class="card-header">Geral</div>
                    <div class="card-body">
                        <form method="post">
                            <?= Csrf::field() ?>
                            <div class="mb-3">
                                <label class="form-label">Nome da organização (exibido no topo)</label>
                                <input class="form-control" name="org_name" value="<?= core_e(Settings::get('org_name', core_config('app.name', ''))) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">ID da unidade padrão (módulos multi-unidade legados)</label>
                                <input type="number" class="form-control" name="default_hospital_id" value="<?= core_e(Settings::get('default_hospital_id', '1')) ?>">
                                <div class="form-text">Usado pelos módulos Documentos e Manutenção, que herdaram estrutura multi-unidade.</div>
                            </div>
                            <button class="btn btn-primary">Salvar</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card">
                    <div class="card-header">Integração Moodle</div>
                    <div class="card-body">
                        <?php if (core_config('moodle.enabled')): ?>
                            <p><span class="badge text-bg-success">Habilitada</span></p>
                            <dl class="row small mb-0">
                                <dt class="col-4">URL</dt><dd class="col-8"><?= core_e(core_config('moodle.url')) ?></dd>
                                <dt class="col-4">Serviço</dt><dd class="col-8"><?= core_e(core_config('moodle.service')) ?></dd>
                                <dt class="col-4">Token admin</dt><dd class="col-8"><?= core_config('moodle.admin_token') ? 'configurado' : '—' ?></dd>
                            </dl>
                        <?php else: ?>
                            <p><span class="badge text-bg-secondary">Desabilitada</span></p>
                            <p class="small text-muted mb-0">Habilite em <code>config/config.php</code> (bloco <code>moodle</code>) para permitir login com credenciais do Moodle e auto-provisionamento de usuários.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
        admin_render('Configurações', (string) ob_get_clean(), 'settings');
        break;

    case 'audit':
        $module = trim((string) ($_GET['module'] ?? ''));
        $sql    = 'SELECT a.*, u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id';
        $par    = [];
        if ($module !== '') {
            $sql .= ' WHERE a.module = ?';
            $par[] = $module;
        }
        $rows = DB::query($sql . ' ORDER BY a.id DESC LIMIT 300', $par);
        ob_start(); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0"><i class="bi bi-journal-text me-2"></i>Log de auditoria</h1>
            <form method="get" class="d-flex gap-2">
                <input type="hidden" name="m" value="admin"><input type="hidden" name="a" value="audit">
                <select class="form-select form-select-sm" name="module" onchange="this.form.submit()">
                    <option value="">Todos os módulos</option>
                    <?php foreach (array_merge(['core', 'admin'], array_keys(Modules::all())) as $slug): ?>
                        <option value="<?= core_e($slug) ?>" <?= $module === $slug ? 'selected' : '' ?>><?= core_e($slug) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Quando</th><th>Usuário</th><th>Módulo</th><th>Ação</th><th>Entidade</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="text-nowrap"><?= core_e(date('d/m/Y H:i:s', strtotime((string) $r['created_at']))) ?></td>
                            <td><?= core_e($r['user_name'] ?? '—') ?></td>
                            <td><span class="badge text-bg-light border"><?= core_e($r['module'] ?? 'core') ?></span></td>
                            <td><?= core_e($r['action']) ?></td>
                            <td class="small text-muted"><?= core_e(trim(($r['entity'] ?? '') . ' #' . ($r['entity_id'] ?? ''), ' #')) ?></td>
                            <td class="small text-muted"><?= core_e($r['ip_address']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        admin_render('Auditoria', (string) ob_get_clean(), 'audit');
        break;

    default:
        Layout::renderError(404, 'Ação não encontrada.');
}
