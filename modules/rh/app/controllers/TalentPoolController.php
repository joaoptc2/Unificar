<?php
/**
 * Controller do Banco de Talentos (Módulo 8)
 */
class TalentPoolController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        core_require('talent_pool.view');

        $search = Sanitize::get('search');
        $area   = Sanitize::get('area');
        $currentPage = max(1, Sanitize::int($_GET['p'] ?? 1));

        $where = ['c.in_talent_pool = 1'];
        $params = [];

        if ($search) {
            $where[] = '(c.full_name LIKE ? OR c.email LIKE ? OR c.area LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        if ($area) {
            $where[] = 'c.area = ?';
            $params[] = $area;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM rh_candidates c {$whereClause}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $pagination = new Pagination($total, $currentPage);

        $sql = "SELECT c.*, rj.title as job_title
                FROM rh_candidates c
                LEFT JOIN rh_recruitment_jobs rj ON c.job_id = rj.id
                {$whereClause}
                ORDER BY c.created_at DESC
                LIMIT {$pagination->perPage} OFFSET {$pagination->offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $candidates = $stmt->fetchAll();

        // Áreas distintas para filtro
        $areas = $this->db->query("SELECT DISTINCT area FROM rh_candidates WHERE in_talent_pool = 1 AND area IS NOT NULL AND area != '' ORDER BY area")->fetchAll(PDO::FETCH_COLUMN);

        $pageTitle = 'Banco de Talentos';
        $page = 'talent_pool';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/talent_pool/index.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function show(): void
    {
        core_require('talent_pool.view');

        $id = Sanitize::int($_GET['id'] ?? 0);
        $stmt = $this->db->prepare(
            'SELECT c.*, rj.title as job_title
             FROM rh_candidates c
             LEFT JOIN rh_recruitment_jobs rj ON c.job_id = rj.id
             WHERE c.id = ? AND c.in_talent_pool = 1'
        );
        $stmt->execute([$id]);
        $candidate = $stmt->fetch();

        if (!$candidate) {
            Session::flash('error', 'Candidato não encontrado no banco de talentos.');
            header('Location: index.php?m=rh&page=talent_pool');
            exit;
        }

        $pageTitle = $candidate['full_name'];
        $page = 'talent_pool';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/talent_pool/show.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    public function remove(): void
    {
        core_require('talent_pool.delete');
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $this->db->prepare('UPDATE rh_candidates SET in_talent_pool = 0 WHERE id = ?')->execute([$id]);
        AuditLog::log('update', 'candidates', $id);

        Session::flash('success', 'Candidato removido do banco de talentos.');
        header('Location: index.php?m=rh&page=talent_pool');
        exit;
    }

    public function export(): void
    {
        core_require('talent_pool.export');

        $candidates = $this->db->query(
            "SELECT c.*, rj.title as job_title
             FROM rh_candidates c
             LEFT JOIN rh_recruitment_jobs rj ON c.job_id = rj.id
             WHERE c.in_talent_pool = 1
             ORDER BY c.full_name"
        )->fetchAll();

        $headers = ['Nome', 'E-mail', 'Telefone', 'Área', 'Vaga Original', 'Status', 'Data'];
        $rows = [];
        foreach ($candidates as $c) {
            $rows[] = [
                $c['full_name'], $c['email'], $c['phone'], $c['area'] ?? '-',
                $c['job_title'] ?? '-', ucfirst(str_replace('_', ' ', $c['status'])),
                date('d/m/Y', strtotime($c['created_at']))
            ];
        }

        Export::csv('banco_talentos_' . date('Y-m-d') . '.csv', $headers, $rows);
    }
}
