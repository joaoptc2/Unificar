<?php
/**
 * Expiration — model para a tabela `expirations`.
 */
class Expiration extends Model
{
    protected static string $table = 'expirations';

    protected static array $fillable = [
        'employee_id', 'type', 'title', 'description',
        'issue_date', 'expiry_date', 'alert_days',
        'file_path', 'created_by',
    ];

    public static function upcoming(int $days = 30, int $limit = 10): array
    {
        $stmt = self::db()->prepare(
            "SELECT ex.*, e.full_name AS employee_name
             FROM expirations ex
             JOIN employees e ON ex.employee_id = e.id
             WHERE e.status = 'ativo'
               AND ex.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
             ORDER BY ex.expiry_date ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $days, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function expiredCount(): int
    {
        return (int)self::db()->query(
            "SELECT COUNT(*) FROM expirations ex
             JOIN employees e ON ex.employee_id = e.id
             WHERE ex.expiry_date < CURDATE() AND e.status = 'ativo'"
        )->fetchColumn();
    }

    /**
     * Distribuição por tipo (para gráfico do dashboard).
     */
    public static function countByType(): array
    {
        return self::db()->query(
            "SELECT type, COUNT(*) AS total
             FROM expirations
             GROUP BY type
             ORDER BY total DESC"
        )->fetchAll();
    }

    /**
     * Vencimentos por mês nos próximos N meses (para gráfico de linha).
     */
    public static function upcomingByMonth(int $months = 6): array
    {
        $stmt = self::db()->prepare(
            "SELECT DATE_FORMAT(ex.expiry_date, '%Y-%m') AS ym,
                    COUNT(*) AS total
             FROM expirations ex
             JOIN employees e ON ex.employee_id = e.id
             WHERE e.status = 'ativo'
               AND ex.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? MONTH)
             GROUP BY ym
             ORDER BY ym ASC"
        );
        $stmt->bindValue(1, $months, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
