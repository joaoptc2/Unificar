<?php
class WarningController
{
    public function index(): void { header('Location: index.php?m=rh&page=employees'); exit; }
    public function store(): void
    {
        Auth::requirePermission('employees', 'edit'); Csrf::check();
        $empId = Sanitize::int($_POST['employee_id'] ?? 0);
        $type = Sanitize::post('type'); $reason = Sanitize::post('reason'); $date = Sanitize::date($_POST['incident_date'] ?? '');
        if (!$empId || !$reason || !$date) { Session::flash('error', 'Preencha todos os campos.'); header('Location: index.php?m=rh&page=employees&action=show&id=' . $empId); exit; }
        $filePath = null;
        if (!empty($_FILES['file']['name'])) { $u = Upload::handle('file', 'documents'); if ($u['success']) $filePath = $u['path']; }
        $id = Warning::insert([
            'employee_id' => $empId, 'type' => $type ?: 'verbal', 'reason' => $reason,
            'incident_date' => $date, 'witnesses' => Sanitize::post('witnesses'),
            'file_path' => $filePath, 'created_by' => Session::userId(),
        ]);
        AuditLog::log('create', 'warnings', $id);
        Session::flash('success', 'Advertencia registrada.'); header('Location: index.php?m=rh&page=employees&action=show&id=' . $empId); exit;
    }
    public function delete(): void
    {
        Auth::requirePermission('employees', 'delete'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0); $empId = Sanitize::int($_POST['employee_id'] ?? 0);
        $w = Warning::find($id); if ($w && $w['file_path']) Upload::delete($w['file_path']);
        Warning::delete($id); AuditLog::log('delete', 'warnings', $id);
        Session::flash('success', 'Advertencia removida.'); header('Location: index.php?m=rh&page=employees&action=show&id=' . $empId); exit;
    }
}
