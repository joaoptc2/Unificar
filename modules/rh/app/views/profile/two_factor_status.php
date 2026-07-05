<div class="row">
    <div class="col-lg-8">
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?= Sanitize::e($success) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= Sanitize::e($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($newCodes) && is_array($newCodes)): ?>
            <div class="card border-warning shadow-sm mb-3">
                <div class="card-header bg-warning bg-opacity-25 fw-semibold">
                    <i class="bi bi-key me-1"></i> Códigos de recuperação
                </div>
                <div class="card-body">
                    <p class="small mb-3">
                        Salve estes códigos em local seguro. Cada código pode ser usado <strong>uma única vez</strong>
                        para entrar caso você perca o acesso ao app autenticador.
                        <br><strong>Eles não serão mostrados novamente.</strong>
                    </p>
                    <div class="row g-2">
                        <?php foreach ($newCodes as $c): ?>
                            <div class="col-md-6">
                                <code class="d-block bg-light p-2 rounded" style="letter-spacing:2px;"><?= Sanitize::e($c) ?></code>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-shield-check me-1 text-success"></i> 2FA está ativo
            </div>
            <div class="card-body">
                <table class="table table-sm mb-3">
                    <tr><th class="w-25">Status</th><td><span class="badge bg-success">Ativo</span></td></tr>
                    <tr><th>Último uso</th><td><?= $lastUsed ? Sanitize::formatDateTime($lastUsed) : 'Nunca' ?></td></tr>
                    <tr><th>Códigos de recuperação restantes</th><td><?= (int)$recoveryCount ?></td></tr>
                </table>

                <h6 class="fw-semibold text-danger mt-3">Desativar 2FA</h6>
                <p class="small text-muted">Informe seu código atual (TOTP ou recuperação) para confirmar.</p>
                <form method="POST" action="index.php?page=two_factor&action=disable" autocomplete="off">
                    <?= Csrf::field() ?>
                    <div class="row g-2">
                        <div class="col-md-5">
                            <input type="text" name="code" class="form-control" required
                                   maxlength="14" placeholder="Código de 6 dígitos ou recuperação">
                        </div>
                        <div class="col-md-7">
                            <button type="submit" class="btn btn-outline-danger"
                                    data-confirm="Tem certeza que deseja desativar o 2FA da sua conta?">
                                <i class="bi bi-shield-x me-1"></i> Desativar 2FA
                            </button>
                            <a href="index.php?page=profile" class="btn btn-link">Voltar ao perfil</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
