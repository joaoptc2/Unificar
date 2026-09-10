<?php
/**
 * Upload seguro de arquivos do módulo RH.
 * - Renomeia com hash
 * - Valida MIME type
 * - Limita tamanho
 * - Bloqueia execução
 *
 * Arquivos PÚBLICOS (fotos, capas — exibidos em <img>) vivem em
 * /uploads/rh/<subdir>/ (RH_UPLOADS_PATH). Arquivos PRIVADOS (documentos,
 * atestados, currículos, anexos de comunicados…) vivem em
 * /storage/uploads/rh/<subdir>/ (STORAGE_PATH) — diretório bloqueado pelo
 * .htaccess raiz da plataforma e nunca exposto por URL: só o
 * DownloadController os entrega, após autenticação e permissão.
 * O caminho ARMAZENADO no banco mantém o formato legado:
 *   - `uploads/<sub>/<arquivo>`          → público
 *   - `storage/uploads/<sub>/<arquivo>`  → privado (servido pelo DownloadController)
 * Arquivos privados gravados por versões anteriores em /uploads/rh/<sub>/
 * continuam sendo localizados (resolvePath tenta os dois locais).
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
     * ex.: fotos de perfil exibidas em <img src>). Os demais são gravados em
     * storage/uploads/rh/ (fora da área pública) e só acessíveis via
     * DownloadController com autenticação/permissão.
     */
    private static array $publicSubdirs = ['employees', 'photos', 'announcements', 'rewards'];

    /** Diretório físico base dos uploads PÚBLICOS do módulo (/uploads/rh). */
    public static function baseDir(): string
    {
        if (defined('RH_UPLOADS_PATH')) {
            return rtrim(RH_UPLOADS_PATH, '/');
        }
        if (defined('UPLOADS_PATH')) {
            return UPLOADS_PATH . '/rh';
        }
        return dirname(__DIR__, 4) . '/uploads/rh';
    }

    /** Diretório físico dos uploads PRIVADOS do módulo (/storage/uploads/rh — fora da área pública). */
    public static function privateDir(): string
    {
        if (defined('STORAGE_PATH')) {
            return rtrim(STORAGE_PATH, '/') . '/uploads/rh';
        }
        return dirname(__DIR__, 4) . '/storage/uploads/rh';
    }

    /** Subdiretório público (servido por URL) ou privado (DownloadController)? */
    public static function isPublicSubdir(string $subDir): bool
    {
        return in_array($subDir, self::$publicSubdirs, true);
    }

    /**
     * Processa upload de arquivo
     *
     * @param string $fieldName Nome do campo do formulário
     * @param string $subDir Subdiretório (employees, photos, documents, resumes, certificates, announcements, rewards)
     * @param string[]|null $onlyExtensions Restringe ainda mais as extensões (ex.: ['jpg','jpeg','png'] para imagens)
     * @return array ['success' => bool, 'path' => string, 'original_name' => string, 'size' => int, 'error' => string]
     *               `path` é armazenado no banco. Se começar com `uploads/` é público;
     *               se começar com `storage/` é privado (servir via DownloadController).
     */
    public static function handle(string $fieldName, string $subDir = 'documents', ?array $onlyExtensions = null): array
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
        if (!in_array($ext, self::$allowedExtensions) || ($onlyExtensions !== null && !in_array($ext, $onlyExtensions, true))) {
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

        // Sanitiza o subdiretório (sem path traversal).
        $subDir = preg_replace('/[^a-z0-9_-]/i', '', $subDir) ?: 'documents';

        $isPublic   = self::isPublicSubdir($subDir);
        $destDir    = ($isPublic ? self::baseDir() : self::privateDir()) . '/' . $subDir . '/';
        $pathPrefix = $isPublic ? 'uploads/' : 'storage/uploads/';

        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }
        if (!$isPublic) {
            // Raiz privada: nega tudo (defesa em profundidade — o .htaccess da
            // plataforma já bloqueia /storage/ e o diretório nunca é linkado).
            $rootHt = self::privateDir() . '/.htaccess';
            if (!file_exists($rootHt)) {
                @file_put_contents($rootHt, "Require all denied\n");
            }
        }

        // .htaccess por subdiretório: públicos permitem leitura mas nunca
        // execução; privados são negados.
        $htaccess = $destDir . '.htaccess';
        if (!file_exists($htaccess)) {
            if ($isPublic) {
                @file_put_contents(
                    $htaccess,
                    "Require all granted\n" .
                    "Options -ExecCGI\n" .
                    "RemoveHandler .php .phtml .php3 .php4 .php5 .phps\n" .
                    "AddType text/plain .php .phtml .php3 .php4 .php5 .phps\n" .
                    "<FilesMatch \"\\.(php|phtml|php3|php4|php5|phps)$\">\n" .
                    "    Require all denied\n" .
                    "</FilesMatch>\n"
                );
            } else {
                @file_put_contents($htaccess, "Require all denied\n");
            }
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
     * `storage/uploads/...` (formatos legados armazenados no banco).
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
     * Privados (`storage/uploads/...`): procura em /storage/uploads/rh/ e, para
     * arquivos gravados por versões anteriores, em /uploads/rh/. Públicos:
     * /uploads/rh/. Retorna null se o arquivo não existir ou estiver fora
     * desses diretórios (proteção contra path traversal).
     */
    public static function resolvePath(string $relativePath): ?string
    {
        // Rejeita qualquer tentativa de path traversal.
        if (strpos($relativePath, '..') !== false) return null;

        $inner = $relativePath;
        $roots = [self::baseDir()];
        if (str_starts_with($inner, 'storage/uploads/')) {
            $inner = substr($inner, strlen('storage/uploads/'));
            $roots = [self::privateDir(), self::baseDir()];
        } elseif (str_starts_with($inner, 'uploads/')) {
            $inner = substr($inner, strlen('uploads/'));
        }
        $inner = ltrim($inner, '/');
        if ($inner === '') return null;

        foreach ($roots as $root) {
            $base = realpath($root);
            if (!$base) continue;
            $full = realpath($base . '/' . $inner);
            // Garante que o resultado está dentro do diretório raiz.
            if ($full && is_file($full) && str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
                return $full;
            }
        }
        return null;
    }

    /**
     * Indica se o path armazenado é privado (requer DownloadController).
     */
    public static function isPrivatePath(?string $relativePath): bool
    {
        return $relativePath !== null && str_starts_with($relativePath, 'storage/');
    }

    /**
     * URL pública direta de um arquivo `uploads/<sub>/<nome>` (fotos etc.).
     */
    public static function publicUrl(?string $path): string
    {
        if (!$path) return '';
        $inner = str_starts_with($path, 'uploads/') ? substr($path, strlen('uploads/')) : $path;
        return core_url('uploads/rh/' . ltrim($inner, '/'));
    }

    /**
     * Gera a URL para acessar um arquivo. Arquivos privados (storage/) são
     * servidos pelo DownloadController. Arquivos públicos (uploads/) são
     * servidos diretamente pelo servidor web em /uploads/rh/.
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
            return 'index.php?m=rh&page=files&action=get&type=' . urlencode($type) . '&id=' . (int)$id;
        }
        // Arquivo público — servido diretamente.
        return self::publicUrl($path);
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
