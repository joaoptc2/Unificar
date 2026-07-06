<?php
/**
 * PollController — Poll management for channels.
 *
 * Every public method maps to ?page=polls&action=X.
 */
class PollController
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /* ------------------------------------------------------------------
     *  create  — Show poll creation form
     *  GET ?page=polls&action=create(&channel_id=N)
     * ----------------------------------------------------------------*/
    public function create(): void
    {
        Auth::requireLogin();
        core_require('polls.create');

        $channelId = Sanitize::int($_GET['channel_id'] ?? 0);
        $channels  = Channel::userChannels(Session::userId());

        View::render('polls/form', [
            'pageTitle'  => 'Nova Enquete',
            'channelId'  => $channelId,
            'channels'   => $channels,
            'csrfToken'  => Csrf::token(),
        ]);
    }

    /* ------------------------------------------------------------------
     *  store  — Persist a new poll
     *  POST ?page=polls&action=store
     * ----------------------------------------------------------------*/
    public function store(): void
    {
        Auth::requireLogin();
        core_require('polls.create');
        Csrf::check();

        $userId    = Session::userId();
        $question  = Sanitize::post('question');
        $channelId = Sanitize::int($_POST['channel_id'] ?? 0);
        $isAnon    = Sanitize::int($_POST['is_anonymous'] ?? 0) ? 1 : 0;
        $isMulti   = Sanitize::int($_POST['is_multiple'] ?? 0) ? 1 : 0;
        $closesAt  = Sanitize::post('closes_at');

        // --- validation ---------------------------------------------------
        if ($question === '') {
            Session::flash('error', 'A pergunta da enquete é obrigatória.');
            header('Location: index.php?m=chat&page=polls&action=create&channel_id=' . $channelId);
            exit;
        }

        $rawOptions = $_POST['options'] ?? [];
        $options = [];
        foreach ($rawOptions as $opt) {
            $opt = Sanitize::string($opt);
            if ($opt !== '') {
                $options[] = $opt;
            }
        }

        if (count($options) < 2) {
            Session::flash('error', 'A enquete precisa de pelo menos 2 opções.');
            header('Location: index.php?m=chat&page=polls&action=create&channel_id=' . $channelId);
            exit;
        }

        if ($channelId <= 0 || !Channel::find($channelId)) {
            Session::flash('error', 'Canal inválido.');
            header('Location: index.php?m=chat&page=polls&action=create');
            exit;
        }

        if (!Channel::isMember($channelId, $userId)) {
            Session::flash('error', 'Você não é membro deste canal.');
            header('Location: index.php?m=chat&page=polls&action=create');
            exit;
        }

        // --- create poll --------------------------------------------------
        $pollId = Poll::insert([
            'channel_id'  => $channelId,
            'user_id'     => $userId,
            'question'    => $question,
            'is_anonymous' => $isAnon,
            'is_multiple'  => $isMulti,
            'closes_at'    => $closesAt !== '' ? $closesAt : null,
            'is_closed'    => 0,
        ]);

        // --- add options --------------------------------------------------
        foreach ($options as $i => $text) {
            Poll::addOption($pollId, $text, $i + 1);
        }

        // --- system message in channel ------------------------------------
        $msgContent = Session::userName() . ' criou uma enquete: "' . $question . '"';
        $messageId  = Message::insert([
            'channel_id' => $channelId,
            'user_id'    => $userId,
            'content'    => $msgContent,
            'type'       => 'system',
            'metadata'   => json_encode(['poll_id' => $pollId]),
        ]);

        // Link message back to poll
        Poll::update($pollId, ['message_id' => $messageId]);

        // --- notify channel members ---------------------------------------
        $members   = Channel::members($channelId);
        $notifyIds = array_filter(
            array_column($members, 'id'),
            fn(int $id) => $id !== $userId
        );

        if (!empty($notifyIds)) {
            $link = 'index.php?m=chat&page=chat&channel_id=' . $channelId;
            Notification::createForMany(
                $notifyIds,
                'poll',
                'Nova enquete: ' . $question,
                Session::userName() . ' criou uma enquete no canal.',
                $link
            );
        }

        AuditLog::log('create', 'poll', $pollId, null, [
            'question'   => $question,
            'channel_id' => $channelId,
            'options'    => count($options),
        ]);

        Session::flash('success', 'Enquete criada com sucesso.');
        header('Location: index.php?m=chat&page=chat&channel_id=' . $channelId);
        exit;
    }

    /* ------------------------------------------------------------------
     *  vote  — Cast a vote (AJAX)
     *  POST ?page=polls&action=vote
     * ----------------------------------------------------------------*/
    public function vote(): void
    {
        Auth::requireLogin();
        if (!core_can('polls.view')) {
            $this->jsonResponse(false, 'Sem permissão para votar.', 403);
            return;
        }

        if (!Csrf::checkAjax()) {
            $this->jsonResponse(false, 'Token CSRF inválido.', 403);
            return;
        }

        $pollId   = Sanitize::int($_POST['poll_id'] ?? 0);
        $optionId = Sanitize::int($_POST['option_id'] ?? 0);
        $userId   = Session::userId();

        $poll = Poll::find($pollId);
        if (!$poll) {
            $this->jsonResponse(false, 'Enquete não encontrada.', 404);
            return;
        }

        if ((int) $poll['is_closed']) {
            $this->jsonResponse(false, 'Esta enquete já foi encerrada.', 400);
            return;
        }

        if ($poll['closes_at'] && strtotime($poll['closes_at']) < time()) {
            Poll::closePoll($pollId);
            $this->jsonResponse(false, 'Esta enquete já foi encerrada.', 400);
            return;
        }

        $success = Poll::vote($pollId, $optionId, $userId);

        if (!$success) {
            $this->jsonResponse(false, 'Erro ao registrar voto.', 500);
            return;
        }

        $results = Poll::results($pollId);

        $this->jsonResponse(true, 'Voto registrado.', 200, [
            'results' => $results,
        ]);
    }

    /* ------------------------------------------------------------------
     *  removeVote  — Remove a vote (AJAX)
     *  POST ?page=polls&action=removeVote
     * ----------------------------------------------------------------*/
    public function removeVote(): void
    {
        Auth::requireLogin();
        if (!core_can('polls.view')) {
            $this->jsonResponse(false, 'Sem permissão para votar.', 403);
            return;
        }

        if (!Csrf::checkAjax()) {
            $this->jsonResponse(false, 'Token CSRF inválido.', 403);
            return;
        }

        $pollId   = Sanitize::int($_POST['poll_id'] ?? 0);
        $optionId = Sanitize::int($_POST['option_id'] ?? 0);
        $userId   = Session::userId();

        $poll = Poll::find($pollId);
        if (!$poll) {
            $this->jsonResponse(false, 'Enquete não encontrada.', 404);
            return;
        }

        if ((int) $poll['is_closed']) {
            $this->jsonResponse(false, 'Esta enquete já foi encerrada.', 400);
            return;
        }

        Poll::removeVote($pollId, $optionId, $userId);

        $results = Poll::results($pollId);

        $this->jsonResponse(true, 'Voto removido.', 200, [
            'results' => $results,
        ]);
    }

    /* ------------------------------------------------------------------
     *  results  — Get poll results (AJAX)
     *  GET ?page=polls&action=results&poll_id=N
     * ----------------------------------------------------------------*/
    public function results(): void
    {
        Auth::requireLogin();
        if (!core_can('polls.view')) {
            $this->jsonResponse(false, 'Sem permissão.', 403);
            return;
        }

        $pollId = Sanitize::int($_GET['poll_id'] ?? 0);

        $poll = Poll::find($pollId);
        if (!$poll) {
            $this->jsonResponse(false, 'Enquete não encontrada.', 404);
            return;
        }

        $results = Poll::results($pollId);

        $this->jsonResponse(true, 'OK', 200, [
            'poll'    => [
                'id'        => (int) $poll['id'],
                'question'  => $poll['question'],
                'is_closed' => (int) $poll['is_closed'],
            ],
            'results' => $results,
        ]);
    }

    /* ------------------------------------------------------------------
     *  close  — Close a poll (creator or admin only)
     *  POST ?page=polls&action=close
     * ----------------------------------------------------------------*/
    public function close(): void
    {
        Auth::requireLogin();

        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if ($isAjax) {
            if (!Csrf::checkAjax()) {
                $this->jsonResponse(false, 'Token CSRF inválido.', 403);
                return;
            }
        } else {
            Csrf::check();
        }

        $pollId = Sanitize::int($_POST['poll_id'] ?? 0);
        $userId = Session::userId();

        $poll = Poll::find($pollId);
        if (!$poll) {
            if ($isAjax) {
                $this->jsonResponse(false, 'Enquete não encontrada.', 404);
            } else {
                Session::flash('error', 'Enquete não encontrada.');
                header('Location: index.php?m=chat&page=chat');
            }
            return;
        }

        // Criador encerra a própria enquete; polls.edit encerra as demais
        if ((int) $poll['user_id'] !== $userId && !core_can('polls.edit')) {
            if ($isAjax) {
                $this->jsonResponse(false, 'Sem permissão para encerrar esta enquete.', 403);
            } else {
                Session::flash('error', 'Sem permissão para encerrar esta enquete.');
                header('Location: index.php?m=chat&page=chat&channel_id=' . $poll['channel_id']);
            }
            return;
        }

        Poll::closePoll($pollId);

        AuditLog::log('close', 'poll', $pollId, ['is_closed' => 0], ['is_closed' => 1]);

        if ($isAjax) {
            $results = Poll::results($pollId);
            $this->jsonResponse(true, 'Enquete encerrada.', 200, [
                'results' => $results,
            ]);
        } else {
            Session::flash('success', 'Enquete encerrada com sucesso.');
            header('Location: index.php?m=chat&page=chat&channel_id=' . $poll['channel_id']);
            exit;
        }
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    /**
     * Send a JSON response.
     */
    private function jsonResponse(bool $success, string $message, int $code = 200, array $extra = []): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    }
}
