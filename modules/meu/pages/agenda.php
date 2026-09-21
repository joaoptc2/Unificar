<?php
/** MEU ESPAÇO — agenda pessoal (semana). */

declare(strict_types=1);

use Core\Csrf;
use Core\DB;
use Core\Flash;
use Core\Layout;
use Core\Tokens;

core_require('agenda.view');

$uid = meu_uid();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    core_require('agenda.manage');
    Csrf::check();
    $op = (string) ($_POST['op'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($op === 'criar' || $op === 'editar') {
        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        $dia    = trim((string) ($_POST['dia'] ?? ''));
        $inteiro = !empty($_POST['dia_inteiro']);
        $hIni   = trim((string) ($_POST['hora_inicio'] ?? '')) ?: '08:00';
        $hFim   = trim((string) ($_POST['hora_fim'] ?? '')) ?: '09:00';

        if ($titulo === '' || !strtotime($dia)) {
            Flash::set('error', 'Informe ao menos o título e o dia.');
            core_redirect(core_module_url('meu', ['page' => 'agenda']));
        }
        $dia    = date('Y-m-d', (int) strtotime($dia));
        $inicio = $inteiro ? $dia . ' 00:00:00' : $dia . ' ' . $hIni . ':00';
        $fim    = $inteiro ? $dia . ' 23:59:59' : $dia . ' ' . $hFim . ':00';
        // Plantão que vira a noite: fim antes do início significa dia seguinte.
        // Sem isto o compromisso ficaria com duração negativa e sumiria da
        // consulta por interseção.
        if (!$inteiro && $fim <= $inicio) {
            $fim = date('Y-m-d H:i:s', (int) strtotime($fim . ' +1 day'));
        }
        $cor = Tokens::color((string) ($_POST['cor'] ?? ''), '');
        $local = trim((string) ($_POST['local'] ?? ''));
        $desc  = trim((string) ($_POST['descricao'] ?? ''));
        $lemb  = (int) ($_POST['lembrete_min'] ?? 0);
        $lemb  = in_array($lemb, [0, 10, 30, 60, 1440], true) ? $lemb : 0;
        $lembEm = $lemb > 0 ? date('Y-m-d H:i:s', (int) strtotime($inicio) - $lemb * 60) : null;

        $campos = [
            mb_substr($titulo, 0, 200), $desc ?: null, mb_substr($local, 0, 200) ?: null,
            $inicio, $fim, $inteiro ? 1 : 0, $cor ?: null, $lemb ?: null, $lembEm,
        ];
        if ($op === 'criar') {
            DB::execute(
                'INSERT INTO meu_eventos (titulo, descricao, local, inicio, fim, dia_inteiro, cor, lembrete_min, lembrete_em, user_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                array_merge($campos, [$uid])
            );
            Flash::set('success', 'Compromisso criado.');
        } elseif (meu_registro('meu_eventos', $id)) {
            DB::execute(
                'UPDATE meu_eventos SET titulo=?, descricao=?, local=?, inicio=?, fim=?, dia_inteiro=?, cor=?,
                        lembrete_min=?, lembrete_em=? WHERE id = ? AND user_id = ?',
                array_merge($campos, [$id, $uid])
            );
            Flash::set('success', 'Compromisso atualizado.');
        }
    } elseif ($op === 'excluir') {
        if (meu_excluir('meu_eventos', $id)) {
            Flash::set('success', 'Compromisso excluído.');
        }
    }
    core_redirect(core_module_url('meu', array_filter([
        'page'  => 'agenda',
        'vista' => (string) ($_POST['vista'] ?? ''),
        'ref'   => (string) ($_POST['ref'] ?? ($_POST['semana'] ?? '')),
    ], static fn ($v) => $v !== '')));
}

// ---- Vista: mês (padrão), semana ou ano -------------------------------------
$vista = (string) ($_GET['vista'] ?? '');
// Compatibilidade com os links antigos (?semana=AAAA-MM-DD): sem vista mas com
// o parâmetro semana → vista semanal.
if ($vista === '' && isset($_GET['semana'])) {
    $vista = 'semana';
}
if (!in_array($vista, ['mes', 'semana', 'ano'], true)) {
    $vista = 'mes';
}

// Âncora do período: um dia qualquer dentro dele (ref, ou o semana legado).
$ref    = (string) ($_GET['ref'] ?? ($_GET['semana'] ?? ''));
$baseTs = ($ref && strtotime($ref)) ? (int) strtotime($ref) : time();

$podeMexer  = core_can('agenda.manage');
$hoje       = date('Y-m-d');
$diasSemana = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];
$iniSemana  = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
$mesesNome  = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
               'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

