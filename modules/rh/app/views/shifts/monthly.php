<?php
/**
 * Extrato MENSAL da escala — lista de presença.
 * Linhas = funcionários, colunas = dias do mês.
 * P = plantão · F = férias · A = afastado · vazio = folga.
 *
 * $mes, $mesRotulo, $diasNoMes, $primeiro, $prevMes, $nextMes,
 * $departments, $deptId, $deptNome, $employees, $grade, $totais
 */
$iniciais = ['1' => 'S', '2' => 'T', '3' => 'Q', '4' => 'Q', '5' => 'S', '6' => 'S', '7' => 'D'];
$tsMes = (int) strtotime($primeiro);
$linkBase = 'index.php?m=rh&page=shifts&action=monthly&mes=';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0"><i class="bi bi-calendar2-range me-2"></i>Escala mensal</h1>
        <div class="text-muted small">
            Lista de presença de <strong><?= Sanitize::e($mesRotulo) ?></strong>
            <?= $deptNome !== '' ? '· ' . Sanitize::e($deptNome) : '· todos os setores' ?>
        </div>
    </div>
    <div class="btn-group">
        <a class="btn btn-outline-secondary" href="<?= $linkBase . Sanitize::e($prevMes) . '&department=' . (int) $deptId ?>" aria-label="Mês anterior"><i class="bi bi-chevron-left"></i></a>
        <a class="btn btn-outline-secondary" href="<?= $linkBase . date('Y-m') . '&department=' . (int) $deptId ?>">Este mês</a>
        <a class="btn btn-outline-secondary" href="<?= $linkBase . Sanitize::e($nextMes) . '&department=' . (int) $deptId ?>" aria-label="Próximo mês"><i class="bi bi-chevron-right"></i></a>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="m" value="rh">
            <input type="hidden" name="page" value="shifts">
            <input type="hidden" name="action" value="monthly">
            <div class="col-sm-4 col-md-3">
                <label class="form-label small mb-1" for="f_mes">Mês</label>
                <input type="month" class="form-control form-control-sm" id="f_mes" name="mes" value="<?= Sanitize::e($mes) ?>">
            </div>
            <div class="col-sm-5 col-md-4">
                <label class="form-label small mb-1" for="f_dept">Setor</label>
                <select class="form-select form-select-sm" id="f_dept" name="department">
                    <option value="0">Todos os setores</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === (int) $deptId ? 'selected' : '' ?>><?= Sanitize::e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-3 col-md-5 d-flex gap-2">
                <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Ver</button>
                <a class="btn btn-outline-dark btn-sm" target="_blank" rel="noopener"
                   href="index.php?m=rh&page=shifts&action=monthly_print&mes=<?= Sanitize::e($mes) ?>&department=<?= (int) $deptId ?>">
                    <i class="bi bi-printer me-1"></i>Imprimir
                </a>
            </div>
        </form>
    </div>
</div>

<div class="d-flex flex-wrap gap-3 small mb-2">
    <span><span class="esc-key esc-P">P</span> Plantão</span>
    <span><span class="esc-key esc-F">F</span> Férias</span>
    <span><span class="esc-key esc-A">A</span> Afastado</span>
    <span><span class="esc-key esc-O">·</span> Folga</span>
</div>

<?php if (empty($employees)): ?>
    <div class="alert alert-info">Nenhum funcionário ativo neste setor para <?= Sanitize::e($mesRotulo) ?>.</div>
<?php else: ?>
<div class="card shadow-sm">
    <div class="table-responsive esc-wrap">
        <table class="table table-bordered table-sm esc-grade mb-0">
            <thead>
                <tr>
                    <th class="esc-nome">Funcionário</th>
                    <?php for ($dia = 1; $dia <= $diasNoMes; $dia++):
                        $dow = (int) date('N', (int) strtotime(sprintf('%s-%02d', $mes, $dia)));
                        $fds = ($dow >= 6); ?>
                        <th class="esc-dia <?= $fds ? 'esc-fds' : '' ?>" title="<?= sprintf('%02d/%s', $dia, date('m', $tsMes)) ?>">
                            <span class="esc-dianum"><?= $dia ?></span>
                            <span class="esc-dow"><?= $iniciais[(string) $dow] ?></span>
                        </th>
                    <?php endfor; ?>
                    <th class="esc-tot" title="Plantões">P</th>
                    <th class="esc-tot" title="Férias">F</th>
                    <th class="esc-tot" title="Afastado">A</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $e): $eid = (int) $e['id']; ?>
                    <tr>
                        <th class="esc-nome" scope="row">
                            <?= Sanitize::e($e['full_name']) ?>
                            <?php if ($e['status'] === 'afastado'): ?><span class="badge bg-warning text-dark ms-1">afastado</span><?php endif; ?>
                            <div class="esc-dept text-muted"><?= Sanitize::e($e['dept_name'] ?? '') ?></div>
                        </th>
                        <?php for ($dia = 1; $dia <= $diasNoMes; $dia++):
                            $cel = $grade[$eid][$dia] ?? null;
                            $dow = (int) date('N', (int) strtotime(sprintf('%s-%02d', $mes, $dia)));
                            $fds = ($dow >= 6);
                            if ($cel === null) {
                                echo '<td class="esc-cel ' . ($fds ? 'esc-fds' : '') . '"></td>';
                            } elseif ($cel['tipo'] === 'P') {
                                $tt = trim(($cel['tpl'] ?: 'Plantão') . ' ' . $cel['ini'] . '–' . $cel['fim']);
                                echo '<td class="esc-cel esc-P" style="--esc-c:' . Sanitize::e($cel['cor']) . '" title="' . Sanitize::e($tt) . '">P</td>';
                            } elseif ($cel['tipo'] === 'F') {
                                echo '<td class="esc-cel esc-F" title="Férias">F</td>';
                            } else {
                                echo '<td class="esc-cel esc-A" title="Afastado">A</td>';
                            }
                        endfor; ?>
                        <td class="esc-tot fw-semibold"><?= $totais[$eid]['P'] ?></td>
                        <td class="esc-tot"><?= $totais[$eid]['F'] ?: '' ?></td>
                        <td class="esc-tot"><?= $totais[$eid]['A'] ?: '' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<style>
.esc-key { display:inline-flex; align-items:center; justify-content:center; width:1.4rem; height:1.4rem; border-radius:.35rem; font-weight:700; font-size:.75rem; }
.esc-P { background:#0d6efd; color:#fff; }
.esc-F { background:#ffc107; color:#212529; }
.esc-A { background:#adb5bd; color:#212529; }
.esc-O { background:#f1f3f5; color:#adb5bd; }
.esc-wrap { overflow-x:auto; }
.esc-grade { border-collapse:separate; }
.esc-grade th, .esc-grade td { text-align:center; vertical-align:middle; padding:.15rem; }
.esc-grade .esc-nome { text-align:left; position:sticky; left:0; background:var(--bs-body-bg,#fff); min-width:190px; z-index:2; white-space:nowrap; }
.esc-grade thead .esc-nome { z-index:3; }
.esc-dept { font-size:.7rem; line-height:1; }
.esc-dia { min-width:1.7rem; font-size:.7rem; line-height:1; }
.esc-dianum { display:block; font-weight:700; }
.esc-dow { display:block; color:#6c757d; font-size:.62rem; }
.esc-fds { background:#f6f7f9; }
.esc-cel { min-width:1.7rem; font-weight:700; font-size:.78rem; }
.esc-cel.esc-P { background:var(--esc-c,#0d6efd); color:#fff; }
.esc-tot { min-width:2rem; background:#f8f9fa; }
</style>
