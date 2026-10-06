<?php
/**
 * Vaccine — catálogo de vacinas e o MOTOR DE STATUS por funcionário.
 *
 * O status de cada vacina para cada funcionário é CALCULADO a partir dos
 * registros (doses, dispensa, sorologia) e das regras do catálogo
 * (doses_total, booster_months, next_dose_days) — nunca digitado:
 *
 *   nao_aplica  dispensa registrada (contraindicação, imune, não se aplica)
 *               ou vacina que só vale para alguns (applies_to_all=0) sem registro;
 *   pendente    nenhuma dose registrada e a vacina vale para todos;
 *   incompleta  faltam doses do esquema (mostra quando a próxima vence);
 *   vencida     esquema completo mas o reforço passou (valid_until < hoje);
 *   vencendo    reforço vence em até ALERT_DAYS dias;
 *   em_dia      completa e válida (ou sem reforço previsto).
 *
 * Hepatite B: sorologia anti-HBs reagente = em_dia mesmo sem as 3 doses;
 * não reagente mantém a cobrança.
 */
class Vaccine extends Model
{
    protected static string $table = 'rh_vaccines';
    protected static array $fillable = ['key','name','category','target','doses_total','schedule','next_dose_days',
        'booster_months','validity_rule','serology','applies_to_all','notes','sources','active','sort_order'];

    public const ALERT_DAYS = 60;
    public const ALERT_DAYS_LONG = 180;

    public const CATEGORIES = [
        'obrigatoria' => 'Obrigatória (NR-32 / PNI)',
        'pni_rotina'  => 'Calendário nacional (PNI)',
        'recomendada' => 'Recomendada (SBIm / ocupacional)',
    ];

    public const STATUS_LABELS = [
        'em_dia'     => 'Em dia',
        'vencendo'   => 'Vencendo',
        'vencida'    => 'Vencida',
        'incompleta' => 'Esquema incompleto',
        'pendente'   => 'Pendente',
        'nao_aplica' => 'Não se aplica',
    ];

    public static function badge(string $status): string
    {
        return match ($status) {
            'em_dia'     => 'bg-success',
            'vencendo'   => 'bg-warning text-dark',
            'vencida'    => 'bg-danger',
            'incompleta' => 'bg-info text-dark',
            'pendente'   => 'bg-secondary',
            default      => 'bg-light text-muted border',
        };
    }

    public static function categoryBadge(string $cat): string
    {
        return match ($cat) {
            'obrigatoria' => 'bg-danger',
            'pni_rotina'  => 'bg-primary',
            default       => 'bg-light text-dark border',
        };
    }

    /** Catálogo (ativas por padrão), na ordem de exibição. */
    public static function catalog(bool $onlyActive = true): array
    {
        $sql = 'SELECT * FROM rh_vaccines' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, name';
        return self::db()->query($sql)->fetchAll();
    }

