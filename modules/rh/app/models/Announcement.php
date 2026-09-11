<?php
/**
 * Announcement — comunicados internos (portal do funcionário + e-mail).
 */
class Announcement extends Model
{
    protected static string $table = 'rh_announcements';
    protected static array $fillable = [
        'title', 'summary', 'body', 'body_html', 'type', 'department_id', 'published_at', 'expires_at', 'pinned',
        'image_path', 'attachment_path', 'attachment_name', 'show_in_portal', 'send_email', 'emailed_at', 'notified_at', 'created_by',
    ];

    public const TYPES = ['informativo' => 'Informativo', 'urgente' => 'Urgente', 'celebracao' => 'Celebração'];

    /**
     * Listagem do módulo com flag de leitura do usuário — 1 consulta.
     *  - $includeDrafts=true (quem gerencia): tudo, inclusive rascunhos/expirados.
     *  - senão: publicados e vigentes; com $deptId (ou $restrict) só os gerais
     *    ou do departamento informado e marcados "exibir no portal".
     */
    public static function published(?int $deptId = null, int $userId = 0, bool $includeDrafts = false, bool $restrict = false): array
    {
        $sql = "SELECT a.*, u.name AS author_name, d.name AS department_name,
                       (r.user_id IS NOT NULL) AS is_read,
                       (SELECT COUNT(*) FROM rh_announcement_reads rr WHERE rr.announcement_id = a.id) AS read_count
                FROM rh_announcements a
                LEFT JOIN users u ON a.created_by = u.id
                LEFT JOIN rh_departments d ON d.id = a.department_id
                LEFT JOIN rh_announcement_reads r ON r.announcement_id = a.id AND r.user_id = ?";
        $params = [$userId];
        $conds = [];
        if (!$includeDrafts) {
            $conds[] = 'a.published_at IS NOT NULL AND a.published_at <= NOW() AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())';
        }
        if ($deptId || $restrict) {
            $conds[] = '(a.department_id IS NULL OR a.department_id = ?)';
            $params[] = (int)$deptId;
        }
        if ($restrict) {
            $conds[] = 'a.show_in_portal = 1';
        }
        if ($conds) {
            $sql .= ' WHERE ' . implode(' AND ', $conds);
        }
        $sql .= ' ORDER BY a.pinned DESC, COALESCE(a.published_at, a.created_at) DESC';
        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Comunicados do portal do funcionário (publicados, vigentes, show_in_portal, do departamento ou gerais). */
    public static function forPortal(?int $deptId, int $userId): array
    {
        $sql = "SELECT a.id, a.title, a.summary, a.body, a.type, a.pinned, a.image_path, a.attachment_name, a.published_at, a.expires_at,
                       (r.user_id IS NOT NULL) AS is_read
                FROM rh_announcements a
                LEFT JOIN rh_announcement_reads r ON r.announcement_id = a.id AND r.user_id = ?
                WHERE a.show_in_portal = 1 AND a.published_at IS NOT NULL AND a.published_at <= NOW()
                  AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())
                  AND (a.department_id IS NULL OR a.department_id = ?)
                ORDER BY a.pinned DESC, a.published_at DESC LIMIT 50";
        $stmt = self::db()->prepare($sql);
        $stmt->execute([$userId, $deptId ?: 0]);
        return $stmt->fetchAll();
    }

    /** true se o comunicado está visível para o departamento (ou é geral) e publicado. */
    public static function visibleTo(array $a, ?int $deptId): bool
    {
        if (empty($a['published_at']) || strtotime($a['published_at']) > time()) return false;
        if (!empty($a['expires_at']) && $a['expires_at'] < date('Y-m-d')) return false;
        return empty($a['department_id']) || (int)$a['department_id'] === (int)$deptId;
    }

    /**
     * O usuário pode LER este comunicado? Quem gerencia (create/edit) lê
     * tudo; os demais só o que está publicado, vigente, marcado para o
     * portal e dirigido ao seu departamento (ou a todos).
     */
    public static function readableBy(array $a, int $userId): bool
    {
        if (core_can_any(['announcements.create', 'announcements.edit'])) return true;
        return (int)($a['show_in_portal'] ?? 0) === 1 && self::visibleTo($a, EmployeeAccess::departmentOf($userId));
    }

    public static function markRead(int $announcementId, int $userId): void
    {
        self::db()->prepare('INSERT IGNORE INTO rh_announcement_reads (announcement_id, user_id) VALUES (?, ?)')->execute([$announcementId, $userId]);
    }

