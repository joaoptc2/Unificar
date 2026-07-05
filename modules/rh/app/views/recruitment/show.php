<?php
$canEdit   = Auth::can('recruitment', 'edit');
$canDelete = Auth::can('recruitment', 'delete');
$publicUrl = rtrim(BASE_URL, '/') . '/index.php?m=rh&page=public_recruitment&action=apply&job_id=' . (int)$job['id'];
?>
<div class="page-header">
    <h1><i class="bi bi-kanban me-2"></i><?= Sanitize::e($job['title']) ?></h1>
    <div class="d-flex gap-2">
        <span class="badge <?= $job['status'] === 'aberta' ? 'bg-success' : 'bg-secondary' ?> fs-6">
            <?= ucfirst($job['status']) ?>
        </span>
        <?php if ($canEdit): ?>
            <a href="index.php?m=rh&page=recruitment&action=edit&id=<?= $job['id'] ?>" class="btn btn-outline-warning btn-sm">
                <i class="bi bi-pencil me-1"></i> Editar
            </a>
        <?php endif; ?>
        <?php if ($canDelete): ?>
            <form method="POST" action="index.php?m=rh&page=recruitment&action=delete" class="d-inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int)$job['id'] ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm"
                        data-confirm="Excluir esta vaga? Esta ação é irreversível e remove também o Kanban e as candidaturas vinculadas não desassociadas.">
                    <i class="bi bi-trash me-1"></i> Excluir vaga
                </button>
            </form>
        <?php endif; ?>
        <a href="index.php?m=rh&page=recruitment" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
    </div>
</div>

<!-- Detalhes da vaga -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row">
            <div class="col-md-4"><small class="text-muted">Departamento</small><div><?= Sanitize::e($job['department_name'] ?? '-') ?></div></div>
            <div class="col-md-8">
                <?php if ($job['description']): ?>
                    <small class="text-muted">Descrição</small>
                    <div class="small"><?= nl2br(Sanitize::e($job['description'])) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($job['status'] === 'aberta'): ?>
            <div class="mt-3 p-2 bg-light rounded">
                <small class="text-muted d-block mb-1">
                    <i class="bi bi-link-45deg me-1"></i>
                    Link público para candidatos (compartilhe em redes sociais, sites, etc.):
                </small>
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control" id="publicJobUrl" readonly
                           value="<?= Sanitize::e($publicUrl) ?>">
                    <button type="button" class="btn btn-outline-primary" onclick="copyJobUrl()">
                        <i class="bi bi-clipboard me-1"></i> Copiar
                    </button>
                    <a href="<?= Sanitize::e($publicUrl) ?>" target="_blank" class="btn btn-outline-secondary">
                        <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                </div>
            </div>
            <script>
            function copyJobUrl() {
                var el = document.getElementById('publicJobUrl');
                el.select(); el.setSelectionRange(0, 99999);
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(el.value);
                } else {
                    document.execCommand('copy');
                }
            }
            </script>
        <?php endif; ?>
    </div>
</div>

<?php if ($canEdit): ?>
<div class="alert alert-info py-2 small mb-2">
    <i class="bi bi-info-circle me-1"></i>
    Arraste os cartões entre as colunas para atualizar a etapa do candidato.
</div>
<?php endif; ?>

<!-- Kanban Board -->
<div id="kanbanBoard" class="d-flex gap-3 overflow-auto pb-3"
     data-job-id="<?= (int)$job['id'] ?>"
     data-csrf="<?= Sanitize::e(Csrf::token()) ?>"
     data-can-edit="<?= $canEdit ? '1' : '0' ?>">

    <!-- Coluna: Novos inscritos -->
    <div class="kanban-column flex-shrink-0">
        <div class="column-header d-flex justify-content-between">
            <span>Inscritos</span>
            <span class="badge bg-secondary" data-step-counter="0"><?= count($newCandidates) ?></span>
        </div>
        <div class="kanban-list p-2" data-step-id="0" style="min-height:200px;">
            <?php foreach ($newCandidates as $c): ?>
                <?= renderCandidateCard($c, $job, $canEdit) ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Colunas das etapas -->
    <?php foreach ($steps as $step): ?>
        <div class="kanban-column flex-shrink-0">
            <div class="column-header d-flex justify-content-between">
                <span><?= Sanitize::e($step['name']) ?></span>
                <span class="badge bg-secondary" data-step-counter="<?= (int)$step['id'] ?>">
                    <?= count($candidatesByStep[$step['id']] ?? []) ?>
                </span>
            </div>
            <div class="kanban-list p-2" data-step-id="<?= (int)$step['id'] ?>" style="min-height:200px;">
                <?php foreach ($candidatesByStep[$step['id']] ?? [] as $c): ?>
                    <?= renderCandidateCard($c, $job, $canEdit, $step) ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php
