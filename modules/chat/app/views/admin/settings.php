<div class="page-header">
    <h1><i class="bi bi-palette me-2"></i>Configurações</h1>
    <a href="index.php?m=chat&page=admin" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="index.php?m=chat&page=admin&action=updateSettings">
                    <?= Csrf::field() ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Nome do aplicativo</label>
                            <input type="text" name="app_name" class="form-control"
                                   value="<?= Sanitize::e($settings['app_name'] ?? 'TeamChat') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Permitir registro</label>
                            <select name="allow_registration" class="form-select">
                                <option value="1" <?= ($settings['allow_registration'] ?? '1') === '1' ? 'selected' : '' ?>>Sim</option>
                                <option value="0" <?= ($settings['allow_registration'] ?? '1') === '0' ? 'selected' : '' ?>>Não</option>
                            </select>
                        </div>

                        <div class="col-12"><hr><h6>Cores</h6></div>

                        <div class="col-md-4">
                            <label class="form-label">Cor primária</label>
                            <input type="color" name="primary_color" class="form-control form-control-color w-100"
                                   value="<?= Sanitize::e($settings['primary_color'] ?? '#6366f1') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Fundo sidebar</label>
                            <input type="color" name="sidebar_bg" class="form-control form-control-color w-100"
                                   value="<?= Sanitize::e($settings['sidebar_bg'] ?? '#0f0a25') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Texto sidebar</label>
                            <input type="color" name="sidebar_text" class="form-control form-control-color w-100"
                                   value="<?= Sanitize::e($settings['sidebar_text'] ?? '#a5b4fc') ?>">
                        </div>

                        <div class="col-12 text-end">
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
