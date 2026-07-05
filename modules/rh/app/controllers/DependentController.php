<?php
class DependentController
{
    public function index(): void { header('Location: index.php?m=rh&page=employees'); exit; }
    public function store(): void
    {
        Auth::requirePermission('employees', 'edit'); Csrf::check();
        $empId = Sanitize::int($_POST['employee_id'] ?? 0);
        $name = Sanitize::post('full_name');
        if (!$empId || !$name) { Session::flash('error', 'Nome obrigatorio.'); header('Location: index.php?m=rh&page=employees&action=show&id=' . $empId); exit; }
        EmployeeDependent::insert([
            'employee_id' => $empId, 'full_name' => $name,
            'cpf' => Sanitize::cpf($_POST['cpf'] ?? ''), 'birth_date' => Sanitize::date($_POST['birth_date'] ?? ''),
            'relationship' => Sanitize::post('relationship') ?: 'outro',
            'for_health_plan' => !empty($_POST['for_health_plan']) ? 1 : 0,
            'for_ir' => !empty($_POST['for_ir']) ? 1 : 0, 'notes' => Sanitize::post('notes'),
        ]);
        AuditLog::log('create', 'employee_dependents', (int)Database::getInstance()->lastInsertId());
        Session::flash('success', 'Dependente cadastrado.'); header('Location: index.php?m=rh&page=employees&action=show&id=' . $empId); exit;
    }
    public function delete(): void
    {
        Auth::requirePermission('employees', 'edit'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0); $empId = Sanitize::int($_POST['employee_id'] ?? 0);
        EmployeeDependent::delete($id); AuditLog::log('delete', 'employee_dependents', $id);
        Session::flash('success', 'Dependente removido.'); header('Location: index.php?m=rh&page=employees&action=show&id=' . $empId); exit;
    }
}
