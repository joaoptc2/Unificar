<?php
/**
 * Complaint — canal de denúncia ANÔNIMO.
 *
 * O que garante o anonimato é a ESTRUTURA: a tabela não tem user_id,
 * employee_id nem IP, e nada aqui chama AuditLog (que gravaria usuário e
 * endereço). O denunciante recebe um protocolo e uma chave — só o sha256 da
 * chave é gravado — e acompanha a apuração por eles, logado ou não.
 */
class Complaint extends Model
{
    protected static string $table = 'rh_complaints';
    protected static array $fillable = ['status', 'response', 'internal_notes', 'handled_by', 'responded_at', 'closed_at'];

    public const CATEGORIES = [
        'assedio_moral'  => 'Assédio moral',
        'assedio_sexual' => 'Assédio sexual',
        'discriminacao'  => 'Discriminação',
        'fraude'         => 'Fraude / desvio / corrupção',
        'seguranca'      => 'Segurança do paciente / do trabalho',
        'conduta'        => 'Conduta antiética / conflito de interesses',
        'outro'          => 'Outro',
    ];

    public const STATUS_LABELS = [
        'nova'        => 'Nova',
        'em_apuracao' => 'Em apuração',
        'concluida'   => 'Concluída',
        'arquivada'   => 'Arquivada',
    ];

    /** Classe do selo por status. */
    public static function badge(string $status): string
    {
        return match ($status) {
            'em_apuracao' => 'bg-info text-dark',
            'concluida'   => 'bg-success',
            'arquivada'   => 'bg-secondary',
            default       => 'bg-warning text-dark',
        };
    }

    /**
     * Abre uma denúncia. Devolve [id, protocolo, chave] — a chave aparece
     * UMA vez para o denunciante e nunca mais pode ser recuperada.
     */
    public static function open(array $data): array
    {
        $db = self::db();
        $key = bin2hex(random_bytes(16)); // 32 hex
        // Protocolo legível: DEN-AAAA-XXXXXX (sem 0/O/1/I para ditar por telefone).
        $alfabeto = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        for ($tentativa = 0; $tentativa < 5; $tentativa++) {
            $suf = '';
            for ($i = 0; $i < 6; $i++) {
                $suf .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
            $protocol = 'DEN-' . date('Y') . '-' . $suf;
            $stmt = $db->prepare('SELECT 1 FROM rh_complaints WHERE protocol = ?');
            $stmt->execute([$protocol]);
            if (!$stmt->fetchColumn()) {
                break;
            }
        }
        // created_at truncado à hora: dificulta cruzar com logs de acesso.
        $stmt = $db->prepare(
            'INSERT INTO rh_complaints (protocol, key_hash, category, subject, body, involved, occurred_at, location,
                    contact, attachment_path, attachment_name, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'nova\', DATE_FORMAT(NOW(), \'%Y-%m-%d %H:00:00\'))'
        );
        $stmt->execute([
            $protocol, hash('sha256', $key), $data['category'], $data['subject'], $data['body'],
            $data['involved'] ?: null, $data['occurred_at'] ?: null, $data['location'] ?: null,
            $data['contact'] ?: null, $data['attachment_path'] ?: null, $data['attachment_name'] ?: null,
        ]);
        return [(int) $db->lastInsertId(), $protocol, $key];
    }

    /**
     * Localiza pelo protocolo E pela chave (igualdade exata + formato), ou
     * null. hash_equals evita comparação em tempo variável.
     */
    public static function findByCredentials(string $protocol, string $key): ?array
    {
        $protocol = strtoupper(trim($protocol));
        $key = strtolower(trim($key));
        if (!preg_match('/^DEN-\d{4}-[A-Z0-9]{6}$/', $protocol) || !preg_match('/^[0-9a-f]{32}$/', $key)) {
            return null;
        }
        $stmt = self::db()->prepare('SELECT * FROM rh_complaints WHERE protocol = ? LIMIT 1');
        $stmt->execute([$protocol]);
        $row = $stmt->fetch();
        if (!$row || !hash_equals((string) $row['key_hash'], hash('sha256', $key))) {
            return null;
        }
        return $row;
    }

    /** Lista para a comissão, com filtros. */
    public static function listing(string $status = '', string $category = ''): array
    {
        $conds = []; $params = [];
        if ($status !== '')   { $conds[] = 'c.status = ?';   $params[] = $status; }
        if ($category !== '') { $conds[] = 'c.category = ?'; $params[] = $category; }
        $wc = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
        $stmt = self::db()->prepare(
            "SELECT c.*, u.name AS handled_by_name,
                    (SELECT COUNT(*) FROM rh_complaint_messages m WHERE m.complaint_id = c.id) AS messages,
                    (SELECT COUNT(*) FROM rh_complaint_messages m WHERE m.complaint_id = c.id AND m.author = 'denunciante'
                        AND m.created_at > COALESCE(c.responded_at, c.created_at)) AS unread_from_reporter
             FROM rh_complaints c
             LEFT JOIN users u ON u.id = c.handled_by
             $wc
             ORDER BY FIELD(c.status,'nova','em_apuracao','concluida','arquivada'), c.created_at DESC
             LIMIT 300"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function openCount(): int
    {
        return self::count("status IN ('nova','em_apuracao')");
    }

    /** Mensagens da conversa pelo protocolo (mais antigas primeiro). */
    public static function messages(int $id): array
    {
        $stmt = self::db()->prepare(
            'SELECT m.*, u.name AS user_name FROM rh_complaint_messages m
             LEFT JOIN users u ON u.id = m.user_id
             WHERE m.complaint_id = ? ORDER BY m.created_at, m.id'
        );
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }

    /** Mensagem da comissão (identificada) ou do denunciante (anônima). */
    public static function addMessage(int $id, string $author, string $body, ?int $userId = null): void
    {
        self::db()->prepare(
            'INSERT INTO rh_complaint_messages (complaint_id, author, user_id, body) VALUES (?, ?, ?, ?)'
        )->execute([$id, $author, $author === 'comissao' ? $userId : null, $body]);
        if ($author === 'comissao') {
            self::db()->prepare('UPDATE rh_complaints SET responded_at = NOW() WHERE id = ?')->execute([$id]);
        }
    }

    /** Avisa a comissão (complaints.view) de denúncia nova — sem nada identificável. */
    public static function notifyCommittee(int $id, string $protocol, string $category): void
    {
        $label = self::CATEGORIES[$category] ?? 'Outro';
        foreach (Core\Perms::usersWith('rh', 'complaints.view') as $uid) {
            Core\Notifications::add(
                (int) $uid,
                'Nova denúncia ' . $protocol,
                'Categoria: ' . $label . '. Abra para apurar.',
                'index.php?m=rh&page=complaints&action=show&id=' . $id,
                'warning',
                'rh'
            );
        }
    }
}
