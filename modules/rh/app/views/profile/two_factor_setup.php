<div class="row">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-shield-lock me-1"></i> Ativar autenticação em duas etapas
            </div>
            <div class="card-body">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger small"><?= Sanitize::e($error) ?></div>
                <?php endif; ?>

                <p class="mb-3">
                    O 2FA adiciona uma camada extra de segurança ao login. Você precisará informar
                    um código de 6 dígitos (gerado a cada 30s) sempre que entrar.
                </p>

                <h6 class="fw-semibold mt-3">1. Instale um aplicativo autenticador</h6>
                <p class="small text-muted">
                    Google Authenticator, Microsoft Authenticator, Authy, 1Password ou similar.
                </p>

                <h6 class="fw-semibold mt-4">2. Escaneie o QR Code</h6>
                <div class="text-center my-3">
                    <div id="qrcode" class="d-inline-block p-3 bg-white border rounded"></div>
                </div>
                <p class="small text-center">
                    Ou digite manualmente este código:<br>
                    <code style="font-size:1rem; letter-spacing:2px;"><?= Sanitize::e($secret) ?></code>
                </p>

                <h6 class="fw-semibold mt-4">3. Confirme com um código</h6>
                <form method="POST" action="index.php?page=two_factor&action=activate" autocomplete="off">
                    <?= Csrf::field() ?>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label small">Código de 6 dígitos</label>
                            <input type="text" inputmode="numeric" pattern="\d{6}"
                                   name="code" class="form-control" maxlength="6" required
                                   placeholder="000000" style="letter-spacing:4px;">
                        </div>
                        <div class="col-md-8">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> Ativar 2FA
                            </button>
                            <a href="index.php?page=profile" class="btn btn-link">Cancelar</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script>
(function () {
    const otpauth = <?= json_encode($otpauth, JSON_UNESCAPED_SLASHES) ?>;
    // qrcode-generator: tipo 0 (auto), correção L
    const qr = qrcode(0, 'M');
    qr.addData(otpauth);
    qr.make();
    document.getElementById('qrcode').innerHTML = qr.createSvgTag({ cellSize: 5, margin: 0 });
})();
</script>
