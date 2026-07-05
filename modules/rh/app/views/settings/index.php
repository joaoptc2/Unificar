<div class="page-header">
    <h1><i class="bi bi-palette me-2"></i>Personalização</h1>
</div>

<form method="POST" action="index.php?page=settings&action=update" id="themeForm">
    <?= Csrf::field() ?>

    <div class="row g-3">
        <!-- Identidade -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-building me-1"></i> Identidade
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Nome do sistema / hospital</label>
                            <input type="text" name="app_name" class="form-control"
                                   value="<?= Sanitize::e($settings['app_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ícone do logo (Bootstrap Icons)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i id="logoIconPreview" class="bi <?= Sanitize::e($settings['theme_logo_icon']) ?>"></i></span>
                                <input type="text" name="theme_logo_icon" class="form-control"
                                       value="<?= Sanitize::e($settings['theme_logo_icon']) ?>"
                                       placeholder="bi-hospital"
                                       oninput="document.getElementById('logoIconPreview').className='bi '+this.value">
                            </div>
                            <small class="text-muted">Ex: bi-hospital, bi-heart-pulse, bi-plus-circle. <a href="https://icons.getbootstrap.com/" target="_blank">Ver todos</a></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cores principais -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-droplet me-1"></i> Cores Principais
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php
                        $colorFields = [
                            'theme_primary'       => 'Cor primária (botões, links, destaques)',
                            'theme_navbar_bg'     => 'Navbar superior (fundo)',
                            'theme_page_bg'       => 'Fundo da página',
                        ];
                        foreach ($colorFields as $key => $label):
                        ?>
                        <div class="col-md-4">
                            <label class="form-label"><?= $label ?></label>
                            <div class="input-group">
                                <input type="color" name="<?= $key ?>" class="form-control form-control-color"
                                       value="<?= Sanitize::e($settings[$key]) ?>" title="<?= $label ?>">
                                <input type="text" class="form-control form-control-sm" readonly
                                       value="<?= Sanitize::e($settings[$key]) ?>"
                                       style="max-width:90px; font-size:0.78rem;"
                                       onclick="this.previousElementSibling.click()">
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-layout-sidebar me-1"></i> Sidebar (Menu Lateral)
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php
                        $sidebarFields = [
                            'theme_sidebar_bg'    => 'Fundo',
                            'theme_sidebar_text'  => 'Texto',
                            'theme_sidebar_hover' => 'Hover / ativo',
                        ];
                        foreach ($sidebarFields as $key => $label):
                        ?>
                        <div class="col-md-4">
                            <label class="form-label"><?= $label ?></label>
                            <div class="input-group">
                                <input type="color" name="<?= $key ?>" class="form-control form-control-color"
                                       value="<?= Sanitize::e($settings[$key]) ?>">
                                <input type="text" class="form-control form-control-sm" readonly
                                       value="<?= Sanitize::e($settings[$key]) ?>"
                                       style="max-width:90px; font-size:0.78rem;">
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Badges / Status -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-tag me-1"></i> Cores de Status (badges)
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php
                        $badgeFields = [
                            'theme_badge_ativo'  => 'Ativo / Válido',
                            'theme_badge_alerta' => 'Alerta / Próximo',
                            'theme_badge_perigo' => 'Erro / Vencido',
                        ];
                        foreach ($badgeFields as $key => $label):
                        ?>
                        <div class="col-md-4">
                            <label class="form-label"><?= $label ?></label>
                            <div class="input-group">
                                <input type="color" name="<?= $key ?>" class="form-control form-control-color"
                                       value="<?= Sanitize::e($settings[$key]) ?>">
                                <span class="badge d-flex align-items-center ms-2" style="background:<?= Sanitize::e($settings[$key]) ?>;">
                                    Exemplo
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Login / Telas Públicas -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Tela de Login (gradiente)
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Cor inicial</label>
                            <input type="color" name="theme_login_gradient_start" class="form-control form-control-color"
                                   value="<?= Sanitize::e($settings['theme_login_gradient_start']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Cor final</label>
                            <input type="color" name="theme_login_gradient_end" class="form-control form-control-color"
                                   value="<?= Sanitize::e($settings['theme_login_gradient_end']) ?>">
                        </div>
                        <div class="col-12">
                            <div class="rounded p-3 text-white text-center fw-semibold" id="gradientPreview"
                                 style="background: linear-gradient(135deg, <?= Sanitize::e($settings['theme_login_gradient_start']) ?>, <?= Sanitize::e($settings['theme_login_gradient_end']) ?>);">
                                Pré-visualização do gradiente
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tipografia e arredondamento -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-fonts me-1"></i> Tipografia e Forma
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Família de fontes</label>
                            <select name="theme_font_family" class="form-select">
                                <?php
                                $fonts = [
                                    "'Segoe UI', system-ui, -apple-system, sans-serif" => "Segoe UI (padrão)",
                                    "'Inter', system-ui, sans-serif"                   => "Inter",
                                    "'Poppins', system-ui, sans-serif"                 => "Poppins",
                                    "'Roboto', system-ui, sans-serif"                  => "Roboto",
                                    "'Nunito', system-ui, sans-serif"                  => "Nunito",
                                    "system-ui, sans-serif"                            => "System UI (nativo)",
                                ];
                                foreach ($fonts as $val => $label):
                                ?>
                                    <option value="<?= Sanitize::e($val) ?>" <?= $settings['theme_font_family'] === $val ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Para Inter, Poppins, Roboto e Nunito, inclua o @import do Google Fonts manualmente no CSS, ou use "Segoe UI" que já funciona sem download.</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Arredondamento (rem)</label>
                            <input type="number" name="theme_border_radius" class="form-control"
                                   value="<?= Sanitize::e($settings['theme_border_radius']) ?>"
                                   min="0" max="2" step="0.125" placeholder="0.75">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Ações -->
    <div class="d-flex gap-2 justify-content-end mt-2 mb-4">
        <form method="POST" action="index.php?page=settings&action=reset" class="d-inline">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-outline-secondary"
                    data-confirm="Restaurar todas as cores e configurações visuais para o padrão?">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Restaurar padrão
            </button>
        </form>
        <button type="submit" form="themeForm" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i> Salvar personalização
        </button>
    </div>
</form>

<script>
// Sync color pickers ↔ text displays
document.querySelectorAll('input[type="color"]').forEach(function (picker) {
    var textInput = picker.parentElement.querySelector('input[type="text"]');
    if (textInput) {
        picker.addEventListener('input', function () { textInput.value = picker.value; });
    }
});
// Gradient preview live update
var gs = document.querySelector('[name="theme_login_gradient_start"]');
var ge = document.querySelector('[name="theme_login_gradient_end"]');
var gp = document.getElementById('gradientPreview');
if (gs && ge && gp) {
    function updateGradient() {
        gp.style.background = 'linear-gradient(135deg, ' + gs.value + ', ' + ge.value + ')';
    }
    gs.addEventListener('input', updateGradient);
    ge.addEventListener('input', updateGradient);
}
</script>
