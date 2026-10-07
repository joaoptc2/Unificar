<?php
/** ENFERMAGEM — listas dos menus (procedimentos e médicos). */

declare(strict_types=1);

use Core\Csrf;
use Core\DB;
use Core\Flash;
use Core\Layout;

core_require('ccih.config');

$voltar = core_module_url('enfermagem', ['page' => 'config']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $op = (string) ($_POST['op'] ?? '');

    if ($op === 'proc_add') {
        $nome = mb_substr(trim((string) ($_POST['nome'] ?? '')), 0, 200);
        if ($nome !== '') {
            $protese = !empty($_POST['protese']) ? 1 : 0;
            $prazo   = $protese ? 90 : 30;
            // Ordem calculada em consulta separada: um subquery sobre a própria
            // tabela dentro do INSERT é recusado pelo MySQL 8 (erro 1093).
            $ord = DB::queryOne('SELECT COALESCE(MAX(ordem),0)+1 AS n FROM enf_procedimentos');
            DB::execute(
                'INSERT INTO enf_procedimentos (nome, protese_padrao, prazo_padrao, ordem)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE protese_padrao=VALUES(protese_padrao), prazo_padrao=VALUES(prazo_padrao), ativo=1',
                [$nome, $protese, $prazo, (int) ($ord['n'] ?? 1)]
            );
            Flash::set('success', 'Procedimento salvo.');
        }
    } elseif ($op === 'proc_toggle') {
        DB::execute('UPDATE enf_procedimentos SET ativo = 1 - ativo WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
    } elseif ($op === 'proc_del') {
        DB::execute('DELETE FROM enf_procedimentos WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        Flash::set('success', 'Procedimento removido.');
    } elseif ($op === 'med_add') {
        $nome = mb_substr(trim((string) ($_POST['nome'] ?? '')), 0, 160);
        if ($nome !== '') {
            DB::execute('INSERT IGNORE INTO enf_medicos (nome) VALUES (?)', [$nome]);
            DB::execute('UPDATE enf_medicos SET ativo = 1 WHERE nome = ?', [$nome]);
            Flash::set('success', 'Médico salvo.');
        }
    } elseif ($op === 'med_toggle') {
        DB::execute('UPDATE enf_medicos SET ativo = 1 - ativo WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
    } elseif ($op === 'med_del') {
        DB::execute('DELETE FROM enf_medicos WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        Flash::set('success', 'Médico removido.');
    }
    core_redirect($voltar);
}

$procs = enf_procedimentos(false);
$meds  = DB::query('SELECT * FROM enf_medicos ORDER BY ordem, nome');

ob_start(); ?>
<h1 class="h4 mb-3"><i class="bi bi-list-ul me-2"></i>Listas</h1>
<p class="text-muted">As opções que aparecem nos menus do cadastro. Procedimentos com prótese entram com 90 dias de vigilância (ANVISA).</p>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header">Procedimentos</div>
            <div class="card-body">
                <form method="post" class="row g-2 align-items-end mb-3">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="op" value="proc_add">
                    <div class="col-12 col-md-7">
                        <label class="form-label small mb-1">Novo procedimento</label>
                        <input class="form-control form-control-sm" name="nome" maxlength="200" required>
                    </div>
                    <div class="col-7 col-md-3">
                        <div class="form-check form-switch mt-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="p_prot" name="protese" value="1">
                            <label class="form-check-label small" for="p_prot">Prótese (90d)</label>
                        </div>
                    </div>
                    <div class="col-5 col-md-2"><button class="btn btn-primary btn-sm w-100">Adicionar</button></div>
                </form>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                            <?php foreach ($procs as $p): ?>
                            <tr class="<?= (int) $p['ativo'] === 1 ? '' : 'text-muted' ?>">
                                <td><?= core_e($p['nome']) ?>
                                    <?php if ((int) $p['protese_padrao'] === 1): ?><span class="badge text-bg-dark ms-1">90d</span><?php endif; ?>
                                    <?php if ((int) $p['ativo'] === 0): ?><span class="badge text-bg-light border ms-1">inativo</span><?php endif; ?>
                                </td>
                                <td class="text-end" style="white-space:nowrap">
                                    <form method="post" class="d-inline">
                                        <?= Csrf::field() ?><input type="hidden" name="op" value="proc_toggle"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary py-0 px-1" title="Ativar/inativar"><i class="bi bi-<?= (int) $p['ativo'] === 1 ? 'toggle-on' : 'toggle-off' ?>"></i></button>
                                    </form>
                                    <form method="post" class="d-inline">
                                        <?= Csrf::field() ?><input type="hidden" name="op" value="proc_del"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger py-0 px-1" data-confirm="Remover &quot;<?= core_e($p['nome']) ?>&quot;?"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header">Médicos</div>
            <div class="card-body">
                <form method="post" class="row g-2 align-items-end mb-3">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="op" value="med_add">
                    <div class="col-8"><label class="form-label small mb-1">Novo médico</label><input class="form-control form-control-sm" name="nome" maxlength="160" required></div>
                    <div class="col-4"><button class="btn btn-primary btn-sm w-100">Adicionar</button></div>
                </form>
                <?php if (!$meds): ?>
                    <p class="text-muted small mb-0">Nenhum médico cadastrado. Os nomes digitados no cadastro de cirurgias também viram sugestões.</p>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($meds as $m): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center <?= (int) $m['ativo'] === 1 ? '' : 'text-muted' ?>">
                        <span><?= core_e($m['nome']) ?><?php if ((int) $m['ativo'] === 0): ?> <span class="badge text-bg-light border">inativo</span><?php endif; ?></span>
                        <span style="white-space:nowrap">
                            <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="med_toggle"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="btn btn-sm btn-outline-secondary py-0 px-1"><i class="bi bi-<?= (int) $m['ativo'] === 1 ? 'toggle-on' : 'toggle-off' ?>"></i></button></form>
                            <form method="post" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="op" value="med_del"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="btn btn-sm btn-outline-danger py-0 px-1" data-confirm="Remover &quot;<?= core_e($m['nome']) ?>&quot;?"><i class="bi bi-trash"></i></button></form>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
Layout::render(['title' => 'Listas', 'content' => (string) ob_get_clean(), 'active' => 'config']);
