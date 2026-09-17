<?php
/**
 * MEU ESPAÇO — preencher o formulário de outra pessoa.
 *
 * O envio cria uma solicitação para o dono do formulário e grava as respostas
 * presas a ela. As duas escritas vão numa transação: meia solicitação, sem as
 * respostas, seria um pedido que o dono abre e não entende.
 */

declare(strict_types=1);

use Core\Csrf;
use Core\DB;
use Core\Flash;
use Core\Layout;
use Core\Notifications;

core_require('formularios.view');

$uid = meu_uid();
$eu  = Core\Auth::user();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

// Carrega SEM exigir ser o dono (é justamente o formulário de outra pessoa),
// mas só se estiver ativo e o dono continuar ativo no sistema.
$f = meu_formulario($id, false);
if ($f === null || !(int) $f['ativo']) {
    Layout::renderError(404, 'Formulário não encontrado ou desativado.');
    exit;
}
$dono = meu_usuario_valido((int) $f['user_id']);
if ($dono === null) {
    Layout::renderError(404, 'A pessoa dona deste formulário não está mais ativa.');
    exit;
}
if ((int) $f['user_id'] === $uid) {
    Flash::set('warning', 'Este formulário é seu — para editá-lo, use a tela de formulários.');
    core_redirect(core_module_url('meu', ['page' => 'formularios', 'editar' => $id]));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    core_require('solicitacoes.enviar');
    Csrf::check();

    $respostas = [];
    $faltando  = [];
    foreach ($f['campos'] as $c) {
        $v = meu_valida_resposta($c, $_POST['campo'][$c['id']] ?? null);
        if ($v === null) {
            if ((int) $c['obrigatorio']) {
                $faltando[] = $c['rotulo'];
            }
            continue;
        }
        $respostas[(int) $c['id']] = $v;
    }
    if ($faltando) {
        Flash::set('error', 'Faltou responder: ' . implode('; ', array_slice($faltando, 0, 5))
            . (count($faltando) > 5 ? ' e mais ' . (count($faltando) - 5) : '') . '.');
        core_redirect(core_module_url('meu', ['page' => 'formulario', 'id' => $id]));
    }

    $titulo = trim((string) ($_POST['titulo'] ?? '')) ?: $f['titulo'];
    $novo   = 0;
    DB::transaction(function () use (&$novo, $uid, $f, $titulo, $respostas, $id) {
        DB::execute(
            'INSERT INTO meu_solicitacoes (remetente_id, destinatario_id, formulario_id, titulo, mensagem)
             VALUES (?,?,?,?,?)',
            [$uid, (int) $f['user_id'], $id, mb_substr($titulo, 0, 200),
             trim((string) ($_POST['mensagem'] ?? '')) ?: null]
        );
        $novo = DB::lastId();
        foreach ($respostas as $campoId => [$nota, $texto]) {
            DB::execute(
                'INSERT INTO meu_formulario_respostas (solicitacao_id, campo_id, nota, resposta) VALUES (?,?,?,?)',
                [$novo, $campoId, $nota, $texto]
            );
        }
    });

    Notifications::add((int) $f['user_id'], 'Formulário preenchido por ' . $eu['name'],
        mb_substr((string) $f['titulo'], 0, 160),
        core_module_url('meu', ['page' => 'solicitacoes', 'ver' => $novo]), 'solicitacao', 'meu');
    Flash::set('success', 'Enviado para ' . $dono['name'] . '.');
    core_redirect(core_module_url('meu', ['page' => 'solicitacoes', 'aba' => 'enviadas', 'ver' => $novo]));
}

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-ui-checks me-2"></i><?= core_e($f['titulo']) ?></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= core_module_url('meu', ['page' => 'formularios']) ?>">
        <i class="bi bi-arrow-left me-1"></i>Voltar
    </a>
</div>
<p class="text-muted">
    Formulário de <strong><?= core_e($f['dono_nome']) ?></strong>.
    Ao enviar, vira uma solicitação para essa pessoa.
