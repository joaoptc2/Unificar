<?php
/**
 * MEU ESPAÇO — meus formulários de solicitação.
 *
 * "Crie o formulário que as pessoas preenchem para pedir algo a você." Todo
 * preenchimento vira uma solicitação — por isso não existe tabela de "envio"
 * separada: a solicitação É o envio.
 */

declare(strict_types=1);

use Core\Csrf;
use Core\DB;
use Core\Flash;
use Core\Layout;

core_require('formularios.view');

$uid = meu_uid();
$url = fn (array $q = []) => core_module_url('meu', ['page' => 'formularios'] + $q);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    core_require('formularios.manage');
    Csrf::check();
    $op = (string) ($_POST['op'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($op === 'salvar') {
        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        if ($titulo === '') {
            Flash::set('error', 'O formulário precisa de um título.');
            core_redirect($url(['editar' => $id ?: null]));
        }
        $desc  = trim((string) ($_POST['descricao'] ?? '')) ?: null;
        $ativo = !empty($_POST['ativo']) ? 1 : 0;

        $existente = $id > 0 ? meu_formulario($id) : null;
        if ($id > 0 && $existente === null) {
            Flash::set('error', 'Formulário não encontrado.');
            core_redirect($url());
        }

        DB::transaction(function () use (&$id, $existente, $titulo, $desc, $ativo, $uid) {
            if ($existente === null) {
                DB::execute('INSERT INTO meu_formularios (user_id, titulo, descricao, ativo) VALUES (?,?,?,?)',
                    [$uid, mb_substr($titulo, 0, 200), $desc, $ativo]);
                $id = DB::lastId();
            } else {
                DB::execute('UPDATE meu_formularios SET titulo = ?, descricao = ?, ativo = ? WHERE id = ? AND user_id = ?',
                    [mb_substr($titulo, 0, 200), $desc, $ativo, $id, $uid]);
            }

            // Campos só são regravados enquanto NINGUÉM respondeu. Depois da
            // primeira resposta eles congelam: apagar um campo apagaria em
            // cascata as respostas que apontam para ele, e o que sobrasse
            // responderia a perguntas que não existem mais.
            if ($existente !== null && meu_formulario_congelado($existente)) {
                return;
            }
            DB::execute('DELETE FROM meu_formulario_campos WHERE formulario_id = ?', [$id]);
            foreach (meu_campos_do_post($_POST) as $c) {
                DB::execute(
                    'INSERT INTO meu_formulario_campos (formulario_id, rotulo, tipo, opcoes, obrigatorio, ajuda, ordem)
                     VALUES (?,?,?,?,?,?,?)',
                    [$id, $c['rotulo'], $c['tipo'], $c['opcoes'], $c['obrigatorio'], $c['ajuda'], $c['ordem']]
                );
            }
        });
        Flash::set('success', 'Formulário salvo.');
        core_redirect($url(['editar' => $id]));
    }

    if ($op === 'excluir') {
        $f = meu_formulario($id);
        if ($f !== null) {
            if (meu_formulario_congelado($f)) {
                Flash::set('error', 'Este formulário já gerou ' . $f['usos'] . ' solicitação(ões). '
                    . 'Desative-o em vez de excluir — excluir levaria junto as respostas.');
                core_redirect($url(['editar' => $id]));
            }
            DB::execute('DELETE FROM meu_formularios WHERE id = ? AND user_id = ?', [$id, $uid]);
            Flash::set('success', 'Formulário excluído.');
        }
        core_redirect($url());
    }
    core_redirect($url());
}

$meus     = DB::query('SELECT f.*, (SELECT COUNT(*) FROM meu_solicitacoes s WHERE s.formulario_id = f.id) usos
                         FROM meu_formularios f WHERE f.user_id = ? ORDER BY f.ativo DESC, f.titulo', [$uid]);
$editando = isset($_GET['editar']) ? meu_formulario((int) $_GET['editar']) : null;
$novo     = isset($_GET['novo']);
$tipos    = meu_tipos_campo();
$podeMexer = core_can('formularios.manage');
$congelado = $editando !== null && meu_formulario_congelado($editando);

// Formulários de OUTRAS pessoas, que eu posso preencher.
$deOutros = DB::query(
    'SELECT f.id, f.titulo, f.descricao, u.name AS dono FROM meu_formularios f
       JOIN users u ON u.id = f.user_id
      WHERE f.ativo = 1 AND f.user_id <> ? AND u.active = 1
      ORDER BY u.name, f.titulo LIMIT 50', [$uid]);

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-ui-checks me-2"></i>Formulários</h1>
    <?php if ($podeMexer && !$editando && !$novo): ?>
        <a class="btn btn-primary btn-sm" href="<?= $url(['novo' => 1]) ?>"><i class="bi bi-plus-lg me-1"></i>Novo formulário</a>
    <?php endif; ?>
</div>

<?php if ($podeMexer && ($editando || $novo)): ?>
<div class="card mb-3">
    <div class="card-header"><?= $editando ? 'Editar formulário' : 'Novo formulário' ?></div>
    <div class="card-body">
        <?php if ($congelado): ?>
            <div class="alert alert-warning py-2 small">
                <i class="bi bi-lock me-1"></i>
                Este formulário já gerou <strong><?= (int) $editando['usos'] ?></strong> solicitação(ões), então
                as <strong>perguntas estão travadas</strong>. Título, descrição e ativo/inativo continuam
                editáveis. Mudar as perguntas agora deixaria as respostas já recebidas apontando para
                perguntas que não existem mais — crie um formulário novo.
            </div>
        <?php endif; ?>
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="op" value="salvar">
            <input type="hidden" name="id" value="<?= (int) ($editando['id'] ?? 0) ?>">
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-8">
                    <label class="form-label small fw-semibold" for="f_titulo">Título</label>
                    <input class="form-control" id="f_titulo" name="titulo" maxlength="200" required
                           value="<?= core_e($editando['titulo'] ?? '') ?>" placeholder="Pedido de material">
                </div>
                <div class="col-12 col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input type="hidden" name="ativo" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" name="ativo" id="f_ativo" value="1"
                               <?= ($editando === null || (int) $editando['ativo']) ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="f_ativo">Ativo (aceita novos pedidos)</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold" for="f_desc">Explicação (opcional)</label>
                    <textarea class="form-control form-control-sm" id="f_desc" name="descricao" rows="2"><?= core_e($editando['descricao'] ?? '') ?></textarea>
                </div>
            </div>

            <h2 class="h6 text-muted text-uppercase">Perguntas</h2>
            <div id="campos">
                <?php
                $campos = $editando['campos'] ?? [];
                if (!$campos) { $campos = [['rotulo' => '', 'tipo' => 'text', 'opcoes' => null, 'obrigatorio' => 0, 'ajuda' => '']]; }
                foreach ($campos as $i => $c):
                    $ops = $c['opcoes'] ? implode("\n", json_decode((string) $c['opcoes'], true) ?: []) : ''; ?>
                    <div class="border rounded p-2 mb-2 campo-item">
                        <div class="row g-2">
                            <div class="col-12 col-md-5">
                                <input class="form-control form-control-sm" name="campos[<?= $i ?>][rotulo]"
                                       value="<?= core_e($c['rotulo']) ?>" placeholder="Pergunta" maxlength="500"
                                       aria-label="Pergunta" <?= $congelado ? 'readonly' : '' ?>>
                            </div>
                            <div class="col-7 col-md-3">
                                <select class="form-select form-select-sm" name="campos[<?= $i ?>][tipo]"
                                        aria-label="Tipo" <?= $congelado ? 'disabled' : '' ?>>
                                    <?php foreach ($tipos as $k => $lbl): ?>
                                        <option value="<?= $k ?>" <?= ($c['tipo'] ?? 'text') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-5 col-md-2 d-flex align-items-center">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="campos[<?= $i ?>][obrigatorio]"
                                           id="ob<?= $i ?>" value="1" <?= (int) ($c['obrigatorio'] ?? 0) ? 'checked' : '' ?>
                                           <?= $congelado ? 'disabled' : '' ?>>
                                    <label class="form-check-label small" for="ob<?= $i ?>">Obrigatória</label>
                                </div>
                            </div>
                            <div class="col-12 col-md-2">
                                <input class="form-control form-control-sm" name="campos[<?= $i ?>][ajuda]"
                                       value="<?= core_e($c['ajuda'] ?? '') ?>" placeholder="Dica" maxlength="255"
                                       aria-label="Dica" <?= $congelado ? 'readonly' : '' ?>>
                            </div>
                            <div class="col-12">
                                <textarea class="form-control form-control-sm" name="campos[<?= $i ?>][opcoes]" rows="2"
                                          placeholder="Uma opção por linha (só para escolha única e múltipla)"
                                          <?= $congelado ? 'readonly' : '' ?>><?= core_e($ops) ?></textarea>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (!$congelado): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="addCampo">
                    <i class="bi bi-plus-lg me-1"></i>Mais uma pergunta
                </button>
            <?php endif; ?>

            <div class="mt-3">
                <button class="btn btn-primary btn-sm">Salvar</button>
                <a class="btn btn-link btn-sm" href="<?= $url() ?>">Voltar</a>
            </div>
        </form>
        <?php if ($editando && !$congelado): ?>
            <form method="post" class="mt-2">
                <?= Csrf::field() ?>
                <input type="hidden" name="op" value="excluir">
                <input type="hidden" name="id" value="<?= (int) $editando['id'] ?>">
                <button class="btn btn-outline-danger btn-sm" data-confirm="Excluir este formulário?">
                    <i class="bi bi-trash me-1"></i>Excluir
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var add = document.getElementById('addCampo');
    if (!add) { return; }
    add.addEventListener('click', function () {
        var caixa = document.getElementById('campos');
        var n = caixa.querySelectorAll('.campo-item').length;
        var molde = caixa.querySelector('.campo-item').cloneNode(true);
        // Renumera os nomes e limpa os valores: clonar sem isto faria o novo
        // campo sobrescrever o primeiro no POST.
        molde.querySelectorAll('[name]').forEach(function (el) {
            el.name = el.name.replace(/campos\[\d+\]/, 'campos[' + n + ']');
            if (el.type === 'checkbox') { el.checked = false; el.id = 'ob' + n; }
            else { el.value = ''; }
        });
        molde.querySelectorAll('label[for]').forEach(function (l) { l.htmlFor = 'ob' + n; });
        caixa.appendChild(molde);
        var primeiro = molde.querySelector('input[name$="[rotulo]"]');
        if (primeiro) { primeiro.focus(); }
    });
});
</script>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">Meus formulários</div>
            <?php if (!$meus): ?>
                <div class="card-body text-muted text-center py-4">
                    Nenhum ainda. Crie um para receber pedidos já organizados.
                </div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($meus as $f): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <div class="min-w-0">
                            <div class="fw-semibold text-break"><?= core_e($f['titulo']) ?>
                                <?php if (!(int) $f['ativo']): ?><span class="badge text-bg-light border ms-1">inativo</span><?php endif; ?>
                            </div>
                            <div class="small text-muted"><?= (int) $f['usos'] ?> solicitação(ões) recebida(s)</div>
                        </div>
                        <?php if ($podeMexer): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= $url(['editar' => (int) $f['id']]) ?>">
                                <i class="bi bi-pencil"></i>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">Formulários de outras pessoas</div>
            <?php if (!$deOutros): ?>
                <div class="card-body text-muted text-center py-4">Ninguém publicou formulários ainda.</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($deOutros as $f): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <div class="min-w-0">
                            <div class="fw-semibold text-break"><?= core_e($f['titulo']) ?></div>
                            <div class="small text-muted"><?= core_e($f['dono']) ?></div>
                        </div>
                        <a class="btn btn-sm btn-outline-primary"
                           href="<?= core_module_url('meu', ['page' => 'formulario', 'id' => (int) $f['id']]) ?>">
                            Preencher
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
Layout::render(['title' => 'Formulários', 'content' => (string) ob_get_clean(), 'active' => 'formularios']);
