<?php
/**
 * Controller de Documentos (Módulo 2 — Ficha Funcional Digital)
 */
class DocumentController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        Auth::requirePermission('documents', 'view');
        header('Location: index.php?page=employees');
        exit;
    }

    public function create(): void
    {
        Auth::requirePermission('documents', 'create');
        $employeeId = Sanitize::int($_GET['employee_id'] ?? 0);

        $stmt = $this->db->prepare('SELECT id, full_name FROM employees WHERE id = ?');
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch();

        if (!$employee) {
            Session::flash('error', 'Funcionário não encontrado.');
            header('Location: index.php?page=employees');
            exit;
        }

        $pageTitle = 'Enviar Documento';
        $page = 'employees';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/documents/form.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function store(): void
    {
        Auth::requirePermission('documents', 'create');
        Csrf::check();

        $employeeId = Sanitize::int($_POST['employee_id'] ?? 0);
        $docType    = Sanitize::post('doc_type');
        $title      = Sanitize::post('title');
        $notes      = Sanitize::post('notes');

        if (!$employeeId || !$docType || !$title) {
            Session::flash('error', 'Preencha todos os campos obrigatórios.');
            header('Location: index.php?page=documents&action=create&employee_id=' . $employeeId);
            exit;
        }

        $upload = Upload::handle('file', 'documents');
        if (!$upload['success']) {
            Session::flash('error', 'Erro no upload: ' . $upload['error']);
            header('Location: index.php?page=documents&action=create&employee_id=' . $employeeId);
            exit;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO employee_documents (employee_id, doc_type, title, file_path, file_original_name, file_size, notes, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$employeeId, $docType, $title, $upload['path'], $upload['original_name'], $upload['size'], $notes, Session::userId()]);

        AuditLog::log('create', 'employee_documents', (int)$this->db->lastInsertId());

        Session::flash('success', 'Documento enviado com sucesso.');
        header('Location: index.php?page=employees&action=show&id=' . $employeeId);
        exit;
    }

    public function delete(): void
    {
        Auth::requirePermission('documents', 'delete');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $employeeId = Sanitize::int($_POST['employee_id'] ?? 0);

        $stmt = $this->db->prepare('SELECT * FROM employee_documents WHERE id = ?');
        $stmt->execute([$id]);
        $doc = $stmt->fetch();

        if ($doc) {
            Upload::delete($doc['file_path']);
            $stmt = $this->db->prepare('DELETE FROM employee_documents WHERE id = ?');
            $stmt->execute([$id]);
            AuditLog::log('delete', 'employee_documents', $id);
            Session::flash('success', 'Documento excluído.');
        }

        header('Location: index.php?page=employees&action=show&id=' . $employeeId);
        exit;
    }
}