</p>
<?php if ($f['descricao']): ?>
    <div class="alert alert-light border"><?= nl2br(core_e($f['descricao'])) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="mb-3">
                <label class="form-label small fw-semibold" for="fx_titulo">Resumo do pedido</label>
                <input class="form-control" id="fx_titulo" name="titulo" maxlength="200"
                       value="<?= core_e($f['titulo']) ?>" required>
            </div>

            <?php foreach ($f['campos'] as $c):
                $cid = (int) $c['id'];
                $ops = $c['opcoes'] ? (json_decode((string) $c['opcoes'], true) ?: []) : [];
                $req = (int) $c['obrigatorio'] ? 'required' : ''; ?>
                <div class="mb-3">
                    <label class="form-label small fw-semibold" for="c<?= $cid ?>">
                        <?= core_e($c['rotulo']) ?>
                        <?php if ((int) $c['obrigatorio']): ?><span class="text-danger">*</span><?php endif; ?>
                    </label>

                    <?php if ($c['tipo'] === 'textarea'): ?>
                        <textarea class="form-control" id="c<?= $cid ?>" name="campo[<?= $cid ?>]" rows="3" <?= $req ?>></textarea>

                    <?php elseif ($c['tipo'] === 'choice'): ?>
                        <select class="form-select" id="c<?= $cid ?>" name="campo[<?= $cid ?>]" <?= $req ?>>
                            <option value="">Selecione</option>
                            <?php foreach ($ops as $o): ?><option value="<?= core_e($o) ?>"><?= core_e($o) ?></option><?php endforeach; ?>
                        </select>

                    <?php elseif ($c['tipo'] === 'multiple'): ?>
                        <?php foreach ($ops as $k => $o): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="campo[<?= $cid ?>][]"
                                       id="c<?= $cid ?>_<?= $k ?>" value="<?= core_e($o) ?>">
                                <label class="form-check-label" for="c<?= $cid ?>_<?= $k ?>"><?= core_e($o) ?></label>
                            </div>
                        <?php endforeach; ?>

                    <?php elseif ($c['tipo'] === 'yes_no'): ?>
                        <?php foreach (['sim' => 'Sim', 'nao' => 'Não'] as $v => $lbl): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="campo[<?= $cid ?>]"
                                       id="c<?= $cid ?>_<?= $v ?>" value="<?= $v ?>" <?= $req ?>>
                                <label class="form-check-label" for="c<?= $cid ?>_<?= $v ?>"><?= $lbl ?></label>
                            </div>
                        <?php endforeach; ?>

                    <?php elseif ($c['tipo'] === 'rating'): ?>
                        <select class="form-select" id="c<?= $cid ?>" name="campo[<?= $cid ?>]" <?= $req ?>>
                            <option value="">Selecione</option>
                            <?php for ($n = 1; $n <= 5; $n++): ?><option value="<?= $n ?>"><?= $n ?></option><?php endfor; ?>
                        </select>

                    <?php elseif ($c['tipo'] === 'number'): ?>
                        <input type="number" class="form-control" id="c<?= $cid ?>" name="campo[<?= $cid ?>]" <?= $req ?>>

                    <?php elseif ($c['tipo'] === 'date'): ?>
                        <input type="date" class="form-control" id="c<?= $cid ?>" name="campo[<?= $cid ?>]" <?= $req ?>>

                    <?php else: ?>
                        <input class="form-control" id="c<?= $cid ?>" name="campo[<?= $cid ?>]" maxlength="5000" <?= $req ?>>
                    <?php endif; ?>

                    <?php if ($c['ajuda']): ?><div class="form-text small"><?= core_e($c['ajuda']) ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="mb-3">
                <label class="form-label small fw-semibold" for="fx_msg">Observação (opcional)</label>
                <textarea class="form-control form-control-sm" id="fx_msg" name="mensagem" rows="2"></textarea>
            </div>

            <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Enviar para <?= core_e($f['dono_nome']) ?></button>
        </form>
    </div>
</div>
<?php
Layout::render(['title' => $f['titulo'], 'content' => (string) ob_get_clean(), 'active' => 'formularios']);
