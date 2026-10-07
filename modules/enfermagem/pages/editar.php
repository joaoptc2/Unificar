<?php
/** ENFERMAGEM — cadastro/edição de uma cirurgia sob vigilância. */

declare(strict_types=1);

use Core\Csrf;
use Core\DB;
use Core\Flash;
use Core\Layout;

core_require('ccih.manage');

$id = (int) ($_GET['id'] ?? 0);
$cir = $id > 0 ? DB::queryOne('SELECT * FROM enf_cirurgias WHERE id = ?', [$id]) : null;
if ($id > 0 && $cir === null) {
    Layout::renderError(404, 'Cirurgia não encontrada.');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();

    $data = trim((string) ($_POST['data_cirurgia'] ?? ''));
    $data = ($data !== '' && strtotime($data)) ? date('Y-m-d', (int) strtotime($data)) : '';
    $medico   = mb_substr(trim((string) ($_POST['medico'] ?? '')), 0, 160);
    $paciente = mb_substr(trim((string) ($_POST['paciente'] ?? '')), 0, 200);
    $celular  = mb_substr(enf_celular((string) ($_POST['celular'] ?? '')), 0, 20);
    $email    = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 190);
    $proc     = mb_substr(trim((string) ($_POST['procedimento'] ?? '')), 0, 200);
    $protese  = !empty($_POST['protese']) ? 1 : 0;
    $prazo    = enf_prazo_dias($protese === 1);

    $erros = [];
    if ($data === '')      { $erros[] = 'Informe a data da cirurgia.'; }
    if ($paciente === '')  { $erros[] = 'Informe o nome do paciente.'; }
    if ($medico === '')    { $erros[] = 'Informe o médico.'; }
    if ($proc === '')      { $erros[] = 'Escolha o procedimento.'; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'E-mail inválido.';
    }

    if ($erros) {
        Flash::set('error', implode(' ', $erros));
        core_redirect(core_module_url('enfermagem', ['page' => 'editar'] + ($id ? ['id' => $id] : [])));
    }

    if ($id > 0) {
        DB::execute(
            'UPDATE enf_cirurgias SET data_cirurgia=?, medico=?, paciente=?, celular=?, email=?,
                    procedimento=?, protese=?, prazo_dias=? WHERE id=?',
            [$data, $medico, $paciente, $celular ?: null, $email ?: null, $proc, $protese, $prazo, $id]
        );
        Flash::set('success', 'Cirurgia atualizada.');
    } else {
        DB::execute(
            'INSERT INTO enf_cirurgias (data_cirurgia, medico, paciente, celular, email, procedimento,
                    protese, prazo_dias, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
            [$data, $medico, $paciente, $celular ?: null, $email ?: null, $proc, $protese, $prazo, enf_uid()]
        );
        $id = DB::lastId();
        Flash::set('success', 'Cirurgia cadastrada. Registre as ligações quando chegar o prazo.');
    }
    // Médico digitado que não está na lista vira sugestão para as próximas.
    if ($medico !== '') {
        DB::execute('INSERT IGNORE INTO enf_medicos (nome) VALUES (?)', [$medico]);
    }
    core_redirect(core_module_url('enfermagem', ['page' => 'paciente', 'id' => $id]));
}

$procs   = enf_procedimentos();
$medicos = enf_medicos();
$v = fn (string $k) => core_e((string) ($cir[$k] ?? ''));

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-heart-pulse me-2"></i><?= $id ? 'Editar cirurgia' : 'Nova cirurgia' ?></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= core_module_url('enfermagem', ['page' => 'pacientes']) ?>">
        <i class="bi bi-arrow-left me-1"></i>Voltar
    </a>
</div>

<div class="card">
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= Csrf::field() ?>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold" for="f_data">Data da cirurgia *</label>
                <input type="date" class="form-control" id="f_data" name="data_cirurgia" required
                       value="<?= $v('data_cirurgia') ?>">
            </div>
            <div class="col-12 col-md-5">
                <label class="form-label small fw-semibold" for="f_pac">Paciente *</label>
                <input class="form-control" id="f_pac" name="paciente" maxlength="200" required value="<?= $v('paciente') ?>">
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label small fw-semibold" for="f_med">Médico *</label>
                <input class="form-control" id="f_med" name="medico" list="lst_medicos" maxlength="160" required value="<?= $v('medico') ?>">
                <datalist id="lst_medicos">
                    <?php foreach ($medicos as $m): ?><option value="<?= core_e($m) ?>"><?php endforeach; ?>
                </datalist>
            </div>

            <div class="col-6 col-md-3">
                <label class="form-label small fw-semibold" for="f_cel">Celular (com DDD)</label>
                <input class="form-control" id="f_cel" name="celular" inputmode="numeric" maxlength="20"
                       placeholder="31999990000" value="<?= $v('celular') ?>">
            </div>
            <div class="col-6 col-md-4">
                <label class="form-label small fw-semibold" for="f_email">E-mail</label>
                <input type="email" class="form-control" id="f_email" name="email" maxlength="190" value="<?= $v('email') ?>">
            </div>
            <div class="col-12 col-md-5">
                <label class="form-label small fw-semibold" for="f_proc">Procedimento *</label>
                <select class="form-select" id="f_proc" name="procedimento" required>
                    <option value="">— escolha —</option>
                    <?php foreach ($procs as $p): ?>
                        <option value="<?= core_e($p['nome']) ?>" data-protese="<?= (int) $p['protese_padrao'] ?>"
                            <?= ($cir['procedimento'] ?? '') === $p['nome'] ? 'selected' : '' ?>>
                            <?= core_e($p['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="f_protese" name="protese" value="1"
                           <?= (int) ($cir['protese'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="f_protese">
                        Colocou <strong>prótese/implante</strong>?
                        <span class="text-muted small">— com prótese, a vigilância passa a ser de 90 dias (regra ANVISA), com ligações extras aos 60 e 90 dias.</span>
                    </label>
                </div>
            </div>

            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= $id ? 'Salvar' : 'Cadastrar' ?></button>
                <a class="btn btn-link" href="<?= core_module_url('enfermagem', ['page' => $id ? 'paciente' : 'pacientes'] + ($id ? ['id' => $id] : [])) ?>">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<script>
// Ao escolher um procedimento com prótese, já marca o interruptor (o usuário
// pode desmarcar). Não decide sozinho no envio: quem manda é o interruptor.
(function () {
    var proc = document.getElementById('f_proc'), prot = document.getElementById('f_protese');
    if (!proc || !prot) return;
    proc.addEventListener('change', function () {
        var opt = proc.options[proc.selectedIndex];
        if (opt && opt.dataset.protese === '1') { prot.checked = true; }
    });
})();
</script>
<?php
Layout::render(['title' => $id ? 'Editar cirurgia' : 'Nova cirurgia', 'content' => (string) ob_get_clean(), 'active' => 'pacientes']);