// Semana da âncora (segunda a domingo) — base da vista semanal e destino de
// cliques nas demais.
$segunda = (int) strtotime('monday this week', $baseTs);
$domingo = (int) strtotime('+6 days', $segunda);

// Faixa consultada e âncora que o formulário usa como data padrão.
if ($vista === 'semana') {
    $rangeIni = date('Y-m-d 00:00:00', $segunda);
    $rangeFim = date('Y-m-d 23:59:59', $domingo);
    $refAtual = date('Y-m-d', $segunda);
} elseif ($vista === 'ano') {
    $ano      = (int) date('Y', $baseTs);
    $rangeIni = sprintf('%04d-01-01 00:00:00', $ano);
    $rangeFim = sprintf('%04d-12-31 23:59:59', $ano);
    $refAtual = sprintf('%04d-01-01', $ano);
} else { // mes
    $primeiroMes = (int) strtotime(date('Y-m-01', $baseTs));
    $ultimoMes   = (int) strtotime(date('Y-m-t', $baseTs));
    // A grade do mês começa na segunda-feira da semana do dia 1 e termina no
    // domingo da semana do último dia.
    $gradeIni = (int) strtotime('monday this week', $primeiroMes);
    $gradeFim = (int) strtotime('sunday this week', $ultimoMes);
    $rangeIni = date('Y-m-d 00:00:00', $gradeIni);
    $rangeFim = date('Y-m-d 23:59:59', $gradeFim);
    $refAtual = date('Y-m-01', $baseTs);
}

$eventos = meu_eventos($rangeIni, $rangeFim);

// Distribui cada compromisso por TODOS os dias que ele cobre, dentro da faixa.
$porDia = [];
$iniFaixaTs = (int) strtotime($rangeIni);
$fimFaixaTs = (int) strtotime($rangeFim);
foreach ($eventos as $e) {
    $d = max((int) strtotime($e['inicio']), $iniFaixaTs);
    $f = min((int) strtotime($e['fim']), $fimFaixaTs);
    for ($t = (int) strtotime(date('Y-m-d', $d)); $t <= $f; $t = (int) strtotime('+1 day', $t)) {
        $porDia[date('Y-m-d', $t)][] = $e;
    }
}

$editando = isset($_GET['editar']) ? meu_registro('meu_eventos', (int) $_GET['editar']) : null;

// URLs preservando a vista.
$urlRef   = fn (string $v, int $ts): string => core_module_url('meu', ['page' => 'agenda', 'vista' => $v, 'ref' => date('Y-m-d', $ts)]);
$urlHoje  = fn (string $v): string => core_module_url('meu', ['page' => 'agenda', 'vista' => $v, 'ref' => $hoje]);
$urlSemana = fn (int $ts): string => $urlRef('semana', $ts); // compat interna

// Navegação contextual (anterior / próximo) e rótulo do período por vista.
if ($vista === 'semana') {
    $tsAnt = (int) strtotime('-7 days', $segunda);
    $tsProx = (int) strtotime('+7 days', $segunda);
    $rotulo = date('d/m/Y', $segunda) . ' a ' . date('d/m/Y', $domingo);
} elseif ($vista === 'ano') {
    $tsAnt = (int) strtotime($ano . '-01-01 -1 year');
    $tsProx = (int) strtotime($ano . '-01-01 +1 year');
    $rotulo = (string) $ano;
} else { // mes
    $tsAnt = (int) strtotime(date('Y-m-01', $baseTs) . ' -1 month');
    $tsProx = (int) strtotime(date('Y-m-01', $baseTs) . ' +1 month');
    $rotulo = $mesesNome[(int) date('n', $baseTs)] . ' de ' . date('Y', $baseTs);
}

