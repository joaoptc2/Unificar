<?php
/**
 * MEU ESPAÇO — solicitações entre pessoas.
 *
 * Esta é a primeira tela do módulo em que o dado NÃO é só meu: uma
 * solicitação tem duas pontas. Todo carregamento usa meu_solicitacao() ou
 * meu_solicitacoes(), que levam o pertencimento no WHERE — nunca se carrega
 * pelo id para decidir depois o que mostrar.
 */

declare(strict_types=1);

use Core\Audit;
use Core\Csrf;
use Core\DB;
use Core\Flash;
use Core\Layout;
use Core\Notifications;

core_require('solicitacoes.view');

$uid  = meu_uid();
$eu   = Core\Auth::user();
$url  = fn (array $q = []) => core_module_url('meu', ['page' => 'solicitacoes'] + $q);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $op = (string) ($_POST['op'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($op === 'enviar') {
        core_require('solicitacoes.enviar');
        $para   = meu_usuario_valido((int) ($_POST['destinatario_id'] ?? 0));
        $titulo = trim((string) ($_POST['titulo'] ?? ''));
        if ($para === null) {
            Flash::set('error', 'Escolha uma pessoa ativa para receber a solicitação.');
            core_redirect($url(['nova' => 1]));
        }
        if ((int) $para['id'] === $uid) {
            Flash::set('error', 'Para você mesmo, use as tarefas — é o que elas são.');
            core_redirect($url(['nova' => 1]));
        }
        if ($titulo === '') {
            Flash::set('error', 'Descreva em uma linha o que você precisa.');
            core_redirect($url(['nova' => 1]));
        }
        $prazo = trim((string) ($_POST['prazo'] ?? ''));
        $prazo = ($prazo !== '' && strtotime($prazo)) ? date('Y-m-d', (int) strtotime($prazo)) : null;
        $prio  = in_array($_POST['prioridade'] ?? '', ['baixa', 'normal', 'alta'], true) ? (string) $_POST['prioridade'] : 'normal';

        DB::execute(
            'INSERT INTO meu_solicitacoes (remetente_id, destinatario_id, titulo, mensagem, prioridade, prazo)
             VALUES (?,?,?,?,?,?)',
            [$uid, (int) $para['id'], mb_substr($titulo, 0, 200),
             trim((string) ($_POST['mensagem'] ?? '')) ?: null, $prio, $prazo]
        );
        $novo = DB::lastId();
        // Notificar é o ÚNICO canal de descoberta: sem isto o destinatário
        // nunca fica sabendo. O link aponta para a tela dele, não para a
        // minha — um link para "minhas enviadas" entregue a quem recebeu dá
        // tela vazia, que a pessoa lê como sistema quebrado.
        Notifications::add((int) $para['id'], 'Nova solicitação de ' . $eu['name'],
            mb_substr($titulo, 0, 160),
            core_module_url('meu', ['page' => 'solicitacoes', 'ver' => $novo]), 'solicitacao', 'meu');
        Flash::set('success', 'Solicitação enviada para ' . $para['name'] . '.');
        core_redirect($url(['aba' => 'enviadas']));
    }

    // Daqui para baixo, tudo age sobre uma solicitação existente — e só
    // carrega se eu for uma das pontas.
    $s = meu_solicitacao($id);
    if ($s === null) {
        Flash::set('error', 'Solicitação não encontrada.');
        core_redirect($url());
    }
    $souDestinatario = (int) $s['destinatario_id'] === $uid;
    $souRemetente    = (int) $s['remetente_id'] === $uid;

    if ($op === 'responder' && $souDestinatario) {
        core_require('solicitacoes.responder');
        $nova = in_array($_POST['situacao'] ?? '', ['aceita', 'recusada', 'concluida'], true)
              ? (string) $_POST['situacao'] : '';
        if ($nova === '') {
            core_redirect($url(['ver' => $id]));
        }
        $resposta = trim((string) ($_POST['resposta'] ?? ''));
        $tarefaId = $s['tarefa_id'] !== null ? (int) $s['tarefa_id'] : null;

        // Aceitar cria a tarefa correspondente: sem isso o "aceito" não vira
        // trabalho em lugar nenhum e o pedido some da vista de quem aceitou.
        if ($nova === 'aceita' && $tarefaId === null) {
            DB::execute(
                'INSERT INTO meu_tarefas (user_id, titulo, detalhe, prazo, prioridade, origem, origem_id)
                 VALUES (?,?,?,?,?,?,?)',
                [$uid, mb_substr((string) $s['titulo'], 0, 200),
                 'Solicitação de ' . $s['remetente_nome'] . ($s['mensagem'] ? "\n\n" . $s['mensagem'] : ''),
                 $s['prazo'], $s['prioridade'], 'solicitacao', $id]
            );
            $tarefaId = DB::lastId();
        }
        DB::execute(
            'UPDATE meu_solicitacoes SET situacao = ?, resposta = ?, respondida_em = ?, tarefa_id = ?
              WHERE id = ? AND destinatario_id = ?',
            [$nova, $resposta ?: null, date('Y-m-d H:i:s'), $tarefaId, $id, $uid]
        );
        // Ação sobre registro de OUTRA pessoa vai para a auditoria — o resto
        // do módulo não audita nada, e é coerente: lá o dado é só meu.
        Audit::log('meu.solicitacao.responder', 'meu_solicitacoes', (string) $id,
            ['situacao' => $nova, 'remetente' => (int) $s['remetente_id']]);
        Notifications::add((int) $s['remetente_id'],
            'Solicitação ' . meu_situacoes()[$nova][0] . ': ' . mb_substr((string) $s['titulo'], 0, 120),
            $resposta ?: null,
            core_module_url('meu', ['page' => 'solicitacoes', 'aba' => 'enviadas', 'ver' => $id]), 'solicitacao', 'meu');
        Flash::set('success', 'Resposta registrada.');
        core_redirect($url(['ver' => $id]));
    }

    if ($op === 'cancelar' && $souRemetente && $s['situacao'] === 'pendente') {
        core_require('solicitacoes.enviar');
        DB::execute("UPDATE meu_solicitacoes SET situacao = 'cancelada' WHERE id = ? AND remetente_id = ?", [$id, $uid]);
        Audit::log('meu.solicitacao.cancelar', 'meu_solicitacoes', (string) $id,
            ['destinatario' => (int) $s['destinatario_id']]);
        Flash::set('success', 'Solicitação cancelada.');
        core_redirect($url(['aba' => 'enviadas']));
    }

    core_redirect($url());
}

$aba   = ($_GET['aba'] ?? 'recebidas') === 'enviadas' ? 'enviadas' : 'recebidas';
$todas = !empty($_GET['todas']);
$lista = meu_solicitacoes($aba, $todas);
$ver   = isset($_GET['ver']) ? meu_solicitacao((int) $_GET['ver']) : null;
$nova  = isset($_GET['nova']) && core_can('solicitacoes.enviar');
$pessoas = $nova ? meu_pessoas((string) ($_GET['q'] ?? '')) : [];
$situacoes = meu_situacoes();

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-inbox me-2"></i>Solicitações</h1>
    <?php if (core_can('solicitacoes.enviar')): ?>
        <a class="btn btn-primary btn-sm" href="<?= $url(['nova' => 1]) ?>">
            <i class="bi bi-send me-1"></i>Pedir algo a alguém
        </a>
    <?php endif; ?>
</div>

<?php if ($nova): ?>
<div class="card mb-3">
    <div class="card-header">Nova solicitação</div>
    <div class="card-body">
        <form method="get" class="mb-3">
            <input type="hidden" name="m" value="meu">
            <input type="hidden" name="page" value="solicitacoes">
            <input type="hidden" name="nova" value="1">
            <div class="input-group input-group-sm" style="max-width:420px">
                <input class="form-control" name="q" value="<?= core_e((string) ($_GET['q'] ?? '')) ?>"
                       placeholder="Buscar pessoa por nome ou setor">
                <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
            </div>
        </form>
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="op" value="enviar">
            <div class="row g-3">
                <div class="col-12 col-md-5">
                    <label class="form-label small fw-semibold" for="s_para">Para quem</label>
                    <select class="form-select" id="s_para" name="destinatario_id" required size="6">
                        <?php foreach ($pessoas as $p): ?>
                            <option value="<?= (int) $p['id'] ?>">
                                <?= core_e($p['name']) ?><?= $p['sector'] ? ' — ' . core_e($p['sector']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$pessoas): ?>
                        <div class="form-text small">Nenhuma pessoa encontrada com esse termo.</div>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-7">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="s_titulo">O que você precisa</label>
                        <input class="form-control" id="s_titulo" name="titulo" maxlength="200" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold" for="s_msg">Detalhe (opcional)</label>
                        <textarea class="form-control form-control-sm" id="s_msg" name="mensagem" rows="3"></textarea>
                    </div>
                    <div class="row g-2">
                        <div class="col-7">
                            <label class="form-label small fw-semibold" for="s_prazo">Para quando</label>
                            <input type="date" class="form-control form-control-sm" id="s_prazo" name="prazo">
                        </div>
                        <div class="col-5">
                            <label class="form-label small fw-semibold" for="s_prio">Prioridade</label>
                            <select class="form-select form-select-sm" id="s_prio" name="prioridade">
                                <option value="baixa">Baixa</option>
                                <option value="normal" selected>Normal</option>
                                <option value="alta">Alta</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <button class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Enviar</button>
                <a class="btn btn-link btn-sm" href="<?= $url() ?>">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($ver): ?>
<?php $st = $situacoes[$ver['situacao']] ?? ['?', 'text-bg-light']; ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-file-text me-2"></i><?= core_e($ver['titulo']) ?></span>
        <span class="badge <?= $st[1] ?>"><?= $st[0] ?></span>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            De <strong><?= core_e($ver['remetente_nome']) ?></strong>
            para <strong><?= core_e($ver['destinatario_nome']) ?></strong>
            · <?= date('d/m/Y H:i', (int) strtotime($ver['created_at'])) ?>
            <?php if ($ver['prazo']): ?> · para <?= date('d/m/Y', (int) strtotime($ver['prazo'])) ?><?php endif; ?>
            <?php if ($ver['formulario_titulo']): ?> · formulário "<?= core_e($ver['formulario_titulo']) ?>"<?php endif; ?>
        </p>
        <?php if ($ver['mensagem']): ?><p><?= nl2br(core_e($ver['mensagem'])) ?></p><?php endif; ?>

        <?php $resp = DB::query(
                'SELECT c.rotulo, c.tipo, r.resposta FROM meu_formulario_respostas r
                   JOIN meu_formulario_campos c ON c.id = r.campo_id
                  WHERE r.solicitacao_id = ? ORDER BY c.ordem, c.id', [(int) $ver['id']]); ?>
        <?php if ($resp): ?>
            <dl class="row small mb-0">
                <?php foreach ($resp as $r): ?>
                    <dt class="col-sm-4 text-muted fw-normal"><?= core_e($r['rotulo']) ?></dt>
                    <dd class="col-sm-8"><?= nl2br(core_e((string) $r['resposta'])) ?></dd>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>

        <?php if ($ver['resposta']): ?>
            <div class="alert alert-light border mt-3 mb-0">
                <div class="small text-muted mb-1">Resposta de <?= core_e($ver['destinatario_nome']) ?>
                    · <?= $ver['respondida_em'] ? date('d/m/Y H:i', (int) strtotime($ver['respondida_em'])) : '' ?></div>
                <?= nl2br(core_e($ver['resposta'])) ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ((int) $ver['destinatario_id'] === $uid && in_array($ver['situacao'], ['pendente', 'aceita'], true)
              && core_can('solicitacoes.responder')): ?>
    <div class="card-footer bg-transparent">
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="op" value="responder">
            <input type="hidden" name="id" value="<?= (int) $ver['id'] ?>">
            <div class="mb-2">
                <label class="form-label small fw-semibold" for="s_resp">Resposta (opcional)</label>
                <textarea class="form-control form-control-sm" id="s_resp" name="resposta" rows="2"></textarea>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($ver['situacao'] === 'pendente'): ?>
                    <button class="btn btn-success btn-sm" name="situacao" value="aceita">
                        <i class="bi bi-check2 me-1"></i>Aceitar (vira tarefa minha)
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" name="situacao" value="recusada">
                        <i class="bi bi-x me-1"></i>Recusar
                    </button>
                <?php endif; ?>
                <button class="btn btn-primary btn-sm" name="situacao" value="concluida">
                    <i class="bi bi-check2-all me-1"></i>Concluir
                </button>
            </div>
        </form>
    </div>
    <?php elseif ((int) $ver['remetente_id'] === $uid && $ver['situacao'] === 'pendente' && core_can('solicitacoes.enviar')): ?>
    <div class="card-footer bg-transparent">
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="op" value="cancelar">
            <input type="hidden" name="id" value="<?= (int) $ver['id'] ?>">
            <button class="btn btn-outline-danger btn-sm" data-confirm="Cancelar esta solicitação?">
                <i class="bi bi-x-circle me-1"></i>Cancelar solicitação
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $aba === 'recebidas' ? 'active' : '' ?>"
       href="<?= $url(['aba' => 'recebidas'] + ($todas ? ['todas' => 1] : [])) ?>">
        Recebidas
        <?php $p = meu_solicitacoes_pendentes(); if ($p): ?><span class="badge text-bg-warning ms-1"><?= $p ?></span><?php endif; ?>
    </a></li>
    <li class="nav-item"><a class="nav-link <?= $aba === 'enviadas' ? 'active' : '' ?>"
       href="<?= $url(['aba' => 'enviadas'] + ($todas ? ['todas' => 1] : [])) ?>">Enviadas</a></li>
    <li class="nav-item ms-auto"><a class="nav-link"
       href="<?= $url(['aba' => $aba] + ($todas ? [] : ['todas' => 1])) ?>">
        <?= $todas ? 'Só as abertas' : 'Mostrar encerradas' ?></a></li>
</ul>

<div class="card">
    <?php if (!$lista): ?>
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>
            <?= $aba === 'recebidas' ? 'Ninguém pediu nada a você.' : 'Você não pediu nada a ninguém.' ?>
        </div>
    <?php else: ?>
    <ul class="list-group list-group-flush">
        <?php foreach ($lista as $s):
            $st = $situacoes[$s['situacao']] ?? ['?', 'text-bg-light'];
            $estado = meu_prazo_estado($s['prazo']); ?>
            <li class="list-group-item d-flex align-items-start gap-2">
                <div class="flex-grow-1 min-w-0">
                    <a class="fw-semibold text-decoration-none" href="<?= $url(['aba' => $aba, 'ver' => (int) $s['id']]) ?>">
                        <?php if ($s['prioridade'] === 'alta'): ?><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i><?php endif; ?>
                        <?= core_e($s['titulo']) ?>
                    </a>
                    <div class="small text-muted">
                        <?= $aba === 'recebidas' ? 'de' : 'para' ?> <?= core_e($s['contraparte']) ?>
                        · <?= date('d/m/Y', (int) strtotime($s['created_at'])) ?>
                        <?php if ($s['prazo']): ?>
                            · <span class="<?= $estado === 'vencida' ? 'text-danger fw-semibold' : ($estado === 'hoje' ? 'text-warning' : '') ?>">
                                prazo <?= date('d/m', (int) strtotime($s['prazo'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="badge <?= $st[1] ?> flex-shrink-0"><?= $st[0] ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php
Layout::render(['title' => 'Solicitações', 'content' => (string) ob_get_clean(), 'active' => 'solicitacoes']);
