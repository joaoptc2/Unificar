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
    core_redirect(core_module_url('meu', ['page' => 'agenda', 'semana' => (string) ($_POST['semana'] ?? '')]));
}

// Semana exibida: a âncora é sempre uma segunda-feira.
$ancora  = (string) ($_GET['semana'] ?? '');
$baseTs  = $ancora && strtotime($ancora) ? (int) strtotime($ancora) : time();
$segunda = (int) strtotime('monday this week', $baseTs);
$domingo = (int) strtotime('+6 days', $segunda);

$eventos = meu_eventos(date('Y-m-d 00:00:00', $segunda), date('Y-m-d 23:59:59', $domingo));

// Distribui cada compromisso por TODOS os dias que ele cobre.
$porDia = [];
for ($i = 0; $i < 7; $i++) {
    $porDia[date('Y-m-d', (int) strtotime("+{$i} days", $segunda))] = [];
}
foreach ($eventos as $e) {
    $d = max((int) strtotime($e['inicio']), $segunda);
    $f = min((int) strtotime($e['fim']), (int) strtotime(date('Y-m-d 23:59:59', $domingo)));
    for ($t = (int) strtotime(date('Y-m-d', $d)); $t <= $f; $t = (int) strtotime('+1 day', $t)) {
        $k = date('Y-m-d', $t);
        if (isset($porDia[$k])) {
            $porDia[$k][] = $e;
        }
    }
}

$editando = isset($_GET['editar']) ? meu_registro('meu_eventos', (int) $_GET['editar']) : null;
$podeMexer = core_can('agenda.manage');
$hoje = date('Y-m-d');
$diasSemana = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];

$urlSemana = fn (int $ts): string => core_module_url('meu', ['page' => 'agenda', 'semana' => date('Y-m-d', $ts)]);

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-calendar3 me-2"></i>Agenda</h1>
    <div class="btn-group btn-group-sm">
        <a class="btn btn-outline-secondary" href="<?= $urlSemana((int) strtotime('-7 days', $segunda)) ?>" aria-label="Semana anterior"><i class="bi bi-chevron-left"></i></a>
        <a class="btn btn-outline-secondary" href="<?= core_module_url('meu', ['page' => 'agenda']) ?>">Esta semana</a>
        <a class="btn btn-outline-secondary" href="<?= $urlSemana((int) strtotime('+7 days', $segunda)) ?>" aria-label="Próxima semana"><i class="bi bi-chevron-right"></i></a>
    </div>
</div>
<p class="text-muted small">
    <?= date('d/m/Y', $segunda) ?> a <?= date('d/m/Y', $domingo) ?>
    · <?= count($eventos) ?> compromisso(s)
</p>

<div class="row g-3">
    <div class="col-12 <?= $podeMexer ? 'col-xl-8' : '' ?>">
        <div class="row g-2">
        <?php foreach ($porDia as $dia => $lista):
            $ts = (int) strtotime($dia);
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
                                       href="<?= core_module_url('meu', ['page' => 'agenda', 'semana' => date('Y-m-d', $segunda), 'editar' => (int) $e['id']]) ?>"><i class="bi bi-pencil"></i></a>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <?php if ($podeMexer): ?>
    <div class="col-12 col-xl-4">
        <div class="card">
            <div class="card-header"><?= $editando ? 'Editar compromisso' : 'Novo compromisso' ?></div>
            <div class="card-body">
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="op" value="<?= $editando ? 'editar' : 'criar' ?>">
                    <input type="hidden" name="id" value="<?= (int) ($editando['id'] ?? 0) ?>">
                    <input type="hidden" name="semana" value="<?= date('Y-m-d', $segunda) ?>">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="e_titulo">Título</label>
                        <input class="form-control form-control-sm" id="e_titulo" name="titulo" maxlength="200" required
                               value="<?= core_e($editando['titulo'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="e_dia">Dia</label>
                        <input type="date" class="form-control form-control-sm" id="e_dia" name="dia" required
                               value="<?= core_e($editando ? date('Y-m-d', (int) strtotime($editando['inicio'])) : date('Y-m-d')) ?>">
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
                        <input type="hidden" name="semana" value="<?= date('Y-m-d', $segunda) ?>">
                        <button class="btn btn-outline-danger btn-sm w-100"
                                data-confirm="Excluir &quot;<?= core_e($editando['titulo']) ?>&quot;?">
                            <i class="bi bi-trash me-1"></i>Excluir
                        </button>
                    </form>
                    <a class="btn btn-link btn-sm w-100" href="<?= core_module_url('meu', ['page' => 'agenda', 'semana' => date('Y-m-d', $segunda)]) ?>">Cancelar edição</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php
Layout::render(['title' => 'Agenda', 'content' => (string) ob_get_clean(), 'active' => 'agenda']);
