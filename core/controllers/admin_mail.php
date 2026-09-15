<?php
/**
 * ADMINISTRAÇÃO — E-mail (configuração, layout, teste de entrega e de
 * recebimento, fila e diagnóstico).
 *
 * A configuração do SMTP saiu de "só editando config/config.php" e passou a
 * poder ser feita aqui (Core\MailConfig grava em settings e sobrepõe o
 * arquivo; a senha vai cifrada). O botão de teste usa exatamente o mesmo
 * Core\Mailer do envio real e mostra a conversa com o servidor — é o que
 * transforma "falha no envio" em uma instrução do que arrumar.
 *
 * Todas as funções começam por Auth::requireGlobalAdmin(): as ações também
 * estão em $coreActions (core/controllers/admin.php), e a checagem repetida
 * aqui é de propósito — se alguém reordenar o switch ou esquecer uma ação na
 * lista, a tela continua fechada.
 */

declare(strict_types=1);

use Core\Audit;
use Core\Auth;
use Core\Csrf;
use Core\Flash;
use Core\MailConfig;
use Core\MailDiagnostics;
use Core\MailInbox;
use Core\MailQueue;
use Core\MailTemplate;
use Core\MailSecret;
use Core\Mailer;
use Core\MailTester;
use Core\Settings;

/** Abas da tela. */
function core_mail_tabs(): array
{
    return [
        'config' => ['label' => 'Configuração', 'icon' => 'bi-sliders'],
        'layout' => ['label' => 'Layout', 'icon' => 'bi-palette'],
        'test'   => ['label' => 'Teste de entrega', 'icon' => 'bi-send-check'],
        'inbox'  => ['label' => 'Recebimento', 'icon' => 'bi-inbox'],
        'queue'  => ['label' => 'Fila', 'icon' => 'bi-list-ol'],
        'diag'   => ['label' => 'Diagnóstico', 'icon' => 'bi-activity'],
        'log'    => ['label' => 'Histórico de testes', 'icon' => 'bi-clock-history'],
    ];
}

function core_mail_tab(): string
{
    $t = (string) ($_GET['tab'] ?? 'config');
    return array_key_exists($t, core_mail_tabs()) ? $t : 'config';
}

/** Selo colorido de um item de diagnóstico. */
function core_mail_badge(string $nivel): string
{
    return match ($nivel) {
        'ok'    => '<span class="badge text-bg-success">ok</span>',
        'aviso' => '<span class="badge text-bg-warning">atenção</span>',
        'erro'  => '<span class="badge text-bg-danger">problema</span>',
        default => '<span class="badge text-bg-secondary">info</span>',
    };
}

/** @param array<int,array{nivel:string,titulo:string,detalhe:string}> $itens */
function core_mail_checklist(array $itens): string
{
    if ($itens === []) {
        return '<p class="text-muted mb-0">Nada a mostrar.</p>';
    }
    $html = '<ul class="list-group list-group-flush">';
    foreach ($itens as $i) {
        $html .= '<li class="list-group-item d-flex gap-3 align-items-start">'
               . '<div style="min-width:82px">' . core_mail_badge($i['nivel']) . '</div>'
               . '<div><div class="fw-semibold">' . core_e($i['titulo']) . '</div>'
               . '<div class="small text-muted">' . core_e($i['detalhe']) . '</div></div></li>';
    }
    return $html . '</ul>';
}

// ---------------------------------------------------------------- GRAVAÇÃO

function core_admin_mail_save(): void
{
    Auth::requireGlobalAdmin();
    Csrf::check();

    if (MailConfig::isLocked()) {
        Flash::set('danger', 'A configuração de e-mail está travada em config/config.php (mail.lock).');
        core_redirect('index.php?m=admin&a=mail');
    }

    if (($_POST['op'] ?? '') === 'reset') {
        MailConfig::reset();
        Audit::log('mail.config.reset', 'settings', null, 'Configuração de e-mail devolvida ao arquivo');
        Flash::set('success', 'Configuração devolvida ao que está em config/config.php.');
        core_redirect('index.php?m=admin&a=mail');
    }

    // Checkbox desmarcado não é enviado pelo navegador: o formulário manda um
    // campo escondido antes de cada um, por isso a chave sempre chega.
    $values = [];
    foreach (array_keys(MailConfig::FIELDS) as $key) {
        if (array_key_exists($key, $_POST)) {
            $values[$key] = (string) $_POST[$key];
        }
    }

    // Senha: em branco = não mexer; marcar "limpar" = apagar.
    $password = null;
    if (!empty($_POST['clear_pass'])) {
        $password = '';
    } elseif (($_POST['pass'] ?? '') !== '') {
        $password = (string) $_POST['pass'];
    }

    try {
        MailConfig::save($values, $password);
    } catch (\Throwable $e) {
        Flash::set('danger', 'Não foi possível salvar: ' . $e->getMessage());
        core_redirect('index.php?m=admin&a=mail');
    }

    Audit::log('mail.config.save', 'settings', null,
        'Configuração de e-mail atualizada (servidor: ' . (MailConfig::host() ?: 'sem SMTP') . ')');
    Flash::set('success', 'Configuração de e-mail salva.'
        . ($password !== null ? ' A senha foi ' . ($password === '' ? 'apagada.' : 'guardada cifrada.') : ''));
    core_redirect('index.php?m=admin&a=mail');
}

/** Dispara o teste de entrega. */
function core_admin_mail_test(): void
{
    Auth::requireGlobalAdmin();
    Csrf::check();

    $to   = (string) ($_POST['to'] ?? '');
    $path = (string) ($_POST['path'] ?? 'smtp');

    $r = MailTester::run($to, $path);
    Audit::log('mail.test', 'mail_tests', $r['id'] ? (string) $r['id'] : null,
        'Teste de e-mail para ' . MailConfig::clean($to) . ' — ' . ($r['ok'] ? 'aceito' : 'falhou'));

    if ($r['id'] > 0) {
        core_redirect('index.php?m=admin&a=mail&tab=log&id=' . $r['id']);
    }
    Flash::set('danger', $r['error'] ?: 'Não foi possível executar o teste.');
    core_redirect('index.php?m=admin&a=mail&tab=test');
}

