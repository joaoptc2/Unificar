<?php
$is_controlled = (int) ($document['is_controlled'] ?? 1) === 1;
$is_editor = document_is_editor($document);
$days = $document['expiration_date'] ? days_until($document['expiration_date']) : null;
$badge_exp = $days === null ? 'bg-light text-dark' : ($days < 0 ? 'badge-vencido' : ($days <= 30 ? 'badge-proximo' : 'badge-valido'));
$status = $document['status'] ?? 'approved';
$status_labels = [
    'draft' => ['bg-secondary', 'Rascunho'],
    'pending_review' => ['bg-warning text-dark', 'Em revisão'],
    'approved' => ['bg-success', 'Aprovado'],
    'expired' => ['bg-danger', 'Vencido'],
    'archived' => ['bg-dark', 'Arquivado'],
];
$st = $status_labels[$status] ?? ['bg-secondary', $status];
$conf_labels = ['public'=>'Público','internal'=>'Interno','restricted'=>'Restrito','confidential'=>'Confidencial'];
$conf_icons  = ['public'=>'bi-globe','internal'=>'bi-building','restricted'=>'bi-lock','confidential'=>'bi-shield-lock'];
$list_url = $is_controlled ? url('documents') : url('documents/uncontrolled');
$print_url = url('documents/print/' . $document['id']);
$review_due = $is_controlled && ($document['next_review_date'] ?? null) && strtotime($document['next_review_date']) <= time();
?>
<div class="page-header">
    <h1>
        <?php if (!empty($document['document_code'])): ?>
            <small class="text-muted"><?php echo e($document['document_code']); ?></small>
        <?php endif; ?>
        <i class="bi <?php echo $is_editor ? 'bi-file-earmark-richtext' : 'bi-file-earmark-text'; ?> me-2"></i><?php echo e($document['title']); ?>
    </h1>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($is_editor): ?>
        <a href="<?php echo $print_url; ?>" target="_blank" class="btn btn-success btn-sm">
            <i class="bi bi-printer me-1"></i>Imprimir / PDF
        </a>
        <?php elseif (!empty($document['file_path'])): ?>
        <a href="<?php echo url('documents/download?id=' . $document['id']); ?>" class="btn btn-success btn-sm">
            <i class="bi bi-download me-1"></i>Baixar
        </a>
        <?php endif; ?>
        <?php if (core_can('documents.edit')): ?>
        <a href="<?php echo url('documents/edit?id=' . $document['id']); ?>" class="btn btn-warning btn-sm">
            <i class="bi bi-pencil me-1"></i>Editar
        </a>
        <?php endif; ?>
        <a href="<?php echo $list_url; ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
    </div>
</div>

