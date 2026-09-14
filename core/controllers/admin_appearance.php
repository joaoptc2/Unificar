<?php
/**
 * ADMINISTRAÇÃO — Aparência (identidade visual da plataforma).
 *
 * Nome, logotipo, favicon, cores, fonte, cantos, densidade e tela de
 * login. Tudo é gravado por Core\Branding em `settings` (prefixo brand.)
 * e vira variáveis CSS no <head> de todas as páginas, então a mudança
 * vale para o núcleo e para todos os módulos.
 */

declare(strict_types=1);

use Core\Audit;
use Core\Auth;
use Core\Branding;
use Core\Csrf;
use Core\Flash;

/** Campos de imagem: chave => [rótulo, ajuda, formatos] */
function core_appearance_images(): array
{
    return [
        'logo' => [
            'label' => 'Logotipo',
            'help'  => 'Aparece no topo e na tela de login. PNG ou SVG com fundo transparente, altura a partir de 80 px.',
            'accept' => 'image/png,image/jpeg,image/gif,image/webp,image/svg+xml',
        ],
        'logo_light' => [
            'label' => 'Logotipo para fundo escuro',
            'help'  => 'Opcional: versão clara do logotipo, usada quando o topo é escuro.',
            'accept' => 'image/png,image/jpeg,image/gif,image/webp,image/svg+xml',
        ],
        'favicon' => [
            'label' => 'Favicon',
            'help'  => 'Ícone da aba do navegador. PNG quadrado (32×32 ou 64×64), SVG ou ICO.',
            'accept' => 'image/png,image/svg+xml,image/x-icon,image/vnd.microsoft.icon',
        ],
        'login_bg' => [
            'label' => 'Fundo da tela de login',
            'help'  => 'Opcional: imagem de fundo da tela de entrada (uma foto do hospital, por exemplo).',
            'accept' => 'image/png,image/jpeg,image/webp',
        ],
    ];
}

/** Aparência: salvar, aplicar tema pronto ou restaurar o padrão. */
function core_admin_appearance_save(): void
{
    Auth::requireGlobalAdmin();
    Csrf::check();
    $op = (string) ($_POST['op'] ?? 'save');

    if ($op === 'preset') {
        $key = (string) ($_POST['preset'] ?? '');
        if (Branding::applyPreset($key)) {
            Audit::log('branding.preset', 'settings', $key, 'Tema aplicado');
            Flash::set('success', 'Tema aplicado. Ajuste o que quiser e salve.');
        } else {
            Flash::set('error', 'Tema desconhecido.');
        }
        core_redirect('index.php?m=admin&a=appearance');
    }

    if ($op === 'reset') {
        Branding::reset(!empty($_POST['keep_images']));
        Audit::log('branding.reset', 'settings', null, 'Identidade visual restaurada');
        Flash::set('success', 'Identidade visual restaurada para o padrão.');
        core_redirect('index.php?m=admin&a=appearance');
    }

    // Remoções marcadas
    foreach (array_keys(core_appearance_images()) as $key) {
        if (!empty($_POST['remove_' . $key])) {
            Branding::deleteImage($key);
        }
    }

    // Uploads
    $errors = [];
    foreach (array_keys(core_appearance_images()) as $key) {
        if (!isset($_FILES[$key])) {
            continue;
        }
        $r = Branding::uploadImage($key, $_FILES[$key]);
        if (!$r['ok']) {
            $errors[] = core_appearance_images()[$key]['label'] . ': ' . ($r['error'] ?? 'falha no envio.');
        }
    }

    Branding::save($_POST);
    Audit::log('branding.save', 'settings', null, 'Identidade visual atualizada');

    if ($errors) {
        Flash::set('warning', 'Aparência salva, mas: ' . implode(' ', $errors));
    } else {
        Flash::set('success', 'Aparência salva.');
    }
    core_redirect('index.php?m=admin&a=appearance');
}