// Contagem do PERÍODO nomeado. Nas vistas semana/ano a faixa consultada é o
// próprio período. Na vista mês a faixa é maior (inclui a cauda dos meses
// vizinhos que a grade mostra), então conta só os eventos que tocam o mês real.
if ($vista === 'mes') {
    $mesIniTs = (int) strtotime(date('Y-m-01 00:00:00', $baseTs));
    $mesFimTs = (int) strtotime(date('Y-m-t 23:59:59', $baseTs));
    $totalPeriodo = 0;
    foreach ($eventos as $e) {
        if ((int) strtotime($e['inicio']) <= $mesFimTs && (int) strtotime($e['fim']) >= $mesIniTs) {
            $totalPeriodo++;
        }
    }
} else {
    $totalPeriodo = count($eventos);
}
ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <h1 class="h4 mb-0"><i class="bi bi-calendar3 me-2"></i>Agenda</h1>
    <div class="btn-group btn-group-sm" role="group" aria-label="Modo de visualização">
        <?php foreach (['mes' => 'Mês', 'semana' => 'Semana', 'ano' => 'Ano'] as $v => $lbl): ?>
            <a class="btn <?= $vista === $v ? 'btn-primary' : 'btn-outline-primary' ?>"
               href="<?= $urlRef($v, $baseTs) ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
    </div>
</div>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="btn-group btn-group-sm">
        <a class="btn btn-outline-secondary" href="<?= $urlRef($vista, $tsAnt) ?>" aria-label="Anterior"><i class="bi bi-chevron-left"></i></a>
        <a class="btn btn-outline-secondary" href="<?= $urlHoje($vista) ?>">Hoje</a>
        <a class="btn btn-outline-secondary" href="<?= $urlRef($vista, $tsProx) ?>" aria-label="Próximo"><i class="bi bi-chevron-right"></i></a>
    </div>
    <span class="fw-semibold text-capitalize"><?= core_e($rotulo) ?></span>
    <span class="text-muted small"><?= $totalPeriodo ?> compromisso(s)</span>
</div>

