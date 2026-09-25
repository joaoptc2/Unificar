<?php
/** ENFERMAGEM — roteiro da ligação (o que falar, palavra por palavra). */

declare(strict_types=1);

use Core\Layout;

core_require('ccih.view');

$perguntas = [
    ['1. Febre', '"Depois que saiu do hospital, teve febre? Mediu? Deu mais de 37,8?" → SIM se febre medida ≥ 37,8 °C ou relato de calafrios/febre sem medir.'],
    ['2. Vermelhidão / calor / inchaço', '"A pele em volta da cicatriz está vermelha, quente ou inchada — mais do que nos primeiros dias?" → SIM se está piorando ou apareceu depois.'],
    ['3. Dor', '"A dor no local está melhorando a cada dia, ou piorando?" → SIM se está piorando ou voltou a doer depois de melhorar.'],
    ['4. Secreção (líquido)', '"Saiu algum líquido pela cicatriz ou pelo dreno? Qual a cor? Era grosso? Cheiro ruim?" → NÃO / CLARA-AMARELADA (fino, claro) / COM PUS (grosso, escuro/esverdeado, cheiro ruim).'],
    ['5. Ferida abriu', '"Algum ponto abriu ou a cicatriz se abriu em algum lugar?" → SIM / NÃO.'],
    ['6. Antibiótico', '"Algum médico passou antibiótico depois da alta? Qual o nome?" → SIM / NÃO. Anote o remédio nas observações.'],
    ['7. Voltou ao médico', '"Precisou voltar ao médico, a um pronto-socorro, ou ficou internado por causa da cirurgia?" → rotina / extra por queixa / reinternação / reoperação.'],
    ['8. Aberta', '"Tem mais alguma coisa que notou e quer contar?" → anote nas observações.'],
];

$alertas = [
    ['danger',  'AVISAR CCIH (vermelho)', 'Comunique a enfermeira da CCIH NO MESMO DIA (WhatsApp institucional / ramal). Não oriente tratamento nem diga que é infecção: quem avalia é a CCIH e o cirurgião.'],
    ['warning', 'OBSERVAR (amarelo)', 'Informe a CCIH na rotina (fim do dia). Geralmente é secreção clara ou consulta extra sem outros sinais.'],
    ['success', 'SEM QUEIXAS (verde)', 'Nada a fazer. Se for paciente com prótese, o sistema já marca as próximas ligações (60 e 90 dias).'],
];

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-card-checklist me-2"></i>Roteiro da ligação</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= core_module_url('enfermagem', ['page' => 'painel']) ?>"><i class="bi bi-arrow-left me-1"></i>Painel</a>
</div>

<p class="text-muted">Fale com calma, em linguagem simples. Você <strong>não precisa saber de medicina</strong>: basta anotar exatamente o que o paciente responder. Quem decide se é infecção é a CCIH.</p>

<div class="card mb-3">
    <div class="card-header"><i class="bi bi-chat-quote me-1"></i>Apresentação</div>
    <div class="card-body">
        <p class="mb-2 fst-italic">"Bom dia/boa tarde. Meu nome é ______, falo do hospital, do setor que acompanha a recuperação dos pacientes. Posso falar com [NOME]? Estou ligando para saber como está a recuperação da cirurgia do dia [DATA]. É rápido, uns 3 minutos. Tudo bem?"</p>
        <ul class="small text-muted mb-0">
            <li><strong>Se não for o paciente:</strong> pergunte se é responsável/familiar que acompanha. Se for, pode fazer as perguntas. Se não, combine melhor horário e anote nas observações.</li>
            <li><strong>Se recusar:</strong> agradeça, registre 'PACIENTE RECUSOU' e não insista.</li>
        </ul>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><i class="bi bi-list-ol me-1"></i>Perguntas (faça TODAS, na ordem)</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($perguntas as [$titulo, $texto]): ?>
        <li class="list-group-item">
            <div class="fw-semibold"><?= core_e($titulo) ?></div>
            <div class="small text-muted"><?= core_e($texto) ?></div>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="card-footer small text-muted">Não pule perguntas: mesmo que o paciente diga "está tudo bem", faça todas — muitas vezes ele só lembra do sintoma quando perguntado.</div>
</div>

<div class="card mb-3">
    <div class="card-header"><i class="bi bi-chat-heart me-1"></i>Encerramento</div>
    <div class="card-body">
        <p class="mb-2 fst-italic">"Muito obrigada. Se aparecer febre, vermelhidão, pus ou a ferida abrir, procure o seu cirurgião ou o hospital. Tenha uma boa recuperação!"</p>
        <p class="mb-0 small text-muted">Para pacientes com <strong>prótese</strong>: "Vamos ligar de novo daqui a um e dois meses, tudo bem?"</p>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><i class="bi bi-flag me-1"></i>O que fazer depois da ligação</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($alertas as [$cor, $titulo, $texto]): ?>
        <li class="list-group-item">
            <span class="badge text-bg-<?= $cor ?> mb-1"><?= core_e($titulo) ?></span>
            <div class="small text-muted"><?= core_e($texto) ?></div>
        </li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="alert alert-light border small mb-0">
    <strong>Palavras que indicam possível infecção</strong> (anote sempre que ouvir): pus · secreção grossa · cheiro ruim/forte · vermelho e quente · inchou de repente · febre · calafrio · ponto abriu · a ferida abriu · precisou drenar · o médico abriu para limpar · tomando antibiótico · internou de novo · fez outra cirurgia.
    <div class="mt-2 text-danger"><i class="bi bi-shield-lock me-1"></i>Dados de saúde são sigilosos (LGPD): não compartilhe fora da CCIH.</div>
</div>
<?php
Layout::render(['title' => 'Roteiro', 'content' => (string) ob_get_clean(), 'active' => 'roteiro']);
