<?php
/** Portal — responder pesquisa. Variáveis: $employee, $survey, $open, $answered (+ as do _top). */
$portalTitle = $survey['title'];
$compact = true;
require __DIR__ . '/_top.php';
?>
    <div class="mb-3"><a href="index.php?m=rh&page=my#pesquisas" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i> Voltar às pesquisas</a></div>
    <div class="row justify-content-center"><div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <span class="badge bg-info text-dark"><?= Sanitize::e(Survey::TYPES[$survey['type']] ?? ucfirst($survey['type'])) ?></span>
                <?= (int)$survey['anonymous'] ? '<span class="badge bg-dark"><i class="bi bi-incognito"></i> Anônima</span>' : '<span class="badge bg-light text-dark border">Identificada</span>' ?>
                <h2 class="fw-bold h3 mt-2"><?= Sanitize::e($survey['title']) ?></h2>
                <?php if ($survey['description']): ?><p class="text-muted"><?= nl2br(Sanitize::e($survey['description'])) ?></p><?php endif; ?>
                <?php if ($survey['ends_at']): ?><small class="text-muted d-block mb-3">Disponível até <?= Sanitize::formatDate($survey['ends_at']) ?></small><?php endif; ?>
                <hr>
                <?php if ($answered): ?>
                    <div class="alert alert-success mb-0"><i class="bi bi-check-circle me-1"></i> Você já respondeu esta pesquisa. Obrigado pela participação!</div>
                <?php elseif (!$open): ?>
                    <div class="alert alert-secondary mb-0"><i class="bi bi-lock me-1"></i> Esta pesquisa não está aberta para respostas no momento.</div>
                <?php elseif (empty($survey['questions'])): ?>
                    <div class="alert alert-warning mb-0">Esta pesquisa ainda não possui perguntas.</div>
                <?php else: ?>
                    <?php require __DIR__ . '/../surveys/_answer_form.php'; ?>
                <?php endif; ?>
            </div>
        </div>
    </div></div>
<?php require __DIR__ . '/_bottom.php'; ?>
