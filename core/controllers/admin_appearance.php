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
use Core\CssSanitizer;
use Core\Flash;

/** Campos de imagem: chave => [rótulo, ajuda, formatos] */
/**
 * Tamanho máximo real de um envio neste servidor: o menor entre o limite do
 * módulo (2 MB) e o do PHP (upload_max_filesize / post_max_size).
 */
function core_appearance_upload_limit(): string
{
    $toBytes = static function (string $v): int {
        $v = trim($v);
        if ($v === '') {
            return 0;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g'     => $n * 1073741824,
            'm'     => $n * 1048576,
            'k'     => $n * 1024,
            default => $n,
        };
    };
    $limits = array_filter([
        2097152,
        $toBytes((string) ini_get('upload_max_filesize')),
        $toBytes((string) ini_get('post_max_size')),
    ]);
    $bytes = $limits ? min($limits) : 2097152;
    return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
}

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

    if ($op === 'import') {
        core_admin_appearance_import();
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

    // Antes/depois na auditoria: "identidade visual atualizada" não diz o que
    // mudou nem permite voltar atrás.
    $antes = Branding::all();
    Branding::save($_POST);
    $depois = Branding::all();
    $mudou  = [];
    foreach ($depois as $k => $v) {
        if (($antes[$k] ?? '') !== $v) {
            $mudou[$k] = ['de' => $antes[$k] ?? '', 'para' => $v];
        }
    }
    Audit::log('branding.save', 'settings', null, $mudou !== []
        ? ['alterados' => array_keys($mudou), 'valores' => $mudou]
        : 'Identidade visual salva sem alterações');

    $cssEnviado = (string) ($_POST['custom_css'] ?? '');
    if ($cssEnviado !== '') {
        if (mb_strlen($cssEnviado) > 16384) {
            $errors[] = sprintf('o CSS tem %s KB e o limite é 16 KB — só os primeiros 16 KB foram gravados.',
                number_format(mb_strlen($cssEnviado) / 1024, 1, ',', '.'));
        } elseif (Branding::get('custom_css') === '' ) {
            $errors[] = 'o CSS foi recusado inteiro pelo filtro de segurança '
                      . '(texto construído para escapar do filtro, como @import aninhado).';
        } elseif (CssSanitizer::wouldChange($cssEnviado)) {
            $errors[] = 'parte do CSS foi removida pelo filtro de segurança (@import, endereço externo ou script).';
        }
    }

    if ($errors) {
        Flash::set('warning', 'Aparência salva, mas: ' . implode(' ', $errors));
    } else {
        Flash::set('success', 'Aparência salva.');
    }
    core_redirect('index.php?m=admin&a=appearance');
}

/**
 * Importa um tema exportado por outra instalação.
 *
 * O arquivo passa por Branding::save(), que valida campo a campo — é a mesma
 * fronteira do formulário. As chaves ausentes voltam ao padrão, para o
 * resultado ser reprodutível: importar duas vezes o mesmo arquivo em
 * instalações diferentes tem de dar a mesma tela.
 */
function core_admin_appearance_import(): void
{
    $file = $_FILES['theme_file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        Flash::set('danger', 'Nenhum arquivo de tema recebido.');
        return;
    }
    if (($file['size'] ?? 0) > 262144 || !is_uploaded_file($file['tmp_name'])) {
        Flash::set('danger', 'Arquivo de tema inválido.');
        return;
    }
    $dados = json_decode((string) file_get_contents($file['tmp_name']), true);
    if (!is_array($dados)) {
        Flash::set('danger', 'O arquivo não é um tema válido (JSON esperado).');
        return;
    }

    // Toda chave conhecida é escrita: ausente = valor padrão, nunca "o que já
    // estava lá" — senão o resultado dependeria do que havia antes.
    $valores = [];
    foreach (Branding::DEFAULTS as $k => $padrao) {
        if (in_array($k, ['logo', 'logo_light', 'favicon', 'login_bg'], true)) {
            continue; // imagens continuam por upload
        }
        $valores[$k] = is_scalar($dados[$k] ?? null) ? (string) $dados[$k] : $padrao;
    }
    Branding::save($valores);
    Audit::log('branding.import', 'settings', null, 'Tema importado de arquivo');
    Flash::set('success', 'Tema importado. As imagens (logotipo, favicon) continuam as desta instalação.');
}

