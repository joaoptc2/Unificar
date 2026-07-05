<?php
/**
 * SettingsController — página de configuração visual do sistema.
 * Apenas admins. Salva chave-valor na tabela `settings`.
 *
 * Rota: ?page=settings
 */
class SettingsController
{
    private PDO $db;

    /** Chaves de aparência com valores padrão (usados quando não houver registro no banco). */
    private const DEFAULTS = [
        'app_name'            => 'RH Hospital',
        'theme_primary'       => '#0d6efd',
        'theme_sidebar_bg'    => '#1e293b',
        'theme_sidebar_text'  => '#94a3b8',
        'theme_sidebar_hover' => '#334155',
        'theme_page_bg'       => '#f1f5f9',
        'theme_navbar_bg'     => '#0d6efd',
        'theme_font_family'   => "'Segoe UI', system-ui, -apple-system, sans-serif",
        'theme_border_radius' => '0.75',
        'theme_badge_ativo'   => '#10b981',
        'theme_badge_alerta'  => '#f59e0b',
        'theme_badge_perigo'  => '#ef4444',
        'theme_login_gradient_start' => '#0d6efd',
        'theme_login_gradient_end'   => '#6610f2',
        'theme_logo_icon'     => 'bi-hospital',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        Auth::requirePermission('users', 'view'); // apenas admin

        $current = $this->loadAll();

        View::render('settings/index', [
            'pageTitle' => 'Personalização',
            'page'      => 'settings',
            'settings'  => $current,
            'defaults'  => self::DEFAULTS,
        ]);
    }

    public function update(): void
    {
        Auth::requirePermission('users', 'edit');
        Csrf::check();

        $allowed = array_keys(self::DEFAULTS);
        $stmt = $this->db->prepare(
            'INSERT INTO settings (setting_key, setting_value, description)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );

        foreach ($allowed as $key) {
            if (!isset($_POST[$key])) continue;
            $val = trim((string)$_POST[$key]);
            $stmt->execute([$key, $val, 'Personalização visual']);
        }

        // Atualiza app_name no config/app.php para manter consistência nos crons/e-mails.
        $newName = trim((string)($_POST['app_name'] ?? ''));
        if ($newName !== '') {
            $appFile = __DIR__ . '/../../config/app.php';
            $content = file_get_contents($appFile);
            $content = preg_replace(
                "/'app_name'\s*=>\s*'[^']*'/",
                "'app_name' => " . var_export($newName, true),
                $content
            );
            @file_put_contents($appFile, $content);
        }

        AuditLog::log('update', 'settings', null, null, $_POST);
        FileCache::forget('theme.css');

        Session::flash('success', 'Personalização salva com sucesso.');
        header('Location: index.php?page=settings');
        exit;
    }

    public function reset(): void
    {
        Auth::requirePermission('users', 'edit');
        Csrf::check();

        $keys = array_keys(self::DEFAULTS);
        $ph   = implode(',', array_fill(0, count($keys), '?'));
        $this->db->prepare("DELETE FROM settings WHERE setting_key IN ($ph)")->execute($keys);

        FileCache::forget('theme.css');
        AuditLog::log('delete', 'settings', null, null, ['action' => 'reset_theme']);

        Session::flash('success', 'Visual restaurado para o padrão.');
        header('Location: index.php?page=settings');
        exit;
    }

    // -----------------------------------------------------------------

    private function loadAll(): array
    {
        $rows = $this->db->query(
            "SELECT setting_key, setting_value FROM settings"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $merged = self::DEFAULTS;
        foreach ($merged as $k => &$v) {
            if (isset($rows[$k]) && $rows[$k] !== '') $v = $rows[$k];
        }
        return $merged;
    }

    /**
     * Retorna as configurações de tema mescladas (pode ser chamado de qualquer lugar).
     */
    public static function theme(): array
    {
        return FileCache::remember('theme.css', 600, function () {
            try {
                $db  = Database::getInstance();
                $rows = $db->query("SELECT setting_key, setting_value FROM settings")
                           ->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (\Throwable $e) {
                $rows = [];
            }
            $merged = self::DEFAULTS;
            foreach ($merged as $k => &$v) {
                if (isset($rows[$k]) && $rows[$k] !== '') $v = $rows[$k];
            }
            return $merged;
        });
    }
}
