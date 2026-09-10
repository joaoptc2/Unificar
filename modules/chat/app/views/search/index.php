<?php
// Resultados vindos do SearchController: mensagens, pessoas e canais
$messages       = $results['messages'] ?? [];
$users          = $results['users'] ?? [];
$channelResults = $results['channels'] ?? [];
$query          = $query ?? '';

/** Destaca o termo buscado no trecho (saída já escapada). */
$highlight = static function (string $text, string $q): string {
    $safe = Sanitize::e($text);
    if ($q === '') {
        return $safe;
    }
    return preg_replace('/' . preg_quote(Sanitize::e($q), '/') . '/iu', '<mark>$0</mark>', $safe) ?? $safe;
};
?>
<div class="page-header">
    <h1 class="h4"><i class="bi bi-search me-2"></i>Buscar mensagens</h1>
    <a href="index.php?m=chat&page=chat" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar ao chat</a>
</div>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <form class="mb-4" method="GET" action="index.php">
            <input type="hidden" name="m" value="chat">
            <input type="hidden" name="page" value="search">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" name="query" class="form-control" placeholder="Buscar mensagens, pessoas ou canais..."
                       value="<?= Sanitize::e($query) ?>" autofocus maxlength="200">
                <button type="submit" class="btn btn-primary">Buscar</button>
            </div>
            <div class="form-text">A busca de mensagens considera apenas os canais e conversas de que você participa.</div>
        </form>

        <?php if ($query !== ''): ?>
            <?php if (!empty($messages)): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-chat me-2"></i>Mensagens (<?= count($messages) ?>)</div>
                <div class="card-body p-0">
                    <?php foreach ($messages as $msg): ?>
                    <a href="index.php?m=chat&page=chat&channel_id=<?= (int) $msg['channel_id'] ?>#msg-<?= (int) $msg['id'] ?>" class="search-result-item">
                        <div class="d-flex justify-content-between flex-wrap gap-1">
                            <strong><?= Sanitize::e($msg['user_name'] ?? 'Usuário removido') ?></strong>
                            <span class="text-muted small">
                                <?= ($msg['channel_type'] ?? '') === 'direct' ? 'Mensagem direta' : '#' . Sanitize::e($msg['channel_name'] ?? '') ?>
                                · <?= Sanitize::e(Sanitize::formatDateTime($msg['created_at'])) ?>
                            </span>
                        </div>
                        <p class="mb-0 text-muted"><?= $highlight(mb_substr((string) $msg['content'], 0, 200), $query) ?></p>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($users)): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-people me-2"></i>Pessoas (<?= count($users) ?>)</div>
                <div class="card-body p-0">
                    <?php foreach ($users as $u): ?>
                    <div class="search-result-item d-flex align-items-center gap-2">
                        <span class="dm-status <?= Sanitize::e($u['status'] ?? 'offline') ?>"></span>
                        <div class="min-w-0">
                            <strong><?= Sanitize::e($u['name']) ?></strong>
                            <span class="text-muted small ms-1"><?= Sanitize::e($u['title'] ?: $u['email']) ?></span>
                        </div>
                        <?php if ((int) $u['id'] !== (int) Session::userId()): ?>
                        <a href="index.php?m=chat&page=channels&action=direct&user_id=<?= (int) $u['id'] ?>" class="btn btn-outline-primary btn-sm ms-auto" title="Mensagem direta">
                            <i class="bi bi-chat"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($channelResults)): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-hash me-2"></i>Canais (<?= count($channelResults) ?>)</div>
                <div class="card-body p-0">
                    <?php foreach ($channelResults as $ch): ?>
                    <a href="index.php?m=chat&page=chat&action=channel&id=<?= (int) $ch['id'] ?>" class="search-result-item">
                        <strong>#<?= Sanitize::e($ch['name']) ?></strong>
                        <?php if (!empty($ch['description'])): ?>
                        <p class="mb-0 text-muted small"><?= Sanitize::e(mb_substr((string) $ch['description'], 0, 120)) ?></p>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($messages) && empty($users) && empty($channelResults)): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5 text-muted">
                    <i class="bi bi-search display-4"></i>
                    <p class="mt-2 mb-0">Nenhum resultado para "<?= Sanitize::e($query) ?>".</p>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
