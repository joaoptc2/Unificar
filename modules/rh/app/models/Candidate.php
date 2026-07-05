<?php
/**
 * Candidate — model para a tabela `candidates`.
 */
class Candidate extends Model
{
    protected static string $table = 'rh_candidates';

    protected static array $fillable = [
        'job_id', 'full_name', 'email', 'phone', 'cpf', 'area',
        'experience', 'resume_path', 'current_step_id', 'status',
        'notes', 'access_token', 'in_talent_pool',
    ];

    public static function countByStatus(): array
    {
        return self::db()->query(
            "SELECT status, COUNT(*) AS total
             FROM rh_candidates GROUP BY status ORDER BY total DESC"
        )->fetchAll();
    }
}
