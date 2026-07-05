<?php
/**
 * MÓDULO DE INSPEÇÕES — Design System "RH Hospital"
 *
 * Dual view (similar a cleaning.php):
 *   ?page=inspections               → lista execuções passadas
 *   ?page=inspections&action=routes → CRUD de rotas de inspeção
 *   ?page=inspections&action=execute&route_id=X → formulário de execução
 */
requireModule('inspections');

$hid    = hospitalId();
$action = $_GET['action'] ?? 'list';

// ============================================================
// PROCESSAR POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    requireWrite();
    $act = $_POST['action'] ?? '';

    /* ----- Rotas ------------------------------------------------ */
    if ($act === 'add_route' || $act === 'edit_route') {
        $id          = (int)($_POST['id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '') ?: null;
        $status      = $_POST['status'] ?? 'active';

        // Locais: cada linha vira um item do JSON array
        $raw = trim($_POST['locations'] ?? '');
        $locations = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $locations[] = $line;
            }
        }
        $locJson = $locations ? json_encode($locations, JSON_UNESCAPED_UNICODE) : '[]';

        if ($title === '' || empty($locations)) {
            flash('error', 'Título e ao menos um local são obrigatórios.');
            redirect(url('inspections', ['action' => 'routes']));
        }

        try {
            if ($act === 'add_route') {
                db()->prepare("
                    INSERT INTO man_inspection_routes (hospital_id, title, description, locations, status)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$hid, $title, $description, $locJson, 'active']);
                auditLog('create', 'inspection_routes', (int)db()->lastInsertId());
                flash('success', 'Rota de inspeção criada!');
            } else {
                db()->prepare("
                    UPDATE man_inspection_routes
                    SET title = ?, description = ?, locations = ?, status = ?
                    WHERE id = ? AND hospital_id = ?
                ")->execute([$title, $description, $locJson, $status, $id, $hid]);
                auditLog('update', 'inspection_routes', $id);
                flash('success', 'Rota atualizada.');
            }
        } catch (\Throwable $ex) {
            flash('error', 'Erro ao salvar rota: ' . $ex->getMessage());
        }
        redirect(url('inspections', ['action' => 'routes']));
    }

    if ($act === 'delete_route') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            db()->prepare("DELETE FROM man_inspection_routes WHERE id = ? AND hospital_id = ?")
                ->execute([$id, $hid]);
            auditLog('delete', 'inspection_routes', $id);
            flash('success', 'Rota removida.');
        } catch (\Throwable $ex) {
            flash('error', 'Erro ao remover rota.');
        }
        redirect(url('inspections', ['action' => 'routes']));
    }

    /* ----- Execução --------------------------------------------- */
    if ($act === 'execute') {
        $routeId     = (int)($_POST['route_id'] ?? 0);
        $executedBy  = trim($_POST['executed_by_name'] ?? '');
        $observation = trim($_POST['observation'] ?? '') ?: null;
        $results     = $_POST['results'] ?? [];

        if ($routeId <= 0 || $executedBy === '') {
            flash('error', 'Rota e nome do executor são obrigatórios.');
            redirect(url('inspections', ['action' => 'execute', 'route_id' => $routeId]));
        }

        // Validar que a rota pertence ao hospital
        try {
            $chk = db()->prepare("SELECT id FROM man_inspection_routes WHERE id = ? AND hospital_id = ?");
            $chk->execute([$routeId, $hid]);
            if (!$chk->fetch()) {
                flash('error', 'Rota não encontrada.');
                redirect(url('inspections'));
            }
        } catch (\Throwable $ex) {
            flash('error', 'Erro ao validar rota.');
            redirect(url('inspections'));
        }

        // Normalizar resultados: array de {location, status, observation}
        $resultsClean = [];
        if (is_array($results)) {
            foreach ($results as $idx => $r) {
                $resultsClean[] = [
                    'location'    => trim($r['location'] ?? ''),
                    'status'      => in_array($r['status'] ?? '', ['pass', 'fail', 'na'], true) ? $r['status'] : 'na',
                    'observation' => trim($r['observation'] ?? ''),
                ];
            }
        }
        $resultsJson = json_encode($resultsClean, JSON_UNESCAPED_UNICODE);

        // Upload de foto opcional
        $photoPath = null;
        if (!empty($_FILES['photo']['name'])) {
            $photoPath = uploadFile($_FILES['photo'], 'inspections');
        }

        try {
            db()->prepare("
                INSERT INTO man_inspection_executions
                    (hospital_id, route_id, executed_by, executed_by_name, results, photo_path, observation)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $hid, $routeId, $_SESSION['user_id'] ?? null, $executedBy,
                $resultsJson, $photoPath, $observation,
            ]);
            auditLog('create', 'inspection_executions', (int)db()->lastInsertId());
            flash('success', 'Inspeção registrada com sucesso!');
        } catch (\Throwable $ex) {
            flash('error', 'Erro ao registrar inspeção: ' . $ex->getMessage());
        }
        redirect(url('inspections'));
    }
}

