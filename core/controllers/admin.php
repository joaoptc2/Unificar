<?php
/**
 * Administração central da plataforma (somente admins globais):
 *  - usuários (CRUD) com matriz de permissões por módulo;
 *  - configurações gerais;
 *  - log de auditoria unificado.
 * Rotas: index.php?m=admin&a=<ação>
 */

declare(strict_types=1);

use Core\Access;
use Core\Audit;
use Core\Auth;
use Core\Csrf;
use Core\DB;
use Core\Flash;
use Core\Layout;
use Core\Modules;
use Core\Settings;

Auth::requireGlobalAdmin();

$action = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_GET['a'] ?? 'index')));

function admin_sidebar(): array
{
    return [[
        'heading' => 'Administração',
        'items'   => [
            ['label' => 'Visão geral', 'url' => core_module_url('admin'), 'icon' => 'bi-speedometer2', 'key' => 'index'],
            ['label' => 'Usuários e permissões', 'url' => core_module_url('admin', ['a' => 'users']), 'icon' => 'bi-people', 'key' => 'users'],
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

switch ($action) {

    case 'index':
        $stats = [
            'users'   => (int) (DB::queryOne('SELECT COUNT(*) n FROM users')['n'] ?? 0),
            'active'  => (int) (DB::queryOne('SELECT COUNT(*) n FROM users WHERE active = 1')['n'] ?? 0),
            'moodle'  => (int) (DB::queryOne('SELECT COUNT(*) n FROM users WHERE auth_source = "moodle"')['n'] ?? 0),
            'modules' => count(Modules::all()),
        ];
        $recent = DB::query('SELECT a.*, u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 12');
        ob_start(); ?>
        <h1 class="h4 mb-3"><i class="bi bi-gear me-2"></i>Administração da plataforma</h1>
        <div class="row g-3 mb-4">
            <?php foreach ([
                ['Usuários', $stats['users'], 'bi-people', 'primary'],
                ['Ativos', $stats['active'], 'bi-person-check', 'success'],
                ['Contas Moodle', $stats['moodle'], 'bi-mortarboard', 'info'],
                ['Módulos', $stats['modules'], 'bi-grid', 'secondary'],
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

    case 'users':
        $q     = trim((string) ($_GET['q'] ?? ''));
        $sql   = 'SELECT * FROM users';
        $par   = [];
        if ($q !== '') {
            $sql .= ' WHERE name LIKE ? OR email LIKE ? OR username LIKE ?';
            $par  = ["%{$q}%", "%{$q}%", "%{$q}%"];
        }
        $users   = DB::query($sql . ' ORDER BY name LIMIT 500', $par);
        $modules = Modules::all();
        $accessByUser = [];
        foreach (DB::query('SELECT user_id, module_slug, role FROM user_module_access') as $row) {
            $accessByUser[(int) $row['user_id']][$row['module_slug']] = $row['role'];
        }
        ob_start(); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0"><i class="bi bi-people me-2"></i>Usuários e permissões</h1>
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
                            <th class="text-center">Origem</th>
                            <?php foreach ($modules as $slug => $m): ?>
                                <th class="text-center small"><i class="bi <?= core_e($m['icon'] ?? '') ?>"></i> <?= core_e($m['name']) ?></th>
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
                                </div>
                                <div class="small text-muted"><?= core_e($u['email']) ?></div>
                            </td>
                            <td class="text-center">
                                <?php if ($u['auth_source'] === 'moodle'): ?>
                                    <span class="badge text-bg-info" title="Autentica via Moodle"><i class="bi bi-mortarboard"></i></span>
                                <?php else: ?>
                                    <span class="badge text-bg-light border">local</span>
                                <?php endif; ?>
                            </td>
                            <?php foreach ($modules as $slug => $m):
                                $role = $u['is_admin']
                                    ? ($m['admin_role'] ?? 'admin')
                                    : ($accessByUser[(int) $u['id']][$slug] ?? 'none'); ?>
                                <td class="text-center">
                                    <?php if ($role === 'none'): ?>
                                        <span class="text-muted">—</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary"><?= core_e($m['roles'][$role] ?? $role) ?></span>
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
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="<?= core_module_url('admin', ['a' => 'user_form', 'id' => $u['id']]) ?>">
                                    <i class="bi bi-pencil"></i>
                                </a>
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
        $access = $id ? array_column(DB::query('SELECT module_slug, role FROM user_module_access WHERE user_id = ?', [$id]), 'role', 'module_slug') : [];
        $modules = Modules::all();
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
                                <label class="form-check-label" for="adm">Administrador global <span class="text-muted small">(acesso total a todos os módulos e a esta administração)</span></label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">Permissões por módulo</div>
                        <div class="card-body">
                            <p class="small text-muted">Defina o nível de acesso do usuário em cada sistema. Administradores globais ignoram esta matriz.</p>
                            <?php foreach ($modules as $slug => $m): ?>
                                <div class="row align-items-center mb-2">
                                    <label class="col-5 col-form-label">
                                        <i class="bi <?= core_e($m['icon'] ?? '') ?> me-1"></i><?= core_e($m['name']) ?>
                                    </label>
                                    <div class="col-7">
                                        <select class="form-select" name="access[<?= core_e($slug) ?>]">
                                            <option value="none">— sem acesso —</option>
                                            <?php foreach ($m['roles'] ?? [] as $roleKey => $roleLabel): ?>
                                                <option value="<?= core_e($roleKey) ?>" <?= ($access[$slug] ?? 'none') === $roleKey ? 'selected' : '' ?>>
                                                    <?= core_e($roleLabel) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar</button>
                <a class="btn btn-outline-secondary" href="<?= core_module_url('admin', ['a' => 'users']) ?>">Cancelar</a>
                <?php if ($user && (int) $user['id'] !== (int) Auth::id()): ?>
                    <form method="post" action="<?= core_module_url('admin', ['a' => 'user_delete']) ?>" class="ms-auto"
                          onsubmit="return confirm('Desativar este usuário?')">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                        <button class="btn btn-outline-danger"><i class="bi bi-person-x me-1"></i>Desativar</button>
                    </form>
                <?php endif; ?>
            </div>
        </form>
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

        foreach ((array) ($_POST['access'] ?? []) as $slug => $role) {
            $manifest = Modules::manifest((string) $slug);
            if (!$manifest) {
                continue;
            }
            $role = (string) $role;
            if ($role !== 'none' && !isset($manifest['roles'][$role])) {
                continue; // nível inválido para o módulo
            }
            Access::set($id, (string) $slug, $role, Auth::id());
        }

        Flash::set('success', 'Usuário salvo.');
        core_redirect('index.php?m=admin&a=users');
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
