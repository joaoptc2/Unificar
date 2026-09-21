<?php
class ShiftController
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    public function index(): void
    {
        core_require('shifts.view');
        $deptId = Sanitize::int($_GET['department'] ?? 0);
        $weekStart = Sanitize::date($_GET['week'] ?? '') ?: date('Y-m-d', strtotime('monday this week'));
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
        $departments = $this->db->query('SELECT id, name FROM rh_departments WHERE active = 1 ORDER BY name')->fetchAll();
        $templates = ShiftTemplate::allActive();
        $shifts = $deptId ? Shift::forDepartmentRange($deptId, $weekStart, $weekEnd) : [];
        $employees = $deptId
            ? $this->db->prepare("SELECT id, full_name FROM rh_employees WHERE department_id = ? AND status = 'ativo' ORDER BY full_name")
            : null;
        if ($employees) { $employees->execute([$deptId]); $employees = $employees->fetchAll(); } else { $employees = []; }
        $prevWeek = date('Y-m-d', strtotime($weekStart . ' -7 days'));
        $nextWeek = date('Y-m-d', strtotime($weekStart . ' +7 days'));
        View::render('shifts/index', [
            'pageTitle' => 'Escalas de Plantao', 'page' => 'shifts',
            'departments' => $departments, 'templates' => $templates,
            'deptId' => $deptId, 'weekStart' => $weekStart, 'weekEnd' => $weekEnd,
            'prevWeek' => $prevWeek, 'nextWeek' => $nextWeek,
            'shifts' => $shifts, 'employees' => $employees,
        ]);
    }

    public function store(): void
    {
        core_require('shifts.create');
        Csrf::check();
        $empId = Sanitize::int($_POST['employee_id'] ?? 0);
        $date = Sanitize::date($_POST['shift_date'] ?? '');
        $start = Sanitize::post('start_time');
        $end = Sanitize::post('end_time');
        $deptId = Sanitize::int($_POST['department_id'] ?? 0);
        $tplId = Sanitize::int($_POST['template_id'] ?? 0);
        if (!$empId || !$date || !$start || !$end) {
            Session::flash('error', 'Preencha todos os campos obrigatorios.');
            header('Location: index.php?m=rh&page=shifts&department=' . $deptId); exit;
        }
        if (Shift::hasConflict($empId, $date, $start, $end)) {
            Session::flash('error', 'Conflito: funcionario ja tem escala neste horario.');
            header('Location: index.php?m=rh&page=shifts&department=' . $deptId); exit;
        }
        $id = Shift::insert([
            'employee_id' => $empId, 'department_id' => $deptId ?: null,
            'template_id' => $tplId ?: null, 'shift_date' => $date,
            'start_time' => $start, 'end_time' => $end,
            'type' => Sanitize::post('type') ?: 'regular',
            'notes' => Sanitize::post('notes'), 'created_by' => Session::userId(),
        ]);
        AuditLog::log('create', 'shifts', $id);
        Session::flash('success', 'Escala cadastrada.');
        header('Location: index.php?m=rh&page=shifts&department=' . $deptId . '&week=' . $date); exit;
    }

    public function delete(): void
    {
        core_require('shifts.delete');
        Csrf::check();
        $id = Sanitize::int($_POST['id'] ?? 0);
        $shift = Shift::find($id);
        Shift::delete($id);
        AuditLog::log('delete', 'shifts', $id);
        Session::flash('success', 'Escala removida.');
        $dept = $shift['department_id'] ?? 0;
        header('Location: index.php?m=rh&page=shifts&department=' . $dept); exit;
    }

    public function swap(): void
    {
        Auth::requireLogin();
        Csrf::check();
        $shiftId = Sanitize::int($_POST['shift_id'] ?? 0);
        $withEmpId = Sanitize::int($_POST['swap_employee_id'] ?? 0);
        $shift = Shift::find($shiftId);
        if (!$shift) { Session::flash('error', 'Escala nao encontrada.'); header('Location: index.php?m=rh&page=shifts'); exit; }
        Shift::update($shiftId, ['status' => 'troca_pendente', 'swap_with_id' => $withEmpId]);
        AuditLog::log('swap_request', 'shifts', $shiftId);
        Session::flash('success', 'Solicitacao de troca enviada para aprovacao.');
        header('Location: index.php?m=rh&page=shifts&department=' . ($shift['department_id'] ?? 0)); exit;
    }

    public function approve_swap(): void
    {
        core_require('shifts.edit');
        Csrf::check();
        $shiftId = Sanitize::int($_POST['shift_id'] ?? 0);
        $shift = Shift::find($shiftId);
        if (!$shift || $shift['status'] !== 'troca_pendente') {
            Session::flash('error', 'Troca invalida.'); header('Location: index.php?m=rh&page=shifts'); exit;
        }
        $newEmp = (int)$shift['swap_with_id'];
        Shift::update($shiftId, ['employee_id' => $newEmp, 'status' => 'trocado', 'swap_with_id' => null]);
        AuditLog::log('swap_approved', 'shifts', $shiftId);
        Session::flash('success', 'Troca aprovada.');
        header('Location: index.php?m=rh&page=shifts&department=' . ($shift['department_id'] ?? 0)); exit;
    }

    public function events(): void
    {
        core_require('shifts.view');
        $deptId = Sanitize::int($_GET['department'] ?? 0);
        $start = Sanitize::date(substr($_GET['start'] ?? '', 0, 10)) ?: date('Y-m-01');
        $end = Sanitize::date(substr($_GET['end'] ?? '', 0, 10)) ?: date('Y-m-t');
        $shifts = $deptId ? Shift::forDepartmentRange($deptId, $start, $end) : [];
        $out = [];
        foreach ($shifts as $s) {
            $out[] = [
                'id' => (int)$s['id'],
                'title' => $s['employee_name'] . ($s['template_name'] ? ' (' . $s['template_name'] . ')' : ''),
                'start' => $s['shift_date'] . 'T' . $s['start_time'],
                'end' => $s['shift_date'] . 'T' . $s['end_time'],
                'color' => $s['color'] ?? '#0d6efd',
                'extendedProps' => ['type' => $s['type'], 'status' => $s['status']],
            ];
        }
        header('Content-Type: application/json');
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Extrato MENSAL da escala, em lista de presença: uma linha por
     * funcionário, uma coluna por dia do mês, marcando quem está de plantão
     * (P), de férias (F) ou afastado (A). Reúne rh_shifts + rh_vacations +
     * o status/leave do funcionário num só quadro imprimível.
     */
    public function monthly(): void
    {
        core_require('shifts.view');
        $dados = $this->buildMonthly();
        View::render('shifts/monthly', $dados);
    }

    /** Mesmo extrato, em página limpa para impressão (sem o layout do portal). */
    public function monthly_print(): void
    {
        core_require('shifts.view');
        $dados = $this->buildMonthly();
        View::renderRaw('shifts/monthly_print', $dados);
    }

    /**
     * Monta os dados do extrato mensal. Prioridade da célula, do mais forte
     * para o mais fraco: plantão > férias > afastamento > folga.
     * @return array<string,mixed>
     */
    private function buildMonthly(): array
    {
        $deptId = Sanitize::int($_GET['department'] ?? 0);
        $mes = (string) ($_GET['mes'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
            $mes = date('Y-m');
        }
        $primeiro = $mes . '-01';
        $ts = (int) strtotime($primeiro);
        $ultimo = date('Y-m-t', $ts);
        $diasNoMes = (int) date('t', $ts);

        $departments = $this->db->query('SELECT id, name FROM rh_departments WHERE active = 1 ORDER BY name')->fetchAll();
        $deptNome = '';
        if ($deptId) {
            foreach ($departments as $d) {
                if ((int) $d['id'] === $deptId) { $deptNome = (string) $d['name']; break; }
            }
        }

        // Funcionários: ativos e afastados entram (o afastado precisa aparecer
        // marcado); desligados e anonimizados ficam de fora.
        $sqlEmp = "SELECT e.id, e.full_name, e.status, e.leave_date, e.return_date, d.name AS dept_name
                   FROM rh_employees e LEFT JOIN rh_departments d ON e.department_id = d.id
                   WHERE e.status IN ('ativo','afastado') AND e.anonymized_at IS NULL";
        $params = [];
        if ($deptId) { $sqlEmp .= ' AND e.department_id = ?'; $params[] = $deptId; }
        $sqlEmp .= ' ORDER BY e.full_name';
        $stmt = $this->db->prepare($sqlEmp);
        $stmt->execute($params);
        $employees = $stmt->fetchAll();

        // Plantões do mês, indexados por funcionário → dia.
        // Sem filtro por setor NA CONSULTA de plantões: o escopo já vem do
        // conjunto de funcionários (dept-filtrado). Filtrar aqui também
        // descartaria um plantão que o funcionário listado fez marcado em
        // outro setor (cobertura), sumindo da sua linha. Só os plantões de
        // funcionários listados entram na grade (indexados por employee_id).
        $sqlSh = "SELECT s.employee_id, s.shift_date, s.start_time, s.end_time, s.type, s.status,
                         st.color, st.name AS template_name
                  FROM rh_shifts s LEFT JOIN rh_shift_templates st ON s.template_id = st.id
                  WHERE s.shift_date BETWEEN ? AND ?";
        $stmt = $this->db->prepare($sqlSh);
        $stmt->execute([$primeiro, $ultimo]);
        $shiftsByEmpDay = [];
        foreach ($stmt->fetchAll() as $s) {
            $dia = (int) substr((string) $s['shift_date'], 8, 2);
            // Se houver mais de um plantão no dia, mantém o primeiro; o
            // detalhe fino é a tela semanal, aqui é a visão de presença.
            $shiftsByEmpDay[(int) $s['employee_id']][$dia] ??= $s;
        }

        // Férias que cruzam o mês. Datas concretas (start/end) quando existem,
        // senão a janela do período; só as que valem como ausência real.
        $sqlV = "SELECT employee_id,
                        COALESCE(start_date, period_start) AS d0,
                        COALESCE(end_date, period_end)     AS d1
                 FROM rh_vacations
                 WHERE status IN ('aprovada','em_gozo','concluida')
                   AND COALESCE(start_date, period_start) <= ?
                   AND COALESCE(end_date, period_end)     >= ?";
        $stmt = $this->db->prepare($sqlV);
        $stmt->execute([$ultimo, $primeiro]);
        $vacByEmp = [];
        foreach ($stmt->fetchAll() as $v) {
            if ($v['d0'] && $v['d1']) {
                $vacByEmp[(int) $v['employee_id']][] = [(string) $v['d0'], (string) $v['d1']];
            }
        }

        // Monta a grade: emp → dia → ['tipo'=>'P|F|A', ...].
        $grade = [];
        $totais = [];
        foreach ($employees as $e) {
            $eid = (int) $e['id'];
            $linha = [];
            $tP = $tF = $tA = 0;
            // Janela de afastamento do próprio funcionário. Quando o status é
            // "afastado" mas o RH não preencheu a data de afastamento, marca o
            // mês inteiro exibido (senão "quem está afastado" — o objetivo da
            // lista — nunca apareceria marcado). return_date nulo mantém "A"
            // até o fim do mês.
            $afIni = ($e['status'] === 'afastado') ? (string) ($e['leave_date'] ?: $primeiro) : null;
            $afFim = $e['return_date'] ? (string) $e['return_date'] : null;
            for ($dia = 1; $dia <= $diasNoMes; $dia++) {
                $dataDia = sprintf('%s-%02d', $mes, $dia);
                $cel = null;
                if (isset($shiftsByEmpDay[$eid][$dia])) {
                    $s = $shiftsByEmpDay[$eid][$dia];
                    $cel = [
                        'tipo'  => 'P',
                        'cor'   => $s['color'] ?: '#0d6efd',
                        'ini'   => substr((string) $s['start_time'], 0, 5),
                        'fim'   => substr((string) $s['end_time'], 0, 5),
                        'tpl'   => (string) ($s['template_name'] ?? ''),
                        'stt'   => (string) $s['status'],
                    ];
                    $tP++;
                } elseif ($this->emAlgumIntervalo($dataDia, $vacByEmp[$eid] ?? [])) {
                    $cel = ['tipo' => 'F'];
                    $tF++;
                } elseif ($afIni !== null && $dataDia >= $afIni && ($afFim === null || $dataDia <= $afFim)) {
                    $cel = ['tipo' => 'A'];
                    $tA++;
                }
                $linha[$dia] = $cel;
            }
            $grade[$eid] = $linha;
            $totais[$eid] = ['P' => $tP, 'F' => $tF, 'A' => $tA];
        }

        return [
            'pageTitle'   => 'Escala mensal',
            'page'        => 'shifts',
            'departments' => $departments,
            'deptId'      => $deptId,
            'deptNome'    => $deptNome,
            'mes'         => $mes,
            'mesRotulo'   => $this->mesPorExtenso($mes),
            'diasNoMes'   => $diasNoMes,
            'primeiro'    => $primeiro,
            'prevMes'     => date('Y-m', (int) strtotime($primeiro . ' -1 month')),
            'nextMes'     => date('Y-m', (int) strtotime($primeiro . ' +1 month')),
            'employees'   => $employees,
            'grade'       => $grade,
            'totais'      => $totais,
        ];
    }

    /** true se $data (Y-m-d) cai em algum intervalo [d0,d1] inclusive. */
    private function emAlgumIntervalo(string $data, array $intervalos): bool
    {
        foreach ($intervalos as [$d0, $d1]) {
            if ($data >= $d0 && $data <= $d1) {
                return true;
            }
        }
        return false;
    }

    /** "setembro de 2026" a partir de "2026-09". */
    private function mesPorExtenso(string $mes): string
    {
        $nomes = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
                  'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        [$a, $m] = array_map('intval', explode('-', $mes));
        return ($nomes[$m] ?? '') . ' de ' . $a;
    }
}
