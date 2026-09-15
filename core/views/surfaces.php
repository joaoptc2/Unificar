<?php
/**
 * GALERIA DE SUPERFÍCIES — todas as peças da interface numa página só.
 *
 * Serve a três propósitos:
 *
 *  1. Conferência: mudar a cor primária afeta topo, botões, selos, tabelas,
 *     alertas, gráficos, e-mail e impressão. Antes era preciso abrir seis
 *     telas para ver se alguma tinha ficado ilegível.
 *  2. Contrato do CSS livre: as classes marcadas com "estável" são as que o
 *     administrador pode usar no CSS dele sem medo de a próxima atualização
 *     quebrar tudo.
 *  3. Teste visual: é esta página que o teste automatizado fotografa e
 *     compara entre versões (scripts/test_visual.mjs).
 *
 * É só leitura e não depende de dado nenhum do hospital — pode ser aberta
 * em produção a qualquer momento.
 */
$estaveis = [
    '.portal-topbar'        => 'Barra superior',
    '.portal-sidebar'       => 'Menu lateral',
    '.portal-sidebar-nav'   => 'Lista de itens do menu',
    '.portal-main'          => 'Área de conteúdo',
    '.portal-content'       => 'Miolo com largura máxima',
    '.portal-module-card'   => 'Cartão de módulo',
    '.portal-avisos'        => 'Avisos de canto (notificações)',
    '.portal-bare-card'     => 'Cartão da tela de login',
];
$rep = static fn (string $fg, string $bg): array => Core\Tokens::contrastReport($fg, $bg);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-grid-3x3-gap me-2"></i>Galeria de superfícies</h1>
    <a class="btn btn-outline-secondary" href="<?= core_module_url('admin', ['a' => 'appearance']) ?>">
        <i class="bi bi-palette me-1"></i>Voltar à Aparência
    </a>
</div>
<p class="text-muted">
    Todas as peças da interface com a identidade em uso. Serve para conferir de uma vez o efeito de
    uma mudança de cor — e é esta página que o teste visual automatizado compara entre versões.
</p>

<div class="row g-3">
    <!-- Cores em uso -->
    <div class="col-12">
        <div class="card">
            <div class="card-header">Cores em uso e legibilidade</div>
            <div class="card-body">
                <div class="row g-2">
                    <?php
                    $superficie = Core\Branding::get('body_bg', '#ffffff');
                    foreach ([
                        'primary' => 'Primária', 'accent' => 'Destaque',
                        'success' => 'Sucesso', 'warning' => 'Alerta',
                        'danger'  => 'Erro',    'info'    => 'Informação',
                    ] as $k => $rotulo):
                        $cor = Core\Tokens::color(Core\Branding::get($k), '#cccccc');
                        $sobre = $rep(Core\Tokens::contrastColor($cor), $cor);
                        $noFundo = $rep($cor, $superficie);
                    ?>
                    <div class="col-6 col-md-4 col-xl-2">
                        <div class="border rounded overflow-hidden h-100">
                            <div style="background:<?= core_e($cor) ?>;color:<?= core_e(Core\Tokens::contrastColor($cor)) ?>;padding:.75rem">
                                <div class="fw-semibold small"><?= core_e($rotulo) ?></div>
                                <div class="small font-monospace"><?= core_e($cor) ?></div>
                            </div>
                            <div class="p-2 small">
                                <div>texto sobre ela:
                                    <span class="badge text-bg-<?= $sobre['nivel'] === 'ok' ? 'success' : ($sobre['nivel'] === 'aviso' ? 'warning' : 'danger') ?>">
                                        <?= core_e($sobre['texto']) ?>
                                    </span>
                                </div>
                                <div class="mt-1">ela sobre o fundo:
                                    <span class="badge text-bg-<?= $noFundo['nivel'] === 'ok' ? 'success' : ($noFundo['nivel'] === 'aviso' ? 'warning' : 'danger') ?>">
                                        <?= core_e($noFundo['texto']) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Botões e selos -->
    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header">Botões, selos e alertas</div>
            <div class="card-body d-flex flex-column gap-3">
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach (['primary','secondary','success','warning','danger','info'] as $v): ?>
                        <button class="btn btn-<?= $v ?> btn-sm"><?= $v ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach (['primary','secondary','success','warning','danger','info'] as $v): ?>
                        <button class="btn btn-outline-<?= $v ?> btn-sm">outline</button>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach (['primary','secondary','success','warning','danger','info','light','dark'] as $v): ?>
                        <span class="badge text-bg-<?= $v ?>"><?= $v ?></span>
                    <?php endforeach; ?>
                </div>
                <?php foreach (['success' => 'Documento aprovado.', 'warning' => 'Vence em 3 dias.',
                                'danger' => 'Documento vencido.', 'info' => 'Revisão agendada.'] as $v => $txt): ?>
                    <div class="alert alert-<?= $v ?> py-2 mb-0 small"><?= core_e($txt) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Formulários e tabela -->
    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header">Formulário e tabela</div>
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label small" for="gsTexto">Campo de texto</label>
                        <input class="form-control form-control-sm" id="gsTexto" value="Conteúdo de exemplo">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small" for="gsSel">Seleção</label>
                        <select class="form-select form-select-sm" id="gsSel"><option>Todos os setores</option></select>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="gsChk" checked>
                            <label class="form-check-label small" for="gsChk">Somente controlados</label>
                        </div>
                        <div class="form-text">Texto de ajuda, no tom secundário.</div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Código</th><th>Título</th><th>Status</th></tr></thead>
                        <tbody>
                            <tr><td>POP-014</td><td>Higienização de mãos</td><td><span class="badge text-bg-success">Aprovado</span></td></tr>
                            <tr class="table-warning"><td>PRO-008</td><td>Admissão de paciente</td><td><span class="badge text-bg-warning">Vencendo</span></td></tr>
                            <tr class="table-danger"><td>IT-031</td><td>Calibração de bombas</td><td><span class="badge text-bg-danger">Vencido</span></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Contrato do CSS livre -->
    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-braces me-1"></i>Contrato do CSS livre</div>
            <div class="card-body">
                <p class="small text-muted">
                    Estas classes são <strong>estáveis</strong>: o CSS que o hospital escrever em
                    Aparência &rsaquo; CSS livre pode se apoiar nelas, e elas não mudam de nome em
                    atualizações. Qualquer outra classe pode mudar sem aviso — inclusive as do Bootstrap.
                </p>
                <div class="row g-2">
                    <?php foreach ($estaveis as $classe => $desc): ?>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="border rounded p-2 h-100">
                            <code class="small"><?= core_e($classe) ?></code>
                            <div class="small text-muted"><?= core_e($desc) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="small text-muted mt-3 mb-0">
                    Prefira as variáveis a cores fixas: <code>var(--portal-primary)</code>,
                    <code>var(--portal-border)</code>, <code>var(--portal-radius)</code>. Assim o seu CSS
                    acompanha o tema, inclusive no modo escuro.
                </p>
            </div>
        </div>
    </div>
</div>
