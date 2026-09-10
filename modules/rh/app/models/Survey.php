<?php
/**
 * Survey — pesquisas (clima, eNPS, pulse, personalizadas) com construtor de
 * perguntas, público-alvo, portal do funcionário e envio por e-mail.
 *
 * Tipos de pergunta (rh_survey_questions.type) e formato da resposta:
 *   rating   1–5 estrelas                    → rating
 *   scale    escala numérica min..max        → rating  (options: {min,max,min_label,max_label})
 *   yes_no   sim/não                         → rating 1/0 + answer 'sim'/'nao'
 *   choice   escolha única                   → answer (texto da opção)  (options: {options:[...]})
 *   multiple múltipla escolha                → answer JSON ["a","b"]     (options: {options:[...]})
 *   text     texto livre                     → answer
 *   number   número                          → rating (int) + answer (valor original)
 *   date     data                            → answer Y-m-d
 */
class Survey extends Model
{
    protected static string $table = 'rh_surveys';
    protected static array $fillable = [
        'title', 'description', 'type', 'anonymous', 'department_id', 'show_in_portal', 'send_email', 'emailed_at',
        'notified_at', 'status', 'starts_at', 'ends_at', 'created_by',
    ];

    public const QUESTION_TYPES = [
        'rating'   => 'Avaliação (1–5 estrelas)',
        'scale'    => 'Escala numérica',
        'yes_no'   => 'Sim / Não',
        'choice'   => 'Escolha única',
        'multiple' => 'Múltipla escolha',
        'text'     => 'Texto livre',
        'number'   => 'Número',
        'date'     => 'Data',
    ];

    public const TYPES = ['clima' => 'Clima', 'enps' => 'eNPS', 'pulse' => 'Pulse', 'custom' => 'Personalizada'];

    public static function withQuestions(int $id): ?array
    {
        $survey = self::find($id);
        if (!$survey) return null;
        $survey['questions'] = self::questions($id);
        return $survey;
    }

