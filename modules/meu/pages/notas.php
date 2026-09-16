<?php
/** MEU ESPAÇO — bloco de notas. */

declare(strict_types=1);

use Core\Csrf;
use Core\DB;
use Core\Flash;
use Core\HtmlSanitizer;
use Core\Layout;

core_require('notas.view');

$uid = meu_uid();
// Paleta fixa: cor livre por nota vira arco-íris e some com a legibilidade.
// Estes seis tons têm contraste conferido contra o texto padrão.
const MEU_NOTA_CORES = ['', '#fff8c5', '#dcffe4', '#dbeafe', '#fae8ff', '#ffe4e6'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    core_require('notas.manage');
    Csrf::check();
    $op = (string) ($_POST['op'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($op === 'criar' || $op === 'editar') {
        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        // A nota passa pelo mesmo filtro dos comunicados: o conteúdo é do
        // próprio dono, mas colar de um e-mail ou de um site traz junto o
        // que veio.
        $conteudo = HtmlSanitizer::clean(mb_substr((string) ($_POST['conteudo'] ?? ''), 0, 200000));
        if ($titulo === '' && trim(strip_tags($conteudo)) === '') {
            Flash::set('error', 'A nota está vazia.');
            core_redirect(core_module_url('meu', ['page' => 'notas']));
        }
        $cor = in_array($_POST['cor'] ?? '', MEU_NOTA_CORES, true) ? (string) $_POST['cor'] : '';

        if ($op === 'criar') {
            DB::execute(
                'INSERT INTO meu_notas (user_id, titulo, conteudo, cor) VALUES (?,?,?,?)',
                [$uid, mb_substr($titulo, 0, 200) ?: null, $conteudo, $cor ?: null]
            );
            Flash::set('success', 'Nota criada.');
        } elseif (meu_registro('meu_notas', $id)) {
            DB::execute(
                'UPDATE meu_notas SET titulo = ?, conteudo = ?, cor = ? WHERE id = ? AND user_id = ?',
                [mb_substr($titulo, 0, 200) ?: null, $conteudo, $cor ?: null, $id, $uid]
            );
            Flash::set('success', 'Nota salva.');
        }
    } elseif ($op === 'fixar') {
        DB::execute('UPDATE meu_notas SET fixada = 1 - fixada WHERE id = ? AND user_id = ?', [$id, $uid]);
    } elseif ($op === 'arquivar') {
        DB::execute('UPDATE meu_notas SET arquivada = 1 - arquivada WHERE id = ? AND user_id = ?', [$id, $uid]);
    } elseif ($op === 'excluir') {
        if (meu_excluir('meu_notas', $id)) {
            Flash::set('success', 'Nota excluída.');
        }
    }
    core_redirect(core_module_url('meu', ['page' => 'notas'] + (!empty($_POST['voltar_arquivadas']) ? ['arquivadas' => 1] : [])));
}

$verArquivadas = !empty($_GET['arquivadas']);
$notas    = meu_notas($verArquivadas);
$editando = isset($_GET['editar']) ? meu_registro('meu_notas', (int) $_GET['editar']) : null;
$podeMexer = core_can('notas.manage');
$nArquivadas = (int) (DB::queryOne('SELECT COUNT(*) c FROM meu_notas WHERE user_id = ? AND arquivada = 1', [$uid])['c'] ?? 0);

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-journal-text me-2"></i><?= $verArquivadas ? 'Notas arquivadas' : 'Notas' ?></h1>
    <div class="d-flex gap-2">
        <?php if ($verArquivadas): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= core_module_url('meu', ['page' => 'notas']) ?>">
                <i class="bi bi-arrow-left me-1"></i>Voltar às notas
            </a>
        <?php elseif ($nArquivadas > 0): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= core_module_url('meu', ['page' => 'notas', 'arquivadas' => 1]) ?>">
                <i class="bi bi-archive me-1"></i>Arquivadas (<?= $nArquivadas ?>)
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($podeMexer && !$verArquivadas): ?>
<div class="card mb-3">
    <div class="card-header"><?= $editando ? 'Editar nota' : 'Nova nota' ?></div>
    <div class="card-body">
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="op" value="<?= $editando ? 'editar' : 'criar' ?>">
            <input type="hidden" name="id" value="<?= (int) ($editando['id'] ?? 0) ?>">
            <div class="mb-2">
                <label class="form-label small fw-semibold" for="n_titulo">Título (opcional)</label>
                <input class="form-control" id="n_titulo" name="titulo" maxlength="200" value="<?= core_e($editando['titulo'] ?? '') ?>">
            </div>
            <div class="mb-2">
                <label class="form-label small fw-semibold" for="n_conteudo">Conteúdo</label>
                <textarea class="form-control" id="n_conteudo" name="conteudo" rows="5"><?= core_e($editando['conteudo'] ?? '') ?></textarea>
                <div class="form-text small">Aceita negrito, listas e links.</div>
            </div>
            <div class="mb-3">
                <span class="form-label small fw-semibold d-block">Cor</span>
                <?php foreach (MEU_NOTA_CORES as $i => $c): ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="cor" id="n_cor<?= $i ?>" value="<?= $c ?>"
                               <?= ($editando['cor'] ?? '') === $c ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="n_cor<?= $i ?>">
                            <span class="d-inline-block border rounded" style="width:20px;height:20px;vertical-align:-4px;background:<?= $c ?: 'var(--portal-surface)' ?>"></span>
                            <span class="visually-hidden"><?= $c ?: 'sem cor' ?></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
            <button class="btn btn-primary btn-sm"><?= $editando ? 'Salvar' : 'Adicionar' ?></button>
            <?php if ($editando): ?>
                <a class="btn btn-link btn-sm" href="<?= core_module_url('meu', ['page' => 'notas']) ?>">Cancelar</a>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (!$notas): ?>
    <div class="card"><div class="card-body text-center text-muted py-5">
        <i class="bi bi-journal fs-2 d-block mb-2 opacity-50"></i>
        <?= $verArquivadas ? 'Nenhuma nota arquivada.' : 'Nenhuma nota ainda.' ?>
    </div></div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($notas as $n):
        $fundo = $n['cor'] ? 'background:' . core_e($n['cor']) . ';' : ''; ?>
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="card h-100" style="<?= $fundo ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h2 class="h6 fw-semibold mb-1 text-break"><?= core_e($n['titulo'] ?: 'Sem título') ?></h2>
                        <?php if ((int) $n['fixada']): ?><i class="bi bi-pin-angle-fill text-warning" title="Fixada"></i><?php endif; ?>
                    </div>
                    <?php /* Sanitizado na gravação (HtmlSanitizer::clean). */ ?>
                    <div class="small meu-nota-corpo"><?= $n['conteudo'] ?></div>
                </div>
                <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
                    <small class="text-muted"><?= date('d/m/Y H:i', (int) strtotime($n['updated_at'])) ?></small>
                    <?php if ($podeMexer): ?>
                    <div class="d-flex gap-1">
                        <?php if (!$verArquivadas): ?>
                        <a class="btn btn-sm btn-outline-secondary py-0 px-1" title="Editar" aria-label="Editar nota"
                           href="<?= core_module_url('meu', ['page' => 'notas', 'editar' => (int) $n['id']]) ?>"><i class="bi bi-pencil"></i></a>
                        <form method="post" class="d-inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="op" value="fixar">
                            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary py-0 px-1" title="<?= (int) $n['fixada'] ? 'Desafixar' : 'Fixar' ?>"
                                    aria-label="<?= (int) $n['fixada'] ? 'Desafixar' : 'Fixar' ?>"><i class="bi bi-pin-angle"></i></button>
                        </form>
                        <?php endif; ?>
                        <form method="post" class="d-inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="op" value="arquivar">
                            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                            <?php if ($verArquivadas): ?><input type="hidden" name="voltar_arquivadas" value="1"><?php endif; ?>
                            <button class="btn btn-sm btn-outline-secondary py-0 px-1" title="<?= $verArquivadas ? 'Restaurar' : 'Arquivar' ?>"
                                    aria-label="<?= $verArquivadas ? 'Restaurar' : 'Arquivar' ?>"><i class="bi bi-<?= $verArquivadas ? 'arrow-counterclockwise' : 'archive' ?>"></i></button>
                        </form>
                        <form method="post" class="d-inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="op" value="excluir">
                            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                            <?php if ($verArquivadas): ?><input type="hidden" name="voltar_arquivadas" value="1"><?php endif; ?>
                            <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Excluir" aria-label="Excluir"
                                    data-confirm="Excluir esta nota? Não é reversível."><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php
Layout::render(['title' => 'Notas', 'content' => (string) ob_get_clean(), 'active' => 'notas']);
