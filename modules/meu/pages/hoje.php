<?php
/**
 * MEU ESPAÇO — "Hoje": a única tela que junta as três partes.
 *
 * É a que abre por padrão porque responde à pergunta do começo do turno —
 * o que tenho hoje, o que está atrasado, o que anotei — sem obrigar a
 * visitar três páginas.
 */

declare(strict_types=1);

use Core\Auth;
use Core\Layout;

$user = Auth::user();
$primeiro = trim(explode(' ', trim((string) ($user['name'] ?? '')))[0]);

[$de, $ate] = meu_dia();
$eventosHoje = core_can('agenda.view')  ? meu_eventos($de, $ate)     : [];
$tarefas     = core_can('tarefas.view') ? meu_tarefas_abertas(8)     : [];
$notas       = core_can('notas.view')   ? meu_notas(false, 4)        : [];

$pedidos   = core_can('solicitacoes.view') ? meu_solicitacoes('recebidas', false) : [];
$pendentes = array_filter($pedidos, fn ($s) => $s['situacao'] === 'pendente');
$atrasadas = array_filter($tarefas, fn ($t) => meu_prazo_estado($t['prazo']) === 'vencida');
$agora     = date('H:i:s');

// Saudação pela hora: é o primeiro texto da tela e uma saudação errada às
// 22h faz o sistema parecer desatento.
$h = (int) date('G');
$saudacao = $h < 12 ? 'Bom dia' : ($h < 18 ? 'Boa tarde' : 'Boa noite');

ob_start(); ?>
<div class="mb-4">
    <h1 class="h4 mb-1"><?= $saudacao ?><?= $primeiro !== '' ? ', ' . core_e($primeiro) : '' ?>.</h1>
    <p class="text-muted mb-0"><?= core_e(ucfirst(meu_data_extenso(time()))) ?></p>
</div>

<?php if ($pendentes): ?>
<div class="alert alert-info d-flex align-items-center gap-2">
    <i class="bi bi-inbox-fill"></i>
    <div>
        <strong><?= count($pendentes) ?></strong> pessoa(s) estão esperando sua resposta.
        <a href="<?= core_module_url('meu', ['page' => 'solicitacoes']) ?>" class="alert-link">Ver</a>
    </div>
</div>
<?php endif; ?>

<?php if ($atrasadas): ?>
<div class="alert alert-warning d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <div>
        <strong><?= count($atrasadas) ?></strong> tarefa(s) com prazo vencido.
        <a href="<?= core_module_url('meu', ['page' => 'tarefas']) ?>" class="alert-link">Ver</a>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <?php if (core_can('agenda.view')): ?>
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-calendar3 me-2"></i>Hoje na agenda</span>
                <a class="btn btn-sm btn-outline-secondary py-0" href="<?= core_module_url('meu', ['page' => 'agenda']) ?>">Semana</a>
            </div>
            <div class="card-body">
                <?php if (!$eventosHoje): ?>
                    <p class="text-muted text-center py-4 mb-0">Nenhum compromisso hoje.</p>
                <?php else: ?>
                    <?php foreach ($eventosHoje as $e):
                        $emCurso = !(int) $e['dia_inteiro']
                                && date('H:i:s', (int) strtotime($e['inicio'])) <= $agora
                                && date('H:i:s', (int) strtotime($e['fim']))    >= $agora; ?>
                        <div class="d-flex gap-2 align-items-start mb-3">
                            <span class="rounded-pill flex-shrink-0" style="width:4px;align-self:stretch;background:<?= core_e($e['cor'] ?: 'var(--portal-primary)') ?>"></span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold text-break">
                                    <?= core_e($e['titulo']) ?>
                                    <?php if ($emCurso): ?><span class="badge text-bg-success ms-1">agora</span><?php endif; ?>
                                </div>
                                <div class="small text-muted">
                                    <?= (int) $e['dia_inteiro']
                                        ? 'Dia inteiro'
                                        : date('H:i', (int) strtotime($e['inicio'])) . '–' . date('H:i', (int) strtotime($e['fim'])) ?>
                                    <?php if ($e['local']): ?> · <?= core_e($e['local']) ?><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (core_can('tarefas.view')): ?>
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-check2-square me-2"></i>Próximas tarefas</span>
                <a class="btn btn-sm btn-outline-secondary py-0" href="<?= core_module_url('meu', ['page' => 'tarefas']) ?>">Todas</a>
            </div>
            <?php if (!$tarefas): ?>
                <div class="card-body"><p class="text-muted text-center py-4 mb-0">Nada pendente.</p></div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($tarefas as $t):
                    $estado = meu_prazo_estado($t['prazo']);
                    $cor = ['vencida' => 'text-danger', 'hoje' => 'text-warning'][$estado] ?? 'text-muted'; ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2 py-2">
                        <span class="text-break small">
                            <?php if ($t['prioridade'] === 'alta'): ?><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i><?php endif; ?>
                            <?= core_e($t['titulo']) ?>
                        </span>
                        <?php if ($t['prazo']): ?>
                            <small class="<?= $cor ?> flex-shrink-0"><?= date('d/m', (int) strtotime($t['prazo'])) ?></small>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (core_can('notas.view') && $notas): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-journal-text me-2"></i>Notas recentes</span>
                <a class="btn btn-sm btn-outline-secondary py-0" href="<?= core_module_url('meu', ['page' => 'notas']) ?>">Todas</a>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <?php foreach ($notas as $n): ?>
                        <div class="col-12 col-sm-6 col-lg-3">
                            <a class="card h-100 text-decoration-none" style="<?= $n['cor'] ? 'background:' . core_e($n['cor']) . ';' : '' ?>"
                               href="<?= core_module_url('meu', ['page' => 'notas', 'editar' => (int) $n['id']]) ?>">
                                <div class="card-body p-2">
                                    <div class="small fw-semibold text-body text-break">
                                        <?php if ((int) $n['fixada']): ?><i class="bi bi-pin-angle-fill text-warning"></i> <?php endif; ?>
                                        <?= core_e($n['titulo'] ?: 'Sem título') ?>
                                    </div>
                                    <div class="text-muted text-break" style="font-size:.78rem">
                                        <?= core_e(mb_substr(trim(strip_tags((string) $n['conteudo'])), 0, 90)) ?>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php
Layout::render(['title' => 'Hoje', 'content' => (string) ob_get_clean(), 'active' => 'hoje']);
