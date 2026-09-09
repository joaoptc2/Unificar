<?php
/** Aba "Aniversariantes (A4)" — Administração central. Espera $settings, $layouts, $months. */
$saveUrl    = core_admin_url('rh', 'birthdays', ['action' => 'save_config']);
$previewUrl = core_admin_url('rh', 'birthdays', ['action' => 'preview']);
$curMonth   = (int)date('n');
$curYear    = (int)date('Y');
?>
<div class="page-header">
    <h1 class="h5"><i class="bi bi-gift me-2"></i>Aniversariantes — cartaz A4</h1>
    <a href="<?= core_module_url('rh', ['page' => 'birthdays', 'action' => 'print', 'month' => $curMonth, 'year' => $curYear]) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-printer me-1"></i> Abrir cartaz do mês atual (configuração salva)
    </a>
</div>

<form method="POST" action="<?= $saveUrl ?>" id="bdConfigForm">
    <?= Csrf::field() ?>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-layout-text-window me-1"></i> Conteúdo do cartaz</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Título</label>
                            <input type="text" name="title" class="form-control" maxlength="200" value="<?= Sanitize::e($settings['title']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Layout de página (papel timbrado)</label>
                            <select name="layout_id" class="form-select">
                                <option value="0">Padrão do sistema</option>
                                <?php foreach ($layouts as $l): ?>
                                    <option value="<?= (int)$l['id'] ?>" <?= (int)$settings['layout_id'] === (int)$l['id'] ? 'selected' : '' ?>>
                                        <?= Sanitize::e($l['name']) ?> — <?= Sanitize::e($l['page_size']) ?> <?= $l['orientation'] === 'landscape' ? 'paisagem' : 'retrato' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Cadastre layouts em Administração › Layouts de documentos.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Texto de introdução (HTML)</label>
                            <textarea name="intro_html" class="form-control font-monospace" rows="3"><?= Sanitize::e($settings['intro_html']) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Template HTML da lista</label>
                            <textarea name="item_html" class="form-control font-monospace" rows="12"><?= Sanitize::e($settings['item_html']) ?></textarea>
                            <div class="form-text">
                                Use <code>{{lista}}</code> para inserir a tabela gerada automaticamente e/ou um bloco
                                <code>{{#cada}} … {{/cada}}</code> repetido por aniversariante com
                                <code>{{nome}}</code>, <code>{{dia}}</code>, <code>{{data}}</code>, <code>{{departamento}}</code>,
                                <code>{{cargo}}</code>, <code>{{foto}}</code> e <code>{{idade}}</code>.
                                Em todo o cartaz valem <code>{{mes}}</code>, <code>{{ano}}</code>, <code>{{org}}</code> e <code>{{total}}</code>.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">CSS extra</label>
                            <textarea name="css" class="form-control font-monospace" rows="8"><?= Sanitize::e($settings['css']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-sliders me-1"></i> Opções</div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="show_photo" id="bdShowPhoto" value="1" <?= $settings['show_photo'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="bdShowPhoto">Incluir a foto do funcionário</label>
                    </div>
                    <label class="form-label">Ordenação</label>
                    <select name="order" class="form-select mb-3">
                        <option value="day" <?= $settings['order'] === 'day' ? 'selected' : '' ?>>Por dia do mês</option>
                        <option value="name" <?= $settings['order'] === 'name' ? 'selected' : '' ?>>Por nome</option>
                    </select>
                    <button type="submit" class="btn btn-primary w-100 mb-2"><i class="bi bi-check-lg me-1"></i> Salvar layout</button>
                    <button type="button" class="btn btn-outline-secondary w-100" id="bdRestoreDefaults"><i class="bi bi-arrow-counterclockwise me-1"></i> Restaurar template padrão</button>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-eye me-1"></i> Pré-visualização</div>
                <div class="card-body">
                    <p class="small text-muted">Abre o cartaz em nova aba com os valores <strong>atuais do formulário</strong> (sem salvar).</p>
                    <div class="row g-2 mb-2">
                        <div class="col-7">
                            <select name="preview_month" class="form-select form-select-sm" form="bdPreviewForm">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>" <?= $m === $curMonth ? 'selected' : '' ?>><?= $months[$m] ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-5"><input type="number" name="preview_year" class="form-control form-control-sm" value="<?= $curYear ?>" min="2000" max="2100" form="bdPreviewForm"></div>
                    </div>
                    <button type="button" class="btn btn-outline-primary w-100" id="bdPreviewBtn"><i class="bi bi-box-arrow-up-right me-1"></i> Pré-visualizar</button>
                </div>
            </div>
        </div>
    </div>
</form>
<form method="POST" action="<?= $previewUrl ?>" target="_blank" id="bdPreviewForm"><?= Csrf::field() ?></form>

<script>
(function () {
    var main = document.getElementById('bdConfigForm');
    var prev = document.getElementById('bdPreviewForm');
    document.getElementById('bdPreviewBtn').addEventListener('click', function () {
        // Copia os campos do formulário principal para o de pré-visualização (nova aba).
        prev.querySelectorAll('[data-copy]').forEach(function (el) { el.remove(); });
        ['title', 'layout_id', 'intro_html', 'item_html', 'css', 'order'].forEach(function (n) {
            var src = main.querySelector('[name="' + n + '"]');
            if (!src) return;
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = n; h.value = src.value; h.setAttribute('data-copy', '1');
            prev.appendChild(h);
        });
        var photo = main.querySelector('[name="show_photo"]');
        if (photo && photo.checked) {
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = 'show_photo'; h.value = '1'; h.setAttribute('data-copy', '1');
            prev.appendChild(h);
        }
        prev.submit();
    });
    document.getElementById('bdRestoreDefaults').addEventListener('click', function () {
        if (!confirm('Substituir o título, a introdução, o template e o CSS pelos padrões do sistema?')) return;
        main.querySelector('[name="title"]').value = <?= json_encode('Aniversariantes de {{mes}}') ?>;
        main.querySelector('[name="intro_html"]').value = <?= json_encode(BirthdayPoster::defaultIntro()) ?>;
        main.querySelector('[name="item_html"]').value = <?= json_encode(BirthdayPoster::defaultItemHtml()) ?>;
        main.querySelector('[name="css"]').value = <?= json_encode(BirthdayPoster::defaultCss()) ?>;
    });
})();
</script>
