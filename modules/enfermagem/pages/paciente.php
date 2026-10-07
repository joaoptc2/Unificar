<?php
/** ENFERMAGEM — ficha de uma cirurgia: ligações, questionário, retornos e CCIH. */

declare(strict_types=1);

use Core\Csrf;
use Core\DB;
use Core\Flash;
use Core\Layout;

core_require('ccih.view');

$id  = (int) ($_GET['id'] ?? 0);
$cir = $id > 0 ? DB::queryOne('SELECT * FROM enf_cirurgias WHERE id = ?', [$id]) : null;
if ($cir === null) {
    Layout::renderError(404, 'Cirurgia não encontrada.');
    exit;
}

$voltar = core_module_url('enfermagem', ['page' => 'paciente', 'id' => $id]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $op = (string) ($_POST['op'] ?? '');

    // Helpers de leitura do POST para os campos Sim/Não/vazio e menus.
    $simNao = static function (string $k): ?int {
        $v = $_POST[$k] ?? '';
        return $v === '1' ? 1 : ($v === '0' ? 0 : null);
    };
    $enum = static function (string $k, array $ok): ?string {
        $v = (string) ($_POST[$k] ?? '');
        return in_array($v, $ok, true) ? $v : null;
    };
    $dataPost = static function (string $k): ?string {
        $v = trim((string) ($_POST[$k] ?? ''));
        return ($v !== '' && strtotime($v)) ? date('Y-m-d', (int) strtotime($v)) : null;
    };

    if ($op === 'tentativa') {
        core_require('ccih.manage');
        $fase = in_array($_POST['fase'] ?? '', ['d30', 'd60', 'd90'], true) ? (string) $_POST['fase'] : 'd30';
        $res  = (string) ($_POST['resultado'] ?? '');
        $data = $dataPost('data') ?? date('Y-m-d');
        if (!isset(enf_resultados()[$res])) {
            Flash::set('error', 'Escolha o resultado da tentativa.');
            core_redirect($voltar);
        }
        DB::execute(
            'INSERT INTO enf_tentativas (cirurgia_id, fase, data, resultado, sucesso, observacao, created_by)
             VALUES (?,?,?,?,?,?,?)',
            [$id, $fase, $data, $res, enf_resultado_sucesso($res) ? 1 : 0,
             mb_substr(trim((string) ($_POST['observacao'] ?? '')), 0, 500) ?: null, enf_uid()]
        );
        Flash::set('success', 'Tentativa registrada.');
        core_redirect($voltar);
    }

    if ($op === 'questionario') {
        core_require('ccih.manage');
        $res = $enum('contato_resultado', ['atendeu', 'whatsapp']) ?? 'atendeu';
        $cd  = $dataPost('contato_data') ?? date('Y-m-d');
        DB::execute(
            'UPDATE enf_cirurgias SET contato_data=?, contato_resultado=?, febre=?, vermelhidao=?, dor=?,
                    secrecao=?, ferida_abriu=?, antibiotico=?, retorno_medico=?, observacoes=? WHERE id=?',
            [
                $cd, $res, $simNao('febre'), $simNao('vermelhidao'), $simNao('dor'),
                $enum('secrecao', ['nao', 'clara', 'pus']), $simNao('ferida_abriu'), $simNao('antibiotico'),
                $enum('retorno_medico', ['nao', 'rotina', 'extra', 'reinternacao', 'reoperacao']),
                mb_substr(trim((string) ($_POST['observacoes'] ?? '')), 0, 5000) ?: null, $id,
            ]
        );
        // Garante uma tentativa de sucesso na fase d30 (para o histórico e a
        // contagem de ligações do relatório), sem duplicar se já houver.
        $temSucesso = DB::queryOne(
            "SELECT id FROM enf_tentativas WHERE cirurgia_id=? AND fase='d30' AND sucesso=1 LIMIT 1", [$id]
        );
        if ($temSucesso === null) {
            DB::execute(
                "INSERT INTO enf_tentativas (cirurgia_id, fase, data, resultado, sucesso, created_by)
                 VALUES (?, 'd30', ?, ?, 1, ?)", [$id, $cd, $res, enf_uid()]
            );
        }
        Flash::set('success', 'Questionário de 30 dias registrado. Confira o alerta.');
        core_redirect($voltar);
    }

    if ($op === 'c60' || $op === 'c90') {
        core_require('ccih.manage');
        $fase = $op === 'c60' ? 'd60' : 'd90';
        $pref = $op === 'c60' ? 'contato60' : 'contato90';
        $res  = $enum('resultado', array_keys(enf_resultados())) ?? 'atendeu';
        $cd   = $dataPost('data') ?? date('Y-m-d');
        $sinais = $simNao('sinais') ?? 0;
        DB::execute(
            "UPDATE enf_cirurgias SET {$pref}_data=?, {$pref}_resultado=?, {$pref}_sinais=? WHERE id=?",
            [$cd, $res, $sinais, $id]
        );
        DB::execute(
            'INSERT INTO enf_tentativas (cirurgia_id, fase, data, resultado, sucesso, created_by) VALUES (?,?,?,?,?,?)',
            [$id, $fase, $cd, $res, enf_resultado_sucesso($res) ? 1 : 0, enf_uid()]
        );
        Flash::set('success', 'Retorno de ' . ($op === 'c60' ? '60' : '90') . ' dias registrado.');
        core_redirect($voltar);
    }

    if ($op === 'ccih') {
        core_require('ccih.classify');
        DB::execute(
            'UPDATE enf_cirurgias SET classificacao=?, data_diagnostico=?, notificado=?, responsavel_ccih=? WHERE id=?',
            [
                $enum('classificacao', array_keys(enf_classificacoes())),
                $dataPost('data_diagnostico'),
                $enum('notificado', ['sim', 'nao', 'na']),
                mb_substr(trim((string) ($_POST['responsavel_ccih'] ?? '')), 0, 160) ?: null,
                $id,
            ]
        );
        Flash::set('success', 'Avaliação da CCIH salva.');
        core_redirect($voltar);
    }

    if ($op === 'excluir') {
        core_require('ccih.manage');
        DB::execute('DELETE FROM enf_cirurgias WHERE id = ?', [$id]);
        Flash::set('success', 'Cirurgia excluída.');
        core_redirect(core_module_url('enfermagem', ['page' => 'pacientes']));
    }

    core_redirect($voltar);
}

