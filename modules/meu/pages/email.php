<?php
/**
 * MEU ESPAÇO — caixa de e-mail pessoal (IMAP).
 *
 * A SENHA NÃO É GUARDADA. É digitada uma vez por sessão e some com ela.
 * A tela diz isso em voz alta, porque um campo de senha que some sem
 * explicação parece defeito — e porque a pessoa precisa entender que o
 * hospital NÃO está de posse da chave da caixa dela.
 */

declare(strict_types=1);

use Core\Csrf;
use Core\DB;
use Core\Flash;
use Core\HtmlSanitizer;
use Core\Layout;
use Core\Mailer;

core_require('email.view');

$uid   = meu_uid();
$eu    = Core\Auth::user();
$url   = fn (array $q = []) => core_module_url('meu', ['page' => 'email'] + $q);
$conta = meu_email_conta();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();
    $op = (string) ($_POST['op'] ?? '');

    if ($op === 'salvar') {
        core_require('email.manage');
        $usuario = trim((string) ($_POST['usuario'] ?? ''));
        if (!filter_var($usuario, FILTER_VALIDATE_EMAIL)) {
            Flash::set('error', 'Informe o endereço de e-mail completo.');
            core_redirect($url(['config' => 1]));
        }
        $host = trim((string) ($_POST['host'] ?? '')) ?: 'imap.zoho.com';
        $seg  = in_array($_POST['seguranca'] ?? '', ['ssl', 'starttls', 'nenhuma'], true)
              ? (string) $_POST['seguranca'] : 'ssl';
        $porta = (int) ($_POST['porta'] ?? 0) ?: ($seg === 'ssl' ? 993 : 143);
        $smtpSeg = in_array($_POST['smtp_seg'] ?? '', ['ssl', 'tls', 'nenhuma'], true)
                 ? (string) $_POST['smtp_seg'] : 'ssl';

        DB::execute(
            'INSERT INTO meu_email_contas (user_id, host, porta, seguranca, usuario, caixa,
                                           smtp_host, smtp_porta, smtp_seg, ativo)
             VALUES (?,?,?,?,?,?,?,?,?,1)
             ON DUPLICATE KEY UPDATE host = VALUES(host), porta = VALUES(porta),
                 seguranca = VALUES(seguranca), usuario = VALUES(usuario), caixa = VALUES(caixa),
                 smtp_host = VALUES(smtp_host), smtp_porta = VALUES(smtp_porta),
                 smtp_seg = VALUES(smtp_seg), ativo = 1',
            [$uid, mb_substr($host, 0, 190), max(1, min(65535, $porta)), $seg,
             mb_substr($usuario, 0, 190), mb_substr(trim((string) ($_POST['caixa'] ?? 'INBOX')) ?: 'INBOX', 0, 190),
             mb_substr(trim((string) ($_POST['smtp_host'] ?? '')) ?: 'smtp.zoho.com', 0, 190),
             max(1, min(65535, (int) ($_POST['smtp_porta'] ?? 465))), $smtpSeg]
        );
        Flash::set('success', 'Caixa configurada. Agora informe a senha de aplicativo para abrir.');
        core_redirect($url());
    }

    if ($op === 'destrancar') {
        // Campo desabilitado na tela não protege nada: um POST direto passa.
        // A recusa que vale é esta.
        if (!Core\Https::requestIsSecure() && !Core\Https::baseUrlIsLocal()) {
            Flash::set('error', 'A senha não foi aceita: esta requisição chegou por http, e a senha '
                . 'trafegaria em texto claro. Acesse o portal por https.');
            core_redirect($url());
        }
        $senha = (string) ($_POST['senha'] ?? '');
        if ($senha === '' || $conta === null) {
            Flash::set('error', 'Informe a senha de aplicativo.');
            core_redirect($url());
        }
        // Valida antes de guardar na sessão: guardar uma senha errada faria
        // a caixa falhar em toda tela seguinte sem dizer por quê.
        meu_email_destrancar($senha);
        try {
            $c = meu_email_conectar($conta);
            $c->selecionar((string) $conta['caixa']);
            $c->fechar();
            DB::execute('UPDATE meu_email_contas SET ultimo_ok = ? WHERE user_id = ?',
                [date('Y-m-d H:i:s'), $uid]);
            // Abrir uma caixa de e-mail é evento que precisa de rastro: é o
            // momento em que credencial de e-mail entra no sistema.
            Core\Audit::log('meu.email.abrir', 'meu_email_contas', (string) $uid,
                ['conta' => (string) $conta['usuario'], 'host' => (string) $conta['host']], $uid, 'meu');
            Flash::set('success', 'Caixa aberta. A senha vale até você sair do sistema.');
        } catch (\Throwable $e) {
            meu_email_trancar();
            Flash::set('error', 'Não consegui abrir a caixa: ' . $e->getMessage());
        }
        core_redirect($url());
    }

    if ($op === 'trancar') {
        meu_email_trancar();
        Flash::set('success', 'Senha esquecida nesta sessão.');
        core_redirect($url());
    }

    if ($op === 'responder') {
        core_require('email.enviar');
        $uidMsg = (int) ($_POST['uid'] ?? 0);
        $para   = trim((string) ($_POST['para'] ?? ''));
        $assunto = trim((string) ($_POST['assunto'] ?? ''));
        $corpo  = trim((string) ($_POST['corpo'] ?? ''));
        if ($conta === null || meu_email_senha() === '') {
            Flash::set('error', 'A caixa está trancada.');
            core_redirect($url());
        }
        if (!filter_var($para, FILTER_VALIDATE_EMAIL) || $corpo === '') {
            Flash::set('error', 'Confira o destinatário e escreva a resposta.');
            core_redirect($url(['ver' => $uidMsg]));
        }
        // Envia COM AS CREDENCIAIS DO USUÁRIO, não com o SMTP do portal: a
        // resposta precisa sair do endereço dele, e não do robô do sistema.
        $r = Mailer::sendDetailed($para, $assunto, nl2br(core_e($corpo)), [
            'raw'      => true,
            'fallback' => false,
            'password' => meu_email_senha(),
            'config'   => [
                'enabled'    => true,
                'host'       => (string) $conta['smtp_host'],
                'port'       => (int) $conta['smtp_porta'],
                'encryption' => (string) $conta['smtp_seg'],
                'user'       => (string) $conta['usuario'],
                'from'       => (string) $conta['usuario'],
                'from_name'  => (string) $eu['name'],
            ],
        ]);
        // Enviar e-mail COMO o usuário é a ação de maior consequência desta
        // tela: sai da caixa dele, com o nome dele, para fora do hospital.
        Core\Audit::log('meu.email.responder', 'meu_email_contas', (string) $uid,
            ['de' => (string) $conta['usuario'], 'para' => $para, 'ok' => !empty($r['ok'])], $uid, 'meu');

        if (!empty($r['ok'])) {
            Flash::set('success', 'Resposta enviada para ' . $para . '.');
            core_redirect($url());
        }
        // A senha da sessão NUNCA pode aparecer aqui: o diagnóstico do Mailer
        // ecoa o diálogo SMTP, e MailConfig::redact() só conhece a senha
        // global do portal — a do usuário passaria inteira para a tela.
        $msg = (string) ($r['error'] ?: 'falha no servidor de envio.');
        $msg = str_replace(meu_email_senha(), '(senha omitida)', $msg);
        Flash::set('error', 'Não consegui enviar: ' . $msg);
        core_redirect($url(['ver' => $uidMsg]));
    }
    core_redirect($url());
}

