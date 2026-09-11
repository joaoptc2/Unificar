<?php
/**
 * MyController — portal do funcionário ("Minha Área", micropermissão my.view).
 *
 *   page=my                          painel: pontos, elogios, férias, solicitações,
 *                                    comunicados, pesquisas e brindes
 *   page=my&action=announcement&id=N leitura de comunicado (marca como lido)
 *   page=my&action=survey&id=N       responder pesquisa
 *
 * O usuário deve estar vinculado a um funcionário (rh_user_profile.employee_id).
 * O layout é próprio (standalone, sem o menu da plataforma).
 */
class MyController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        core_require('my.view');
        [$employee, $employeeId] = $this->requireEmployee();
        $userId = (int)Session::userId();
        $deptId = $employee['department_id'] ? (int)$employee['department_id'] : null;

        $scoresTotal = EmployeeScore::totalFor($employeeId);
        $reserved    = RewardRedemption::reservedFor($employeeId);

        // Próximos vencimentos do próprio funcionário (60 dias).
        $stmt = $this->db->prepare(
            "SELECT * FROM rh_expirations WHERE employee_id = ?
               AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) ORDER BY expiry_date ASC"
        );
        $stmt->execute([$employeeId]);

        $this->renderPortal('my/index', [
            'employee'      => $employee,
            'scores'        => EmployeeScore::listFor($employeeId),
            'scoresTotal'   => $scoresTotal,
            'available'     => $scoresTotal - $reserved,
            'reserved'      => $reserved,
            'compliments'   => EmployeeCompliment::listFor($employeeId),
            'upcoming'      => $stmt->fetchAll(),
            'vacations'     => Vacation::forEmployee($employeeId),
            'requests'      => EmployeeRequest::forEmployee($employeeId),
            'announcements' => core_can('announcements.view') ? Announcement::forPortal($deptId, $userId) : [],
            'surveys'       => core_can('surveys.view') ? Survey::forPortal($userId, $deptId) : [],
            'rewards'       => core_can('rewards.view') ? Reward::catalog() : [],
            'redemptions'   => core_can('rewards.view') ? RewardRedemption::forEmployee($employeeId) : [],
            'requestTypes'  => EmployeeRequest::TYPES,
            'tab'           => preg_replace('/[^a-z]/', '', (string)($_GET['tab'] ?? '')),
        ]);
    }

    /** Leitura de comunicado no portal. */
    public function announcement(): void
    {
        core_require('my.view');
        core_require('announcements.view');
        [$employee] = $this->requireEmployee();
        $id = Sanitize::int($_GET['id'] ?? 0);
        $item = Announcement::find($id);
        if (!$item || !(int)$item['show_in_portal'] || !Announcement::visibleTo($item, $employee['department_id'] ? (int)$employee['department_id'] : null)) {
            Session::flash('error', 'Comunicado indisponível.');
            header('Location: index.php?m=rh&page=my#comunicados'); exit;
        }
        Announcement::markRead($id, (int)Session::userId());
        $this->renderPortal('my/announcement', ['employee' => $employee, 'item' => $item]);
    }

    /** Formulário de resposta de pesquisa no portal. */
    public function survey(): void
    {
        core_require('my.view');
        core_require('surveys.view');
        [$employee] = $this->requireEmployee();
        $userId = (int)Session::userId();
        $survey = Survey::withQuestions(Sanitize::int($_GET['id'] ?? 0));
        $deptId = $employee['department_id'] ? (int)$employee['department_id'] : null;
        if (!$survey || !(int)$survey['show_in_portal'] || !Survey::targets($survey, $deptId)) {
            Session::flash('error', 'Pesquisa indisponível.');
            header('Location: index.php?m=rh&page=my#pesquisas'); exit;
        }
        $this->renderPortal('my/survey', [
            'employee' => $employee, 'survey' => $survey,
            'open'     => Survey::isOpen($survey),
            'answered' => Survey::hasParticipated((int)$survey['id'], $userId),
        ]);
    }

    /** Funcionário vinculado ao usuário logado (redireciona se não houver). */
    private function requireEmployee(): array
    {
        $employeeId = EmployeeAccess::employeeIdOf(Session::userId());
        if (!$employeeId) {
            // Usuário sem vínculo com funcionário: quem enxerga o dashboard
            // volta para ele; os demais voltam ao portal inicial da
            // plataforma (evita loop my ⇄ dashboard).
            if (core_can('dashboard.view')) {
                Session::flash('error', 'Seu usuário não está vinculado a um funcionário — a Minha Área é exclusiva de funcionários.');
                header('Location: index.php?m=rh&page=dashboard');
                exit;
            }
            Session::flash('error', 'Seu usuário ainda não está vinculado a um funcionário. Procure o RH.');
            core_redirect('index.php');
        }
        $employee = Employee::findWithRelations($employeeId);
        if (!$employee) {
            Session::flash('error', 'Registro de funcionário não encontrado.');
            core_redirect('index.php');
        }
        return [$employee, $employeeId];
    }

    private function renderPortal(string $view, array $data): void
    {
        View::renderRaw($view, array_merge([
            'hospitalName' => Core\Settings::get('org_name', core_config('app.name', 'Portal')),
            'flashSuccess' => Session::flash('success'),
            'flashError'   => Session::flash('error'),
            'unread'       => Core\Notifications::unreadCount((int)Session::userId()),
        ], $data));
    }
}
