<?php
/**
 * ADMINISTRAÇÃO — Backup e restauração.
 *
 * Um pacote de backup é o hospital inteiro dentro de um arquivo: hash de
 * senha de todo mundo, segredo de verificação em duas etapas, atestados,
 * histórico salarial. Por isso a tela é fechada ao administrador global, o
 * download é registrado na auditoria com o nome de quem baixou, e a página
 * avisa o que existe dentro do arquivo antes de entregá-lo.
 *
 * A RESTAURAÇÃO EM PRODUÇÃO NÃO É OFERECIDA AQUI, de propósito. Ela apaga e
 * recria as tabelas, não tem volta, e no dia em que é preciso — banco
 * perdido — esta tela nem carrega. O caminho dela é a linha de comando
 * (scripts/restore.php), que funciona com o sistema fora do ar. O que a tela
 * oferece é a RESTAURAÇÃO DE TESTE em outro banco, que é como se confere que
 * um backup presta sem arriscar o que está no ar.
 */

declare(strict_types=1);

use Core\Audit;
use Core\Auth;
use Core\Backup;
use Core\BackupRestore;
use Core\Csrf;
use Core\Flash;
use Core\Settings;

function core_backup_tabs(): array
{
    return [
        'list'     => ['label' => 'Cópias', 'icon' => 'bi-archive'],
        'schedule' => ['label' => 'Agendamento', 'icon' => 'bi-clock'],
        'howto'    => ['label' => 'Como restaurar', 'icon' => 'bi-life-preserver'],
    ];
}

function core_backup_bytes(int $b): string
{
    if ($b >= 1073741824) {
        return number_format($b / 1073741824, 1, ',', '.') . ' GB';
    }
    if ($b >= 1048576) {
        return number_format($b / 1048576, 1, ',', '.') . ' MB';
    }
    return number_format(max(0, $b) / 1024, 0, ',', '.') . ' KB';
}

// ------------------------------------------------------------------ AÇÕES

/** Gera um backup agora. */
function core_admin_backup_create(): void
{
    Auth::requireGlobalAdmin();
    Csrf::check();

    $comArquivos = !empty($_POST['files']);
    try {
        $r = Backup::create([
            'files'    => $comArquivos,
            'motivo'   => 'manual pela administração',
            'retencao' => true,
        ]);
        $avisos = (array) ($r['avisos'] ?? []);
        Flash::set($avisos === [] ? 'success' : 'warning',
            'Backup gerado (' . core_backup_bytes((int) $r['bytes']) . ', '
            . number_format((float) $r['segundos'], 1, ',', '.') . 's).'
            . ($avisos === [] ? '' : ' Avisos: ' . core_e(implode(' ', array_slice($avisos, 0, 3)))));
    } catch (\Throwable $e) {
        Flash::set('danger', 'Não foi possível gerar o backup: ' . $e->getMessage());
    }
    core_redirect('index.php?m=admin&a=backup');
}

/** Entrega o pacote para download, registrando quem baixou. */
function core_admin_backup_download(): void
{
    Auth::requireGlobalAdmin();

    $id   = (string) ($_GET['id'] ?? '');
    $item = Backup::find($id);
    if (!$item || !is_file((string) $item['caminho'])) {
        http_response_code(404);
        Core\Layout::renderError(404, 'Backup não encontrado.');
        exit;
    }

    // O id vem da listagem do diretório, mas a conferência aqui é de graça e
    // fecha qualquer tentativa de sair da pasta pelo parâmetro.
    $real = realpath((string) $item['caminho']);
    $base = realpath(Backup::dir());
    if ($real === false || $base === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
        http_response_code(400);
        Core\Layout::renderError(400, 'Caminho de backup inválido.');
        exit;
    }

    Audit::log('backup.download', 'backup', substr($id, 0, 40),
        ['pacote' => $id, 'bytes' => (int) $item['bytes']], null, 'core');

    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . basename($real) . '"');
    header('Content-Length: ' . (string) filesize($real));
    header('X-Content-Type-Options: nosniff');
    // Streaming: um pacote de centenas de MB não cabe na memória do PHP.
    $fp = fopen($real, 'rb');
    if ($fp) {
        while (!feof($fp)) {
            echo fread($fp, 262144);
            flush();
        }
        fclose($fp);
    }
    exit;
}

