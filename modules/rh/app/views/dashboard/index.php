<?php $monthNames = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro']; ?>

<div class="page-header">
    <h1><i class="bi bi-speedometer2 me-2"></i>Dashboard</h1>
    <small class="text-muted"><?= date('d/m/Y H:i') ?></small>
</div>

<!-- Cards de estatísticas -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3">
                    <i class="bi bi-people"></i>
                </div>
                <div>
                    <div class="stat-value text-primary"><?= $totalAtivos ?></div>
                    <div class="stat-label">Ativos</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3">
                    <i class="bi bi-person-dash"></i>
                </div>
                <div>
                    <div class="stat-value text-warning"><?= $totalAfastados ?></div>
                    <div class="stat-label">Afastados</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-danger bg-opacity-10 text-danger me-3">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div>
                    <div class="stat-value text-danger"><?= $expiredCount ?></div>
                    <div class="stat-label">Vencidos</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-success bg-opacity-10 text-success me-3">
                    <i class="bi bi-briefcase"></i>
                </div>
                <div>
                    <div class="stat-value text-success"><?= $openJobs ?></div>
                    <div class="stat-label">Vagas Abertas</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Coluna esquerda -->
    <div class="col-lg-8">
        <!-- Gráficos principais -->
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-pie-chart me-1"></i> Funcionários por Departamento
                    </div>
                    <div class="card-body">
                        <canvas id="chartDepartments" height="220"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-bar-chart me-1"></i> Tipos de Contrato (Ativos)
                    </div>
                    <div class="card-body">
                        <canvas id="chartContracts" height="220"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-graph-up me-1"></i> Vencimentos nos Próximos 6 Meses
            </div>
            <div class="card-body">
                <canvas id="chartExpiry" height="120"></canvas>
            </div>
        </div>

        <!-- Vencimentos próximos -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span><i class="bi bi-clock me-1"></i> Vencimentos Próximos</span>
                <a href="index.php?m=rh&page=expirations" class="small text-decoration-none">Ver todos</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Funcionário</th><th>Item</th><th>Vencimento</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($upcomingExpirations)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">Nenhum vencimento próximo.</td></tr>
                            <?php else: foreach ($upcomingExpirations as $exp): ?>
                                <?php
                                $expDate = new DateTime($exp['expiry_date']);
                                $diff = (int)(new DateTime())->diff($expDate)->format('%r%a');
                                $badge = $diff < 0 ? 'badge-vencido' : ($diff <= 7 ? 'badge-proximo' : 'badge-valido');
                                $text = $diff < 0 ? 'Vencido' : "Em {$diff}d";
                                ?>
                                <tr>
                                    <td><a href="index.php?m=rh&page=employees&action=show&id=<?= $exp['employee_id'] ?>" class="text-decoration-none"><?= Sanitize::e($exp['employee_name']) ?></a></td>
                                    <td><?= Sanitize::e($exp['title']) ?></td>
                                    <td><?= Sanitize::formatDate($exp['expiry_date']) ?></td>
                                    <td><span class="badge <?= $badge ?>"><?= $text ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Coluna direita -->
    <div class="col-lg-4">
        <!-- Compromissos de hoje -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-calendar-check me-1"></i> Hoje
            </div>
            <div class="card-body">
                <?php if (empty($todayEvents)): ?>
                    <p class="text-muted small text-center">Nenhum compromisso hoje.</p>
                <?php else: foreach ($todayEvents as $ev): ?>
                    <div class="d-flex align-items-start mb-2 pb-2 border-bottom">
                        <div class="me-2">
                            <span class="badge bg-primary"><?= $ev['event_time'] ? substr($ev['event_time'], 0, 5) : '--:--' ?></span>
                        </div>
                        <div>
                            <div class="small fw-semibold"><?= Sanitize::e($ev['title']) ?></div>
                            <?php if ($ev['employee_name']): ?>
                                <small class="text-muted"><?= Sanitize::e($ev['employee_name']) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
                <a href="index.php?m=rh&page=schedules" class="btn btn-outline-primary btn-sm w-100 mt-2">Ver Agenda</a>
            </div>
        </div>

        <!-- Aniversariantes do mês -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-gift me-1"></i> Aniversariantes — <?= $monthNames[$currentMonth] ?>
            </div>
            <div class="card-body">
                <?php if (empty($birthdays)): ?>
                    <p class="text-muted small text-center">Nenhum aniversariante este mês.</p>
                <?php else: foreach ($birthdays as $b): ?>
                    <?php $isToday = ($b['birth_day'] == (int)date('j')); ?>
                    <div class="d-flex align-items-center mb-2 <?= $isToday ? 'bg-warning bg-opacity-10 rounded p-1' : '' ?>">
                        <span class="badge <?= $isToday ? 'bg-warning text-dark' : 'bg-light text-dark' ?> me-2">
                            <?= sprintf('%02d/%02d', $b['birth_day'], $currentMonth) ?>
                        </span>
                        <div class="small">
                            <div class="fw-semibold"><?= Sanitize::e($b['full_name']) ?></div>
                            <small class="text-muted"><?= Sanitize::e($b['department_name'] ?? '') ?></small>
                        </div>
                        <?php if ($isToday): ?>
                            <span class="badge bg-warning text-dark ms-auto">Hoje!</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
                <a href="index.php?m=rh&page=birthdays" class="btn btn-outline-primary btn-sm w-100 mt-2">Ver Todos</a>
            </div>
        </div>

        <!-- Indicadores rápidos -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-graph-up me-1"></i> Indicadores
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span class="small">Total de funcionários</span>
                    <strong><?= $totalFuncionarios ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="small">Admissões (30 dias)</span>
                    <strong class="text-success"><?= $recentAdmissions ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="small">Notificações pendentes</span>
                    <strong class="text-warning"><?= $unreadNotifications ?></strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="small">Vagas abertas</span>
                    <strong class="text-info"><?= $openJobs ?></strong>
                </div>
            </div>
        </div>

        <!-- Candidatos por status -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-person-lines-fill me-1"></i> Candidatos por Status
            </div>
            <div class="card-body">
                <canvas id="chartCandidates" height="220"></canvas>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    // A biblioteca de gráficos vem de CDN: sem ela (rede bloqueada), a
    // página continua funcionando — só os gráficos não são desenhados.
    if (typeof Chart === 'undefined') {
        document.querySelectorAll('canvas[id^="chart"]').forEach(function (el) {
            const aviso = document.createElement('p');
            aviso.className = 'text-muted small text-center my-3';
            aviso.textContent = 'Gráficos indisponíveis (biblioteca não carregada).';
            el.replaceWith(aviso);
        });
        return;
    }
    const data = <?= json_encode($chartsData, JSON_UNESCAPED_UNICODE) ?>;
    const palette = ['#0d6efd','#198754','#ffc107','#dc3545','#6f42c1','#20c997','#fd7e14','#0dcaf0','#6c757d','#d63384'];
    const textColor = '#495057';
    const gridColor = 'rgba(0,0,0,0.06)';

    if (document.getElementById('chartDepartments') && data.departments.labels.length) {
        new Chart(document.getElementById('chartDepartments'), {
            type: 'doughnut',
            data: { labels: data.departments.labels, datasets: [{ data: data.departments.data, backgroundColor: palette, borderWidth: 0 }] },
            options: { plugins: { legend: { position: 'right', labels: { color: textColor, boxWidth: 12 } } }, cutout: '60%' },
        });
    }
    if (document.getElementById('chartContracts') && data.contracts.labels.length) {
        new Chart(document.getElementById('chartContracts'), {
            type: 'bar',
            data: { labels: data.contracts.labels, datasets: [{ label: 'Funcionários', data: data.contracts.data, backgroundColor: palette[0] }] },
            options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { ticks: { color: textColor }, grid: { color: gridColor } }, y: { ticks: { color: textColor }, grid: { display: false } } } },
        });
    }
    if (document.getElementById('chartExpiry') && data.expiry.labels.length) {
        new Chart(document.getElementById('chartExpiry'), {
            type: 'line',
            data: { labels: data.expiry.labels, datasets: [{ label: 'Vencimentos', data: data.expiry.data, tension: 0.35, borderColor: palette[3], backgroundColor: 'rgba(220,53,69,0.1)', fill: true, pointBackgroundColor: palette[3] }] },
            options: { plugins: { legend: { display: false } }, scales: { x: { ticks: { color: textColor }, grid: { display: false } }, y: { beginAtZero: true, ticks: { color: textColor, precision: 0 }, grid: { color: gridColor } } } },
        });
    }
    if (document.getElementById('chartCandidates') && data.candidates.labels.length) {
        new Chart(document.getElementById('chartCandidates'), {
            type: 'pie',
            data: { labels: data.candidates.labels, datasets: [{ data: data.candidates.data, backgroundColor: palette, borderWidth: 0 }] },
            options: { plugins: { legend: { position: 'bottom', labels: { color: textColor, boxWidth: 10, font: { size: 11 } } } } },
        });
    }
})();
</script>