/** Tela de Aparência. */
function core_admin_appearance(): string
{
    Auth::requireGlobalAdmin();
    $b       = Branding::all();
    $images  = core_appearance_images();
    $presets = Branding::presets();
    $current = (string) Core\Settings::get('brand.preset', '');

    $color = static function (string $key, string $label, string $value, string $help = ''): string {
        $value = $value !== '' ? $value : '#ffffff';
        ob_start(); ?>
        <div class="col-12 col-md-6 col-xl-3">
            <label class="form-label small fw-semibold"><?= core_e($label) ?></label>
            <div class="input-group input-group-sm">
                <input type="color" class="form-control form-control-color" value="<?= core_e($value) ?>"
                       data-color-for="<?= core_e($key) ?>" aria-label="<?= core_e($label) ?>">
                <input type="text" class="form-control" name="<?= core_e($key) ?>" id="f_<?= core_e($key) ?>"
                       value="<?= core_e($value) ?>" pattern="^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$" maxlength="7">
            </div>
            <?php if ($help !== ''): ?><div class="form-text small"><?= core_e($help) ?></div><?php endif; ?>
        </div>
        <?php return (string) ob_get_clean();
    };

    ob_start(); ?>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-palette me-2"></i>Aparência</h1>
        <form method="post" action="<?= core_module_url('admin', ['a' => 'appearance_save']) ?>"
              onsubmit="return confirm('Restaurar a identidade visual padrão?');" class="d-flex align-items-center gap-2">
            <?= Csrf::field() ?>
            <input type="hidden" name="op" value="reset">
            <div class="form-check form-check-inline small mb-0">
                <input class="form-check-input" type="checkbox" name="keep_images" value="1" id="keepImages" checked>
                <label class="form-check-label" for="keepImages">manter imagens</label>
            </div>
            <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Restaurar padrão</button>
        </form>
    </div>

    <p class="text-muted">As escolhas abaixo valem para todo o sistema — topo, menus, botões, tabelas e telas de todos os
        módulos — e também para a tela de login. A pré-visualização ao lado acompanha as mudanças antes de salvar.</p>

    <!-- Temas prontos -->
    <div class="card mb-3">
        <div class="card-header">Temas prontos</div>
        <div class="card-body d-flex flex-wrap gap-2">
            <?php foreach ($presets as $key => $preset): ?>
                <form method="post" action="<?= core_module_url('admin', ['a' => 'appearance_save']) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="op" value="preset">
                    <input type="hidden" name="preset" value="<?= core_e($key) ?>">
                    <button class="btn btn-sm <?= $current === $key ? 'btn-primary' : 'btn-outline-secondary' ?> d-flex align-items-center gap-2">
                        <span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:<?= core_e($preset['values']['primary']) ?>"></span>
                        <span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:<?= core_e($preset['values']['accent']) ?>"></span>
                        <?= core_e($preset['label']) ?>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>

    <form method="post" action="<?= core_module_url('admin', ['a' => 'appearance_save']) ?>" enctype="multipart/form-data" id="brandForm">
        <?= Csrf::field() ?>
        <input type="hidden" name="op" value="save">
        <div class="row g-3">
            <div class="col-12 col-xl-7">

                <div class="card mb-3">
                    <div class="card-header">Identidade</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-7">
                                <label class="form-label">Nome da organização</label>
                                <input class="form-control" name="name" id="f_name" maxlength="120"
                                       value="<?= core_e($b['name'] !== '' ? $b['name'] : Branding::name()) ?>">
                                <div class="form-text">Aparece no título das páginas, nos e-mails e nos documentos.</div>
                            </div>
                            <div class="col-12 col-md-5">
                                <label class="form-label">Nome curto (topo)</label>
                                <input class="form-control" name="short_name" id="f_short_name" maxlength="120"
                                       value="<?= core_e($b['short_name']) ?>" placeholder="opcional">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Mensagem da tela de login</label>
                                <input class="form-control" name="login_message" maxlength="300"
                                       value="<?= core_e($b['login_message']) ?>" placeholder="opcional — ex.: Acesso restrito aos colaboradores">
                            </div>
                        </div>

                        <hr class="my-4">
                        <div class="row g-3">
                            <?php foreach ($images as $key => $img):
                                $path = $b[$key] ?? ''; ?>
                                <div class="col-12 col-md-6">
                                    <label class="form-label"><?= core_e($img['label']) ?></label>
                                    <?php if ($path !== ''): ?>
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <img src="<?= core_e(core_url($path)) ?>" alt="" style="max-height:48px;max-width:150px;background:#eef2f7;border-radius:6px;padding:4px">
                                            <div class="form-check small">
                                                <input class="form-check-input" type="checkbox" name="remove_<?= core_e($key) ?>" value="1" id="rm_<?= core_e($key) ?>">
                                                <label class="form-check-label" for="rm_<?= core_e($key) ?>">remover</label>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" class="form-control form-control-sm" name="<?= core_e($key) ?>" accept="<?= core_e($img['accept']) ?>">
                                    <div class="form-text small"><?= core_e($img['help']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">Cores</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?= $color('primary', 'Cor primária', $b['primary'], 'Botões, links e destaques.') ?>
                            <?= $color('accent', 'Cor de destaque', $b['accent'], 'Detalhes e degradês.') ?>
                            <?= $color('body_bg', 'Fundo da área de trabalho', $b['body_bg']) ?>
                            <?= $color('sidebar_bg', 'Fundo do menu lateral', $b['sidebar_bg']) ?>
                            <?= $color('sidebar_text', 'Texto do menu lateral', $b['sidebar_text']) ?>
                            <div class="col-12 col-md-6 col-xl-3">
                                <label class="form-label small fw-semibold">Estilo do topo</label>
                                <select class="form-select form-select-sm" name="topbar_style" id="f_topbar_style">
                                    <?php foreach (Branding::TOPBAR_STYLES as $k => $lbl): ?>
                                        <option value="<?= core_e($k) ?>" <?= $b['topbar_style'] === $k ? 'selected' : '' ?>><?= core_e($lbl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?= $color('topbar_bg', 'Cor do topo (opcional)', $b['topbar_bg'] !== '' ? $b['topbar_bg'] : $b['primary'], 'Vazio = usa a primária.') ?>
                        </div>
                        <div class="alert alert-light border mt-3 mb-0 small">
                            O sistema calcula sozinho os tons de foco e a cor do texto sobre cada fundo, então o contraste
                            continua legível mesmo com cores claras.
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">Tipografia e formas</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-semibold">Fonte</label>
                                <select class="form-select form-select-sm" name="font" id="f_font">
                                    <?php foreach (Branding::FONTS as $k => $f): ?>
                                        <option value="<?= core_e($k) ?>" data-stack="<?= core_e($f['stack']) ?>" <?= $b['font'] === $k ? 'selected' : '' ?>><?= core_e($f['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-semibold">Densidade</label>
                                <select class="form-select form-select-sm" name="density" id="f_density">
                                    <?php foreach (Branding::DENSITIES as $k => $d): ?>
                                        <option value="<?= core_e($k) ?>" <?= $b['density'] === $k ? 'selected' : '' ?>><?= core_e($d['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label small fw-semibold">Cantos arredondados: <span id="v_radius"><?= core_e($b['radius']) ?></span> px</label>
                                <input type="range" class="form-range" min="0" max="24" step="1" name="radius" id="f_radius" value="<?= core_e($b['radius']) ?>">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label small fw-semibold">Largura do menu: <span id="v_sidebar_width"><?= core_e($b['sidebar_width']) ?></span> px</label>
                                <input type="range" class="form-range" min="180" max="360" step="4" name="sidebar_width" id="f_sidebar_width" value="<?= core_e($b['sidebar_width']) ?>">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label small fw-semibold">Altura do topo: <span id="v_topbar_height"><?= core_e($b['topbar_height']) ?></span> px</label>
                                <input type="range" class="form-range" min="44" max="88" step="2" name="topbar_height" id="f_topbar_height" value="<?= core_e($b['topbar_height']) ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Salvar aparência</button>
                <a class="btn btn-link" href="<?= core_url('index.php') ?>">Ver o portal</a>
            </div>

            <!-- Pré-visualização -->
            <div class="col-12 col-xl-5">
                <div class="card position-sticky" style="top:76px">
                    <div class="card-header">Pré-visualização</div>
                    <div class="card-body">
                        <div id="brandPreview" class="brand-preview">
                            <div class="bp-topbar">
                                <span class="bp-brand"><?= core_e(Branding::shortName()) ?></span>
                                <span class="bp-nav"><span class="bp-nav-item bp-active">Documentos</span><span class="bp-nav-item">RH</span><span class="bp-nav-item">Chat</span></span>
                            </div>
                            <div class="bp-body">
                                <div class="bp-side">
                                    <div class="bp-side-title">Principal</div>
                                    <div class="bp-side-item bp-side-active">Dashboard</div>
                                    <div class="bp-side-item">Documentos</div>
                                    <div class="bp-side-item">Indicadores</div>
                                </div>
                                <div class="bp-main">
                                    <div class="bp-card">
                                        <div class="bp-card-title">Documentos vencendo</div>
                                        <div class="bp-text">Exemplo de conteúdo com <span class="bp-link">um link</span>.</div>
                                        <div class="bp-actions">
                                            <span class="bp-btn">Botão primário</span>
                                            <span class="bp-btn bp-btn-outline">Secundário</span>
                                            <span class="bp-badge">12</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p class="small text-muted mt-2 mb-0">A pré-visualização usa as cores do formulário; o resultado
                            final aparece em todo o sistema depois de salvar.</p>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <style>
    .brand-preview { border: 1px solid var(--portal-border); border-radius: 10px; overflow: hidden; font-size: .78rem; }
    .brand-preview .bp-topbar { display: flex; align-items: center; gap: .75rem; padding: 0 .75rem; height: var(--bp-topbar-h, 44px); background: var(--bp-topbar-bg); color: var(--bp-topbar-text); }
    .brand-preview .bp-brand { font-weight: 700; }
    .brand-preview .bp-nav { display: flex; gap: .25rem; }
    .brand-preview .bp-nav-item { padding: .15rem .5rem; border-radius: var(--bp-radius-sm, 5px); opacity: .85; }
    .brand-preview .bp-nav-item.bp-active { background: rgba(255,255,255,.22); opacity: 1; font-weight: 600; }
    .brand-preview .bp-body { display: flex; min-height: 190px; background: var(--bp-body-bg); }
    .brand-preview .bp-side { width: 40%; max-width: 150px; background: var(--bp-side-bg); color: var(--bp-side-text); padding: .5rem 0; }
    .brand-preview .bp-side-title { font-size: .62rem; text-transform: uppercase; letter-spacing: .06em; opacity: .6; padding: .25rem .6rem; }
    .brand-preview .bp-side-item { padding: .3rem .6rem; border-left: 3px solid transparent; }
    .brand-preview .bp-side-item.bp-side-active { background: var(--bp-side-active-bg); color: var(--bp-side-active); border-left-color: var(--bp-primary); font-weight: 600; }
    .brand-preview .bp-main { flex: 1; padding: .6rem; }
    .brand-preview .bp-card { background: var(--bp-surface); color: var(--bp-text); border: 1px solid rgba(0,0,0,.08); border-radius: var(--bp-radius, 8px); padding: .6rem; }
    .brand-preview .bp-card-title { font-weight: 600; margin-bottom: .3rem; }
    .brand-preview .bp-text { opacity: .8; margin-bottom: .5rem; }
    .brand-preview .bp-link { color: var(--bp-primary); text-decoration: underline; }
    .brand-preview .bp-actions { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }
    .brand-preview .bp-btn { background: var(--bp-primary); color: var(--bp-on-primary); padding: .2rem .6rem; border-radius: var(--bp-radius-sm, 5px); }
    .brand-preview .bp-btn-outline { background: transparent; color: var(--bp-primary); border: 1px solid var(--bp-primary); }
    .brand-preview .bp-badge { background: var(--bp-accent); color: var(--bp-on-accent); padding: .1rem .45rem; border-radius: 10px; }
    </style>

    <script>
    (function () {
        var form = document.getElementById('brandForm');
        if (!form) return;

        // Sincroniza o seletor de cor com o campo de texto (hex)
        form.querySelectorAll('[data-color-for]').forEach(function (picker) {
            var target = document.getElementById('f_' + picker.dataset.colorFor);
            if (!target) return;
            picker.addEventListener('input', function () { target.value = picker.value; paint(); });
            target.addEventListener('input', function () {
                if (/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(target.value)) { picker.value = target.value; paint(); }
            });
        });
        ['f_font', 'f_density', 'f_topbar_style', 'f_short_name'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('input', paint);
            if (el) el.addEventListener('change', paint);
        });
        [['f_radius', 'v_radius'], ['f_sidebar_width', 'v_sidebar_width'], ['f_topbar_height', 'v_topbar_height']].forEach(function (pair) {
            var el = document.getElementById(pair[0]), out = document.getElementById(pair[1]);
            if (!el) return;
            el.addEventListener('input', function () { if (out) out.textContent = el.value; paint(); });
        });

        function hex2rgb(hex) {
            hex = (hex || '').replace('#', '');
            if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
            if (hex.length !== 6) hex = '0d5c8f';
            return [parseInt(hex.slice(0, 2), 16), parseInt(hex.slice(2, 4), 16), parseInt(hex.slice(4, 6), 16)];
        }
        function shade(hex, f) {
            var c = hex2rgb(hex), t = f > 0 ? 255 : 0, a = Math.abs(f);
            return '#' + c.map(function (v) {
                return Math.round(v + (t - v) * a).toString(16).padStart(2, '0');
            }).join('');
        }
        function lum(hex) {
            return hex2rgb(hex).map(function (v) {
                v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
            }).reduce(function (acc, v, i) { return acc + v * [0.2126, 0.7152, 0.0722][i]; }, 0);
        }
        function on(hex) { return lum(hex) > 0.5 ? '#1f2937' : '#ffffff'; }
        function val(id, fallback) { var el = document.getElementById(id); return el && el.value ? el.value : fallback; }

        function paint() {
            var p = val('f_primary', '#0d5c8f'), a = val('f_accent', '#0f9d8f');
            var side = val('f_sidebar_bg', '#ffffff'), sideText = val('f_sidebar_text', '#495057');
            var body = val('f_body_bg', '#f4f6f9'), style = val('f_topbar_style', 'gradient');
            var topBase = val('f_topbar_bg', p) || p;
            var topBg = style === 'solid' ? topBase
                      : style === 'dark' ? 'linear-gradient(90deg,#111827,#1f2937)'
                      : style === 'light' ? '#ffffff'
                      : 'linear-gradient(90deg,' + shade(topBase, -0.22) + ',' + topBase + ')';
            var topText = style === 'light' ? '#1f2937' : style === 'dark' ? '#f8fafc' : on(topBase);
            var sideDark = lum(side) < 0.5, bodyDark = lum(body) < 0.5;
            var el = document.getElementById('brandPreview');
            var font = document.getElementById('f_font');
            var set = function (k, v) { el.style.setProperty(k, v); };
            set('--bp-primary', p); set('--bp-on-primary', on(p));
            set('--bp-accent', a); set('--bp-on-accent', on(a));
            set('--bp-topbar-bg', topBg); set('--bp-topbar-text', topText);
            set('--bp-side-bg', side); set('--bp-side-text', sideText);
            set('--bp-side-active-bg', sideDark ? 'rgba(255,255,255,.12)' : shade(p, 0.88));
            set('--bp-side-active', sideDark ? '#ffffff' : shade(p, -0.18));
            set('--bp-body-bg', body);
            set('--bp-surface', bodyDark ? '#1f2937' : '#ffffff');
            set('--bp-text', bodyDark ? '#e5e7eb' : '#212529');
            set('--bp-radius', val('f_radius', '8') + 'px');
            set('--bp-radius-sm', Math.round(val('f_radius', '8') * 0.6) + 'px');
            set('--bp-topbar-h', Math.max(36, val('f_topbar_height', '56') * 0.8) + 'px');
            if (font && font.selectedOptions[0]) el.style.fontFamily = font.selectedOptions[0].dataset.stack || '';
            var brand = el.querySelector('.bp-brand');
            var short = document.getElementById('f_short_name'), name = document.getElementById('f_name');
            if (brand) brand.textContent = (short && short.value) || (name && name.value) || 'Portal';
        }
        paint();
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}
