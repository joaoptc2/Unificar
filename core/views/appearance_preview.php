<?php
/**
 * Página de amostra da Administração > Aparência.
 *
 * Mostra, com as regras REAIS do sistema (app.css + as variáveis geradas por
 * Core\Branding), o que o administrador vai ver depois de salvar: topo, menu
 * lateral, cartão, tabela, formulário, os quatro alertas, selos de estado,
 * botões e um gráfico. O desenho em miniatura que existia antes mentia por
 * omissão — não tinha nada disso — e repetia a matemática de cor em
 * JavaScript, que podia divergir da do servidor.
 *
 * Variável: $valores (array já normalizado por Branding::normalizeAll)
 */
$b = $valores;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pré-visualização</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= core_asset('core/app.css') ?>">
    <?= Core\Branding::cssVariables($b) ?>
    <style>
        /* A amostra vive dentro de um quadro estreito: o topo e o menu ficam
           presos ao quadro, não à janela. */
        body.portal-body { padding-top: 0; }
        .portal-topbar { position: static !important; }
        .portal-shell { min-height: 0; }
        .portal-sidebar { position: static !important; }
        .portal-main { margin-left: 0 !important; }
        .portal-content { padding: 1rem; }
    </style>
    <?php
    // CSS livre do administrador. Entra DEPOIS dos ajustes da amostra, como
    // acontece na página real, e passa pelo mesmo filtro do arquivo servido
    // em produção (Core\CssSanitizer) — a amostra não pode aceitar o que o
    // sistema recusaria depois.
    $cssLivre = Core\CssSanitizer::clean((string) ($b['custom_css'] ?? ''));
    if ($cssLivre !== ''):
    ?>
    <style><?= $cssLivre ?></style>
    <?php endif; ?>
</head>
<body class="portal-body">

<nav class="navbar navbar-expand portal-topbar"
     data-bs-theme="<?= Core\Branding::isDark($b['theme_mode'] === 'escuro' ? $b['dark_body_bg'] : ($b['topbar_bg'] ?: $b['primary'])) ? 'dark' : 'light' ?>">
    <div class="container-fluid">
        <a class="navbar-brand" href="#" onclick="return false"><?= core_e(Core\Branding::shortName()) ?></a>
        <ul class="navbar-nav portal-module-nav">
            <li class="nav-item"><a class="nav-link active" href="#" onclick="return false">Documentos</a></li>
            <li class="nav-item"><a class="nav-link" href="#" onclick="return false">RH</a></li>
            <li class="nav-item"><a class="nav-link" href="#" onclick="return false">Manutenção</a></li>
        </ul>
        <ul class="navbar-nav ms-auto">
            <li class="nav-item"><a class="nav-link" href="#" onclick="return false"><i class="bi bi-bell"></i></a></li>
            <li class="nav-item d-flex align-items-center gap-2 ps-2">
                <span class="portal-avatar">A</span>
            </li>
        </ul>
    </div>
</nav>

