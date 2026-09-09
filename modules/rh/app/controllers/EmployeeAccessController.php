<?php
/**
 * EmployeeAccessController — aba "Acessos dos funcionários" da
 * Administração central (index.php?m=admin&a=module&slug=rh&tab=access).
 *
 * Lista os funcionários ativos com/sem usuário e permite criar o acesso
 * padrão (CPF / nascimento) individualmente ou para todos de uma vez, e
 * redefinir a senha padrão. Servido por admin_panel.php.
 */
class EmployeeAccessController
{
    public function index(): void
    {
        core_require('employee_access.view');

        $rows = EmployeeAccess::overview();
        $withAccess = $without = 0;
        foreach ($rows as $r) {
            $r['user_id'] ? $withAccess++ : $without++;
        }

        $pageTitle = 'Acessos dos funcionários';
        $page = 'access';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/admin/access.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    /** Cria o acesso padrão de UM funcionário (POST). */
    public function ensure(): void
    {
        core_require('employee_access.manage');
        Csrf::check();

        $employee = Employee::find(Sanitize::int($_POST['id'] ?? 0));
        if (!$employee || !empty($employee['anonymized_at'])) {
            Session::flash('error', 'Funcionário não encontrado.');
            core_redirect(core_admin_url('rh', 'access'));
        }
        $r = EmployeeAccess::ensure($employee, Session::userId());
        Session::flash($r['status'] === 'error' ? 'error' : 'success', Sanitize::e($r['message']));
        core_redirect(core_admin_url('rh', 'access'));
    }

    /** Redefine a senha padrão de UM funcionário (POST). */
    public function reset(): void
    {
        core_require('employee_access.manage');
        Csrf::check();

        $employee = Employee::find(Sanitize::int($_POST['id'] ?? 0));
        if (!$employee) {
            Session::flash('error', 'Funcionário não encontrado.');
            core_redirect(core_admin_url('rh', 'access'));
        }
        $password = EmployeeAccess::resetPassword($employee, Session::userId());
        if ($password === null) {
            Session::flash('error', 'O funcionário não possui acesso — crie o acesso primeiro.');
        } else {
            Session::flash('success', 'Senha de ' . Sanitize::e($employee['full_name']) . ' redefinida para <code>' . Sanitize::e($password) . '</code> (troca obrigatória no próximo login).');
        }
        core_redirect(core_admin_url('rh', 'access'));
    }

    /** Cria o acesso padrão para TODOS os funcionários ativos sem usuário (POST). */
    public function create_all(): void
    {
        core_require('employee_access.manage');
        Csrf::check();

        $created = $linked = $errors = 0;
        $messages = [];
        foreach (EmployeeAccess::overview() as $row) {
            if ($row['user_id']) {
                continue;
            }
            $r = EmployeeAccess::ensure($row, Session::userId());
            if ($r['status'] === 'created') {
                $created++;
            } elseif ($r['status'] === 'linked') {
                $linked++;
            } elseif ($r['status'] === 'error') {
                $errors++;
                if (count($messages) < 5) {
                    $messages[] = $row['full_name'] . ': ' . $r['message'];
                }
            }
        }
        AuditLog::log('employee_access.create_all', 'users', null, null, ['created' => $created, 'linked' => $linked, 'errors' => $errors]);

        $msg = "Acessos criados: {$created}. Usuários existentes vinculados: {$linked}.";
        if ($errors) {
            $msg .= " Falhas: {$errors}.";
            Session::flash('error', Sanitize::e(implode(' | ', $messages)));
        }
        Session::flash('success', Sanitize::e($msg) . ($created ? ' Senha inicial = data de nascimento (ddmmaaaa), com troca obrigatória.' : ''));
        core_redirect(core_admin_url('rh', 'access'));
    }
}