$pageTitle = 'Inspeções';
ob_start();

// ============================================================
// VIEW: ROTAS DE INSPEÇÃO (action=routes)
// ============================================================
if ($action === 'routes') {
    $routes = [];
    try {
        $st = db()->prepare("
            SELECT * FROM man_inspection_routes
            WHERE hospital_id = ?
            ORDER BY title
        ");
        $st->execute([$hid]);
        $routes = $st->fetchAll();
    } catch (\Throwable $ex) {
        $routes = [];
    }
?>
    <div class="page-header">
        <h1><i class="bi bi-signpost-2 me-2"></i>Rotas de Inspeção</h1>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo url('inspections'); ?>"><i class="bi bi-arrow-left me-1"></i> Execuções</a>
            <?php if (canWrite()): ?>
                <button onclick="openModal('modalRouteAdd')" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Nova rota</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr><th>Título</th><th>Descrição</th><th>Locais</th><th>Status</th><th class="text-end">Ações</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($routes)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">Nenhuma rota cadastrada.</td></tr>
                    <?php else: ?>
                        <?php foreach ($routes as $r):
                            $locs = json_decode($r['locations'] ?? '[]', true) ?: [];
                        ?>
                        <tr>
                            <td><strong><?php echo e($r['title']); ?></strong></td>
                            <td class="text-muted"><?php echo e(mb_strimwidth($r['description'] ?? '—', 0, 60, '...')); ?></td>
                            <td><span class="badge bg-secondary"><?php echo count($locs); ?></span></td>
                            <td><span class="badge badge-<?php echo e($r['status']); ?>"><?php echo e($r['status']); ?></span></td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <a class="btn btn-outline-success btn-action" href="<?php echo url('inspections', ['action' => 'execute', 'route_id' => $r['id']]); ?>" title="Executar"><i class="bi bi-play-fill"></i></a>
                                    <?php if (canWrite()): ?>
                                        <button class="btn btn-outline-warning btn-action" onclick="openModal('routeEdit<?php echo $r['id']; ?>')" title="Editar"><i class="bi bi-pencil"></i></button>
                                        <form method="POST" class="d-inline">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="delete_route">
                                            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                            <button class="btn btn-outline-danger btn-action" data-confirm="Excluir esta rota?"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if (canWrite()): ?>
    <!-- Modal: Nova rota -->
    <div class="modal fade" id="modalRouteAdd" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="<?php echo url('inspections', ['action' => 'routes']); ?>">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add_route">
                    <div class="modal-header py-2">
                        <h5 class="modal-title fw-semibold"><i class="bi bi-plus-lg me-1"></i> Nova rota de inspeção</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12"><label class="form-label required">Título</label><input type="text" class="form-control" name="title" required placeholder="Ex: Ronda noturna - Bloco A"></div>
                            <div class="col-12"><label class="form-label">Descrição</label><textarea class="form-control" name="description" rows="2"></textarea></div>
                            <div class="col-12"><label class="form-label required">Locais (um por linha)</label><textarea class="form-control" name="locations" rows="6" required placeholder="Recepção principal&#10;Corredor UTI&#10;Sala de equipamentos&#10;Almoxarifado"></textarea></div>
                        </div>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> Criar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modais: Editar rotas -->
    <?php foreach ($routes as $r):
        $locs = json_decode($r['locations'] ?? '[]', true) ?: [];
    ?>
    <div class="modal fade" id="routeEdit<?php echo $r['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="<?php echo url('inspections', ['action' => 'routes']); ?>">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="edit_route">
                    <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                    <div class="modal-header py-2">
                        <h5 class="modal-title fw-semibold"><i class="bi bi-pencil me-1"></i> Editar: <?php echo e($r['title']); ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12"><label class="form-label required">Título</label><input type="text" class="form-control" name="title" value="<?php echo e($r['title']); ?>" required></div>
                            <div class="col-12"><label class="form-label">Descrição</label><textarea class="form-control" name="description" rows="2"><?php echo e($r['description'] ?? ''); ?></textarea></div>
                            <div class="col-md-6"><label class="form-label">Status</label><select class="form-select" name="status"><option value="active" <?php echo $r['status']==='active'?'selected':''; ?>>Ativa</option><option value="inactive" <?php echo $r['status']==='inactive'?'selected':''; ?>>Inativa</option></select></div>
                            <div class="col-12"><label class="form-label required">Locais (um por linha)</label><textarea class="form-control" name="locations" rows="6" required><?php echo e(implode("\n", $locs)); ?></textarea></div>
                        </div>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

<?php
// ============================================================
// VIEW: EXECUTAR INSPEÇÃO (action=execute)
// ============================================================
} elseif ($action === 'execute') {
    $routeId = (int)($_GET['route_id'] ?? 0);
    $route   = null;
    $locs    = [];

    if ($routeId > 0) {
        try {
            $st = db()->prepare("SELECT * FROM man_inspection_routes WHERE id = ? AND hospital_id = ?");
            $st->execute([$routeId, $hid]);
            $route = $st->fetch() ?: null;
            if ($route) {
                $locs = json_decode($route['locations'] ?? '[]', true) ?: [];
            }
        } catch (\Throwable $ex) {
            $route = null;
        }
    }

    // Se não veio com route_id, lista rotas disponíveis
    $allRoutes = [];
    if (!$route) {
        try {
            $st = db()->prepare("SELECT id, title FROM man_inspection_routes WHERE hospital_id = ? AND status = 'active' ORDER BY title");
            $st->execute([$hid]);
            $allRoutes = $st->fetchAll();
        } catch (\Throwable $ex) {
            $allRoutes = [];
        }
    }
?>
    <div class="page-header">
        <h1><i class="bi bi-clipboard2-pulse me-2"></i>Executar Inspeção</h1>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo url('inspections'); ?>"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
    </div>

    <?php if (!$route): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <p class="mb-3">Selecione uma rota de inspeção:</p>
                <?php if (empty($allRoutes)): ?>
                    <p class="text-muted">Nenhuma rota ativa.
                        <a href="<?php echo url('inspections', ['action' => 'routes']); ?>">Criar uma agora</a>.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($allRoutes as $ar): ?>
                            <a class="list-group-item list-group-item-action" href="<?php echo url('inspections', ['action' => 'execute', 'route_id' => $ar['id']]); ?>">
                                <i class="bi bi-signpost-2 me-2"></i><?php echo e($ar['title']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><?php echo e($route['title']); ?></span>
                <span class="badge bg-secondary"><?php echo count($locs); ?> locais</span>
            </div>
            <div class="card-body">
                <?php if (!empty($route['description'])): ?>
                    <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i> <?php echo nl2br(e($route['description'])); ?></div>
                <?php endif; ?>

                <form method="POST" action="<?php echo url('inspections', ['action' => 'execute']); ?>" enctype="multipart/form-data">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="execute">
                    <input type="hidden" name="route_id" value="<?php echo (int)$route['id']; ?>">

                    <div class="mb-3">
                        <label class="form-label required">Executor</label>
                        <input type="text" class="form-control" name="executed_by_name" required value="<?php echo e($_SESSION['user_name'] ?? ''); ?>">
                    </div>

                    <h6 class="fw-semibold mb-3"><i class="bi bi-geo-alt me-1"></i> Pontos de inspeção</h6>

                    <?php foreach ($locs as $i => $loc): ?>
                    <div class="card bg-light border mb-3">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong><?php echo ($i + 1); ?>. <?php echo e($loc); ?></strong>
                            </div>
                            <input type="hidden" name="results[<?php echo $i; ?>][location]" value="<?php echo e($loc); ?>">
                            <div class="d-flex gap-3 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="results[<?php echo $i; ?>][status]" value="pass" id="r<?php echo $i; ?>_pass" checked>
                                    <label class="form-check-label text-success" for="r<?php echo $i; ?>_pass"><i class="bi bi-check-circle me-1"></i>OK</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="results[<?php echo $i; ?>][status]" value="fail" id="r<?php echo $i; ?>_fail">
                                    <label class="form-check-label text-danger" for="r<?php echo $i; ?>_fail"><i class="bi bi-x-circle me-1"></i>Falha</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="results[<?php echo $i; ?>][status]" value="na" id="r<?php echo $i; ?>_na">
                                    <label class="form-check-label text-muted" for="r<?php echo $i; ?>_na"><i class="bi bi-dash-circle me-1"></i>N/A</label>
                                </div>
                            </div>
                            <textarea class="form-control form-control-sm" name="results[<?php echo $i; ?>][observation]" rows="1" placeholder="Observação (opcional)"></textarea>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="mb-3">
                        <label class="form-label">Foto (opcional)</label>
                        <input type="file" class="form-control" name="photo" accept=".jpg,.jpeg,.png,.webp">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Observação geral</label>
                        <textarea class="form-control" name="observation" rows="3"></textarea>
                    </div>

                    <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i> Registrar inspeção</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

<?php
// ============================================================
// VIEW: LISTA DE EXECUÇÕES (default)
// ============================================================
} else {
    $rows = [];
    try {
        $st = db()->prepare("
            SELECT ie.*, ir.title AS route_title
            FROM man_inspection_executions ie
            LEFT JOIN man_inspection_routes ir ON ir.id = ie.route_id
            WHERE ie.hospital_id = ?
            ORDER BY ie.created_at DESC
            LIMIT 200
        ");
        $st->execute([$hid]);
        $rows = $st->fetchAll();
    } catch (\Throwable $ex) {
        $rows = [];
    }

    // KPIs
    $kpiTotal = 0;
    $kpiAvgOk = 0;
    try {
        $kst = db()->prepare("
            SELECT COUNT(*) AS total FROM man_inspection_executions
            WHERE hospital_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $kst->execute([$hid]);
        $kpiTotal = (int)$kst->fetchColumn();
    } catch (\Throwable $ex) {}
?>
    <div class="page-header">
        <h1><i class="bi bi-clipboard2-pulse me-2"></i>Inspeções</h1>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo url('inspections', ['action' => 'routes']); ?>"><i class="bi bi-signpost-2 me-1"></i> Rotas</a>
            <?php if (canWrite()): ?>
                <a class="btn btn-primary btn-sm" href="<?php echo url('inspections', ['action' => 'execute']); ?>"><i class="bi bi-plus-lg me-1"></i> Nova inspeção</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="card stat-card shadow-sm"><div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3"><i class="bi bi-clipboard-data"></i></div>
                <div><div class="stat-value"><?php echo $kpiTotal; ?></div><div class="stat-label">Inspeções (30d)</div></div>
            </div></div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr><th>Data</th><th>Rota</th><th>Executor</th><th>% OK</th><th>Obs</th><th>Anexos</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Nenhuma inspeção registrada.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r):
                            $results = json_decode($r['results'] ?? '[]', true) ?: [];
                            $totalLocs = count($results);
                            $okCount   = 0;
                            foreach ($results as $res) {
                                if (($res['status'] ?? '') === 'pass') $okCount++;
                            }
                            $pctOk    = $totalLocs > 0 ? round(($okCount / $totalLocs) * 100) : 0;
                            $pctClass = $pctOk >= 90 ? 'badge-completed' : ($pctOk >= 70 ? 'badge-medium' : 'badge-critical');
                        ?>
                        <tr>
                            <td class="text-muted"><?php echo formatDate($r['created_at'], 'd/m/Y H:i'); ?></td>
                            <td><strong><?php echo e($r['route_title'] ?? '—'); ?></strong></td>
                            <td><?php echo e($r['executed_by_name'] ?? '—'); ?></td>
                            <td><span class="badge <?php echo $pctClass; ?>"><?php echo $pctOk; ?>% (<?php echo $okCount; ?>/<?php echo $totalLocs; ?>)</span></td>
                            <td class="text-muted"><?php echo e(mb_strimwidth($r['observation'] ?? '', 0, 40, '...')); ?></td>
                            <td>
                                <?php if (!empty($r['photo_path'])): ?>
                                    <a href="<?php echo e(uploadUrl($r['photo_path'])); ?>" target="_blank" class="btn btn-outline-secondary btn-action" title="Foto"><i class="bi bi-image"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php }
$content = ob_get_clean();
require __DIR__ . '/layout.php';