<div class="row g-3">
    <div class="col-12 <?= $podeMexer ? 'col-xl-8' : '' ?>">
        <?php
        // URL de edição preservando a vista/âncora atuais.
        $urlEditar = fn (int $id): string => core_module_url('meu', ['page' => 'agenda', 'vista' => $vista, 'ref' => $refAtual, 'editar' => $id]);
        ?>
        <?php if ($vista === 'semana'): ?>
        <div class="row g-2">
        <?php for ($i = 0; $i < 7; $i++):
            $ts = (int) strtotime("+{$i} days", $segunda);
            $dia = date('Y-m-d', $ts);
            $lista = $porDia[$dia] ?? [];
            $eHoje = $dia === $hoje; ?>
            <div class="col-12 col-md-6 col-xl-4">
                <div class="card h-100 <?= $eHoje ? 'border-primary' : '' ?>">
                    <div class="card-header py-2 d-flex justify-content-between align-items-center <?= $eHoje ? 'fw-semibold' : '' ?>">
                        <span><?= $diasSemana[(int) date('N', $ts) - 1] ?>, <?= date('d/m', $ts) ?></span>
                        <?php if ($eHoje): ?><span class="badge text-bg-primary">hoje</span><?php endif; ?>
                    </div>
                    <div class="card-body p-2">
                        <?php if (!$lista): ?>
                            <p class="text-muted small mb-0 text-center py-2">Livre</p>
                        <?php endif; ?>
                        <?php foreach ($lista as $e): ?>
                            <div class="d-flex gap-2 align-items-start mb-2">
                                <span class="rounded-pill flex-shrink-0" style="width:4px;align-self:stretch;background:<?= core_e($e['cor'] ?: 'var(--portal-primary)') ?>"></span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="small fw-semibold text-break"><?= core_e($e['titulo']) ?></div>
                                    <div class="text-muted" style="font-size:.78rem">
                                        <?php if ((int) $e['dia_inteiro']): ?>
                                            Dia inteiro
                                        <?php else: ?>
                                            <?= date('H:i', (int) strtotime($e['inicio'])) ?>–<?= date('H:i', (int) strtotime($e['fim'])) ?>
                                            <?php if (date('Y-m-d', (int) strtotime($e['fim'])) !== date('Y-m-d', (int) strtotime($e['inicio']))): ?>
                                                <span title="Termina no dia seguinte">+1</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($e['local']): ?> · <?= core_e($e['local']) ?><?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($podeMexer): ?>
                                <div class="d-flex flex-column gap-1">
                                    <a class="btn btn-sm btn-outline-secondary py-0 px-1" title="Editar" aria-label="Editar compromisso"
                                       href="<?= $urlEditar((int) $e['id']) ?>"><i class="bi bi-pencil"></i></a>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endfor; ?>
        </div>

        <?php elseif ($vista === 'mes'): ?>
        <?php
        // Grade do mês: linhas de 7 dias, de segunda a domingo.
        $mesAtual = (int) date('n', $baseTs);
        $cursor   = $gradeIni;
        ?>
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-bordered mb-0 agenda-mes">
                    <thead>
                        <tr>
                            <?php foreach ($iniSemana as $sig): ?><th class="text-center small"><?= $sig ?></th><?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($cursor <= $gradeFim): ?>
                        <tr>
                            <?php for ($c = 0; $c < 7; $c++):
                                $dia = date('Y-m-d', $cursor);
                                $lista = $porDia[$dia] ?? [];
                                $eHoje = $dia === $hoje;
                                $foraMes = ((int) date('n', $cursor) !== $mesAtual);
                                $fds = ((int) date('N', $cursor) >= 6); ?>
                                <td class="agenda-cel <?= $foraMes ? 'agenda-fora' : '' ?> <?= $fds ? 'agenda-fds' : '' ?> <?= $eHoje ? 'agenda-hoje' : '' ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <a class="agenda-dianum text-decoration-none <?= $eHoje ? 'fw-bold' : '' ?>"
                                           href="<?= $urlRef('semana', $cursor) ?>" title="Ver a semana"><?= (int) date('j', $cursor) ?></a>
                                        <?php if ($podeMexer && !$foraMes): ?>
                                            <a class="agenda-add" title="Novo compromisso neste dia"
                                               href="<?= core_module_url('meu', ['page' => 'agenda', 'vista' => 'mes', 'ref' => $refAtual, 'dia' => $dia]) ?>#form-compromisso"><i class="bi bi-plus"></i></a>
                                        <?php endif; ?>
                                    </div>
                                    <?php foreach (array_slice($lista, 0, 3) as $e): ?>
                                        <a class="agenda-chip d-block text-truncate text-decoration-none <?= $podeMexer ? '' : 'pe-none' ?>"
                                           style="--chip:<?= core_e($e['cor'] ?: 'var(--portal-primary)') ?>"
                                           href="<?= $podeMexer ? $urlEditar((int) $e['id']) : '#' ?>"
                                           title="<?= core_e($e['titulo']) ?>">
                                            <?php if (!(int) $e['dia_inteiro']): ?><span class="agenda-hora"><?= date('H:i', (int) strtotime($e['inicio'])) ?></span> <?php endif; ?>
                                            <?= core_e($e['titulo']) ?>
                                        </a>
                                    <?php endforeach; ?>
                                    <?php if (count($lista) > 3): ?>
                                        <a class="agenda-mais small text-decoration-none" href="<?= $urlRef('semana', $cursor) ?>">+<?= count($lista) - 3 ?> mais</a>
                                    <?php endif; ?>
                                </td>
                            <?php $cursor = (int) strtotime('+1 day', $cursor); endfor; ?>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php else: // ano ?>
        <div class="row g-3">
            <?php for ($m = 1; $m <= 12; $m++):
                $mTs = (int) strtotime(sprintf('%04d-%02d-01', $ano, $m));
                $mIni = (int) strtotime('monday this week', $mTs);
                $mFim = (int) strtotime('sunday this week', (int) strtotime(date('Y-m-t', $mTs)));
                $diasMes = (int) date('t', $mTs);
                $temNoMes = 0;
                for ($dd = 1; $dd <= $diasMes; $dd++) { if (!empty($porDia[sprintf('%04d-%02d-%02d', $ano, $m, $dd)])) { $temNoMes++; } }
            ?>
            <div class="col-12 col-sm-6 col-lg-4 col-xxl-3">
                <div class="card h-100">
                    <div class="card-header py-2 d-flex justify-content-between align-items-center">
                        <a class="fw-semibold text-decoration-none" href="<?= $urlRef('mes', $mTs) ?>"><?= $mesesNome[$m] ?></a>
                        <?php if ($temNoMes): ?><span class="badge bg-primary rounded-pill"><?= $temNoMes ?></span><?php endif; ?>
                    </div>
                    <div class="card-body p-2">
                        <table class="agenda-mini w-100">
                            <thead><tr><?php foreach ($iniSemana as $sig): ?><th><?= mb_substr($sig, 0, 1) ?></th><?php endforeach; ?></tr></thead>
                            <tbody>
                                <?php $cur = $mIni; while ($cur <= $mFim): ?>
                                <tr>
                                    <?php for ($c = 0; $c < 7; $c++):
                                        $dd = date('Y-m-d', $cur);
                                        $noMes = ((int) date('n', $cur) === $m);
                                        $tem = $noMes && !empty($porDia[$dd]);
                                        $eHoje = $dd === $hoje; ?>
                                        <td class="<?= $noMes ? '' : 'text-muted opacity-25' ?> <?= $eHoje ? 'agenda-mini-hoje' : '' ?>">
                                            <?php if ($tem): ?>
                                                <a href="<?= $urlRef('semana', $cur) ?>" class="agenda-mini-ev text-decoration-none" title="<?= count($porDia[$dd]) ?> compromisso(s)"><?= (int) date('j', $cur) ?></a>
                                            <?php else: ?>
                                                <?= (int) date('j', $cur) ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php $cur = (int) strtotime('+1 day', $cur); endfor; ?>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($podeMexer): ?>
    <div class="col-12 col-xl-4">
        <div class="card">
            <div class="card-header"><?= $editando ? 'Editar compromisso' : 'Novo compromisso' ?></div>
            <div class="card-body">
                <?php // Data padrão do novo compromisso: o dia clicado, senão a
                      // âncora do período exibido (dia 1 do mês / segunda da semana
                      // / 1º de janeiro do ano), e não "hoje" — que cairia fora do
                      // mês exibido e sumiria da grade.
                      $diaNovoPadrao = (isset($_GET['dia']) && strtotime((string) $_GET['dia']))
                        ? date('Y-m-d', (int) strtotime((string) $_GET['dia'])) : $refAtual; ?>
                <form method="post" id="form-compromisso">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="op" value="<?= $editando ? 'editar' : 'criar' ?>">
                    <input type="hidden" name="id" value="<?= (int) ($editando['id'] ?? 0) ?>">
                    <input type="hidden" name="vista" value="<?= core_e($vista) ?>">
                    <input type="hidden" name="ref" value="<?= core_e($refAtual) ?>">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="e_titulo">Título</label>
                        <input class="form-control form-control-sm" id="e_titulo" name="titulo" maxlength="200" required
                               value="<?= core_e($editando['titulo'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="e_dia">Dia</label>
                        <input type="date" class="form-control form-control-sm" id="e_dia" name="dia" required
                               value="<?= core_e($editando ? date('Y-m-d', (int) strtotime($editando['inicio'])) : $diaNovoPadrao) ?>">
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" name="dia_inteiro" id="e_inteiro" value="1"
                               <?= (int) ($editando['dia_inteiro'] ?? 0) ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="e_inteiro">Dia inteiro</label>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="e_hi">Início</label>
                            <input type="time" class="form-control form-control-sm" id="e_hi" name="hora_inicio"
                                   value="<?= core_e($editando ? date('H:i', (int) strtotime($editando['inicio'])) : '08:00') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="e_hf">Fim</label>
                            <input type="time" class="form-control form-control-sm" id="e_hf" name="hora_fim"
                                   value="<?= core_e($editando ? date('H:i', (int) strtotime($editando['fim'])) : '09:00') ?>">
                            <div class="form-text small">Fim antes do início vira o dia seguinte (plantão noturno).</div>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="e_local">Local (opcional)</label>
                        <input class="form-control form-control-sm" id="e_local" name="local" maxlength="200"
                               value="<?= core_e($editando['local'] ?? '') ?>">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="e_cor">Cor</label>
                            <input type="color" class="form-control form-control-color form-control-sm w-100" id="e_cor" name="cor"
                                   value="<?= core_e(($editando['cor'] ?? '') ?: '#0d5c8f') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold" for="e_lemb">Lembrete</label>
                            <select class="form-select form-select-sm" id="e_lemb" name="lembrete_min">
                                <?php foreach ([0 => 'Sem lembrete', 10 => '10 min antes', 30 => '30 min antes',
                                                60 => '1 hora antes', 1440 => '1 dia antes'] as $k => $lbl): ?>
                                    <option value="<?= $k ?>" <?= (int) ($editando['lembrete_min'] ?? 0) === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="e_desc">Observação</label>
                        <textarea class="form-control form-control-sm" id="e_desc" name="descricao" rows="2"><?= core_e($editando['descricao'] ?? '') ?></textarea>
                    </div>
                    <button class="btn btn-primary btn-sm w-100"><?= $editando ? 'Salvar' : 'Adicionar' ?></button>
                </form>
                <?php if ($editando): ?>
                    <form method="post" class="mt-2">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="op" value="excluir">
                        <input type="hidden" name="id" value="<?= (int) $editando['id'] ?>">
                        <input type="hidden" name="vista" value="<?= core_e($vista) ?>">
                        <input type="hidden" name="ref" value="<?= core_e($refAtual) ?>">
                        <button class="btn btn-outline-danger btn-sm w-100"
                                data-confirm="Excluir &quot;<?= core_e($editando['titulo']) ?>&quot;?">
                            <i class="bi bi-trash me-1"></i>Excluir
                        </button>
                    </form>
                    <a class="btn btn-link btn-sm w-100" href="<?= core_module_url('meu', ['page' => 'agenda', 'vista' => $vista, 'ref' => $refAtual]) ?>">Cancelar edição</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
