<?php
class RequestController
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }
    public function index(): void
    {
        Auth::requirePermission('requests', 'view');
        $status = Sanitize::get('status');
        $where = $status ? "r.status = ?" : ''; $params = $status ? [$status] : [];
        $wc = $where ? "WHERE $where" : '';
        $stmt = $this->db->prepare("SELECT r.*, e.full_name AS employee_name FROM requests r JOIN employees e ON r.employee_id = e.id $wc ORDER BY r.created_at DESC LIMIT 100");
        $stmt->execute($params);
        View::render('requests/index', ['pageTitle' => 'Solicitacoes', 'page' => 'requests', 'items' => $stmt->fetchAll(), 'status' => $status]);
    }
    public function respond(): void
    {
        Auth::requirePermission('requests', 'edit'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0); $status = Sanitize::post('status'); $response = Sanitize::post('response');
        $this->db->prepare('UPDATE requests SET status = ?, response = ?, responded_by = ?, responded_at = NOW() WHERE id = ?')
                 ->execute([$status, $response, Session::userId(), $id]);
        AuditLog::log('respond', 'requests', $id);
        Session::flash('success', 'Solicitacao respondida.'); header('Location: index.php?page=requests'); exit;
    }
    // Portal do funcionario
    public function create(): void
    {
        Auth::requireLogin();
        View::renderRaw('requests/create', ['pageTitle' => 'Nova Solicitacao']);
    }
    public function store(): void
    {
        Auth::requireLogin(); Csrf::check();
        $userId = Session::userId();
        $stmt = $this->db->prepare('SELECT employee_id FROM users WHERE id = ?'); $stmt->execute([$userId]);
        $empId = (int)$stmt->fetchColumn();
        if (!$empId) { Session::flash('error', 'Sem vinculo.'); header('Location: index.php?page=my'); exit; }
        EmployeeRequest::insert([
            'employee_id' => $empId, 'type' => Sanitize::post('type') ?: 'outro',
            'subject' => Sanitize::post('subject'), 'body' => Sanitize::post('body'),
        ]);
        AuditLog::log('create', 'requests', (int)$this->db->lastInsertId());
        Session::flash('success', 'Solicitacao enviada.'); header('Location: index.php?page=my'); exit;
    }
}
