<?php
/**
 * Formulário de resposta de pesquisa — usado no portal (my/survey.php) e na
 * tela de resultados (surveys/show.php). Espera: $survey (com 'questions').
 */
?>
<form method="POST" action="index.php?m=rh&page=surveys&action=respond" id="surveyAnswerForm" novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="survey_id" value="<?= (int)$survey['id'] ?>">
    <?php $n = 0; foreach ($survey['questions'] as $q): $n++; $qid = (int)$q['id']; $req = (int)($q['required'] ?? 0) === 1; $opts = $q['opts'] ?? []; ?>
        <div class="mb-4 pb-3 border-bottom survey-q" data-required="<?= $req ? 1 : 0 ?>" data-type="<?= Sanitize::e($q['type']) ?>">
            <label class="form-label fw-semibold d-block">
                <span class="text-muted me-1"><?= $n ?>.</span><?= Sanitize::e($q['question']) ?>
                <?php if ($req): ?><span class="text-danger" title="Obrigatória">*</span><?php endif; ?>
            </label>
            <?php if (!empty($q['help_text'])): ?><div class="form-text mb-2"><?= Sanitize::e($q['help_text']) ?></div><?php endif; ?>

            <?php switch ($q['type']):
                case 'rating': ?>
                    <div class="star-rating" title="1 a 5 estrelas">
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <input type="radio" name="answers[<?= $qid ?>]" id="q<?= $qid ?>_<?= $i ?>" value="<?= $i ?>">
                            <label for="q<?= $qid ?>_<?= $i ?>" title="<?= $i ?> estrela(s)"><i class="bi bi-star-fill"></i></label>
                        <?php endfor; ?>
                    </div>
                    <?php break;
                case 'scale':
                    $min = (int)($opts['min'] ?? 0); $max = (int)($opts['max'] ?? 10);
                    if ($max <= $min) { $min = 0; $max = 10; } ?>
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <?php if (!empty($opts['min_label'])): ?><small class="text-muted"><?= Sanitize::e($opts['min_label']) ?></small><?php endif; ?>
                        <div class="scale-options btn-group flex-wrap" role="group">
                            <?php for ($i = $min; $i <= $max; $i++): ?>
                                <input type="radio" class="btn-check" name="answers[<?= $qid ?>]" id="q<?= $qid ?>_s<?= $i ?>" value="<?= $i ?>">
                                <label class="btn btn-outline-primary btn-sm" for="q<?= $qid ?>_s<?= $i ?>"><?= $i ?></label>
                            <?php endfor; ?>
                        </div>
                        <?php if (!empty($opts['max_label'])): ?><small class="text-muted"><?= Sanitize::e($opts['max_label']) ?></small><?php endif; ?>
                    </div>
                    <?php break;
                case 'yes_no': ?>
                    <div class="btn-group" role="group">
                        <input type="radio" class="btn-check" name="answers[<?= $qid ?>]" id="q<?= $qid ?>_sim" value="sim">
                        <label class="btn btn-outline-success" for="q<?= $qid ?>_sim"><i class="bi bi-hand-thumbs-up me-1"></i>Sim</label>
                        <input type="radio" class="btn-check" name="answers[<?= $qid ?>]" id="q<?= $qid ?>_nao" value="nao">
                        <label class="btn btn-outline-danger" for="q<?= $qid ?>_nao"><i class="bi bi-hand-thumbs-down me-1"></i>Não</label>
                    </div>
                    <?php break;
                case 'choice': ?>
                    <?php foreach ((array)($opts['options'] ?? []) as $k => $opt): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="answers[<?= $qid ?>]" id="q<?= $qid ?>_c<?= $k ?>" value="<?= Sanitize::e((string)$opt) ?>">
                            <label class="form-check-label" for="q<?= $qid ?>_c<?= $k ?>"><?= Sanitize::e((string)$opt) ?></label>
                        </div>
                    <?php endforeach; ?>
                    <?php break;
                case 'multiple': ?>
                    <?php foreach ((array)($opts['options'] ?? []) as $k => $opt): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="answers[<?= $qid ?>][]" id="q<?= $qid ?>_m<?= $k ?>" value="<?= Sanitize::e((string)$opt) ?>">
                            <label class="form-check-label" for="q<?= $qid ?>_m<?= $k ?>"><?= Sanitize::e((string)$opt) ?></label>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-text">Marque todas as opções que se aplicam.</div>
                    <?php break;
                case 'number': ?>
                    <input type="number" step="any" name="answers[<?= $qid ?>]" class="form-control" style="max-width:220px">
                    <?php break;
                case 'date': ?>
                    <input type="date" name="answers[<?= $qid ?>]" class="form-control" style="max-width:220px">
                    <?php break;
                default: ?>
                    <textarea name="answers[<?= $qid ?>]" class="form-control" rows="3" maxlength="4000"></textarea>
            <?php endswitch; ?>
            <div class="invalid-feedback d-block small" data-error hidden>Esta pergunta é obrigatória.</div>
        </div>
    <?php endforeach; ?>
    <?php if ((int)$survey['anonymous']): ?>
        <p class="small text-muted"><i class="bi bi-incognito me-1"></i>Pesquisa anônima: suas respostas não são vinculadas ao seu nome — apenas registramos que você participou.</p>
    <?php else: ?>
        <p class="small text-muted"><i class="bi bi-person-check me-1"></i>Pesquisa identificada: suas respostas ficam associadas ao seu usuário.</p>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Enviar respostas</button>
</form>
<script>
// Validação por tipo no navegador (o servidor valida novamente).
(function () {
    var form = document.getElementById('surveyAnswerForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        var ok = true, first = null;
        form.querySelectorAll('.survey-q').forEach(function (q) {
            var err = q.querySelector('[data-error]');
            var filled = false;
            q.querySelectorAll('input, textarea, select').forEach(function (i) {
                if (i.type === 'radio' || i.type === 'checkbox') { if (i.checked) filled = true; }
                else if (String(i.value).trim() !== '') filled = true;
            });
            var bad = q.getAttribute('data-required') === '1' && !filled;
            err.hidden = !bad;
            if (bad) { ok = false; if (!first) first = q; }
        });
        if (!ok) { e.preventDefault(); if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    });
})();
</script>
