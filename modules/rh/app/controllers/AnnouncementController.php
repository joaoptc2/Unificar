<?php
/**
 * AnnouncementController — comunicados internos.
 *
 *   page=announcements                      lista (announcements.view)
 *   action=create|store                     novo (announcements.create) — editor rico, imagem, anexo, portal, e-mail
 *   action=edit|update                      editar (announcements.edit)
 *   action=delete                           excluir (announcements.delete)
 *   action=read&id=N                        leitura formatada (marca como lido)
 *   action=publish                          publicar rascunho (announcements.create)
 */
class AnnouncementController
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function index(): void
    {
        core_require('announcements.view');
        $manage = core_can_any(['announcements.create', 'announcements.edit']);
        $items = Announcement::published(null, (int)Session::userId(), $manage);
        View::render('announcements/index', ['pageTitle' => 'Comunicados', 'page' => 'announcements', 'items' => $items, 'manage' => $manage]);
    }

    public function create(): void
    {
        core_require('announcements.create');
        $this->form(null);
    }

    public function edit(): void
    {
        core_require('announcements.edit');
        $item = Announcement::find(Sanitize::int($_GET['id'] ?? 0));
        if (!$item) { Session::flash('error', 'Comunicado não encontrado.'); header('Location: index.php?m=rh&page=announcements'); exit; }
        $this->form($item);
    }

    private function form(?array $item): void
    {
        $depts = $this->db->query('SELECT id, name FROM rh_departments WHERE active = 1 ORDER BY name')->fetchAll();
        $layout = Core\DocLayout::findOrDefault(null);
        $editorCfg = Core\DocLayout::editorConfig(null, [
            'full' => true, 'hiddenInput' => '#bodyHtmlField', 'placeholder' => 'Escreva o comunicado…',
            'sheetWidth' => 0,
        ]);
        View::render('announcements/form', [
            'pageTitle' => $item ? 'Editar Comunicado' : 'Novo Comunicado', 'page' => 'announcements',
            'departments' => $depts, 'item' => $item, 'editorCfg' => $editorCfg,
            'extraCss' => null, 'extraJs' => null,
        ]);
    }

    public function store(): void
    {
        core_require('announcements.create'); Csrf::check();
        $data = $this->formData(null);
        if ($data === null) { header('Location: index.php?m=rh&page=announcements&action=create'); exit; }
        $data['created_by'] = Session::userId();
        $id = Announcement::insert($data);
        AuditLog::log('create', 'announcements', $id);
        $this->afterSave($id, $data, 'Comunicado ' . ($data['published_at'] ? 'publicado' : 'salvo como rascunho') . '.');
    }

    public function update(): void
    {
        core_require('announcements.edit'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $old = Announcement::find($id);
        if (!$old) { Session::flash('error', 'Comunicado não encontrado.'); header('Location: index.php?m=rh&page=announcements'); exit; }
        $data = $this->formData($old);
        if ($data === null) { header('Location: index.php?m=rh&page=announcements&action=edit&id=' . $id); exit; }
        Announcement::update($id, $data);
        AuditLog::log('update', 'announcements', $id);
        $this->afterSave($id, $data, 'Comunicado atualizado.');
    }

    /** Publica um rascunho (POST). */
    public function publish(): void
    {
        core_require('announcements.create'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $item = Announcement::find($id);
        if (!$item) { Session::flash('error', 'Comunicado não encontrado.'); header('Location: index.php?m=rh&page=announcements'); exit; }
        if (empty($item['published_at'])) {
            Announcement::update($id, ['published_at' => date('Y-m-d H:i:s')]);
            AuditLog::log('publish', 'announcements', $id);
            $this->afterSave($id, ['published_at' => date('Y-m-d H:i:s')], 'Comunicado publicado.');
        }
        header('Location: index.php?m=rh&page=announcements'); exit;
    }

    private function afterSave(int $id, array $data, string $msg): void
    {
        if (!empty($data['published_at']) && strtotime($data['published_at']) <= time()) {
            $stats = Announcement::dispatch($id);
            if ($stats['notified']) { $msg .= " {$stats['notified']} funcionário(s) notificado(s) no portal."; }
            if ($stats['queued'])   { $msg .= " {$stats['queued']} e-mail(s) enfileirado(s)."; }
        }
        Session::flash('success', $msg);
        header('Location: index.php?m=rh&page=announcements'); exit;
    }

    /** Lê e valida o formulário; null = erro (flash já definida). Trata uploads. */
    private function formData(?array $old): ?array
    {
        $title   = mb_substr(Sanitize::post('title'), 0, 200);
        $html    = Core\DocLayout::sanitizeHtml((string)($_POST['body_html'] ?? ''));
        $text    = trim(html_entity_decode(strip_tags(preg_replace('#</(p|div|li|h[1-6]|br)\s*>#i', "\n", $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '' && trim(Sanitize::post('body')) !== '') { $text = Sanitize::post('body'); $html = nl2br(Sanitize::e($text)); }
        if ($title === '' || $text === '') { Session::flash('error', 'Título e corpo do comunicado são obrigatórios.'); return null; }

        $type = Sanitize::post('type');
        if (!isset(Announcement::TYPES[$type])) { $type = 'informativo'; }

        $publish = !empty($_POST['publish_now']);
        $publishedAt = $old['published_at'] ?? null;
        if ($publish && empty($publishedAt)) { $publishedAt = date('Y-m-d H:i:s'); }
        if (!$publish && !empty($_POST['unpublish'])) { $publishedAt = null; }

        $data = [
            'title' => $title, 'summary' => mb_substr(Sanitize::post('summary'), 0, 300) ?: null,
            'body' => $text, 'body_html' => $html, 'type' => $type,
            'department_id' => Sanitize::int($_POST['department_id'] ?? 0) ?: null,
            'published_at' => $publishedAt,
            'expires_at' => Sanitize::date($_POST['expires_at'] ?? '') ?: null,
            'pinned' => !empty($_POST['pinned']) ? 1 : 0,
            'show_in_portal' => !empty($_POST['show_in_portal']) ? 1 : 0,
            'send_email' => !empty($_POST['send_email']) ? 1 : 0,
        ];

        // Imagem de capa (pública) e anexo (privado, servido pelo DownloadController).
        if (!empty($_FILES['image']['name'])) {
            $up = Upload::handle('image', 'announcements', ['jpg', 'jpeg', 'png']);
            if (!$up['success']) { Session::flash('error', 'Imagem: ' . $up['error']); return null; }
            if (!empty($old['image_path'])) { Upload::delete($old['image_path']); }
            $data['image_path'] = $up['path'];
        } elseif (!empty($_POST['remove_image']) && !empty($old['image_path'])) {
            Upload::delete($old['image_path']); $data['image_path'] = null;
        }
        if (!empty($_FILES['attachment']['name'])) {
            $up = Upload::handle('attachment', 'announcement_files');
            if (!$up['success']) { Session::flash('error', 'Anexo: ' . $up['error']); return null; }
            if (!empty($old['attachment_path'])) { Upload::delete($old['attachment_path']); }
            $data['attachment_path'] = $up['path'];
            $data['attachment_name'] = mb_substr($up['original_name'], 0, 255);
        } elseif (!empty($_POST['remove_attachment']) && !empty($old['attachment_path'])) {
            Upload::delete($old['attachment_path']); $data['attachment_path'] = null; $data['attachment_name'] = null;
        }
        return $data;
    }

    public function read(): void
    {
        core_require('announcements.view');
        $id = Sanitize::int($_GET['id'] ?? 0);
        $item = Announcement::find($id);
        if (!$item) { header('Location: index.php?m=rh&page=announcements'); exit; }
        if (empty($item['published_at']) && !core_can_any(['announcements.create', 'announcements.edit'])) {
            header('Location: index.php?m=rh&page=announcements'); exit;
        }
        Announcement::markRead($id, (int)Session::userId());
        View::render('announcements/show', ['pageTitle' => $item['title'], 'page' => 'announcements', 'item' => $item]);
    }

    public function delete(): void
    {
        core_require('announcements.delete'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $item = Announcement::find($id);
        if ($item) {
            if (!empty($item['image_path'])) { Upload::delete($item['image_path']); }
            if (!empty($item['attachment_path'])) { Upload::delete($item['attachment_path']); }
            Announcement::delete($id);
            AuditLog::log('delete', 'announcements', $id);
        }
        Session::flash('success', 'Comunicado excluído.'); header('Location: index.php?m=rh&page=announcements'); exit;
    }
}
