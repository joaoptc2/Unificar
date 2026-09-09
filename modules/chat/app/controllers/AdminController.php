<?php
/**
 * AdminController — configuração do módulo: categorias de canais e
 * emojis personalizados.
 *
 * As TELAS são exibidas na Administração central (admin_panel.php →
 * ?m=admin&a=module&slug=chat&tab=categories|emojis). Os POSTs chegam por
 * ?m=chat&page=admin&action=... e, ao terminar, voltam ao painel central.
 * GET em page=admin é redirecionado pelo index.php do módulo.
 *
 * Gates: categories.* e emojis.* (únicas permissões de configuração).
 */
class AdminController
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        Auth::requireLogin();
    }

    public function index(): void
    {
        core_redirect(core_admin_url('chat'));
    }

    /* ------------------------------------------------------------------
     *  Categorias de canais
     * ----------------------------------------------------------------*/
    public function categories(): void
    {
        core_require('categories.view');

        $categories = $this->db->query('SELECT * FROM chat_channel_categories ORDER BY order_num ASC, name ASC')->fetchAll();
        $channels   = $this->db->query(
            'SELECT id, name, type, category_id FROM chat_channels
             WHERE is_archived = 0 AND type <> "direct" ORDER BY name ASC'
        )->fetchAll();

        View::render('admin/categories', [
            'pageTitle'  => 'Categorias de canais',
            'categories' => $categories,
            'channels'   => $channels,
        ]);
    }

    public function saveCategory(): void
    {
        $id = Sanitize::int($_POST['id'] ?? 0);
        core_require($id > 0 ? 'categories.edit' : 'categories.create');
        Csrf::check();

        $name  = mb_substr(Sanitize::post('name'), 0, 200);
        $order = max(0, Sanitize::int($_POST['order_num'] ?? 0));

        if ($name === '') {
            Session::flash('error', 'O nome da categoria é obrigatório.');
            core_redirect(core_admin_url('chat', 'categories'));
        }

        if ($id > 0) {
            $exists = $this->db->prepare('SELECT id FROM chat_channel_categories WHERE id = ?');
            $exists->execute([$id]);
            if (!$exists->fetch()) {
                Session::flash('error', 'Categoria não encontrada.');
                core_redirect(core_admin_url('chat', 'categories'));
            }
            $this->db->prepare('UPDATE chat_channel_categories SET name = ?, order_num = ? WHERE id = ?')
                     ->execute([$name, $order, $id]);
            $catId = $id;
        } else {
            $this->db->prepare('INSERT INTO chat_channel_categories (name, order_num, created_by, created_at) VALUES (?, ?, ?, NOW())')
                     ->execute([$name, $order, Session::userId()]);
            $catId = (int) $this->db->lastInsertId();
        }

        // Canais da categoria (substitui a seleção anterior)
        $channelIds = array_filter(array_map('intval', (array) ($_POST['channel_ids'] ?? [])));
        $this->db->prepare('UPDATE chat_channels SET category_id = NULL WHERE category_id = ?')->execute([$catId]);
        if ($channelIds) {
            $in = implode(',', array_fill(0, count($channelIds), '?'));
            $this->db->prepare("UPDATE chat_channels SET category_id = ? WHERE type <> 'direct' AND id IN ($in)")
                     ->execute(array_merge([$catId], array_values($channelIds)));
        }

        AuditLog::log($id > 0 ? 'update_category' : 'create_category', 'channel_category', $catId, null, [
            'name' => $name, 'order_num' => $order, 'channels' => array_values($channelIds),
        ]);

        Session::flash('success', 'Categoria salva.');
        core_redirect(core_admin_url('chat', 'categories'));
    }

    public function deleteCategory(): void
    {
        core_require('categories.delete');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        if ($id > 0) {
            $this->db->prepare('UPDATE chat_channels SET category_id = NULL WHERE category_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM chat_channel_categories WHERE id = ?')->execute([$id]);
            AuditLog::log('delete_category', 'channel_category', $id);
        }
        Session::flash('success', 'Categoria removida.');
        core_redirect(core_admin_url('chat', 'categories'));
    }

    /* ------------------------------------------------------------------
     *  Emojis personalizados
     * ----------------------------------------------------------------*/
    public function emojis(): void
    {
        core_require('emojis.view');

        $emojis = $this->db->query(
            'SELECT ce.*, u.name AS creator_name FROM chat_custom_emojis ce
             LEFT JOIN users u ON u.id = ce.created_by ORDER BY ce.name ASC'
        )->fetchAll();

        View::render('admin/emojis', [
            'pageTitle' => 'Emojis personalizados',
            'emojis'    => $emojis,
        ]);
    }

    public function addEmoji(): void
    {
        core_require('emojis.create');
        Csrf::check();

        $raw  = trim(Sanitize::post('name'), ": \t");
        $name = mb_substr(preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower($raw)), 0, 50);
        $name = trim($name, '_');

        if ($name === '') {
            Session::flash('error', 'Informe o nome do emoji (letras minúsculas, números e "_").');
            core_redirect(core_admin_url('chat', 'emojis'));
        }

        $dup = $this->db->prepare('SELECT id FROM chat_custom_emojis WHERE name = ?');
        $dup->execute([$name]);
        if ($dup->fetch()) {
            Session::flash('error', 'Já existe um emoji com o nome :' . $name . ':.');
            core_redirect(core_admin_url('chat', 'emojis'));
        }

        $upload = Upload::handle('image', 'emojis', true);
        if (!$upload['success']) {
            Session::flash('error', $upload['error']);
            core_redirect(core_admin_url('chat', 'emojis'));
        }

        $this->db->prepare('INSERT INTO chat_custom_emojis (name, image_path, created_by, created_at) VALUES (?, ?, ?, NOW())')
                 ->execute([$name, $upload['path'], Session::userId()]);
        AuditLog::log('create_emoji', 'custom_emoji', (int) $this->db->lastInsertId(), null, ['name' => $name]);

        Session::flash('success', 'Emoji :' . $name . ': adicionado.');
        core_redirect(core_admin_url('chat', 'emojis'));
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
            $stmt = $this->db->prepare('SELECT name, image_path FROM chat_custom_emojis WHERE id = ?');
            $stmt->execute([$id]);
            if ($row = $stmt->fetch()) {
                Upload::delete((string) $row['image_path']);
                $this->db->prepare('DELETE FROM chat_custom_emojis WHERE id = ?')->execute([$id]);
                AuditLog::log('delete_emoji', 'custom_emoji', $id, ['name' => $row['name']]);
            }
        }
        Session::flash('success', 'Emoji removido.');
        core_redirect(core_admin_url('chat', 'emojis'));
    }
}
