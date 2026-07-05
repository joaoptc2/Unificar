<?php
class SurveyController
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }
    public function index(): void
    {
        Auth::requirePermission('surveys', 'view');
        $surveys = Survey::all(['order' => 'created_at DESC']);
        View::render('surveys/index', ['pageTitle' => 'Pesquisas', 'page' => 'surveys', 'surveys' => $surveys]);
    }
    public function create(): void
    {
        Auth::requirePermission('surveys', 'create');
        View::render('surveys/form', ['pageTitle' => 'Nova Pesquisa', 'page' => 'surveys', 'survey' => null]);
    }
    public function store(): void
    {
        Auth::requirePermission('surveys', 'create'); Csrf::check();
        $title = Sanitize::post('title');
        if (!$title) { Session::flash('error', 'Titulo obrigatorio.'); header('Location: index.php?page=surveys&action=create'); exit; }
        $id = Survey::insert([
            'title' => $title, 'description' => Sanitize::post('description'),
            'type' => Sanitize::post('type') ?: 'clima', 'anonymous' => !empty($_POST['anonymous']) ? 1 : 0,
            'status' => Sanitize::post('status') ?: 'rascunho',
            'starts_at' => Sanitize::date($_POST['starts_at'] ?? '') ?: null,
            'ends_at' => Sanitize::date($_POST['ends_at'] ?? '') ?: null,
            'created_by' => Session::userId(),
        ]);
        $questions = $_POST['questions'] ?? [];
        $order = 0;
        foreach ($questions as $q) {
            $text = trim($q['question'] ?? ''); if (!$text) continue; $order++;
            $this->db->prepare('INSERT INTO survey_questions (survey_id, question, type, sort_order) VALUES (?,?,?,?)')
                     ->execute([$id, $text, $q['type'] ?? 'rating', $order]);
        }
        AuditLog::log('create', 'surveys', $id);
        Session::flash('success', 'Pesquisa criada com ' . $order . ' perguntas.');
        header('Location: index.php?page=surveys'); exit;
    }
    public function show(): void
    {
        Auth::requirePermission('surveys', 'view');
        $survey = Survey::withQuestions(Sanitize::int($_GET['id'] ?? 0));
        if (!$survey) { Session::flash('error', 'Nao encontrada.'); header('Location: index.php?page=surveys'); exit; }
        $results = Survey::results((int)$survey['id']);
        $totalResp = Survey::responseCount((int)$survey['id']);
        View::render('surveys/show', ['pageTitle' => $survey['title'], 'page' => 'surveys', 'survey' => $survey, 'results' => $results, 'totalResp' => $totalResp]);
    }
    public function respond(): void
    {
        Auth::requireLogin(); Csrf::check();
        $surveyId = Sanitize::int($_POST['survey_id'] ?? 0);
        $survey = Survey::find($surveyId);
        if (!$survey || $survey['status'] !== 'ativa') { Session::flash('error', 'Pesquisa indisponivel.'); header('Location: index.php?page=surveys'); exit; }
        $answers = $_POST['answers'] ?? [];
        $userId = (int)$survey['anonymous'] ? null : Session::userId();
        foreach ($answers as $qId => $val) {
            $qId = (int)$qId; $rating = is_numeric($val) ? (int)$val : null; $text = is_string($val) && !is_numeric($val) ? $val : null;
            $this->db->prepare('INSERT INTO survey_responses (survey_id, question_id, user_id, rating, answer) VALUES (?,?,?,?,?)')
                     ->execute([$surveyId, $qId, $userId, $rating, $text]);
        }
        Session::flash('success', 'Resposta registrada. Obrigado!');
        header('Location: index.php?page=surveys'); exit;
    }
    public function delete(): void
    {
        Auth::requirePermission('surveys', 'delete'); Csrf::check();
        Survey::delete(Sanitize::int($_POST['id'] ?? 0));
        Session::flash('success', 'Pesquisa excluida.'); header('Location: index.php?page=surveys'); exit;
    }
}
