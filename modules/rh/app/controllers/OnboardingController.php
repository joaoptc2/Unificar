<?php
class OnboardingController
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function index(): void
    {
        Auth::requirePermission('onboarding', 'view');
        $templates = OnboardingTemplate::all(['order' => 'type, name']);
        View::render('onboarding/index', [
            'pageTitle' => 'Onboarding / Offboarding', 'page' => 'onboarding', 'templates' => $templates,
        ]);
    }

    public function show(): void
    {
        Auth::requirePermission('onboarding', 'view');
        $id = Sanitize::int($_GET['id'] ?? 0);
        $template = OnboardingTemplate::withItems($id);
        if (!$template) { Session::flash('error', 'Template nao encontrado.'); header('Location: index.php?page=onboarding'); exit; }
        View::render('onboarding/show', [
            'pageTitle' => $template['name'], 'page' => 'onboarding', 'template' => $template,
        ]);
    }

    public function create_template(): void
    {
        Auth::requirePermission('onboarding', 'create');
        $departments = $this->db->query('SELECT id, name FROM departments WHERE active = 1 ORDER BY name')->fetchAll();
        View::render('onboarding/template_form', [
            'pageTitle' => 'Novo Template', 'page' => 'onboarding',
            'departments' => $departments, 'template' => null,
        ]);
    }

    public function store_template(): void
    {
        Auth::requirePermission('onboarding', 'create');
        Csrf::check();
        $name = Sanitize::post('name');
        $type = Sanitize::post('type') ?: 'onboarding';
        $deptId = Sanitize::int($_POST['department_id'] ?? 0);
        if (!$name) { Session::flash('error', 'Nome e obrigatorio.'); header('Location: index.php?page=onboarding&action=create_template'); exit; }
        $tplId = OnboardingTemplate::insert(['name' => $name, 'type' => $type, 'department_id' => $deptId ?: null, 'active' => 1]);
        $items = $_POST['items'] ?? [];
        $order = 0;
        foreach ($items as $item) {
            $title = trim($item['title'] ?? '');
            if (!$title) continue;
            $order++;
            $this->db->prepare('INSERT INTO onboarding_template_items (template_id, title, description, responsible_role, sort_order) VALUES (?,?,?,?,?)')
                     ->execute([$tplId, $title, trim($item['description'] ?? ''), trim($item['role'] ?? '') ?: null, $order]);
        }
        AuditLog::log('create', 'onboarding_templates', $tplId);
        Session::flash('success', 'Template criado com ' . $order . ' itens.');
        header('Location: index.php?page=onboarding&action=show&id=' . $tplId); exit;
    }

    public function assign(): void
    {
        Auth::requirePermission('onboarding', 'create');
        $employees = $this->db->query("SELECT id, full_name FROM employees ORDER BY full_name")->fetchAll();
        $templates = OnboardingTemplate::all(['where' => 'active = 1', 'order' => 'type, name']);
        View::render('onboarding/assign', [
            'pageTitle' => 'Atribuir Checklist', 'page' => 'onboarding',
            'employees' => $employees, 'templates' => $templates,
        ]);
    }

    public function assign_store(): void
    {
        Auth::requirePermission('onboarding', 'create');
        Csrf::check();
        $empId = Sanitize::int($_POST['employee_id'] ?? 0);
        $tplId = Sanitize::int($_POST['template_id'] ?? 0);
        if (!$empId || !$tplId) { Session::flash('error', 'Selecione funcionario e template.'); header('Location: index.php?page=onboarding&action=assign'); exit; }
        $tpl = OnboardingTemplate::withItems($tplId);
        if (!$tpl) { Session::flash('error', 'Template nao encontrado.'); header('Location: index.php?page=onboarding&action=assign'); exit; }
        $count = 0;
        foreach ($tpl['items'] as $item) {
            $exists = $this->db->prepare('SELECT 1 FROM onboarding_progress WHERE employee_id = ? AND item_id = ?');
            $exists->execute([$empId, $item['id']]);
            if (!$exists->fetchColumn()) {
                $this->db->prepare('INSERT INTO onboarding_progress (employee_id, template_id, item_id) VALUES (?,?,?)')
                         ->execute([$empId, $tplId, $item['id']]);
                $count++;
            }
        }
        AuditLog::log('assign', 'onboarding_progress', $empId, null, ['template_id' => $tplId, 'items' => $count]);
        Session::flash('success', 'Checklist atribuido com ' . $count . ' itens.');
        header('Location: index.php?page=onboarding&action=progress&employee_id=' . $empId); exit;
    }

    public function progress(): void
    {
        Auth::requirePermission('onboarding', 'view');
        $empId = Sanitize::int($_GET['employee_id'] ?? 0);
        $stmt = $this->db->prepare('SELECT id, full_name FROM employees WHERE id = ?');
        $stmt->execute([$empId]);
        $employee = $stmt->fetch();
        if (!$employee) { Session::flash('error', 'Funcionario nao encontrado.'); header('Location: index.php?page=onboarding'); exit; }
        $stmt = $this->db->prepare(
            'SELECT p.*, i.title AS item_title, i.description AS item_desc, i.responsible_role,
                    t.name AS template_name, t.type AS template_type, u.name AS completed_by_name
             FROM onboarding_progress p
             JOIN onboarding_template_items i ON p.item_id = i.id
             JOIN onboarding_templates t ON p.template_id = t.id
             LEFT JOIN users u ON p.completed_by = u.id
             WHERE p.employee_id = ?
             ORDER BY t.type, i.sort_order'
        );
        $stmt->execute([$empId]);
        $items = $stmt->fetchAll();
        $completed = count(array_filter($items, fn($i) => (int)$i['completed']));
        View::render('onboarding/progress', [
            'pageTitle' => 'Checklist: ' . $employee['full_name'], 'page' => 'onboarding',
            'employee' => $employee, 'items' => $items,
            'completed' => $completed, 'total' => count($items),
        ]);
    }

    public function toggle_item(): void
    {
        Auth::requirePermission('onboarding', 'edit');
        Csrf::check();
        $progressId = Sanitize::int($_POST['progress_id'] ?? 0);
        $empId = Sanitize::int($_POST['employee_id'] ?? 0);
        $stmt = $this->db->prepare('SELECT completed FROM onboarding_progress WHERE id = ?');
        $stmt->execute([$progressId]);
        $current = $stmt->fetchColumn();
        if ($current === false) { header('Location: index.php?page=onboarding'); exit; }
        $newVal = (int)$current ? 0 : 1;
        $this->db->prepare('UPDATE onboarding_progress SET completed = ?, completed_by = ?, completed_at = ? WHERE id = ?')
                 ->execute([$newVal, $newVal ? Session::userId() : null, $newVal ? date('Y-m-d H:i:s') : null, $progressId]);
        AuditLog::log('toggle', 'onboarding_progress', $progressId);
        header('Location: index.php?page=onboarding&action=progress&employee_id=' . $empId); exit;
    }

    public function delete_template(): void
    {
        Auth::requirePermission('onboarding', 'delete');
        Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        OnboardingTemplate::delete($id);
        AuditLog::log('delete', 'onboarding_templates', $id);
        Session::flash('success', 'Template excluido.');
        header('Location: index.php?page=onboarding'); exit;
    }
}