// ── Leitura ────────────────────────────────────────────────────────────────
$configurar = isset($_GET['config']) || $conta === null;
$destrancada = $conta !== null && meu_email_senha() !== '';
$mensagens = [];
$vendo     = null;
$erro      = '';

if ($destrancada && !$configurar) {
    try {
        $c = meu_email_conectar($conta);
        $total = $c->selecionar((string) $conta['caixa']);
        $mensagens = $c->listar($total, 25);
        if (isset($_GET['ver'])) {
            $vendo = $c->mensagem((int) $_GET['ver'], true);
        }
        $c->fechar();
    } catch (\Throwable $e) {
        // Mesma razão do envio: a mensagem do servidor pode devolver o que
        // foi enviado, e o LOGIN levou a senha.
        $erro = str_replace(meu_email_senha(), '(senha omitida)', $e->getMessage());
        // Senha que deixou de valer (trocada no Zoho, por exemplo): tranca de
        // novo para a pessoa poder digitar a nova em vez de ver erro sempre.
        if (stripos($erro, 'recusad') !== false) {
            meu_email_trancar();
            $destrancada = false;
        }
    }
}

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-envelope me-2"></i>Minha caixa de e-mail</h1>
    <div class="d-flex gap-2">
        <?php if ($conta && !$configurar): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= $url(['config' => 1]) ?>">
                <i class="bi bi-gear me-1"></i>Configuração
            </a>
        <?php endif; ?>
        <?php if ($destrancada): ?>
            <form method="post" class="d-inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="op" value="trancar">
                <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-lock me-1"></i>Trancar</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($erro !== ''): ?>
    <div class="alert alert-danger"><?= core_e($erro) ?></div>
<?php endif; ?>

