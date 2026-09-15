<?php

declare(strict_types=1);

namespace Core;

/**
 * Notificações unificadas. Todos os módulos gravam e leem daqui
 * (tabela notifications, coluna module identifica a origem).
 */
final class Notifications
{
    public static function add(int $userId, string $title, ?string $message = null, ?string $link = null, ?string $type = null, ?string $module = null): void
    {
        DB::execute(
            'INSERT INTO notifications (user_id, module, type, title, message, link) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $module ?? (defined('MODULE_SLUG') ? MODULE_SLUG : null), $type, $title, $message, $link]
        );
    }

    public static function unreadCount(int $userId, ?string $module = null): int
    {
        $sql    = 'SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND read_at IS NULL';
        $params = [$userId];
        if ($module !== null) {
            $sql .= ' AND module = ?';
            $params[] = $module;
        }
        $row = DB::queryOne($sql, $params);
        return (int) ($row['n'] ?? 0);
    }

    /** @return array<int, array<string, mixed>> */
    public static function latest(int $userId, int $limit = 15, ?string $module = null): array
    {
        $sql    = 'SELECT * FROM notifications WHERE user_id = ?';
        $params = [$userId];
        if ($module !== null) {
            $sql .= ' AND module = ?';
            $params[] = $module;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit);
        return DB::query($sql, $params);
    }

    /**
     * Intervalo (segundos) entre consultas do sino, por estado da aba.
     * Configurável em Administração > Configurações; os limites impedem
     * tanto o "tempo real" que derruba o servidor quanto o atraso de
     * minutos que gerou esta mudança.
     */
    public static function pollSeconds(bool $visivel = true): int
    {
        $chave = $visivel ? 'notifications.poll_active' : 'notifications.poll_idle';
        $padrao = $visivel ? 5 : 20;
        $v = (int) Settings::get($chave, (string) $padrao);
        return $visivel ? max(2, min(120, $v)) : max(10, min(600, $v ?: $padrao));
    }

    /**
     * Alimenta o sino: contagem não lida + as notificações mais recentes.
     * Com $sinceId > 0 devolve em 'novas' só o que chegou depois daquele id —
     * é o que permite avisar na hora sem reprocessar a lista inteira.
     *
     * @return array{count:int, last_id:int, items:array, novas:array}
     */
    public static function feed(int $userId, int $sinceId = 0, int $limit = 10): array
    {
        $items = self::latest($userId, $limit);
        $maior = 0;
        foreach ($items as $i) {
            $maior = max($maior, (int) $i['id']);
        }
        $novas = [];
        if ($sinceId > 0) {
            foreach ($items as $i) {
                if ((int) $i['id'] > $sinceId) {
                    $novas[] = $i;
                }
            }
        }
        return [
            'count'   => self::unreadCount($userId),
            'last_id' => $maior,
            'items'   => array_map(self::publicRow(...), $items),
            'novas'   => array_map(self::publicRow(...), $novas),
        ];
    }

    /** Só os campos que a tela usa — a linha crua não vai para o navegador. */
    private static function publicRow(array $r): array
    {
        // A data vai em ISO-8601 COM o fuso. "2026-09-15 17:52:00" sem fuso é
        // interpretado pelo navegador como hora local dele: num navegador em
        // UTC, uma notificação recém-criada aparecia como "há 3 h".
        $ts = strtotime((string) $r['created_at']) ?: time();

        return [
            'id'         => (int) $r['id'],
            'title'      => (string) $r['title'],
            'message'    => (string) ($r['message'] ?? ''),
            'link'       => (string) ($r['link'] ?? ''),
            'module'     => (string) ($r['module'] ?? ''),
            'type'       => (string) ($r['type'] ?? ''),
            'read'       => !empty($r['read_at']),
            'created_at' => date('c', $ts),
        ];
    }

    public static function markRead(int $userId, ?int $id = null): void
    {
        if ($id === null) {
            DB::execute('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$userId]);
        } else {
            DB::execute('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND id = ?', [$userId, $id]);
        }
    }
}