/** Verificações que saem para a rede (DNS, portas) — só sob clique. */
function core_admin_mail_probe(): void
{
    Auth::requireGlobalAdmin();
    Csrf::check();

    $what = (string) ($_POST['what'] ?? 'dns');
    try {
        $out = $what === 'ports'
            ? MailDiagnostics::ports(MailConfig::host())
            : MailDiagnostics::dns();
    } catch (\Throwable $e) {
        // Segunda camada: nenhuma checagem de ambiente pode derrubar a tela.
        $out = [['nivel' => 'erro', 'titulo' => 'A verificação não pôde ser feita',
                 'detalhe' => $e->getMessage()]];
    }

    // O resultado vive só até a próxima tela: é uma foto do momento.
    $_SESSION['mail_probe'] = ['what' => $what, 'itens' => $out, 'quando' => date('d/m/Y H:i:s')];
    core_redirect('index.php?m=admin&a=mail&tab=diag');
}

// ------------------------------------------------------------------- TELA

function core_admin_mail(): string
{
    Auth::requireGlobalAdmin();

    $tab    = core_mail_tab();
    $cfg    = MailConfig::all();
    $locked = MailConfig::isLocked();

    ob_start(); ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-envelope-at me-2"></i>E-mail</h1>
        <span class="ms-auto small text-muted">
            <?= $cfg['enabled'] === '1'
                ? '<span class="badge text-bg-success">envio ligado</span>'
                : '<span class="badge text-bg-secondary">envio desligado</span>' ?>
            <?php if ($cfg['host'] !== ''): ?>
                · SMTP <?= core_e($cfg['host'] . ':' . $cfg['port']) ?>
            <?php else: ?>
                · sem servidor SMTP (função mail())
            <?php endif; ?>
        </span>
    </div>

    <?php if ($locked): ?>
        <div class="alert alert-info d-flex gap-2">
            <i class="bi bi-lock"></i>
            <div>A configuração está travada em <code>config/config.php</code> (<code>mail.lock</code>).
            Os valores abaixo são os do arquivo e a gravação está desabilitada — o teste de entrega
            continua funcionando.</div>
        </div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-3">
        <?php foreach (core_mail_tabs() as $key => $t): ?>
            <li class="nav-item">
                <a class="nav-link <?= $tab === $key ? 'active' : '' ?>"
                   href="<?= core_module_url('admin', ['a' => 'mail', 'tab' => $key]) ?>">
                    <i class="bi <?= core_e($t['icon']) ?> me-1"></i><?= core_e($t['label']) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?= match ($tab) {
        'layout' => core_admin_mail_tab_layout(),
        'test'   => core_admin_mail_tab_test(),
        'inbox'  => core_admin_mail_tab_inbox(),
        'queue'  => core_admin_mail_tab_queue(),
        'diag'   => core_admin_mail_tab_diag(),
        'log'    => core_admin_mail_tab_log(),
        default  => core_admin_mail_tab_config($cfg, $locked),
    } ?>
    <?php
    return (string) ob_get_clean();
}

/** Aba Configuração. */
function core_admin_mail_tab_config(array $cfg, bool $locked): string
{
    $origem = static function (string $key): string {
        return match (MailConfig::source($key)) {
            'settings' => '<span class="badge text-bg-light border" title="Definido aqui na tela">tela</span>',
            'config'   => '<span class="badge text-bg-light border" title="Vem de config/config.php">arquivo</span>',
            default    => '<span class="badge text-bg-light border" title="Valor padrão">padrão</span>',
        };
    };
    $dis = $locked ? 'disabled' : '';

    ob_start(); ?>
    <form method="post" action="<?= core_module_url('admin', ['a' => 'mail_save']) ?>" class="row g-3">
        <?= Csrf::field() ?>
        <div class="col-12 col-xl-8">
            <div class="card mb-3">
                <div class="card-header">Servidor de saída (SMTP)</div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input type="hidden" name="enabled" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" id="mailEnabled"
                               name="enabled" value="1" <?= $cfg['enabled'] === '1' ? 'checked' : '' ?> <?= $dis ?>>
                        <label class="form-check-label" for="mailEnabled">
                            <strong>Enviar e-mails</strong> <?= $origem('enabled') ?>
                        </label>
                        <div class="form-text">Desligado, nada sai do sistema — comunicados e avisos
                        ficam esperando na fila.</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-7">
                            <label class="form-label">Servidor <?= $origem('host') ?></label>
                            <input class="form-control" name="host" value="<?= core_e($cfg['host']) ?>"
                                   placeholder="smtp.seudominio.com.br" <?= $dis ?>>
                            <div class="form-text">Em branco: o sistema usa a função mail() do PHP
                            (entrega pelo sendmail da hospedagem).</div>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label">Porta <?= $origem('port') ?></label>
                            <input class="form-control" name="port" type="number" min="1" max="65535"
                                   value="<?= core_e($cfg['port']) ?>" <?= $dis ?>>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label">Segurança <?= $origem('encryption') ?></label>
                            <select class="form-select" name="encryption" <?= $dis ?>>
                                <?php foreach (MailConfig::ENCRYPTIONS as $v => $label): ?>
                                    <option value="<?= core_e($v) ?>" <?= $cfg['encryption'] === $v ? 'selected' : '' ?>>
                                        <?= core_e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label">Usuário <?= $origem('user') ?></label>
                            <input class="form-control" name="user" value="<?= core_e($cfg['user']) ?>"
                                   autocomplete="off" <?= $dis ?>>
                            <div class="form-text">Em branco quando o servidor interno não exige autenticação.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Senha</label>
                            <input class="form-control" name="pass" type="password" autocomplete="new-password"
                                   placeholder="<?= MailConfig::passwordIsSet() ? '•••••••• (guardada)' : 'não definida' ?>" <?= $dis ?>>
                            <?php if (MailConfig::passwordIsSet() && !$locked): ?>
                                <div class="form-check small mt-1">
                                    <input class="form-check-input" type="checkbox" name="clear_pass" value="1" id="clearPass">
                                    <label class="form-check-label" for="clearPass">apagar a senha guardada</label>
                                </div>
                            <?php endif; ?>
                            <div class="form-text">
                                Deixe em branco para manter a atual.
                                <?php if (!MailSecret::hasStrongCrypto()): ?>
                                    <span class="text-warning-emphasis">Sem OpenSSL neste servidor: a senha fica
                                    apenas ofuscada no banco.</span>
                                <?php elseif (MailSecret::appKeyIsDefault()): ?>
                                    <span class="text-warning-emphasis">A chave do aplicativo (app.key) ainda é a
                                    de exemplo — troque-a em config/config.php.</span>
                                <?php else: ?>
                                    Guardada cifrada no banco.
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Identificação da mensagem</div>
                <div class="card-body row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label">Endereço "De" <?= $origem('from') ?></label>
                        <input class="form-control" name="from" type="email"
                               value="<?= core_e($cfg['from']) ?>" placeholder="nao-responda@seudominio.com.br" <?= $dis ?>>
                        <div class="form-text">Quase sempre precisa pertencer ao domínio da conta SMTP.</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Nome do remetente <?= $origem('from_name') ?></label>
                        <input class="form-control" name="from_name" value="<?= core_e($cfg['from_name']) ?>"
                               placeholder="<?= core_e(Core\Branding::name()) ?>" <?= $dis ?>>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Responder para <?= $origem('reply_to') ?></label>
                        <input class="form-control" name="reply_to" type="email"
                               value="<?= core_e($cfg['reply_to']) ?>" placeholder="opcional" <?= $dis ?>>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Nome na apresentação (EHLO) <?= $origem('ehlo') ?></label>
                        <input class="form-control" name="ehlo" value="<?= core_e($cfg['ehlo']) ?>"
                               placeholder="<?= core_e(MailConfig::ehlo()) ?>" <?= $dis ?>>
                        <div class="form-text">Só mexa se o servidor recusar a apresentação.</div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Comportamento</div>
                <div class="card-body row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label">Tempo limite (s) <?= $origem('timeout') ?></label>
                        <input class="form-control" name="timeout" type="number" min="3" max="60"
                               value="<?= core_e($cfg['timeout']) ?>" <?= $dis ?>>
                    </div>
                    <div class="col-12 col-md-8">
                        <label class="form-label">Se o SMTP falhar <?= $origem('fallback_mail') ?></label>
                        <select class="form-select" name="fallback_mail" <?= $dis ?>>
                            <option value="auto" <?= $cfg['fallback_mail'] === 'auto' ? 'selected' : '' ?>>
                                Automático — só usa mail() quando não há servidor configurado (recomendado)
                            </option>
                            <option value="0" <?= $cfg['fallback_mail'] === '0' ? 'selected' : '' ?>>
                                Nunca — a falha do SMTP aparece como falha
                            </option>
                            <option value="1" <?= $cfg['fallback_mail'] === '1' ? 'selected' : '' ?>>
                                Sempre tentar mail() depois de uma falha do SMTP
                            </option>
                        </select>
                        <div class="form-text">A opção "sempre" mascara problemas: a mensagem sai pelo
                        sendmail local e o sistema marca como enviada, mesmo com o SMTP quebrado.</div>
                    </div>
                </div>
            </div>

            <?php if (!$locked): ?>
                <div class="d-flex gap-2 mb-4">
                    <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Salvar</button>
                    <button class="btn btn-outline-secondary" name="op" value="reset"
                            onclick="return confirm('Devolver a configuração ao que está em config/config.php?')">
                        Devolver ao arquivo
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card">
                <div class="card-header">Como isto funciona</div>
                <div class="card-body small">
                    <p>O que você grava aqui <strong>sobrepõe</strong> o bloco <code>mail</code> de
                    <code>config/config.php</code>. O selo ao lado de cada campo mostra de onde vem o
                    valor em uso.</p>
                    <p class="mb-0">Para travar tudo no arquivo (quando a hospedagem gerencia o e-mail),
                    acrescente <code>'lock' =&gt; true</code> ao bloco <code>mail</code>.</p>
                </div>
            </div>
        </div>
    </form>
    <?php
    return (string) ob_get_clean();
}

