<?php
$ind = $indicator ?? [];
$is_edit = !empty($editing);
$f = function($key, $default = '') use ($ind) {
    return $ind[$key] ?? $default;
};
?>
<div class="page-header">
    <h1>
        <i class="bi bi-<?php echo $is_edit ? 'pencil' : 'plus-circle'; ?> me-2"></i>
        <?php echo $is_edit ? 'Editar Indicador' : 'Novo Indicador'; ?>
    </h1>
    <div class="d-flex gap-2">
        <a href="<?php echo url('indicators'); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
    </div>
</div>

<form method="POST"
      action="<?php echo url($is_edit ? 'indicators/update' : 'indicators/store'); ?>"
      id="indicatorForm">
    <?php echo csrf_field(); ?>
    <?php if ($is_edit): ?>
        <input type="hidden" name="id" value="<?php echo (int) $ind['id']; ?>">
    <?php endif; ?>

    <div class="row g-3">
        <!-- Coluna esquerda: dados básicos + variáveis + fórmula -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-info-circle me-1"></i>Informações
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label required">Nome</label>
                            <input type="text" name="name" class="form-control" required
                                   value="<?php echo e($f('name')); ?>"
                                   placeholder="Ex: Taxa de infecção hospitalar">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required">Periodicidade</label>
                            <select name="type" class="form-select" required>
                                <option value="daily"   <?php echo $f('type') === 'daily'   ? 'selected' : ''; ?>>Diário</option>
                                <option value="monthly" <?php echo $f('type') === 'monthly' || !$is_edit ? 'selected' : ''; ?>>Mensal</option>
                                <option value="yearly"  <?php echo $f('type') === 'yearly'  ? 'selected' : ''; ?>>Anual</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Categoria</label>
                            <input type="text" name="category" class="form-control"
                                   value="<?php echo e($f('category')); ?>"
                                   placeholder="Ex: Qualidade, Segurança" list="cat-list">
                            <datalist id="cat-list">
                                <option value="Qualidade">
                                <option value="Segurança do Paciente">
                                <option value="Controle de Infecção">
                                <option value="Satisfação">
                                <option value="Produção">
                                <option value="Outros">
                            </datalist>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Unidade</label>
                            <input type="text" name="unit" class="form-control"
                                   value="<?php echo e($f('unit')); ?>" placeholder="%, dias, casos...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Casas decimais</label>
                            <input type="number" name="decimal_places" class="form-control" min="0" max="6"
                                   value="<?php echo (int) $f('decimal_places', 2); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrição</label>
                            <textarea name="description" class="form-control" rows="2"
                                      placeholder="O que mede, metodologia, fonte de dados..."><?php echo e($f('description')); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-box me-1"></i>Variáveis (medidas coletadas)</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddVar">
                        <i class="bi bi-plus-lg me-1"></i>Adicionar
                    </button>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        Defina as <strong>medidas brutas</strong> coletadas a cada lançamento.
                        Exemplo: <em>numerador (casos) e denominador (total)</em>. Use códigos curtos
                        (<code>a</code>, <code>b</code>, <code>num</code>, <code>den</code>) para referenciá-los na fórmula.
                    </p>

                    <div id="varContainer">
                        <?php
                        $vars_to_render = !empty($variables) ? $variables : [
                            ['code' => '', 'label' => '', 'unit' => ''],
                        ];
                        foreach ($vars_to_render as $v): ?>
                        <div class="row g-2 mb-2 align-items-end var-row">
                            <div class="col-md-2">
                                <label class="form-label">Código</label>
                                <input type="text" name="var_code[]" class="form-control form-control-sm var-code"
                                       placeholder="a" pattern="[a-zA-Z][a-zA-Z0-9_]*"
                                       value="<?php echo e($v['code'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Rótulo</label>
                                <input type="text" name="var_label[]" class="form-control form-control-sm"
                                       placeholder="Número de infecções"
                                       value="<?php echo e($v['label'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Unidade</label>
                                <input type="text" name="var_unit[]" class="form-control form-control-sm"
                                       placeholder="casos, dias..."
                                       value="<?php echo e($v['unit'] ?? ''); ?>">
                            </div>
                            <div class="col-md-1">
                                <button type="button" class="btn btn-outline-danger btn-action btn-remove-var"
                                        data-bs-toggle="tooltip" title="Remover">
                                    <i class="bi bi-x"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-calculator me-1"></i>Fórmula de cálculo
                </div>
                <div class="card-body">
                    <label class="form-label">Expressão</label>
                    <input type="text" name="formula" id="formula" class="form-control font-monospace"
                           value="<?php echo e($f('formula')); ?>" placeholder="Ex: (a/b)*100">
                    <div class="mt-2 small text-muted">
                        <strong>Operadores:</strong> <code>+ − * / ^ ( )</code> &nbsp;
                        <strong>Funções:</strong> <code>abs, sqrt, round, min, max</code><br>
                        <strong>Exemplos:</strong>
                        <code>(a/b)*100</code> taxa &nbsp;
                        <code>a-b</code> variação &nbsp;
                        <code>(a+b+c)/3</code> média
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Testar fórmula</label>
                        <div id="formulaTestBox" class="d-flex flex-wrap gap-2 align-items-end"></div>
                        <div id="formulaResult" class="mt-2 small text-muted">
                            Preencha as variáveis e a fórmula para ver o resultado.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Coluna direita: meta + visual -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-bullseye me-1"></i>Meta
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Valor</label>
                            <input type="text" name="goal" class="form-control"
                                   value="<?php echo e($f('goal_numeric') !== null && $f('goal_numeric') !== '' ? $f('goal_numeric') : $f('goal')); ?>"
                                   placeholder="Ex: 95, 2.5">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tolerância</label>
                            <input type="text" name="goal_tolerance" class="form-control"
                                   value="<?php echo e($f('goal_tolerance', '0')); ?>" placeholder="0">
                            <small class="text-muted">Margem aceitável.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Direção</label>
                            <select name="goal_direction" class="form-select">
                                <option value="higher_better" <?php echo $f('goal_direction') === 'higher_better' ? 'selected' : ''; ?>>Quanto MAIOR, melhor (≥ meta)</option>
                                <option value="lower_better"  <?php echo $f('goal_direction') === 'lower_better'  ? 'selected' : ''; ?>>Quanto MENOR, melhor (≤ meta)</option>
                                <option value="target"        <?php echo $f('goal_direction') === 'target'        ? 'selected' : ''; ?>>Alvo exato (|valor − meta| ≤ tol.)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-graph-up me-1"></i>Visualização
                </div>
                <div class="card-body">
                    <label class="form-label">Tipo de gráfico</label>
                    <div class="btn-group w-100" role="group">
                        <?php foreach (['line' => 'Linha', 'bar' => 'Barras', 'area' => 'Área'] as $val => $label):
                            $checked = $f('chart_type', 'line') === $val ? 'checked' : ''; ?>
                            <input type="radio" class="btn-check" name="chart_type" id="ct_<?php echo $val; ?>" value="<?php echo $val; ?>" <?php echo $checked; ?>>
                            <label class="btn btn-outline-primary" for="ct_<?php echo $val; ?>"><?php echo $label; ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-bar-chart me-1"></i>Benchmark externo
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Valor de referência</label>
                            <input type="text" name="benchmark_value" class="form-control"
                                   value="<?php echo e($f('benchmark_value')); ?>"
                                   placeholder="Ex: 75">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">Fonte</label>
                            <input type="text" name="benchmark_source" class="form-control"
                                   value="<?php echo e($f('benchmark_source')); ?>"
                                   placeholder="Ex: ANAHP, ANVISA, OMS">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-award me-1"></i>Acreditação e Rastreabilidade
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Acreditações / referências</label>
                        <input type="text" name="accreditation" class="form-control"
                               value="<?php echo e($f('accreditation')); ?>"
                               placeholder="ONA, JCI, ANVISA, ANS...">
                        <small class="text-muted">Separe por vírgula.</small>
                    </div>
                </div>
            </div>

            <?php if (!empty($f('variables'))): ?>
                <input type="hidden" name="variables_legacy" value="<?php echo e($f('variables')); ?>">
            <?php endif; ?>
            <?php if (!empty($f('template_slug'))): ?>
                <input type="hidden" name="template_slug" value="<?php echo e($f('template_slug')); ?>">
            <?php endif; ?>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill">
                    <i class="bi bi-check-lg me-1"></i><?php echo $is_edit ? 'Atualizar' : 'Criar'; ?>
                </button>
                <a href="<?php echo url($is_edit ? 'indicators/view?id=' . (int) $ind['id'] : 'indicators'); ?>"
                   class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </div>
    </div>
