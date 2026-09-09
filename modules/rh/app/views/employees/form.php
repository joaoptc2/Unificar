<?php
$isEdit = !empty($employee);
$formAction = $isEdit ? 'index.php?m=rh&page=employees&action=update' : 'index.php?m=rh&page=employees&action=store';
?>

<div class="page-header">
    <h1><i class="bi bi-person-plus me-2"></i><?= $isEdit ? 'Editar Funcionário' : 'Novo Funcionário' ?></h1>
    <a href="index.php?m=rh&page=employees" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<form method="POST" action="<?= $formAction ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= $employee['id'] ?>">
    <?php endif; ?>

    <div class="row g-3">
        <!-- Dados Pessoais -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-person me-1"></i> Dados Pessoais
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Nome Completo</label>
                            <input type="text" name="full_name" class="form-control"
                                   value="<?= Sanitize::e($employee['full_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required">CPF</label>
                            <input type="text" name="cpf" class="form-control" data-mask="cpf"
                                   value="<?= Sanitize::formatCpf($employee['cpf'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required">Data de Nascimento</label>
                            <input type="date" name="birth_date" class="form-control"
                                   value="<?= Sanitize::e($employee['birth_date'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label required">Sexo</label>
                            <select name="gender" class="form-select" required>
                                <option value="">Selecione</option>
                                <option value="M" <?= ($employee['gender'] ?? '') === 'M' ? 'selected' : '' ?>>Masculino</option>
                                <option value="F" <?= ($employee['gender'] ?? '') === 'F' ? 'selected' : '' ?>>Feminino</option>
                                <option value="O" <?= ($employee['gender'] ?? '') === 'O' ? 'selected' : '' ?>>Outro</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Telefone</label>
                            <input type="text" name="phone" class="form-control" data-mask="phone"
                                   value="<?= Sanitize::e($employee['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">E-mail</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= Sanitize::e($employee['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Foto</label>
                            <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png"
                                   data-preview="#photoPreview">
                            <?php if ($isEdit && $employee['photo']): ?>
                                <img src="<?= Sanitize::e(Upload::publicUrl($employee['photo'])) ?>" id="photoPreview"
                                     class="mt-2" style="max-height:80px; border-radius:8px;">
                            <?php else: ?>
                                <img id="photoPreview" class="mt-2" style="max-height:80px; border-radius:8px; display:none;">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Endereço -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-geo-alt me-1"></i> Endereço
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">CEP</label>
                            <div class="input-group">
                                <input type="text" name="address_zip" id="address_zip" class="form-control" data-mask="cep"
                                       value="<?= Sanitize::e($employee['address_zip'] ?? '') ?>"
                                       placeholder="00000-000">
                                <button type="button" id="cepLookupBtn" class="btn btn-outline-secondary" title="Buscar endereço pelo CEP">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                            <small class="text-muted" id="cepStatus">Informe o CEP e perca o foco (ou clique na lupa) para preencher automaticamente.</small>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Rua</label>
                            <input type="text" name="address_street" id="address_street" class="form-control"
                                   value="<?= Sanitize::e($employee['address_street'] ?? '') ?>">
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">Número</label>
                            <input type="text" name="address_number" class="form-control"
                                   value="<?= Sanitize::e($employee['address_number'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Complemento</label>
                            <input type="text" name="address_complement" class="form-control"
                                   value="<?= Sanitize::e($employee['address_complement'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Bairro</label>
                            <input type="text" name="address_neighborhood" id="address_neighborhood" class="form-control"
                                   value="<?= Sanitize::e($employee['address_neighborhood'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Cidade</label>
                            <input type="text" name="address_city" id="address_city" class="form-control"
                                   value="<?= Sanitize::e($employee['address_city'] ?? '') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Estado</label>
                            <select name="address_state" id="address_state" class="form-select">
                                <option value="">UF</option>
                                <?php
                                $states = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
                                foreach ($states as $uf):
                                ?>
                                    <option value="<?= $uf ?>" <?= ($employee['address_state'] ?? '') === $uf ? 'selected' : '' ?>><?= $uf ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dados Contratuais -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-file-earmark-text me-1"></i> Dados Contratuais
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Departamento</label>
                            <select name="department_id" class="form-select">
                                <option value="">Selecione</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= ($employee['department_id'] ?? '') == $d['id'] ? 'selected' : '' ?>>
                                        <?= Sanitize::e($d['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Cargo</label>
                            <select name="job_position_id" class="form-select">
                                <option value="">Selecione</option>
                                <?php foreach ($positions as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= ($employee['job_position_id'] ?? '') == $p['id'] ? 'selected' : '' ?>>
                                        <?= Sanitize::e($p['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required">Data de Admissão</label>
                            <input type="date" name="admission_date" class="form-control"
                                   value="<?= Sanitize::e($employee['admission_date'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tipo de Contrato</label>
                            <select name="contract_type" class="form-select">
                                <?php
                                $contracts = ['CLT','PJ','Temporario','Estagio','Terceirizado'];
                                foreach ($contracts as $c):
                                ?>
                                    <option value="<?= $c ?>" <?= ($employee['contract_type'] ?? 'CLT') === $c ? 'selected' : '' ?>><?= $c ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" id="statusSelect">
                                <option value="ativo" <?= ($employee['status'] ?? 'ativo') === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                                <option value="afastado" <?= ($employee['status'] ?? '') === 'afastado' ? 'selected' : '' ?>>Afastado</option>
                                <option value="desligado" <?= ($employee['status'] ?? '') === 'desligado' ? 'selected' : '' ?>>Desligado</option>
                            </select>
                        </div>
                        <div class="col-md-3" id="terminationDateGroup" style="<?= ($employee['status'] ?? '') === 'desligado' ? '' : 'display:none' ?>">
                            <label class="form-label">Data de Desligamento</label>
                            <input type="date" name="termination_date" class="form-control"
                                   value="<?= Sanitize::e($employee['termination_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-3" id="leaveDateGroup" style="<?= ($employee['status'] ?? '') === 'afastado' ? '' : 'display:none' ?>">
                            <label class="form-label">Data de Afastamento</label>
                            <input type="date" name="leave_date" class="form-control"
                                   value="<?= Sanitize::e($employee['leave_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-3" id="returnDateGroup" style="<?= ($employee['status'] ?? '') === 'afastado' ? '' : 'display:none' ?>">
                            <label class="form-label">Data de Retorno</label>
                            <input type="date" name="return_date" class="form-control"
                                   value="<?= Sanitize::e($employee['return_date'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Conselho Regional -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-award me-1"></i> Conselho Regional
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Conselho</label>
                            <?php
                            $councils = ['N/A', 'COREN', 'CRM', 'CRF'];
                            $currentCouncil = $employee['regional_council'] ?? 'N/A';
                            if (!in_array($currentCouncil, $councils, true)) $currentCouncil = 'N/A';
                            ?>
                            <select name="regional_council" id="regional_council" class="form-select">
                                <?php foreach ($councils as $opt): ?>
                                    <option value="<?= $opt ?>" <?= $currentCouncil === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4" id="councilNumberGroup" style="<?= $currentCouncil === 'N/A' ? 'display:none' : '' ?>">
                            <label class="form-label">Número do Conselho</label>
                            <input type="text" name="council_number" class="form-control"
                                   value="<?= Sanitize::e($employee['council_number'] ?? '') ?>">
                        </div>
                        <div class="col-md-4" id="councilExpiryGroup" style="<?= $currentCouncil === 'N/A' ? 'display:none' : '' ?>">
                            <label class="form-label">Validade da Carteira</label>
                            <input type="date" name="council_expiry" class="form-control"
                                   value="<?= Sanitize::e($employee['council_expiry'] ?? '') ?>">
                            <small class="text-muted">Gera um vencimento automático ao cadastrar.</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ASO (Atestado de Saúde Ocupacional) -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-file-medical me-1"></i> Exame Admissional / Periódico (ASO)
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Último exame realizado</label>
                            <input type="date" name="aso_admissional_date" class="form-control" required
                                   value="<?= Sanitize::e($employee['aso_admissional_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Próximo exame</label>
                            <input type="date" name="aso_next_date" class="form-control" required
                                   value="<?= Sanitize::e($employee['aso_next_date'] ?? '') ?>">
                            <small class="text-muted">Gera um vencimento automático ao cadastrar.</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!$isEdit): ?>
        <div class="col-12">
            <div class="alert alert-info mb-0 py-2 small">
                <i class="bi bi-person-lock me-1"></i>
                Ao cadastrar, o acesso ao portal é criado automaticamente: login = <strong>CPF</strong> e senha inicial =
                <strong>data de nascimento (ddmmaaaa)</strong>, com troca obrigatória no primeiro acesso.
            </div>
        </div>
        <?php endif; ?>

        <!-- Observações -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <label class="form-label">Observações Gerais</label>
                    <textarea name="notes" class="form-control" rows="3"><?= Sanitize::e($employee['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Botões -->
        <div class="col-12 text-end">
            <a href="index.php?m=rh&page=employees" class="btn btn-outline-secondary me-2">Cancelar</a>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Atualizar' : 'Cadastrar' ?>
            </button>
        </div>
    </div>
</form>

<?php if ($isEdit): ?>
    <div class="mt-3"><?php require __DIR__ . '/_access.php'; ?></div>
<?php endif; ?>

<script>
document.getElementById('statusSelect').addEventListener('change', function() {
    var v = this.value;
    document.getElementById('terminationDateGroup').style.display = v === 'desligado' ? '' : 'none';
    document.getElementById('leaveDateGroup').style.display = v === 'afastado' ? '' : 'none';
    document.getElementById('returnDateGroup').style.display = v === 'afastado' ? '' : 'none';
});

// Conselho Regional: esconde número/validade quando "N/A".
(function () {
    var councilSel = document.getElementById('regional_council');
    var numGroup   = document.getElementById('councilNumberGroup');
    var expGroup   = document.getElementById('councilExpiryGroup');
    if (!councilSel) return;
    function toggle() {
        var show = councilSel.value !== 'N/A';
        numGroup.style.display = show ? '' : 'none';
        expGroup.style.display = show ? '' : 'none';
    }
    councilSel.addEventListener('change', toggle);
    toggle();
})();

// Busca de CEP via ViaCEP (https://viacep.com.br) — API pública, sem autenticação.
(function () {
    var cepInput = document.getElementById('address_zip');
    var btn      = document.getElementById('cepLookupBtn');
    var status   = document.getElementById('cepStatus');
    if (!cepInput) return;

    function lookup() {
        var cep = (cepInput.value || '').replace(/\D/g, '');
        if (cep.length !== 8) {
            status.textContent = 'CEP inválido (precisa ter 8 dígitos).';
            status.className = 'text-danger';
            return;
        }
        status.textContent = 'Buscando...';
        status.className = 'text-muted';

        fetch('https://viacep.com.br/ws/' + cep + '/json/')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.erro) {
                    status.textContent = 'CEP não encontrado.';
                    status.className = 'text-danger';
                    return;
                }
                // Preencher apenas campos vazios (preserva edição manual).
                var fill = function (id, val) {
                    var el = document.getElementById(id);
                    if (el && (!el.value || !el.dataset.cepFilled) && val) {
                        el.value = val;
                        el.dataset.cepFilled = '1';
                    }
                };
                fill('address_street',       data.logradouro);
                fill('address_neighborhood', data.bairro);
                fill('address_city',         data.localidade);
                var state = document.getElementById('address_state');
                if (state && data.uf) state.value = data.uf;

                status.textContent = 'Endereço preenchido — ajuste o número e complemento.';
                status.className = 'text-success';
            })
            .catch(function () {
                status.textContent = 'Falha ao consultar CEP (verifique sua conexão).';
                status.className = 'text-danger';
            });
    }

    cepInput.addEventListener('blur', lookup);
    if (btn) btn.addEventListener('click', lookup);
})();
</script>