/** Aba Teste de entrega. */
function core_admin_mail_tab_test(): string
{
    $user   = Auth::user();
    $sugere = (string) ($user['email'] ?? '');
    $restam = max(0, MailTester::LIMITE_HORA - MailTester::countLastHour());

    ob_start(); ?>
    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header">Enviar mensagem de teste</div>
                <div class="card-body">
                    <form method="post" action="<?= core_module_url('admin', ['a' => 'mail_test']) ?>">
                        <?= Csrf::field() ?>
                        <div class="mb-3">
                            <label class="form-label">Enviar para</label>
                            <input class="form-control" name="to" type="email" required
                                   value="<?= core_e($sugere) ?>" placeholder="seu.email@exemplo.com.br">
                            <div class="form-text">Use um endereço que você consiga abrir agora.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Caminho</label>
                            <?php foreach (MailTester::CAMINHOS as $key => $label): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="path"
                                           id="path_<?= core_e($key) ?>" value="<?= core_e($key) ?>"
                                           <?= $key === 'smtp' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="path_<?= core_e($key) ?>">
                                        <?= core_e($label) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button class="btn btn-primary" <?= $restam > 0 ? '' : 'disabled' ?>>
                            <i class="bi bi-send me-1"></i>Enviar teste
                        </button>
                        <span class="small text-muted ms-2"><?= $restam ?> de
                            <?= MailTester::LIMITE_HORA ?> testes restantes nesta hora</span>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header">O que o teste prova (e o que não prova)</div>
                <div class="card-body small">
                    <p><strong>Prova:</strong> que o servidor de saída aceitou a mensagem, e mostra em
                    qual etapa a conversa parou quando não aceita.</p>
                    <p><strong>Não prova:</strong></p>
                    <ul class="mb-2">
                        <?php foreach (MailDiagnostics::limits() as $l): ?>
                            <li><?= core_e($l) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="mb-0 text-muted">O assunto e o corpo são fixos, escritos pelo próprio
                    sistema — o formulário não envia texto livre para ninguém.</p>
                </div>
            </div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/** Aba Fila. */
function core_admin_mail_tab_queue(): string
{
    $stats = MailQueue::stats();
    $rows  = MailQueue::recent(60);

    ob_start(); ?>
    <div class="row g-3 mb-3">
        <?php foreach ([
            ['Pendentes', $stats['pending'], 'bi-hourglass-split', 'text-bg-warning'],
            ['Retidas por configuração', $stats['held'] ?? 0, 'bi-pause-circle', 'text-bg-secondary'],
            ['Com falha', $stats['failed'], 'bi-x-octagon', 'text-bg-danger'],
            ['Enviadas', $stats['sent'], 'bi-check2-circle', 'text-bg-success'],
        ] as [$label, $n, $icon, $cls]): ?>
            <div class="col-6 col-lg-3">
                <div class="card h-100"><div class="card-body d-flex align-items-center gap-3">
                    <span class="badge <?= $cls ?> fs-6"><i class="bi <?= $icon ?>"></i></span>
                    <div><div class="fs-4 fw-semibold"><?= (int) $n ?></div>
                    <div class="small text-muted"><?= core_e($label) ?></div></div>
                </div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (($stats['held'] ?? 0) > 0): ?>
        <div class="alert alert-warning">
            <strong>Mensagens retidas.</strong> Elas falharam por um motivo que vale para todas
            (envio desligado, servidor não informado, autenticação recusada) e por isso
            <em>não</em> gastaram tentativas: assim que a configuração for corrigida, a próxima
            execução do cron as envia.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= core_module_url('admin', ['a' => 'mailqueue_process']) ?>" class="d-inline">
        <?= Csrf::field() ?>
        <button class="btn btn-sm btn-primary"><i class="bi bi-play me-1"></i>Processar agora</button>
    </form>
    <form method="post" action="<?= core_module_url('admin', ['a' => 'mailqueue_retry']) ?>" class="d-inline ms-1">
        <?= Csrf::field() ?>
        <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat me-1"></i>Reenfileirar falhas</button>
    </form>

    <div class="table-responsive mt-3">
        <table class="table table-sm align-middle">
            <thead><tr>
                <th>#</th><th>Quando</th><th>Para</th><th>Assunto</th>
                <th>Estado</th><th>Via</th><th>Erro</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="text-muted"><?= (int) $r['id'] ?></td>
                    <td class="text-nowrap small"><?= core_e(date('d/m H:i', strtotime((string) $r['created_at']))) ?></td>
                    <td class="small"><?= core_e((string) $r['to_email']) ?>
                        <?php if (!empty($r['is_test'])): ?>
                            <span class="badge text-bg-light border">teste</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= core_e(mb_substr((string) $r['subject'], 0, 60)) ?></td>
                    <td>
                        <?php
                        $isHeld = $r['status'] === 'pending' && !empty($r['error_code']);
                        echo match (true) {
                            $r['status'] === 'sent'   => '<span class="badge text-bg-success">enviada</span>',
                            $r['status'] === 'failed' => '<span class="badge text-bg-danger">falhou</span>',
                            $isHeld                   => '<span class="badge text-bg-secondary">retida</span>',
                            default                   => '<span class="badge text-bg-warning">pendente</span>',
                        };
                        ?>
                        <?php if ((int) $r['attempts'] > 0): ?>
                            <span class="small text-muted"><?= (int) $r['attempts'] ?>x</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?= $r['delivery'] === 'mail'
                            ? '<span title="Entregue ao sendmail local — prova menos que um 250 do SMTP">mail()</span>'
                            : core_e((string) ($r['delivery'] ?? '')) ?>
                    </td>
                    <td class="small text-muted" style="max-width:340px">
                        <?php if (!empty($r['error_code'])): ?>
                            <span class="badge text-bg-light border"><?= core_e((string) $r['error_code']) ?></span>
                        <?php endif; ?>
                        <?= core_e(mb_substr((string) ($r['last_error'] ?? ''), 0, 160)) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">A fila está vazia.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    return (string) ob_get_clean();
}

/** Aba Diagnóstico. */
function core_admin_mail_tab_diag(): string
{
    $probe = $_SESSION['mail_probe'] ?? null;
    unset($_SESSION['mail_probe']);

    ob_start(); ?>
    <div class="row g-3">
        <div class="col-12 col-xl-6">
            <div class="card mb-3">
                <div class="card-header">Este servidor</div>
                <?= core_mail_checklist(MailDiagnostics::environment()) ?>
            </div>
            <div class="card">
                <div class="card-header">Fila e rotina automática</div>
                <?= core_mail_checklist(MailDiagnostics::queueHealth()) ?>
            </div>
        </div>
        <div class="col-12 col-xl-6">
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center gap-2">
                    <span>Verificações que saem para a rede</span>
                </div>
                <div class="card-body">
                    <p class="small text-muted">Estas consultas podem demorar alguns segundos e por isso
                    só rodam quando você pede.</p>
                    <form method="post" action="<?= core_module_url('admin', ['a' => 'mail_probe']) ?>" class="d-inline">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="what" value="dns">
                        <button class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-shield-check me-1"></i>Verificar SPF, DKIM e DMARC
                        </button>
                    </form>
                    <form method="post" action="<?= core_module_url('admin', ['a' => 'mail_probe']) ?>" class="d-inline ms-1">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="what" value="ports">
                        <button class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-ethernet me-1"></i>Testar portas de saída
                        </button>
                    </form>
                </div>
                <?php if (is_array($probe)): ?>
                    <div class="card-header bg-transparent small text-muted">
                        Resultado de <?= core_e($probe['quando']) ?>
                    </div>
                    <?= core_mail_checklist((array) $probe['itens']) ?>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header">Fora do alcance do sistema</div>
                <div class="card-body small">
                    <ul class="mb-0">
                        <?php foreach (MailDiagnostics::limits() as $l): ?>
                            <li><?= core_e($l) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/** Aba Histórico de testes (e detalhe de um teste). */
function core_admin_mail_tab_log(): string
{
    $id   = (int) ($_GET['id'] ?? 0);
    $test = $id > 0 ? MailTester::find($id) : null;

    ob_start(); ?>
    <?php if ($test): ?>
        <?php
        $ok    = $test['result'] === 'ok';
        $steps = json_decode((string) ($test['steps'] ?? '[]'), true) ?: [];
        ?>
        <div class="card mb-3">
            <div class="card-header d-flex align-items-center gap-2">
                <span>Teste #<?= (int) $test['id'] ?></span>
                <?= $ok
                    ? '<span class="badge text-bg-success">aceito pelo servidor</span>'
                    : ($test['result'] === 'running'
                        ? '<span class="badge text-bg-secondary">em andamento</span>'
                        : '<span class="badge text-bg-danger">falhou</span>') ?>
                <a class="btn btn-sm btn-outline-secondary ms-auto"
                   href="<?= core_module_url('admin', ['a' => 'mail', 'tab' => 'log']) ?>">todos os testes</a>
            </div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-3">Quando</dt>
                    <dd class="col-sm-9"><?= core_e(date('d/m/Y H:i:s', strtotime((string) $test['created_at']))) ?>
                        · <?= (int) $test['duration_ms'] ?> ms</dd>
                    <dt class="col-sm-3">Para</dt>
                    <dd class="col-sm-9"><?= core_e((string) $test['to_email']) ?></dd>
                    <dt class="col-sm-3">Caminho</dt>
                    <dd class="col-sm-9"><?= core_e(MailTester::CAMINHOS[$test['path']] ?? (string) $test['path']) ?></dd>
                    <dt class="col-sm-3">Solicitado por</dt>
                    <dd class="col-sm-9"><?= core_e((string) ($test['user_name'] ?? '—')) ?>
                        <span class="text-muted"><?= core_e((string) ($test['ip'] ?? '')) ?></span></dd>
                </dl>

                <?php if ($test['path'] === 'queue' && !empty($test['queue_id'])):
                    $q = MailQueue::find((int) $test['queue_id']); ?>
                    <hr>
                    <p class="mb-1"><strong>Estado na fila:</strong>
                        <?= core_e((string) ($q['status'] ?? 'removida')) ?>
                        <?php if (($q['status'] ?? '') === 'pending'): ?>
                            <span class="text-muted">— ainda não processada. Se continuar assim,
                            o cron não está agendado na hospedagem.</span>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>

                <?php if (!$ok && $test['result'] !== 'running'): ?>
                    <hr>
                    <div class="alert alert-danger mb-2">
                        <div class="fw-semibold">
                            <?= core_e((string) $test['error_code']) ?>
                        </div>
                        <div><?= core_e((string) $test['error_message']) ?></div>
                    </div>
                    <?php $dica = MailTester::explain((string) $test['error_code']); ?>
                    <?php if ($dica !== ''): ?>
                        <div class="alert alert-info mb-0"><i class="bi bi-lightbulb me-1"></i><?= core_e($dica) ?></div>
                    <?php endif; ?>
                <?php elseif ($ok): ?>
                    <hr>
                    <div class="alert alert-success mb-0">
                        O servidor de saída aceitou a mensagem. Confira agora a caixa de
                        <strong><?= core_e((string) $test['to_email']) ?></strong> — inclusive a pasta de spam.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($steps): ?>
            <div class="card mb-3">
                <div class="card-header">Tempo de cada etapa</div>
                <div class="card-body">
                    <?php foreach ($steps as $nome => $ms): ?>
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <div style="min-width:120px" class="small"><?= core_e((string) $nome) ?></div>
                            <div class="progress flex-grow-1" style="height:14px">
                                <div class="progress-bar" style="width:<?= min(100, (float) $ms) ?>%"></div>
                            </div>
                            <div class="small text-muted" style="min-width:70px"><?= core_e((string) $ms) ?> ms</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($test['transcript'])): ?>
            <div class="card">
                <div class="card-header">Conversa com o servidor</div>
                <div class="card-body">
                    <pre class="small mb-0" style="white-space:pre-wrap"><?= core_e((string) $test['transcript']) ?></pre>
                    <div class="form-text mt-2">C: o que o sistema enviou · S: o que o servidor respondeu.
                    Usuário e senha aparecem mascarados.</div>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>#</th><th>Quando</th><th>Para</th><th>Caminho</th>
                    <th>Resultado</th><th>Tempo</th><th>Quem</th><th></th></tr></thead>
                <tbody>
                <?php foreach (MailTester::recent(30) as $t): ?>
                    <tr>
                        <td class="text-muted"><?= (int) $t['id'] ?></td>
                        <td class="small text-nowrap"><?= core_e(date('d/m H:i', strtotime((string) $t['created_at']))) ?></td>
                        <td class="small"><?= core_e((string) $t['to_email']) ?></td>
                        <td class="small"><?= core_e((string) $t['path']) ?></td>
                        <td>
                            <?= match ($t['result']) {
                                'ok'      => '<span class="badge text-bg-success">aceito</span>',
                                'running' => '<span class="badge text-bg-secondary">em andamento</span>',
                                default   => '<span class="badge text-bg-danger">'
                                             . core_e((string) ($t['error_code'] ?: 'falhou')) . '</span>',
                            } ?>
                        </td>
                        <td class="small"><?= (int) $t['duration_ms'] ?> ms</td>
                        <td class="small text-muted"><?= core_e((string) ($t['user_name'] ?? '')) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= core_module_url('admin', ['a' => 'mail', 'tab' => 'log', 'id' => (int) $t['id']]) ?>">ver</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (MailTester::recent(1) === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Nenhum teste ainda.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="small text-muted">Os registros são apagados depois de 90 dias.</p>
    <?php endif; ?>
    <?php
    return (string) ob_get_clean();
}

// ═══════════════════════════════════════════════════════════════════════════
//  ABA LAYOUT — a casca de todos os e-mails do portal
// ═══════════════════════════════════════════════════════════════════════════

/** POST: grava o layout dos e-mails. */
function core_admin_mail_layout_save(): void
{
    Auth::requireGlobalAdmin();
    Csrf::check();

    if (!empty($_POST['restaurar'])) {
        MailTemplate::reset();
        Audit::log('mail.layout.reset', 'settings', null, null, null, 'admin');
        Flash::set('success', 'Layout dos e-mails restaurado ao padrão.');
        core_redirect('index.php?m=admin&a=mail&tab=layout');
    }

    $valores = [];
    foreach (array_keys(MailTemplate::DEFAULTS) as $k) {
        // Caixas de seleção ausentes no POST significam "desmarcado".
        $valores[$k] = in_array($k, ['enabled', 'show_logo', 'show_name'], true)
            ? (!empty($_POST[$k]) ? '1' : '0')
            : (string) ($_POST[$k] ?? '');
    }
    MailTemplate::save($valores);
    Audit::log('mail.layout.save', 'settings', null, null, null, 'admin');
    Flash::set('success', 'Layout dos e-mails salvo. Vale para os próximos envios, inclusive os que já estão na fila.');
    core_redirect('index.php?m=admin&a=mail&tab=layout');
}

/** Pré-visualização do layout (HTML do e-mail, servido para o iframe). */
function core_admin_mail_layout_preview(): void
{
    Auth::requireGlobalAdmin();

    // Valores do formulário (POST) ou os salvos (GET). A pré-visualização
    // passa pela MESMA normalização da gravação: o que se vê é o que sai.
    $cfg = MailTemplate::all();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::check();
        foreach (array_keys(MailTemplate::DEFAULTS) as $k) {
            $bruto = in_array($k, ['enabled', 'show_logo', 'show_name'], true)
                ? (!empty($_POST[$k]) ? '1' : '0')
                : (string) ($_POST[$k] ?? $cfg[$k]);
            $cfg[$k] = MailTemplate::normalize($k, $bruto);
        }
    }

    $html = MailTemplate::wrap(
        'Exemplo de mensagem do portal',
        MailTemplate::sampleBody(),
        [
            'preheader' => 'Assim aparece a prévia na caixa de entrada.',
            'cta'       => ['label' => 'Abrir o portal', 'url' => core_url('index.php')],
        ],
        $cfg
    );

    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Security-Policy: sandbox allow-same-origin');
    echo $html;
    exit;
}