<?php if ($configurar): ?>
<div class="card mb-3">
    <div class="card-header">Configuração da caixa</div>
    <div class="card-body">
        <div class="alert alert-info small">
            <strong>A senha não fica guardada.</strong> Você a digita uma vez por sessão e ela some
            quando você sai. O hospital não guarda a chave da sua caixa: nem no banco, nem no backup.
            No Zoho, gere uma <strong>senha de aplicativo</strong> (Minha Conta › Segurança) —
            a senha normal é recusada quando há verificação em duas etapas.
        </div>
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="op" value="salvar">
            <div class="row g-2">
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold" for="e_user">Seu endereço de e-mail</label>
                    <input class="form-control" id="e_user" name="usuario" type="email" required
                           value="<?= core_e($conta['usuario'] ?? ($eu['email'] ?? '')) ?>">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label small fw-semibold" for="e_caixa">Pasta</label>
                    <input class="form-control" id="e_caixa" name="caixa" value="<?= core_e($conta['caixa'] ?? 'INBOX') ?>">
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold" for="e_host">Servidor de leitura (IMAP)</label>
                    <input class="form-control form-control-sm" id="e_host" name="host" value="<?= core_e($conta['host'] ?? 'imap.zoho.com') ?>">
                </div>
                <div class="col-3 col-md-2">
                    <label class="form-label small fw-semibold" for="e_porta">Porta</label>
                    <input class="form-control form-control-sm" id="e_porta" name="porta" type="number" value="<?= (int) ($conta['porta'] ?? 993) ?>">
                </div>
                <div class="col-3 col-md-2">
                    <label class="form-label small fw-semibold" for="e_seg">Cripto</label>
                    <select class="form-select form-select-sm" id="e_seg" name="seguranca">
                        <?php foreach (['ssl' => 'SSL/TLS', 'starttls' => 'STARTTLS', 'nenhuma' => 'Nenhuma'] as $k => $l): ?>
                            <option value="<?= $k ?>" <?= ($conta['seguranca'] ?? 'ssl') === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label small fw-semibold" for="e_shost">Servidor de envio (SMTP)</label>
                    <input class="form-control form-control-sm" id="e_shost" name="smtp_host" value="<?= core_e($conta['smtp_host'] ?? 'smtp.zoho.com') ?>">
                </div>
                <div class="col-3 col-md-2">
                    <label class="form-label small fw-semibold" for="e_sporta">Porta</label>
                    <input class="form-control form-control-sm" id="e_sporta" name="smtp_porta" type="number" value="<?= (int) ($conta['smtp_porta'] ?? 465) ?>">
                </div>
                <div class="col-3 col-md-2">
                    <label class="form-label small fw-semibold" for="e_sseg">Cripto</label>
                    <select class="form-select form-select-sm" id="e_sseg" name="smtp_seg">
                        <?php foreach (['ssl' => 'SSL', 'tls' => 'TLS', 'nenhuma' => 'Nenhuma'] as $k => $l): ?>
                            <option value="<?= $k ?>" <?= ($conta['smtp_seg'] ?? 'ssl') === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button class="btn btn-primary btn-sm mt-3">Salvar</button>
            <?php if ($conta): ?><a class="btn btn-link btn-sm mt-3" href="<?= $url() ?>">Voltar</a><?php endif; ?>
        </form>
    </div>
</div>

<?php elseif (!$destrancada): ?>
<?php // Mesma exceção que Https::enforce() já faz: em instalação local não
      // existe rede no caminho para interceptar, e bloquear ali seria travar
      // o desenvolvimento por causa de uma regra de produção.
      $seguro = Core\Https::requestIsSecure() || Core\Https::baseUrlIsLocal(); ?>
<div class="card mb-3" style="max-width:520px">
    <div class="card-header"><i class="bi bi-lock me-2"></i>Abrir a caixa</div>
    <div class="card-body">
        <?php if (!$seguro): ?>
            <div class="alert alert-danger">
                <strong>Esta página chegou por http.</strong> Digitar aqui mandaria a senha da sua
                caixa de e-mail em texto claro pela rede — quem estiver no caminho consegue ler.
                Acesse o portal por <code>https://</code> e volte.
            </div>
        <?php endif; ?>
        <p class="small text-muted">
            <?= core_e((string) $conta['usuario']) ?> · <?= core_e((string) $conta['host']) ?>
        </p>
        <form method="post">
            <?= Csrf::field() ?>
            <input type="hidden" name="op" value="destrancar">
            <div class="mb-3">
                <label class="form-label small fw-semibold" for="e_senha">Senha de aplicativo</label>
                <input type="password" class="form-control" id="e_senha" name="senha" required autofocus
                       autocomplete="off" <?= $seguro ? '' : 'disabled' ?>>
                <div class="form-text small">
                    Ela <strong>não é guardada</strong>: vale só nesta sessão e some quando você sair.
                </div>
            </div>
            <button class="btn btn-primary" <?= $seguro ? '' : 'disabled' ?>>
                <i class="bi bi-unlock me-1"></i>Abrir
            </button>
        </form>
    </div>
