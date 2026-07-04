<?php
/**
 * Controlador do núcleo: portal inicial, login único (local + Moodle),
 * perfil, senha/2FA e notificações unificadas.
 * Rotas: index.php?m=auth&a=<ação>  (m vazio → portal)
 */

declare(strict_types=1);

use Core\Auth;
use Core\Csrf;
use Core\DB;
use Core\Flash;
use Core\Layout;
use Core\Mailer;
use Core\Modules;
use Core\Notifications;
use Core\Settings;
use Core\Totp;

$action = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_GET['a'] ?? '')));
if ($action === '') {
    $action = Auth::check() ? 'portal' : 'login';
}

switch ($action) {

    // ================= LOGIN =================
    case 'login':
        if (Auth::check()) {
            core_redirect('index.php');
        }
        core_auth_render_login();
        break;

    case 'do_login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            core_redirect('index.php?m=auth&a=login');
        }
        Csrf::check();

        $login    = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $result   = Auth::attempt($login, $password);

        if (!$result['ok']) {
            core_auth_render_login($result['error'] ?? 'Falha no login.', $login);
            break;
        }

        // 2FA habilitado? Reverte o login e exige o código
        $user = $result['user'];
        if (!empty($user['two_factor_enabled'])) {
            $userId = (int) $user['id'];
            Core\Session::destroy();
            Core\Session::start();
            $_SESSION['2fa_pending_user_id'] = $userId;
            $_SESSION['2fa_pending_at']      = time();
            core_redirect('index.php?m=auth&a=two_factor');
        }

        core_auth_redirect_after_login();
        break;

    case 'two_factor':
        core_auth_render_2fa();
        break;

    case 'two_factor_verify':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            core_redirect('index.php?m=auth&a=login');
        }
        Csrf::check();
        $pendingId = (int) ($_SESSION['2fa_pending_user_id'] ?? 0);
        $pendingAt = (int) ($_SESSION['2fa_pending_at'] ?? 0);
        if (!$pendingId || (time() - $pendingAt) > 300) {
            Flash::set('error', 'Sessão de verificação expirada. Faça login novamente.');
            core_redirect('index.php?m=auth&a=login');
        }
        $user = DB::queryOne('SELECT * FROM users WHERE id = ? AND active = 1', [$pendingId]);
        $code = (string) ($_POST['code'] ?? '');
        if (!$user || !$user['two_factor_secret'] || !Totp::verify((string) $user['two_factor_secret'], $code)) {
            core_auth_render_2fa('Código inválido. Tente novamente.');
            break;
        }
        unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_at']);
        Auth::establish($user);
        core_auth_redirect_after_login();
        break;

    case 'logout':
        Auth::logout();
        core_redirect('index.php?m=auth&a=login');
        break;

    // ================= PORTAL INICIAL =================
    case 'portal':
        Auth::requireLogin();
        core_auth_render_portal();
        break;

    // ================= PERFIL =================
    case 'profile':
        Auth::requireLogin();
        core_auth_render_profile();
        break;

    case 'profile_save':
        Auth::requireLogin();
        Csrf::check();
        $name  = trim((string) ($_POST['name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        if ($name === '') {
            Flash::set('error', 'Informe o nome.');
        } else {
            DB::execute('UPDATE users SET name = ?, phone = ? WHERE id = ?', [$name, $phone, Auth::id()]);
            $_SESSION['user_name'] = $name;
            Flash::set('success', 'Perfil atualizado.');
        }
        core_redirect('index.php?m=auth&a=profile');
        break;

    // ================= SENHA E 2FA =================
    case 'security':
        Auth::requireLogin();
        core_auth_render_security();
        break;

    case 'password_save':
        Auth::requireLogin();
        Csrf::check();
        $me      = Auth::user();
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $mustChange = !empty($me['force_password_change']);
        if (!$mustChange && (!$me['password_hash'] || !password_verify($current, (string) $me['password_hash']))) {
            Flash::set('error', 'Senha atual incorreta.');
        } elseif (strlen($new) < 8) {
            Flash::set('error', 'A nova senha deve ter pelo menos 8 caracteres.');
        } elseif ($new !== $confirm) {
            Flash::set('error', 'A confirmação não confere.');
        } else {
            DB::execute(
                'UPDATE users SET password_hash = ?, force_password_change = 0 WHERE id = ?',
                [password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), Auth::id()]
            );
            Core\Audit::log('password.change', 'users', (string) Auth::id());
            Flash::set('success', 'Senha alterada com sucesso.');
            core_redirect('index.php');
        }
        core_redirect('index.php?m=auth&a=security');
        break;

    case 'twofa_setup':
        Auth::requireLogin();
        Csrf::check();
        $secret = Totp::generateSecret();
        $_SESSION['2fa_setup_secret'] = $secret;
        core_redirect('index.php?m=auth&a=security');
        break;

    case 'twofa_activate':
        Auth::requireLogin();
        Csrf::check();
        $secret = (string) ($_SESSION['2fa_setup_secret'] ?? '');
        $code   = (string) ($_POST['code'] ?? '');
        if ($secret !== '' && Totp::verify($secret, $code)) {
            DB::execute('UPDATE users SET two_factor_secret = ?, two_factor_enabled = 1 WHERE id = ?', [$secret, Auth::id()]);
            unset($_SESSION['2fa_setup_secret']);
            Core\Audit::log('2fa.enable', 'users', (string) Auth::id());
            Flash::set('success', 'Verificação em duas etapas ativada.');
        } else {
            Flash::set('error', 'Código inválido — o 2FA não foi ativado.');
        }
        core_redirect('index.php?m=auth&a=security');
        break;

    case 'twofa_disable':
        Auth::requireLogin();
        Csrf::check();
        $me = Auth::user();
        if (!empty($me['two_factor_enabled']) && Totp::verify((string) $me['two_factor_secret'], (string) ($_POST['code'] ?? ''))) {
            DB::execute('UPDATE users SET two_factor_secret = NULL, two_factor_enabled = 0 WHERE id = ?', [Auth::id()]);
            Core\Audit::log('2fa.disable', 'users', (string) Auth::id());
            Flash::set('success', 'Verificação em duas etapas desativada.');
        } else {
            Flash::set('error', 'Código inválido — o 2FA continua ativo.');
        }
        core_redirect('index.php?m=auth&a=security');
        break;

    // ================= RECUPERAÇÃO DE SENHA =================
    case 'forgot':
        core_auth_render_forgot();
        break;

    case 'forgot_send':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            core_redirect('index.php?m=auth&a=forgot');
        }
        Csrf::check();
        $email = trim((string) ($_POST['email'] ?? ''));
        $user  = DB::queryOne('SELECT * FROM users WHERE email = ? AND active = 1', [$email]);
        if ($user && $user['password_hash'] !== null) {
            $token = bin2hex(random_bytes(32));
            DB::execute(
                'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))',
                [$user['id'], hash('sha256', $token)]
            );
            $link = core_url('index.php?m=auth&a=reset&token=' . $token);
            Mailer::send($email, 'Redefinição de senha', '<p>Para redefinir sua senha, acesse: <a href="' . $link . '">' . $link . '</a></p><p>O link vale por 30 minutos.</p>');
        }
        Flash::set('success', 'Se o e-mail existir, enviaremos as instruções de redefinição.');
        core_redirect('index.php?m=auth&a=login');
        break;

    case 'reset':
        core_auth_render_reset((string) ($_GET['token'] ?? ''));
        break;

    case 'reset_save':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            core_redirect('index.php?m=auth&a=login');
        }
        Csrf::check();
        $token = (string) ($_POST['token'] ?? '');
        $row   = DB::queryOne(
            'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()',
            [hash('sha256', $token)]
        );
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if (!$row) {
            Flash::set('error', 'Link inválido ou expirado.');
            core_redirect('index.php?m=auth&a=forgot');
        }
        if (strlen($new) < 8 || $new !== $confirm) {
            Flash::set('error', 'Senha muito curta ou confirmação divergente.');
            core_redirect('index.php?m=auth&a=reset&token=' . urlencode($token));
        }
        DB::execute('UPDATE users SET password_hash = ?, force_password_change = 0 WHERE id = ?', [password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), $row['user_id']]);
        DB::execute('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [$row['id']]);
        Flash::set('success', 'Senha redefinida. Faça login.');
        core_redirect('index.php?m=auth&a=login');
        break;

    // ================= NOTIFICAÇÕES =================
    case 'notifications':
        Auth::requireLogin();
        core_auth_render_notifications();
        break;

    case 'notifications_count':
        header('Content-Type: application/json');
        echo json_encode(['count' => Auth::check() ? Notifications::unreadCount((int) Auth::id()) : 0]);
        break;

    case 'notifications_read_all':
        Auth::requireLogin();
        Notifications::markRead((int) Auth::id());
        core_redirect($_SERVER['HTTP_REFERER'] ?? 'index.php');
        break;

    case 'notification_open':
        Auth::requireLogin();
        $id  = (int) ($_GET['id'] ?? 0);
        $row = DB::queryOne('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$id, Auth::id()]);
        if ($row) {
            Notifications::markRead((int) Auth::id(), $id);
            if (!empty($row['link'])) {
                core_redirect((string) $row['link']);
            }
        }
        core_redirect('index.php?m=auth&a=notifications');
        break;

    default:
        Layout::renderError(404, 'Ação não encontrada.');
}

// ======================================================================
// Renderizações
// ======================================================================

function core_auth_render_login(?string $error = null, string $login = ''): void
{
    $moodle = core_config('moodle.enabled', false);
    ob_start(); ?>
    <?php if ($error): ?><div class="alert alert-danger py-2"><?= core_e($error) ?></div><?php endif; ?>
    <?php foreach (Core\Flash::pull() as $type => $msgs): foreach ((array) $msgs as $msg): ?>
        <div class="alert alert-<?= $type === 'error' ? 'danger' : 'success' ?> py-2"><?= core_e($msg) ?></div>
    <?php endforeach; endforeach; ?>
    <form method="post" action="<?= core_url('index.php?m=auth&a=do_login') ?>">
        <?= Core\Csrf::field() ?>
        <div class="mb-3">
            <label class="form-label">Usuário ou e-mail</label>
            <input type="text" name="login" class="form-control" value="<?= core_e($login) ?>" required autofocus
                   placeholder="<?= $moodle ? 'usuário do Moodle ou e-mail' : 'seu usuário ou e-mail' ?>">
        </div>
        <div class="mb-3">
            <label class="form-label">Senha</label>
            <input type="password" name="password" class="form-control" required>
        </div>
        <button class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right me-1"></i> Entrar</button>
        <div class="text-center mt-3">
            <a class="small text-decoration-none" href="<?= core_url('index.php?m=auth&a=forgot') ?>">Esqueci minha senha</a>
        </div>
        <?php if ($moodle): ?>
            <div class="text-center mt-2 small text-muted">
                <i class="bi bi-mortarboard me-1"></i>Você pode entrar com suas credenciais do Moodle.
            </div>
        <?php endif; ?>
    </form>
    <?php
    Core\Layout::renderBare('Entrar', (string) ob_get_clean());
}

function core_auth_render_2fa(?string $error = null): void
{
    if (empty($_SESSION['2fa_pending_user_id'])) {
        core_redirect('index.php?m=auth&a=login');
    }
    ob_start(); ?>
    <?php if ($error): ?><div class="alert alert-danger py-2"><?= core_e($error) ?></div><?php endif; ?>
    <p class="text-muted small">Digite o código de 6 dígitos do seu aplicativo autenticador.</p>
    <form method="post" action="<?= core_url('index.php?m=auth&a=two_factor_verify') ?>">
        <?= Core\Csrf::field() ?>
        <div class="mb-3">
            <input type="text" name="code" class="form-control form-control-lg text-center" inputmode="numeric"
                   pattern="[0-9]*" maxlength="6" placeholder="000000" required autofocus autocomplete="one-time-code">
        </div>
        <button class="btn btn-primary w-100">Verificar</button>
        <div class="text-center mt-3">
            <a class="small text-decoration-none" href="<?= core_url('index.php?m=auth&a=login') ?>">Voltar ao login</a>
        </div>
    </form>
    <?php
    Core\Layout::renderBare('Verificação em duas etapas', (string) ob_get_clean());
}

function core_auth_render_portal(): void
{
    $user    = Auth::user();
    $modules = Modules::forUser((int) $user['id']);
    ob_start(); ?>
    <div class="mb-4">
        <h1 class="h4 mb-1">Olá, <?= core_e(explode(' ', (string) $user['name'])[0]) ?> 👋</h1>
        <p class="text-muted mb-0">Escolha um módulo para começar.</p>
    </div>
    <?php if (!$modules): ?>
        <div class="alert alert-warning">
            Você ainda não tem acesso a nenhum módulo. Solicite a liberação ao administrador da plataforma.
        </div>
    <?php endif; ?>
    <div class="row g-3">
        <?php foreach ($modules as $slug => $m): ?>
            <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                <a class="card portal-module-card h-100" href="<?= core_module_url($slug) ?>">
                    <div class="card-body d-flex flex-column gap-3">
                        <div class="module-icon"><i class="bi <?= core_e($m['icon'] ?? 'bi-app') ?>"></i></div>
                        <div>
                            <div class="fw-semibold text-body"><?= core_e($m['name']) ?></div>
                            <div class="small text-muted"><?= core_e($m['description'] ?? '') ?></div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
        <?php if (core_config('moodle.enabled')): ?>
            <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                <a class="card portal-module-card h-100" href="<?= core_e(core_config('moodle.url')) ?>" target="_blank" rel="noopener">
                    <div class="card-body d-flex flex-column gap-3">
                        <div class="module-icon"><i class="bi bi-mortarboard"></i></div>
                        <div>
                            <div class="fw-semibold text-body"><?= core_e(core_config('moodle.link_label', 'Moodle')) ?> <i class="bi bi-box-arrow-up-right small"></i></div>
                            <div class="small text-muted">Plataforma de ensino a distância.</div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endif; ?>
    </div>
    <?php
    Core\Layout::render(['title' => 'Início', 'content' => (string) ob_get_clean(), 'module' => null, 'sidebar' => null]);
}

function core_auth_render_profile(): void
{
    $user = Auth::user();
    ob_start(); ?>
    <h1 class="h4 mb-3"><i class="bi bi-person me-2"></i>Meu perfil</h1>
    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-body">
                    <form method="post" action="<?= core_url('index.php?m=auth&a=profile_save') ?>">
                        <?= Core\Csrf::field() ?>
                        <div class="mb-3">
                            <label class="form-label">Nome</label>
                            <input class="form-control" name="name" value="<?= core_e($user['name']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">E-mail</label>
                            <input class="form-control" value="<?= core_e($user['email']) ?>" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Usuário</label>
                            <input class="form-control" value="<?= core_e($user['username']) ?>" disabled>
                            <?php if ($user['auth_source'] === 'moodle'): ?>
                                <div class="form-text"><i class="bi bi-mortarboard me-1"></i>Conta vinculada ao Moodle.</div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Telefone</label>
                            <input class="form-control" name="phone" value="<?= core_e($user['phone']) ?>">
                        </div>
                        <button class="btn btn-primary">Salvar</button>
                        <a class="btn btn-outline-secondary" href="<?= core_url('index.php?m=auth&a=security') ?>"><i class="bi bi-shield-lock me-1"></i>Senha e 2FA</a>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header">Meus acessos</div>
                <div class="card-body">
                    <?php foreach (Core\Access::allFor((int) $user['id']) as $slug => $role):
                        $m = Core\Modules::manifest($slug); ?>
                        <div class="d-flex justify-content-between border-bottom py-2">
                            <span><i class="bi <?= core_e($m['icon'] ?? 'bi-app') ?> me-2"></i><?= core_e($m['name'] ?? $slug) ?></span>
                            <span class="badge text-bg-secondary"><?= core_e($m['roles'][$role] ?? $role) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    Core\Layout::render(['title' => 'Meu perfil', 'content' => (string) ob_get_clean(), 'module' => null, 'sidebar' => null]);
}

function core_auth_render_security(): void
{
    $user        = Auth::user();
    $force       = !empty($user['force_password_change']) || isset($_GET['force']);
    $setupSecret = $_SESSION['2fa_setup_secret'] ?? null;
    ob_start(); ?>
    <h1 class="h4 mb-3"><i class="bi bi-shield-lock me-2"></i>Senha e verificação em duas etapas</h1>
    <?php if ($force): ?>
        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>Você precisa definir uma nova senha antes de continuar.</div>
    <?php endif; ?>
    <div class="row g-3">
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header">Alterar senha</div>
                <div class="card-body">
                    <?php if ($user['password_hash'] === null && $user['auth_source'] === 'moodle'): ?>
                        <p class="text-muted small mb-2"><i class="bi bi-info-circle me-1"></i>Sua conta autentica pelo Moodle. Definir uma senha local é opcional
                        (permite entrar mesmo se o Moodle estiver fora do ar).</p>
                    <?php endif; ?>
                    <form method="post" action="<?= core_url('index.php?m=auth&a=password_save') ?>">
                        <?= Core\Csrf::field() ?>
                        <?php if (!$force && $user['password_hash'] !== null): ?>
                            <div class="mb-3">
                                <label class="form-label">Senha atual</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label class="form-label">Nova senha (mín. 8 caracteres)</label>
                            <input type="password" name="new_password" class="form-control" minlength="8" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirmar nova senha</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="8" required>
                        </div>
                        <button class="btn btn-primary">Alterar senha</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header">Verificação em duas etapas (2FA)</div>
                <div class="card-body">
                    <?php if (!empty($user['two_factor_enabled'])): ?>
                        <p><span class="badge text-bg-success">Ativo</span> Sua conta exige código do autenticador ao entrar.</p>
                        <form method="post" action="<?= core_url('index.php?m=auth&a=twofa_disable') ?>" class="d-flex gap-2">
                            <?= Core\Csrf::field() ?>
                            <input type="text" name="code" class="form-control" style="max-width: 140px" placeholder="Código" inputmode="numeric" maxlength="6" required>
                            <button class="btn btn-outline-danger">Desativar</button>
                        </form>
                    <?php elseif ($setupSecret): ?>
                        <p class="small text-muted">1. Adicione a chave abaixo no Google/Microsoft Authenticator (ou leia o QR):</p>
                        <div class="p-2 bg-light border rounded font-monospace small mb-2"><?= core_e($setupSecret) ?></div>
                        <?php $uri = Core\Totp::provisioningUri($setupSecret, (string) $user['email'], Core\Settings::get('org_name', 'Portal')); ?>
                        <div class="text-center mb-3">
                            <img alt="QR Code 2FA" width="180" height="180"
                                 src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&amp;data=<?= urlencode($uri) ?>">
                        </div>
                        <p class="small text-muted">2. Digite o código gerado para confirmar:</p>
                        <form method="post" action="<?= core_url('index.php?m=auth&a=twofa_activate') ?>" class="d-flex gap-2">
                            <?= Core\Csrf::field() ?>
                            <input type="text" name="code" class="form-control" style="max-width: 140px" placeholder="000000" inputmode="numeric" maxlength="6" required>
                            <button class="btn btn-success">Ativar 2FA</button>
                        </form>
                    <?php else: ?>
                        <p class="text-muted small">Proteja sua conta exigindo um código do aplicativo autenticador além da senha.</p>
                        <form method="post" action="<?= core_url('index.php?m=auth&a=twofa_setup') ?>">
                            <?= Core\Csrf::field() ?>
                            <button class="btn btn-outline-primary"><i class="bi bi-qr-code me-1"></i>Configurar 2FA</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    Core\Layout::render(['title' => 'Senha e 2FA', 'content' => (string) ob_get_clean(), 'module' => null, 'sidebar' => null]);
}

function core_auth_render_forgot(): void
{
    ob_start(); ?>
    <?php foreach (Core\Flash::pull() as $type => $msgs): foreach ((array) $msgs as $msg): ?>
        <div class="alert alert-<?= $type === 'error' ? 'danger' : 'success' ?> py-2"><?= core_e($msg) ?></div>
    <?php endforeach; endforeach; ?>
    <p class="text-muted small">Informe seu e-mail e enviaremos um link para redefinir a senha.</p>
    <form method="post" action="<?= core_url('index.php?m=auth&a=forgot_send') ?>">
        <?= Core\Csrf::field() ?>
        <div class="mb-3">
            <input type="email" name="email" class="form-control" placeholder="seu@email.com" required autofocus>
        </div>
        <button class="btn btn-primary w-100">Enviar link</button>
        <div class="text-center mt-3">
            <a class="small text-decoration-none" href="<?= core_url('index.php?m=auth&a=login') ?>">Voltar ao login</a>
        </div>
    </form>
    <?php
    Core\Layout::renderBare('Recuperar senha', (string) ob_get_clean());
}

function core_auth_render_reset(string $token): void
{
    ob_start(); ?>
    <p class="text-muted small">Defina sua nova senha.</p>
    <form method="post" action="<?= core_url('index.php?m=auth&a=reset_save') ?>">
        <?= Core\Csrf::field() ?>
        <input type="hidden" name="token" value="<?= core_e($token) ?>">
        <div class="mb-3">
            <label class="form-label">Nova senha (mín. 8 caracteres)</label>
            <input type="password" name="new_password" class="form-control" minlength="8" required autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label">Confirmar nova senha</label>
            <input type="password" name="confirm_password" class="form-control" minlength="8" required>
        </div>
        <button class="btn btn-primary w-100">Redefinir senha</button>
    </form>
    <?php
    Core\Layout::renderBare('Redefinir senha', (string) ob_get_clean());
}

function core_auth_render_notifications(): void
{
    $user = Auth::user();
    $rows = Core\DB::query(
        'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 200',
        [$user['id']]
    );
    ob_start(); ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-bell me-2"></i>Notificações</h1>
        <a class="btn btn-sm btn-outline-secondary" href="<?= core_url('index.php?m=auth&a=notifications_read_all') ?>">
            <i class="bi bi-check2-all me-1"></i>Marcar todas como lidas
        </a>
    </div>
    <div class="card">
        <div class="list-group list-group-flush">
            <?php if (!$rows): ?>
                <div class="list-group-item text-muted text-center py-4">Nenhuma notificação.</div>
            <?php endif; ?>
            <?php foreach ($rows as $n): ?>
                <a class="list-group-item list-group-item-action <?= $n['read_at'] ? '' : 'fw-semibold' ?>"
                   href="<?= core_url('index.php?m=auth&a=notification_open&id=' . (int) $n['id']) ?>">
                    <div class="d-flex justify-content-between">
                        <span>
                            <span class="badge text-bg-light border me-1"><?= core_e($n['module'] ?: 'portal') ?></span>
                            <?= core_e($n['title']) ?>
                        </span>
                        <small class="text-muted"><?= core_e(date('d/m/Y H:i', strtotime((string) $n['created_at']))) ?></small>
                    </div>
                    <?php if ($n['message']): ?><div class="small text-muted fw-normal mt-1"><?= core_e($n['message']) ?></div><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    Core\Layout::render(['title' => 'Notificações', 'content' => (string) ob_get_clean(), 'module' => null, 'sidebar' => null]);
}

function core_auth_redirect_after_login(): void
{
    $me = Auth::user();
    if (!empty($me['force_password_change'])) {
        core_redirect('index.php?m=auth&a=security&force=1');
    }
    $intended = $_SESSION['intended_url'] ?? null;
    unset($_SESSION['intended_url']);
    if ($intended && str_contains($intended, 'index.php') && !str_contains($intended, 'm=auth')) {
        core_redirect(BASE_URL . preg_replace('#^.*?(/index\.php)#', '$1', $intended));
    }
    core_redirect('index.php');
}