    /**
     * Público-alvo: funcionários ativos do departamento (ou todos), com o
     * usuário vinculado (para notificação) e o e-mail real (funcionário ou
     * usuário), ignorando o placeholder @sem-email.local.
     */
    public static function audience(?int $deptId): array
    {
        $sql = "SELECT e.id, e.full_name, e.email, p.user_id, u.email AS user_email
                FROM rh_employees e
                LEFT JOIN rh_user_profile p ON p.employee_id = e.id
                LEFT JOIN users u ON u.id = p.user_id AND u.active = 1
                WHERE e.status = 'ativo' AND e.anonymized_at IS NULL";
        $params = [];
        if ($deptId) { $sql .= ' AND e.department_id = ?'; $params[] = $deptId; }
        $stmt = self::db()->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $email = trim((string)($r['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || EmployeeAccess::isPlaceholderEmail($email)) {
                $email = trim((string)($r['user_email'] ?? ''));
            }
            $r['real_email'] = ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !EmployeeAccess::isPlaceholderEmail($email)) ? mb_strtolower($email) : null;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Ao publicar: notificação in-app para o público-alvo (uma única vez —
     * grava notified_at) e, se send_email e ainda não enviado, enfileira o
     * e-mail (Core\MailQueue) e grava emailed_at. Edições posteriores não
     * reenviam nada.
     * @return array{notified:int, queued:int}
     */
    public static function dispatch(int $id): array
    {
        $a = self::find($id);
        $stats = ['notified' => 0, 'queued' => 0];
        if (!$a || empty($a['published_at'])) {
            return $stats;
        }
        $audience = self::audience($a['department_id'] ? (int)$a['department_id'] : null);

        if ((int)$a['show_in_portal'] === 1 && empty($a['notified_at'])) {
            foreach ($audience as $r) {
                if (!empty($r['user_id'])) {
                    Core\Notifications::add((int)$r['user_id'], 'Comunicado: ' . $a['title'],
                        $a['summary'] ?: mb_substr($a['body'], 0, 160), 'index.php?m=rh&page=my&action=announcement&id=' . $id,
                        $a['type'] === 'urgente' ? 'warning' : 'info', 'rh');
                    $stats['notified']++;
                }
            }
            self::db()->prepare('UPDATE rh_announcements SET notified_at = NOW() WHERE id = ?')->execute([$id]);
        }

        if ((int)$a['send_email'] === 1 && empty($a['emailed_at'])) {
            $recipients = [];
            foreach ($audience as $r) {
                if ($r['real_email']) {
                    $recipients[] = ['email' => $r['real_email'], 'name' => $r['full_name']];
                }
            }
            if ($recipients) {
                $stats['queued'] = Core\MailQueue::enqueueMany($recipients, '[Comunicado] ' . $a['title'], self::emailHtml($a), 'rh', 'announcement', $id);
            }
            self::db()->prepare('UPDATE rh_announcements SET emailed_at = NOW() WHERE id = ?')->execute([$id]);
        }
        return $stats;
    }

    /** HTML do e-mail do comunicado. */
    public static function emailHtml(array $a): string
    {
        $org   = (string)(Core\Settings::get('org_name', core_config('app.name', 'Portal')) ?? 'Portal');
        $link  = core_url('index.php?m=rh&page=my&action=announcement&id=' . (int)$a['id']);
        $color = match ($a['type']) { 'urgente' => '#dc3545', 'celebracao' => '#198754', default => '#0d6efd' };
        $label = self::TYPES[$a['type']] ?? ucfirst((string)$a['type']);
        $body  = !empty($a['body_html']) ? $a['body_html'] : nl2br(Sanitize::e((string)$a['body']));
        $img   = !empty($a['image_path'])
            ? '<img src="' . Sanitize::e(Upload::publicUrl($a['image_path'])) . '" alt="" style="max-width:100%;border-radius:8px;margin:0 0 16px">' : '';
        $summary = !empty($a['summary']) ? '<p style="font-size:15px;color:#555;margin:0 0 16px"><em>' . Sanitize::e($a['summary']) . '</em></p>' : '';
        $attach  = !empty($a['attachment_name'])
            ? '<p style="margin:16px 0 0;font-size:13px;color:#555">📎 Anexo disponível no portal: ' . Sanitize::e($a['attachment_name']) . '</p>' : '';

        return '<!DOCTYPE html><html lang="pt-BR"><body style="margin:0;padding:24px;background:#f3f5f8;font-family:Arial,Helvetica,sans-serif;color:#222">'
            . '<div style="max-width:640px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.06)">'
            . '<div style="background:' . $color . ';color:#fff;padding:18px 24px">'
            . '<div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;opacity:.85">' . Sanitize::e($org) . ' · ' . Sanitize::e($label) . '</div>'
            . '<h1 style="margin:6px 0 0;font-size:22px">' . Sanitize::e((string)$a['title']) . '</h1></div>'
            . '<div style="padding:24px;font-size:15px;line-height:1.55">' . $img . $summary . $body . $attach
            . '<p style="margin:24px 0 0"><a href="' . Sanitize::e($link) . '" style="display:inline-block;background:' . $color . ';color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:bold">Abrir no portal do funcionário</a></p>'
            . '</div>'
            . '<div style="padding:12px 24px;background:#f8f9fa;color:#888;font-size:11px">E-mail automático do módulo RH — ' . Sanitize::e($org) . '. Não responda a esta mensagem.</div>'
            . '</div></body></html>';
    }
}
