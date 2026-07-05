<?php
/**
 * TwoFactorController — gerenciamento de 2FA TOTP do usuário logado.
 *
 * Rotas:
 *   ?page=two_factor                 → status / iniciar configuração
 *   ?page=two_factor&action=activate → POST: confirma e ativa
 *   ?page=two_factor&action=disable  → POST: desativa (exige código)
 *   ?page=two_factor&action=verify   → POST: tela durante o login (challenge)
 *   ?page=two_factor&action=challenge→ GET: tela durante o login (challenge)
 */
class TwoFactorController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        Auth::requireLogin();

        $appConfig = require __DIR__ . '/../../config/app.php';
        $issuer    = $appConfig['app_name'] ?? 'RH';
        $userEmail = Session::get('user_email');

        $row = $this->getRecord((int)Session::userId());

        // Se não tem 2FA ainda OU está desabilitado, gera um novo segredo (não persiste até confirmar).
        if (!$row || (int)$row['enabled'] === 0) {
            $secret = $row['secret'] ?? Totp::generateSecret();
            // Persiste segredo "pendente" para que o setup sobreviva a reloads.
            $this->upsertSecret((int)Session::userId(), $secret, false);
            $uri = Totp::uri($issuer, $userEmail, $secret);
            View::render('profile/two_factor_setup', [
                'pageTitle' => 'Autenticação em duas etapas',
                'page'      => 'profile',
                'secret'    => $secret,
                'otpauth'   => $uri,
                'error'     => Session::flash('error'),
            ]);
            return;
        }

        // Já habilitado.
        View::render('profile/two_factor_status', [
            'pageTitle' => 'Autenticação em duas etapas',
            'page'      => 'profile',
            'enabled'   => true,
            'lastUsed'  => $row['last_used_at'],
            'recoveryCount' => $row['recovery_codes'] ? count(json_decode($row['recovery_codes'], true) ?: []) : 0,
            'success'   => Session::flash('success'),
            'error'     => Session::flash('error'),
            'newCodes'  => Session::flash('recovery_codes'),
        ]);
    }

    public function activate(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $userId = (int)Session::userId();
        $code   = $_POST['code'] ?? '';
        $row    = $this->getRecord($userId);

        if (!$row) {
            Session::flash('error', 'Configuração de 2FA não iniciada.');
            header('Location: index.php?page=two_factor');
            exit;
        }

        if (!Totp::verify($row['secret'], $code, 1)) {
            Session::flash('error', 'Código inválido. Tente novamente.');
            header('Location: index.php?page=two_factor');
            exit;
        }

        // Gera códigos de recuperação e persiste.
        $codes = Totp::generateRecoveryCodes(8);
        $hashed = Totp::hashRecoveryCodes($codes);
        $stmt = $this->db->prepare(
            'UPDATE user_2fa SET enabled = 1, recovery_codes = ?, last_used_at = NOW() WHERE user_id = ?'
        );
        $stmt->execute([json_encode($hashed), $userId]);

        AuditLog::log('2fa_enabled', 'users', $userId);

        Session::flash('success', '2FA ativado com sucesso. Guarde os códigos de recuperação abaixo em local seguro — eles não serão mostrados novamente.');
        Session::flash('recovery_codes', $codes);
        header('Location: index.php?page=two_factor');
        exit;
    }

    public function disable(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $userId = (int)Session::userId();
        $code   = $_POST['code'] ?? '';
        $row    = $this->getRecord($userId);

        if (!$row || (int)$row['enabled'] === 0) {
            header('Location: index.php?page=two_factor');
            exit;
        }

        // Exige código TOTP atual ou de recuperação para desativar.
        $valid = Totp::verify($row['secret'], $code, 1);
        if (!$valid) {
            $hashed = json_decode($row['recovery_codes'] ?: '[]', true) ?: [];
            $valid  = Totp::matchRecoveryCode($hashed, $code) !== null;
        }
        if (!$valid) {
            Session::flash('error', 'Código inválido. 2FA não foi desativado.');
            header('Location: index.php?page=two_factor');
            exit;
        }

        $this->db->prepare('DELETE FROM user_2fa WHERE user_id = ?')->execute([$userId]);
        AuditLog::log('2fa_disabled', 'users', $userId);
        Session::flash('success', '2FA desativado.');
        header('Location: index.php?page=two_factor');
        exit;
    }

    /**
     * Tela mostrada durante o login quando o usuário tem 2FA ativo.
     */
    public function challenge(): void
    {
        if (!Session::has('2fa_pending_user_id')) {
            header('Location: index.php?page=login');
            exit;
        }
        View::renderRaw('auth/two_factor_challenge', [
            'error' => Session::flash('error'),
        ]);
    }

    /**
     * Verifica o código durante o fluxo de login.
     */
    public function verify(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?page=two_factor&action=challenge');
            exit;
        }
        Csrf::check();

        $pendingId = (int)Session::get('2fa_pending_user_id');
        if (!$pendingId) {
            header('Location: index.php?page=login');
            exit;
        }

        $code = $_POST['code'] ?? '';
        $row  = $this->getRecord($pendingId);

        if (!$row || !(int)$row['enabled']) {
            // Inconsistência — encerra o fluxo.
            Session::remove('2fa_pending_user_id');
            header('Location: index.php?page=login');
            exit;
        }

        $valid = Totp::verify($row['secret'], $code, 1);
        $usedRecovery = false;
        if (!$valid) {
            $hashed = json_decode($row['recovery_codes'] ?: '[]', true) ?: [];
            $idx = Totp::matchRecoveryCode($hashed, $code);
            if ($idx !== null) {
                $valid = true;
                $usedRecovery = true;
                array_splice($hashed, $idx, 1);
                $this->db->prepare('UPDATE user_2fa SET recovery_codes = ? WHERE user_id = ?')
                         ->execute([json_encode($hashed), $pendingId]);
            }
        }

        if (!$valid) {
            RateLimit::recordLogin(RateLimit::clientIp(), Session::get('2fa_pending_email'), false);
            AuditLog::log('2fa_failed', 'users', $pendingId);
            Session::flash('error', 'Código inválido.');
            header('Location: index.php?page=two_factor&action=challenge');
            exit;
        }

        // Sucesso — completar o login.
        $this->db->prepare('UPDATE user_2fa SET last_used_at = NOW() WHERE user_id = ?')
                 ->execute([$pendingId]);
        Auth::completePendingLogin();

        if ($usedRecovery) {
            Session::flash('success', 'Login efetuado com código de recuperação. Considere gerar novos códigos.');
        }
        $dest = Session::userRole() === 'funcionario' ? 'my' : 'dashboard';
        header('Location: index.php?page=' . $dest);
        exit;
    }

    // -----------------------------------------------------------------

    private function getRecord(int $userId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM user_2fa WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function upsertSecret(int $userId, string $secret, bool $enabled): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO user_2fa (user_id, secret, enabled) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE secret = VALUES(secret)'
        );
        $stmt->execute([$userId, $secret, $enabled ? 1 : 0]);
    }
}
