<?php
$has_variables = !empty($variables);
$default_date = !empty($data['reference_date']) ? $data['reference_date'] : date('Y-m-d');
?>
<div class="page-header">
    <h1><i class="bi bi-plus-circle me-2"></i>Lançar Dados</h1>
    <div class="d-flex gap-2">
        <a href="<?php echo url('indicators/view?id=' . (int) $indicator['id']); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
    </div>
</div>
<p class="text-muted small mb-3"><?php echo e($indicator['name']); ?></p>

<form method="POST" action="<?php echo url('indicators/store_data'); ?>" id="dataForm">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="indicator_id" value="<?php echo (int) $indicator['id']; ?>">

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-calendar me-1"></i>Período
                </div>
                <div class="card-body">
                    <label class="form-label required">Data de referência</label>
                    <input type="date" name="reference_date" class="form-control" required
                           value="<?php echo e($default_date); ?>">
                    <small class="text-muted">Se já existir lançamento nesta data, os valores serão substituídos.</small>
                </div>
            </div>

            <?php if ($has_variables): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-box me-1"></i>Medidas coletadas
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">Preencha os valores brutos. O resultado será <strong>calculado automaticamente</strong>.</p>
                    <div class="row g-3">
                        <?php foreach ($variables as $v):
                            $val = $data_values[$v['code']] ?? '';
                        ?>
                        <div class="col-md-6">
                            <label class="form-label required">
                                <?php echo e($v['label']); ?>
                                <span class="badge bg-light text-dark font-monospace ms-1"><?php echo e($v['code']); ?></span>
                                <?php if (!empty($v['unit'])): ?>
                                    <small class="text-muted">(<?php echo e($v['unit']); ?>)</small>
                                <?php endif; ?>
                            </label>
                            <input type="text" inputmode="decimal"
                                   name="var_values[<?php echo e($v['code']); ?>]"
                                   data-var="<?php echo e($v['code']); ?>"
                                   class="form-control var-input" required
                                   value="<?php echo e($val === '' ? '' : rtrim(rtrim(number_format($val, 4, '.', ''), '0'), '.')); ?>"
                                   placeholder="0">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-calculator me-1"></i>Valor
                </div>
                <div class="card-body">
                    <label class="form-label required">Valor do indicador</label>
                    <div class="input-group">
                        <input type="text" inputmode="decimal" name="value" class="form-control" required
                               value="<?php echo e($data['value'] ?? ''); ?>" placeholder="0">
                        <?php if (!empty($indicator['unit'])): ?>
                            <span class="input-group-text"><?php echo e($indicator['unit']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-chat me-1"></i>Observações
                </div>
                <div class="card-body">
                    <textarea name="observations" class="form-control" rows="3"
                              placeholder="Notas sobre este lançamento (opcional)"><?php echo e($data['observations'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-eye me-1"></i>Resultado calculado
                </div>
                <div class="card-body text-center">
                    <div id="previewValue" class="display-6 fw-bold text-muted">—</div>
                    <div class="small text-muted mt-1"><?php echo e($indicator['unit'] ?: 'unidade'); ?></div>

                    <?php if (($indicator['goal_numeric'] ?? null) !== null): ?>
                        <hr>
                        <div class="small text-muted">Meta:
                            <strong><?php echo format_number($indicator['goal_numeric'], (int) ($indicator['decimal_places'] ?? 2)); ?></strong>
                            <?php echo e($indicator['unit']); ?>
                            <?php if ((float) ($indicator['goal_tolerance'] ?? 0) > 0): ?>
                                (&pm; <?php echo format_number($indicator['goal_tolerance'], 2); ?>)
                            <?php endif; ?>
                        </div>
                        <div class="mt-2">
                            <span id="goalStatus" class="badge bg-secondary">Aguardando valor</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($indicator['formula'])): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-calculator me-1"></i>Fórmula
                </div>
                <div class="card-body">
                    <code class="d-block bg-light p-2 rounded"><?php echo e($indicator['formula']); ?></code>
                </div>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-check-lg me-1"></i>Salvar lançamento
            </button>
        </div>
    </div>
</form>

<script>
(function() {
    var formula   = <?php echo json_encode($indicator['formula'] ?? ''); ?>;
    var goal      = <?php echo ($indicator['goal_numeric'] ?? null) !== null ? (float) $indicator['goal_numeric'] : 'null'; ?>;
    var goalDir   = <?php echo json_encode($indicator['goal_direction'] ?? 'higher_better'); ?>;
    var goalTol   = <?php echo (float) ($indicator['goal_tolerance'] ?? 0); ?>;
    var decimals  = <?php echo (int) ($indicator['decimal_places'] ?? 2); ?>;
    var FN_NAMES  = ['abs','sqrt','round','min','max'];

    var previewEl = document.getElementById('previewValue');
    var statusEl  = document.getElementById('goalStatus');

    function compute() {
        var vars = {};
        document.querySelectorAll('.var-input').forEach(function(inp) {
            var v = parseFloat((inp.value || '').replace(',', '.'));
            vars[inp.dataset.var] = isNaN(v) ? null : v;
        });
        if (formula) {
            for (var k in vars) if (vars[k] === null) return null;
            try {
                var safe = formula.replace(/[a-zA-Z_][a-zA-Z0-9_]*/g, function(m) {
                    if (FN_NAMES.indexOf(m) !== -1) return 'Math.'+m;
                    if (!(m in vars)) throw new Error();
                    return '('+vars[m]+')';
                });
                var r = (new Function('return ('+safe+')'))();
                return (isNaN(r) || !isFinite(r)) ? null : r;
            } catch(e) { return null; }
        }
        var keys = Object.keys(vars);
        if (keys.length) {
            var sum = 0, ok = true;
            keys.forEach(function(k){ if (vars[k]===null) ok=false; else sum+=vars[k]; });
            return ok ? sum : null;
        }
        var d = document.querySelector('input[name="value"]');
        if (d) { var dv = parseFloat((d.value||'').replace(',','.')); return isNaN(dv)?null:dv; }
        return null;
    }

    function goalCheck(v) {
        if (v===null||goal===null) return null;
        if (goalDir==='higher_better') return v>=goal?'met':(goalTol>0&&v>=goal-goalTol?'tolerance':'missed');
        if (goalDir==='lower_better') return v<=goal?'met':(goalTol>0&&v<=goal+goalTol?'tolerance':'missed');
        var d=Math.abs(v-goal); return d<=goalTol?'met':(d<=goalTol*2?'tolerance':'missed');
    }

    function recalc() {
        var v = compute();
        if (v === null) {
            previewEl.textContent = '—'; previewEl.className = 'display-6 fw-bold text-muted';
            if (statusEl) { statusEl.className='badge bg-secondary'; statusEl.textContent='Aguardando valor'; }
            return;
        }
        previewEl.textContent = (+v).toFixed(decimals); previewEl.className = 'display-6 fw-bold text-dark';
        if (statusEl) {
            var st = goalCheck(v);
            if (st==='met')       { statusEl.className='badge badge-valido'; statusEl.textContent='✓ Meta atingida'; }
            else if(st==='tolerance'){statusEl.className='badge badge-proximo'; statusEl.textContent='~ Na tolerância'; }
            else if(st==='missed'){ statusEl.className='badge badge-vencido'; statusEl.textContent='✗ Fora da meta'; }
            else { statusEl.className='badge bg-secondary'; statusEl.textContent='—'; }
        }
    }
    document.querySelectorAll('#dataForm input').forEach(function(i){ i.addEventListener('input',recalc); });
    recalc();
})();
</script>
