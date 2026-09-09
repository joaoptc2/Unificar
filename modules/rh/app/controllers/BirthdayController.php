<?php
/**
 * Controller de Aniversariantes.
 *
 *   page=birthdays                      lista do mês (birthdays.view)
 *   page=birthdays&action=print&month=M&year=Y[&department=D]
 *                                       cartaz A4 no papel timbrado (birthdays.export)
 *
 * A personalização do cartaz (layout, título, template, CSS) fica na
 * Administração central → RH → "Aniversariantes (A4)" (birthdays.configure),
 * servida por admin_panel.php através de configure()/save_config()/preview().
 */
class BirthdayController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        core_require('birthdays.view');

        $month      = Sanitize::int($_GET['month'] ?? date('n'));
        $year       = Sanitize::int($_GET['year'] ?? date('Y')) ?: (int)date('Y');
        $department = Sanitize::int($_GET['department'] ?? 0);
        if ($month < 1 || $month > 12) $month = (int)date('n');

        $birthdays   = BirthdayPoster::employees($month, $department, 'day');
        $departments = $this->db->query('SELECT id, name FROM rh_departments WHERE active = 1 ORDER BY name')->fetchAll();
        $monthNames  = BirthdayPoster::MONTHS;

        $pageTitle = 'Aniversariantes';
        $page = 'birthdays';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/birthdays/index.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    /** Cartaz A4 (imprimir / salvar em PDF). */
    public function print(): void
    {
        core_require('birthdays.export');

        $month      = Sanitize::int($_GET['month'] ?? date('n'));
        $year       = Sanitize::int($_GET['year'] ?? date('Y'));
        $department = Sanitize::int($_GET['department'] ?? 0);

        AuditLog::log('export', 'birthdays', null, null, ['month' => $month, 'year' => $year]);
        echo BirthdayPoster::render($month, $year, $department, [], !empty($_GET['pdf']));
        exit;
    }

    // ---- Administração central (aba "Aniversariantes (A4)") --------------

    /** Formulário de personalização do cartaz. */
    public function configure(): void
    {
        core_require('birthdays.configure');

        $settings = BirthdayPoster::settings();
        $layouts  = Core\DocLayout::active('page');
        $months   = BirthdayPoster::MONTHS;

        $pageTitle = 'Aniversariantes (A4)';
        $page = 'birthdays';
        require __DIR__ . '/../views/layout/header.php';
        require __DIR__ . '/../views/admin/birthdays.php';
        require __DIR__ . '/../views/layout/footer.php';
    }

    /** Grava a personalização (POST). */
    public function save_config(): void
    {
        core_require('birthdays.configure');
        Csrf::check();

        BirthdayPoster::save($this->configFromPost());
        AuditLog::log('configure', 'birthdays');
        Session::flash('success', 'Layout do cartaz de aniversariantes salvo.');
        core_redirect(core_admin_url('rh', 'birthdays'));
    }

    /** Pré-visualização com os valores do formulário (POST, sem salvar). */
    public function preview(): void
    {
        core_require('birthdays.configure');
        Csrf::check();

        $month = Sanitize::int($_POST['preview_month'] ?? date('n'));
        $year  = Sanitize::int($_POST['preview_year'] ?? date('Y'));
        $cfg   = $this->configFromPost();
        $cfg['intro_html'] = Core\DocLayout::sanitizeHtml($cfg['intro_html']);
        $cfg['item_html']  = Core\DocLayout::sanitizeHtml($cfg['item_html']) ?: BirthdayPoster::defaultItemHtml();
        echo BirthdayPoster::render($month, $year, 0, $cfg);
        exit;
    }

    private function configFromPost(): array
    {
        return [
            'layout_id'  => Sanitize::int($_POST['layout_id'] ?? 0),
            'title'      => mb_substr(Sanitize::post('title'), 0, 200),
            'intro_html' => Sanitize::string($_POST['intro_html'] ?? ''),
            'item_html'  => Sanitize::string($_POST['item_html'] ?? ''),
            'css'        => Sanitize::string($_POST['css'] ?? ''),
            'show_photo' => !empty($_POST['show_photo']),
            'order'      => Sanitize::post('order') === 'name' ? 'name' : 'day',
        ];
    }
}
