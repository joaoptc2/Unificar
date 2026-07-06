<?php
class AnnouncementController
{
    public function index(): void
    {
        core_require('announcements.view');
        $items = Announcement::published();
        View::render('announcements/index', ['pageTitle' => 'Comunicados', 'page' => 'announcements', 'items' => $items]);
    }
    public function create(): void
    {
        core_require('announcements.create');
        $depts = Database::getInstance()->query('SELECT id, name FROM rh_departments WHERE active = 1 ORDER BY name')->fetchAll();
        View::render('announcements/form', ['pageTitle' => 'Novo Comunicado', 'page' => 'announcements', 'departments' => $depts, 'item' => null]);
    }
    public function store(): void
    {
        core_require('announcements.create'); Csrf::check();
        $title = Sanitize::post('title'); $body = Sanitize::post('body');
        if (!$title || !$body) { Session::flash('error', 'Titulo e corpo obrigatorios.'); header('Location: index.php?m=rh&page=announcements&action=create'); exit; }
        $publish = !empty($_POST['publish_now']);
        $id = Announcement::insert([
            'title' => $title, 'body' => $body, 'type' => Sanitize::post('type') ?: 'informativo',
            'department_id' => Sanitize::int($_POST['department_id'] ?? 0) ?: null,
            'published_at' => $publish ? date('Y-m-d H:i:s') : null,
            'expires_at' => Sanitize::date($_POST['expires_at'] ?? '') ?: null,
            'pinned' => !empty($_POST['pinned']) ? 1 : 0, 'created_by' => Session::userId(),
        ]);
        AuditLog::log('create', 'announcements', $id);
        Session::flash('success', 'Comunicado ' . ($publish ? 'publicado' : 'salvo como rascunho') . '.');
        header('Location: index.php?m=rh&page=announcements'); exit;
    }
    public function read(): void
    {
        Auth::requireLogin();
        $id = Sanitize::int($_GET['id'] ?? 0);
        Announcement::markRead($id, (int)Session::userId());
        $item = Announcement::find($id);
        if (!$item) { header('Location: index.php?m=rh&page=announcements'); exit; }
        View::render('announcements/show', ['pageTitle' => $item['title'], 'page' => 'announcements', 'item' => $item]);
    }
    public function delete(): void
    {
        core_require('announcements.delete'); Csrf::check();
        Announcement::delete(Sanitize::int($_POST['id'] ?? 0));
        Session::flash('success', 'Comunicado excluido.'); header('Location: index.php?m=rh&page=announcements'); exit;
    }
}
