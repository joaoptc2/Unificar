<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Política de Privacidade — <?= Sanitize::e($hospitalName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php?page=public_recruitment">
                <i class="bi bi-hospital me-1"></i> <?= Sanitize::e($hospitalName) ?>
            </a>
        </div>
    </nav>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4 p-md-5">
                        <h2 class="fw-bold mb-3">
                            <i class="bi bi-shield-lock me-2 text-primary"></i>
                            Política de Privacidade
                        </h2>
                        <p class="text-muted small">Última atualização: <?= date('d/m/Y') ?></p>

                        <h5 class="mt-4">1. Quais dados coletamos</h5>
                        <p>Ao se inscrever em um processo seletivo, coletamos: nome completo, CPF,
                        e-mail, telefone, endereço (opcional), área de atuação, experiência profissional
                        e currículo. Em colaboradores efetivos, coletamos também dados contratuais
                        (cargo, departamento, admissão, contrato), documentos profissionais e atestados
                        médicos quando aplicável.</p>

                        <h5 class="mt-4">2. Finalidade do tratamento</h5>
                        <ul>
                            <li>Avaliação de candidaturas em processos seletivos.</li>
                            <li>Gestão de pessoas (admissão, folha, contratos).</li>
                            <li>Cumprimento de obrigações legais e regulatórias (eSocial, Ministério do Trabalho, conselhos profissionais).</li>
                            <li>Notificações operacionais (vencimentos, treinamentos, agenda).</li>
                        </ul>

                        <h5 class="mt-4">3. Base legal (LGPD art. 7º)</h5>
                        <p>Para candidatos: <strong>consentimento</strong> (art. 7º, I) registrado no momento da inscrição.<br>
                           Para colaboradores: <strong>execução de contrato</strong> (art. 7º, V) e <strong>cumprimento de obrigação legal</strong> (art. 7º, II).</p>

                        <h5 class="mt-4">4. Compartilhamento</h5>
                        <p>Os dados não são compartilhados com terceiros, exceto para cumprimento
                        de obrigações legais (órgãos públicos) ou mediante seu consentimento explícito.</p>

                        <h5 class="mt-4">5. Tempo de retenção</h5>
                        <ul>
                            <li>Currículos de candidatos: até 12 meses após o encerramento do processo seletivo (incluindo banco de talentos).</li>
                            <li>Dados de colaboradores: pelo período da relação de trabalho, mais o prazo legal de guarda de documentos trabalhistas.</li>
                            <li>Logs de auditoria: 365 dias.</li>
                        </ul>

                        <h5 class="mt-4">6. Seus direitos (LGPD art. 18)</h5>
                        <p>Você pode, a qualquer momento, solicitar:</p>
                        <ul>
                            <li>Confirmação e acesso aos dados que possuímos sobre você;</li>
                            <li>Correção de dados incompletos, inexatos ou desatualizados;</li>
                            <li><strong>Eliminação</strong> dos dados (direito ao esquecimento);</li>
                            <li>Portabilidade dos dados;</li>
                            <li>Revogação do consentimento.</li>
                        </ul>
                        <p class="alert alert-info">
                            <strong>Candidatos:</strong> use o
                            <a href="index.php?page=public_recruitment&action=track">acompanhamento da candidatura</a>
                            para visualizar e excluir seus dados, ou escreva para
                            <strong><?= Sanitize::e($contactEmail ?: 'rh@hospital.com.br') ?></strong>.
                        </p>

                        <h5 class="mt-4">7. Segurança</h5>
                        <p>Adotamos medidas técnicas e administrativas para proteger seus dados:
                        senhas com hash bcrypt, transmissão criptografada (HTTPS), controle de acesso
                        por perfil, registro de auditoria de todas as ações, isolamento de documentos
                        sensíveis (fora da pasta pública do servidor) e revisão periódica.</p>

                        <h5 class="mt-4">8. Encarregado (DPO)</h5>
                        <p>Para questões sobre proteção de dados, entre em contato:
                        <strong><?= Sanitize::e($contactEmail ?: 'rh@hospital.com.br') ?></strong>.</p>

                        <hr class="my-4">
                        <a href="index.php?page=public_recruitment" class="btn btn-outline-primary">
                            <i class="bi bi-arrow-left me-1"></i> Voltar
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
