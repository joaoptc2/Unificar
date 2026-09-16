<?php
/** MEU ESPAÇO — tarefas pessoais. */

declare(strict_types=1);

use Core\Csrf;
use Core\DB;
use Core\Flash;
use Core\Layout;

core_require('tarefas.view');

$uid = meu_uid();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    core_require('tarefas.manage');
    Csrf::check();
    $op = (string) ($_POST['op'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($op === 'criar' || $op === 'editar') {
        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        if ($titulo === '') {
            Flash::set('error', 'A tarefa precisa de um título.');
            core_redirect(core_module_url('meu', ['page' => 'tarefas']));
        }
        $prazo = trim((string) ($_POST['prazo'] ?? ''));
        // Data inválida vira "sem prazo" em vez de erro: o campo é opcional
        // e um 0000-00-00 no banco atrapalharia a ordenação para sempre.
        $prazo = ($prazo !== '' && strtotime($prazo)) ? date('Y-m-d', (int) strtotime($prazo)) : null;
        $prio  = in_array($_POST['prioridade'] ?? '', ['baixa', 'normal', 'alta'], true)
               ? (string) $_POST['prioridade'] : 'normal';
        $det   = trim((string) ($_POST['detalhe'] ?? ''));

        if ($op === 'criar') {
            DB::execute(
                'INSERT INTO meu_tarefas (user_id, titulo, detalhe, prazo, prioridade) VALUES (?,?,?,?,?)',
                [$uid, mb_substr($titulo, 0, 200), $det !== '' ? $det : null, $prazo, $prio]
            );
            Flash::set('success', 'Tarefa criada.');
        } elseif (meu_registro('meu_tarefas', $id)) {
            DB::execute(
                'UPDATE meu_tarefas SET titulo = ?, detalhe = ?, prazo = ?, prioridade = ?
                  WHERE id = ? AND user_id = ?',
                [mb_substr($titulo, 0, 200), $det !== '' ? $det : null, $prazo, $prio, $id, $uid]
            );
            Flash::set('success', 'Tarefa atualizada.');
        }
    } elseif ($op === 'situacao') {
        $nova = in_array($_POST['situacao'] ?? '', ['aberta', 'fazendo', 'concluida'], true)
              ? (string) $_POST['situacao'] : 'aberta';
        DB::execute(
            'UPDATE meu_tarefas SET situacao = ?, concluida_em = ? WHERE id = ? AND user_id = ?',
            [$nova, $nova === 'concluida' ? date('Y-m-d H:i:s') : null, $id, $uid]
        );
    } elseif ($op === 'excluir') {
        if (meu_excluir('meu_tarefas', $id)) {
            Flash::set('success', 'Tarefa excluída.');
        }
    }
    core_redirect(core_module_url('meu', ['page' => 'tarefas']));
}

$abertas    = meu_tarefas_abertas();
$concluidas = DB::query(
    "SELECT * FROM meu_tarefas WHERE user_id = ? AND situacao = 'concluida'
      ORDER BY concluida_em DESC LIMIT 20",
    [$uid]
);
$editando = isset($_GET['editar']) ? meu_registro('meu_tarefas', (int) $_GET['editar']) : null;
$podeMexer = core_can('tarefas.manage');

$selo = [
    'vencida' => ['text-bg-danger',  'Vencida'],
    'hoje'    => ['text-bg-warning', 'Hoje'],
    'proxima' => ['text-bg-info',    'Em breve'],
];

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-check2-square me-2"></i>Tarefas</h1>
    <span class="text-muted small"><?= count($abertas) ?> em aberto</span>
</div>

<div class="row g-3">
    <?php if ($podeMexer): ?>
    <div class="col-12 col-lg-4 order-lg-2">
        <div class="card">
            <div class="card-header"><?= $editando ? 'Editar tarefa' : 'Nova tarefa' ?></div>
            <div class="card-body">
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="op" value="<?= $editando ? 'editar' : 'criar' ?>">
                    <input type="hidden" name="id" value="<?= (int) ($editando['id'] ?? 0) ?>">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="t_titulo">O que precisa ser feito</label>
                        <input class="form-control" id="t_titulo" name="titulo" maxlength="200" required
                               value="<?= core_e($editando['titulo'] ?? '') ?>" autofocus>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="t_detalhe">Detalhe (opcional)</label>
                        <textarea class="form-control form-control-sm" id="t_detalhe" name="detalhe" rows="2"><?= core_e($editando['detalhe'] ?? '') ?></textarea>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label small fw-semibold" for="t_prazo">Prazo</label>
                            <input type="date" class="form-control form-control-sm" id="t_prazo" name="prazo"
                                   value="<?= core_e($editando['prazo'] ?? '') ?>">
                        </div>
                        <div class="col-5">
                            <label class="form-label small fw-semibold" for="t_prio">Prioridade</label>
                            <select class="form-select form-select-sm" id="t_prio" name="prioridade">
                                <?php foreach (['baixa' => 'Baixa', 'normal' => 'Normal', 'alta' => 'Alta'] as $k => $lbl): ?>
                                    <option value="<?= $k ?>" <?= ($editando['prioridade'] ?? 'normal') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm w-100"><?= $editando ? 'Salvar' : 'Adicionar' ?></button>
                    <?php if ($editando): ?>
                        <a class="btn btn-link btn-sm w-100 mt-1" href="<?= core_module_url('meu', ['page' => 'tarefas']) ?>">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-12 <?= $podeMexer ? 'col-lg-8 order-lg-1' : '' ?>">
        <div class="card">
            <div class="card-header">Em aberto</div>
            <?php if (!$abertas): ?>
                <div class="card-body text-center text-muted py-5">
                    <i class="bi bi-check2-circle fs-2 d-block mb-2 opacity-50"></i>
                    Nada pendente. Aproveite.
                </div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($abertas as $t):
                    $estado = meu_prazo_estado($t['prazo']); ?>
                    <li class="list-group-item d-flex align-items-start gap-2">
                        <?php if ($podeMexer): ?>
                        <form method="post" class="mt-1">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="op" value="situacao">
                            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <input type="hidden" name="situacao" value="concluida">
                            <button class="btn btn-sm btn-outline-secondary py-0 px-1" title="Concluir"
                                    aria-label="Concluir <?= core_e($t['titulo']) ?>"><i class="bi bi-square"></i></button>
                        </form>
                        <?php endif; ?>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold"><?= core_e($t['titulo']) ?>
                                <?php if ($t['prioridade'] === 'alta'): ?>
                                    <i class="bi bi-exclamation-triangle-fill text-danger small" title="Prioridade alta"></i>
                                <?php endif; ?>
                            </div>
                            <?php if ($t['detalhe']): ?>
                                <div class="small text-muted"><?= nl2br(core_e($t['detalhe'])) ?></div>
                            <?php endif; ?>
                            <?php if ($t['prazo']): ?>
                                <span class="badge <?= $selo[$estado][0] ?? 'text-bg-light border' ?> mt-1">
                                    <?= $selo[$estado][1] ?? date('d/m/Y', (int) strtotime($t['prazo'])) ?>
                                    <?php if (isset($selo[$estado])): ?>· <?= date('d/m', (int) strtotime($t['prazo'])) ?><?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($podeMexer): ?>
                        <div class="d-flex gap-1">
                            <a class="btn btn-sm btn-outline-secondary py-0 px-1"
                               href="<?= core_module_url('meu', ['page' => 'tarefas', 'editar' => (int) $t['id']]) ?>"
                               title="Editar" aria-label="Editar"><i class="bi bi-pencil"></i></a>
                            <form method="post" class="d-inline">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="op" value="excluir">
                                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Excluir" aria-label="Excluir"
                                        data-confirm="Excluir a tarefa &quot;<?= core_e($t['titulo']) ?>&quot;?"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <?php if ($concluidas): ?>
        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Concluídas recentemente</span>
                <span class="badge text-bg-light border"><?= count($concluidas) ?></span>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($concluidas as $t): ?>
                    <li class="list-group-item d-flex align-items-center gap-2 py-2">
                        <?php if ($podeMexer): ?>
                        <form method="post">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="op" value="situacao">
                            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <input type="hidden" name="situacao" value="aberta">
                            <button class="btn btn-sm btn-link text-success p-0" title="Reabrir"
                                    aria-label="Reabrir <?= core_e($t['titulo']) ?>"><i class="bi bi-check-square-fill"></i></button>
                        </form>
                        <?php endif; ?>
                        <span class="flex-grow-1 text-muted text-decoration-line-through small"><?= core_e($t['titulo']) ?></span>
                        <small class="text-muted"><?= $t['concluida_em'] ? date('d/m H:i', (int) strtotime($t['concluida_em'])) : '' ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php
Layout::render(['title' => 'Tarefas', 'content' => (string) ob_get_clean(), 'active' => 'tarefas']);
