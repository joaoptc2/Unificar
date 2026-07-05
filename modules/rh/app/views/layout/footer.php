        </div><!-- /.container-fluid -->
    </main>

    <!-- Modal de busca global -->
    <div class="modal fade" id="globalSearchModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0">
                            <i class="bi bi-search"></i>
                        </span>
                        <input type="text" id="globalSearchInput" class="form-control border-start-0"
                               placeholder="Buscar funcionário, vencimento, candidato, compromisso..." autocomplete="off">
                    </div>
                </div>
                <div class="modal-body p-0" style="min-height:120px; max-height:60vh; overflow-y:auto;">
                    <div id="globalSearchResults" class="p-3 text-muted small text-center">
                        Digite ao menos 2 caracteres para buscar.
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <small class="text-muted">
                        Atalhos:
                        <kbd>Ctrl</kbd>+<kbd>K</kbd> para abrir,
                        <kbd>Esc</kbd> para fechar,
                        <kbd>↑</kbd>/<kbd>↓</kbd> + <kbd>Enter</kbd> para navegar.
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="main-footer text-center text-muted py-3">
        <small>RH Hospital &copy; <?= date('Y') ?> — Sistema de Gestão de Recursos Humanos</small>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= ASSET_URL ?>js/app.js"></script>
    <script src="<?= ASSET_URL ?>js/global-search.js"></script>
    <script>
    (function(){
        var html = document.documentElement;
        var btn = document.getElementById('themeToggle');
        var icon = document.getElementById('themeIcon');
        var stored = localStorage.getItem('theme') || 'light';
        html.setAttribute('data-bs-theme', stored);
        updateIcon(stored);
        if (btn) btn.addEventListener('click', function(){
            var current = html.getAttribute('data-bs-theme') || 'light';
            var next = current === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-bs-theme', next);
            localStorage.setItem('theme', next);
            updateIcon(next);
        });
        function updateIcon(t){ if(icon) icon.className = t === 'dark' ? 'bi bi-sun fs-5' : 'bi bi-moon-stars fs-5'; }
    })();
    </script>
    <?php if (!empty($extraJs)): ?>
        <?php foreach ($extraJs as $js): ?>
            <script src="<?= $js ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