/** Tela de Aparência. */
function core_admin_appearance(): string
{
    Auth::requireGlobalAdmin();
    $b       = Branding::all();
    $images  = core_appearance_images();
    $presets = Branding::presets();
    $current = (string) Core\Settings::get('brand.preset', '');

    /**
     * Campo de cor. $contra é o par "frente|fundo" que o selo de
     * legibilidade avalia ao vivo (assets/core/contrast.js) — é o que
     * impede o administrador de salvar texto branco sobre fundo branco e
     * só descobrir depois, com o menu do hospital inteiro ilegível.
     */
    $color = static function (string $key, string $label, string $value, string $help = '', string $contra = ''): string {
        $value = $value !== '' ? $value : '#ffffff';
        ob_start(); ?>
        <div class="col-12 col-md-6 col-xl-3">
            <label class="form-label small fw-semibold d-flex align-items-center gap-2">
                <span><?= core_e($label) ?></span>
                <?php if ($contra !== ''): ?>
                    <span class="badge" data-contraste="<?= core_e($contra) ?>"></span>
                <?php endif; ?>
            </label>
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
                        <p class="text-muted small">Cada imagem pode ter até <?= core_e(core_appearance_upload_limit()) ?>
                           (limite deste servidor).</p>
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
                    <div class="card-header">Cores da marca</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?= $color('primary', 'Cor primária', $b['primary'], 'Botões, links e destaques.', 'primary|body_bg') ?>
                            <?= $color('accent', 'Cor de destaque', $b['accent'], 'Detalhes e degradês.') ?>
                            <?= $color('body_bg', 'Fundo da área de trabalho', $b['body_bg'], 'O texto do sistema é escuro sobre ele.', '#212529|body_bg') ?>
                            <?= $color('sidebar_bg', 'Fundo do menu lateral', $b['sidebar_bg'], '', 'sidebar_text|sidebar_bg') ?>
                            <?= $color('sidebar_text', 'Texto do menu lateral', $b['sidebar_text'], '', 'sidebar_text|sidebar_bg') ?>
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
                        <?= core_appearance_contrast_warnings($b) ?>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">Cores de estado</div>
                    <div class="card-body">
                        <p class="small text-muted">São as cores de significado, usadas em mais de oitocentos lugares
                        nos módulos: selos de "conforme" e "vencido", alertas, barras de progresso e as colunas de
                        situação das tabelas. Os valores de fábrica são os do Bootstrap.</p>
                        <div class="row g-3">
                            <?php foreach (Branding::STATES as $k => $lbl): ?>
                                <?= $color($k, $lbl, $b[$k], '', $k . '|body_bg') ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">Tema claro e escuro</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label small fw-semibold">Modo</label>
                                <select class="form-select form-select-sm" name="theme_mode" id="f_theme_mode">
                                    <?php foreach (Branding::THEME_MODES as $k => $lbl): ?>
                                        <option value="<?= core_e($k) ?>" <?= $b['theme_mode'] === $k ? 'selected' : '' ?>><?= core_e($lbl) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text small">"Seguir o aparelho" usa a preferência do celular ou do
                                computador de cada pessoa — plantão noturno com tela escura, expediente com tela clara.</div>
                            </div>
                            <div class="col-12 col-md-6 d-flex align-items-center">
                                <div class="form-check form-switch mt-3">
                                    <input type="hidden" name="theme_toggle" value="0">
                                    <input class="form-check-input" type="checkbox" role="switch" value="1"
                                           name="theme_toggle" id="f_theme_toggle"
                                           <?= $b['theme_toggle'] === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="f_theme_toggle">
                                        Deixar cada pessoa alternar o tema pelo menu do usuário
                                    </label>
                                </div>
                            </div>
                            <?= $color('dark_body_bg', 'Fundo no tema escuro', $b['dark_body_bg'], 'O texto no escuro é claro sobre ele.', '#e9ecef|dark_body_bg') ?>
                            <?= $color('dark_sidebar_bg', 'Menu no tema escuro', $b['dark_sidebar_bg']) ?>
                            <?= $color('dark_primary', 'Cor primária no escuro', $b['dark_primary'] !== '' ? $b['dark_primary'] : $b['primary'],
                                       'Vazio = o sistema clareia a primária só o quanto for preciso.') ?>
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
                                        <option value="<?= core_e($k) ?>" <?= $b['font'] === $k ? 'selected' : '' ?>><?= core_e($f['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label small fw-semibold">Densidade</label>
                                <select class="form-select form-select-sm" name="density" id="f_density">
                                    <?php foreach (Branding::DENSITIES as $k => $d): ?>
                                        <option value="<?= core_e($k) ?>" <?= $b['density'] === $k ? 'selected' : '' ?>><?= core_e($d['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label small fw-semibold">Sombras</label>
                                <select class="form-select form-select-sm" name="shadow" id="f_shadow">
                                    <?php foreach (Branding::SHADOWS as $k => $sh): ?>
                                        <option value="<?= core_e($k) ?>" <?= $b['shadow'] === $k ? 'selected' : '' ?>><?= core_e($sh['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label small fw-semibold">Tamanho da letra:
                                    <span id="v_font_size"><?= core_e($b['font_size']) ?></span> px</label>
                                <input type="range" class="form-range" min="13" max="20" step="1"
                                       name="font_size" id="f_font_size" value="<?= core_e($b['font_size']) ?>">
                                <div class="form-text small">Vale para o sistema inteiro.</div>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label small fw-semibold">Cantos:
                                    <span id="v_radius"><?= core_e($b['radius']) ?></span> px</label>
                                <input type="range" class="form-range" min="0" max="24" step="1" name="radius" id="f_radius" value="<?= core_e($b['radius']) ?>">
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label small fw-semibold">Largura do menu:
                                    <span id="v_sidebar_width"><?= core_e($b['sidebar_width']) ?></span> px</label>
                                <input type="range" class="form-range" min="180" max="360" step="4" name="sidebar_width" id="f_sidebar_width" value="<?= core_e($b['sidebar_width']) ?>">
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label small fw-semibold">Altura do topo:
                                    <span id="v_topbar_height"><?= core_e($b['topbar_height']) ?></span> px</label>
                                <input type="range" class="form-range" min="44" max="88" step="2" name="topbar_height" id="f_topbar_height" value="<?= core_e($b['topbar_height']) ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">Menu lateral</div>
                    <div class="card-body">
                        <label class="form-label small fw-semibold">Comportamento no computador</label>
                        <select class="form-select form-select-sm" name="sidebar_mode" id="f_sidebar_mode">
                            <?php foreach (Branding::SIDEBAR_MODES as $k => $lbl): ?>
                                <option value="<?= core_e($k) ?>" <?= $b['sidebar_mode'] === $k ? 'selected' : '' ?>><?= core_e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small">Em telas de 1366 px — posto de enfermagem, recepção — o menu em
                        ícones devolve espaço útil para as tabelas. No celular o menu continua deslizando pela lateral,
                        como hoje.</div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">Tela de entrada</div>
                    <div class="card-body row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Layout</label>
                            <select class="form-select form-select-sm" name="login_layout" id="f_login_layout">
                                <?php foreach (Branding::LOGIN_LAYOUTS as $k => $lbl): ?>
                                    <option value="<?= core_e($k) ?>" <?= $b['login_layout'] === $k ? 'selected' : '' ?>><?= core_e($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text small">O formato lado a lado exige a imagem de fundo enviada acima.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Largura do cartão:
                                <span id="v_login_card_width"><?= core_e($b['login_card_width']) ?></span> px</label>
                            <input type="range" class="form-range" min="340" max="620" step="10"
                                   name="login_card_width" id="f_login_card_width" value="<?= core_e($b['login_card_width']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Rodapé da tela de entrada</label>
                            <input class="form-control form-control-sm" name="login_footer" maxlength="400"
                                   value="<?= core_e($b['login_footer']) ?>"
                                   placeholder="Uso restrito aos colaboradores — LGPD (Lei 13.709/2018) · Suporte: ramal 1234">
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span>CSS do administrador</span>
                        <span class="badge text-bg-light border">avançado</span>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted mb-2">Para o ajuste que nenhum campo cobre: esconder uma coluna na
                        impressão, aumentar a letra de uma tela vista de longe no centro cirúrgico, destacar um selo.
                        O texto é servido como folha de estilo própria e passa por um filtro que remove
                        <code>@import</code>, <code>expression()</code>, <code>javascript:</code> e endereços externos.</p>
                        <textarea class="form-control form-control-sm" name="custom_css" id="f_custom_css" rows="6"
                                  spellcheck="false" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace"
                                  placeholder=".portal-content { max-width: 1600px; }"><?= core_e($b['custom_css']) ?></textarea>
                        <div class="form-text small">Até 16 KB. Um erro aqui só afeta a aparência: para voltar atrás,
                        limpe o campo e salve.</div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Salvar aparência</button>
                    <a class="btn btn-outline-secondary" href="<?= core_module_url('admin', ['a' => 'appearance_export']) ?>">
                        <i class="bi bi-download me-1"></i>Exportar tema
                    </a>
                    <label class="btn btn-outline-secondary mb-0">
                        <i class="bi bi-upload me-1"></i>Importar tema
                        <input type="file" name="theme_file" accept="application/json,.json" hidden
                               onchange="this.form.op.value='import'; this.form.submit();">
                    </label>
                    <a class="btn btn-link" href="<?= core_url('index.php') ?>">Ver o portal</a>
                </div>
            </div>

            <!-- Pré-visualização: a página real, não um desenho dela -->
            <div class="col-12 col-xl-5">
                <div class="card position-sticky" style="top:76px">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span>Pré-visualização</span>
                        <div class="btn-group btn-group-sm ms-auto" role="group" id="bpEsquema">
                            <button type="button" class="btn btn-outline-secondary active" data-esquema="claro">claro</button>
                            <button type="button" class="btn btn-outline-secondary" data-esquema="escuro">escuro</button>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="bpAtualizar">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                    <div class="card-body p-2">
                        <!-- A amostra é renderizada em 1280px e reduzida: dentro de uma
                             coluna estreita, a página real reflui para o formato de
                             celular e deixaria de mostrar o menu lateral. -->
                        <div style="overflow:hidden;border:1px solid var(--portal-border);border-radius:8px;height:560px">
                            <iframe id="brandPreview" title="Pré-visualização da aparência"
                                    style="width:1280px;height:1000px;border:0;background:#fff;
                                           transform:scale(.56);transform-origin:0 0"
                                    name="brandPreviewFrame"></iframe>
                        </div>
                        <p class="small text-muted mt-2 mb-0">Esta é a página real, montada com as mesmas regras do
                        sistema e com os valores do formulário — inclusive tabelas, formulários, alertas, selos de
                        estado e gráfico. Nada é salvo até você clicar em <em>Salvar aparência</em>.</p>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <script>
    // Espera o carregamento dos scripts do rodapé: este bloco é embutido no
    // conteúdo e, sem isto, roda ANTES de assets/core/preview.js existir.
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('brandForm');
        if (!form) { return; }

        // Campo de cor e campo de texto andam juntos. O PortalPreview já
        // observa 'input' no formulário, então aqui só se espelha o valor.
        form.querySelectorAll('[data-color-for]').forEach(function (picker) {
            var alvo = document.getElementById('f_' + picker.getAttribute('data-color-for'));
            if (!alvo) { return; }
            picker.addEventListener('input', function () { alvo.value = picker.value; });
            alvo.addEventListener('input', function () {
                if (/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(alvo.value)) { picker.value = alvo.value; }
            });
        });

        // Números ao lado dos controles deslizantes.
        ['radius', 'sidebar_width', 'topbar_height', 'font_size', 'login_card_width'].forEach(function (k) {
            var campo = document.getElementById('f_' + k), saida = document.getElementById('v_' + k);
            if (campo && saida) {
                campo.addEventListener('input', function () { saida.textContent = campo.value; });
            }
        });

        /* A pré-visualização é a PÁGINA REAL renderizada pelo servidor com os
           valores do formulário. Antes era um desenho em HTML com a matemática
           de cor reescrita em JavaScript — duas implementações da mesma regra,
           que podiam divergir, e que não mostravam tabela, alerta nem gráfico. */
        // Uma implementação só de pré-visualização (assets/core/preview.js).
        // Em POST, para o CSS livre caber — por URL ele era descartado.
        var esquema = 'claro';
        var amostra = PortalPreview.ligar({
            form:   form,
            quadro: 'brandPreview',
            acao:   <?= json_encode(core_module_url('admin', ['a' => 'appearance_preview'])) ?>,
            espera: 350,
            ignorar: ['op', 'theme_file'],
            extra:  function () { return { esquema: esquema }; }
        });
        if (!amostra) { return; }

        // Selos de legibilidade ao lado dos seletores de cor.
        PortalContraste.ligar(form);

        var botao = document.getElementById('bpAtualizar');
        if (botao) { botao.addEventListener('click', amostra.atualizar); }

        var grupo = document.getElementById('bpEsquema');
        if (grupo) {
            grupo.addEventListener('click', function (e) {
                var b = e.target.closest('[data-esquema]');
                if (!b) { return; }
                esquema = b.getAttribute('data-esquema');
                grupo.querySelectorAll('button').forEach(function (x) { x.classList.remove('active'); });
                b.classList.add('active');
                amostra.atualizar();
            });
        }
    });
    </script>
    <?php
    return (string) ob_get_clean();
}

/**
 * Avisos de contraste: nada impede o administrador de salvar texto branco
 * sobre fundo branco e cegar o menu de todo o hospital. O sistema já sabe
 * calcular contraste — aqui ele avisa antes, em vez de deixar acontecer.
 *
 * @param array<string,string> $b
 */
function core_appearance_contrast_warnings(array $b): string
{
    $pares = [
        ['sidebar_text', 'sidebar_bg', 'O texto do menu lateral'],
        ['primary', 'body_bg', 'A cor primária sobre o fundo da área de trabalho'],
    ];
    $avisos = [];
    foreach ($pares as [$frente, $fundo, $rotulo]) {
        $c1 = $b[$frente] ?? '';
        $c2 = $b[$fundo] ?? '';
        if ($c1 === '' || $c2 === '') {
            continue;
        }
        $r = Branding::contrastRatio($c1, $c2);
        if ($r < 3.0) {
            $avisos[] = sprintf('%s quase some no fundo escolhido (contraste %.1f:1; o mínimo legível é 4,5:1).',
                $rotulo, $r);
        } elseif ($r < 4.5) {
            $avisos[] = sprintf('%s está no limite da legibilidade (contraste %.1f:1).', $rotulo, $r);
        }
    }

    if ($avisos === []) {
        return '<div class="alert alert-light border mt-3 mb-0 small">'
             . 'O sistema calcula sozinho os tons de foco e a cor do texto sobre cada fundo, então o contraste '
             . 'continua legível mesmo com cores claras.</div>';
    }
    $html = '<div class="alert alert-warning mt-3 mb-0 small"><strong>Atenção ao contraste</strong><ul class="mb-0 mt-1">';
    foreach ($avisos as $a) {
        $html .= '<li>' . core_e($a) . '</li>';
    }
    return $html . '</ul></div>';
}

/**
 * Exporta o tema como arquivo JSON, para levar a identidade de homologação
 * para produção sem redigitar tudo — e para ter uma cópia das escolhas.
 * As imagens não vão junto: continuam por upload.
 */
function core_admin_appearance_export(): void
{
    Auth::requireGlobalAdmin();

    $b    = Branding::all();
    $tema = ['_formato' => 'tema-plataforma-unificada/1', '_gerado_em' => date('c')];
    foreach (Branding::DEFAULTS as $k => $_) {
        if (in_array($k, ['logo', 'logo_light', 'favicon', 'login_bg'], true)) {
            continue;
        }
        $tema[$k] = $b[$k];
    }

    $nome = 'tema-' . preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower(Branding::name())) . '-' . date('Y-m-d') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nome . '"');
    echo json_encode($tema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Pré-visualização: renderiza uma página de amostra com as MESMAS regras do
 * sistema, a partir dos valores que estão no formulário. Nada é gravado.
 *
 * Tudo o que chega por querystring passa por Branding::normalizeAll() antes de
 * virar CSS — sem isso, um valor cru entraria num bloco <style> servido pelo
 * próprio portal, que é injeção de CSS.
 */
function core_admin_appearance_preview(): void
{
    Auth::requireGlobalAdmin();

    // Aceita POST (o padrão agora) e GET (favoritos antigos). O POST é o que
    // permite mandar o CSS livre junto: por URL ele ficava de fora por causa
    // do limite de tamanho, e a amostra mentia para quem tinha CSS próprio.
    $entrada = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::check();
    }

    $valores = Branding::normalizeAll($entrada);
    if (($entrada['esquema'] ?? 'claro') === 'escuro') {
        $valores['theme_mode'] = 'escuro';
    } elseif ($valores['theme_mode'] === 'auto') {
        $valores['theme_mode'] = 'claro';
    }

    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: SAMEORIGIN');
    require CORE_PATH . '/views/appearance_preview.php';
    exit;
}
