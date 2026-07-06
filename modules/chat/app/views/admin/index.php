<div class="page-header">
    <h1><i class="bi bi-gear me-2"></i>Administração</h1>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon bg-primary-subtle text-primary"><i class="bi bi-people"></i></div>
            <div class="stat-value"><?= $totalUsers ?? 0 ?></div>
            <div class="stat-label">Usuários</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon bg-success-subtle text-success"><i class="bi bi-hash"></i></div>
            <div class="stat-value"><?= $totalChannels ?? 0 ?></div>
            <div class="stat-label">Canais</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon bg-info-subtle text-info"><i class="bi bi-chat"></i></div>
            <div class="stat-value"><?= $messagesToday ?? 0 ?></div>
            <div class="stat-label">Mensagens hoje</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon bg-warning-subtle text-warning"><i class="bi bi-kanban"></i></div>
            <div class="stat-value"><?= $activeTasks ?? 0 ?></div>
            <div class="stat-label">Tarefas ativas</div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <i class="bi bi-people display-4 text-primary"></i>
                <h5 class="mt-3">Gerenciar Usuários</h5>
                <p class="text-muted">Usuários e permissões são geridos na administração central da plataforma</p>
                <a href="<?= core_url('index.php?m=admin&a=users') ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-arrow-right me-1"></i> Gerenciar
                </a>
            </div>
        </div>
    </div>
    <?php if (core_can('admin.settings')): ?>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <i class="bi bi-palette display-4 text-success"></i>
                <h5 class="mt-3">Configurações</h5>
                <p class="text-muted">Personalizar nome, cores e configurações do sistema</p>
                <a href="index.php?m=chat&page=admin&action=settings" class="btn btn-success btn-sm">
                    <i class="bi bi-arrow-right me-1"></i> Configurar
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <i class="bi bi-journal-text display-4 text-warning"></i>
                <h5 class="mt-3">Log de Atividades</h5>
                <p class="text-muted">Auditoria de ações do sistema</p>
                <a href="index.php?m=chat&page=admin&action=audit" class="btn btn-warning btn-sm"><i class="bi bi-arrow-right me-1"></i> Ver Logs</a>
            </div>
        </div>
    </div>
    <?php if (core_can('emojis.view')): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <i class="bi bi-emoji-smile display-4 text-info"></i>
                <h5 class="mt-3">Emojis Personalizados</h5>
                <p class="text-muted">Gerenciar emojis customizados</p>
                <a href="index.php?m=chat&page=admin&action=emojis" class="btn btn-info btn-sm"><i class="bi bi-arrow-right me-1"></i> Gerenciar</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (core_can('admin.export')): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <i class="bi bi-download display-4 text-danger"></i>
                <h5 class="mt-3">Exportar Dados</h5>
                <p class="text-muted">Exportar mensagens, usuários e dados</p>
                <a href="index.php?m=chat&page=admin&action=export" class="btn btn-danger btn-sm"><i class="bi bi-arrow-right me-1"></i> Exportar</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (core_can('categories.view')): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <i class="bi bi-collection display-4 text-secondary"></i>
                <h5 class="mt-3">Categorias de Canais</h5>
                <p class="text-muted">Organizar canais em grupos</p>
                <a href="index.php?m=chat&page=admin&action=categories" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-right me-1"></i> Gerenciar</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