    public static function questions(int $surveyId): array
    {
        $stmt = self::db()->prepare('SELECT * FROM rh_survey_questions WHERE survey_id = ? ORDER BY sort_order, id');
        $stmt->execute([$surveyId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$q) {
            $opts = json_decode((string)($q['options'] ?? ''), true);
            $q['opts'] = is_array($opts) ? $opts : [];
        }
        return $rows;
    }

    /** Substitui as perguntas da pesquisa (construtor). $questions já validadas. */
    public static function saveQuestions(int $surveyId, array $questions): int
    {
        $db = self::db();
        $keep = [];
        $order = 0;
        $ins = $db->prepare('INSERT INTO rh_survey_questions (survey_id, question, type, options, required, help_text, sort_order) VALUES (?,?,?,?,?,?,?)');
        $upd = $db->prepare('UPDATE rh_survey_questions SET question = ?, type = ?, options = ?, required = ?, help_text = ?, sort_order = ? WHERE id = ? AND survey_id = ?');
        foreach ($questions as $q) {
            $order++;
            $optsJson = $q['opts'] ? json_encode($q['opts'], JSON_UNESCAPED_UNICODE) : null;
            if (!empty($q['id'])) {
                $upd->execute([$q['question'], $q['type'], $optsJson, $q['required'], $q['help_text'], $order, (int)$q['id'], $surveyId]);
                if ($upd->rowCount() >= 0) {
                    $chk = $db->prepare('SELECT id FROM rh_survey_questions WHERE id = ? AND survey_id = ?');
                    $chk->execute([(int)$q['id'], $surveyId]);
                    if ($chk->fetchColumn()) { $keep[] = (int)$q['id']; continue; }
                }
            }
            $ins->execute([$surveyId, $q['question'], $q['type'], $optsJson, $q['required'], $q['help_text'], $order]);
            $keep[] = (int)$db->lastInsertId();
        }
        if ($keep) {
            $in = implode(',', array_fill(0, count($keep), '?'));
            $db->prepare("DELETE FROM rh_survey_questions WHERE survey_id = ? AND id NOT IN ({$in})")->execute([$surveyId, ...$keep]);
        } else {
            $db->prepare('DELETE FROM rh_survey_questions WHERE survey_id = ?')->execute([$surveyId]);
        }
        return $order;
    }

    /** Listagem com contagem de participações — 1 consulta. $includeDrafts=false oculta rascunhos. */
    public static function listAll(bool $includeDrafts = true): array
    {
        return self::db()->query(
            "SELECT s.*, d.name AS department_name,
                    (SELECT COUNT(*) FROM rh_survey_participations p WHERE p.survey_id = s.id) AS participants,
                    (SELECT COUNT(*) FROM rh_survey_questions q WHERE q.survey_id = s.id) AS question_count
             FROM rh_surveys s LEFT JOIN rh_departments d ON d.id = s.department_id
             " . ($includeDrafts ? '' : "WHERE s.status <> 'rascunho'") . "
             ORDER BY FIELD(s.status,'ativa','rascunho','encerrada'), s.created_at DESC"
        )->fetchAll();
    }

    /** true se a pesquisa está aberta hoje (status ativa e dentro do período). */
    public static function isOpen(array $s): bool
    {
        if (($s['status'] ?? '') !== 'ativa') return false;
        $today = date('Y-m-d');
        if (!empty($s['starts_at']) && $s['starts_at'] > $today) return false;
        if (!empty($s['ends_at']) && $s['ends_at'] < $today) return false;
        return true;
    }

    /** O usuário/departamento faz parte do público-alvo? */
    public static function targets(array $s, ?int $deptId): bool
    {
        return empty($s['department_id']) || (int)$s['department_id'] === (int)$deptId;
    }

    public static function hasParticipated(int $surveyId, int $userId): bool
    {
        $stmt = self::db()->prepare('SELECT 1 FROM rh_survey_participations WHERE survey_id = ? AND user_id = ?');
        $stmt->execute([$surveyId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    /** Pesquisas ativas do portal ainda não respondidas pelo usuário (e as já respondidas, para exibir status). */
    public static function forPortal(int $userId, ?int $deptId): array
    {
        $stmt = self::db()->prepare(
            "SELECT s.id, s.title, s.description, s.type, s.anonymous, s.starts_at, s.ends_at,
                    (p.user_id IS NOT NULL) AS answered, p.completed_at,
                    (SELECT COUNT(*) FROM rh_survey_questions q WHERE q.survey_id = s.id) AS question_count
             FROM rh_surveys s
             LEFT JOIN rh_survey_participations p ON p.survey_id = s.id AND p.user_id = ?
             WHERE s.status = 'ativa' AND s.show_in_portal = 1
               AND (s.starts_at IS NULL OR s.starts_at <= CURDATE())
               AND (s.ends_at IS NULL OR s.ends_at >= CURDATE())
               AND (s.department_id IS NULL OR s.department_id = ?)
             ORDER BY answered ASC, s.created_at DESC"
        );
        $stmt->execute([$userId, $deptId ?: 0]);
        return $stmt->fetchAll();
    }

    public static function participantCount(int $surveyId): int
    {
        $stmt = self::db()->prepare('SELECT COUNT(*) FROM rh_survey_participations WHERE survey_id = ?');
        $stmt->execute([$surveyId]);
        return (int)$stmt->fetchColumn();
    }

    /** Tamanho do público-alvo (funcionários ativos com usuário vinculado). */
    public static function audienceCount(?int $deptId): int
    {
        $sql = "SELECT COUNT(*) FROM rh_employees e JOIN rh_user_profile p ON p.employee_id = e.id
                JOIN users u ON u.id = p.user_id AND u.active = 1
                WHERE e.status = 'ativo' AND e.anonymized_at IS NULL";
        $params = [];
        if ($deptId) { $sql .= ' AND e.department_id = ?'; $params[] = $deptId; }
        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Estatísticas por pergunta (média, distribuição, contagens por opção,
     * lista de textos) — 2 consultas no total.
     */
    public static function stats(int $surveyId): array
    {
        $questions = self::questions($surveyId);
        $stmt = self::db()->prepare('SELECT question_id, rating, answer FROM rh_survey_responses WHERE survey_id = ? ORDER BY id');
        $stmt->execute([$surveyId]);
        $byQ = [];
        foreach ($stmt->fetchAll() as $r) {
            $byQ[(int)$r['question_id']][] = $r;
        }
        $out = [];
        foreach ($questions as $q) {
            $rows = $byQ[(int)$q['id']] ?? [];
            $st = ['question' => $q, 'total' => count($rows), 'avg' => null, 'min' => null, 'max' => null, 'dist' => [], 'counts' => [], 'texts' => [], 'scale_min' => 1, 'scale_max' => 5];
            switch ($q['type']) {
                case 'rating':
                case 'scale':
                case 'number':
                    $vals = [];
                    foreach ($rows as $r) { if ($r['rating'] !== null) { $vals[] = (int)$r['rating']; } }
                    if ($vals) {
                        $st['avg'] = round(array_sum($vals) / count($vals), 2);
                        $st['min'] = min($vals); $st['max'] = max($vals);
                    }
                    if ($q['type'] !== 'number') {
                        $min = $q['type'] === 'rating' ? 1 : (int)($q['opts']['min'] ?? 1);
                        $max = $q['type'] === 'rating' ? 5 : (int)($q['opts']['max'] ?? 10);
                        $st['scale_min'] = $min; $st['scale_max'] = $max;
                        for ($i = $min; $i <= $max; $i++) { $st['dist'][$i] = 0; }
                        foreach ($vals as $v) { if (isset($st['dist'][$v])) { $st['dist'][$v]++; } }
                    }
                    break;
                case 'yes_no':
                    $st['counts'] = ['Sim' => 0, 'Não' => 0];
                    foreach ($rows as $r) { $st['counts'][(int)$r['rating'] === 1 ? 'Sim' : 'Não']++; }
                    break;
                case 'choice':
                case 'multiple':
                    foreach ((array)($q['opts']['options'] ?? []) as $opt) { $st['counts'][(string)$opt] = 0; }
                    foreach ($rows as $r) {
                        $vals = $q['type'] === 'multiple' ? (json_decode((string)$r['answer'], true) ?: []) : [(string)$r['answer']];
                        foreach ((array)$vals as $v) {
                            $v = (string)$v;
                            if (!isset($st['counts'][$v])) { $st['counts'][$v] = 0; }
                            $st['counts'][$v]++;
                        }
                    }
                    break;
                case 'date':
                    foreach ($rows as $r) { if ($r['answer']) { $st['texts'][] = Sanitize::formatDate($r['answer']); } }
                    break;
                default: // text
                    foreach ($rows as $r) { if (trim((string)$r['answer']) !== '') { $st['texts'][] = (string)$r['answer']; } }
            }
            $out[] = $st;
        }
        return $out;
    }

    /**
     * Ao ativar: notificação in-app ao público-alvo (uma única vez — grava
     * notified_at) e, se send_email e ainda não enviado, e-mail com link
     * direto para responder (grava emailed_at). Edições posteriores não
     * reenviam nada.
     * @return array{notified:int, queued:int}
     */
    public static function dispatch(int $id): array
    {
        $s = self::find($id);
        $stats = ['notified' => 0, 'queued' => 0];
        if (!$s || $s['status'] !== 'ativa') return $stats;
        $audience = Announcement::audience($s['department_id'] ? (int)$s['department_id'] : null);
        $link = 'index.php?m=rh&page=my&action=survey&id=' . $id;

        if ((int)$s['show_in_portal'] === 1 && empty($s['notified_at'])) {
            foreach ($audience as $r) {
                if (!empty($r['user_id'])) {
                    Core\Notifications::add((int)$r['user_id'], 'Nova pesquisa: ' . $s['title'],
                        'Sua opinião é importante — responda pelo portal.', $link, 'info', 'rh');
                    $stats['notified']++;
                }
            }
            self::db()->prepare('UPDATE rh_surveys SET notified_at = NOW() WHERE id = ?')->execute([$id]);
        }
        if ((int)$s['send_email'] === 1 && empty($s['emailed_at'])) {
            $recipients = [];
            foreach ($audience as $r) {
                if ($r['real_email']) { $recipients[] = ['email' => $r['real_email'], 'name' => $r['full_name']]; }
            }
            if ($recipients) {
                $stats['queued'] = Core\MailQueue::enqueueMany($recipients, '[Pesquisa] ' . $s['title'], self::emailHtml($s), 'rh', 'survey', $id);
            }
            self::db()->prepare('UPDATE rh_surveys SET emailed_at = NOW() WHERE id = ?')->execute([$id]);
        }
        return $stats;
    }

    public static function emailHtml(array $s): string
    {
        $org  = (string)(Core\Settings::get('org_name', core_config('app.name', 'Portal')) ?? 'Portal');
        $link = core_url('index.php?m=rh&page=my&action=survey&id=' . (int)$s['id']);
        $until = !empty($s['ends_at']) ? '<p style="color:#555;font-size:13px">Disponível até ' . Sanitize::formatDate($s['ends_at']) . '.</p>' : '';
        $anon  = (int)$s['anonymous'] ? '<p style="color:#555;font-size:13px">🔒 Esta pesquisa é <strong>anônima</strong>: suas respostas não são vinculadas ao seu nome.</p>' : '';
        return '<!DOCTYPE html><html lang="pt-BR"><body style="margin:0;padding:24px;background:#f3f5f8;font-family:Arial,Helvetica,sans-serif;color:#222">'
            . '<div style="max-width:640px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.06)">'
            . '<div style="background:#6610f2;color:#fff;padding:18px 24px"><div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.85">' . Sanitize::e($org) . ' · Pesquisa</div>'
            . '<h1 style="margin:6px 0 0;font-size:22px">' . Sanitize::e((string)$s['title']) . '</h1></div>'
            . '<div style="padding:24px;font-size:15px;line-height:1.55">'
            . (!empty($s['description']) ? '<p>' . nl2br(Sanitize::e((string)$s['description'])) . '</p>' : '')
            . $until . $anon
            . '<p style="margin:24px 0 0"><a href="' . Sanitize::e($link) . '" style="display:inline-block;background:#6610f2;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:bold">Responder agora</a></p>'
            . '</div><div style="padding:12px 24px;background:#f8f9fa;color:#888;font-size:11px">E-mail automático do módulo RH — ' . Sanitize::e($org) . '.</div></div></body></html>';
    }
}
