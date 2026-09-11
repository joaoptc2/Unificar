<div class="page-header">
    <h1 class="h4"><i class="bi bi-gear me-2"></i>Configurações — #<?= Sanitize::e($channel['name']) ?></h1>
    <a href="index.php?m=chat&page=chat&channel_id=<?= $channel['id'] ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar ao canal
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="index.php?m=chat&page=channels&action=updateSettings">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= $channel['id'] ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Modo somente leitura</label>
                            <select name="is_readonly" class="form-select">
                                <option value="0" <?= !(int)($channel['is_readonly'] ?? 0) ? 'selected' : '' ?>>Desativado — todos podem enviar</option>
                                <option value="1" <?= (int)($channel['is_readonly'] ?? 0) ? 'selected' : '' ?>>Ativado — somente moderadores enviam</option>
                            </select>
                            <div class="form-text">Quando ativado, apenas quem tem a permissão "Moderar" do chat envia mensagens.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Retenção de mensagens (dias)</label>
                            <input type="number" name="retention_days" class="form-control" min="0"
                                   placeholder="Ilimitado"
                                   value="<?= ($channel['retention_days'] ?? '') !== '' && $channel['retention_days'] !== null ? (int)$channel['retention_days'] : '' ?>">
                            <div class="form-text">Deixe vazio para manter mensagens indefinidamente.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Slow mode (segundos entre mensagens)</label>
                            <select name="slow_mode_seconds" class="form-select">
                                <option value="0" <?= (int)($channel['slow_mode_seconds'] ?? 0) === 0 ? 'selected' : '' ?>>Desativado</option>
                                <option value="5" <?= (int)($channel['slow_mode_seconds'] ?? 0) === 5 ? 'selected' : '' ?>>5 segundos</option>
                                <option value="10" <?= (int)($channel['slow_mode_seconds'] ?? 0) === 10 ? 'selected' : '' ?>>10 segundos</option>
                                <option value="30" <?= (int)($channel['slow_mode_seconds'] ?? 0) === 30 ? 'selected' : '' ?>>30 segundos</option>
                                <option value="60" <?= (int)($channel['slow_mode_seconds'] ?? 0) === 60 ? 'selected' : '' ?>>1 minuto</option>
                                <option value="300" <?= (int)($channel['slow_mode_seconds'] ?? 0) === 300 ? 'selected' : '' ?>>5 minutos</option>
                            </select>
                            <div class="form-text">Intervalo mínimo entre mensagens de cada usuário.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Permitir threads</label>
                            <select name="allow_threads" class="form-select">
                                <option value="1" <?= (int)($channel['allow_threads'] ?? 1) ? 'selected' : '' ?>>Sim</option>
                                <option value="0" <?= !(int)($channel['allow_threads'] ?? 1) ? 'selected' : '' ?>>Não</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Máximo de mensagens fixadas</label>
                            <input type="number" name="max_pinned" class="form-control" min="1" max="200"
                                   value="<?= (int)($channel['max_pinned'] ?? 50) ?>">
                        </div>

                        <div class="col-12 text-end mt-3">
                            <a href="index.php?m=chat&page=chat&channel_id=<?= $channel['id'] ?>" class="btn btn-outline-secondary me-2">Cancelar</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> Salvar configurações
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php if (!empty($canArchive)): ?>
        <div class="card border-0 shadow-sm mt-3 border-danger-subtle">
            <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <strong class="text-danger"><i class="bi bi-archive me-1"></i>Arquivar canal</strong>
                    <div class="small text-muted">O canal deixa de aparecer para todos; as mensagens são preservadas.</div>
                </div>
                <form method="POST" action="index.php?m=chat&page=channels&action=archive">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int) $channel['id'] ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Arquivar o canal #<?= Sanitize::e($channel['name']) ?>?"><i class="bi bi-archive me-1"></i> Arquivar</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
