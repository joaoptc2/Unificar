<?php
/**
 * PrivacyController — exibe a Política de Privacidade pública e
 * implementa o "direito ao esquecimento" do candidato (LGPD).
 */
class PrivacyController
{
    public function index(): void
    {
        $appConfig = require __DIR__ . '/../../config/app.php';
        $hospitalName = $appConfig['app_name'] ?? 'Hospital';
        $contactEmail = $appConfig['mail_from'] ?? '';
        require __DIR__ . '/../views/privacy/index.php';
    }

    /**
     * Permite que o candidato delete sua candidatura usando o token de
     * acompanhamento (direito ao esquecimento — LGPD art. 18, VI).
     */
    public function delete_candidate(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?page=public_recruitment&action=track');
            exit;
        }
        Csrf::check();
        $token = trim($_POST['token'] ?? '');
        if (strlen($token) < 12) {
            Session::flash('error', 'Token inválido.');
            header('Location: index.php?page=public_recruitment&action=track&token=' . urlencode($token));
            exit;
        }
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT id FROM candidates WHERE access_token LIKE ? LIMIT 1');
        $stmt->execute([$token . '%']);
        $row = $stmt->fetch();
        if ($row && Lgpd::deleteCandidate((int)$row['id'])) {
            Session::flash('success', 'Sua candidatura e dados pessoais foram removidos do nosso sistema.');
        } else {
            Session::flash('error', 'Não foi possível localizar a candidatura.');
        }
        header('Location: index.php?page=public_recruitment&action=track');
        exit;
    }
}