$c = enf_derivar($cir);
$podeMexer   = core_can('ccih.manage');
$podeCcih    = core_can('ccih.classify');
$statusMeta  = enf_status_meta();
$alertaMeta  = enf_alerta_meta();
$temContato  = !empty($c['contato_data']);

$selNota = static function (?int $v, string $name, bool $enabled): string {
    $dis = $enabled ? '' : 'disabled';
    $o = static fn ($val, $lbl) => '<option value="' . $val . '"' . ($v === ($val === '' ? null : (int) $val) ? ' selected' : '') . '>' . $lbl . '</option>';
    return '<select class="form-select form-select-sm" name="' . $name . '" ' . $dis . '>'
        . $o('', '—') . $o('1', 'Sim') . $o('0', 'Não') . '</select>';
};

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex align-items-center gap-2 min-w-0">
        <a class="btn btn-outline-secondary btn-sm" href="<?= core_module_url('enfermagem', ['page' => 'pacientes']) ?>"><i class="bi bi-arrow-left"></i></a>
        <h1 class="h4 mb-0 text-truncate"><?= core_e($c['paciente']) ?></h1>
    </div>
    <div class="d-flex gap-2">
        <?php if ($podeMexer): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= core_module_url('enfermagem', ['page' => 'editar', 'id' => $id]) ?>"><i class="bi bi-pencil me-1"></i>Editar</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <!-- Coluna esquerda: identificação + status -->
    <div class="col-12 col-lg-4">
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-3">
                    <span class="badge <?= $statusMeta[$c['_status']][1] ?>"><?= $statusMeta[$c['_status']][0] ?></span>
                    <span class="badge <?= $alertaMeta[$c['_alerta']][1] ?>"><?= $alertaMeta[$c['_alerta']][0] ?></span>
                    <?php if ($c['_protese']): ?><span class="badge text-bg-dark"><i class="bi bi-shield-plus me-1"></i>Prótese · 90 dias</span><?php endif; ?>
                </div>
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Cirurgia</dt><dd class="col-7"><?= enf_data_br($c['data_cirurgia']) ?></dd>
                    <dt class="col-5 text-muted">Procedimento</dt><dd class="col-7"><?= core_e($c['procedimento']) ?></dd>
                    <dt class="col-5 text-muted">Médico</dt><dd class="col-7"><?= core_e($c['medico']) ?></dd>
                    <dt class="col-5 text-muted">Celular</dt><dd class="col-7">
                        <?php if ($c['celular']): ?><a href="tel:<?= core_e($c['celular']) ?>"><?= core_e($c['celular']) ?></a><?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </dd>
                    <dt class="col-5 text-muted">E-mail</dt><dd class="col-7 text-truncate"><?= $c['email'] ? core_e($c['email']) : '<span class="text-muted">—</span>' ?></dd>
                    <dt class="col-5 text-muted">Ligar a partir de</dt><dd class="col-7"><?= enf_data_br($c['_ligar_a_partir']) ?></dd>
                    <dt class="col-5 text-muted">Vigiar até</dt><dd class="col-7"><?= enf_data_br($c['_vigiar_ate']) ?></dd>
                </dl>
            </div>
        </div>

        <!-- Histórico de tentativas -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-1"></i>Tentativas</span>
                <span class="badge text-bg-light border"><?= count($c['_tentativas']) ?></span>
            </div>
            <?php if (!$c['_tentativas']): ?>
                <div class="card-body text-muted small text-center py-3">Nenhuma ligação registrada ainda.</div>
            <?php else: ?>
            <ul class="list-group list-group-flush small">
                <?php foreach ($c['_tentativas'] as $t):
                    $lbl = enf_resultados()[$t['resultado']][0] ?? $t['resultado']; ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <span><?= enf_data_br($t['data']) ?>
                                <span class="badge text-bg-light border ms-1"><?= strtoupper((string) $t['fase']) ?></span>
                            </span>
                            <span class="<?= (int) $t['sucesso'] === 1 ? 'text-success' : 'text-muted' ?>">
                                <i class="bi <?= (int) $t['sucesso'] === 1 ? 'bi-check-circle' : 'bi-dash-circle' ?>"></i>
                            </span>
                        </div>
                        <div class="text-muted"><?= core_e($lbl) ?></div>
                        <?php if ($t['observacao']): ?><div class="fst-italic"><?= core_e($t['observacao']) ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <?php if ($podeMexer && $c['_status'] !== 'realizado'): ?>
            <div class="card-body border-top">
                <form method="post" class="row g-2">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="op" value="tentativa">
                    <input type="hidden" name="fase" value="d30">
                    <div class="col-5">
                        <label class="form-label small mb-1">Data</label>
                        <input type="date" class="form-control form-control-sm" name="data" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-7">
                        <label class="form-label small mb-1">Resultado</label>
                        <select class="form-select form-select-sm" name="resultado">
                            <?php foreach (enf_resultados() as $k => $r): ?>
                                <option value="<?= $k ?>"><?= core_e($r[0]) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <input class="form-control form-control-sm" name="observacao" maxlength="500" placeholder="Observação (opcional)">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-telephone-plus me-1"></i>Registrar tentativa</button>
                        <div class="form-text">Não conseguiu falar? Registre a tentativa. Se falou, use o questionário ao lado.</div>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Coluna direita: questionário + retornos + CCIH -->
    <div class="col-12 col-lg-8">
        <!-- Questionário de 30 dias -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-card-checklist me-1"></i>Questionário de 30 dias</span>
                <?php if ($temContato): ?><span class="badge text-bg-success">Contato em <?= enf_data_br($c['contato_data']) ?></span><?php endif; ?>
            </div>
            <div class="card-body">
                <form method="post" class="row g-3">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="op" value="questionario">
                    <div class="col-6 col-md-4">
                        <label class="form-label small fw-semibold">Data do contato</label>
                        <input type="date" class="form-control form-control-sm" name="contato_data" <?= $podeMexer ? '' : 'disabled' ?>
                               value="<?= core_e($c['contato_data'] ?: date('Y-m-d')) ?>">
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label small fw-semibold">Como falou</label>
                        <select class="form-select form-select-sm" name="contato_resultado" <?= $podeMexer ? '' : 'disabled' ?>>
                            <option value="atendeu"  <?= ($c['contato_resultado'] ?? '') === 'atendeu' ? 'selected' : '' ?>>Atendeu (telefone)</option>
                            <option value="whatsapp" <?= ($c['contato_resultado'] ?? '') === 'whatsapp' ? 'selected' : '' ?>>WhatsApp / e-mail</option>
                        </select>
                    </div>
                    <div class="col-12"><hr class="my-1"></div>

                    <div class="col-6 col-md-4">
                        <label class="form-label small fw-semibold">1. Febre ≥ 37,8 °C</label>
                        <?= $selNota($c['febre'] !== null ? (int) $c['febre'] : null, 'febre', $podeMexer) ?>
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label small fw-semibold">2. Vermelhidão/calor/inchaço</label>
                        <?= $selNota($c['vermelhidao'] !== null ? (int) $c['vermelhidao'] : null, 'vermelhidao', $podeMexer) ?>
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label small fw-semibold">3. Dor piorando</label>
                        <?= $selNota($c['dor'] !== null ? (int) $c['dor'] : null, 'dor', $podeMexer) ?>
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label small fw-semibold">4. Secreção (líquido)</label>
                        <select class="form-select form-select-sm" name="secrecao" <?= $podeMexer ? '' : 'disabled' ?>>
                            <option value="">—</option>
                            <?php foreach (enf_secrecao_opcoes() as $k => $lbl): ?>
                                <option value="<?= $k ?>" <?= ($c['secrecao'] ?? '') === $k ? 'selected' : '' ?>><?= core_e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label small fw-semibold">5. Ferida abriu</label>
                        <?= $selNota($c['ferida_abriu'] !== null ? (int) $c['ferida_abriu'] : null, 'ferida_abriu', $podeMexer) ?>
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label small fw-semibold">6. Usou antibiótico</label>
                        <?= $selNota($c['antibiotico'] !== null ? (int) $c['antibiotico'] : null, 'antibiotico', $podeMexer) ?>
                    </div>
                    <div class="col-12 col-md-8">
                        <label class="form-label small fw-semibold">7. Voltou ao médico</label>
                        <select class="form-select form-select-sm" name="retorno_medico" <?= $podeMexer ? '' : 'disabled' ?>>
                            <option value="">—</option>
                            <?php foreach (enf_retorno_opcoes() as $k => $lbl): ?>
                                <option value="<?= $k ?>" <?= ($c['retorno_medico'] ?? '') === $k ? 'selected' : '' ?>><?= core_e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Observações (nas palavras do paciente)</label>
                        <textarea class="form-control form-control-sm" name="observacoes" rows="2" <?= $podeMexer ? '' : 'disabled' ?>><?= core_e($c['observacoes'] ?? '') ?></textarea>
                    </div>
                    <?php if ($podeMexer): ?>
                    <div class="col-12">
                        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Salvar questionário</button>
                        <span class="text-muted small ms-2">O alerta é calculado automaticamente a partir das respostas.</span>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if ($c['_protese']): ?>
        <!-- Retornos 60/90 dias (prótese) -->
        <div class="row g-3 mb-1">
            <?php foreach (['c60' => ['60', $c['_r60'], 'contato60'], 'c90' => ['90', $c['_r90'], 'contato90']] as $op => [$dias, $estado, $pref]): ?>
            <div class="col-12 col-md-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Retorno de <?= $dias ?> dias</span>
                        <?php
                        $badge = $estado === 'realizado' ? 'text-bg-success' : ($estado === 'vencido' ? 'text-bg-warning' : 'text-bg-light border');
                        $txt   = $estado === 'realizado' ? 'Realizado' : ($estado === 'vencido' ? 'Vencido — ligar' : 'Aguardando');
                        ?>
                        <span class="badge <?= $badge ?>"><?= $txt ?></span>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-2">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="op" value="<?= $op ?>">
                            <div class="col-12 small text-muted">Ligar a partir de <?= enf_data_br($op === 'c60' ? $c['_ligar_60'] : $c['_ligar_90']) ?></div>
                            <div class="col-6">
                                <label class="form-label small mb-1">Data do contato</label>
                                <input type="date" class="form-control form-control-sm" name="data" <?= $podeMexer ? '' : 'disabled' ?>
                                       value="<?= core_e($c[$pref . '_data'] ?: date('Y-m-d')) ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label small mb-1">Resultado</label>
                                <select class="form-select form-select-sm" name="resultado" <?= $podeMexer ? '' : 'disabled' ?>>
                                    <?php foreach (enf_resultados() as $k => $r): ?>
                                        <option value="<?= $k ?>" <?= ($c[$pref . '_resultado'] ?? 'atendeu') === $k ? 'selected' : '' ?>><?= core_e($r[0]) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label small mb-1">Sinais de infecção relatados?</label>
                                <?= $selNota($c[$pref . '_sinais'] !== null ? (int) $c[$pref . '_sinais'] : null, 'sinais', $podeMexer) ?>
                            </div>
                            <?php if ($podeMexer): ?>
                            <div class="col-12"><button class="btn btn-outline-primary btn-sm w-100">Salvar retorno de <?= $dias ?> dias</button></div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Avaliação da CCIH -->
        <div class="card mb-3 border-primary-subtle">
            <div class="card-header bg-primary-subtle"><i class="bi bi-clipboard2-pulse me-1"></i>Avaliação da CCIH</div>
            <div class="card-body">
                <?php if (!$podeCcih): ?>
                    <p class="small text-muted mb-2">Preenchida apenas pela enfermeira ou médico da CCIH.</p>
                <?php endif; ?>
                <form method="post" class="row g-3">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="op" value="ccih">
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold">Classificação (ANVISA)</label>
                        <select class="form-select form-select-sm" name="classificacao" <?= $podeCcih ? '' : 'disabled' ?>>
                            <option value="">—</option>
                            <?php foreach (enf_classificacoes() as $k => $lbl): ?>
                                <option value="<?= $k ?>" <?= ($c['classificacao'] ?? '') === $k ? 'selected' : '' ?>><?= core_e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold">Data do diagnóstico</label>
                        <input type="date" class="form-control form-control-sm" name="data_diagnostico" <?= $podeCcih ? '' : 'disabled' ?>
                               value="<?= core_e($c['data_diagnostico'] ?? '') ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold">Notificado?</label>
                        <select class="form-select form-select-sm" name="notificado" <?= $podeCcih ? '' : 'disabled' ?>>
                            <option value="">—</option>
                            <?php foreach (enf_notificacao_opcoes() as $k => $lbl): ?>
                                <option value="<?= $k ?>" <?= ($c['notificado'] ?? '') === $k ? 'selected' : '' ?>><?= core_e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-8">
                        <label class="form-label small fw-semibold">Responsável na CCIH</label>
                        <input class="form-control form-control-sm" name="responsavel_ccih" maxlength="160" <?= $podeCcih ? '' : 'disabled' ?>
                               value="<?= core_e($c['responsavel_ccih'] ?? '') ?>">
                    </div>
                    <?php if ($podeCcih): ?>
                    <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Salvar avaliação</button></div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if ($podeMexer): ?>
        <form method="post" class="text-end">
            <?= Csrf::field() ?>
            <input type="hidden" name="op" value="excluir">
            <button class="btn btn-outline-danger btn-sm" data-confirm="Excluir esta cirurgia e todo o histórico de ligações? Não dá para desfazer.">
                <i class="bi bi-trash me-1"></i>Excluir cirurgia
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php
Layout::render(['title' => $c['paciente'], 'content' => (string) ob_get_clean(), 'active' => 'pacientes']);