</form>

<template id="varTemplate">
    <div class="row g-2 mb-2 align-items-end var-row">
        <div class="col-md-2">
            <label class="form-label">Código</label>
            <input type="text" name="var_code[]" class="form-control form-control-sm var-code" placeholder="a" pattern="[a-zA-Z][a-zA-Z0-9_]*">
        </div>
        <div class="col-md-6">
            <label class="form-label">Rótulo</label>
            <input type="text" name="var_label[]" class="form-control form-control-sm" placeholder="Descrição">
        </div>
        <div class="col-md-3">
            <label class="form-label">Unidade</label>
            <input type="text" name="var_unit[]" class="form-control form-control-sm" placeholder="(opcional)">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-outline-danger btn-action btn-remove-var">
                <i class="bi bi-x"></i>
            </button>
        </div>
    </div>
</template>

<script>
(function() {
    var container = document.getElementById('varContainer');
    var tpl       = document.getElementById('varTemplate');
    var addBtn    = document.getElementById('btnAddVar');
    var testBox   = document.getElementById('formulaTestBox');
    var result    = document.getElementById('formulaResult');
    var formula   = document.getElementById('formula');
    var FN_NAMES  = ['abs','sqrt','round','min','max'];

    function bindRow(row) {
        row.querySelector('.btn-remove-var').addEventListener('click', function() {
            row.remove(); rebuildTestBox();
        });
        row.querySelectorAll('input').forEach(function(inp) {
            inp.addEventListener('input', rebuildTestBox);
        });
    }
    container.querySelectorAll('.var-row').forEach(bindRow);

    addBtn.addEventListener('click', function() {
        var row = tpl.content.firstElementChild.cloneNode(true);
        container.appendChild(row);
        bindRow(row); rebuildTestBox();
    });

    function rebuildTestBox() {
        testBox.innerHTML = '';
        container.querySelectorAll('.var-code').forEach(function(codeInp) {
            var code = (codeInp.value || '').trim();
            if (!code) return;
            var wrap = document.createElement('div');
            wrap.innerHTML = '<label class="form-label mb-0">'+code+'</label>'
                +'<input type="number" step="any" data-var="'+code+'" class="form-control form-control-sm test-var" style="width:90px">';
            testBox.appendChild(wrap);
        });
        testBox.querySelectorAll('.test-var').forEach(function(i) {
            i.addEventListener('input', evaluateFormula);
        });
        evaluateFormula();
    }

    function evaluateFormula() {
        var expr = (formula.value || '').trim();
        if (!expr) { result.className='mt-2 small text-muted'; result.textContent=''; return; }
        var vars = {};
        var allFilled = true;
        testBox.querySelectorAll('.test-var').forEach(function(i) {
            var v = parseFloat(i.value);
            if (isNaN(v)) allFilled = false;
            vars[i.dataset.var] = v;
        });
        if (!allFilled) {
            result.className='mt-2 small text-muted';
            result.textContent='Preencha as variáveis acima para testar.';
            return;
        }
        try {
            var safe = expr.replace(/[a-zA-Z_][a-zA-Z0-9_]*/g, function(m) {
                if (FN_NAMES.indexOf(m) !== -1) return 'Math.'+m;
                if (!(m in vars)) throw new Error("Variável '"+m+"' não definida.");
                return '('+vars[m]+')';
            });
            if (!/^[\d\s+\-*\/().,Math a-zA-Z_]+$/.test(safe)) throw new Error('Caractere inválido.');
            var fn = new Function('return ('+safe+')');
            var v = fn();
            if (isNaN(v) || !isFinite(v)) throw new Error('Resultado inválido.');
            result.className = 'mt-2 small text-success';
            result.innerHTML = '<i class="bi bi-check-circle me-1"></i>Resultado: <strong>' + (+v).toFixed(4) + '</strong>';
        } catch (err) {
            result.className = 'mt-2 small text-danger';
            result.textContent = 'Erro: ' + err.message;
        }
    }

    formula.addEventListener('input', evaluateFormula);
    rebuildTestBox();
})();
</script>
