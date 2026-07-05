<?php
/**
 * Upload seguro de arquivos
 * - Renomeia com hash
 * - Valida MIME type
 * - Limita tamanho
 * - Bloqueia execução
 */
class Upload
{
    private static array $allowedMimes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private static array $allowedExtensions = [
        'pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx',
    ];

    private static int $maxSize = 5242880; // 5MB

    /**
     * Subdiretórios considerados "públicos" (acessíveis diretamente por URL —
     * ex.: fotos de perfil exibidas em <img src>). Demais subdiretórios são
     * armazenados em `storage/uploads/`, fora do webroot, e só acessíveis via
     * DownloadController com autenticação.
     */
    private static array $publicSubdirs = ['employees', 'photos'];

    /**
     * Processa upload de arquivo
     *
     * @param string $fieldName Nome do campo do formulário
     * @param string $subDir Subdiretório (employees, documents, resumes, certificates)
     * @return array ['success' => bool, 'path' => string, 'original_name' => string, 'size' => int, 'error' => string]
     *               `path` é armazenado no banco. Se começar com `uploads/` é público;
     *               se começar com `storage/` é privado (servir via DownloadController).
     */
    public static function handle(string $fieldName, string $subDir = 'documents'): array
    {
        $result = ['success' => false, 'path' => '', 'original_name' => '', 'size' => 0, 'error' => ''];

        if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
            $result['error'] = 'Nenhum arquivo enviado.';
            return $result;
        }

        $file = $_FILES[$fieldName];

        // Verificar erros de upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $result['error'] = self::getUploadError($file['error']);
            return $result;
        }

        // Verificar tamanho
        if ($file['size'] > self::$maxSize) {
            $result['error'] = 'Arquivo excede o tamanho máximo permitido (' . round(self::$maxSize / 1024 / 1024, 1) . 'MB).';
            return $result;
        }

        // Verificar extensão
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::$allowedExtensions)) {
            $result['error'] = 'Extensão de arquivo não permitida: .' . $ext;
            return $result;
        }

        // Verificar MIME type real
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, self::$allowedMimes)) {
            $result['error'] = 'Tipo de arquivo não permitido: ' . $mimeType;
            return $result;
        }

        // Gerar nome seguro com hash
        $safeName = bin2hex(random_bytes(16)) . '_' . time() . '.' . $ext;

        $isPublic  = in_array($subDir, self::$publicSubdirs, true);
        $baseDir   = $isPublic ? __DIR__ . '/../../public/uploads/' : __DIR__ . '/../../storage/uploads/';
        $destDir   = $baseDir . $subDir . '/';
        $pathPrefix = $isPublic ? 'uploads/' : 'storage/uploads/';

        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }

        // Criar .htaccess para bloquear execução (segurança extra — aplicável
        // ao diretório público; para storage/ o webserver já não serve nada
        // pois está fora do webroot e bloqueado pelo .htaccess raiz).
        $htaccess = $destDir . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents(
                $htaccess,
                "Options -ExecCGI\n" .
                "RemoveHandler .php .phtml .php3 .php4 .php5 .phps\n" .
                "AddType text/plain .php .phtml .php3 .php4 .php5 .phps\n" .
                "<FilesMatch \"\\.(php|phtml|php3|php4|php5|phps)$\">\n" .
                "    Require all denied\n" .
                "</FilesMatch>\n"
            );
        }

        $destPath = $destDir . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $result['error'] = 'Erro ao mover o arquivo. Verifique as permissões do diretório.';
            return $result;
        }

        $result['success']       = true;
        $result['path']          = $pathPrefix . $subDir . '/' . $safeName;
        $result['original_name'] = $file['name'];
        $result['size']          = $file['size'];

        return $result;
    }

    /**
     * Remove arquivo do disco. Aceita tanto paths `uploads/...` quanto
     * `storage/uploads/...` (além de retrocompatibilidade com paths antigos).
     */
    public static function delete(?string $relativePath): bool
    {
        if (empty($relativePath)) return false;
        $full = self::resolvePath($relativePath);
        if ($full && is_file($full)) {
            return @unlink($full);
        }
        return false;
    }

    /**
     * Resolve o caminho absoluto a partir do path armazenado no banco.
     * Retorna null se o arquivo estiver fora dos diretórios permitidos
     * (proteção contra path traversal).
     */
    public static function resolvePath(string $relativePath): ?string
    {
        // Rejeita qualquer tentativa de path traversal.
        if (strpos($relativePath, '..') !== false) return null;

        $projectRoot = realpath(__DIR__ . '/../../');
        if (!$projectRoot) return null;

        if (str_starts_with($relativePath, 'storage/')) {
            $base = $projectRoot . '/';
        } elseif (str_starts_with($relativePath, 'uploads/')) {
            $base = $projectRoot . '/public/';
        } else {
            // Retrocompatibilidade: paths antigos sem prefixo (ficam em public/).
            $base = $projectRoot . '/public/';
        }

        $full = realpath($base . $relativePath);
        if (!$full) return null;

        // Garante que o resultado está dentro do projeto.
        if (!str_starts_with($full, $projectRoot)) return null;
        return $full;
    }

    /**
     * Indica se o path armazenado é privado (requer DownloadController).
     */
    public static function isPrivatePath(?string $relativePath): bool
    {
        return $relativePath !== null && str_starts_with($relativePath, 'storage/');
    }

    /**
     * Gera a URL para acessar um arquivo. Arquivos privados (storage/) são
     * servidos pelo DownloadController. Arquivos públicos (uploads/) são
     * servidos diretamente pelo servidor web.
     *
     * @param string $path    Caminho armazenado no banco.
     * @param string $type    Tipo do recurso para o DownloadController
     *                        (document|certificate|expiration|resume).
     * @param int    $id      ID do registro dono do arquivo.
     */
    public static function url(?string $path, string $type = '', int $id = 0): string
    {
        if (!$path) return '';
        if (self::isPrivatePath($path) || (!str_starts_with($path, 'uploads/') && $type && $id)) {
            // Para paths antigos sem prefixo uploads/, também usa controller se
            // tipo/id forem fornecidos (retrocompatibilidade segura).
            return 'index.php?page=files&action=get&type=' . urlencode($type) . '&id=' . (int)$id;
        }
        // Arquivo público — servido diretamente.
        return (defined('ASSET_URL') ? ASSET_URL : '') . $path;
    }

    /**
     * Traduz código de erro de upload
     */
    private static function getUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE   => 'Arquivo excede o limite do servidor.',
            UPLOAD_ERR_FORM_SIZE  => 'Arquivo excede o limite do formulário.',
            UPLOAD_ERR_PARTIAL    => 'Upload incompleto.',
            UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário não encontrado.',
            UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar arquivo no disco.',
            UPLOAD_ERR_EXTENSION  => 'Upload bloqueado por extensão do servidor.',
            default               => 'Erro desconhecido no upload.',
        };
    }
}
