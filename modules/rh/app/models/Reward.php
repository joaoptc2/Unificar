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

    /**
     * Catálogo do portal: ativos e com estoque DISPONÍVEL (estoque físico
     * menos resgates pendentes, que já reservam uma unidade). Traz
     * `available_stock` (NULL = ilimitado).
     */
    public static function catalog(): array
    {
        return self::db()->query(
            "SELECT r.*,
                    (SELECT COUNT(*) FROM rh_reward_redemptions d WHERE d.reward_id = r.id AND d.status = 'pendente') AS pending,
                    CASE WHEN r.stock IS NULL THEN NULL ELSE GREATEST(0, r.stock - (SELECT COUNT(*) FROM rh_reward_redemptions d2 WHERE d2.reward_id = r.id AND d2.status = 'pendente')) END AS available_stock
             FROM rh_rewards r
             WHERE r.active = 1
             HAVING available_stock IS NULL OR available_stock > 0
             ORDER BY r.points_cost ASC, r.name"
        )->fetchAll();
    }

    /** Estoque disponível para novos resgates (estoque − pendentes); null = ilimitado. */
    public static function availableStock(int $rewardId): ?int
    {
        $row = self::db()->prepare(
            "SELECT r.stock, (SELECT COUNT(*) FROM rh_reward_redemptions d WHERE d.reward_id = r.id AND d.status = 'pendente') AS pending
             FROM rh_rewards r WHERE r.id = ?"
        );
        $row->execute([$rewardId]);
        $r = $row->fetch();
        if (!$r || $r['stock'] === null) return null;
        return max(0, (int)$r['stock'] - (int)$r['pending']);
    }
}