<div class="portal-shell has-sidebar">
    <aside class="portal-sidebar">
        <div class="portal-sidebar-brand d-flex"><i class="bi bi-folder2-open me-2"></i><span>Documentos</span></div>
        <nav class="portal-sidebar-nav">
            <div class="portal-sidebar-heading">Principal</div>
            <ul class="nav flex-column">
                <li class="nav-item"><a class="nav-link active" href="#" onclick="return false">
                    <i class="bi bi-speedometer2"></i><span class="portal-sidebar-label">Dashboard</span></a></li>
                <li class="nav-item"><a class="nav-link" href="#" onclick="return false">
                    <i class="bi bi-file-earmark-text"></i><span class="portal-sidebar-label">Documentos</span></a></li>
                <li class="nav-item"><a class="nav-link" href="#" onclick="return false">
                    <i class="bi bi-graph-up"></i><span class="portal-sidebar-label">Indicadores</span>
                    <span class="badge text-bg-danger ms-auto">3</span></a></li>
            </ul>
        </nav>
    </aside>

    <main class="portal-main">
        <div class="portal-content">
            <h1 class="h5 mb-3">Amostra da aparência</h1>

            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3">
                    <div class="card h-100"><div class="card-body py-3">
                        <div class="fs-4 fw-semibold">128</div>
                        <div class="small text-muted">Documentos</div>
                    </div></div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card h-100"><div class="card-body py-3">
                        <div class="fs-4 fw-semibold text-success">96%</div>
                        <div class="small text-muted">Conformidade</div>
                    </div></div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card h-100"><div class="card-body py-3">
                        <div class="fs-4 fw-semibold text-warning">7</div>
                        <div class="small text-muted">Vencendo</div>
                    </div></div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card h-100"><div class="card-body py-3">
                        <div class="fs-4 fw-semibold text-danger">2</div>
                        <div class="small text-muted">Vencidos</div>
                    </div></div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header d-flex align-items-center">
                    <span>Documentos vencendo</span>
                    <a class="ms-auto small" href="#" onclick="return false">ver todos</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>Código</th><th>Título</th><th>Setor</th><th>Situação</th></tr></thead>
                        <tbody>
                            <tr><td>POP-014</td><td>Higienização de mãos</td><td>CCIH</td>
                                <td><span class="badge text-bg-success">Vigente</span></td></tr>
                            <tr><td>PRO-008</td><td>Admissão de paciente</td><td>Recepção</td>
                                <td><span class="badge text-bg-warning">Vence em 12 dias</span></td></tr>
                            <tr><td>IT-031</td><td>Calibração de bombas</td><td>Engenharia</td>
                                <td><span class="badge text-bg-danger">Vencido</span></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-12 col-lg-6">
                    <div class="card h-100"><div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Setor</label>
                            <select class="form-select"><option>Todos os setores</option></select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Buscar</label>
                            <input class="form-control" placeholder="código ou título">
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" checked id="pv1">
                            <label class="form-check-label" for="pv1">Somente controlados</label>
                        </div>
                        <button class="btn btn-primary">Filtrar</button>
                        <button class="btn btn-outline-secondary">Limpar</button>
                        <button class="btn btn-outline-danger">Excluir</button>
                    </div></div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="alert alert-success py-2">Documento aprovado e publicado.</div>
                    <div class="alert alert-warning py-2">Três documentos vencem nesta semana.</div>
                    <div class="alert alert-danger py-2">Um documento está vencido.</div>
                    <div class="alert alert-info py-2 mb-3">A próxima revisão começa em 10 dias.</div>
                    <ul class="nav nav-tabs">
                        <li class="nav-item"><a class="nav-link active" href="#" onclick="return false">Ativos</a></li>
                        <li class="nav-item"><a class="nav-link" href="#" onclick="return false">Arquivados</a></li>
                    </ul>
                    <div class="progress mt-3" style="height:10px">
                        <div class="progress-bar" style="width:72%"></div>
                    </div>
                </div>
            </div>

            <div class="card mt-3"><div class="card-body">
                <canvas id="pvChart" height="120"></canvas>
                <div class="small text-muted mt-2">Os gráficos também seguem as cores escolhidas.</div>
            </div></div>
        </div>
    </main>
</div>

<script src="<?= core_asset('core/app.js') ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (!window.Chart || !window.PortalTheme) { return; }
    PortalTheme.applyChartDefaults();
    var cores = PortalTheme.palette();
    new Chart(document.getElementById('pvChart'), {
        type: 'bar',
        data: {
            labels: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun'],
            datasets: [{ label: 'Publicados', data: [12, 19, 9, 17, 14, 21], backgroundColor: cores[0] },
                       { label: 'Revisados', data: [7, 11, 6, 9, 12, 8], backgroundColor: cores[1] }]
        },
        options: { plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
    });
})();
</script>
</body>
</html>
