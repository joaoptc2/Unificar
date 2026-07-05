<?php
class Survey extends Model
{
    protected static string $table = 'surveys';
    protected static array $fillable = ['title','description','type','anonymous','status','starts_at','ends_at','created_by'];

    public static function withQuestions(int $id): ?array
    {
        $survey = self::find($id);
        if (!$survey) return null;
        $stmt = self::db()->prepare('SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order');
        $stmt->execute([$id]);
        $survey['questions'] = $stmt->fetchAll();
        return $survey;
    }

    public static function responseCount(int $surveyId): int
    {
        $stmt = self::db()->prepare('SELECT COUNT(DISTINCT IFNULL(user_id, id)) FROM survey_responses WHERE survey_id = ?');
        $stmt->execute([$surveyId]);
        return (int)$stmt->fetchColumn();
    }

    public static function results(int $surveyId): array
    {
        $stmt = self::db()->prepare(
            'SELECT q.id, q.question, q.type, AVG(r.rating) AS avg_rating, COUNT(r.id) AS total_responses
             FROM survey_questions q
             LEFT JOIN survey_responses r ON r.question_id = q.id
             WHERE q.survey_id = ?
             GROUP BY q.id ORDER BY q.sort_order'
        );
        $stmt->execute([$surveyId]);
        return $stmt->fetchAll();
    }
}