<!-- Status badges -->
<div class="d-flex gap-2 mb-3 flex-wrap">
    <span class="badge <?php echo $is_controlled ? 'bg-primary' : 'bg-secondary'; ?>">
        <i class="bi <?php echo $is_controlled ? 'bi-shield-check' : 'bi-archive'; ?> me-1"></i><?php echo $is_controlled ? 'Controlado' : 'Não controlado'; ?>
    </span>
    <?php if ($is_controlled): ?>
        <span class="badge <?php echo $st[0]; ?>"><?php echo $st[1]; ?></span>
        <span class="badge <?php echo $badge_exp; ?>"><?php echo $days === null ? 'Sem validade' : expiry_label($days); ?></span>
    <?php endif; ?>
    <span class="badge bg-light text-dark">
        <i class="bi <?php echo $conf_icons[$document['confidentiality'] ?? 'internal'] ?? 'bi-building'; ?> me-1"></i>
        <?php echo $conf_labels[$document['confidentiality'] ?? 'internal'] ?? 'Interno'; ?>
    </span>
    <span class="badge bg-light text-dark"><i class="bi <?php echo $is_editor ? 'bi-pencil-square' : 'bi-upload'; ?> me-1"></i><?php echo $is_editor ? 'Escrito no sistema' : 'Arquivo enviado'; ?></span>
    <?php if ($review_due): ?>
        <span class="badge bg-warning text-dark"><i class="bi bi-arrow-repeat me-1"></i>Revisão pendente</span>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-info-circle me-1"></i>Informações
            </div>
            <div class="card-body">
                <table class="table table-borderless table-sm mb-0">
                    <tr><th class="text-muted" style="width:170px">Categoria</th>
                        <td><span class="badge bg-secondary"><?php echo e($document['category']); ?></span></td></tr>
                    <tr><th class="text-muted">Setor</th>
                        <td><?php echo e($document['sector_name'] ?: '—'); ?></td></tr>
                    <tr><th class="text-muted">Responsável</th>
                        <td><?php echo e($document['responsible'] ?: '—'); ?></td></tr>
                    <?php if ($is_controlled): ?>
                        <?php if (!empty($document['issuing_body'])): ?>
                        <tr><th class="text-muted">Órgão emissor</th>
                            <td><?php echo e($document['issuing_body']); ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($document['legal_basis'])): ?>
                        <tr><th class="text-muted">Base legal</th>
                            <td><?php echo e($document['legal_basis']); ?></td></tr>
                        <?php endif; ?>
                        <tr><th class="text-muted">Validade</th>
                            <td><?php echo format_date($document['expiration_date']); ?>
                                <?php if ($document['expiration_date']): ?>
                                <small class="text-muted">(aviso <?php echo (int) $document['notify_days_before']; ?> dias antes)</small>
                                <?php endif; ?></td></tr>
                        <?php if ($document['next_review_date'] ?? null): ?>
                        <tr><th class="text-muted">Próxima revisão</th>
                            <td><?php echo format_date($document['next_review_date']); ?>
                                <small class="text-muted">(a cada <?php echo (int) ($document['review_interval_months'] ?? 12); ?> meses)</small></td></tr>
                        <?php endif; ?>
                        <?php if ($document['approved_by_name'] ?? null): ?>
                        <tr><th class="text-muted">Aprovado por</th>
                            <td><?php echo e($document['approved_by_name']); ?> em <?php echo format_datetime($document['approved_at']); ?></td></tr>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($is_editor): ?>
                    <tr><th class="text-muted">Layout / fonte</th>
                        <td class="small">
                            <?php
                                $lay = $document['layout_id'] ? Core\DocLayout::find((int) $document['layout_id']) : null;
                                $cov = $document['cover_layout_id'] ? Core\DocLayout::find((int) $document['cover_layout_id']) : null;
                                echo e($lay['name'] ?? 'Layout padrão');
                                if ($cov) echo ' · capa: ' . e($cov['name']);
                                if (!empty($document['font_family']) || !empty($document['font_size'])) {
                                    echo ' · ' . e(trim(($document['font_family'] ?? '') . ' ' . ($document['font_size'] ?? '')));
                                }
                            ?>
                        </td></tr>
                    <?php endif; ?>
                    <tr><th class="text-muted">Cadastrado por</th>
                        <td><?php echo e($document['created_by_name'] ?? '—'); ?> em <?php echo format_datetime($document['created_at']); ?></td></tr>
                    <tr><th class="text-muted">Atualizado em</th>
                        <td><?php echo format_datetime($document['updated_at']); ?></td></tr>
                    <?php if (!empty($document['observations'])): ?>
                    <tr><th class="text-muted">Observações</th>
                        <td><?php echo nl2br(e($document['observations'])); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <?php if ($is_editor): ?>
        <!-- Pré-visualização do documento escrito no sistema -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-file-earmark-richtext me-1"></i>Pré-visualização (v<?php echo (int) $document['current_version']; ?>)</span>
                <a href="<?php echo $print_url; ?>" target="_blank" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-printer me-1"></i>Abrir para imprimir / PDF
                </a>
            </div>
            <div class="card-body p-0">
                <iframe src="<?php echo $print_url; ?>" title="Pré-visualização do documento" class="doc-preview-frame" loading="lazy"></iframe>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($is_controlled): ?>
        <!-- Ciência digital -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-check2-square me-1"></i>Ciência do documento</span>
                <span class="badge bg-primary"><?php echo count($ack_status['acknowledged']); ?> leitura(s)</span>
            </div>
            <div class="card-body">
                <?php if (!$user_acked && core_can('documents.acknowledge')): ?>
                    <form method="POST" action="<?php echo url('documents/acknowledge'); ?>" class="mb-3">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="id" value="<?php echo (int) $document['id']; ?>">
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-check2-circle me-1"></i>Confirmar que li este documento
                        </button>
                    </form>
                <?php elseif ($user_acked): ?>
                    <div class="alert alert-success py-2 mb-3 small">
                        <i class="bi bi-check-circle me-1"></i>Você já confirmou ciência deste documento.
                    </div>
                <?php endif; ?>

                <?php if (!empty($ack_status['acknowledged'])): ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Usuário</th><th>Data</th></tr></thead>
                        <tbody>
                        <?php foreach ($ack_status['acknowledged'] as $ack): ?>
                            <tr>
                                <td class="small"><?php echo e($ack['user_name']); ?></td>
                                <td class="small text-muted"><?php echo format_datetime($ack['acknowledged_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <!-- Arquivo / conteúdo -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-paperclip me-1"></i><?php echo $is_editor ? 'Documento' : 'Arquivo'; ?></div>
            <div class="card-body text-center">
                <?php if ($is_editor): ?>
                    <i class="bi bi-file-earmark-richtext display-4 text-primary"></i>
                    <p class="mb-1 fw-semibold mt-2">Escrito no sistema</p>
                    <small class="text-muted">Impressão/PDF com o layout do hospital</small>
                    <div class="mt-3 d-flex flex-column gap-2">
                        <a href="<?php echo $print_url; ?>" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye me-1"></i>Visualizar</a>
                        <?php if (core_can('documents.export')): ?>
                        <a href="<?php echo $print_url . '&pdf=1'; ?>" target="_blank" class="btn btn-success btn-sm"><i class="bi bi-printer me-1"></i>Imprimir / PDF</a>
                        <?php endif; ?>
                    </div>
                <?php elseif (!empty($document['file_name'])): ?>
                    <i class="bi <?php echo file_icon($document['file_type']); ?> display-4"></i>
                    <p class="mb-1 fw-semibold mt-2"><?php echo e($document['file_name']); ?></p>
                    <small class="text-muted"><?php echo format_bytes($document['file_size'] ?? 0); ?></small>
                    <div class="mt-3">
                        <a href="<?php echo url('documents/download?id=' . $document['id']); ?>"
                           class="btn btn-outline-primary btn-sm"><i class="bi bi-download me-1"></i>Baixar</a>
                    </div>
                <?php else: ?>
                    <i class="bi bi-file-earmark-x display-4 text-muted"></i>
                    <p class="text-muted mb-0 mt-2 small">Nenhum arquivo anexado</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Ações -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-gear me-1"></i>Ações</div>
            <div class="card-body d-flex flex-column gap-2">
                <?php if ($is_controlled): ?>
                    <?php if ($status === 'draft' && core_can('documents.create')): ?>
                        <form method="POST" action="<?php echo url('documents/submit'); ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo (int) $document['id']; ?>">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-send me-1"></i>Enviar para revisão
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($status === 'pending_review' && core_can('documents.approve')): ?>
                        <form method="POST" action="<?php echo url('documents/approve'); ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo (int) $document['id']; ?>">
                            <button type="submit" class="btn btn-success btn-sm w-100">
                                <i class="bi bi-check-lg me-1"></i>Aprovar
                            </button>
                        </form>
                        <form method="POST" action="<?php echo url('documents/reject'); ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo (int) $document['id']; ?>">
                            <button type="submit" class="btn btn-outline-warning btn-sm w-100">
                                <i class="bi bi-arrow-return-left me-1"></i>Devolver para rascunho
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($review_due && core_can('documents.approve')): ?>
                        <form method="POST" action="<?php echo url('documents/mark-reviewed'); ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="id" value="<?php echo (int) $document['id']; ?>">
                            <button type="submit" class="btn btn-info text-white btn-sm w-100">
                                <i class="bi bi-arrow-repeat me-1"></i>Marcar como revisado
                            </button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="small text-muted mb-0"><i class="bi bi-info-circle me-1"></i>Documento não controlado: sem fluxo de aprovação, revisão ou ciência.</p>
                <?php endif; ?>

                <?php if (core_can('documents.edit')): ?>
                <a href="<?php echo url('documents/edit?id=' . $document['id']); ?>" class="btn btn-warning btn-sm w-100">
                    <i class="bi bi-pencil me-1"></i>Editar
                </a>
                <?php endif; ?>

                <?php if (core_can('documents.delete')): ?>
                <form method="POST" action="<?php echo url('documents/delete'); ?>"
                      data-confirm="Remover este documento?">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $document['id']; ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                        <i class="bi bi-trash me-1"></i>Remover
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Versão -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-clock-history me-1"></i>Versão v<?php echo (int) ($document['current_version'] ?? 1); ?>
            </div>
            <?php if (empty($versions ?? [])): ?>
            <div class="card-body small text-muted">
                <i class="bi bi-info-circle me-1"></i>Ao editar, a versão anterior é salva automaticamente.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Histórico de versões -->
<?php $versions = $versions ?? []; ?>
<?php if (!empty($versions)): ?>
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-1"></i>Histórico de versões</span>
        <span class="badge bg-secondary"><?php echo count($versions); ?> versões anteriores</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr>
                    <th>Versão</th><?php if ($is_controlled): ?><th>Validade</th><?php endif; ?><th>Conteúdo</th>
                    <th>Por</th><th>Data</th><th>Notas</th><th class="text-end">Ação</th>
                </tr></thead>
                <tbody>
                <?php foreach ($versions as $ver):
                    $v_editor = !empty($ver['content_html']) || !empty($ver['cover_html']);
                ?>
                    <tr>
                        <td><span class="badge bg-light text-dark">v<?php echo (int) $ver['version']; ?></span></td>
                        <?php if ($is_controlled): ?><td class="small"><?php echo format_date($ver['expiration_date']); ?></td><?php endif; ?>
                        <td class="small">
                            <?php if ($v_editor): ?>
                                <i class="bi bi-file-earmark-richtext text-primary me-1"></i>Editor
                                <?php if (!empty($ver['font_family'])): ?><small class="text-muted">(<?php echo e($ver['font_family']); ?> <?php echo e($ver['font_size'] ?? ''); ?>)</small><?php endif; ?>
                            <?php else: ?>
                                <?php echo e($ver['file_name'] ?: '—'); ?>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?php echo e($ver['created_by_name'] ?? '—'); ?></td>
                        <td class="small text-muted"><?php echo format_datetime($ver['created_at']); ?></td>
                        <td class="small text-muted"><?php echo e($ver['notes'] ?: '—'); ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($v_editor): ?>
                            <a href="<?php echo url('documents/print/' . $document['id'] . '?version=' . (int) $ver['version']); ?>" target="_blank"
                               class="btn btn-outline-primary btn-action" title="Visualizar / imprimir esta versão"><i class="bi bi-printer"></i></a>
                            <?php endif; ?>
                            <?php if (!empty($ver['file_path'])): ?>
                            <a href="<?php echo url('documents/download-version?id=' . $document['id'] . '&version_id=' . $ver['id']); ?>"
                               class="btn btn-outline-primary btn-action" title="Baixar arquivo desta versão"><i class="bi bi-download"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
