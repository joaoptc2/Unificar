<?php
/**
 * AdminController — Painel administrativo do módulo.
 *
 * A gestão de USUÁRIOS saiu do módulo: usuários são globais e são
 * administrados no núcleo (?m=admin&a=users). Aqui ficam apenas as telas
 * de domínio do chat: dashboard, configurações, emojis, categorias,
 * exportação e o log de auditoria (global, filtrado por module='chat').
 *
 * Gates por MICROPERMISSÃO em cada ação (não mais por nível):
 *   dashboard/auditoria → admin.view; configurações → admin.settings;
 *   exportação → admin.export; emojis → emojis.*; categorias → categories.*.
 */
class AdminController
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();

        Auth::requireLogin();
    }

    /* ------------------------------------------------------------------
     *  index  — Dashboard com estatísticas
     *  GET ?m=chat&page=admin
     * ----------------------------------------------------------------*/
    public function index(): void
    {
        core_require('admin.view');

        // Usuários ativos (tabela global)
        $totalUsers = User::count('active = 1');

        // Canais ativos
        $totalChannels = Channel::count('is_archived = 0');

        // Mensagens enviadas hoje
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM chat_messages WHERE DATE(created_at) = CURDATE() AND deleted_at IS NULL'
        );
        $stmt->execute();
        $messagesToday = (int) $stmt->fetchColumn();

        // Tarefas ativas
        $activeTasks = Task::count('status NOT IN ("done","cancelled")');

        View::render('admin/index', [
            'pageTitle'     => 'Painel Administrativo',
            'totalUsers'    => $totalUsers,
            'totalChannels' => $totalChannels,
            'messagesToday' => $messagesToday,
            'activeTasks'   => $activeTasks,
        ]);
    }

    /* ------------------------------------------------------------------
     *  settings  — Configurações do módulo (chat_settings)
     * ----------------------------------------------------------------*/
    public function settings(): void
    {
        core_require('admin.settings');

        $stmt = $this->db->query('SELECT `key`, `value` FROM chat_settings ORDER BY `key` ASC');
        $rows = $stmt->fetchAll();

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        View::render('admin/settings', [
            'pageTitle' => 'Configurações',
            'settings'  => $settings,
        ]);
    }

    public function updateSettings(): void
    {
        core_require('admin.settings');
        Csrf::check();

        $allowedKeys = ['app_name','allow_registration','primary_color','sidebar_bg','sidebar_text'];

        $stmtUpsert = $this->db->prepare(
            'INSERT INTO chat_settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
        );

        $fields = [];
        foreach ($allowedKeys as $key) {
            if (!isset($_POST[$key])) continue;
            $value = Sanitize::string($_POST[$key]);
            $stmtUpsert->execute([$key, $value]);
            $fields[$key] = $value;
        }

        AuditLog::log('update_settings', 'settings', null, null, $fields);

        Session::flash('success', 'Configurações salvas com sucesso.');
        header('Location: index.php?m=chat&page=admin&action=settings');
        exit;
    }

    /* ------------------------------------------------------------------
     *  audit — Log de auditoria (tabela GLOBAL audit_log, module='chat')
     * ----------------------------------------------------------------*/
    public function audit(): void
    {
        core_require('admin.view');

        $search = [
            'user'        => Sanitize::get('user'),
            'action_type' => Sanitize::get('action_type'),
            'entity_type' => Sanitize::get('entity_type'),
            'date_from'   => Sanitize::get('date_from'),
            'date_to'     => Sanitize::get('date_to'),
        ];

        $where = ['a.module = ?']; $params = ['chat'];
        if ($search['user']) {
            $where[] = 'u.name LIKE ?'; $params[] = '%'.$search['user'].'%';
        }
        if ($search['action_type']) {
            $where[] = 'a.action = ?'; $params[] = $search['action_type'];
        }
        if ($search['entity_type']) {
            $where[] = 'a.entity LIKE ?'; $params[] = '%'.$search['entity_type'].'%';
        }
        if ($search['date_from']) {
            $where[] = 'a.created_at >= ?'; $params[] = $search['date_from'] . ' 00:00:00';
        }
        if ($search['date_to']) {
            $where[] = 'a.created_at <= ?'; $params[] = $search['date_to'] . ' 23:59:59';
        }

        $wSql = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id = a.user_id $wSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $currentPage = max(1, Sanitize::int($_GET['p'] ?? 1));
        $pagination = new Pagination($total, $currentPage, 50);

        $stmt = $this->db->prepare(
            "SELECT a.*, a.entity AS entity_type, u.name AS user_name FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id $wSql
             ORDER BY a.created_at DESC LIMIT {$pagination->perPage} OFFSET {$pagination->offset}"
        );
        $stmt->execute($params);

        View::render('admin/audit', [
            'pageTitle'  => 'Log de Atividades',
            'page'       => 'admin',
            'logs'       => $stmt->fetchAll(),
            'pagination' => $pagination,
            'search'     => $search,
        ]);
    }

    // ---- CUSTOM EMOJIS (#29) ----
    public function emojis(): void
    {
        core_require('emojis.view');
        $emojis = $this->db->query('SELECT ce.*, u.name AS creator_name FROM chat_custom_emojis ce LEFT JOIN users u ON u.id = ce.created_by ORDER BY ce.name ASC')->fetchAll();
        View::render('admin/emojis', [
            'pageTitle' => 'Emojis Personalizados',
            'page'      => 'admin',
            'emojis'    => $emojis,
        ]);
    }

    public function addEmoji(): void
    {
        core_require('emojis.create');
        Csrf::check();
        $name = Sanitize::slug(Sanitize::post('name'));
        if (!$name) {
            Session::flash('error', 'Nome do emoji é obrigatório.');
            header('Location: index.php?m=chat&page=admin&action=emojis'); exit;
        }

        $upload = Upload::handle('image', 'avatars');
        if (!$upload['success']) {
            Session::flash('error', $upload['error']);
            header('Location: index.php?m=chat&page=admin&action=emojis'); exit;
        }

        $this->db->prepare('INSERT INTO chat_custom_emojis (name, image_path, created_by, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$name, $upload['path'], Session::userId()]);

        Session::flash('success', 'Emoji :' . $name . ': adicionado.');
        header('Location: index.php?m=chat&page=admin&action=emojis'); exit;
    }

    /** Alias legado (o formulário postava para storeEmoji). */
    public function storeEmoji(): void
    {
        $this->addEmoji();
    }

    public function deleteEmoji(): void
    {
        core_require('emojis.delete');
        Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        if ($id > 0) {
            $emoji = $this->db->prepare('SELECT image_path FROM chat_custom_emojis WHERE id = ?');
            $emoji->execute([$id]);
            $row = $emoji->fetch();
            if ($row) {
                Upload::delete($row['image_path']);
                $this->db->prepare('DELETE FROM chat_custom_emojis WHERE id = ?')->execute([$id]);
            }
        }
        Session::flash('success', 'Emoji removido.');
        header('Location: index.php?m=chat&page=admin&action=emojis'); exit;
    }

    // ---- EXPORT (#11) ----
    public function export(): void
    {
        core_require('admin.export');
        $channels = $this->db->query('SELECT id, name FROM chat_channels WHERE is_archived = 0 ORDER BY name ASC')->fetchAll();
        $exports = $this->db->query('SELECT el.*, u.name AS user_name FROM chat_export_logs el LEFT JOIN users u ON u.id = el.user_id ORDER BY el.created_at DESC LIMIT 20')->fetchAll();

        View::render('admin/export', [
            'pageTitle' => 'Exportar Dados',
            'page'      => 'admin',
            'channels'  => $channels,
            'exports'   => $exports,
        ]);
    }

    public function doExport(): void
    {
        core_require('admin.export');
        Csrf::check();
        $type      = Sanitize::post('type');
        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);
        $from      = Sanitize::post('date_from');
        $to        = Sanitize::post('date_to');

        $validTypes = ['messages','users','channels','tasks','audit_log'];
        if (!in_array($type, $validTypes, true)) {
            Session::flash('error', 'Tipo inválido.'); header('Location: index.php?m=chat&page=admin&action=export'); exit;
        }

        $filename = $type . '_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $wParts = []; $params = [];
        if ($from) { $wParts[] = 'created_at >= ?'; $params[] = "$from 00:00:00"; }
        if ($to)   { $wParts[] = 'created_at <= ?'; $params[] = "$to 23:59:59"; }
        $wSql = $wParts ? ' WHERE ' . implode(' AND ', $wParts) : '';

        if ($type === 'messages') {
            if ($channelId > 0) { $wParts[] = 'channel_id = ?'; $params[] = $channelId; $wSql = ' WHERE ' . implode(' AND ', $wParts); }
            fputcsv($out, ['ID','Canal','Usuário','Conteúdo','Tipo','Criado em']);
            $stmt = $this->db->prepare("SELECT m.id, c.name AS channel_name, u.name AS user_name, m.content, m.type, m.created_at FROM chat_messages m LEFT JOIN chat_channels c ON c.id = m.channel_id LEFT JOIN users u ON u.id = m.user_id $wSql ORDER BY m.created_at DESC LIMIT 50000");
            $stmt->execute($params);
            while ($row = $stmt->fetch()) fputcsv($out, $row);
        } elseif ($type === 'users') {
            fputcsv($out, ['ID','Nome','Email','Perfil','Status','Criado em']);
            $stmt = $this->db->query(
                "SELECT u.id, u.name, u.email,
                        COALESCE(uma.role, '') AS role,
                        COALESCE(p.status, 'offline') AS status,
                        u.created_at
                 FROM users u
                 LEFT JOIN user_module_access uma ON uma.user_id = u.id AND uma.module_slug = 'chat'
                 LEFT JOIN chat_presence p ON p.user_id = u.id
                 ORDER BY u.name ASC"
            );
            while ($row = $stmt->fetch()) fputcsv($out, $row);
        } elseif ($type === 'channels') {
            fputcsv($out, ['ID','Nome','Tipo','Membros','Criado em']);
            $stmt = $this->db->query("SELECT c.id, c.name, c.type, (SELECT COUNT(*) FROM chat_channel_members cm WHERE cm.channel_id = c.id) AS member_count, c.created_at FROM chat_channels c ORDER BY c.name ASC");
            while ($row = $stmt->fetch()) fputcsv($out, $row);
        } elseif ($type === 'tasks') {
            fputcsv($out, ['ID','Título','Status','Prioridade','Criado por','Data limite','Criado em']);
            $stmt = $this->db->prepare("SELECT t.id, t.title, t.status, t.priority, u.name, t.due_date, t.created_at FROM chat_tasks t LEFT JOIN users u ON u.id = t.created_by $wSql ORDER BY t.created_at DESC");
            $stmt->execute($params);
            while ($row = $stmt->fetch()) fputcsv($out, $row);
        } elseif ($type === 'audit_log') {
            $wParts[] = 'a.module = ?'; $params[] = 'chat';
            $wSql = ' WHERE ' . implode(' AND ', $wParts);
            fputcsv($out, ['ID','Usuário','Ação','Entidade','Entity ID','IP','Data']);
            $stmt = $this->db->prepare("SELECT a.id, u.name, a.action, a.entity, a.entity_id, a.ip_address, a.created_at FROM audit_log a LEFT JOIN users u ON u.id = a.user_id $wSql ORDER BY a.created_at DESC LIMIT 50000");
            $stmt->execute($params);
            while ($row = $stmt->fetch()) fputcsv($out, $row);
        }

        fclose($out);
        exit;
    }

    /** Alias legado (o formulário postava para generateExport). */
    public function generateExport(): void
    {
        $this->doExport();
    }

    // ---- CHANNEL CATEGORIES (#30) ----
    public function categories(): void
    {
        core_require('categories.view');
        $categories = $this->db->query('SELECT * FROM chat_channel_categories ORDER BY order_num ASC')->fetchAll();
        $channels = $this->db->query('SELECT id, name, category_id FROM chat_channels WHERE is_archived = 0 ORDER BY name ASC')->fetchAll();

        View::render('admin/categories', [
            'pageTitle'  => 'Categorias de Canais',
            'page'       => 'admin',
            'categories' => $categories,
            'channels'   => $channels,
        ]);
    }

    public function saveCategory(): void
    {
        $id = Sanitize::int($_POST['id'] ?? 0);
        core_require($id > 0 ? 'categories.edit' : 'categories.create');
        Csrf::check();
        $name  = Sanitize::post('name');
        $order = Sanitize::int($_POST['order_num'] ?? 0);

        if (!$name) { Session::flash('error', 'Nome obrigatório.'); header('Location: index.php?m=chat&page=admin&action=categories'); exit; }

        if ($id > 0) {
            $this->db->prepare('UPDATE chat_channel_categories SET name = ?, order_num = ? WHERE id = ?')->execute([$name, $order, $id]);
        } else {
            $this->db->prepare('INSERT INTO chat_channel_categories (name, order_num, created_by, created_at) VALUES (?, ?, ?, NOW())')->execute([$name, $order, Session::userId()]);
        }

        $channelIds = $_POST['channel_ids'] ?? [];
        if ($id > 0 || !$id) {
            $catId = $id > 0 ? $id : (int) $this->db->lastInsertId();
            $this->db->prepare('UPDATE chat_channels SET category_id = NULL WHERE category_id = ?')->execute([$catId]);
            if (is_array($channelIds)) {
                $stmt = $this->db->prepare('UPDATE chat_channels SET category_id = ? WHERE id = ?');
                foreach ($channelIds as $cid) $stmt->execute([$catId, (int)$cid]);
            }
        }

        Session::flash('success', 'Categoria salva.');
        header('Location: index.php?m=chat&page=admin&action=categories'); exit;
    }

    public function deleteCategory(): void
    {
        core_require('categories.delete');
        Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        if ($id > 0) {
            $this->db->prepare('UPDATE chat_channels SET category_id = NULL WHERE category_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM chat_channel_categories WHERE id = ?')->execute([$id]);
        }
        Session::flash('success', 'Categoria removida.');
        header('Location: index.php?m=chat&page=admin&action=categories'); exit;
    }
}
