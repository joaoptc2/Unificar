<?php
/**
 * DownloadController — serve arquivos privados (documentos, atestados, CVs,
 * arquivos de vencimentos) armazenados em `storage/uploads/`, fora do webroot.
 *
 * Requer autenticação e valida a permissão baseada no tipo de recurso.
 * Rota: index.php?m=rh&page=files&action=get&type=<tipo>&id=<id>
 */
class DownloadController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        $this->get();
    }

    public function get(): void
    {
        Auth::requireLogin();

        $type = Sanitize::get('type');
        $id   = Sanitize::int($_GET['id'] ?? 0);
        if (!$type || !$id) {
            $this->abort(400, 'Requisição inválida.');
        }

        [$path, $originalName] = $this->resolveFile($type, $id);
        if (!$path) {
            $this->abort(404, 'Arquivo não encontrado.');
        }

        $full = Upload::resolvePath($path);
        if (!$full || !is_file($full)) {
            $this->abort(404, 'Arquivo não disponível.');
        }

        AuditLog::log('download', $type, $id);
        $this->stream($full, $originalName ?: basename($full));
    }

    /**
     * Retorna [relativePath, originalName] após checar permissões do recurso.
     */
    private function resolveFile(string $type, int $id): array
    {
        switch ($type) {
            case 'document': // employee_documents
                core_require('employee_documents.view');
                $stmt = $this->db->prepare(
                    'SELECT file_path, file_original_name FROM rh_employee_documents WHERE id = ?'
                );
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                return $row ? [$row['file_path'], $row['file_original_name']] : [null, null];

            case 'certificate': // medical_certificates
                core_require('certificates.view');
                $stmt = $this->db->prepare('SELECT file_path FROM rh_medical_certificates WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                return $row ? [$row['file_path'], null] : [null, null];

            case 'expiration':
                core_require('expirations.view');
                $stmt = $this->db->prepare('SELECT file_path, title FROM rh_expirations WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                return $row ? [$row['file_path'], $row['title']] : [null, null];

            case 'announcement': // anexo de comunicado
                core_require('announcements.view');
                $stmt = $this->db->prepare('SELECT attachment_path, attachment_name, published_at FROM rh_announcements WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if ($row && empty($row['published_at']) && !core_can_any(['announcements.create', 'announcements.edit'])) {
                    $this->abort(403, 'Sem permissão.');
                }
                return $row ? [$row['attachment_path'], $row['attachment_name']] : [null, null];

            case 'resume': // currículo do candidato
                // Acesso a currículos exige permissão de recrutamento OU banco de talentos.
                if (!core_can('recruitment.view') && !core_can('talent_pool.view')) {
                    $this->abort(403, 'Sem permissão.');
                }
                $stmt = $this->db->prepare('SELECT resume_path, full_name FROM rh_candidates WHERE id = ?');
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                return $row ? [$row['resume_path'], $row['full_name']] : [null, null];

            default:
                $this->abort(400, 'Tipo inválido.');
        }
        return [null, null];
    }

    private function stream(string $absolutePath, string $downloadName): void
    {
        $size = filesize($absolutePath);
        $mime = @mime_content_type($absolutePath) ?: 'application/octet-stream';

        // Sanitiza o nome para o header Content-Disposition.
        $safeName = preg_replace('/[\r\n"\\\\]/', '_', $downloadName);
        if (!pathinfo($safeName, PATHINFO_EXTENSION)) {
            $ext = pathinfo($absolutePath, PATHINFO_EXTENSION);
            if ($ext) $safeName .= '.' . $ext;
        }

        // Limpa qualquer saída anterior (views/header.php podem ter sido carregadas
        // mas nos fluxos do router atual não são antes do action).
        if (ob_get_level() > 0) { @ob_end_clean(); }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Content-Disposition: inline; filename="' . $safeName . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, no-cache');
        readfile($absolutePath);
        exit;
    }

    private function abort(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }
}
