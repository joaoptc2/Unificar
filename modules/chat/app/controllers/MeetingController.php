<?php
/**
 * MeetingController — Schedule and manage meetings.
 *
 * Every public method corresponds to a ?page=meetings&action=X route.
 */
class MeetingController
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /* ------------------------------------------------------------------
     *  index  — List upcoming meetings for the logged-in user
     *  GET ?page=meetings
     * ----------------------------------------------------------------*/
    public function index(): void
    {
        Auth::requireLogin();
        core_require('meetings.view');

        $userId   = Session::userId();
        $meetings = Meeting::upcoming($userId, 50);

        View::render('meetings/index', [
            'pageTitle' => 'Reuniões',
            'meetings'  => $meetings,
        ]);
    }

    /* ------------------------------------------------------------------
     *  show  — Display a single meeting with participants
     *  GET ?page=meetings&action=show&id=N
     * ----------------------------------------------------------------*/
    public function show(): void
    {
        Auth::requireLogin();
        core_require('meetings.view');

        $id = isset($_GET['id']) ? Sanitize::int($_GET['id']) : 0;
        if ($id <= 0) {
            Session::flash('error', 'Reunião inválida.');
            header('Location: index.php?m=chat&page=meetings');
            exit;
        }

        $meeting = Meeting::withParticipants($id);
        if (!$meeting) {
            Session::flash('error', 'Reunião não encontrada.');
            header('Location: index.php?m=chat&page=meetings');
            exit;
        }

        View::render('meetings/show', [
            'pageTitle' => Sanitize::e($meeting['title']),
            'meeting'   => $meeting,
            'userId'    => Session::userId(),
        ]);
    }

    /* ------------------------------------------------------------------
     *  create  — Show the new-meeting form
     *  GET ?page=meetings&action=create
     * ----------------------------------------------------------------*/
    public function create(): void
    {
        Auth::requireLogin();
        core_require('meetings.create');

        $users    = User::active();
        $channels = Channel::userChannels(Session::userId());

        View::render('meetings/form', [
            'pageTitle' => 'Nova Reunião',
            'meeting'   => null,
            'users'     => $users,
            'channels'  => $channels,
        ]);
    }

    /* ------------------------------------------------------------------
     *  store  — Persist a new meeting
     *  POST ?page=meetings&action=store
     * ----------------------------------------------------------------*/
    public function store(): void
    {
        Auth::requireLogin();
        core_require('meetings.create');
        Csrf::check();

        $userId = Session::userId();

        $title          = Sanitize::string($_POST['title'] ?? '');
        $description    = Sanitize::string($_POST['description'] ?? '');
        $scheduledAt    = Sanitize::string($_POST['scheduled_at'] ?? '');
        $durationMin    = Sanitize::int($_POST['duration_minutes'] ?? 30);
        $location       = Sanitize::string($_POST['location'] ?? '');
        $meetingLink    = Sanitize::string($_POST['meeting_link'] ?? '');
        $type           = Sanitize::string($_POST['type'] ?? 'video');
        $channelId      = Sanitize::int($_POST['channel_id'] ?? 0);
        $participants   = array_map('intval', $_POST['participants'] ?? []);

        // Validation
        if ($title === '' || $scheduledAt === '') {
            Session::flash('error', 'Título e data/hora são obrigatórios.');
            header('Location: index.php?m=chat&page=meetings&action=create');
            exit;
        }

        $meetingId = Meeting::insert([
            'title'           => $title,
            'description'     => $description,
            'scheduled_at'    => $scheduledAt,
            'duration_minutes'=> $durationMin,
            'location'        => $location,
            'meeting_link'    => $meetingLink,
            'type'            => $type,
            'channel_id'      => $channelId > 0 ? $channelId : null,
            'status'          => 'scheduled',
            'created_by'      => $userId,
        ]);

        // Always include the creator as a participant
        if (!in_array($userId, $participants, true)) {
            $participants[] = $userId;
        }

        Meeting::setParticipants($meetingId, $participants);

        // Notify all participants except the creator
        $notifyIds = array_filter($participants, fn(int $uid) => $uid !== $userId);
        if (!empty($notifyIds)) {
            Notification::createForMany(
                $notifyIds,
                'meeting',
                'Nova reunião: ' . $title,
                Session::userName() . ' agendou uma reunião para ' . Sanitize::formatDateTime($scheduledAt),
                'index.php?m=chat&page=meetings&action=show&id=' . $meetingId
            );
        }

        // Post a system message in the linked channel
        if ($channelId > 0) {
            Message::insert([
                'channel_id' => $channelId,
                'user_id'    => $userId,
                'content'    => '📅 Reunião agendada: **' . $title . '** em ' . Sanitize::formatDateTime($scheduledAt),
                'type'       => 'system',
            ]);
        }

        AuditLog::log('create', 'meeting', $meetingId, null, [
            'title'        => $title,
            'scheduled_at' => $scheduledAt,
        ]);

        Session::flash('success', 'Reunião criada com sucesso.');
        header('Location: index.php?m=chat&page=meetings&action=show&id=' . $meetingId);
        exit;
    }

    /* ------------------------------------------------------------------
     *  edit  — Show the edit form for an existing meeting
     *  GET ?page=meetings&action=edit&id=N
     * ----------------------------------------------------------------*/
    public function edit(): void
    {
        Auth::requireLogin();

        $id = isset($_GET['id']) ? Sanitize::int($_GET['id']) : 0;

        $meeting = Meeting::withParticipants($id);
        if (!$meeting) {
            Session::flash('error', 'Reunião não encontrada.');
            header('Location: index.php?m=chat&page=meetings');
            exit;
        }

        // Criador edita a própria reunião; meetings.edit permite editar as demais
        if ((int) $meeting['created_by'] !== Session::userId() && !core_can('meetings.edit')) {
            Session::flash('error', 'Sem permissão para editar esta reunião.');
            header('Location: index.php?m=chat&page=meetings&action=show&id=' . $id);
            exit;
        }

        $users    = User::active();
        $channels = Channel::userChannels(Session::userId());

        View::render('meetings/form', [
            'pageTitle' => 'Editar Reunião',
            'meeting'   => $meeting,
            'users'     => $users,
            'channels'  => $channels,
        ]);
    }

    /* ------------------------------------------------------------------
     *  update  — Persist changes to an existing meeting
     *  POST ?page=meetings&action=update
     * ----------------------------------------------------------------*/
    public function update(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $meeting = Meeting::find($id);
        if (!$meeting) {
            Session::flash('error', 'Reunião não encontrada.');
            header('Location: index.php?m=chat&page=meetings');
            exit;
        }

        if ((int) $meeting['created_by'] !== Session::userId() && !core_can('meetings.edit')) {
            Session::flash('error', 'Sem permissão para editar esta reunião.');
            header('Location: index.php?m=chat&page=meetings&action=show&id=' . $id);
            exit;
        }

        $title          = Sanitize::string($_POST['title'] ?? '');
        $description    = Sanitize::string($_POST['description'] ?? '');
        $scheduledAt    = Sanitize::string($_POST['scheduled_at'] ?? '');
        $durationMin    = Sanitize::int($_POST['duration_minutes'] ?? 30);
        $location       = Sanitize::string($_POST['location'] ?? '');
        $meetingLink    = Sanitize::string($_POST['meeting_link'] ?? '');
        $type           = Sanitize::string($_POST['type'] ?? 'video');
        $channelId      = Sanitize::int($_POST['channel_id'] ?? 0);
        $participants   = array_map('intval', $_POST['participants'] ?? []);

        if ($title === '' || $scheduledAt === '') {
            Session::flash('error', 'Título e data/hora são obrigatórios.');
            header('Location: index.php?m=chat&page=meetings&action=edit&id=' . $id);
            exit;
        }

        $oldData = $meeting;

        Meeting::update($id, [
            'title'           => $title,
            'description'     => $description,
            'scheduled_at'    => $scheduledAt,
            'duration_minutes'=> $durationMin,
            'location'        => $location,
            'meeting_link'    => $meetingLink,
            'type'            => $type,
            'channel_id'      => $channelId > 0 ? $channelId : null,
        ]);

        // Always include the creator as a participant
        $userId = Session::userId();
        if (!in_array($userId, $participants, true)) {
            $participants[] = $userId;
        }

        Meeting::setParticipants($id, $participants);

        AuditLog::log('update', 'meeting', $id, $oldData, [
            'title'        => $title,
            'scheduled_at' => $scheduledAt,
        ]);

        Session::flash('success', 'Reunião atualizada com sucesso.');
        header('Location: index.php?m=chat&page=meetings&action=show&id=' . $id);
        exit;
    }

    /* ------------------------------------------------------------------
     *  respond  — Accept / decline / tentative a meeting invitation
     *  POST ?page=meetings&action=respond
     * ----------------------------------------------------------------*/
    public function respond(): void
    {
        Auth::requireLogin();
        core_require('meetings.view');
        Csrf::check();

        $meetingId = Sanitize::int($_POST['meeting_id'] ?? 0);
        $status    = Sanitize::string($_POST['status'] ?? '');

        if ($meetingId <= 0 || !in_array($status, ['accepted', 'declined', 'tentative'], true)) {
            Session::flash('error', 'Dados inválidos.');
            header('Location: index.php?m=chat&page=meetings');
            exit;
        }

        Meeting::respond($meetingId, Session::userId(), $status);

        $labels = [
            'accepted'  => 'Você aceitou a reunião.',
            'declined'  => 'Você recusou a reunião.',
            'tentative' => 'Você respondeu como talvez.',
        ];

        Session::flash('success', $labels[$status]);
        header('Location: index.php?m=chat&page=meetings&action=show&id=' . $meetingId);
        exit;
    }

    /* ------------------------------------------------------------------
     *  cancel  — Cancel a meeting and notify participants
     *  POST ?page=meetings&action=cancel
     * ----------------------------------------------------------------*/
    public function cancel(): void
    {
        Auth::requireLogin();
        Csrf::check();

        $id = Sanitize::int($_POST['id'] ?? 0);
        $meeting = Meeting::withParticipants($id);

        if (!$meeting) {
            Session::flash('error', 'Reunião não encontrada.');
            header('Location: index.php?m=chat&page=meetings');
            exit;
        }

        // Criador cancela a própria reunião; meetings.delete cancela as demais
        if ((int) $meeting['created_by'] !== Session::userId() && !core_can('meetings.delete')) {
            Session::flash('error', 'Sem permissão para cancelar esta reunião.');
            header('Location: index.php?m=chat&page=meetings&action=show&id=' . $id);
            exit;
        }

        Meeting::update($id, ['status' => 'cancelled']);

        // Notify participants
        $participantIds = array_column($meeting['participants'], 'id');
        $notifyIds      = array_filter($participantIds, fn(int $uid) => $uid !== Session::userId());

        if (!empty($notifyIds)) {
            Notification::createForMany(
                $notifyIds,
                'meeting',
                'Reunião cancelada: ' . $meeting['title'],
                Session::userName() . ' cancelou a reunião.',
                'index.php?m=chat&page=meetings&action=show&id=' . $id
            );
        }

        AuditLog::log('cancel', 'meeting', $id, ['status' => $meeting['status']], ['status' => 'cancelled']);

        Session::flash('success', 'Reunião cancelada.');
        header('Location: index.php?m=chat&page=meetings');
        exit;
    }

    /* ------------------------------------------------------------------
     *  calendar  — Monthly calendar view of meetings
     *  GET ?page=meetings&action=calendar[&month=N&year=N]
     * ----------------------------------------------------------------*/
    public function calendar(): void
    {
        Auth::requireLogin();
        core_require('calendar.view');

        $userId = Session::userId();

        $month = isset($_GET['month']) ? Sanitize::int($_GET['month']) : (int) date('n');
        $year  = isset($_GET['year'])  ? Sanitize::int($_GET['year'])  : (int) date('Y');

        // Clamp values
        if ($month < 1 || $month > 12) $month = (int) date('n');
        if ($year < 2000 || $year > 2100) $year = (int) date('Y');

        $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $end   = date('Y-m-t 23:59:59', mktime(0, 0, 0, $month, 1, $year));

        $meetings = Meeting::allForCalendar($userId, $start, $end);

        // Group meetings by day for the template
        $byDay = [];
        foreach ($meetings as $m) {
            $day = (int) date('j', strtotime($m['scheduled_at']));
            $byDay[$day][] = $m;
        }

        $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        $firstDow    = (int) date('w', mktime(0, 0, 0, $month, 1, $year));

        // Previous / next month links
        $prevMonth = $month - 1;
        $prevYear  = $year;
        if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }

        $nextMonth = $month + 1;
        $nextYear  = $year;
        if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

        View::render('meetings/calendar', [
            'pageTitle'   => 'Calendário de Reuniões',
            'meetings'    => $meetings,
            'byDay'       => $byDay,
            'month'       => $month,
            'year'        => $year,
            'daysInMonth' => $daysInMonth,
            'firstDow'    => $firstDow,
            'prevMonth'   => $prevMonth,
            'prevYear'    => $prevYear,
            'nextMonth'   => $nextMonth,
            'nextYear'    => $nextYear,
        ]);
    }
}
