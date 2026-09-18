<?php
/**
 * PrivacyController — exibe a Política de Privacidade pública e
 * implementa o "direito ao esquecimento" do candidato (LGPD).
 */
class PrivacyController
{
    public function index(): void
    {
        try {
            $hospitalName = Core\Branding::name();
        } catch (\Throwable $e) {
            $hospitalName = 'Hospital';
        }
        $contactEmail = (string)core_config('mail.from', '');
        require __DIR__ . '/../views/privacy/index.php';
    }

    /**
     * Permite que o candidato delete sua candidatura usando o token de
     * acompanhamento (direito ao esquecimento — LGPD art. 18, VI).
     */
    public function delete_candidate(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?m=rh&page=public_recruitment&action=track');
            exit;
        }
        Csrf::check();
        $token = trim($_POST['token'] ?? '');
        // Igualdade EXATA com validação de formato. O access_token é sempre 64
        // hexadecimais (bin2hex de 32 bytes). O código antigo fazia
        // `LIKE ?` com $token.'%', então "____________" (12 sublinhados, que
        // passava no guard strlen<12) virava o curinga `____________%` — no
        // MySQL `_` casa qualquer caractere e `%` o resto, casando TODOS os
        // candidatos. Uma rota PÚBLICA (is_public, minPerm=null) apagava a
        // base inteira sem login. Mesmo conserto já aplicado em
        // PublicRecruitmentController::track.
        if (!preg_match('/^[0-9a-f]{64}$/i', $token)) {
            Session::flash('error', 'Token inválido.');
            header('Location: index.php?m=rh&page=public_recruitment&action=track&token=' . urlencode($token));
            exit;
        }
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM rh_candidates WHERE access_token = ? LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if ($row && Lgpd::deleteCandidate((int)$row['id'])) {
            Session::flash('success', 'Sua candidatura e dados pessoais foram removidos do nosso sistema.');
        } else {
            Session::flash('error', 'Não foi possível localizar a candidatura.');
        }
        header('Location: index.php?m=rh&page=public_recruitment&action=track');
        exit;
    }
}