</div>

<?php else: ?>
<div class="row g-3">
    <div class="col-12 <?= $vendo ? 'col-lg-5' : '' ?>">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><?= core_e((string) $conta['caixa']) ?></span>
                <span class="badge text-bg-light border"><?= count($mensagens) ?></span>
            </div>
            <?php if (!$mensagens): ?>
                <div class="card-body text-center text-muted py-4">Nenhuma mensagem.</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($mensagens as $m): ?>
                    <li class="list-group-item <?= $vendo && (int) $vendo['uid'] === (int) $m['uid'] ? 'active' : '' ?>">
                        <a class="text-decoration-none d-block <?= $vendo && (int) $vendo['uid'] === (int) $m['uid'] ? 'text-white' : '' ?>"
                           href="<?= $url(['ver' => (int) $m['uid']]) ?>">
                            <div class="d-flex justify-content-between gap-2">
                                <span class="<?= $m['lida'] ? '' : 'fw-bold' ?> text-truncate">
                                    <?= core_e($m['de']['nome'] ?: $m['de']['email']) ?>
                                </span>
                                <small class="flex-shrink-0"><?= $m['data'] ? date('d/m H:i', (int) strtotime($m['data'])) : '' ?></small>
                            </div>
                            <div class="small text-truncate <?= $m['lida'] ? 'text-muted' : 'fw-semibold' ?>">
                                <?= core_e($m['assunto']) ?>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($vendo): ?>
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header">
                <div class="fw-semibold"><?= core_e($vendo['assunto']) ?></div>
                <div class="small text-muted">
                    <?= core_e($vendo['de']['nome'] ?: $vendo['de']['email']) ?>
                    &lt;<?= core_e($vendo['de']['email']) ?>&gt;
                    · <?= $vendo['data'] ? date('d/m/Y H:i', (int) strtotime($vendo['data'])) : '' ?>
                </div>
            </div>
            <div class="card-body">
                <?php if ($vendo['html'] !== ''): ?>
                    <?php /* HTML de fora: passa pelo sanitizador dos comunicados. */ ?>
                    <div class="meu-email-corpo"><?= HtmlSanitizer::clean($vendo['html']) ?></div>
                <?php else: ?>
                    <div style="white-space:pre-wrap"><?= core_e($vendo['texto']) ?></div>
                <?php endif; ?>

                <?php if (!empty($vendo['truncada'])): ?>
                    <div class="alert alert-warning small mt-2 mb-0">
                        Esta mensagem é grande demais para abrir inteira aqui — o corpo foi cortado.
                        Veja pelo webmail.
                    </div>
                <?php endif; ?>

                <?php if ($vendo['anexos']): ?>
                    <hr>
                    <p class="small text-muted mb-1">Anexos (baixe pelo webmail):</p>
                    <ul class="small mb-0">
                        <?php foreach ($vendo['anexos'] as $a): ?>
                            <li><?= core_e($a['nome'] ?: 'sem nome') ?>
                                <span class="text-muted">(<?= Core\HealthCheck::bytes((int) $a['bytes']) ?>)</span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <?php if (core_can('email.enviar')): ?>
            <div class="card-footer bg-transparent">
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="op" value="responder">
                    <input type="hidden" name="uid" value="<?= (int) $vendo['uid'] ?>">
                    <div class="row g-2 mb-2">
                        <div class="col-12 col-md-5">
                            <input class="form-control form-control-sm" name="para" type="email" required
                                   value="<?= core_e($vendo['de']['email']) ?>" aria-label="Para">
                        </div>
                        <div class="col-12 col-md-7">
                            <input class="form-control form-control-sm" name="assunto" aria-label="Assunto"
                                   value="<?= core_e(meu_email_assunto_resposta($vendo['assunto'])) ?>">
                        </div>
                    </div>
                    <textarea class="form-control form-control-sm mb-2" name="corpo" rows="5"
                              aria-label="Resposta"><?= core_e(meu_email_citar($vendo)) ?></textarea>
                    <button class="btn btn-primary btn-sm"><i class="bi bi-reply me-1"></i>Responder</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php
Layout::render(['title' => 'E-mail', 'content' => (string) ob_get_clean(), 'active' => 'email']);
