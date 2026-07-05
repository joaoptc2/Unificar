<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Ficha Funcional — <?= Sanitize::e($employee['full_name']) ?></title>
    <style>
        @page { size: A4; margin: 16mm 14mm; }
        * { box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #222;
            margin: 0;
            padding: 0;
        }
        .sheet { max-width: 720px; margin: 0 auto; padding: 16px; }
        .sheet-header {
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 8px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .sheet-header h1 { font-size: 16px; margin: 0; color: #0d6efd; }
        .sheet-header small { color: #666; }
        .section { margin-top: 18px; page-break-inside: avoid; }
        .section h2 {
            font-size: 13px;
            background: #f0f4f8;
            padding: 6px 10px;
            margin: 0 0 8px;
            border-left: 3px solid #0d6efd;
        }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 5px 6px; vertical-align: top; }
        table.info td { border-bottom: 1px solid #eee; }
        table.info td.label {
            width: 28%;
            font-weight: bold;
            color: #555;
            background: #fafafa;
        }
        table.data { border: 1px solid #ddd; }
        table.data th {
            background: #f5f7fa;
            text-align: left;
            border-bottom: 2px solid #ddd;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        table.data td { border-bottom: 1px solid #eee; }
        .photo-wrap { text-align: right; }
        .photo-wrap img { width: 90px; height: 110px; object-fit: cover; border: 1px solid #ccc; }
        .badge-st {
            display: inline-block; padding: 2px 8px; border-radius: 3px;
            font-size: 10px; font-weight: bold; color: #fff;
        }
        .st-ativo      { background: #198754; }
        .st-afastado   { background: #fd7e14; }
        .st-desligado  { background: #dc3545; }
        .st-vencido    { background: #dc3545; }
        .st-proximo    { background: #fd7e14; }
        .st-valido     { background: #198754; }
        .footer-note { margin-top: 30px; text-align: center; color: #888; font-size: 10px; }

        /* Barra superior com botões — oculta na impressão. */
        .toolbar {
            position: sticky; top: 0;
            background: #f8f9fa;
            border-bottom: 1px solid #ddd;
            padding: 10px 16px;
            display: flex; gap: 8px; justify-content: flex-end;
        }
        .toolbar button, .toolbar a {
            padding: 6px 14px; border: 1px solid #0d6efd; background: #0d6efd;
            color: #fff; border-radius: 4px; text-decoration: none; font-size: 12px;
            cursor: pointer;
        }
        .toolbar a.btn-secondary { background: #fff; color: #0d6efd; }
        @media print {
            .toolbar { display: none; }
            body { background: #fff; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <a class="btn-secondary" href="index.php?m=rh&page=employees&action=show&id=<?= (int)$employee['id'] ?>">&larr; Voltar</a>
        <button onclick="window.print()" type="button">Imprimir / Salvar como PDF</button>
    </div>

    <div class="sheet">
        <div class="sheet-header">
            <div>
                <h1><?= Sanitize::e($hospitalName) ?></h1>
                <small>Ficha Funcional — emitida em <?= date('d/m/Y H:i') ?></small>
            </div>
            <div class="photo-wrap">
                <?php if (!empty($employee['photo'])): ?>
                    <img src="<?= Sanitize::e(Upload::url($employee['photo'])) ?>" alt="">
                <?php endif; ?>
            </div>
        </div>

        <div class="section">
            <h2>Dados Pessoais</h2>
            <table class="info">
                <tr>
                    <td class="label">Nome completo</td>
                    <td><?= Sanitize::e($employee['full_name']) ?></td>
                    <td class="label">CPF</td>
                    <td><?= Sanitize::e(Sanitize::formatCpf($employee['cpf'])) ?></td>
                </tr>
                <tr>
                    <td class="label">Nascimento</td>
                    <td><?= Sanitize::formatDate($employee['birth_date']) ?></td>
                    <td class="label">Sexo</td>
                    <td><?= Sanitize::e($employee['gender']) ?></td>
                </tr>
                <tr>
                    <td class="label">E-mail</td>
                    <td><?= Sanitize::e($employee['email'] ?: '-') ?></td>
                    <td class="label">Telefone</td>
                    <td><?= Sanitize::e($employee['phone'] ?: '-') ?></td>
                </tr>
                <tr>
                    <td class="label">Endereço</td>
                    <td colspan="3">
                        <?= Sanitize::e(trim(
                            ($employee['address_street'] ?? '') . ', ' .
                            ($employee['address_number'] ?? '') . ' ' .
                            ($employee['address_complement'] ? '— ' . $employee['address_complement'] : '') . ' — ' .
                            ($employee['address_neighborhood'] ?? '') . ', ' .
                            ($employee['address_city'] ?? '') . '/' .
                            ($employee['address_state'] ?? '') . ' — CEP ' .
                            ($employee['address_zip'] ?? '')
                        , ', -/')) ?>
                    </td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h2>Dados Contratuais</h2>
            <table class="info">
                <tr>
                    <td class="label">Cargo</td>
                    <td><?= Sanitize::e($employee['position_title'] ?: '-') ?></td>
                    <td class="label">Departamento</td>
                    <td><?= Sanitize::e($employee['department_name'] ?: '-') ?></td>
                </tr>
                <tr>
                    <td class="label">Admissão</td>
                    <td><?= Sanitize::formatDate($employee['admission_date']) ?></td>
                    <td class="label">Contrato</td>
                    <td><?= Sanitize::e($employee['contract_type']) ?></td>
                </tr>
                <tr>
                    <td class="label">Status</td>
                    <td>
                        <span class="badge-st st-<?= Sanitize::e($employee['status']) ?>">
                            <?= Sanitize::e(strtoupper($employee['status'])) ?>
                        </span>
                    </td>
                    <td class="label">Desligamento</td>
                    <td><?= Sanitize::formatDate($employee['termination_date']) ?></td>
                </tr>
                <?php if ($employee['regional_council']): ?>
                <tr>
                    <td class="label">Conselho Regional</td>
                    <td><?= Sanitize::e($employee['regional_council'] . ' ' . $employee['council_number']) ?></td>
                    <td class="label">Validade</td>
                    <td><?= Sanitize::formatDate($employee['council_expiry']) ?></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <?php if (!empty($expirations)): ?>
        <div class="section">
            <h2>Vencimentos e Obrigações</h2>
            <table class="data">
                <thead>
                    <tr><th>Tipo</th><th>Título</th><th>Emissão</th><th>Vencimento</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php $today = new DateTime(); foreach ($expirations as $ex):
                    $expDate = new DateTime($ex['expiry_date']);
                    $diff = $today->diff($expDate)->days;
                    $isPast = $expDate < $today;
                    if ($isPast) { $st = 'vencido'; $txt = 'Vencido'; }
                    elseif ($diff <= $ex['alert_days']) { $st = 'proximo'; $txt = 'Próximo'; }
                    else { $st = 'valido'; $txt = 'Válido'; }
                ?>
                    <tr>
                        <td><?= Sanitize::e(str_replace('_', ' ', $ex['type'])) ?></td>
                        <td><?= Sanitize::e($ex['title']) ?></td>
                        <td><?= Sanitize::formatDate($ex['issue_date']) ?></td>
                        <td><?= Sanitize::formatDate($ex['expiry_date']) ?></td>
                        <td><span class="badge-st st-<?= $st ?>"><?= $txt ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($certificates)): ?>
        <div class="section">
            <h2>Atestados Médicos</h2>
            <table class="data">
                <thead>
                    <tr><th>Data</th><th>Dias</th><th>CID</th><th>Médico</th><th>CRM</th></tr>
                </thead>
                <tbody>
                <?php foreach ($certificates as $c): ?>
                    <tr>
                        <td><?= Sanitize::formatDate($c['issue_date']) ?></td>
                        <td><?= (int)$c['days'] ?></td>
                        <td><?= Sanitize::e($c['cid'] ?: '-') ?></td>
                        <td><?= Sanitize::e($c['doctor_name'] ?: '-') ?></td>
                        <td><?= Sanitize::e($c['doctor_crm'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($documents)): ?>
        <div class="section">
            <h2>Documentos da Ficha</h2>
            <table class="data">
                <thead>
                    <tr><th>Tipo</th><th>Título</th><th>Enviado em</th></tr>
                </thead>
                <tbody>
                <?php foreach ($documents as $d): ?>
                    <tr>
                        <td><?= Sanitize::e($d['doc_type']) ?></td>
                        <td><?= Sanitize::e($d['title']) ?></td>
                        <td><?= Sanitize::formatDateTime($d['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($records)): ?>
        <div class="section">
            <h2>Histórico Funcional</h2>
            <table class="data">
                <thead>
                    <tr><th>Data</th><th>Evento</th><th>Descrição</th><th>Responsável</th></tr>
                </thead>
                <tbody>
                <?php foreach ($records as $r): ?>
                    <tr>
                        <td><?= Sanitize::formatDate($r['record_date']) ?></td>
                        <td><?= Sanitize::e(ucfirst($r['record_type'])) ?></td>
                        <td><?= Sanitize::e($r['description']) ?></td>
                        <td><?= Sanitize::e($r['user_name'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($employee['notes'])): ?>
        <div class="section">
            <h2>Observações</h2>
            <p><?= nl2br(Sanitize::e($employee['notes'])) ?></p>
        </div>
        <?php endif; ?>

        <div class="footer-note">
            Documento gerado automaticamente pelo sistema RH em <?= date('d/m/Y H:i:s') ?>.
        </div>
    </div>
</body>
</html>