function core_admin_mail_tab_layout(): string
{
    $cfg    = MailTemplate::all();
    $fontes = MailTemplate::fonts();

    ob_start(); ?>
    <p class="text-muted">
        A casca de todos os e-mails do portal — comunicados, pesquisas, avisos de vencimento,
        redefinição de senha. Cada mensagem traz o seu texto; o cabeçalho, as cores e o rodapé
        vêm daqui. Vale também para o que já está na fila, porque a casca é aplicada no envio.
    </p>

    <form method="post" action="<?= core_module_url('admin', ['a' => 'mail_layout_save']) ?>" id="mailLayoutForm">
        <?= Csrf::field() ?>
        <div class="row g-3">
            <div class="col-12 col-xl-5">
                <div class="card mb-3">
                    <div class="card-header">Cabeçalho</div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" name="enabled" id="mlEnabled"
                                   value="1" <?= $cfg['enabled'] === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mlEnabled">Aplicar este layout aos e-mails</label>
                            <div class="form-text">Desligado, cada mensagem sai como o módulo a escreveu (comportamento antigo).</div>
                        </div>
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label" for="mlHeaderBg">Cor do cabeçalho</label>
                                <input type="color" class="form-control form-control-color w-100" name="header_bg" id="mlHeaderBg"
                                       value="<?= core_e(MailTemplate::headerBg($cfg)) ?>">
                                <div class="form-text">Padrão: a cor da marca.</div>
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="mlHeaderText">Texto do cabeçalho</label>
                                <input type="color" class="form-control form-control-color w-100" name="header_text" id="mlHeaderText"
                                       value="<?= core_e(MailTemplate::headerText($cfg)) ?>">
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-3 mt-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="show_logo" id="mlLogo" value="1" <?= $cfg['show_logo'] === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="mlLogo">Mostrar o logotipo</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="show_name" id="mlName" value="1" <?= $cfg['show_name'] === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="mlName">Mostrar o nome da organização</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">Corpo</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6 col-md-4">
                                <label class="form-label" for="mlBodyBg">Fundo da página</label>
                                <input type="color" class="form-control form-control-color w-100" name="body_bg" id="mlBodyBg" value="<?= core_e($cfg['body_bg']) ?>">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label" for="mlCardBg">Fundo do cartão</label>
                                <input type="color" class="form-control form-control-color w-100" name="card_bg" id="mlCardBg" value="<?= core_e($cfg['card_bg']) ?>">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label" for="mlTextColor">Cor do texto</label>
                                <input type="color" class="form-control form-control-color w-100" name="text_color" id="mlTextColor" value="<?= core_e($cfg['text_color']) ?>">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label" for="mlLinkColor">Cor dos links</label>
                                <input type="color" class="form-control form-control-color w-100" name="link_color" id="mlLinkColor" value="<?= core_e(MailTemplate::linkColor($cfg)) ?>">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label" for="mlWidth">Largura (px)</label>
                                <input type="number" class="form-control" name="width" id="mlWidth" min="320" max="900" step="10" value="<?= core_e($cfg['width']) ?>">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label" for="mlRadius">Cantos (px)</label>
                                <input type="number" class="form-control" name="radius" id="mlRadius" min="0" max="24" value="<?= core_e($cfg['radius']) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="mlFont">Fonte</label>
                                <select class="form-select" name="font" id="mlFont">
                                    <?php foreach ($fontes as $pilha => $rotulo): ?>
                                        <option value="<?= core_e($pilha) ?>" <?= $cfg['font'] === $pilha ? 'selected' : '' ?>><?= core_e($rotulo) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Só fontes que existem nos clientes de e-mail — fonte da web não carrega no Outlook.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">Rodapé</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="mlSignature">Assinatura</label>
                            <input type="text" class="form-control" name="signature" id="mlSignature" maxlength="120"
                                   value="<?= core_e($cfg['signature']) ?>" placeholder="<?= core_e(Core\Branding::name()) ?>">
                            <div class="form-text">Vazio usa o nome da organização.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="mlFooterNote">Aviso</label>
                            <textarea class="form-control" name="footer_note" id="mlFooterNote" rows="2" maxlength="500"><?= core_e($cfg['footer_note']) ?></textarea>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="mlFooterExtra">Texto adicional (HTML simples)</label>
                            <textarea class="form-control font-monospace" name="footer_extra" id="mlFooterExtra" rows="2" maxlength="500"
                                      placeholder="Endereço, telefone, aviso de confidencialidade…"><?= core_e($cfg['footer_extra']) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar layout</button>
                    <button class="btn btn-outline-secondary" name="restaurar" value="1"
                            onclick="return confirm('Restaurar o layout padrão dos e-mails?')">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Restaurar padrão
                    </button>
                    <a class="btn btn-outline-primary ms-auto" href="<?= core_module_url('admin', ['a' => 'mail', 'tab' => 'test']) ?>">
                        <i class="bi bi-send-check me-1"></i>Enviar um teste
                    </a>
                </div>
            </div>

            <div class="col-12 col-xl-7">
                <div class="card position-sticky" style="top:1rem">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span><i class="bi bi-eye me-1"></i>Pré-visualização</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="mlRefresh" title="Atualizar">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <iframe name="mailLayoutFrame" id="mailLayoutFrame" title="Pré-visualização do e-mail"
                                style="width:100%;height:72vh;min-height:460px;border:0;background:#fff"></iframe>
                    </div>
                    <div class="card-footer small text-muted">
                        Mostra os valores atuais do formulário, ainda não salvos. O HTML usa tabelas e estilo
                        embutido — é o que Outlook e Gmail renderizam de forma previsível.
                    </div>
                </div>
            </div>
        </div>
    </form>

    <form method="post" action="<?= core_module_url('admin', ['a' => 'mail_layout_preview']) ?>"
          target="mailLayoutFrame" id="mailLayoutPreview"><?= Csrf::field() ?></form>

    <script>
    (function () {
        var form = document.getElementById('mailLayoutForm');
        var prev = document.getElementById('mailLayoutPreview');
        if (!form || !prev) return;

        var TEXTO = ['header_bg','header_text','body_bg','card_bg','text_color','link_color',
                     'font','width','radius','signature','footer_note','footer_extra'];
        var CHECK = ['enabled','show_logo','show_name'];

        function atualizar() {
            prev.querySelectorAll('[data-copy]').forEach(function (e) { e.remove(); });
            var add = function (n, v) {
                var h = document.createElement('input');
                h.type = 'hidden'; h.name = n; h.value = v; h.setAttribute('data-copy', '1');
                prev.appendChild(h);
            };
            TEXTO.forEach(function (n) { var el = form.querySelector('[name="' + n + '"]'); if (el) add(n, el.value); });
            CHECK.forEach(function (n) { var el = form.querySelector('[name="' + n + '"]'); if (el && el.checked) add(n, '1'); });
            prev.submit();
        }

        var t = null;
        form.addEventListener('input',  function () { clearTimeout(t); t = setTimeout(atualizar, 400); });
        form.addEventListener('change', function () { clearTimeout(t); t = setTimeout(atualizar, 150); });
        document.getElementById('mlRefresh').addEventListener('click', atualizar);
        atualizar();
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}

// ═══════════════════════════════════════════════════════════════════════════
//  ABA RECEBIMENTO — teste de IMAP/POP3 (fecha o ciclo com o envio)
// ═══════════════════════════════════════════════════════════════════════════

/** POST: grava a configuração da caixa de recebimento. */
function core_admin_mail_inbox_save(): void
{
    Auth::requireGlobalAdmin();
    Csrf::check();

    if (!empty($_POST['esquecer_senha'])) {
        MailInbox::forgetPassword();
        Flash::set('success', 'Senha da caixa removida.');
        core_redirect('index.php?m=admin&a=mail&tab=inbox');
    }

    $valores = [];
    foreach (array_keys(MailInbox::DEFAULTS) as $k) {
        $valores[$k] = in_array($k, ['enabled', 'verify_peer'], true)
            ? (!empty($_POST[$k]) ? '1' : '0')
            : (string) ($_POST[$k] ?? '');
    }
    $senha = (string) ($_POST['password'] ?? '');
    MailInbox::save($valores, $senha !== '' ? $senha : null);

    Audit::log('mail.inbox.save', 'settings', null, null, null, 'admin');
    Flash::set('success', 'Configuração de recebimento salva.');
    core_redirect('index.php?m=admin&a=mail&tab=inbox');
}

/**
 * POST: testa o recebimento. Com "ciclo completo", envia primeiro um e-mail
 * com um código no assunto e depois procura por ele na caixa — que é a
 * pergunta que o administrador realmente tem: "chega ou não chega?".
 */
function core_admin_mail_inbox_test(): void
{
    Auth::requireGlobalAdmin();
    Csrf::check();

    $override = [];
    foreach (array_keys(MailInbox::DEFAULTS) as $k) {
        if (isset($_POST[$k]) && $_POST[$k] !== '') {
            $override[$k] = (string) $_POST[$k];
        }
    }
    $senha  = (string) ($_POST['password'] ?? '');
    $ciclo  = !empty($_POST['ciclo']);
    $codigo = '';
    $envio  = null;

    if ($ciclo) {
        $para = trim((string) ($_POST['para'] ?? ($override['user'] ?? MailInbox::all()['user'])));
        $codigo = 'PORTAL-' . strtoupper(bin2hex(random_bytes(3)));
        $envio = Mailer::sendDetailed(
            $para,
            'Teste de recebimento ' . $codigo,
            '<p>Se esta mensagem chegou, o envio e o recebimento estão funcionando.</p>'
            . '<p>Código deste teste: <strong>' . core_e($codigo) . '</strong></p>',
            ['transcript' => false]
        );
        if (!$envio['ok']) {
            // Sem envio não há o que procurar: mostra logo o erro do envio.
            $_SESSION['_mail_inbox_result'] = [
                'ok' => false, 'code' => 'ENVIO', 'error' => $envio['error'],
                'total' => 0, 'mensagens' => [], 'encontrada' => null,
                'transcript' => [], 'ms' => $envio['ms'], 'codigo' => $codigo,
                'envio' => $envio, 'protocol' => $override['protocol'] ?? MailInbox::all()['protocol'],
            ];
            core_redirect('index.php?m=admin&a=mail&tab=inbox');
        }
        // O servidor precisa de um instante para entregar na caixa.
        sleep(3);
    }

    $r = MailInbox::test($override, $senha !== '' ? $senha : null, $codigo);
    $r['codigo'] = $codigo;
    $r['envio']  = $envio;
    $_SESSION['_mail_inbox_result'] = $r;

    Audit::log('mail.inbox.test', 'settings', null, null,
        ['ok' => $r['ok'], 'code' => $r['code'], 'ciclo' => $ciclo], 'admin');
    core_redirect('index.php?m=admin&a=mail&tab=inbox');
}

function core_admin_mail_tab_inbox(): string
{
    $cfg = MailInbox::all();
    $r   = $_SESSION['_mail_inbox_result'] ?? null;
    unset($_SESSION['_mail_inbox_result']);

    ob_start(); ?>
    <p class="text-muted">
        Confere se a caixa do portal <strong>recebe</strong> mensagens. O teste de entrega, na aba ao lado,
        mostra que o servidor de saída aceitou o e-mail; este abre a caixa e lê o que chegou.
        Juntos respondem a pergunta inteira: saiu e chegou.
    </p>

    <?php if (!function_exists('imap_open')): ?>
        <div class="alert alert-light border d-flex gap-2 py-2 small">
            <i class="bi bi-info-circle mt-1"></i>
            <div>Este servidor não tem a extensão <code>imap</code> do PHP — comum nas hospedagens.
            O teste abaixo não depende dela: fala IMAP e POP3 diretamente, como o envio já faz com SMTP.</div>
        </div>
    <?php endif; ?>

    <?php if ($r): ?>
        <?php
        $ok    = !empty($r['ok']);
        $achou = !empty($r['encontrada']);
        $classe = $ok ? ($r['codigo'] !== '' ? ($achou ? 'success' : 'warning') : 'success') : 'danger';
        ?>
        <div class="alert alert-<?= $classe ?>">
            <h2 class="h6 mb-2">
                <?php if (!$ok): ?>
                    <i class="bi bi-x-octagon me-1"></i>Não foi possível ler a caixa
                <?php elseif ($r['codigo'] === ''): ?>
                    <i class="bi bi-check-circle me-1"></i>Caixa acessada com sucesso
                <?php elseif ($achou): ?>
                    <i class="bi bi-check-circle me-1"></i>Ciclo completo: a mensagem enviada chegou
                <?php else: ?>
                    <i class="bi bi-hourglass-split me-1"></i>A caixa abriu, mas a mensagem ainda não apareceu
                <?php endif; ?>
                <span class="small fw-normal ms-1">(<?= (float) $r['ms'] ?> ms)</span>
            </h2>

            <?php if (!$ok): ?>
                <p class="mb-1"><strong><?= core_e($r['code']) ?></strong> — <?= core_e($r['error']) ?></p>
                <?php $dica = $r['code'] === 'ENVIO' ? 'O envio falhou antes da procura; resolva na aba "Teste de entrega".' : MailInbox::explain((string) $r['code']); ?>
                <?php if ($dica !== ''): ?><p class="mb-0 small"><?= core_e($dica) ?></p><?php endif; ?>
            <?php else: ?>
                <p class="mb-1 small">
                    <?= (int) $r['total'] ?> mensagem(ns) na caixa.
                    <?php if ($r['codigo'] !== '' && !$achou): ?>
                        A procura foi pelo código <code><?= core_e($r['codigo']) ?></code> — a entrega pode levar
                        alguns segundos a mais. Repita o teste em instantes.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

        <?php if (!empty($r['mensagens'])): ?>
        <div class="card mb-3">
            <div class="card-header">Últimas mensagens da caixa</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>De</th><th>Assunto</th><th>Data</th></tr></thead>
                    <tbody>
                    <?php foreach ($r['mensagens'] as $m): ?>
                        <tr class="<?= ($r['codigo'] !== '' && str_contains((string) $m['subject'], (string) $r['codigo'])) ? 'table-success' : '' ?>">
                            <td class="small"><?= core_e($m['from'] ?: '—') ?></td>
                            <td class="small"><?= core_e($m['subject'] ?: '(sem assunto)') ?></td>
                            <td class="small text-muted"><?= core_e($m['date'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($r['transcript'])): ?>
        <details class="mb-3">
            <summary class="small text-muted">Conversa com o servidor</summary>
            <pre class="small bg-body-tertiary border rounded p-2 mt-2 mb-0" style="max-height:18rem;overflow:auto"><?php
                foreach ($r['transcript'] as $l) { echo core_e($l), "\n"; }
            ?></pre>
        </details>
        <?php endif; ?>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <form method="post" action="<?= core_module_url('admin', ['a' => 'mail_inbox_save']) ?>">
                <?= Csrf::field() ?>
                <div class="card">
                    <div class="card-header">Servidor de recebimento</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="miProto">Protocolo</label>
                                <select class="form-select" name="protocol" id="miProto">
                                    <option value="imap" <?= $cfg['protocol'] === 'imap' ? 'selected' : '' ?>>IMAP</option>
                                    <option value="pop3" <?= $cfg['protocol'] === 'pop3' ? 'selected' : '' ?>>POP3</option>
                                </select>
                                <div class="form-text">IMAP mantém as mensagens no servidor; prefira-o.</div>
                            </div>
                            <div class="col-12 col-md-8">
                                <label class="form-label" for="miHost">Servidor</label>
                                <input class="form-control" name="host" id="miHost" value="<?= core_e($cfg['host']) ?>"
                                       placeholder="imap.seudominio.com.br">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label" for="miSec">Segurança</label>
                                <select class="form-select" name="security" id="miSec">
                                    <option value="ssl"      <?= $cfg['security'] === 'ssl' ? 'selected' : '' ?>>SSL/TLS direto</option>
                                    <option value="starttls" <?= $cfg['security'] === 'starttls' ? 'selected' : '' ?>>STARTTLS</option>
                                    <option value="none"     <?= $cfg['security'] === 'none' ? 'selected' : '' ?>>Sem criptografia</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label" for="miPort">Porta</label>
                                <input type="number" class="form-control" name="port" id="miPort" min="1" max="65535"
                                       value="<?= core_e($cfg['port']) ?>">
                                <div class="form-text">993/143 IMAP · 995/110 POP3</div>
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="miTimeout">Tempo limite (s)</label>
                                <input type="number" class="form-control" name="timeout" id="miTimeout" min="3" max="60"
                                       value="<?= core_e($cfg['timeout']) ?>">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="miUser">Usuário</label>
                                <input class="form-control" name="user" id="miUser" value="<?= core_e($cfg['user']) ?>"
                                       autocomplete="off" placeholder="portal@seudominio.com.br">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="miPass">Senha</label>
                                <input type="password" class="form-control" name="password" id="miPass"
                                       autocomplete="new-password"
                                       placeholder="<?= MailInbox::passwordIsSet() ? 'já configurada — deixe em branco para manter' : 'senha da caixa' ?>">
                                <div class="form-text">
                                    Guardada cifrada, como a do SMTP. Em contas com verificação em duas etapas, use senha de aplicativo.
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="miBox">Caixa (IMAP)</label>
                                <input class="form-control" name="mailbox" id="miBox" value="<?= core_e($cfg['mailbox']) ?>" placeholder="INBOX">
                            </div>
                            <div class="col-12 col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="verify_peer" id="miVerify" value="1"
                                           <?= $cfg['verify_peer'] === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="miVerify">Verificar o certificado do servidor</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer d-flex flex-wrap gap-2">
                        <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar</button>
                        <?php if (MailInbox::passwordIsSet()): ?>
                        <button class="btn btn-outline-secondary" name="esquecer_senha" value="1"
                                onclick="return confirm('Remover a senha guardada desta caixa?')">Esquecer a senha</button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <div class="col-12 col-lg-5">
            <form method="post" action="<?= core_module_url('admin', ['a' => 'mail_inbox_test']) ?>">
                <?= Csrf::field() ?>
                <div class="card">
                    <div class="card-header">Testar</div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" name="ciclo" id="miCiclo" value="1" checked>
                            <label class="form-check-label" for="miCiclo">Ciclo completo (enviar e procurar)</label>
                            <div class="form-text">
                                Envia uma mensagem com um código no assunto e procura por ela na caixa.
                                Desligado, apenas abre a caixa e lista o que já está lá.
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="miPara">Enviar para</label>
                            <input class="form-control" name="para" id="miPara"
                                   value="<?= core_e($cfg['user']) ?>" placeholder="a própria caixa">
                            <div class="form-text">Normalmente o próprio endereço da caixa acima.</div>
                        </div>
                        <p class="small text-muted mb-0">
                            O teste usa os valores <strong>salvos</strong>. Altere e salve o formulário ao lado antes de testar.
                        </p>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-primary w-100"><i class="bi bi-inbox me-1"></i>Testar recebimento</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}
