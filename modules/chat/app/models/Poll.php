<?php
class Poll extends Model
{
    protected static string $table = 'chat_polls';
    protected static array  $fillable = [
        'channel_id', 'message_id', 'user_id', 'question',
        'is_anonymous', 'is_multiple', 'closes_at', 'is_closed',
    ];

    /**
     * Find a poll by ID and attach its options with vote counts and voter names.
     */
    public static function withOptions(int $id): ?array
    {
        $poll = self::find($id);
        if (!$poll) {
            return null;
        }

        $db = Database::getInstance();

        $stmt = $db->prepare(
            'SELECT po.id, po.text, po.order_num,
                    COUNT(pv.id) AS vote_count
             FROM chat_poll_options po
             LEFT JOIN chat_poll_votes pv ON pv.option_id = po.id
             WHERE po.poll_id = ?
             GROUP BY po.id, po.text, po.order_num
             ORDER BY po.order_num ASC'
        );
        $stmt->execute([$id]);
        $options = $stmt->fetchAll();

        // Attach voter names per option (only if not anonymous)
        if (!(int) $poll['is_anonymous']) {
            $nameStmt = $db->prepare(
                'SELECT GROUP_CONCAT(u.name SEPARATOR ", ") AS voter_names
                 FROM chat_poll_votes pv
                 INNER JOIN users u ON u.id = pv.user_id
                 WHERE pv.option_id = ?'
            );
            foreach ($options as &$opt) {
                $nameStmt->execute([$opt['id']]);
                $row = $nameStmt->fetch();
                $opt['voter_names'] = $row['voter_names'] ?? '';
            }
            unset($opt);
        } else {
            foreach ($options as &$opt) {
                $opt['voter_names'] = '';
            }
            unset($opt);
        }

        $poll['options'] = $options;
        return $poll;
    }

    /**
     * Add an option to a poll.
     */
    public static function addOption(int $pollId, string $text, int $order): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO chat_poll_options (poll_id, text, order_num) VALUES (?, ?, ?)'
        );
        $stmt->execute([$pollId, $text, $order]);
        return (int) $db->lastInsertId();
    }

    /**
     * Cast a vote. For single-choice polls, remove any existing vote first.
     */
    public static function vote(int $pollId, int $optionId, int $userId): bool
    {
        $db   = Database::getInstance();
        $poll = self::find($pollId);
        if (!$poll) {
            return false;
        }

        // Single-choice: remove any previous vote by this user on this poll
        if (!(int) $poll['is_multiple']) {
            $db->prepare(
                'DELETE pv FROM chat_poll_votes pv
                 INNER JOIN chat_poll_options po ON po.id = pv.option_id
                 WHERE po.poll_id = ? AND pv.user_id = ?'
            )->execute([$pollId, $userId]);
        }

        $stmt = $db->prepare(
            'INSERT IGNORE INTO chat_poll_votes (option_id, user_id, created_at) VALUES (?, ?, NOW())'
        );
        return $stmt->execute([$optionId, $userId]);
    }

    /**
     * Remove a specific vote.
     */
    public static function removeVote(int $pollId, int $optionId, int $userId): void
    {
        $db = Database::getInstance();
        $db->prepare(
            'DELETE pv FROM chat_poll_votes pv
             INNER JOIN chat_poll_options po ON po.id = pv.option_id
             WHERE po.poll_id = ? AND pv.option_id = ? AND pv.user_id = ?'
        )->execute([$pollId, $optionId, $userId]);
    }

    /**
     * Check whether a user has voted on a poll.
     */
    public static function hasVoted(int $pollId, int $userId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT 1 FROM chat_poll_votes pv
             INNER JOIN chat_poll_options po ON po.id = pv.option_id
             WHERE po.poll_id = ? AND pv.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$pollId, $userId]);
        return (bool) $stmt->fetch();
    }

    /**
     * Return full results for a poll: options with vote counts, percentages, and voter names.
     */
    public static function results(int $pollId): array
    {
        $db   = Database::getInstance();
        $poll = self::find($pollId);

        $stmt = $db->prepare(
            'SELECT po.id, po.text,
                    COUNT(pv.id) AS vote_count,
                    GROUP_CONCAT(u.name SEPARATOR ", ") AS voter_names
             FROM chat_poll_options po
             LEFT JOIN chat_poll_votes pv ON pv.option_id = po.id
             LEFT JOIN users u ON u.id = pv.user_id
             WHERE po.poll_id = ?
             GROUP BY po.id, po.text, po.order_num
             ORDER BY po.order_num ASC'
        );
        $stmt->execute([$pollId]);
        $options = $stmt->fetchAll();

        $totalVotes = 0;
        foreach ($options as $opt) {
            $totalVotes += (int) $opt['vote_count'];
        }

        // If poll is anonymous, strip voter names
        $isAnonymous = $poll && (int) $poll['is_anonymous'];

        foreach ($options as &$opt) {
            $opt['vote_count']  = (int) $opt['vote_count'];
            $opt['percentage']  = $totalVotes > 0
                ? round(($opt['vote_count'] / $totalVotes) * 100, 1)
                : 0;
            if ($isAnonymous) {
                $opt['voter_names'] = '';
            }
        }
        unset($opt);

        return [
            'options'     => $options,
            'total_votes' => $totalVotes,
        ];
    }

    /**
     * Close a poll.
     */
    public static function closePoll(int $id): void
    {
        $db = Database::getInstance();
        $db->prepare('UPDATE chat_polls SET is_closed = 1 WHERE id = ?')->execute([$id]);
    }

    /**
     * Recent polls in a channel.
     */
    public static function byChannel(int $channelId, int $limit = 10): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT p.*, u.name AS creator_name,
                    (SELECT COUNT(*) FROM chat_poll_votes pv
                     INNER JOIN chat_poll_options po ON po.id = pv.option_id
                     WHERE po.poll_id = p.id) AS total_votes
             FROM chat_polls p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE p.channel_id = ?
             ORDER BY p.created_at DESC
             LIMIT ?'
        );
        $stmt->execute([$channelId, $limit]);
        return $stmt->fetchAll();
    }
}
