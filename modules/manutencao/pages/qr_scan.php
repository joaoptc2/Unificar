<?php
/**
 * PÁGINA PÚBLICA DE QR CODE — solicitação de manutenção ou limpeza
 *
 * Acesso: ?page=qr-scan&token=TOKEN_DO_LOCAL
 *
 * Fluxo:
 *   1. Valida o token contra qr_locations
 *   2. Mostra duas opções: Limpeza ou Manutenção
 *   3. Limpeza → registra cleaning_execution direto (sem mais dados)
 *   4. Manutenção → formulário com título, descrição, foto (anônimo)
 */

$token = trim($_GET['token'] ?? '');
$loc   = null;
$error = '';
$done  = '';
$view  = $_GET['v'] ?? 'choose'; // choose | manutencao | done

if ($token === '') {
    $error = 'QR Code inválido — token não informado.';
} else {
    try {
        $stmt = db()->prepare("
            SELECT ql.*, s.name AS sector_name, h.name AS hospital_name
            FROM man_qr_locations ql
            LEFT JOIN man_sectors s   ON s.id = ql.sector_id
            LEFT JOIN man_hospitals h ON h.id = ql.hospital_id
            WHERE ql.token = ? AND ql.status = 'active'
        ");
        $stmt->execute([$token]);
        $loc = $stmt->fetch();
        if (!$loc) {
            $error = 'Local não encontrado ou desativado.';
        }
    } catch (Throwable $ex) {
        error_log('qr_scan error: ' . $ex->getMessage());
        $error = 'Sistema em configuração. A tabela de locais QR ainda não foi criada. Execute a migração SQL.';
    }
}

// ============================================================
// PROCESSAR POST
// ============================================================
if ($loc && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['qr_action'] ?? '';

    if ($act === 'cleaning') {
        try {
            $osNumber = generateOsNumber();
            db()->prepare("
                INSERT INTO man_service_orders
                    (hospital_id, equipment_id, os_number, type, priority, status,
                     title, description, observation, anonymous_token, created_at)
                VALUES (?, NULL, ?, 'corrective', 'medium', 'open', ?, ?, ?, ?, NOW())
            ")->execute([
                (int)$loc['hospital_id'],
                $osNumber,
                'Solicitacao de limpeza - ' . $loc['name'],
                'Solicitacao de limpeza realizada via QR Code.',
                'Local: ' . $loc['name'] . ($loc['sector_name'] ? ' - Setor: ' . $loc['sector_name'] : ''),
                generateToken(16),
            ]);
            $newOsId = (int)db()->lastInsertId();
            try { addOsHistory($newOsId, 'Solicitacao de limpeza via QR', 'Local: ' . $loc['name']); } catch (Throwable $ignored) {}

            try {
                db()->prepare("
                    INSERT INTO notifications (hospital_id, type, title, message, reference_id, created_at)
                    VALUES (?, 'warning', ?, ?, ?, NOW())
                ")->execute([
                    (int)$loc['hospital_id'],
                    'Solicitacao de limpeza',
                    'Local: ' . $loc['name'] . ($loc['sector_name'] ? ' - Setor: ' . $loc['sector_name'] : '') . ' (OS ' . $osNumber . ')',
                    $newOsId,
                ]);
            } catch (Throwable $ignored) {}

            $view = 'done';
            $done = 'Solicitacao de limpeza registrada (OS ' . $osNumber . ') para o local "' . $loc['name'] . '". A equipe sera notificada.';
        } catch (Throwable $ex) {
            error_log('qr cleaning error: ' . $ex->getMessage());
            $error = 'Erro ao registrar solicitacao de limpeza.';
        }
    }

    if ($act === 'maintenance') {
        $title = trim($_POST['title'] ?? '');
        $desc  = trim($_POST['description'] ?? '') ?: null;

        if ($title === '') {
            $error = 'Informe um título para o chamado.';
            $view  = 'manutencao';
        } else {
            try {
                $osNumber = generateOsNumber();
                $photoPath = null;
                if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                    $photoPath = uploadFile($_FILES['photo'], 'os_photos');
                }

                db()->prepare("
                    INSERT INTO man_service_orders
                        (hospital_id, equipment_id, os_number, type, priority, status,
                         title, description, photo_path, observation, anonymous_token, created_at)
                    VALUES (?, NULL, ?, 'corrective', 'medium', 'open', ?, ?, ?, ?, ?, NOW())
                ")->execute([
                    (int)$loc['hospital_id'],
                    $osNumber,
                    $title,
                    $desc,
                    $photoPath,
                    'Local: ' . $loc['name'] . ($loc['sector_name'] ? ' - Setor: ' . $loc['sector_name'] : ''),
                    generateToken(16),
                ]);
                $newOsId = (int)db()->lastInsertId();
                try { addOsHistory($newOsId, 'Criacao via QR Code', 'Local: ' . $loc['name']); } catch (Throwable $ignored) {}

                $view = 'done';
                $done = 'Chamado ' . $osNumber . ' aberto com sucesso para o local "' . $loc['name'] . '".';
            } catch (Throwable $ex) {
                error_log('qr maintenance error: ' . $ex->getMessage());
                $error = 'Erro ao abrir chamado.';
                $view  = 'manutencao';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitação &middot; <?php echo e(APP_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">

            <?php if ($error && !$loc): ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-qr-code-scan text-danger" style="font-size:3rem"></i>
                        <h5 class="mt-3 mb-2">QR Code inválido</h5>
                        <p class="text-muted"><?php echo e($error); ?></p>
                    </div>
                </div>

            <?php elseif ($view === 'done'): ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-check-circle text-success" style="font-size:3rem"></i>
                        <h5 class="mt-3 mb-2">Registrado!</h5>
                        <p class="text-muted"><?php echo e($done); ?></p>
                        <a href="<?php echo url('qr-scan', ['token' => $token]); ?>" class="btn btn-outline-primary btn-sm mt-2">
                            <i class="bi bi-arrow-left me-1"></i> Nova solicitação
                        </a>
                    </div>
                </div>

            <?php elseif ($view === 'manutencao'): ?>
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white">
                        <div class="d-flex align-items-center gap-2">
                            <a href="<?php echo url('qr-scan', ['token' => $token]); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
                            <div>
                                <h5 class="mb-0 fw-semibold">Abrir chamado de manutenção</h5>
                                <small class="text-muted"><?php echo e($loc['name']); ?> <?php if ($loc['sector_name']): ?>— <?php echo e($loc['sector_name']); ?><?php endif; ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo e($error); ?></div>
                        <?php endif; ?>
                        <form method="POST" action="<?php echo url('qr-scan', ['token' => $token, 'v' => 'manutencao']); ?>" enctype="multipart/form-data">
                            <input type="hidden" name="qr_action" value="maintenance">
                            <div class="mb-3">
                                <label class="form-label required">O que está acontecendo?</label>
                                <input type="text" class="form-control" name="title" required placeholder="Ex: Ar condicionado não funciona" value="<?php echo e($_POST['title'] ?? ''); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Detalhes (opcional)</label>
                                <textarea class="form-control" name="description" rows="3" placeholder="Descreva o problema com mais detalhes..."><?php echo e($_POST['description'] ?? ''); ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Foto (opcional)</label>
                                <input type="file" class="form-control" name="photo" accept=".jpg,.jpeg,.png,.webp">
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-send me-1"></i> Enviar chamado
                            </button>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- TELA DE ESCOLHA -->
                <div class="text-center mb-4">
                    <div class="login-icon mb-3"><i class="bi bi-qr-code-scan"></i></div>
                    <h4 class="fw-bold"><?php echo e($loc['name']); ?></h4>
                    <?php if ($loc['sector_name']): ?>
                        <p class="text-muted mb-0"><?php echo e($loc['sector_name']); ?> — <?php echo e($loc['hospital_name'] ?? ''); ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo e($error); ?></div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-6">
                        <form method="POST" action="<?php echo url('qr-scan', ['token' => $token]); ?>">
                            <input type="hidden" name="qr_action" value="cleaning">
                            <button type="submit" class="card border-0 shadow-sm w-100 text-center p-4 btn" style="background:#f0fdf4">
                                <i class="bi bi-droplet-half text-success" style="font-size:2.5rem"></i>
                                <h5 class="mt-3 mb-1 text-success fw-bold">Limpeza</h5>
                                <small class="text-muted">Solicitar limpeza deste local</small>
                            </button>
                        </form>
                    </div>
                    <div class="col-6">
                        <a href="<?php echo url('qr-scan', ['token' => $token, 'v' => 'manutencao']); ?>" class="card border-0 shadow-sm w-100 text-center p-4 text-decoration-none" style="background:#eff6ff">
                            <i class="bi bi-tools text-primary" style="font-size:2.5rem"></i>
                            <h5 class="mt-3 mb-1 text-primary fw-bold">Manutenção</h5>
                            <small class="text-muted">Reportar problema neste local</small>
                        </a>
                    </div>
                </div>

                <p class="text-center text-muted small mt-4"><?php echo e(APP_NAME); ?></p>
            <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
