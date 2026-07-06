<?php
/**
 * Controller do Dashboard de RH (Módulo 6).
 *
 * Cacheado via FileCache por 5 minutos — reduz significativamente a carga em
 * hospedagem compartilhada (antes ~12 queries por hit).
 */
class DashboardController
{
    private PDO $db;
    private const CACHE_TTL = 300; // 5 min

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        Auth::requireLogin();

        // Funcionário comum não tem acesso ao dashboard de RH — redireciona ao portal.
        if (Session::userRole() === 'funcionario') {
            header('Location: index.php?m=rh&page=my');
            exit;
        }

        $today = date('Y-m-d');
        $currentMonth = (int)date('n');

        // --- Métricas globais (cache compartilhado) ------------------------
        $cached = FileCache::remember('dashboard.global.' . $today, self::CACHE_TTL, function () use ($today, $currentMonth) {
            $statusCounts = Employee::countByStatus();

            // Compromissos do dia (sempre curtos, pode entrar no cache global).
            $stmt = $this->db->prepare(
                "SELECT s.*, e.full_name AS employee_name
                 FROM rh_schedules s
                 LEFT JOIN rh_employees e ON s.employee_id = e.id
                 WHERE s.event_date = ?
                 ORDER BY s.event_time ASC"
            );
            $stmt->execute([$today]);
            $todayEvents = $stmt->fetchAll();

            // Datasets para gráficos.
            $byDept = Employee::countByDepartment();
            $byContract = Employee::countByContract();
            $expByType = Expiration::countByType();
            $expByMonth = Expiration::upcomingByMonth(6);
            $candidatesByStatus = Candidate::countByStatus();

            return [
                'totalAtivos'        => $statusCounts['ativo'],
                'totalAfastados'     => $statusCounts['afastado'],
                'totalDesligados'    => $statusCounts['desligado'],
                'totalFuncionarios'  => array_sum($statusCounts),
                'deptStats'          => $byDept,
                'contractStats'      => $byContract,
                'upcomingExpirations'=> Expiration::upcoming(30, 10),
                'expiredCount'       => Expiration::expiredCount(),
                'birthdays'          => Employee::birthdaysInMonth($currentMonth),
                'todayEvents'        => $todayEvents,
                'openJobs'           => (int)$this->db->query(
                    "SELECT COUNT(*) FROM rh_recruitment_jobs WHERE status = 'aberta'"
                )->fetchColumn(),
                'recentAdmissions'   => Employee::recentAdmissions(30),
                'expByType'          => $expByType,
                'expByMonth'         => $expByMonth,
                'candidatesByStatus' => $candidatesByStatus,
            ];
        });

        // --- Dados por-usuário (fora do cache compartilhado) ---------------
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND module = 'rh' AND read_at IS NULL");
        $stmt->execute([Session::userId()]);
        $unreadNotifications = (int)$stmt->fetchColumn();

        // Datasets prontos para Chart.js.
        $chartsData = self::buildCharts($cached);

        $viewData = array_merge($cached, [
            'unreadNotifications' => $unreadNotifications,
            'chartsData'          => $chartsData,
            'pageTitle'           => 'Dashboard',
            'page'                => 'dashboard',
        ]);

        View::render('dashboard/index', $viewData);
    }

    private static function buildCharts(array $d): array
    {
        // Departamentos (doughnut).
        $dept = [
            'labels' => array_map(fn($r) => $r['name'], $d['deptStats']),
            'data'   => array_map(fn($r) => (int)$r['total'], $d['deptStats']),
        ];
        // Contratos (barra horizontal).
        $contract = [
            'labels' => array_map(fn($r) => $r['contract_type'], $d['contractStats']),
            'data'   => array_map(fn($r) => (int)$r['total'], $d['contractStats']),
        ];
        // Vencimentos próximos por mês (linha).
        $months = [];
        $monthLabels = [];
        for ($i = 0; $i < 6; $i++) {
            $m = date('Y-m', strtotime("+{$i} month"));
            $months[$m] = 0;
            $monthLabels[$m] = strftime_safe($m);
        }
        foreach ($d['expByMonth'] as $row) {
            if (isset($months[$row['ym']])) {
                $months[$row['ym']] = (int)$row['total'];
            }
        }
        $expiry = [
            'labels' => array_values($monthLabels),
            'data'   => array_values($months),
        ];
        // Candidatos por status (pizza).
        $candStatus = [
            'labels' => array_map(fn($r) => ucfirst(str_replace('_', ' ', $r['status'])), $d['candidatesByStatus']),
            'data'   => array_map(fn($r) => (int)$r['total'], $d['candidatesByStatus']),
        ];
        return [
            'departments' => $dept,
            'contracts'   => $contract,
            'expiry'      => $expiry,
            'candidates'  => $candStatus,
        ];
    }
}

/**
 * Helper local — retorna mês/ano em PT-BR sem depender de locale instalado
 * (setlocale é inconsistente em shared hosting).
 */
function strftime_safe(string $ym): string
{
    $pt = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',
           7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];
    [$y, $m] = explode('-', $ym);
    return ($pt[(int)$m] ?? $m) . '/' . substr($y, 2);
}
