<div class="page-header">
    <h1><i class="bi bi-bar-chart me-2"></i>Nova Enquete</h1>
    <a href="index.php?page=<?= !empty($channelId) ? 'chat&channel_id=' . (int)$channelId : 'polls' ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="index.php?page=polls&action=store">
                    <?= Csrf::field() ?>
                    <?php if (!empty($channelId)): ?>
                        <input type="hidden" name="channel_id" value="<?= (int)$channelId ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label required">Pergunta</label>
                            <textarea name="question" class="form-control" rows="3" required
                                      placeholder="Digite a pergunta da enquete..."><?= Sanitize::e($poll['question'] ?? '') ?></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label required">Opções</label>
                            <div id="optionsContainer">
                                <?php
                                $options = $poll['options'] ?? ['', ''];
                                foreach ($options as $i => $option):
                                ?>
                                <div class="option-row input-group mb-2">
                                    <span class="input-group-text option-number"><?= $i + 1 ?></span>
                                    <input type="text" name="options[]" class="form-control"
                                           placeholder="Opção <?= $i + 1 ?>" value="<?= Sanitize::e(is_string($option) ? $option : ($option['text'] ?? '')) ?>" required>
                                    <button type="button" class="btn btn-outline-danger remove-option" title="Remover">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm mt-1" id="addOptionBtn">
                                <i class="bi bi-plus-lg me-1"></i> Adicionar opção
                            </button>
                        </div>

                        <div class="col-12"><hr></div>

                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_anonymous" value="1" id="isAnonymous"
                                    <?= !empty($poll['is_anonymous']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="isAnonymous">Voto anônimo</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_multiple" value="1" id="isMultiple"
                                    <?= !empty($poll['is_multiple']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="isMultiple">Múltipla escolha</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Encerrar em</label>
                            <input type="datetime-local" name="closes_at" class="form-control"
                                   value="<?= !empty($poll['closes_at']) ? date('Y-m-d\TH:i', strtotime($poll['closes_at'])) : '' ?>">
                        </div>

                        <?php if (empty($channelId)): ?>
                        <div class="col-12">
                            <label class="form-label required">Canal</label>
                            <select name="channel_id" class="form-select" required>
                                <option value="">Selecione um canal</option>
                                <?php foreach ($channels ?? [] as $ch): ?>
                                <option value="<?= (int)$ch['id'] ?>"
                                    <?= ((int)($poll['channel_id'] ?? 0)) === (int)$ch['id'] ? 'selected' : '' ?>>
                                    <?= Sanitize::e($ch['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-12 text-end">
                            <a href="index.php?page=<?= !empty($channelId) ? 'chat&channel_id=' . (int)$channelId : 'polls' ?>"
                               class="btn btn-outline-secondary me-2">Cancelar</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> Criar Enquete
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('addOptionBtn').addEventListener('click', function() {
    const container = document.getElementById('optionsContainer');
    const num = container.querySelectorAll('.option-row').length + 1;
    const html = `
    <div class="option-row input-group mb-2">
        <span class="input-group-text option-number">${num}</span>
        <input type="text" name="options[]" class="form-control" placeholder="Opção ${num}" required>
        <button type="button" class="btn btn-outline-danger remove-option" title="Remover">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>`;
    container.insertAdjacentHTML('beforeend', html);
});

document.getElementById('optionsContainer').addEventListener('click', function(e) {
    if (e.target.closest('.remove-option')) {
        const rows = this.querySelectorAll('.option-row');
        if (rows.length > 2) {
            e.target.closest('.option-row').remove();
            this.querySelectorAll('.option-number').forEach((el, i) => el.textContent = i + 1);
        }
    }
});
</script>
