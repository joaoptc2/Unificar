<?php
/**
 * Uploads do módulo — gravados em /uploads/chat/{attachments,emojis,avatars}
 * na raiz da plataforma (UPLOADS_PATH definido pelo bootstrap do núcleo).
 * O caminho relativo armazenado no banco começa com "uploads/chat/".
 *
 * Validação: tamanho máximo, MIME real (finfo) E extensão coerente com o
 * MIME (lista em config/app.php), nome de arquivo aleatório.
 */
class Upload
{
    /**
     * @param string $fieldName  campo de $_FILES
     * @param string $subDir     subpasta em uploads/chat/
     * @param bool   $imagesOnly aceita apenas imagens (emojis)
     */
    public static function handle(string $fieldName, string $subDir = 'attachments', bool $imagesOnly = false): array
    {
        if (empty($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
            return ['success' => false, 'error' => 'Nenhum arquivo enviado.'];
        }

        $file = $_FILES[$fieldName];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => self::errorMessage((int) $file['error'])];
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'error' => 'Envio inválido.'];
        }

        $cfg  = (require CHAT_PATH . '/config/app.php')['upload'];

        if ($file['size'] <= 0) {
            return ['success' => false, 'error' => 'Arquivo vazio.'];
        }
        if ($file['size'] > $cfg['max_size']) {
            return ['success' => false, 'error' => 'Arquivo muito grande (máximo ' . self::formatSize((int) $cfg['max_size']) . ').'];
        }

        $allowed = $imagesOnly ? $cfg['allowed_images'] : $cfg['allowed_files'];

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            return ['success' => false, 'error' => 'Tipo de arquivo não permitido.'];
        }

        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed[$mime], true)) {
            return ['success' => false, 'error' => 'A extensão do arquivo não corresponde ao seu conteúdo.'];
        }

        // Imagens: garante que é decodificável (evita arquivos disfarçados)
        if (str_starts_with($mime, 'image/') && @getimagesize($file['tmp_name']) === false) {
            return ['success' => false, 'error' => 'Imagem inválida.'];
        }

        $name    = bin2hex(random_bytes(16)) . '.' . $ext;
        $subDir  = preg_replace('/[^a-z0-9_-]/', '', $subDir) ?: 'attachments';
        $destDir = UPLOADS_PATH . '/chat/' . $subDir;

        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            return ['success' => false, 'error' => 'Falha ao preparar a pasta de uploads.'];
        }

        $destPath = $destDir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return ['success' => false, 'error' => 'Falha ao salvar arquivo.'];
        }
        @chmod($destPath, 0644);

        return [
            'success'       => true,
            'path'          => 'uploads/chat/' . $subDir . '/' . $name,
            'original_name' => mb_substr(basename((string) $file['name']), 0, 250),
            'file_type'     => $mime,
            'file_size'     => (int) $file['size'],
        ];
    }

    public static function delete(string $path): bool
    {
        $path = ltrim($path, '/');
        // Apenas arquivos do próprio módulo, sem travessia de diretório
        if (!str_starts_with($path, 'uploads/chat/') || str_contains($path, '..')) {
            return false;
        }
        $full = BASE_PATH . '/' . $path;
        if (is_file($full)) {
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
        $val = (float) $bytes;
        while ($val >= 1024 && $i < 3) {
            $val /= 1024;
            $i++;
        }
        return round($val, 1) . ' ' . $units[$i];
    }

    private static function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande.',
            UPLOAD_ERR_PARTIAL => 'Envio incompleto. Tente novamente.',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo enviado.',
            default => 'Falha no envio do arquivo.',
        };
    }
}
