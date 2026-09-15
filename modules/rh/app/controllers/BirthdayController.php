<?php
/**
 * Controller de Aniversariantes.
 *
 *   page=birthdays                      lista do mês (birthdays.view)
 *   page=birthdays&action=print&month=M&year=Y[&department=D]
 *                                       cartaz A4 no papel timbrado (birthdays.export)
 *
 * A personalização do cartaz fica na Administração central → RH →
 * "Aniversariantes (A4)" (birthdays.configure), servida por admin_panel.php
 * através de configure()/save_config()/preview(). São dois modos: o VISUAL
 * (modelo, colunas, cores, foto, o que aparece) e o AVANÇADO (HTML e CSS na
 * mão) — ver BirthdayPoster.
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
        $cfg = $this->configFromPost();
        $cfg['intro_html'] = Core\DocLayout::sanitizeHtml($cfg['intro_html']);
        if ($cfg['mode'] === 'avancado') {
            $cfg['item_html'] = Core\DocLayout::sanitizeHtml($cfg['item_html']) ?: BirthdayPoster::defaultItemHtml();
        } else {
            // Modo visual: o corpo e o CSS saem das opções do formulário —
            // é o que garante que a pré-visualização mostre o que será salvo.
            $cfg['item_html'] = BirthdayPoster::buildItemHtml($cfg);
            $cfg['css']       = BirthdayPoster::buildCss($cfg);
        }
        echo BirthdayPoster::render($month, $year, 0, $cfg);
        exit;
    }

    private function configFromPost(): array
    {
        $cfg = [
            'mode'       => Sanitize::post('mode') === 'avancado' ? 'avancado' : 'visual',
            'layout_id'  => Sanitize::int($_POST['layout_id'] ?? 0),
            'title'      => mb_substr(Sanitize::post('title'), 0, 200),
            'intro_html' => Sanitize::string($_POST['intro_html'] ?? ''),
            'item_html'  => Sanitize::string($_POST['item_html'] ?? ''),
            'css'        => Sanitize::string($_POST['css'] ?? ''),
            'show_photo' => !empty($_POST['show_photo']),
            'order'      => Sanitize::post('order') === 'name' ? 'name' : 'day',

            // Modo visual (a validação de faixa e de cor é do BirthdayPoster).
            'model'       => Sanitize::post('model'),
            'columns'     => Sanitize::int($_POST['columns'] ?? 3),
            'accent'      => Sanitize::post('accent'),
            'card_bg'     => Sanitize::post('card_bg'),
            'text_color'  => Sanitize::post('text_color'),
            'border'      => !empty($_POST['border']),
            'title_size'  => Sanitize::int($_POST['title_size'] ?? 22),
            'font_size'   => Sanitize::int($_POST['font_size'] ?? 11),
            'photo_shape' => Sanitize::post('photo_shape'),
            'photo_size'  => Sanitize::int($_POST['photo_size'] ?? 26),
        ];
        foreach (['show_day', 'show_date', 'show_position', 'show_department', 'show_age', 'show_emoji'] as $k) {
            $cfg[$k] = !empty($_POST[$k]);
        }
        return $cfg;
    }

    /**
     * Gera o HTML e o CSS a partir das opções visuais e passa para o modo
     * avançado. Serve de ponto de partida para quem quer ajustar detalhes à
     * mão sem começar de uma página em branco.
     */
    public function to_advanced(): void
    {
        core_require('birthdays.configure');
        Csrf::check();

        $cfg = $this->configFromPost();
        $cfg['item_html'] = BirthdayPoster::buildItemHtml($cfg);
        $cfg['css']       = BirthdayPoster::buildCss($cfg);
        $cfg['mode']      = 'avancado';

        BirthdayPoster::save($cfg);
        AuditLog::log('configure', 'birthdays', null, null, ['mode' => 'avancado']);
        Session::flash('success', 'HTML e CSS gerados a partir do visual. Agora o cartaz usa o modo avançado.');
        core_redirect(core_admin_url('rh', 'birthdays'));
    }

    /** Volta às opções visuais padrão (não mexe no HTML/CSS avançado). */
    public function reset_visual(): void
    {
        core_require('birthdays.configure');
        Csrf::check();

        $cfg = array_merge($this->configFromPost(), BirthdayPoster::visualDefaults(), ['mode' => 'visual']);
        BirthdayPoster::save($cfg);
        Session::flash('success', 'Opções visuais restauradas.');
        core_redirect(core_admin_url('rh', 'birthdays'));
    }
}