    /** Registros (doses/dispensa/sorologia) de um funcionário, por vacina. */
    public static function recordsOf(int $employeeId): array
    {
        $stmt = self::db()->prepare(
            'SELECT r.*, u.name AS created_by_name, v.name AS vaccine_name
             FROM rh_employee_vaccines r
             JOIN rh_vaccines v ON v.id = r.vaccine_id
             LEFT JOIN users u ON u.id = r.created_by
             WHERE r.employee_id = ?
             ORDER BY r.applied_at, r.id'
        );
        $stmt->execute([$employeeId]);
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[(int) $r['vaccine_id']][] = $r;
        }
        return $map;
    }

    /** Registros de vários funcionários de uma vez (painel/cron), agrupados. */
    public static function recordsOfMany(array $employeeIds): array
    {
        $employeeIds = array_values(array_filter(array_map('intval', $employeeIds)));
        if ($employeeIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($employeeIds), '?'));
        $stmt = self::db()->prepare(
            "SELECT * FROM rh_employee_vaccines WHERE employee_id IN ($in) ORDER BY applied_at, id"
        );
        $stmt->execute($employeeIds);
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[(int) $r['employee_id']][(int) $r['vaccine_id']][] = $r;
        }
        return $map;
    }

    /**
     * Validade de uma dose segundo o catálogo: só a dose que COMPLETA o
     * esquema (ou um reforço) ganha valid_until = aplicação + booster_months.
     * Doses intermediárias ganham o prazo da próxima dose (next_dose_days).
     * Devolve [valid_until, next_due].
     */
    public static function computeDates(array $vaccine, int $doseNumber, string $appliedAt): array
    {
        $ts = strtotime($appliedAt);
        if (!$ts) {
            return [null, null];
        }
        $total = max(1, (int) $vaccine['doses_total']);
        if ($doseNumber < $total) {
            $days = (int) ($vaccine['next_dose_days'] ?? 0);
            return [null, $days > 0 ? date('Y-m-d', strtotime("+{$days} days", $ts)) : null];
        }
        $months = (int) ($vaccine['booster_months'] ?? 0);
        return [$months > 0 ? date('Y-m-d', strtotime("+{$months} months", $ts)) : null, null];
    }

    /**
     * Avalia UMA vacina para um funcionário a partir dos registros dela.
     * Devolve um array com status, doses, última dose, validade, próxima
     * dose e os próprios registros.
     */
    public static function evaluate(array $vaccine, array $records): array
    {
        $hoje = date('Y-m-d');
        $limiteAlerta = date('Y-m-d', strtotime('+' . self::ALERT_DAYS . ' days'));
        $total = max(1, (int) $vaccine['doses_total']);

        $doses = []; $dispensa = null; $sorologia = null;
        foreach ($records as $r) {
            if ($r['kind'] === 'dose')           { $doses[] = $r; }
            elseif ($r['kind'] === 'dispensa')   { $dispensa = $r; }
            elseif ($r['kind'] === 'sorologia')  { $sorologia = $r; } // a mais recente vence (ordem por data)
        }
        $ultima = $doses ? $doses[count($doses) - 1] : null;

        $out = [
            'vaccine'    => $vaccine,
            'records'    => $records,
            'doses'      => count($doses),
            'doses_total'=> $total,
            'last'       => $ultima,
            'valid_until'=> $ultima['valid_until'] ?? null,
            'next_due'   => null,
            'status'     => 'pendente',
            'unverified' => count(array_filter($records, fn ($r) => (int) $r['verified'] === 0)),
            'serology'   => $sorologia,
        ];

        if ($dispensa) {
            $out['status'] = 'nao_aplica';
            return $out;
        }
        if ($sorologia && strtolower((string) $sorologia['result']) === 'reagente') {
            $out['status'] = 'em_dia';
            return $out;
        }
        if (!$doses) {
            $out['status'] = (int) $vaccine['applies_to_all'] === 1 ? 'pendente' : 'nao_aplica';
            return $out;
        }

        // Reforço/anual: cada dose recomeça a contagem — a última decide.
        $months = (int) ($vaccine['booster_months'] ?? 0);
        $completa = count($doses) >= $total || (int) ($ultima['dose_number'] ?? 0) >= $total;

        if (!$completa && $months === 0) {
            $out['status'] = 'incompleta';
            [, $next] = self::computeDates($vaccine, (int) ($ultima['dose_number'] ?: count($doses)), (string) $ultima['applied_at']);
            $out['next_due'] = $next;
            return $out;
        }
        if (!$completa && $months > 0 && count($doses) < $total) {
            // Esquema inicial de várias doses com reforço depois (ex.: dT):
            // ainda incompleto.
            $out['status'] = 'incompleta';
            [, $next] = self::computeDates($vaccine, count($doses), (string) $ultima['applied_at']);
            $out['next_due'] = $next;
            return $out;
        }

        $validUntil = $ultima['valid_until'] ?: null;
        if ($validUntil === null && $months > 0 && !empty($ultima['applied_at'])) {
            $validUntil = date('Y-m-d', strtotime("+{$months} months", (int) strtotime((string) $ultima['applied_at'])));
        }
        $out['valid_until'] = $validUntil;
        // Janela de "vencendo": 60 dias para vacinas anuais; 180 dias para
        // reforços longos (5 e 10 anos — meningo ACWY, dT/dTpa), como a
        // SBIm/PNI orientam, para dar tempo de agendar.
        $janela = $months >= 60 ? self::ALERT_DAYS_LONG : self::ALERT_DAYS;
        $limiteAlerta = date('Y-m-d', strtotime('+' . $janela . ' days'));
        $out['alert_days'] = $janela;
        if ($validUntil === null) {
            $out['status'] = 'em_dia';
        } elseif ($validUntil < $hoje) {
            $out['status'] = 'vencida';
        } elseif ($validUntil <= $limiteAlerta) {
            $out['status'] = 'vencendo';
        } else {
            $out['status'] = 'em_dia';
        }
        return $out;
    }

    /** Avalia TODO o catálogo para um funcionário. @return array<int, array> por vaccine_id */
    public static function evaluateEmployee(int $employeeId, ?array $catalog = null, ?array $records = null): array
    {
        $catalog ??= self::catalog();
        $records ??= self::recordsOf($employeeId);
        $out = [];
        foreach ($catalog as $v) {
            $out[(int) $v['id']] = self::evaluate($v, $records[(int) $v['id']] ?? []);
        }
        return $out;
    }

    /** Resumo (contagem por status) de uma avaliação. */
    public static function summarize(array $evaluation): array
    {
        $z = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        foreach ($evaluation as $e) {
            $z[$e['status']]++;
        }
        return $z;
    }

    /** Quantos funcionários ativos têm alguma vacina vencida/vencendo (dashboard). */
    public static function alertCounts(): array
    {
        $emps = self::db()->query("SELECT id FROM rh_employees WHERE status = 'ativo'")->fetchAll(PDO::FETCH_COLUMN);
        $catalog = self::catalog();
        $all = self::recordsOfMany($emps);
        $vencida = 0; $vencendo = 0; $pendente = 0;
        foreach ($emps as $eid) {
            $ev = self::evaluateEmployee((int) $eid, $catalog, $all[(int) $eid] ?? []);
            $s = self::summarize($ev);
            if ($s['vencida'] > 0)  { $vencida++; }
            if ($s['vencendo'] > 0) { $vencendo++; }
            if ($s['pendente'] > 0 || $s['incompleta'] > 0) { $pendente++; }
        }
        return ['vencida' => $vencida, 'vencendo' => $vencendo, 'pendente' => $pendente, 'funcionarios' => count($emps)];
    }
}