function core_admin_backup_delete(): void
{
    Auth::requireGlobalAdmin();
    Csrf::check();

    $id = (string) ($_POST['id'] ?? '');
    $ok = Backup::delete($id);
    Flash::set($ok ? 'success' : 'danger',
        $ok ? 'Backup excluído.' : 'Backup não encontrado (ou já excluído).');
    core_redirect('index.php?m=admin&a=backup');
}

/** Confere um pacote: manifesto, somas de verificação e conteúdo. */
function core_admin_backup_verify(): void
{
    Auth::requireGlobalAdmin();
    Csrf::check();

    $id   = (string) ($_POST['id'] ?? '');
    $item = Backup::find($id);
    if (!$item) {
        Flash::set('danger', 'Backup não encontrado.');
        core_redirect('index.php?m=admin&a=backup');
    }
    try {
        $r  = Backup::verify((string) $item['caminho']);
        $ok = (bool) ($r['ok'] ?? false);
        Flash::set($ok ? 'success' : 'danger', $ok
            ? 'Pacote íntegro: todas as entradas conferem com o manifesto.'
            : 'O pacote NÃO confere: ' . implode(' ', array_slice((array) ($r['erros'] ?? []), 0, 3)));
    } catch (\Throwable $e) {
        Flash::set('danger', 'Não foi possível conferir: ' . $e->getMessage());
    }
    core_redirect('index.php?m=admin&a=backup');
}

function core_admin_backup_schedule_save(): void
{
    Auth::requireGlobalAdmin();
    Csrf::check();

    Settings::set('backup.schedule_enabled', !empty($_POST['enabled']) ? '1' : '0');
    Settings::set('backup.schedule_hour', (string) max(0, min(23, (int) ($_POST['hour'] ?? 3))));
    Settings::set('backup.schedule_minute', (string) max(0, min(59, (int) ($_POST['minute'] ?? 0))));
    Settings::set('backup.schedule_files', !empty($_POST['files']) ? '1' : '0');
    Settings::set('backup.keep_daily', (string) max(1, min(60, (int) ($_POST['keep_daily'] ?? 7))));
    Settings::set('backup.keep_weekly', (string) max(0, min(52, (int) ($_POST['keep_weekly'] ?? 4))));
    Settings::set('backup.keep_monthly', (string) max(0, min(36, (int) ($_POST['keep_monthly'] ?? 3))));
    Settings::set('backup.max_mb', (string) max(50, min(102400, (int) ($_POST['max_mb'] ?? 2048))));

    Audit::log('backup.schedule', 'settings', null, 'Agendamento de backup atualizado', null, 'core');
    Flash::set('success', 'Agendamento salvo.');
    core_redirect('index.php?m=admin&a=backup&tab=schedule');
}

// ------------------------------------------------------------------- TELA

function core_admin_backup(): string
{
    Auth::requireGlobalAdmin();

    $tab = (string) ($_GET['tab'] ?? 'list');
    if (!array_key_exists($tab, core_backup_tabs())) {
        $tab = 'list';
    }

    ob_start(); ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-hdd-stack me-2"></i>Backup e restauração</h1>
    </div>

    <ul class="nav nav-tabs mb-3">
        <?php foreach (core_backup_tabs() as $key => $t): ?>
            <li class="nav-item">
                <a class="nav-link <?= $tab === $key ? 'active' : '' ?>"
                   href="<?= core_module_url('admin', ['a' => 'backup', 'tab' => $key]) ?>">
                    <i class="bi <?= core_e($t['icon']) ?> me-1"></i><?= core_e($t['label']) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <?= match ($tab) {
        'schedule' => core_admin_backup_tab_schedule(),
        'howto'    => core_admin_backup_tab_howto(),
        default    => core_admin_backup_tab_list(),
    } ?>
    <?php
    return (string) ob_get_clean();
}

