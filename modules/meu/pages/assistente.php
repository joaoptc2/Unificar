<?php
/**
 * MEU ESPAÇO — assistente.
 *
 * Três funções, todas sobre o que é DO PRÓPRIO USUÁRIO e todas com o texto à
 * vista antes de sair. A tela mostra exatamente o que será enviado, porque
 * "a IA leu meus dados" é uma frase que precisa ter resposta verificável.
 */

declare(strict_types=1);

use Core\Assistente;
use Core\Csrf;
use Core\Flash;
use Core\Layout;

core_require('assistente.usar');

if (!Assistente::ligado()) {
    Layout::renderError(404, 'O assistente não está ligado nesta instalação. '
        . 'Um administrador pode ligá-lo em Administração › Assistente.');
    exit;
}

$uid = meu_uid();
$url = fn (array $q = []) => core_module_url('meu', ['page' => 'assistente'] + $q);

/**
 * As funções disponíveis. Cada uma declara o que monta e o que manda — e o
 * que monta vem SEMPRE das tabelas do próprio usuário.
 */
$funcoes = [
    'organizar' => [
        'rotulo'  => 'Transformar anotação em tarefas',
        'ajuda'   => 'Cole o que você anotou correndo. Devolve uma lista de tarefas, uma por linha.',
        'sistema' => 'Você ajuda um profissional de hospital a organizar o próprio trabalho. '
                   . 'Receba uma anotação solta e devolva uma lista de tarefas objetivas, uma por linha, '
                   . 'começando cada linha com "- ". Não invente prazo, pessoa ou detalhe que não esteja '
                   . 'no texto. Se algo estiver ambíguo, mantenha a ambiguidade em vez de escolher por '
                   . 'conta própria. Responda em português do Brasil, sem preâmbulo.',
        'entrada' => 'livre',
    ],
    'resumir' => [
        'rotulo'  => 'Resumir minhas solicitações abertas',
        'ajuda'   => 'Lê as solicitações que VOCÊ recebeu e estão abertas, e devolve o que é urgente.',
        'sistema' => 'Você ajuda um profissional de hospital a priorizar. Receba uma lista de '
                   . 'solicitações e devolva, em no máximo cinco linhas, o que precisa de atenção '
                   . 'primeiro e por quê. Use só o que está na lista. Português do Brasil, sem preâmbulo.',
        'entrada' => 'solicitacoes',
    ],
    'responder' => [
        'rotulo'  => 'Rascunhar uma resposta',
        'ajuda'   => 'Cole a mensagem recebida e diga em poucas palavras o que responder.',
        'sistema' => 'Você redige respostas profissionais curtas para um funcionário de hospital. '
                   . 'Receba a mensagem original e a intenção da resposta, e devolva SÓ o texto da '
                   . 'resposta — sem assunto, sem saudação genérica de rodapé, sem explicar o que fez. '
                   . 'Tom cordial e direto. Português do Brasil.',
        'entrada' => 'livre',
    ],
];

$escolhida = (string) ($_POST['funcao'] ?? $_GET['funcao'] ?? 'organizar');
if (!isset($funcoes[$escolhida])) {
    $escolhida = 'organizar';
}

$resposta = '';
$enviado  = '';
$avisos   = [];

/** Monta o texto da função que lê dados do portal. */
$montar = function (string $tipo) use ($uid): string {
    if ($tipo !== 'solicitacoes') {
        return '';
    }
    $linhas = [];
    foreach (meu_solicitacoes('recebidas', false) as $s) {
        $linhas[] = sprintf(
            '- %s (de %s, prioridade %s%s, situação %s)',
            $s['titulo'], $s['contraparte'], $s['prioridade'],
            $s['prazo'] ? ', prazo ' . date('d/m/Y', (int) strtotime($s['prazo'])) : '',
            $s['situacao']
        );
    }
    return $linhas ? implode("\n", $linhas) : '';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $f = $funcoes[$escolhida];

    $texto = $f['entrada'] === 'livre'
        ? trim((string) ($_POST['texto'] ?? ''))
        : $montar($f['entrada']);

    if ($escolhida === 'responder') {
        $intencao = trim((string) ($_POST['intencao'] ?? ''));
        if ($intencao !== '') {
            $texto = "MENSAGEM RECEBIDA:\n" . $texto . "\n\nO QUE RESPONDER:\n" . $intencao;
        }
    }

    if ($texto === '') {
        Flash::set('error', $f['entrada'] === 'livre'
            ? 'Escreva ou cole o texto.'
            : 'Não há nada para resumir: você não tem solicitações abertas.');
        core_redirect($url(['funcao' => $escolhida]));
    }

    // A trava age ANTES de sair. Ela recusa — não redige por cima, porque
    // apagar um CPF e mandar o resto daria ao usuário a impressão errada de
    // que o texto foi conferido.
    $marcas = Assistente::marcasSensiveis($texto);
    if ($marcas !== [] && empty($_POST['confirmo'])) {
        $avisos   = $marcas;
        $enviado  = $texto;
    } else {
        if ($marcas !== []) {
            // Seguiu mesmo assim: fica na auditoria, com a marca encontrada e
            // sem o texto.
            Core\Audit::log('ia.envio_com_marca', 'ia_uso', null,
                ['funcao' => $escolhida, 'marcas' => $marcas], $uid, 'meu');
        }
        $r = Assistente::perguntar($escolhida, $f['sistema'], $texto, ['max_tokens' => 1500]);
        if ($r['ok']) {
            $resposta = $r['texto'];
            $enviado  = $texto;
        } else {
            Flash::set('error', $r['erro']);
            $enviado = $texto;
        }
    }
}

