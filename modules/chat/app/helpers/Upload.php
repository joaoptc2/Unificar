<?php
/**
 * Uploads do módulo — gravados em /uploads/chat/{avatars,attachments}
 * na raiz da plataforma (UPLOADS_PATH definido pelo bootstrap do núcleo).
 * O caminho relativo armazenado no banco começa com "uploads/chat/".
 */
class Upload
{
    public static function handle(string $fieldName, string $subDir = 'attachments'): array
    {
        if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Nenhum arquivo enviado.'];
        }

        $file = $_FILES[$fieldName];
        $cfg  = (require CHAT_PATH . '/config/app.php')['upload'];

        if ($file['size'] > $cfg['max_size']) {
            return ['success' => false, 'error' => 'Arquivo muito grande.'];
        }

        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, $cfg['allowed_files'], true)) {
            return ['success' => false, 'error' => 'Tipo de arquivo não permitido.'];
        }

        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $name = bin2hex(random_bytes(16)) . '.' . $ext;

        $subDir  = preg_replace('/[^a-z0-9_-]/', '', $subDir) ?: 'attachments';
        $destDir = UPLOADS_PATH . '/chat/' . $subDir;

        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $destPath = $destDir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return ['success' => false, 'error' => 'Falha ao salvar arquivo.'];
        }

        return [
            'success'       => true,
            'path'          => 'uploads/chat/' . $subDir . '/' . $name,
            'original_name' => $file['name'],
            'file_type'     => $mime,
            'file_size'     => $file['size'],
        ];
    }

    public static function delete(string $path): bool
    {
        $path = ltrim($path, '/');
        // Apenas arquivos do próprio módulo
        if (!str_starts_with($path, 'uploads/chat/')) {
            return false;
        }
        $full = BASE_PATH . '/' . $path;
        if (file_exists($full)) {
            return unlink($full);
        }
        return false;
    }

    public static function url(string $path): string
    {
        return BASE_URL . '/' . ltrim($path, '/');
    }

    public static function isImage(string $mime): bool
    {
        return str_starts_with($mime, 'image/');
    }

    public static function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
