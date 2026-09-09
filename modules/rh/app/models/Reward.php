<?php
/**
 * Reward — catálogo de brindes trocados por pontos (rh_employee_scores).
 */
class Reward extends Model
{
    protected static string $table = 'rh_rewards';
    protected static array $fillable = ['name', 'description', 'points_cost', 'stock', 'image_path', 'active', 'created_by'];

    /** Catálogo completo (RH) com contagem de resgates — 1 consulta. */
    public static function listAll(): array
    {
        return self::db()->query(
            "SELECT r.*,
                    (SELECT COUNT(*) FROM rh_reward_redemptions d WHERE d.reward_id = r.id AND d.status IN ('aprovada','entregue')) AS redeemed,
                    (SELECT COUNT(*) FROM rh_reward_redemptions d WHERE d.reward_id = r.id AND d.status = 'pendente') AS pending
             FROM rh_rewards r ORDER BY r.active DESC, r.points_cost ASC, r.name"
        )->fetchAll();
    }

    /** Catálogo do portal (ativos e com estoque). */
    public static function catalog(): array
    {
        return self::db()->query(
            'SELECT * FROM rh_rewards WHERE active = 1 AND (stock IS NULL OR stock > 0) ORDER BY points_cost ASC, name'
        )->fetchAll();
    }
}
