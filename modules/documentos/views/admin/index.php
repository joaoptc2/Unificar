<div class="page-header">
    <h1><i class="bi bi-gear me-2"></i>Administração</h1>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-5">
                <i class="bi bi-people display-4 text-primary mb-3"></i>
                <h5 class="fw-bold">Usuários</h5>
                <p class="text-muted small">Gerenciar usuários e permissões</p>
                <div class="display-6 fw-bold text-primary mb-3"><?php echo (int) $stats['total_users']; ?></div>
                <a href="<?php echo url('admin/users'); ?>" class="btn btn-primary">
                    <i class="bi bi-arrow-right me-1"></i>Gerenciar
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-5">
                <i class="bi bi-diagram-3 display-4 text-success mb-3"></i>
                <h5 class="fw-bold">Setores</h5>
                <p class="text-muted small">Departamentos e unidades</p>
                <div class="display-6 fw-bold text-success mb-3"><?php echo (int) ($stats['total_sectors'] ?? 0); ?></div>
                <a href="<?php echo url('admin/sectors'); ?>" class="btn btn-success">
                    <i class="bi bi-arrow-right me-1"></i>Gerenciar
                </a>
            </div>
        </div>
    </div>
    <?php if (is_admin()): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-5">
                <i class="bi bi-palette display-4 text-info mb-3"></i>
                <h5 class="fw-bold">Aparência</h5>
                <p class="text-muted small">Cores, fontes e identidade visual</p>
                <a href="<?php echo url('admin/settings'); ?>" class="btn btn-info text-white mt-4">
                    <i class="bi bi-arrow-right me-1"></i>Configurar
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
