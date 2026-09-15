<?php
/**
 * Aba "Aniversariantes (A4)" — Administração central.
 * Espera $settings, $layouts, $months.
 *
 * Dois modos (ver BirthdayPoster):
 *   VISUAL   — opções simples; o HTML e o CSS do cartaz são gerados.
 *   AVANÇADO — o HTML e o CSS gravados valem como estão.
 *
 * A pré-visualização é um iframe que recarrega sozinho ao mexer nas opções:
 * escolher "3 colunas" e ver o resultado é o que torna isto configurável de
 * verdade para quem não escreve HTML.
 */
$saveUrl     = core_admin_url('rh', 'birthdays', ['action' => 'save_config']);
$previewUrl  = core_admin_url('rh', 'birthdays', ['action' => 'preview']);
$advancedUrl = core_admin_url('rh', 'birthdays', ['action' => 'to_advanced']);
$resetUrl    = core_admin_url('rh', 'birthdays', ['action' => 'reset_visual']);
$curMonth    = (int)date('n');
$curYear     = (int)date('Y');
$visual      = ($settings['mode'] ?? 'visual') !== 'avancado';
$accentAtual = $settings['accent'] !== '' ? $settings['accent'] : (string) Core\Branding::get('primary');
?>
<div class="page-header d-flex flex-wrap gap-2 align-items-center">
    <h1 class="h5 mb-0"><i class="bi bi-gift me-2"></i>Aniversariantes — cartaz A4</h1>
    <a href="<?= core_module_url('rh', ['page' => 'birthdays', 'action' => 'print', 'month' => $curMonth, 'year' => $curYear]) ?>"
       target="_blank" class="btn btn-outline-primary btn-sm ms-auto">
        <i class="bi bi-printer me-1"></i> Abrir cartaz do mês (configuração salva)
    </a>
</div>

