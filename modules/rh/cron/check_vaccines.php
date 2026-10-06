<?php
/**
 * CRON: controle de vencimento das VACINAS (módulo RH).
 *
 * Para cada funcionário ativo, avalia o catálogo (Vaccine::evaluateEmployee)
 * e, para cada vacina VENCENDO (≤ Vaccine::ALERT_DAYS) ou VENCIDA:
 *   • avisa o RH (vaccines.edit) e o próprio funcionário — uma vez por dose
 *     (alert_notified_at / expired_notified_at na dose que define a validade);
 *   • abre automaticamente a solicitação de RENOVAÇÃO, se não houver uma em
 *     aberto (requested_by NULL = automática), que o funcionário vê na
 *     Minha Área e responde com o comprovante.
 * Vacinas pendentes/incompletas NÃO geram aviso automático (seria ruído na
 * primeira carga): o RH usa "Solicitar regularização" na ficha ou no painel.
 */

if (!defined('CRON_AUTORIZADO')) {
    http_response_code(404);
    exit;
}
echo "[" . date('Y-m-d H:i:s') . "] [rh] Verificando vacinas...\n";

try {
    $db = Database::getInstance();
    $emps = $db->query("SELECT e.id, e.full_name, p.user_id FROM rh_employees e LEFT JOIN rh_user_profile p ON p.employee_id = e.id WHERE e.status = 'ativo'")->fetchAll();
    $catalog = Vaccine::catalog();
    $records = Vaccine::recordsOfMany(array_column($emps, 'id'));
    $rhUsers = Core\Perms::usersWith('rh', 'vaccines.edit');

    $avisos = 0; $abertas = 0;
    foreach ($emps as $e) {
        $eid = (int) $e['id'];
        $ev = Vaccine::evaluateEmployee($eid, $catalog, $records[$eid] ?? []);
        foreach ($ev as $vid => $x) {
            if (!in_array($x['status'], ['vencendo', 'vencida'], true) || !$x['last']) {
                continue;
            }
            $dose = $x['last'];
            $col  = $x['status'] === 'vencida' ? 'expired_notified_at' : 'alert_notified_at';
            if (!empty($dose[$col])) {
                continue; // já avisado para esta dose
            }
            $nome = $x['vaccine']['name'];
            $quando = $x['valid_until'] ? Sanitize::formatDate($x['valid_until']) : '';
            $titulo = ($x['status'] === 'vencida' ? 'Vacina vencida: ' : 'Vacina vencendo: ') . $nome;
            $msg = $e['full_name'] . ' — ' . $nome . ($x['status'] === 'vencida' ? ' venceu em ' : ' vence em ') . $quando . '.';
            foreach ($rhUsers as $uid) {
                Core\Notifications::add((int) $uid, $titulo, $msg, 'index.php?m=rh&page=employees&action=show&id=' . $eid . '#tabVacinas',
                    $x['status'] === 'vencida' ? 'warning' : 'info', 'rh');
                $avisos++;
            }
            if (!empty($e['user_id'])) {
                Core\Notifications::add((int) $e['user_id'], $titulo, 'Sua vacina ' . $nome . ($x['status'] === 'vencida' ? ' venceu em ' : ' vence em ') . $quando . '. Envie o comprovante da renovação pela Minha Área.',
                    'index.php?m=rh&page=my&tab=vacinas', 'warning', 'rh');
            }
            $db->prepare("UPDATE rh_employee_vaccines SET {$col} = NOW() WHERE id = ?")->execute([(int) $dose['id']]);

            if (!VaccineRequest::open($eid, (int) $vid)) {
                VaccineRequest::request($eid, (int) $vid, 'renovacao', null, 'Renovação automática por vencimento.', $x['valid_until'] ?? null);
                $abertas++;
            }
        }
    }
    echo "Avisos gerados: {$avisos}. Solicitações de renovação abertas: {$abertas}.\n";
} catch (Throwable $e) {
    echo "ERRO em check_vaccines: " . $e->getMessage() . "\n";
    error_log('[rh/check_vaccines] ' . $e->getMessage());
}
