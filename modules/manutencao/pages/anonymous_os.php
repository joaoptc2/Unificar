<?php
/**
 * VISUALIZAÇÃO ANÔNIMA DE OS — Design System "RH Hospital"
 */

$token = trim($_GET['token'] ?? '');
$os    = null;
$error = '';

if ($token === '') {
    $error = 'Token não informado.';
} else {
    try {
        $stmt = db()->prepare("
            SELECT so.*, e.name AS equip_name, h.name AS hospital_name
            FROM man_service_orders so
            LEFT JOIN man_equipment e ON e.id = so.equipment_id
            LEFT JOIN man_hospitals h ON h.id = so.hospital_id
            WHERE so.anonymous_token = ?
        ");
        $stmt->execute([$token]);
        $os = $stmt->fetch();
        if (!$os) { $error = 'Ordem de serviço não encontrada.'; }
    } catch (Exception $ex) {
        $error = 'Erro ao buscar OS.';
    }
}

$statusLabels = ['open'=>'Aberta','in_progress'=>'Em Andamento','waiting_part'=>'Aguardando Peça','completed'=>'Concluída','cancelled'=>'Cancelada'];
$typeLabels   = ['preventive'=>'Preventiva','corrective'=>'Corretiva','predictive'=>'Preditiva','calibration'=>'Calibração','inspection'=>'Inspeção'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OS <?php echo $os ? e($os['os_number']) : 'Não encontrada'; ?> &middot; <?php echo e(APP_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-7">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">

                    <?php if ($error): ?>
                        <div class="alert alert-danger text-center mb-0">
                            <i class="bi bi-exclamation-octagon fs-2 d-block mb-2"></i>
                            <?php echo e($error); ?>
                        </div>
                    <?php else: ?>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <h4 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>OS <?php echo e($os['os_number']); ?></h4>
                                <small class="text-muted"><?php echo e($os['hospital_name'] ?? ''); ?></small>
                            </div>
                            <span class="badge badge-<?php echo e($os['status']); ?> fs-6">
                                <?php echo e($statusLabels[$os['status']] ?? $os['status']); ?>
                            </span>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-sm-6">
                                <small class="text-muted d-block">Título</small>
                                <strong><?php echo e($os['title']); ?></strong>
                            </div>
                            <div class="col-sm-6">
                                <small class="text-muted d-block">Tipo</small>
                                <strong><?php echo e($typeLabels[$os['type']] ?? $os['type']); ?></strong>
                            </div>
                            <div class="col-sm-6">
                                <small class="text-muted d-block">Equipamento</small>
                                <strong><?php echo e($os['equip_name'] ?? '—'); ?></strong>
                            </div>
                            <div class="col-sm-6">
                                <small class="text-muted d-block">Criada em</small>
                                <strong><?php echo formatDate($os['created_at'], 'd/m/Y H:i'); ?></strong>
                            </div>
                            <div class="col-sm-6">
                                <small class="text-muted d-block">Data Prevista</small>
                                <strong><?php echo formatDate($os['scheduled_date'] ?? ''); ?></strong>
                            </div>
                        </div>

                        <?php if ($os['description']): ?>
                            <hr class="my-3">
                            <small class="text-muted d-block mb-1">Descrição</small>
                            <p class="mb-2"><?php echo nl2br(e($os['description'])); ?></p>
                        <?php endif; ?>

                        <?php if ($os['observation']): ?>
                            <small class="text-muted d-block mb-1">Observação</small>
                            <p class="mb-2"><?php echo nl2br(e($os['observation'])); ?></p>
                        <?php endif; ?>

                        <?php if ($os['solution']): ?>
                            <small class="text-muted d-block mb-1">Solução</small>
                            <p class="mb-0"><?php echo nl2br(e($os['solution'])); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>

                </div>
                <div class="card-footer bg-transparent text-center py-3">
                    <small class="text-muted"><?php echo e(APP_NAME); ?> &middot; Consulta pública</small>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