/** Renderiza um cartão do Kanban. */
function renderCandidateCard(array $c, array $job, bool $canEdit, ?array $step = null): string {
    ob_start();
    ?>
    <div class="kanban-card mb-2 p-2 border rounded bg-white shadow-sm" data-candidate-id="<?= (int)$c['id'] ?>" style="cursor:<?= $canEdit ? 'grab' : 'default' ?>;">
        <div class="fw-semibold"><?= Sanitize::e($c['full_name']) ?></div>
        <small class="text-muted"><?= Sanitize::e($c['email']) ?></small>
        <?php if ($c['resume_path']): ?>
            <div><a href="<?= Sanitize::e(Upload::url($c['resume_path'], 'resume', (int)$c['id'])) ?>" target="_blank" class="small"><i class="bi bi-file-earmark-pdf me-1"></i>Currículo</a></div>
        <?php endif; ?>
        <?php if ($canEdit && $step): ?>
            <div class="mt-2 d-flex gap-1">
                <form method="POST" action="index.php?m=rh&page=recruitment&action=evaluate_candidate" class="flex-grow-1">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="candidate_id" value="<?= (int)$c['id'] ?>">
                    <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                    <input type="hidden" name="candidate_status" value="aprovado">
                    <input type="hidden" name="notes" value="Aprovado na etapa <?= Sanitize::e($step['name']) ?>">
                    <button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-check"></i> Aprovar</button>
                </form>
                <form method="POST" action="index.php?m=rh&page=recruitment&action=evaluate_candidate">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="candidate_id" value="<?= (int)$c['id'] ?>">
                    <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                    <input type="hidden" name="candidate_status" value="reprovado">
                    <input type="hidden" name="notes" value="Reprovado na etapa <?= Sanitize::e($step['name']) ?>">
                    <button type="submit" class="btn btn-danger btn-sm" data-confirm="Reprovar candidato?"><i class="bi bi-x"></i></button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}
?>

<?php if ($canEdit): ?>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const board = document.getElementById('kanbanBoard');
    if (!board) return;

    const csrf  = board.dataset.csrf;
    const jobId = board.dataset.jobId;

    document.querySelectorAll('#kanbanBoard .kanban-list').forEach(function (list) {
        new Sortable(list, {
            group: 'kanban',
            animation: 150,
            ghostClass: 'kanban-ghost',
            onEnd: function (evt) {
                const card     = evt.item;
                const stepId   = evt.to.dataset.stepId;
                const fromStep = evt.from.dataset.stepId;
                if (stepId === fromStep) return; // mesma coluna
                if (!stepId || stepId === '0') {
                    // Voltar para "Inscritos" não está suportado pelo backend → desfaz.
                    evt.from.insertBefore(card, evt.from.children[evt.oldIndex] || null);
                    return;
                }

                const candidateId = card.dataset.candidateId;
                const fd = new FormData();
                fd.append('_csrf_token', csrf);
                fd.append('candidate_id', candidateId);
                fd.append('step_id', stepId);
                fd.append('job_id', jobId);
                fd.append('notes', 'Movido via Kanban');

                fetch('index.php?m=rh&page=recruitment&action=move_candidate', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                })
                    .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
                    .then(function (res) {
                        if (!res.ok) {
                            alert(res.data.message || 'Falha ao mover.');
                            evt.from.insertBefore(card, evt.from.children[evt.oldIndex] || null);
                            return;
                        }
                        updateCounters();
                    })
                    .catch(function () {
                        alert('Erro de rede ao mover o candidato.');
                        evt.from.insertBefore(card, evt.from.children[evt.oldIndex] || null);
                    });
            },
        });
    });

    function updateCounters() {
        document.querySelectorAll('#kanbanBoard .kanban-list').forEach(function (list) {
            const stepId = list.dataset.stepId;
            const counter = document.querySelector('[data-step-counter="' + stepId + '"]');
            if (counter) counter.textContent = list.querySelectorAll('.kanban-card').length;
        });
    }
});
</script>
<style>
.kanban-ghost { opacity: 0.5; background: #eef; }
</style>
<?php endif; ?>