/* Vista mensal */
.agenda-mes { table-layout: fixed; }
.agenda-mes th { background: var(--bs-tertiary-bg, #f6f7f9); color: #6c757d; font-weight: 600; }
.agenda-cel { height: 92px; vertical-align: top; padding: .25rem .3rem !important; overflow: hidden; }
.agenda-cel.agenda-fora { background: var(--bs-tertiary-bg, #f6f7f9); }
.agenda-cel.agenda-fds { background: #fafbfc; }
.agenda-cel.agenda-hoje { outline: 2px solid var(--portal-primary, #0d6efd); outline-offset: -2px; }
.agenda-dianum { font-size: .82rem; color: inherit; }
.agenda-fora .agenda-dianum { color: #adb5bd; }
.agenda-add { color: #adb5bd; font-size: .8rem; line-height: 1; opacity: 0; transition: opacity .12s; }
.agenda-cel:hover .agenda-add { opacity: 1; }
/* Em toque não há hover persistente: o "+" ficaria invisível e a interação de
   clicar o dia para criar sumiria. Deixa sempre visível onde não há hover. */
@media (hover: none) { .agenda-add { opacity: .6; } }
.agenda-chip { font-size: .72rem; line-height: 1.35; padding: 0 .3rem; margin-top: 2px; border-radius: .25rem;
    background: color-mix(in srgb, var(--chip, #0d6efd) 16%, transparent); color: #1a1a1a; border-left: 3px solid var(--chip, #0d6efd); }
.agenda-chip .agenda-hora { font-weight: 600; opacity: .75; }
.agenda-mais { color: #6c757d; font-size: .7rem; }
/* Vista anual (mini-mês) */
.agenda-mini { border-collapse: collapse; font-size: .68rem; }
.agenda-mini th, .agenda-mini td { text-align: center; padding: 1px 0; width: 14.28%; color: #495057; }
.agenda-mini th { color: #adb5bd; font-weight: 600; }
.agenda-mini-ev { display: inline-block; min-width: 1.15rem; border-radius: 50%; background: var(--portal-primary, #0d6efd); color: #fff !important; font-weight: 700; }
.agenda-mini-hoje { outline: 1px solid var(--portal-primary, #0d6efd); border-radius: 50%; }
</style>
<?php
Layout::render(['title' => 'Agenda', 'content' => (string) ob_get_clean(), 'active' => 'agenda']);