<form method="POST" action="<?= $saveUrl ?>" id="bdConfigForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="mode" id="bdMode" value="<?= $visual ? 'visual' : 'avancado' ?>">

    <div class="row g-3">
        <!-- ─────────────── Coluna de opções ─────────────── -->
        <div class="col-xl-6">

            <ul class="nav nav-pills mb-3" role="tablist">
                <li class="nav-item">
                    <button class="nav-link <?= $visual ? 'active' : '' ?>" type="button" data-bs-toggle="pill" data-bs-target="#bdPaneVisual">
                        <i class="bi bi-sliders me-1"></i>Visual
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link <?= $visual ? '' : 'active' ?>" type="button" data-bs-toggle="pill" data-bs-target="#bdPaneAvancado">
                        <i class="bi bi-code-slash me-1"></i>Avançado (HTML/CSS)
                    </button>
                </li>
            </ul>

            <!-- Comum aos dois modos -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-type me-1"></i> Texto e papel</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="bdTitle">Título</label>
                        <input type="text" name="title" id="bdTitle" class="form-control" maxlength="200"
                               value="<?= Sanitize::e($settings['title']) ?>">
                        <div class="form-text">Aceita <code>{{mes}}</code>, <code>{{ano}}</code>, <code>{{org}}</code> e <code>{{total}}</code>.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="bdIntro">Texto de introdução</label>
                        <textarea name="intro_html" id="bdIntro" class="form-control" rows="3"><?= Sanitize::e($settings['intro_html']) ?></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label" for="bdLayout">Papel timbrado</label>
                            <select name="layout_id" id="bdLayout" class="form-select">
                                <option value="0">Padrão (sem layout)</option>
                                <?php foreach ($layouts as $l): ?>
                                    <option value="<?= (int)$l['id'] ?>" <?= (int)$l['id'] === (int)$settings['layout_id'] ? 'selected' : '' ?>>
                                        <?= Sanitize::e($l['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Cadastre em Administração › Layouts de documentos.</div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="bdOrder">Ordenação</label>
                            <select name="order" id="bdOrder" class="form-select">
                                <option value="day"  <?= $settings['order'] === 'day' ? 'selected' : '' ?>>Por dia do mês</option>
                                <option value="name" <?= $settings['order'] === 'name' ? 'selected' : '' ?>>Por nome</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-content">
                <!-- ─────── Modo visual ─────── -->
                <div class="tab-pane fade <?= $visual ? 'show active' : '' ?>" id="bdPaneVisual">

                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-grid-3x3-gap me-1"></i> Modelo</div>
                        <div class="card-body">
                            <div class="row g-2 mb-3">
                                <?php foreach (BirthdayPoster::MODELS as $k => $rotulo): ?>
                                <div class="col-6">
                                    <input type="radio" class="btn-check" name="model" id="bdModel<?= $k ?>" value="<?= $k ?>"
                                           <?= $settings['model'] === $k ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-primary w-100 text-start" for="bdModel<?= $k ?>">
                                        <?= Sanitize::e($rotulo) ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="row g-3" id="bdColunasWrap">
                                <div class="col-md-6">
                                    <label class="form-label" for="bdColumns">Colunas <span class="text-muted small">(cartões)</span></label>
                                    <select name="columns" id="bdColumns" class="form-select">
                                        <?php foreach ([1, 2, 3, 4] as $c): ?>
                                            <option value="<?= $c ?>" <?= (int)$settings['columns'] === $c ? 'selected' : '' ?>><?= $c ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="border" id="bdBorder" value="1" <?= $settings['border'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="bdBorder">Borda no cartão</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-palette me-1"></i> Cores e tamanhos</div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label" for="bdAccent">Cor de destaque</label>
                                    <input type="color" name="accent" id="bdAccent" class="form-control form-control-color w-100"
                                           value="<?= Sanitize::e($accentAtual) ?>">
                                    <div class="form-text">Padrão: a cor da marca.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="bdCardBg">Fundo do cartão</label>
                                    <input type="color" name="card_bg" id="bdCardBg" class="form-control form-control-color w-100"
                                           value="<?= Sanitize::e($settings['card_bg']) ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="bdTextColor">Cor do texto</label>
                                    <input type="color" name="text_color" id="bdTextColor" class="form-control form-control-color w-100"
                                           value="<?= Sanitize::e($settings['text_color']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="bdTitleSize">Tamanho do título: <span id="bdTitleSizeVal"><?= (int)$settings['title_size'] ?></span> pt</label>
                                    <input type="range" name="title_size" id="bdTitleSize" class="form-range" min="8" max="48"
                                           value="<?= (int)$settings['title_size'] ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="bdFontSize">Tamanho do nome: <span id="bdFontSizeVal"><?= (int)$settings['font_size'] ?></span> pt</label>
                                    <input type="range" name="font_size" id="bdFontSize" class="form-range" min="6" max="24"
                                           value="<?= (int)$settings['font_size'] ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-person-badge me-1"></i> Foto e informações</div>
                        <div class="card-body">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="show_photo" id="bdShowPhoto" value="1" <?= $settings['show_photo'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="bdShowPhoto">Incluir a foto do funcionário</label>
                            </div>
                            <div class="row g-3 mb-3" id="bdFotoOpcoes">
                                <div class="col-md-6">
                                    <label class="form-label" for="bdPhotoShape">Formato da foto</label>
                                    <select name="photo_shape" id="bdPhotoShape" class="form-select">
                                        <?php foreach (BirthdayPoster::PHOTO_SHAPES as $k => $rotulo): ?>
                                            <option value="<?= $k ?>" <?= $settings['photo_shape'] === $k ? 'selected' : '' ?>><?= Sanitize::e($rotulo) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="bdPhotoSize">Tamanho: <span id="bdPhotoSizeVal"><?= (int)$settings['photo_size'] ?></span> mm</label>
                                    <input type="range" name="photo_size" id="bdPhotoSize" class="form-range" min="8" max="60"
                                           value="<?= (int)$settings['photo_size'] ?>">
                                </div>
                            </div>

                            <label class="form-label">Mostrar em cada aniversariante</label>
                            <div class="d-flex flex-wrap gap-3">
                                <?php
                                $campos = [
                                    'show_day'        => 'Dia',
                                    'show_date'       => 'Data (dia/mês)',
                                    'show_position'   => 'Cargo',
                                    'show_department' => 'Departamento',
                                    'show_age'        => 'Idade',
                                    'show_emoji'      => 'Emoji 🎂',
                                ];
                                foreach ($campos as $k => $rotulo): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="<?= $k ?>" id="bd_<?= $k ?>" value="1"
                                           <?= !empty($settings[$k]) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="bd_<?= $k ?>"><?= Sanitize::e($rotulo) ?></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Salvar layout</button>
                        <button type="submit" class="btn btn-outline-secondary" formaction="<?= $resetUrl ?>">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Restaurar padrão
                        </button>
                        <button type="submit" class="btn btn-outline-secondary ms-auto" formaction="<?= $advancedUrl ?>"
                                onclick="return confirm('Gerar o HTML e o CSS a partir destas opções e passar para o modo avançado? O cartaz passa a usar o código gerado.')">
                            <i class="bi bi-code-slash me-1"></i> Gerar HTML/CSS e editar à mão
                        </button>
                    </div>
                </div>

                <!-- ─────── Modo avançado ─────── -->
                <div class="tab-pane fade <?= $visual ? '' : 'show active' ?>" id="bdPaneAvancado">
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white fw-semibold"><i class="bi bi-code-slash me-1"></i> HTML e CSS</div>
                        <div class="card-body">
                            <div class="alert alert-warning py-2 small">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                No modo avançado o cartaz usa exatamente o código abaixo — as opções visuais deixam de valer.
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="bdItemHtml">Template HTML do corpo</label>
                                <textarea name="item_html" id="bdItemHtml" class="form-control font-monospace" rows="12"><?= Sanitize::e($settings['item_html']) ?></textarea>
                                <div class="form-text">
                                    <code>{{lista}}</code> insere a tabela pronta. O bloco
                                    <code>{{#cada}}…{{/cada}}</code> se repete por aniversariante com
                                    <code>{{nome}}</code>, <code>{{dia}}</code>, <code>{{data}}</code>,
                                    <code>{{cargo}}</code>, <code>{{departamento}}</code>, <code>{{foto}}</code>, <code>{{idade}}</code>.
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="bdCss">CSS</label>
                                <textarea name="css" id="bdCss" class="form-control font-monospace" rows="10"><?= Sanitize::e($settings['css']) ?></textarea>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <button type="submit" class="btn btn-primary" onclick="document.getElementById('bdMode').value='avancado'">
                                    <i class="bi bi-check-lg me-1"></i> Salvar (modo avançado)
                                </button>
                                <button type="submit" class="btn btn-outline-secondary" formaction="<?= $resetUrl ?>"
                                        onclick="return confirm('Voltar ao modo visual com as opções padrão? O HTML e o CSS continuam guardados.')">
                                    <i class="bi bi-sliders me-1"></i> Voltar ao modo visual
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="bdRestoreDefaults">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Restaurar template de exemplo
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ─────────────── Pré-visualização ─────────────── -->
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm position-sticky" style="top:1rem">
                <div class="card-header bg-white d-flex flex-wrap gap-2 align-items-center">
                    <span class="fw-semibold"><i class="bi bi-eye me-1"></i> Pré-visualização</span>
                    <div class="ms-auto d-flex gap-1">
                        <select name="preview_month" class="form-select form-select-sm" style="width:auto">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $m === $curMonth ? 'selected' : '' ?>><?= $months[$m] ?></option>
                            <?php endfor; ?>
                        </select>
                        <input type="number" name="preview_year" class="form-control form-control-sm" style="width:6rem"
                               value="<?= $curYear ?>" min="2000" max="2100">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="bdPreviewBtn" title="Atualizar">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="bdPreviewNova" title="Abrir em nova aba">
                            <i class="bi bi-box-arrow-up-right"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0 bg-body-tertiary">
                    <iframe name="bdPreviewFrame" id="bdPreviewFrame" title="Pré-visualização do cartaz"
                            style="width:100%;height:70vh;min-height:420px;border:0;background:#fff"></iframe>
                </div>
                <div class="card-footer bg-white small text-muted">
                    Mostra os valores <strong>atuais do formulário</strong>, ainda não salvos.
                    Sem aniversariantes no mês escolhido o cartaz sai vazio — troque o mês acima.
                </div>
            </div>
        </div>
    </div>
</form>


<script>
document.addEventListener('DOMContentLoaded', function () {
    var main = document.getElementById('bdConfigForm');
    if (!main) return;

    // Uma implementação só de pré-visualização (assets/core/preview.js).
    // Ela já sabe as regras que antes cada tela repetia: caixa desmarcada
    // não vai, de um grupo de rádios vai só o marcado.
    var amostra = PortalPreview.ligar({
        form:   main,
        quadro: 'bdPreviewFrame',
        acao:   <?= json_encode(core_admin_url('rh', 'birthdays', ['action' => 'preview'])) ?>,
        espera: 500,
        // Mês e ano vivem no cabeçalho da amostra, fora do formulário.
        extra: function () {
            var m = document.querySelector('[name="preview_month"]');
            var a = document.querySelector('[name="preview_year"]');
            return { preview_month: m ? m.value : '', preview_year: a ? a.value : '' };
        }
    });
    if (!amostra) return;

    document.getElementById('bdPreviewBtn').addEventListener('click', amostra.atualizar);
    document.getElementById('bdPreviewNova').addEventListener('click', amostra.emNovaAba);
    document.querySelectorAll('[name="preview_month"],[name="preview_year"]')
        .forEach(function (el) { el.addEventListener('change', amostra.atualizar); });

    // Números ao lado dos controles deslizantes.
    var rotulos = { bdTitleSize: 'bdTitleSizeVal', bdFontSize: 'bdFontSizeVal', bdPhotoSize: 'bdPhotoSizeVal' };
    main.addEventListener('input', function (e) {
        var alvo = e.target && rotulos[e.target.id];
        if (alvo) { document.getElementById(alvo).textContent = e.target.value; }
    });

    // Trocar de aba troca o modo gravado: o que está à vista é o que vale.
    document.querySelectorAll('[data-bs-target="#bdPaneVisual"],[data-bs-target="#bdPaneAvancado"]').forEach(function (b) {
        b.addEventListener('shown.bs.tab', function () {
            document.getElementById('bdMode').value =
                b.getAttribute('data-bs-target') === '#bdPaneAvancado' ? 'avancado' : 'visual';
            amostra.atualizar();
        });
    });

    document.getElementById('bdRestoreDefaults').addEventListener('click', function () {
        if (!confirm('Substituir o template e o CSS pelos de exemplo do sistema?')) return;
        document.getElementById('bdItemHtml').value = <?= json_encode(BirthdayPoster::defaultItemHtml()) ?>;
        document.getElementById('bdCss').value      = <?= json_encode(BirthdayPoster::defaultCss()) ?>;
        amostra.atualizar();
    });
});
</script>
