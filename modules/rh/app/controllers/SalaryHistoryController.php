<?php
class SalaryHistoryController
{
    public function index(): void { header('Location: index.php?m=rh&page=employees'); exit; }
    public function store(): void
    {
        Auth::requirePermission('employees', 'edit'); Csrf::check();
        $empId = Sanitize::int($_POST['employee_id'] ?? 0);
        $salary = (float)str_replace(['.', ','], ['', '.'], $_POST['salary'] ?? '0');
        $reason = Sanitize::post('reason'); $date = Sanitize::date($_POST['effective_date'] ?? '');
        if (!$empId || $salary <= 0 || !$date) { Session::flash('error', 'Preencha todos os campos.'); header('Location: index.php?m=rh&page=employees&action=show&id=' . $empId); exit; }
        SalaryHistory::insert(['employee_id' => $empId, 'salary' => $salary, 'reason' => $reason, 'effective_date' => $date, 'created_by' => Session::userId()]);
        AuditLog::log('create', 'salary_history', (int)Database::getInstance()->lastInsertId());
        Session::flash('success', 'Historico salarial atualizado.'); header('Location: index.php?m=rh&page=employees&action=show&id=' . $empId); exit;
    }
    public function delete(): void
    {
        Auth::requirePermission('employees', 'edit'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0); $empId = Sanitize::int($_POST['employee_id'] ?? 0);
        SalaryHistory::delete($id); AuditLog::log('delete', 'salary_history', $id);
        Session::flash('success', 'Registro removido.'); header('Location: index.php?m=rh&page=employees&action=show&id=' . $empId); exit;
    }
}
