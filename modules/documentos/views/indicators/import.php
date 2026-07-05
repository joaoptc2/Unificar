<div class="page-header">
    <h1><i class="bi bi-upload me-2"></i>Importar Dados</h1>
    <div class="d-flex gap-2">
        <a href="<?php echo url('indicators/view?id=' . $indicator['id']); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
    </div>
</div>
<p class="text-muted small mb-3">Indicador: <strong><?php echo e($indicator['name']); ?></strong></p>

<?php if ($result): ?>
    <?php if ($result['ok']): ?>
        <div class="alert alert-<?php echo empty($result['errors']) ? 'success' : 'warning'; ?>">
            <i class="bi bi-check-circle me-2"></i>
            <strong><?php echo $result['imported']; ?></strong> lançamentos importados de
            <strong><?php echo $result['total']; ?></strong> linhas.
            <?php if (!empty($result['errors'])): ?>
                <br><strong><?php echo count($result['errors']); ?></strong> erros:
                <ul class="mb-0 mt-1 small">
                    <?php foreach (array_slice($result['errors'], 0, 10) as $err): ?>
                        <li><?php echo e($err); ?></li>
                    <?php endforeach; ?>
                    <?php if (count($result['errors']) > 10): ?>
                        <li>... e mais <?php echo count($result['errors']) - 10; ?> erros</li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i><?php echo e($result['error']); ?></div>
    <?php endif; ?>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-filetype-csv me-1"></i>Upload de CSV
            </div>
            <div class="card-body">
                <form method="POST" action="<?php echo url('indicators/import?id=' . $indicator['id']); ?>"
                      enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label required">Arquivo CSV</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
                        <small class="text-muted">Separador: ponto-e-vírgula (;). Encoding: UTF-8.</small>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload me-1"></i>Importar
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-info-circle me-1"></i>Formato esperado
            </div>
            <div class="card-body small">
                <p>O CSV deve ter cabeçalho na primeira linha com estas colunas:</p>
                <table class="table table-sm mb-2">
                    <thead><tr><th>Coluna</th><th>Obrigatória</th></tr></thead>
                    <tbody>
                        <tr><td><code>data</code></td><td>Sim (dd/mm/aaaa ou aaaa-mm-dd)</td></tr>
                        <?php if (!empty($variables)): ?>
                            <?php foreach ($variables as $v): ?>
                            <tr><td><code><?php echo e($v['code']); ?></code> <small class="text-muted">(<?php echo e($v['label']); ?>)</small></td><td>Sim</td></tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td><code>valor</code></td><td>Sim</td></tr>
                        <?php endif; ?>
                        <tr><td><code>observacoes</code></td><td>Não</td></tr>
                    </tbody>
                </table>
                <p class="mb-1"><strong>Exemplo:</strong></p>
                <pre class="bg-light p-2 rounded mb-0" style="font-size:.75rem">data;<?php
                    if (!empty($variables)) {
                        echo implode(';', array_column($variables, 'code'));
                    } else { echo 'valor'; }
                    echo ';observacoes'; ?>
01/01/2025;<?php
                    if (!empty($variables)) {
                        echo implode(';', array_fill(0, count($variables), '10'));
                    } else { echo '95.5'; }
                    echo ';Valor de exemplo'; ?>
</pre>
            </div>
        </div>
    </div>
</div>
