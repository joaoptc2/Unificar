<div class="page-header">
    <h1><i class="bi bi-gear me-2"></i>Configurações — #<?= Sanitize::e($channel['name']) ?></h1>
    <a href="index.php?page=chat&channel_id=<?= $channel['id'] ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar ao Canal
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="index.php?page=channels&action=updateSettings">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= $channel['id'] ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Modo somente leitura</label>
                            <select name="is_readonly" class="form-select">
                                <option value="0" <?= !(int)($channel['is_readonly'] ?? 0) ? 'selected' : '' ?>>Desativado — todos podem enviar</option>
                                <option value="1" <?= (int)($channel['is_readonly'] ?? 0) ? 'selected' : '' ?>>Ativado — somente admins enviam</option>
                            </select>
                            <div class="form-text">Quando ativado, apenas administradores podem enviar mensagens.</div>
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
                            <a href="index.php?page=chat&channel_id=<?= $channel['id'] ?>" class="btn btn-outline-secondary me-2">Cancelar</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> Salvar Configurações
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
