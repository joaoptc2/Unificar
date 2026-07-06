<?php
/**
 * ComplimentController — elogios recebidos por funcionários.
 */
class ComplimentController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        header('Location: index.php?m=rh&page=employees');
        exit;
    }

    public function store(): void
    {
        core_require('compliments.create');
        Csrf::check();

        $employeeId = Sanitize::int($_POST['employee_id'] ?? 0);
        $message    = Sanitize::post('message');
        $from       = Sanitize::post('compliment_from');

        if ($employeeId <= 0 || $message === '') {
            Session::flash('error', 'Informe o funcionário e a mensagem do elogio.');
            header('Location: index.php?m=rh&page=employees&action=show&id=' . $employeeId);
            exit;
        }
        if (mb_strlen($message) > 2000) {
            Session::flash('error', 'Mensagem muito longa (máx. 2000 caracteres).');
            header('Location: index.php?m=rh&page=employees&action=show&id=' . $employeeId);
            exit;
        }

        EmployeeCompliment::insert([
            'employee_id'     => $employeeId,
            'message'         => $message,
            'compliment_from' => $from ?: null,
            'created_by'      => Session::userId(),
        ]);

        AuditLog::log('create', 'employee_compliments', (int)$this->db->lastInsertId());

        Session::flash('success', 'Elogio registrado.');
        header('Location: index.php?m=rh&page=employees&action=show&id=' . $employeeId);
        exit;
    }

    public function delete(): void
    {
        core_require('compliments.delete');
        Csrf::check();

        $id         = Sanitize::int($_POST['id'] ?? 0);
        $employeeId = Sanitize::int($_POST['employee_id'] ?? 0);

        EmployeeCompliment::delete($id);
        AuditLog::log('delete', 'employee_compliments', $id);

        Session::flash('success', 'Elogio removido.');
        header('Location: index.php?m=rh&page=employees&action=show&id=' . $employeeId);
        exit;
    }
}
