<?php
/**
 * Aba "Usuários do setor" do painel de configuração (Administração central).
 *
 * Define QUEM enxerga o setor. Como os setores são independentes, esta lista
 * é o que decide quais documentos, indicadores e planos de ação cada pessoa
 * vê no módulo. O POST manda a lista COMPLETA (marcados = ficam).
 */
$linkedSet = array_fill_keys(array_map('intval', $linked), true);
$canAssign = core_can('sectors.assign');
$total     = count($users);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h5 mb-1">
            <i class="bi bi-people me-2"></i>Usuários do setor
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle align-middle"><?php echo e($sector['name']); ?></span>
        </h2>
        <p class="text-muted small mb-0">
            Quem estiver marcado aqui enxerga os documentos, indicadores e planos de ação deste setor —
            e apenas os dos setores em que estiver incluído.
        </p>
    </div>
    <a href="<?php echo e(core_admin_url('documentos', 'sectors')); ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Voltar aos setores
    </a>
</div>

<?php if (!$canAssign): ?>
    <div class="alert alert-info py-2 small">
        <i class="bi bi-info-circle me-1"></i>Você pode consultar os usuários deste setor, mas não alterá-los
        (permissão <code>sectors.assign</code>).
    </div>
<?php endif; ?>

<form method="POST" action="<?php echo url('admin/sector-users-save'); ?>" id="sectorUsersForm">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="sector_id" value="<?php echo (int) $sector['id']; ?>">

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent d-flex flex-wrap gap-2 align-items-center">
            <div class="input-group input-group-sm" style="max-width:320px">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" class="form-control" id="secUserFilter" placeholder="Filtrar por nome, usuário ou e-mail"
                       autocomplete="off" aria-label="Filtrar usuários">
            </div>
            <?php if ($canAssign): ?>
            <div class="btn-group btn-group-sm ms-auto">
                <button type="button" class="btn btn-outline-secondary" data-sec-bulk="all">Marcar visíveis</button>
                <button type="button" class="btn btn-outline-secondary" data-sec-bulk="none">Desmarcar visíveis</button>
            </div>
            <?php endif; ?>
            <span class="small text-muted <?php echo $canAssign ? '' : 'ms-auto'; ?>">
                <span id="secUserCount"><?php echo count($linkedSet); ?></span> de <?php echo $total; ?> usuário(s)
            </span>
        </div>
        <div class="card-body p-0">
            <?php if ($total === 0): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-people display-1"></i>
                    <p class="mt-2 mb-0">Nenhum usuário ativo na plataforma.</p>
                </div>
            <?php else: ?>
            <div class="table-responsive" style="max-height:60vh">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="sticky-top">
                        <tr>
                            <th style="width:3rem"></th>
                            <th>Nome</th>
                            <th>Usuário</th>
                            <th>E-mail</th>
                        </tr>
                    </thead>
                    <tbody id="secUserRows">
                    <?php foreach ($users as $u): $uid = (int) $u['id']; ?>
                        <tr data-sec-search="<?php echo e(mb_strtolower(($u['name'] ?? '') . ' ' . ($u['username'] ?? '') . ' ' . ($u['email'] ?? ''))); ?>">
                            <td>
                                <input class="form-check-input" type="checkbox" name="user_ids[]"
                                       value="<?php echo $uid; ?>" id="secu<?php echo $uid; ?>"
                                       <?php echo isset($linkedSet[$uid]) ? 'checked' : ''; ?>
                                       <?php echo $canAssign ? '' : 'disabled'; ?>>
                            </td>
                            <td><label class="mb-0 fw-semibold" for="secu<?php echo $uid; ?>"><?php echo e($u['name']); ?></label></td>
                            <td class="small text-muted font-monospace"><?php echo e($u['username'] ?: '—'); ?></td>
                            <td class="small text-muted"><?php echo e($u['email'] ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($canAssign && $total > 0): ?>
        <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
            <a href="<?php echo e(core_admin_url('documentos', 'sectors')); ?>" class="btn btn-outline-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar usuários do setor</button>
        </div>
        <?php endif; ?>
    </div>
</form>

<script>
(function () {
    var form   = document.getElementById('sectorUsersForm');
    if (!form) return;
    var filtro = document.getElementById('secUserFilter');
    var rows   = Array.prototype.slice.call(document.querySelectorAll('#secUserRows tr'));
    var conta  = document.getElementById('secUserCount');

    function marcados() {
        return form.querySelectorAll('input[name="user_ids[]"]:checked').length;
    }
    function atualizaContador() { if (conta) conta.textContent = marcados(); }

    // As linhas escondidas pelo filtro continuam no formulário: esconder não
    // é desmarcar, senão filtrar por "UTI" e salvar apagaria todo o resto.
    if (filtro) {
        filtro.addEventListener('input', function () {
            var termo = filtro.value.trim().toLowerCase();
            rows.forEach(function (tr) {
                tr.hidden = termo !== '' && tr.getAttribute('data-sec-search').indexOf(termo) === -1;
            });
        });
    }
    form.querySelectorAll('[data-sec-bulk]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var alvo = btn.getAttribute('data-sec-bulk') === 'all';
            rows.forEach(function (tr) {
                if (tr.hidden) return;                    // só as visíveis
                var cb = tr.querySelector('input[type="checkbox"]');
                if (cb && !cb.disabled) cb.checked = alvo;
            });
            atualizaContador();
        });
    });
    form.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'user_ids[]') atualizaContador();
    });
})();
</script>