$f = $funcoes[$escolhida];
$previa = $f['entrada'] === 'livre' ? '' : $montar($f['entrada']);
$saldo  = Assistente::saldo();

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-stars me-2"></i>Assistente</h1>
    <?php if ($saldo !== PHP_INT_MAX): ?>
        <span class="small text-muted">Resta US$ <?= number_format($saldo / 100, 2, ',', '.') ?> no mês</span>
    <?php endif; ?>
</div>

<div class="alert alert-light border small">
    <i class="bi bi-info-circle me-1"></i>
    O texto abaixo <strong>sai do hospital</strong> para ser processado. Fica registrado que houve a
    chamada e de que tamanho — <strong>o conteúdo não é guardado</strong>. Não envie dado de paciente.
</div>

<ul class="nav nav-pills mb-3 flex-wrap gap-1">
    <?php foreach ($funcoes as $k => $x): ?>
        <li class="nav-item">
            <a class="nav-link <?= $k === $escolhida ? 'active' : '' ?>" href="<?= $url(['funcao' => $k]) ?>">
                <?= core_e($x['rotulo']) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><?= core_e($f['rotulo']) ?></div>
            <div class="card-body">
                <p class="small text-muted"><?= core_e($f['ajuda']) ?></p>
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="funcao" value="<?= core_e($escolhida) ?>">

                    <?php if ($f['entrada'] === 'livre'): ?>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold" for="a_texto">
                                <?= $escolhida === 'responder' ? 'Mensagem recebida' : 'Sua anotação' ?>
                            </label>
                            <textarea class="form-control" id="a_texto" name="texto" rows="8"
                                      maxlength="<?= Assistente::TAMANHO_MAX ?>"><?= core_e($enviado !== '' && $escolhida !== 'responder' ? $enviado : (string) ($_POST['texto'] ?? '')) ?></textarea>
                        </div>
                        <?php if ($escolhida === 'responder'): ?>
                            <div class="mb-2">
                                <label class="form-label small fw-semibold" for="a_int">O que responder</label>
                                <input class="form-control" id="a_int" name="intencao" maxlength="500"
                                       placeholder="confirmar a reunião e pedir a pauta"
                                       value="<?= core_e((string) ($_POST['intencao'] ?? '')) ?>">
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="mb-2">
                            <span class="form-label small fw-semibold d-block">O que será enviado</span>
                            <?php if ($previa === ''): ?>
                                <p class="text-muted small mb-0">Você não tem solicitações abertas — nada a enviar.</p>
                            <?php else: ?>
                                <pre class="small bg-body-tertiary border rounded p-2 mb-0"
                                     style="max-height:220px;overflow:auto;white-space:pre-wrap"><?= core_e($previa) ?></pre>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($avisos): ?>
                        <div class="alert alert-danger small mt-2">
                            <strong>Parei antes de enviar.</strong> Encontrei no texto:
                            <ul class="mb-2 mt-1">
                                <?php foreach ($avisos as $a): ?><li><?= core_e($a) ?></li><?php endforeach; ?>
                            </ul>
                            Isto é uma conferência de formato, não de conteúdo — ela não reconhece um
                            relato clínico escrito por extenso. Se você tem certeza de que não há dado de
                            paciente aqui, confirme abaixo.
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="confirmo" id="a_conf" value="1">
                                <label class="form-check-label fw-semibold" for="a_conf">
                                    Confirmo que não há dado de paciente neste texto
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>

                    <button class="btn btn-primary btn-sm mt-2" <?= ($f['entrada'] !== 'livre' && $previa === '') ? 'disabled' : '' ?>>
                        <i class="bi bi-send me-1"></i><?= $avisos ? 'Enviar mesmo assim' : 'Enviar' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">Resposta</div>
            <div class="card-body">
                <?php if ($resposta === ''): ?>
                    <p class="text-muted text-center py-5 mb-0">A resposta aparece aqui.</p>
                <?php else: ?>
                    <div style="white-space:pre-wrap"><?= core_e($resposta) ?></div>
                    <hr>
                    <p class="small text-muted mb-0">
                        Confira antes de usar. O assistente erra, e num hospital o custo de um erro
                        repassado sem leitura não é o mesmo de um erro de digitação.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
Layout::render(['title' => 'Assistente', 'content' => (string) ob_get_clean(), 'active' => 'assistente']);
