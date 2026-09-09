    <div class="text-center text-muted small mt-4">
        Precisa alterar sua senha? Acesse <a href="<?= core_url('index.php?m=auth&a=security') ?>">Senha e Segurança</a>.
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Confirmação em botões/links com data-confirm (sem dependências).
document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
        if (!confirm(el.getAttribute('data-confirm') || 'Confirma?')) { e.preventDefault(); }
    });
});
</script>
<?= $extraScripts ?? '' ?>
</body>
</html>
