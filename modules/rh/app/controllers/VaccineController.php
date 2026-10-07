<?php
/**
 * VaccineController — controle de vacinação dos funcionários.
 *
 *   page=vaccines                              painel: situação de todos (vaccines.view)
 *   page=vaccines&action=requests              solicitações de renovação (vaccines.view)
 *   page=vaccines&action=store_record          POST registra dose/dispensa/sorologia (vaccines.edit)
 *   page=vaccines&action=verify_record         POST valida comprovante enviado pelo funcionário (vaccines.edit)
 *   page=vaccines&action=delete_record         POST exclui registro (vaccines.delete)
 *   page=vaccines&action=request               POST solicita renovação/regularização (vaccines.edit)
 *   page=vaccines&action=request_all           POST solicita para todos os vencidos/vencendo (vaccines.edit)
 *   page=vaccines&action=cancel_request        POST (vaccines.edit)
 *   page=vaccines&action=my_submit             POST do portal: funcionário envia comprovante (my.view)
 *   Administração central (aba "Vacinas"): catalog / catalog_save / catalog_toggle / catalog_delete (vaccines.config)
 */
class VaccineController
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    // ------------------------------------------------------------------ painel
    public function index(): void
    {
        core_require('vaccines.view');
        $fStatus = Sanitize::get('status');
        $fVac    = Sanitize::int($_GET['vaccine_id'] ?? 0);
        $fDept   = Sanitize::int($_GET['department_id'] ?? 0);

        $sql = "SELECT e.id, e.full_name, e.department_id, d.name AS department_name
                FROM rh_employees e LEFT JOIN rh_departments d ON d.id = e.department_id
                WHERE e.status = 'ativo'" . ($fDept ? ' AND e.department_id = ' . (int) $fDept : '') . ' ORDER BY e.full_name';
        $employees = $this->db->query($sql)->fetchAll();
        $catalog   = Vaccine::catalog();
        $records   = Vaccine::recordsOfMany(array_column($employees, 'id'));

        $rows = []; $totais = array_fill_keys(array_keys(Vaccine::STATUS_LABELS), 0);
        foreach ($employees as $e) {
            $ev = Vaccine::evaluateEmployee((int) $e['id'], $catalog, $records[(int) $e['id']] ?? []);
            $resumo = Vaccine::summarize($ev);
            foreach ($resumo as $k => $n) { $totais[$k] += $n; }
            // Filtro por vacina/status: mantém a linha só se bate.
            if ($fVac && isset($ev[$fVac])) {
                if ($fStatus !== '' && $ev[$fVac]['status'] !== $fStatus) { continue; }
            } elseif ($fStatus !== '' && ($resumo[$fStatus] ?? 0) === 0) {
                continue;
            }
            $rows[] = ['employee' => $e, 'eval' => $ev, 'resumo' => $resumo];
        }

        View::render('vaccines/index', [
            'pageTitle'   => 'Vacinas',
            'page'        => 'vaccines',
            'rows'        => $rows,
            'catalog'     => $catalog,
            'totais'      => $totais,
            'departments' => $this->db->query('SELECT id, name FROM rh_departments WHERE active = 1 ORDER BY name')->fetchAll(),
            'fStatus'     => $fStatus, 'fVac' => $fVac, 'fDept' => $fDept,
            'pendingValidation' => VaccineRequest::pendingValidationCount(),
        ]);
    }

    public function requests(): void
    {
        core_require('vaccines.view');
        $status = Sanitize::get('status');
        View::render('vaccines/requests', [
            'pageTitle' => 'Solicitações de vacina',
            'page'      => 'vaccines',
            'items'     => VaccineRequest::listing($status),
            'status'    => $status,
        ]);
    }

    // --------------------------------------------------------------- registros
    public function store_record(): void
    {
        core_require('vaccines.edit'); Csrf::check();
        $employeeId = Sanitize::int($_POST['employee_id'] ?? 0);
        $vaccineId  = Sanitize::int($_POST['vaccine_id'] ?? 0);
        $back = 'index.php?m=rh&page=employees&action=show&id=' . $employeeId . '#tabVacinas';
        $vaccine = Vaccine::find($vaccineId);
        if (!$employeeId || !$vaccine) {
            Session::flash('error', 'Vacina ou funcionário inválido.');
            header('Location: ' . $back); exit;
        }
        $kind = in_array($_POST['kind'] ?? '', ['dose', 'dispensa', 'sorologia'], true) ? (string) $_POST['kind'] : 'dose';
        $applied = Sanitize::date($_POST['applied_at'] ?? null);
        if ($kind !== 'dispensa' && !$applied) {
            Session::flash('error', 'Informe a data.');
            header('Location: ' . $back); exit;
        }
        $doseNumber = max(0, min(20, Sanitize::int($_POST['dose_number'] ?? 0)));
        if ($kind === 'dose' && $doseNumber === 0) {
            // Próxima dose: conta as já registradas (reforço/anual continua contando).
            $st = $this->db->prepare("SELECT COUNT(*) FROM rh_employee_vaccines WHERE employee_id = ? AND vaccine_id = ? AND kind = 'dose'");
            $st->execute([$employeeId, $vaccineId]);
            $doseNumber = (int) $st->fetchColumn() + 1;
        }
        [$validUntil] = $kind === 'dose' ? Vaccine::computeDates($vaccine, $doseNumber, (string) $applied) : [null, null];
        $manual = Sanitize::date($_POST['valid_until'] ?? null);
        if ($manual) { $validUntil = $manual; }

        $filePath = null; $fileName = null;
        if (!empty($_FILES['file']['name'])) {
            $up = Upload::handle('file', 'vaccines');
            if (!$up['success']) {
                Session::flash('error', 'Comprovante recusado: ' . $up['error']);
                header('Location: ' . $back); exit;
            }
            $filePath = $up['path']; $fileName = $up['original_name'];
        }
        $this->db->prepare(
            'INSERT INTO rh_employee_vaccines (employee_id, vaccine_id, kind, dose_number, dose_label, applied_at, valid_until, batch,
                    manufacturer, result, reason, file_path, file_original_name, notes, verified, verified_by, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)'
        )->execute([
            $employeeId, $vaccineId, $kind,
            $kind === 'dose' ? $doseNumber : null,
            mb_substr(trim((string) ($_POST['dose_label'] ?? '')), 0, 40) ?: null,
            $applied ?: null, $validUntil,
            mb_substr(trim((string) ($_POST['batch'] ?? '')), 0, 60) ?: null,
            mb_substr(trim((string) ($_POST['manufacturer'] ?? '')), 0, 80) ?: null,
            $kind === 'sorologia' ? (in_array($_POST['result'] ?? '', ['reagente', 'nao_reagente'], true) ? (string) $_POST['result'] : 'reagente') : null,
            $kind === 'dispensa' ? (mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 200) ?: 'Dispensa') : null,
            $filePath, $fileName,
            mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 2000) ?: null,
            Session::userId(), Session::userId(),
        ]);
        $recId = (int) $this->db->lastInsertId();
        AuditLog::log('create', 'employee_vaccines', $recId, null, ['employee_id' => $employeeId, 'vaccine_id' => $vaccineId, 'kind' => $kind]);
        $this->closeRequestsIfResolved($employeeId, $vaccineId, $recId);
        Session::flash('success', 'Registro de vacina salvo.');
        header('Location: ' . $back); exit;
    }

    /** Valida um comprovante enviado pelo funcionário (verified=0 → 1), ajustando data/validade se preciso. */
    public function verify_record(): void
    {
        core_require('vaccines.edit'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $st = $this->db->prepare('SELECT r.*, v.doses_total, v.booster_months, v.next_dose_days FROM rh_employee_vaccines r JOIN rh_vaccines v ON v.id = r.vaccine_id WHERE r.id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) { Session::flash('error', 'Registro não encontrado.'); header('Location: index.php?m=rh&page=vaccines'); exit; }
        $back = 'index.php?m=rh&page=employees&action=show&id=' . (int) $r['employee_id'] . '#tabVacinas';
        if (!empty($_POST['reject'])) {
            $this->db->prepare('DELETE FROM rh_employee_vaccines WHERE id = ?')->execute([$id]);
            $this->db->prepare("UPDATE rh_vaccine_requests SET status = 'aberta', employee_vaccine_id = NULL WHERE employee_vaccine_id = ?")->execute([$id]);
            AuditLog::log('reject', 'employee_vaccines', $id);
            Session::flash('success', 'Comprovante recusado; a solicitação voltou a aguardar o funcionário.');
            header('Location: ' . $back); exit;
        }
        $applied = Sanitize::date($_POST['applied_at'] ?? null) ?: $r['applied_at'];
        $dose = max(1, Sanitize::int($_POST['dose_number'] ?? 0) ?: (int) ($r['dose_number'] ?: 1));
        [$validUntil] = Vaccine::computeDates($r, $dose, (string) $applied);
        $manual = Sanitize::date($_POST['valid_until'] ?? null);
        if ($manual) { $validUntil = $manual; }
        $this->db->prepare('UPDATE rh_employee_vaccines SET applied_at = ?, dose_number = ?, valid_until = ?, verified = 1, verified_by = ? WHERE id = ?')
                 ->execute([$applied, $dose, $validUntil, Session::userId(), $id]);
        AuditLog::log('verify', 'employee_vaccines', $id);
        $this->closeRequestsIfResolved((int) $r['employee_id'], (int) $r['vaccine_id'], $id);
        Session::flash('success', 'Comprovante validado.');
        header('Location: ' . $back); exit;
    }

    public function delete_record(): void
    {
        core_require('vaccines.delete'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $st = $this->db->prepare('SELECT employee_id, file_path FROM rh_employee_vaccines WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if ($r) {
            if (!empty($r['file_path'])) { Upload::delete($r['file_path']); }
            $this->db->prepare('DELETE FROM rh_employee_vaccines WHERE id = ?')->execute([$id]);
            AuditLog::log('delete', 'employee_vaccines', $id);
            Session::flash('success', 'Registro excluído.');
        }
        header('Location: index.php?m=rh&page=employees&action=show&id=' . (int) ($r['employee_id'] ?? 0) . '#tabVacinas'); exit;
    }

    // ------------------------------------------------------------ solicitações
    public function request(): void
    {
        core_require('vaccines.edit'); Csrf::check();
        $employeeId = Sanitize::int($_POST['employee_id'] ?? 0);
        $vaccineId  = Sanitize::int($_POST['vaccine_id'] ?? 0);
        $kind = ($_POST['kind'] ?? '') === 'pendente' ? 'pendente' : 'renovacao';
        $back = 'index.php?m=rh&page=employees&action=show&id=' . $employeeId . '#tabVacinas';
        if (!$employeeId || !Vaccine::find($vaccineId)) {
            Session::flash('error', 'Vacina ou funcionário inválido.');
            header('Location: ' . $back); exit;
        }
        $id = VaccineRequest::request($employeeId, $vaccineId, $kind, Session::userId(),
            mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 2000), Sanitize::date($_POST['due_date'] ?? null));
        AuditLog::log('request', 'vaccine_requests', $id);
        Session::flash('success', 'Solicitação enviada ao funcionário.');
        header('Location: ' . $back); exit;
    }

    /** Solicita de uma vez para todas as vacinas vencidas/vencendo/pendentes (de um funcionário ou de todos). */
    public function request_all(): void
    {
        core_require('vaccines.edit'); Csrf::check();
        $employeeId = Sanitize::int($_POST['employee_id'] ?? 0);
        $incluirPendentes = !empty($_POST['include_pending']);
        $ids = $employeeId ? [$employeeId] : $this->db->query("SELECT id FROM rh_employees WHERE status = 'ativo'")->fetchAll(PDO::FETCH_COLUMN);
        $catalog = Vaccine::catalog();
        $records = Vaccine::recordsOfMany($ids);
        $n = 0;
        foreach ($ids as $eid) {
            $ev = Vaccine::evaluateEmployee((int) $eid, $catalog, $records[(int) $eid] ?? []);
            foreach ($ev as $vid => $e) {
                $alvo = in_array($e['status'], ['vencida', 'vencendo'], true) || ($incluirPendentes && in_array($e['status'], ['pendente', 'incompleta'], true));
                if (!$alvo || VaccineRequest::open((int) $eid, (int) $vid)) { continue; }
                VaccineRequest::request((int) $eid, (int) $vid, in_array($e['status'], ['vencida', 'vencendo'], true) ? 'renovacao' : 'pendente',
                    Session::userId(), null, $e['valid_until'] ?? null);
                $n++;
            }
        }
        AuditLog::log('request_all', 'vaccine_requests', null, null, ['count' => $n, 'employee_id' => $employeeId]);
        Session::flash('success', $n . ' solicitação(ões) enviada(s).');
        header('Location: ' . ($employeeId ? 'index.php?m=rh&page=employees&action=show&id=' . $employeeId . '#tabVacinas' : 'index.php?m=rh&page=vaccines&action=requests')); exit;
    }

    public function cancel_request(): void
    {
        core_require('vaccines.edit'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $this->db->prepare("UPDATE rh_vaccine_requests SET status = 'cancelada', closed_by = ?, closed_at = NOW() WHERE id = ? AND status IN ('aberta','enviada')")
                 ->execute([Session::userId(), $id]);
        AuditLog::log('cancel', 'vaccine_requests', $id);
        Session::flash('success', 'Solicitação cancelada.');
        // Volta para a ficha quando veio dela (employee_id no POST); nunca
        // para o Referer, que o cliente controla (open redirect).
        $emp = Sanitize::int($_POST['employee_id'] ?? 0);
        header('Location: ' . ($emp ? 'index.php?m=rh&page=employees&action=show&id=' . $emp . '#tabVacinas' : 'index.php?m=rh&page=vaccines&action=requests')); exit;
    }

    /** Portal: o funcionário envia o comprovante (vira dose NÃO verificada e a solicitação passa a 'enviada'). */
    public function my_submit(): void
    {
        core_require('my.view'); Csrf::check();
        $employeeId = EmployeeAccess::employeeIdOf(Session::userId());
        $back = 'index.php?m=rh&page=my&tab=vacinas';
        if (!$employeeId) { header('Location: ' . $back); exit; }
        $vaccineId = Sanitize::int($_POST['vaccine_id'] ?? 0);
        $vaccine = Vaccine::find($vaccineId);
        $applied = Sanitize::date($_POST['applied_at'] ?? null);
        if (!$vaccine || !$applied || $applied > date('Y-m-d')) {
            Session::flash('error', 'Informe a vacina e a data de aplicação (não pode ser futura).');
            header('Location: ' . $back); exit;
        }
        if (empty($_FILES['file']['name'])) {
            Session::flash('error', 'Anexe o comprovante (foto do cartão/carteira ou PDF).');
            header('Location: ' . $back); exit;
        }
        $up = Upload::handle('file', 'vaccines');
        if (!$up['success']) {
            Session::flash('error', 'Comprovante recusado: ' . $up['error']);
            header('Location: ' . $back); exit;
        }
        $st = $this->db->prepare("SELECT COUNT(*) FROM rh_employee_vaccines WHERE employee_id = ? AND vaccine_id = ? AND kind = 'dose'");
        $st->execute([$employeeId, $vaccineId]);
        $dose = (int) $st->fetchColumn() + 1;
        [$validUntil] = Vaccine::computeDates($vaccine, $dose, $applied);
        $this->db->prepare(
            'INSERT INTO rh_employee_vaccines (employee_id, vaccine_id, kind, dose_number, applied_at, valid_until, file_path, file_original_name, notes, verified, submitted_by_employee, created_by)
             VALUES (?,?,\'dose\',?,?,?,?,?,?,0,1,?)'
        )->execute([$employeeId, $vaccineId, $dose, $applied, $validUntil, $up['path'], $up['original_name'],
            mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 1000) ?: null, Session::userId()]);
        $recId = (int) $this->db->lastInsertId();

        $req = VaccineRequest::open($employeeId, $vaccineId);
        if ($req) {
            $this->db->prepare("UPDATE rh_vaccine_requests SET status = 'enviada', employee_vaccine_id = ?, employee_note = ?, responded_at = NOW() WHERE id = ?")
                     ->execute([$recId, mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 1000) ?: null, (int) $req['id']]);
        }
        $nome = Employee::find($employeeId)['full_name'] ?? 'Funcionário';
        foreach (Core\Perms::usersWith('rh', 'vaccines.edit') as $uid) {
            Core\Notifications::add((int) $uid, 'Comprovante de vacina para validar: ' . $vaccine['name'], $nome . ' enviou o comprovante.',
                'index.php?m=rh&page=employees&action=show&id=' . $employeeId . '#tabVacinas', 'info', 'rh');
        }
        Session::flash('success', 'Comprovante enviado. O RH vai validar o registro.');
        header('Location: ' . $back); exit;
    }

    /** Fecha solicitações abertas desta vacina quando um registro verificado resolve o status. */
    private function closeRequestsIfResolved(int $employeeId, int $vaccineId, int $recId): void
    {
        $vaccine = Vaccine::find($vaccineId);
        if (!$vaccine) { return; }
        $ev = Vaccine::evaluate($vaccine, Vaccine::recordsOf($employeeId)[$vaccineId] ?? []);
        if (in_array($ev['status'], ['em_dia', 'nao_aplica'], true) || ($ev['status'] === 'incompleta' && $ev['unverified'] === 0)) {
            // 'incompleta' também fecha a solicitação de RENOVAÇÃO (a dose foi dada);
            // a de regularização ('pendente') só fecha quando o esquema completa.
            $this->db->prepare(
                "UPDATE rh_vaccine_requests SET status = 'concluida', employee_vaccine_id = COALESCE(employee_vaccine_id, ?), closed_by = ?, closed_at = NOW()
                 WHERE employee_id = ? AND vaccine_id = ? AND status IN ('aberta','enviada')"
                 . ($ev['status'] === 'incompleta' ? " AND kind = 'renovacao'" : '')
            )->execute([$recId, Session::userId(), $employeeId, $vaccineId]);
        }
    }

    // ---------------------------------------------------- catálogo (admin central)
    public function catalog(): void
    {
        core_require('vaccines.config');
        $edit = Sanitize::int($_GET['id'] ?? 0);
        View::render('vaccines/catalog', [
            'pageTitle' => 'Catálogo de vacinas',
            'page'      => 'vaccines',
            'items'     => Vaccine::catalog(false),
            'editing'   => $edit ? Vaccine::find($edit) : null,
        ]);
    }

    public function catalog_save(): void
    {
        core_require('vaccines.config'); Csrf::check();
        $id   = Sanitize::int($_POST['id'] ?? 0);
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        $back = core_admin_url('rh', 'vaccines');
        if ($name === '') { Session::flash('error', 'Informe o nome da vacina.'); header('Location: ' . $back); exit; }
        $cat = isset(Vaccine::CATEGORIES[$_POST['category'] ?? '']) ? (string) $_POST['category'] : 'recomendada';
        $data = [
            'name' => $name, 'category' => $cat,
            'target' => mb_substr(trim((string) ($_POST['target'] ?? '')), 0, 200) ?: null,
            'doses_total' => max(1, min(10, Sanitize::int($_POST['doses_total'] ?? 1))),
            'schedule' => mb_substr(trim((string) ($_POST['schedule'] ?? '')), 0, 200) ?: null,
            'next_dose_days' => Sanitize::int($_POST['next_dose_days'] ?? 0) ?: null,
            'booster_months' => Sanitize::int($_POST['booster_months'] ?? 0) ?: null,
            'validity_rule' => mb_substr(trim((string) ($_POST['validity_rule'] ?? '')), 0, 255) ?: null,
            'serology' => mb_substr(trim((string) ($_POST['serology'] ?? '')), 0, 120) ?: null,
            'applies_to_all' => !empty($_POST['applies_to_all']) ? 1 : 0,
            'notes' => mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 2000) ?: null,
            'sort_order' => Sanitize::int($_POST['sort_order'] ?? 0),
        ];
        if ($id) {
            Vaccine::update($id, $data);
            AuditLog::log('update', 'vaccines', $id);
            Session::flash('success', 'Vacina atualizada.');
        } else {
            $key = preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name));
            $key = trim((string) $key, '_') ?: 'vacina';
            $base = $key; $n = 1;
            while (self::keyExists($key)) { $key = $base . '_' . (++$n); }
            $data['key'] = mb_substr($key, 0, 40);
            $data['active'] = 1;
            $id = Vaccine::insert($data);
            AuditLog::log('create', 'vaccines', $id);
            Session::flash('success', 'Vacina adicionada ao catálogo.');
        }
        header('Location: ' . $back); exit;
    }

    public function catalog_toggle(): void
    {
        core_require('vaccines.config'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $this->db->prepare('UPDATE rh_vaccines SET active = 1 - active WHERE id = ?')->execute([$id]);
        AuditLog::log('toggle', 'vaccines', $id);
        header('Location: ' . core_admin_url('rh', 'vaccines')); exit;
    }

    public function catalog_delete(): void
    {
        core_require('vaccines.config'); Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $st = $this->db->prepare('SELECT COUNT(*) FROM rh_employee_vaccines WHERE vaccine_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            Session::flash('error', 'Esta vacina tem registros de funcionários — inative em vez de excluir.');
        } else {
            $this->db->prepare('DELETE FROM rh_vaccines WHERE id = ?')->execute([$id]);
            AuditLog::log('delete', 'vaccines', $id);
            Session::flash('success', 'Vacina removida do catálogo.');
        }
        header('Location: ' . core_admin_url('rh', 'vaccines')); exit;
    }

    private static function keyExists(string $key): bool
    {
        $st = Database::getInstance()->prepare('SELECT 1 FROM rh_vaccines WHERE `key` = ?');
        $st->execute([$key]);
        return (bool) $st->fetchColumn();
    }
}
