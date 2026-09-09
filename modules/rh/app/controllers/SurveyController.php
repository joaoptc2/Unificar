<?php
/**
 * SurveyController — pesquisas.
 *
 *   page=surveys                       lista (surveys.view)
 *   action=create|store                nova (surveys.create) — construtor de perguntas
 *   action=edit|update                 editar (surveys.edit)
 *   action=close                       encerrar (surveys.edit) — POST
 *   action=delete                      excluir (surveys.delete) — POST
 *   action=show&id=N                   resultados (surveys.view)
 *   action=respond                     POST de resposta (portal: page=my&action=survey)
 */
class SurveyController
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function index(): void
    {
        core_require('surveys.view');
        View::render('surveys/index', ['pageTitle' => 'Pesquisas', 'page' => 'surveys', 'surveys' => Survey::listAll()]);
    }

    public function create(): void
    {
        core_require('surveys.create');
        $this->form(null);
    }

    public function edit(): void
    {
        core_require('surveys.edit');
        $survey = Survey::withQuestions(Sanitize::int($_GET['id'] ?? 0));
        if (!$survey) { Session::flash('error', 'Pesquisa não encontrada.'); header('Location: index.php?m=rh&page=surveys'); exit; }
        $this->form($survey);
    }

    private function form(?array $survey): void
    {
        $depts = $this->db->query('SELECT id, name FROM rh_departments WHERE active = 1 ORDER BY name')->fetchAll();
        View::render('surveys/form', [
            'pageTitle' => $survey ? 'Editar Pesquisa' : 'Nova Pesquisa', 'page' => 'surveys',
            'survey' => $survey, 'departments' => $depts,
        ]);
    }

    public function store(): void
    {
        core_require('surveys.create'); Csrf::check();
        $data = $this->formData();
        $questions = $this->questionsFromPost();
        if ($data['title'] === '') { Session::flash('error', 'Título obrigatório.'); header('Location: index.php?m=rh&page=surveys&action=create'); exit; }
        if (!$questions) { Session::flash('error', 'Adicione ao menos uma pergunta.'); header('Location: index.php?m=rh&page=surveys&action=create'); exit; }
        $data['created_by'] = Session::userId();
        $id = Survey::insert($data);
        $n = Survey::saveQuestions($id, $questions);
        AuditLog::log('create', 'surveys', $id);
        $this->afterSave($id, $data['status'], "Pesquisa criada com {$n} pergunta(s).");
    }

    public function update(): void
    {
        core_require('surveys.edit'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $old = Survey::find($id);
        if (!$old) { Session::flash('error', 'Pesquisa não encontrada.'); header('Location: index.php?m=rh&page=surveys'); exit; }
        $data = $this->formData();
        $questions = $this->questionsFromPost();
        if ($data['title'] === '') { Session::flash('error', 'Título obrigatório.'); header('Location: index.php?m=rh&page=surveys&action=edit&id=' . $id); exit; }
        if (!$questions) { Session::flash('error', 'Adicione ao menos uma pergunta.'); header('Location: index.php?m=rh&page=surveys&action=edit&id=' . $id); exit; }
        Survey::update($id, $data);
        // Com respostas registradas, as perguntas ficam congeladas (preserva a integridade dos resultados).
        $msg = 'Pesquisa atualizada.';
        if (Survey::participantCount($id) === 0) {
            Survey::saveQuestions($id, $questions);
        } else {
            $msg .= ' As perguntas não foram alteradas porque já existem respostas.';
        }
        AuditLog::log('update', 'surveys', $id);
        $this->afterSave($id, $data['status'], $msg);
    }

    private function afterSave(int $id, string $status, string $msg): void
    {
        if ($status === 'ativa') {
            $stats = Survey::dispatch($id);
            if ($stats['notified']) { $msg .= " {$stats['notified']} funcionário(s) notificado(s) no portal."; }
            if ($stats['queued'])   { $msg .= " {$stats['queued']} e-mail(s) enfileirado(s)."; }
        }
        Session::flash('success', $msg);
        header('Location: index.php?m=rh&page=surveys'); exit;
    }

    /** Encerra a pesquisa (POST). */
    public function close(): void
    {
        core_require('surveys.edit'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        Survey::update($id, ['status' => 'encerrada']);
        AuditLog::log('close', 'surveys', $id);
        Session::flash('success', 'Pesquisa encerrada.'); header('Location: index.php?m=rh&page=surveys&action=show&id=' . $id); exit;
    }

    private function formData(): array
    {
        $type = Sanitize::post('type');
        if (!isset(Survey::TYPES[$type])) { $type = 'clima'; }
        $status = Sanitize::post('status');
        if (!in_array($status, ['rascunho', 'ativa', 'encerrada'], true)) { $status = 'rascunho'; }
        return [
            'title' => mb_substr(Sanitize::post('title'), 0, 200),
            'description' => Sanitize::post('description') ?: null,
            'type' => $type,
            'anonymous' => !empty($_POST['anonymous']) ? 1 : 0,
            'department_id' => Sanitize::int($_POST['department_id'] ?? 0) ?: null,
            'show_in_portal' => !empty($_POST['show_in_portal']) ? 1 : 0,
            'send_email' => !empty($_POST['send_email']) ? 1 : 0,
            'status' => $status,
            'starts_at' => Sanitize::date($_POST['starts_at'] ?? '') ?: null,
            'ends_at' => Sanitize::date($_POST['ends_at'] ?? '') ?: null,
        ];
    }

    /** Normaliza as perguntas do construtor (questions[i][...]). */
    private function questionsFromPost(): array
    {
        $out = [];
        foreach ((array)($_POST['questions'] ?? []) as $q) {
            if (!is_array($q)) continue;
            $text = mb_substr(Sanitize::string($q['question'] ?? ''), 0, 1000);
            if ($text === '') continue;
            $type = (string)($q['type'] ?? 'rating');
            if (!isset(Survey::QUESTION_TYPES[$type])) { $type = 'rating'; }
            $opts = [];
            if ($type === 'choice' || $type === 'multiple') {
                $lines = preg_split('/\r\n|\r|\n/', (string)($q['options'] ?? '')) ?: [];
                $options = [];
                foreach ($lines as $l) { $l = trim($l); if ($l !== '' && !in_array($l, $options, true)) { $options[] = mb_substr($l, 0, 200); } }
                if (count($options) < 2) continue; // escolha precisa de ao menos 2 opções
                $opts = ['options' => $options];
            } elseif ($type === 'scale') {
                $min = Sanitize::int($q['min'] ?? 0); $max = Sanitize::int($q['max'] ?? 10);
                if ($max <= $min) { $min = 0; $max = 10; }
                if ($max - $min > 100) { $max = $min + 100; }
                $opts = ['min' => $min, 'max' => $max,
                         'min_label' => mb_substr(Sanitize::string($q['min_label'] ?? ''), 0, 60),
                         'max_label' => mb_substr(Sanitize::string($q['max_label'] ?? ''), 0, 60)];
            }
            $out[] = [
                'id' => Sanitize::int($q['id'] ?? 0), 'question' => $text, 'type' => $type, 'opts' => $opts,
                'required' => !empty($q['required']) ? 1 : 0,
                'help_text' => mb_substr(Sanitize::string($q['help_text'] ?? ''), 0, 255) ?: null,
            ];
        }
        return $out;
    }

    public function show(): void
    {
        core_require('surveys.view');
        $survey = Survey::withQuestions(Sanitize::int($_GET['id'] ?? 0));
        if (!$survey) { Session::flash('error', 'Pesquisa não encontrada.'); header('Location: index.php?m=rh&page=surveys'); exit; }
        $participants = Survey::participantCount((int)$survey['id']);
        $audience     = Survey::audienceCount($survey['department_id'] ? (int)$survey['department_id'] : null);
        $dept = $survey['department_id'] ? $this->db->query('SELECT name FROM rh_departments WHERE id = ' . (int)$survey['department_id'])->fetchColumn() : null;
        View::render('surveys/show', [
            'pageTitle' => $survey['title'], 'page' => 'surveys', 'survey' => $survey,
            'stats' => Survey::stats((int)$survey['id']), 'participants' => $participants, 'audience' => $audience,
            'departmentName' => $dept ?: null,
            'answered' => Survey::hasParticipated((int)$survey['id'], (int)Session::userId()),
            'employeeDept' => $this->userDepartment(),
        ]);
    }

    /** Grava a resposta (POST do portal). */
    public function respond(): void
    {
        core_require('surveys.view'); Csrf::check();
        $surveyId = Sanitize::int($_POST['survey_id'] ?? 0);
        $userId   = (int)Session::userId();
        $survey   = Survey::withQuestions($surveyId);
        $back     = core_can('my.view') ? 'index.php?m=rh&page=my&action=survey&id=' . $surveyId : 'index.php?m=rh&page=surveys&action=show&id=' . $surveyId;
        $done     = core_can('my.view') ? 'index.php?m=rh&page=my#pesquisas' : 'index.php?m=rh&page=surveys';

        if (!$survey || !Survey::isOpen($survey)) { Session::flash('error', 'Pesquisa indisponível.'); header("Location: $done"); exit; }
        if (!Survey::targets($survey, $this->userDepartment()) && !core_can('surveys.create')) {
            Session::flash('error', 'Esta pesquisa não é destinada ao seu departamento.'); header("Location: $done"); exit;
        }
        if (Survey::hasParticipated($surveyId, $userId)) { Session::flash('error', 'Você já respondeu esta pesquisa.'); header("Location: $done"); exit; }

        $answers = (array)($_POST['answers'] ?? []);
        $rows = []; $errors = [];
        foreach ($survey['questions'] as $q) {
            $qid = (int)$q['id'];
            $raw = $answers[$qid] ?? null;
            $v = $this->validateAnswer($q, $raw);
            if ($v === false) { $errors[] = $q['question']; continue; }
            if ($v === null) continue; // opcional em branco
            $rows[] = [$qid, $v['rating'], $v['answer']];
        }
        if ($errors) {
            Session::flash('error', 'Responda corretamente: ' . Sanitize::e(implode('; ', array_map(fn($e) => mb_substr($e, 0, 60), $errors))));
            header("Location: $back"); exit;
        }

        $respUser = (int)$survey['anonymous'] ? null : $userId;
        $this->db->beginTransaction();
        try {
            $ins = $this->db->prepare('INSERT INTO rh_survey_responses (survey_id, question_id, user_id, rating, answer) VALUES (?,?,?,?,?)');
            foreach ($rows as [$qid, $rating, $answer]) { $ins->execute([$surveyId, $qid, $respUser, $rating, $answer]); }
            $this->db->prepare('INSERT INTO rh_survey_participations (survey_id, user_id) VALUES (?, ?)')->execute([$surveyId, $userId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            Session::flash('error', 'Não foi possível registrar a resposta. Tente novamente.');
            header("Location: $back"); exit;
        }
        Session::flash('success', 'Resposta registrada. Obrigado por participar!');
        header("Location: $done"); exit;
    }

    /**
     * Valida uma resposta pelo tipo. Retorna ['rating'=>?, 'answer'=>?],
     * null (opcional em branco) ou false (inválida/obrigatória).
     */
    private function validateAnswer(array $q, $raw)
    {
        $required = (int)($q['required'] ?? 0) === 1;
        $blank = fn () => $required ? false : null;
        switch ($q['type']) {
            case 'rating':
                if ($raw === null || $raw === '') return $blank();
                $n = (int)$raw; if ($n < 1 || $n > 5) return false;
                return ['rating' => $n, 'answer' => null];
            case 'scale':
                if ($raw === null || $raw === '') return $blank();
                $min = (int)($q['opts']['min'] ?? 0); $max = (int)($q['opts']['max'] ?? 10);
                if (!is_numeric($raw)) return false;
                $n = (int)$raw; if ($n < $min || $n > $max) return false;
                return ['rating' => $n, 'answer' => null];
            case 'yes_no':
                if ($raw === null || $raw === '') return $blank();
                if (!in_array($raw, ['sim', 'nao'], true)) return false;
                return ['rating' => $raw === 'sim' ? 1 : 0, 'answer' => $raw];
            case 'choice':
                if ($raw === null || $raw === '') return $blank();
                if (!in_array((string)$raw, array_map('strval', (array)($q['opts']['options'] ?? [])), true)) return false;
                return ['rating' => null, 'answer' => (string)$raw];
            case 'multiple':
                $vals = array_values(array_unique(array_map('strval', (array)$raw)));
                $vals = array_values(array_intersect($vals, array_map('strval', (array)($q['opts']['options'] ?? []))));
                if (!$vals) return $blank();
                return ['rating' => null, 'answer' => json_encode($vals, JSON_UNESCAPED_UNICODE)];
            case 'number':
                if ($raw === null || trim((string)$raw) === '') return $blank();
                $s = str_replace(',', '.', trim((string)$raw));
                if (!is_numeric($s)) return false;
                return ['rating' => (int)round((float)$s), 'answer' => $s];
            case 'date':
                if ($raw === null || $raw === '') return $blank();
                $d = Sanitize::date((string)$raw); if (!$d) return false;
                return ['rating' => null, 'answer' => $d];
            default: // text
                $t = mb_substr(Sanitize::string(is_string($raw) ? $raw : ''), 0, 4000);
                if ($t === '') return $blank();
                return ['rating' => null, 'answer' => $t];
        }
    }

    private function userDepartment(): ?int
    {
        $row = Core\DB::queryOne('SELECT p.department_id, e.department_id AS emp_dept FROM rh_user_profile p LEFT JOIN rh_employees e ON e.id = p.employee_id WHERE p.user_id = ?', [Session::userId()]);
        $d = (int)($row['emp_dept'] ?? $row['department_id'] ?? 0);
        return $d ?: null;
    }

    public function delete(): void
    {
        core_require('surveys.delete'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        Survey::delete($id);
        AuditLog::log('delete', 'surveys', $id);
        Session::flash('success', 'Pesquisa excluída.'); header('Location: index.php?m=rh&page=surveys'); exit;
    }
}
