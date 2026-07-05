<div class="page-header">
    <h1><i class="bi bi-palette me-2"></i>Configurações Visuais</h1>
    <div class="d-flex gap-2">
        <form method="POST" action="<?php echo url('admin/settings_reset'); ?>"
              data-confirm="Restaurar TODAS as configurações ao padrão original? Esta ação não pode ser desfeita.">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Restaurar padrão
            </button>
        </form>
        <a href="<?php echo url('admin'); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
    </div>
</div>

<form method="POST" action="<?php echo url('admin/settings_save'); ?>" id="settingsForm">
    <?php echo csrf_field(); ?>

    <div class="row g-3">
        <!-- ── Painel de edição ─────────────────────────────────────────── -->
        <div class="col-lg-7">
            <?php foreach ($groups as $group_name => $fields): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <?php
                    $group_icons = [
                        'Identidade' => 'bi-tag',
                        'Cores' => 'bi-palette',
                        'Sidebar' => 'bi-layout-sidebar',
                        'Tipografia' => 'bi-fonts',
                        'Cards' => 'bi-card-heading',
                        'Tela de Login' => 'bi-box-arrow-in-right',
                        'Avançado' => 'bi-code-slash',
                    ];
                    $icon = $group_icons[$group_name] ?? 'bi-gear';
                    ?>
                    <i class="bi <?php echo $icon; ?> me-1"></i><?php echo e($group_name); ?>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php foreach ($fields as $key => $meta):
                            $value = $current[$key] ?? ($defaults[$key] ?? '');
                            $type  = $meta['type'];
                            $col   = ($type === 'textarea') ? '12' : (($type === 'text' || $type === 'select') ? '12' : '6');
                        ?>
                        <div class="col-md-<?php echo $col; ?>">
                            <label class="form-label" for="s_<?php echo $key; ?>">
                                <?php echo e($meta['label']); ?>
                            </label>

                            <?php if ($type === 'color'): ?>
                                <div class="input-group input-group-sm">
                                    <input type="color" class="form-control form-control-color"
                                           id="s_<?php echo $key; ?>" name="<?php echo $key; ?>"
                                           value="<?php echo e($value); ?>"
                                           data-live="<?php echo $key; ?>">
                                    <input type="text" class="form-control form-control-sm font-monospace"
                                           value="<?php echo e($value); ?>"
                                           data-color-text="<?php echo $key; ?>"
                                           maxlength="7" pattern="#[0-9a-fA-F]{6}"
                                           style="max-width:100px">
                                </div>

                            <?php elseif ($type === 'range'): ?>
                                <div class="d-flex align-items-center gap-2">
                                    <input type="range" class="form-range flex-grow-1"
                                           id="s_<?php echo $key; ?>" name="<?php echo $key; ?>"
                                           value="<?php echo e($value); ?>"
                                           min="<?php echo $meta['min'] ?? 0; ?>"
                                           max="<?php echo $meta['max'] ?? 100; ?>"
                                           step="<?php echo $meta['step'] ?? 1; ?>"
                                           data-live="<?php echo $key; ?>">
                                    <span class="badge bg-light text-dark font-monospace"
                                          data-range-value="<?php echo $key; ?>"><?php echo e($value); ?></span>
                                </div>

                            <?php elseif ($type === 'select'): ?>
                                <select class="form-select form-select-sm"
                                        id="s_<?php echo $key; ?>" name="<?php echo $key; ?>"
                                        data-live="<?php echo $key; ?>">
                                    <?php foreach (($meta['options'] ?? []) as $opt_val => $opt_label): ?>
                                        <option value="<?php echo e($opt_val); ?>"
                                                <?php echo $value === $opt_val ? 'selected' : ''; ?>>
                                            <?php echo e($opt_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                            <?php elseif ($type === 'textarea'): ?>
                                <textarea class="form-control form-control-sm font-monospace"
                                          id="s_<?php echo $key; ?>" name="<?php echo $key; ?>"
                                          rows="4" placeholder="/* seu CSS aqui */"
                                          data-live="<?php echo $key; ?>"><?php echo e($value); ?></textarea>

                            <?php elseif ($type === 'icon'): ?>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="bi <?php echo e($value); ?>" id="iconPreview"></i></span>
                                    <input type="text" class="form-control form-control-sm"
                                           id="s_<?php echo $key; ?>" name="<?php echo $key; ?>"
                                           value="<?php echo e($value); ?>"
                                           placeholder="bi-hospital"
                                           data-live="<?php echo $key; ?>">
                                </div>

                            <?php else: ?>
                                <input type="text" class="form-control form-control-sm"
                                       id="s_<?php echo $key; ?>" name="<?php echo $key; ?>"
                                       value="<?php echo e($value); ?>"
                                       data-live="<?php echo $key; ?>">
                            <?php endif; ?>

                            <?php if (!empty($meta['help'])): ?>
                                <small class="text-muted"><?php echo e($meta['help']); ?></small>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <button type="submit" class="btn btn-primary w-100 mb-4">
                <i class="bi bi-check-lg me-2"></i>Salvar configurações
            </button>
        </div>

        <!-- ── Preview ao vivo ──────────────────────────────────────────── -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-3 position-sticky" style="top: 70px">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-eye me-1"></i>Preview em tempo real
                </div>
                <div class="card-body p-0">
                    <!-- Mini navbar -->
                    <div id="pv_navbar" class="d-flex align-items-center px-3 py-2 text-white"
                         style="background:<?php echo e($current['navbar_bg']); ?>">
                        <i class="bi <?php echo e($current['logo_icon']); ?> me-2" id="pv_logo_icon"></i>
                        <strong class="small" id="pv_app_name"><?php echo e($current['app_name']); ?></strong>
                        <span class="ms-auto small opacity-75"><i class="bi bi-person-circle"></i></span>
                    </div>
                    <div class="d-flex" style="min-height:260px">
                        <!-- Mini sidebar -->
                        <div id="pv_sidebar" class="flex-shrink-0 py-2" style="width:140px; background:<?php echo e($current['sidebar_bg']); ?>">
                            <div class="px-2 mb-1"><small class="text-uppercase" style="font-size:.55rem; color:<?php echo e($current['sidebar_text']); ?>;letter-spacing:.5px">Menu</small></div>
                            <?php
                            $pv_items = [
                                ['bi-speedometer2', 'Dashboard', true],
                                ['bi-folder2-open', 'Documentos', false],
                                ['bi-graph-up', 'Indicadores', false],
                                ['bi-bell', 'Notificações', false],
                            ];
                            foreach ($pv_items as $pvi): ?>
                            <div class="d-flex align-items-center px-2 py-1 pv-nav-item <?php echo $pvi[2] ? 'pv-active' : ''; ?>"
                                 style="font-size:.7rem; cursor:default; border-left:2px solid <?php echo $pvi[2] ? $current['primary_color'] : 'transparent'; ?>;
                                        background:<?php echo $pvi[2] ? $current['sidebar_hover_bg'] : 'transparent'; ?>;
                                        color:<?php echo $pvi[2] ? $current['sidebar_active_text'] : $current['sidebar_text']; ?>">
                                <i class="bi <?php echo $pvi[0]; ?> me-1" style="font-size:.65rem"></i><?php echo $pvi[1]; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <!-- Mini content -->
                        <div id="pv_body" class="flex-grow-1 p-2" style="background:<?php echo e($current['body_bg']); ?>">
                            <div class="d-flex gap-1 mb-2">
                                <?php foreach (['#0d6efd'=>'12', '#10b981'=>'5', '#f59e0b'=>'2'] as $c => $n): ?>
                                <div class="pv-card flex-fill p-1 bg-white text-center"
                                     style="border-radius:<?php echo $current['card_border_radius']; ?>rem; box-shadow:<?php echo $current['card_shadow']; ?>; font-size:.6rem">
                                    <strong style="font-size:.85rem;color:<?php echo $c; ?>"><?php echo $n; ?></strong><br>
                                    <span class="text-muted">Items</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="pv-card bg-white p-2"
                                 style="border-radius:<?php echo $current['card_border_radius']; ?>rem; box-shadow:<?php echo $current['card_shadow']; ?>">
                                <div class="d-flex align-items-center mb-1">
                                    <span class="fw-semibold" style="font-size:.7rem">Documentos</span>
                                    <span class="ms-auto badge text-white" id="pv_btn" style="font-size:.5rem;background:<?php echo e($current['primary_color']); ?>">Novo</span>
                                </div>
                                <div style="font-size:.6rem" class="text-muted">Alvará de Func. &middot; <span class="badge" style="background:#10b981;font-size:.45rem">Válido</span></div>
                                <div style="font-size:.6rem" class="text-muted">Certificado ISO &middot; <span class="badge" style="background:#f59e0b;font-size:.45rem">Vencendo</span></div>
                            </div>
                        </div>
                    </div>
                    <!-- Mini footer -->
                    <div class="text-center py-1 border-top" style="font-size:.55rem;color:#64748b" id="pv_footer">
                        <span id="pv_footer_text"><?php echo e($current['footer_text'] ?: $current['app_name'] . ' &copy; ' . date('Y')); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
(function() {
    // ── Sincroniza color input ↔ text input ──────────────────────────
    document.querySelectorAll('[data-color-text]').forEach(function(txt) {
        var key = txt.dataset.colorText;
        var picker = document.querySelector('input[type="color"][data-live="'+key+'"]');
        if (!picker) return;
        picker.addEventListener('input', function() { txt.value = picker.value; updatePreview(); });
        txt.addEventListener('input', function() {
            if (/^#[0-9a-fA-F]{6}$/.test(txt.value)) { picker.value = txt.value; updatePreview(); }
        });
    });

    // ── Sincroniza range ↔ badge ─────────────────────────────────────
    document.querySelectorAll('input[type="range"][data-live]').forEach(function(r) {
        var badge = document.querySelector('[data-range-value="'+r.dataset.live+'"]');
        r.addEventListener('input', function() { if(badge) badge.textContent = r.value; updatePreview(); });
    });

    // ── Qualquer input com data-live atualiza preview ────────────────
    document.querySelectorAll('[data-live]').forEach(function(el) {
        el.addEventListener('input', updatePreview);
        el.addEventListener('change', updatePreview);
    });

    function val(key) {
        var el = document.querySelector('[data-live="'+key+'"]');
        return el ? (el.value || '') : '';
    }

    function updatePreview() {
        // Navbar
        var nb = document.getElementById('pv_navbar');
        nb.style.background = val('navbar_bg');

        // App name
        document.getElementById('pv_app_name').textContent = val('app_name') || 'Sistema';

        // Logo icon
        var li = document.getElementById('pv_logo_icon');
        li.className = 'bi ' + (val('logo_icon') || 'bi-hospital') + ' me-2';

        // Icon preview (na sidebar do form)
        var ip = document.getElementById('iconPreview');
        if (ip) ip.className = 'bi ' + (val('logo_icon') || 'bi-hospital');

        // Sidebar
        var sb = document.getElementById('pv_sidebar');
        sb.style.background = val('sidebar_bg');

        var navItems = sb.querySelectorAll('.pv-nav-item');
        navItems.forEach(function(item) {
            var isActive = item.classList.contains('pv-active');
            item.style.color = isActive ? val('sidebar_active_text') : val('sidebar_text');
            item.style.background = isActive ? val('sidebar_hover_bg') : 'transparent';
            item.style.borderLeftColor = isActive ? val('primary_color') : 'transparent';
        });

        // Headings (small text in sidebar)
        var sh = sb.querySelector('small');
        if (sh) sh.style.color = val('sidebar_text');

        // Body
        document.getElementById('pv_body').style.background = val('body_bg');

        // Cards
        var radius = val('card_border_radius') + 'rem';
        var shadow = val('card_shadow');
        document.querySelectorAll('.pv-card').forEach(function(c) {
            c.style.borderRadius = radius;
            c.style.boxShadow = shadow;
        });

        // Primary color button
        document.getElementById('pv_btn').style.background = val('primary_color');

        // Footer
        var ft = val('footer_text') || (val('app_name') + ' &copy; ' + new Date().getFullYear());
        document.getElementById('pv_footer_text').innerHTML = ft.replace(/</g,'&lt;').replace(/>/g,'&gt;') || ft;
    }
})();
</script>