function core_admin_backup_tab_list(): string
{
    $itens = Backup::list();
    $dir   = Backup::dir();
    $foraDoWebroot = !str_starts_with(realpath($dir) ?: $dir, realpath(BASE_PATH) ?: BASE_PATH);
    $ultimo = $itens[0] ?? null;

    ob_start(); ?>
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-8">
            <div class="alert <?= $ultimo ? 'alert-light border' : 'alert-warning' ?> mb-0">
                <?php if ($ultimo):
                    $idade = (time() - strtotime((string) $ultimo['criado_em'])) / 3600; ?>
                    <strong>Último backup:</strong>
                    <?= core_e(date('d/m/Y H:i', strtotime((string) $ultimo['criado_em']))) ?>
                    (<?= $idade < 48 ? sprintf('há %.0f h', $idade) : sprintf('há %.0f dias', $idade / 24) ?>),
                    <?= core_backup_bytes((int) $ultimo['bytes']) ?>,
                    <?= (int) $ultimo['tabelas'] ?> tabelas e <?= number_format((int) $ultimo['linhas'], 0, ',', '.') ?> linhas.
                    <?php if ($idade > 48): ?>
                        <span class="text-danger d-block mt-1">Faz mais de dois dias. Verifique o agendamento.</span>
                    <?php endif; ?>
                <?php else: ?>
                    <strong>Nenhum backup ainda.</strong> Gere o primeiro agora e configure o agendamento
                    na aba ao lado — um sistema de hospital sem cópia é um problema esperando data.
                <?php endif; ?>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <form method="post" action="<?= core_module_url('admin', ['a' => 'backup_create']) ?>" class="card h-100">
                <?= Csrf::field() ?>
                <div class="card-body d-flex flex-column gap-2">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="files" value="1" id="bkFiles" checked>
                        <label class="form-check-label small" for="bkFiles">incluir os arquivos enviados
                        (documentos, fotos, anexos)</label>
                    </div>
                    <button class="btn btn-primary"><i class="bi bi-play-circle me-1"></i>Gerar backup agora</button>
                    <div class="form-text">Pode levar alguns minutos em bases grandes.</div>
                </div>
            </form>
        </div>
    </div>

    <div class="alert alert-warning">
        <strong>O que existe dentro de um pacote destes:</strong> todos os dados do hospital, incluindo
        hash da senha de cada usuário, segredo da verificação em duas etapas, dados de saúde e
        documentos de pessoal. Guarde o arquivo baixado como guardaria um prontuário — e apague-o do
        computador quando não precisar mais. Todo download fica registrado na auditoria.
    </div>

    <div class="card">
        <div class="card-header d-flex align-items-center gap-2">
            <span>Cópias guardadas</span>
            <span class="ms-auto small text-muted">
                <?= core_e($dir) ?>
                <?php if ($foraDoWebroot): ?>
                    <span class="badge text-bg-success">fora da pasta pública</span>
                <?php else: ?>
                    <span class="badge text-bg-warning" title="Protegido por .htaccess e nome aleatório">dentro da pasta pública</span>
                <?php endif; ?>
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>Quando</th><th>Tamanho</th><th>Conteúdo</th><th>Origem</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($itens as $i): ?>
                    <tr>
                        <td class="text-nowrap">
                            <?= core_e(date('d/m/Y H:i', strtotime((string) $i['criado_em']))) ?>
                            <?php if ((int) $i['avisos'] > 0): ?>
                                <span class="badge text-bg-warning" title="O manifesto traz avisos"><?= (int) $i['avisos'] ?> aviso(s)</span>
                            <?php endif; ?>
                        </td>
                        <td><?= core_backup_bytes((int) $i['bytes']) ?></td>
                        <td class="small">
                            <?php if (!empty($i['com_banco'])): ?>
                                <?= (int) $i['tabelas'] ?> tabelas ·
                                <?= number_format((int) $i['linhas'], 0, ',', '.') ?> linhas
                            <?php else: ?>
                                <span class="text-muted">sem banco</span>
                            <?php endif; ?>
                            <?php if ((int) $i['arquivos'] > 0): ?>
                                · <?= number_format((int) $i['arquivos'], 0, ',', '.') ?> arquivos
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= core_e((string) ($i['motivo'] ?: $i['gerado_por'])) ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-primary"
                               href="<?= core_module_url('admin', ['a' => 'backup_download', 'id' => (string) $i['id']]) ?>">
                                <i class="bi bi-download"></i>
                            </a>
                            <form method="post" class="d-inline"
                                  action="<?= core_module_url('admin', ['a' => 'backup_verify']) ?>">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= core_e((string) $i['id']) ?>">
                                <button class="btn btn-sm btn-outline-secondary" title="Conferir integridade">
                                    <i class="bi bi-shield-check"></i>
                                </button>
                            </form>
                            <form method="post" class="d-inline"
                                  action="<?= core_module_url('admin', ['a' => 'backup_delete']) ?>"
                                  onsubmit="return confirm('Excluir esta cópia? Não há como desfazer.');">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= core_e((string) $i['id']) ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($itens === []): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Nenhuma cópia guardada.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

function core_admin_backup_tab_schedule(): string
{
    $lig   = Settings::get('backup.schedule_enabled', '0') === '1';
    $hora  = (int) Settings::get('backup.schedule_hour', '3');
    $min   = (int) Settings::get('backup.schedule_minute', '0');
    $arq   = Settings::get('backup.schedule_files', '1') === '1';
    $ultimo = Settings::get('cron.last_run_at');

    ob_start(); ?>
    <form method="post" action="<?= core_module_url('admin', ['a' => 'backup_schedule_save']) ?>" class="row g-3">
        <?= Csrf::field() ?>
        <div class="col-12 col-xl-7">
            <div class="card mb-3">
                <div class="card-header">Backup automático</div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input type="hidden" name="enabled" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" name="enabled" value="1"
                               id="bkEnabled" <?= $lig ? 'checked' : '' ?>>
                        <label class="form-check-label" for="bkEnabled"><strong>Gerar backup todos os dias</strong></label>
                    </div>
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-semibold">Hora</label>
                            <input class="form-control" type="number" name="hour" min="0" max="23" value="<?= $hora ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small fw-semibold">Minuto</label>
                            <input class="form-control" type="number" name="minute" min="0" max="59" value="<?= $min ?>">
                        </div>
                        <div class="col-12 col-md-6 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input type="hidden" name="files" value="0">
                                <input class="form-check-input" type="checkbox" name="files" value="1"
                                       id="bkSchedFiles" <?= $arq ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="bkSchedFiles">incluir os arquivos enviados</label>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-light border mt-3 mb-0 small">
                        O backup roda dentro da <strong>rotina periódica</strong> do sistema
                        (<code>cron.php</code>): ele sai na primeira execução depois da hora marcada,
                        desde que o último tenha mais de 20 horas — assim um cron atrasado não deixa
                        o dia sem cópia.
                        <?php if ($ultimo === null): ?>
                            <div class="text-danger mt-1"><strong>A rotina periódica nunca rodou.</strong>
                            Sem ela agendada na hospedagem, o backup automático não acontece.</div>
                        <?php else: ?>
                            <div class="mt-1">Última execução da rotina:
                            <?= core_e(date('d/m/Y H:i', strtotime($ultimo))) ?>.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Quanto guardar</div>
                <div class="card-body row g-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold">Dias recentes</label>
                        <input class="form-control" type="number" name="keep_daily" min="1" max="60"
                               value="<?= (int) Settings::get('backup.keep_daily', '7') ?>">
                        <div class="form-text small">tudo dos últimos N dias</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold">Semanas</label>
                        <input class="form-control" type="number" name="keep_weekly" min="0" max="52"
                               value="<?= (int) Settings::get('backup.keep_weekly', '4') ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold">Meses</label>
                        <input class="form-control" type="number" name="keep_monthly" min="0" max="36"
                               value="<?= (int) Settings::get('backup.keep_monthly', '3') ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold">Teto (MB)</label>
                        <input class="form-control" type="number" name="max_mb" min="50" max="102400"
                               value="<?= (int) Settings::get('backup.max_mb', '2048') ?>">
                        <div class="form-text small">o mais recente nunca é apagado</div>
                    </div>
                </div>
            </div>

            <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Salvar agendamento</button>
        </div>
    </form>
    <?php
    return (string) ob_get_clean();
}

function core_admin_backup_tab_howto(): string
{
    ob_start(); ?>
    <div class="row g-3">
        <div class="col-12 col-xl-8">
            <div class="card mb-3">
                <div class="card-header">Restaurar de verdade (produção)</div>
                <div class="card-body">
                    <p>A restauração em produção <strong>não é feita por esta tela</strong>, e isso é
                    de propósito: ela apaga e recria todas as tabelas, não tem como desfazer, e no dia
                    em que ela é necessária — banco perdido — esta página nem abre.</p>
                    <p>O caminho é a linha de comando, que funciona com o sistema fora do ar:</p>
                    <pre class="bg-body-tertiary p-3 rounded small mb-3"><code># conferir o pacote (não toca no banco)
php scripts/restore.php --list
php scripts/restore.php --inspect=&lt;id-do-backup&gt;

# restaurar em um banco de TESTE (recomendado antes de qualquer coisa)
php scripts/restore.php --restore=&lt;id&gt; --target=portal_teste

# restaurar em produção (pede confirmação; gera um backup de segurança antes)
php scripts/restore.php --restore=&lt;id&gt; --target=producao</code></pre>
                    <p class="mb-0">Se a hospedagem não dá acesso a linha de comando, baixe o pacote,
                    descompacte e importe o <code>database.sql</code> pelo phpMyAdmin — e depois
                    devolva a pasta <code>uploads/</code> por FTP.</p>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Conferir que um backup presta (sem arriscar nada)</div>
                <div class="card-body">
                    <p>Um backup que ninguém testou é uma promessa, não uma cópia. O jeito de
                    transformar em cópia é restaurar em outro banco e comparar:</p>
                    <pre class="bg-body-tertiary p-3 rounded small mb-3"><code>php scripts/restore.php --restore=&lt;id&gt; --target=portal_teste
php scripts/backup_verify.php --b=portal_teste</code></pre>
                    <p class="mb-0">O segundo comando compara <strong>tabela a tabela</strong> —
                    contagem de linhas e uma soma de verificação do conteúdo — e termina dizendo se os
                    dois bancos são iguais. No modo de teste as credenciais são neutralizadas na cópia
                    (senhas e 2FA), para uma base de teste não virar um segundo cofre.</p>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card">
                <div class="card-header">O que entra no pacote</div>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item">Estrutura e dados de todas as tabelas</li>
                    <li class="list-group-item">Arquivos enviados (<code>uploads/</code> e
                        <code>storage/uploads/</code>), quando marcado</li>
                    <li class="list-group-item">Manifesto: data, versão, migrações aplicadas,
                        linhas e soma de verificação por tabela</li>
                    <li class="list-group-item text-muted"><code>config/config.php</code> fica de fora
                        por padrão (guarda os segredos da instalação)</li>
                </ul>
            </div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}
