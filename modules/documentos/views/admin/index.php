<div class="page-header">
    <h1><i class="bi bi-gear me-2"></i>Administração</h1>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-5">
                <i class="bi bi-people display-4 text-primary mb-3"></i>
                <h5 class="fw-bold">Usuários &amp; Setores</h5>
                <p class="text-muted small">Associação de setores dos usuários com acesso ao módulo</p>
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
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-5">
                <i class="bi bi-tags display-4 text-info mb-3"></i>
                <h5 class="fw-bold">Categorias</h5>
                <p class="text-muted small">Taxonomia de documentos</p>
                <div class="display-6 fw-bold text-info mb-3"><?php echo (int) ($stats['total_categories'] ?? 0); ?></div>
                <a href="<?php echo url('admin/categories'); ?>" class="btn btn-info text-white">
                    <i class="bi bi-arrow-right me-1"></i>Gerenciar
                </a>
            </div>
        </div>
    </div>
    <?php if (is_admin()): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-5">
                <i class="bi bi-hospital display-4 text-secondary mb-3"></i>
                <h5 class="fw-bold">Hospitais / Unidades</h5>
                <p class="text-muted small">Unidades do módulo (legado multi-hospital)</p>
                <a href="<?php echo url('admin/hospitals'); ?>" class="btn btn-secondary mt-4">
                    <i class="bi bi-arrow-right me-1"></i>Gerenciar
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-5">
                <i class="bi bi-people-fill display-4 text-danger mb-3"></i>
                <h5 class="fw-bold">Usuários (central)</h5>
                <p class="text-muted small">Criação de usuários, senhas e permissões — administração da plataforma</p>
                <a href="<?php echo core_url('index.php?m=admin&a=users'); ?>" class="btn btn-danger mt-4">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Abrir
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
