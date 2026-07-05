<div class="page-header">
    <h1><i class="bi bi-person-badge me-2"></i>Ficha Funcional</h1>
    <div class="d-flex gap-2">
        <a href="index.php?page=employees&action=print&id=<?= $employee['id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-printer me-1"></i> Imprimir / PDF
        </a>
        <?php if (Auth::can('employees', 'edit') && empty($employee['anonymized_at'])): ?>
            <a href="index.php?page=employees&action=edit&id=<?= $employee['id'] ?>" class="btn btn-warning btn-sm">
                <i class="bi bi-pencil me-1"></i> Editar
            </a>
        <?php endif; ?>
        <?php if (Auth::can('employees', 'delete') && $employee['status'] === 'desligado' && empty($employee['anonymized_at'])): ?>
            <form method="POST" action="index.php?page=employees&action=anonymize" class="d-inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= $employee['id'] ?>">
                <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Anonimizar este funcionário (LGPD)? Esta ação remove dados pessoais e arquivos, mantendo apenas registros estatísticos. Não é reversível.">
                    <i class="bi bi-shield-lock me-1"></i> Anonimizar (LGPD)
                </button>
            </form>
        <?php endif; ?>
        <a href="index.php?page=employees" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
    </div>
    <?php if (!empty($employee['anonymized_at'])): ?>
        <div class="alert alert-secondary mt-2 mb-0 py-2">
            <i class="bi bi-shield-check me-1"></i>
            Registro anonimizado em <?= Sanitize::formatDateTime($employee['anonymized_at']) ?> conforme LGPD.
        </div>
    <?php endif; ?>
</div>

<div class="row g-3">
    <!-- Perfil -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body py-4">
                <?php if ($employee['photo']): ?>
                    <img src="<?= Sanitize::e(Upload::url($employee['photo'])) ?>" class="employee-photo-lg mb-3" alt="">
                <?php else: ?>
                    <div class="employee-photo-lg bg-light d-flex align-items-center justify-content-center mx-auto mb-3">
                        <i class="bi bi-person fs-1 text-muted"></i>
                    </div>
                <?php endif; ?>
                <h5 class="fw-bold mb-1"><?= Sanitize::e($employee['full_name']) ?></h5>
                <p class="text-muted mb-2"><?= Sanitize::e($employee['position_title'] ?? 'Sem cargo') ?></p>
                <?php
                $badgeClass = match($employee['status']) {
                    'ativo' => 'badge-ativo',
                    'afastado' => 'badge-afastado',
                    'desligado' => 'badge-desligado',
                    default => 'bg-secondary'
                };
                ?>
                <span class="badge <?= $badgeClass ?> px-3 py-2"><?= ucfirst($employee['status']) ?></span>
            </div>
        </div>

        <!-- Ações rápidas -->
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body">
                <h6 class="fw-semibold mb-3">Ações Rápidas</h6>
                <?php if (Auth::can('documents', 'create')): ?>
                    <a href="index.php?page=documents&action=create&employee_id=<?= $employee['id'] ?>"
                       class="btn btn-outline-primary btn-sm w-100 mb-2">
                        <i class="bi bi-upload me-1"></i> Enviar Documento
                    </a>
                <?php endif; ?>
                <?php if (Auth::can('expirations', 'create')): ?>
                    <a href="index.php?page=expirations&action=create&employee_id=<?= $employee['id'] ?>"
                       class="btn btn-outline-primary btn-sm w-100 mb-2">
                        <i class="bi bi-clock me-1"></i> Novo Vencimento
                    </a>
                <?php endif; ?>
                <?php if (Auth::can('certificates', 'create')): ?>
                    <a href="index.php?page=certificates&action=create&employee_id=<?= $employee['id'] ?>"
                       class="btn btn-outline-primary btn-sm w-100 mb-2">
                        <i class="bi bi-file-medical me-1"></i> Novo Atestado
                    </a>
                <?php endif; ?>
                <?php if (Auth::can('employees', 'delete')): ?>
                    <form method="POST" action="index.php?page=employees&action=delete" class="mt-3">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= $employee['id'] ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100"
                                data-confirm="Tem certeza que deseja excluir este funcionário? Esta ação é irreversível.">
                            <i class="bi bi-trash me-1"></i> Excluir Funcionário
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Dados -->
    <div class="col-md-8">
        <!-- Tabs -->
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabDados">Dados</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabDocumentos">Documentos <span class="badge bg-secondary"><?= count($documents) ?></span></a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabTreinamentos">Treinamentos <span class="badge bg-secondary"><?= count($trainings) ?></span></a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabEpis">EPIs <span class="badge bg-secondary"><?= count($epis) ?></span></a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabVencimentos">Vencimentos <span class="badge bg-secondary"><?= count($expirations) ?></span></a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabAtestados">Atestados <span class="badge bg-secondary"><?= count($certificates) ?></span></a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabPontos">
                Pontuação
                <span class="badge <?= $scoresTotal >= 0 ? 'bg-success' : 'bg-danger' ?>"><?= ($scoresTotal >= 0 ? '+' : '') . $scoresTotal ?></span>
            </a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabElogios">Elogios <span class="badge bg-secondary"><?= count($compliments) ?></span></a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabHistorico">Histórico <span class="badge bg-secondary"><?= count($records) ?></span></a></li>
        </ul>

        <div class="tab-content">
            <!-- Tab Dados -->
            <div class="tab-pane fade show active" id="tabDados">
                <div class="card border-0 shadow-sm border-top-0 rounded-top-0">
                    <div class="card-body">
                        <h6 class="fw-semibold text-primary mb-3">Dados Pessoais</h6>
                        <div class="row g-2 mb-4">
                            <div class="col-md-6"><small class="text-muted">CPF</small><div><?= Sanitize::formatCpf($employee['cpf']) ?></div></div>
                            <div class="col-md-6"><small class="text-muted">Data de Nascimento</small><div><?= Sanitize::formatDate($employee['birth_date']) ?></div></div>
                            <div class="col-md-6"><small class="text-muted">Sexo</small><div><?= match($employee['gender']) { 'M' => 'Masculino', 'F' => 'Feminino', default => 'Outro' } ?></div></div>
                            <div class="col-md-6"><small class="text-muted">Telefone</small><div><?= Sanitize::e($employee['phone'] ?: '-') ?></div></div>
                            <div class="col-md-6"><small class="text-muted">E-mail</small><div><?= Sanitize::e($employee['email'] ?: '-') ?></div></div>
                        </div>

                        <h6 class="fw-semibold text-primary mb-3">Endereço</h6>
                        <div class="row g-2 mb-4">
                            <div class="col-12">
                                <div>
                                    <?= Sanitize::e($employee['address_street'] ?? '') ?>
                                    <?= $employee['address_number'] ? ', ' . Sanitize::e($employee['address_number']) : '' ?>
                                    <?= $employee['address_complement'] ? ' - ' . Sanitize::e($employee['address_complement']) : '' ?>
                                </div>
                                <div>
                                    <?= Sanitize::e($employee['address_neighborhood'] ?? '') ?>
                                    <?= $employee['address_city'] ? ' - ' . Sanitize::e($employee['address_city']) : '' ?>
                                    <?= $employee['address_state'] ? '/' . Sanitize::e($employee['address_state']) : '' ?>
                                    <?= $employee['address_zip'] ? ' - CEP: ' . Sanitize::e($employee['address_zip']) : '' ?>
                                </div>
                            </div>
                        </div>

                        <h6 class="fw-semibold text-primary mb-3">Dados Contratuais</h6>
                        <div class="row g-2 mb-4">
                            <div class="col-md-6"><small class="text-muted">Departamento</small><div><?= Sanitize::e($employee['department_name'] ?? '-') ?></div></div>
                            <div class="col-md-6"><small class="text-muted">Cargo</small><div><?= Sanitize::e($employee['position_title'] ?? '-') ?></div></div>
                            <div class="col-md-4"><small class="text-muted">Admissão</small><div><?= Sanitize::formatDate($employee['admission_date']) ?></div></div>
                            <div class="col-md-4"><small class="text-muted">Contrato</small><div><?= Sanitize::e($employee['contract_type']) ?></div></div>
                            <div class="col-md-4"><small class="text-muted">Status</small><div><span class="badge <?= $badgeClass ?>"><?= ucfirst($employee['status']) ?></span></div></div>
                            <?php if ($employee['termination_date']): ?>
                                <div class="col-md-4"><small class="text-muted">Desligamento</small><div><?= Sanitize::formatDate($employee['termination_date']) ?></div></div>
                            <?php endif; ?>
                            <?php if ($employee['leave_date']): ?>
                                <div class="col-md-4"><small class="text-muted">Afastamento</small><div><?= Sanitize::formatDate($employee['leave_date']) ?></div></div>
                            <?php endif; ?>
                            <?php if ($employee['return_date']): ?>
                                <div class="col-md-4"><small class="text-muted">Retorno</small><div><?= Sanitize::formatDate($employee['return_date']) ?></div></div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($employee['regional_council']) && $employee['regional_council'] !== 'N/A'): ?>
                            <h6 class="fw-semibold text-primary mb-3">Conselho Regional</h6>
                            <div class="row g-2 mb-4">
                                <div class="col-md-4"><small class="text-muted">Conselho</small><div><?= Sanitize::e($employee['regional_council']) ?></div></div>
                                <div class="col-md-4"><small class="text-muted">Número</small><div><?= Sanitize::e($employee['council_number'] ?? '-') ?></div></div>
                                <div class="col-md-4"><small class="text-muted">Validade</small><div><?= Sanitize::formatDate($employee['council_expiry']) ?></div></div>
                            </div>
                        <?php endif; ?>

                        <h6 class="fw-semibold text-primary mb-3">Exame Admissional (ASO)</h6>
                        <div class="row g-2 mb-4">
                            <div class="col-md-6"><small class="text-muted">Último exame</small><div><?= Sanitize::formatDate($employee['aso_admissional_date'] ?? null) ?></div></div>
                            <div class="col-md-6"><small class="text-muted">Próximo exame</small><div><?= Sanitize::formatDate($employee['aso_next_date'] ?? null) ?></div></div>
                        </div>

                        <?php if ($portalUser): ?>
                            <h6 class="fw-semibold text-primary mb-3">Acesso ao Portal</h6>
                            <div class="row g-2 mb-4">
                                <div class="col-md-6"><small class="text-muted">Login (e-mail)</small><div><?= Sanitize::e($portalUser['email']) ?></div></div>
                                <div class="col-md-3"><small class="text-muted">Status</small><div>
                                    <span class="badge <?= $portalUser['active'] ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= $portalUser['active'] ? 'Ativo' : 'Inativo' ?>
                                    </span>
                                </div></div>
                                <div class="col-md-3"><small class="text-muted">Último acesso</small><div><?= $portalUser['last_login'] ? Sanitize::formatDateTime($portalUser['last_login']) : '-' ?></div></div>
                            </div>
                        <?php endif; ?>

                        <?php if ($employee['notes']): ?>
                            <h6 class="fw-semibold text-primary mb-3">Observações</h6>
                            <p class="mb-0"><?= nl2br(Sanitize::e($employee['notes'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tab Documentos -->
            <div class="tab-pane fade" id="tabDocumentos">
                <div class="card border-0 shadow-sm border-top-0 rounded-top-0">
                    <div class="card-body">
                        <?php if (empty($documents)): ?>
                            <p class="text-muted text-center py-3">Nenhum documento cadastrado.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead><tr><th>Tipo</th><th>Título</th><th>Arquivo</th><th>Data</th><th></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($documents as $doc): ?>
                                        <tr>
                                            <td><span class="badge bg-info"><?= Sanitize::e($doc['doc_type']) ?></span></td>
                                            <td><?= Sanitize::e($doc['title']) ?></td>
                                            <td><a href="<?= Sanitize::e(Upload::url($doc['file_path'], 'document', (int)$doc['id'])) ?>" target="_blank" class="text-decoration-none"><i class="bi bi-download me-1"></i>Baixar</a></td>
                                            <td><?= Sanitize::formatDateTime($doc['created_at']) ?></td>
                                            <td>
                                                <?php if (Auth::can('documents', 'delete')): ?>
                                                    <form method="POST" action="index.php?page=documents&action=delete" class="d-inline">
                                                        <?= Csrf::field() ?>
                                                        <input type="hidden" name="id" value="<?= $doc['id'] ?>">
                                                        <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Excluir este documento?"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tab Vencimentos -->
            <div class="tab-pane fade" id="tabVencimentos">
                <div class="card border-0 shadow-sm border-top-0 rounded-top-0">
                    <div class="card-body">
                        <?php if (empty($expirations)): ?>
                            <p class="text-muted text-center py-3">Nenhum vencimento cadastrado.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead><tr><th>Tipo</th><th>Título</th><th>Vencimento</th><th>Status</th><th></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($expirations as $exp): ?>
                                        <?php
                                        $today = new DateTime();
                                        $expDate = new DateTime($exp['expiry_date']);
                                        $diff = $today->diff($expDate)->days;
                                        $isPast = $expDate < $today;
                                        if ($isPast) { $statusBadge = 'badge-vencido'; $statusText = 'Vencido'; }
                                        elseif ($diff <= $exp['alert_days']) { $statusBadge = 'badge-proximo'; $statusText = 'Próximo'; }
                                        else { $statusBadge = 'badge-valido'; $statusText = 'Válido'; }
                                        ?>
                                        <tr>
                                            <td><small><?= Sanitize::e(str_replace('_', ' ', $exp['type'])) ?></small></td>
                                            <td><?= Sanitize::e($exp['title']) ?></td>
                                            <td><?= Sanitize::formatDate($exp['expiry_date']) ?></td>
                                            <td><span class="badge <?= $statusBadge ?>"><?= $statusText ?></span></td>
                                            <td>
                                                <a href="index.php?page=expirations&action=edit&id=<?= $exp['id'] ?>" class="btn btn-outline-primary btn-action"><i class="bi bi-pencil"></i></a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tab Atestados -->
            <div class="tab-pane fade" id="tabAtestados">
                <div class="card border-0 shadow-sm border-top-0 rounded-top-0">
                    <div class="card-body">
                        <?php if (empty($certificates)): ?>
                            <p class="text-muted text-center py-3">Nenhum atestado cadastrado.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead><tr><th>Data</th><th>Dias</th><th>CID</th><th>Médico</th><th>CRM</th><th></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($certificates as $cert): ?>
                                        <tr>
                                            <td><?= Sanitize::formatDate($cert['issue_date']) ?></td>
                                            <td><?= $cert['days'] ?></td>
                                            <td><?= Sanitize::e($cert['cid'] ?: '-') ?></td>
                                            <td><?= Sanitize::e($cert['doctor_name'] ?: '-') ?></td>
                                            <td><?= Sanitize::e($cert['doctor_crm'] ?: '-') ?></td>
                                            <td>
                                                <?php if ($cert['file_path']): ?>
                                                    <a href="<?= Sanitize::e(Upload::url($cert['file_path'], 'certificate', (int)$cert['id'])) ?>" target="_blank" class="btn btn-outline-primary btn-action"><i class="bi bi-download"></i></a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tab Treinamentos -->
            <div class="tab-pane fade" id="tabTreinamentos">
                <div class="card border-0 shadow-sm border-top-0 rounded-top-0">
                    <div class="card-body">
                        <?php if (Auth::can('documents', 'create')): ?>
                            <a class="btn btn-sm btn-outline-primary mb-2"
                               href="index.php?page=documents&action=create&employee_id=<?= $employee['id'] ?>&type=Treinamento">
                                <i class="bi bi-plus-lg me-1"></i> Novo treinamento
                            </a>
                        <?php endif; ?>
                        <?php if (empty($trainings)): ?>
                            <p class="text-muted text-center py-3">Nenhum treinamento registrado.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead><tr><th>Título</th><th>Arquivo</th><th>Data</th><th></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($trainings as $doc): ?>
                                        <tr>
                                            <td><?= Sanitize::e($doc['title']) ?></td>
                                            <td><a href="<?= Sanitize::e(Upload::url($doc['file_path'], 'document', (int)$doc['id'])) ?>" target="_blank"><i class="bi bi-download me-1"></i>Baixar</a></td>
                                            <td><?= Sanitize::formatDateTime($doc['created_at']) ?></td>
                                            <td>
                                                <?php if (Auth::can('documents', 'delete')): ?>
                                                    <form method="POST" action="index.php?page=documents&action=delete" class="d-inline">
                                                        <?= Csrf::field() ?>
                                                        <input type="hidden" name="id" value="<?= $doc['id'] ?>">
                                                        <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Excluir?"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tab EPIs -->
            <div class="tab-pane fade" id="tabEpis">
                <div class="card border-0 shadow-sm border-top-0 rounded-top-0">
                    <div class="card-body">
                        <?php if (Auth::can('documents', 'create')): ?>
                            <a class="btn btn-sm btn-outline-primary mb-2"
                               href="index.php?page=documents&action=create&employee_id=<?= $employee['id'] ?>&type=EPI">
                                <i class="bi bi-plus-lg me-1"></i> Registrar entrega de EPI
                            </a>
                        <?php endif; ?>
                        <?php if (empty($epis)): ?>
                            <p class="text-muted text-center py-3">Nenhum EPI registrado.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead><tr><th>Descrição</th><th>Comprovante</th><th>Data</th><th></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($epis as $doc): ?>
                                        <tr>
                                            <td><?= Sanitize::e($doc['title']) ?></td>
                                            <td><a href="<?= Sanitize::e(Upload::url($doc['file_path'], 'document', (int)$doc['id'])) ?>" target="_blank"><i class="bi bi-download me-1"></i>Baixar</a></td>
                                            <td><?= Sanitize::formatDateTime($doc['created_at']) ?></td>
                                            <td>
                                                <?php if (Auth::can('documents', 'delete')): ?>
                                                    <form method="POST" action="index.php?page=documents&action=delete" class="d-inline">
                                                        <?= Csrf::field() ?>
                                                        <input type="hidden" name="id" value="<?= $doc['id'] ?>">
                                                        <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Excluir?"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tab Pontuação -->
            <div class="tab-pane fade" id="tabPontos">
                <div class="card border-0 shadow-sm border-top-0 rounded-top-0">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <span class="text-muted small">Saldo atual</span>
                                <h3 class="mb-0 <?= $scoresTotal >= 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= ($scoresTotal >= 0 ? '+' : '') . $scoresTotal ?> pts
                                </h3>
                            </div>
                        </div>

                        <?php if (Auth::can('employees', 'edit')): ?>
                        <form method="POST" action="index.php?page=scores&action=store" class="border rounded p-3 mb-3 bg-light">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-2">
                                    <label class="form-label small">Pontos</label>
                                    <input type="number" name="points" class="form-control" required min="-1000" max="1000" value="1" placeholder="Positivo ou negativo">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Categoria (opcional)</label>
                                    <input type="text" name="category" class="form-control" placeholder="Ex: Disciplina">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label small">Motivo</label>
                                    <input type="text" name="reason" class="form-control" required placeholder="Justifique o lançamento">
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i> Lançar</button>
                                </div>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php if (empty($scores)): ?>
                            <p class="text-muted text-center py-3">Nenhum lançamento ainda.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead><tr><th>Data</th><th>Pontos</th><th>Categoria</th><th>Motivo</th><th>Lançado por</th><th></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($scores as $s): ?>
                                        <tr>
                                            <td><?= Sanitize::formatDateTime($s['created_at']) ?></td>
                                            <td><strong class="<?= $s['points'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= ($s['points'] >= 0 ? '+' : '') . (int)$s['points'] ?></strong></td>
                                            <td><?= Sanitize::e($s['category'] ?: '-') ?></td>
                                            <td><?= nl2br(Sanitize::e($s['reason'])) ?></td>
                                            <td><?= Sanitize::e($s['created_by_name'] ?: '-') ?></td>
                                            <td>
                                                <?php if (Auth::can('employees', 'edit')): ?>
                                                <form method="POST" action="index.php?page=scores&action=delete" class="d-inline">
                                                    <?= Csrf::field() ?>
                                                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                                    <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Remover este lançamento?"><i class="bi bi-trash"></i></button>
                                                </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tab Elogios -->
            <div class="tab-pane fade" id="tabElogios">
                <div class="card border-0 shadow-sm border-top-0 rounded-top-0">
                    <div class="card-body">
                        <?php if (Auth::can('employees', 'edit')): ?>
                        <form method="POST" action="index.php?page=compliments&action=store" class="border rounded p-3 mb-3 bg-light">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label small">De (opcional)</label>
                                    <input type="text" name="compliment_from" class="form-control" placeholder="Cliente, paciente, colega, etc.">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Mensagem</label>
                                    <input type="text" name="message" class="form-control" required placeholder="Descreva o elogio">
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-heart"></i> Registrar</button>
                                </div>
                            </div>
                        </form>
                        <?php endif; ?>

                        <?php if (empty($compliments)): ?>
                            <p class="text-muted text-center py-3">Nenhum elogio registrado ainda.</p>
                        <?php else: ?>
                            <?php foreach ($compliments as $c): ?>
                                <div class="border-start border-4 border-warning ps-3 mb-3">
                                    <p class="mb-1"><i class="bi bi-quote text-warning"></i> <?= nl2br(Sanitize::e($c['message'])) ?></p>
                                    <small class="text-muted">
                                        <?= $c['compliment_from'] ? 'de <strong>' . Sanitize::e($c['compliment_from']) . '</strong> &middot; ' : '' ?>
                                        <?= Sanitize::formatDateTime($c['created_at']) ?>
                                        <?= $c['created_by_name'] ? ' &middot; registrado por ' . Sanitize::e($c['created_by_name']) : '' ?>
                                    </small>
                                    <?php if (Auth::can('employees', 'edit')): ?>
                                        <form method="POST" action="index.php?page=compliments&action=delete" class="d-inline ms-2">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                            <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                                            <button type="submit" class="btn btn-link btn-sm text-danger p-0" data-confirm="Remover elogio?"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Tab Histórico -->
            <div class="tab-pane fade" id="tabHistorico">
                <div class="card border-0 shadow-sm border-top-0 rounded-top-0">
                    <div class="card-body">
                        <?php if (empty($records)): ?>
                            <p class="text-muted text-center py-3">Nenhum registro no histórico.</p>
                        <?php else: ?>
                            <div class="timeline">
                                <?php foreach ($records as $rec): ?>
                                    <div class="d-flex mb-3">
                                        <div class="me-3 text-center" style="min-width:80px;">
                                            <small class="text-muted"><?= Sanitize::formatDate($rec['record_date']) ?></small>
                                        </div>
                                        <div class="border-start border-2 border-primary ps-3">
                                            <span class="badge bg-primary mb-1"><?= Sanitize::e(ucfirst(str_replace('_', ' ', $rec['record_type']))) ?></span>
                                            <p class="mb-0 small"><?= Sanitize::e($rec['description']) ?></p>
                                            <?php if ($rec['user_name']): ?>
                                                <small class="text-muted">por <?= Sanitize::e($rec['user_name']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
