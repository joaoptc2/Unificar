<?php
/**
 * ============================================================
 * PLATAFORMA UNIFICADA — Instalador
 * 1. Coleta credenciais do banco e dados do administrador;
 * 2. Gera config/config.php;
 * 3. Executa sql/schema.sql (núcleo) e sql/modules/*.sql;
 * 4. Cria o administrador inicial.
 * Remova este arquivo após a instalação.
 * ============================================================
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * O instalador é o único .php público que precisa enxergar o CÓDIGO, e ele
 * roda antes de existir configuração — então não pode carregar o bootstrap.
 * Usa o mesmo localizador dos outros pontos de entrada: assim ele e o
 * bootstrap NUNCA divergem sobre onde as coisas estão.
 *
 * Isto não é zelo: as duas cascatas de config (a daqui e a do bootstrap) já
 * eram cópias uma da outra, ancoradas em variáveis DIFERENTES (__DIR__ aqui,
 * BASE_PATH lá) que só coincidiam porque tudo morava junto. Separadas, o
 * instalador procuraria o config num lugar e o portal em outro — e o
 * instalador, não achando nada, ofereceria instalar POR CIMA de uma
 * instalação viva, com app.key nova e toda senha cifrada ilegível.
 */
require __DIR__ . '/localizar.php';

/** Onde mora o código: core/, sql/, config/config.example.php. */
define('APP_DIR', UNIFICAR_APP_DIR);

require_once APP_DIR . '/core/src/Migrations.php'; // apenas o parser de SQL

/**
 * Onde o config PODE estar. A mesma cascata do core/bootstrap.php — repetida
 * aqui porque o instalador roda ANTES de existir configuração e por isso não
 * carrega o bootstrap.
 *
 * Verificar só __DIR__.'/config/config.php' era um perigo real: com o arquivo
 * movido para fora da área pública, o instalador diria "não instalado" e,
 * se alguém confirmasse, rodaria o schema por cima, recriaria o admin e
 * geraria uma app.key nova — que torna ilegível toda senha de SMTP já
 * cifrada.
 */
$configCandidatos = array_filter([
    getenv('UNIFICAR_CONFIG') ?: null,
    dirname(BASE_PATH) . '/' . basename(BASE_PATH) . '-config/config.php',
    // Irmã da pasta PAI: é onde este próprio instalador grava numa subpasta
    // do site (ver $configIrmao abaixo). A lista aqui e a do bootstrap têm
    // de ser a MESMA, senão o instalador grava num lugar que o portal não
    // procura e oferece reinstalar no acesso seguinte.
    dirname(dirname(BASE_PATH)) . '/' . basename(dirname(BASE_PATH)) . '-config/config.php',
    BASE_PATH . '/config/config.php',
]);
$configExistente = null;
$configIlegivel  = false;
foreach ($configCandidatos as $c) {
    // EXISTIR é is_file(). Usar is_readable() como critério de existência
    // fazia um config sem permissão de leitura parecer "não instalado" — e o
    // instalador então oferecia reinstalar por cima de uma instalação viva,
    // recriando o admin e gerando uma app.key nova, que torna ilegível toda
    // senha de SMTP já cifrada.
    if (@is_file($c)) {
        $configExistente = $c;
        $configIlegivel  = !@is_readable($c);
        break;
    }
}
// Ilegível é recusado inclusive com ?force=1: quem não consegue LER o arquivo
// também não consegue conferir o que estaria sobrescrevendo.
if ($configExistente !== null && $configIlegivel) {
    exit('Existe configuração em ' . htmlspecialchars($configExistente, ENT_QUOTES)
       . ', mas não consigo lê-la (dono ou permissão do arquivo). Corrija as permissões — '
       . 'não vou oferecer reinstalação sem saber o que já está instalado.');
}
if ($configExistente !== null && empty($_GET['force'])) {
    exit('A plataforma já está instalada (configuração em ' . htmlspecialchars($configExistente, ENT_QUOTES)
       . '). Remova o arquivo install.php. Para reinstalar, apague esse arquivo de configuração.');
}

// Destino de uma instalação NOVA: fora da área pública quando der, dentro
// quando não der. A pasta irmã leva o nome da pasta pública para não colidir
// com outra instalação em hospedagem com addon domains, onde o diretório
// acima é o home da conta, compartilhado.
$configIrmao = dirname(BASE_PATH) . '/' . basename(BASE_PATH) . '-config';

// Se a instalação está numa SUBPASTA do site (public_html/portal), a pasta
// irmã continua dentro da área servida — public_html/portal-config responde
// pela URL /portal-config/. Nesse caso, sobe para a irmã da RAIZ do site.
$docroot = @realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
if ($docroot !== false && $docroot !== '' && str_starts_with($configIrmao . '/', $docroot . '/')) {
    $configIrmao = dirname($docroot) . '/' . basename($docroot) . '-config';
    // Se nem isso escapa (docroot na raiz do sistema, por exemplo), não há
    // "fora" possível por aqui: o instalador grava dentro e diz a verdade.
    if (str_starts_with($configIrmao . '/', $docroot . '/')) {
        $configIrmao = '';
    }
}

$configFile  = BASE_PATH . '/config/config.php';
$configFora  = false;
if ($configIrmao !== ''
    && (@is_dir($configIrmao) ? @is_writable($configIrmao) : @mkdir($configIrmao, 0750, true))) {
    $configFile = $configIrmao . '/config.php';
    $configFora = true;
}
// Reinstalando com ?force=1 sobre um config que EXISTE: grava por cima dele,
// onde ele está. Calcular um destino novo gravava outro config, com app.key
// nova, e abandonava o antigo — com a senha do banco — no public_html,
// enquanto o portal seguia lendo o antigo pela cascata.
$configAnterior = null;
if ($configExistente !== null) {
    $configFile     = $configExistente;
    $configFora     = !str_starts_with($configExistente, BASE_PATH . '/');
    $configAnterior = @include $configExistente;
    if (!is_array($configAnterior)) {
        $configAnterior = null;
    }
}

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = (int) ($_POST['db_port'] ?? 3306);
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $orgName = trim($_POST['org_name'] ?? 'Portal Corporativo');
    $admName = trim($_POST['admin_name'] ?? '');
    $admUser = trim($_POST['admin_username'] ?? '');
    $admMail = trim($_POST['admin_email'] ?? '');
    $admPass = (string) ($_POST['admin_password'] ?? '');

    if ($dbName === '' || $dbUser === '') $errors[] = 'Informe os dados do banco.';
    if ($admName === '' || $admUser === '' || $admMail === '') $errors[] = 'Informe os dados do administrador.';
    if (strlen($admPass) < 8) $errors[] = 'A senha do administrador deve ter pelo menos 8 caracteres.';

    if (!$errors) {
        try {
            $pdo = new PDO(
                "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Executa os schemas (núcleo primeiro, depois módulos)
            $files = array_merge(
                [APP_DIR . '/sql/schema.sql'],
                glob(APP_DIR . '/sql/modules/*.sql') ?: []
            );
            foreach ($files as $file) {
                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new RuntimeException("Não foi possível ler {$file}");
                }
                // Executa statement a statement (parser do núcleo: respeita strings e comentários)
                foreach (Core\Migrations::split($sql) as $stmt) {
                    $pdo->exec($stmt);
                }
            }

            // Instalação nova: o schema já está atualizado — registra todas as
            // migrações como aplicadas (Administração → Atualizações de banco).
            $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
                filename VARCHAR(150) PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                notes TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $mark = $pdo->prepare("INSERT IGNORE INTO schema_migrations (filename, notes) VALUES (?, 'instalação')");
            foreach (glob(APP_DIR . '/sql/migrations/*.sql') ?: [] as $mf) {
                $mark->execute([basename($mf)]);
            }

            // Administrador inicial (substitui o seed padrão)
            $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
            $stmt->execute([$admUser, $admMail]);
            $hash = password_hash($admPass, PASSWORD_BCRYPT, ['cost' => 12]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $pdo->prepare('UPDATE users SET name = ?, username = ?, email = ?, password_hash = ?, is_admin = 1, active = 1 WHERE id = ?')
                    ->execute([$admName, $admUser, $admMail, $hash, $row['id']]);
            } else {
                $pdo->prepare('INSERT INTO users (name, username, email, password_hash, is_admin, active) VALUES (?, ?, ?, ?, 1, 1)')
                    ->execute([$admName, $admUser, $admMail, $hash]);
            }
            // Remove o admin seed se não for o mesmo
            $pdo->prepare("DELETE FROM users WHERE username = 'admin' AND email = 'admin@example.com' AND username <> ?")
                ->execute([$admUser]);

            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('org_name', ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                ->execute([$orgName]);

            // Gera config/config.php a partir do exemplo
            $template = require APP_DIR . '/config/config.example.php';
            $template['app']['name'] = $orgName;
            // app.key e cron_secret são PRESERVADOS numa reinstalação: gerar
            // novos torna ilegível toda senha cifrada no banco (SMTP, IMAP,
            // chave da IA) e quebra o agendamento do cron.
            $template['app']['key']  = (string) ($configAnterior['app']['key'] ?? '') ?: bin2hex(random_bytes(24));
            $template['cron_secret'] = (string) ($configAnterior['cron_secret'] ?? '') ?: bin2hex(random_bytes(16));
            // app.base_url gravada com o endereço REAL desta instalação. Vazia
            // (o padrão do exemplo), o portal montava toda URL absoluta a partir
            // do Host da requisição — e o link do e-mail de redefinição de
            // senha apontava para o domínio de quem pedisse o reset, com token
            // válido. Reproduzido ponta a ponta. Quem tem proxy ou domínio
            // diferente ajusta no config depois; o que não pode é ficar vazia.
            $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $hostReq = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $dirReq  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
            $template['app']['base_url'] = preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $hostReq)
                ? $esquema . '://' . $hostReq . $dirReq
                : null;
            $template['db'] = [
                'host' => $dbHost, 'port' => $dbPort, 'name' => $dbName,
                'user' => $dbUser, 'pass' => $dbPass, 'charset' => 'utf8mb4',
            ];

            $export = "<?php\n\n// Gerado pelo instalador em " . date('Y-m-d H:i:s') . "\n\nreturn " . var_export($template, true) . ";\n";
            if (!is_dir(dirname($configFile))) {
                mkdir(dirname($configFile), 0750, true);
            }
            // O retorno é conferido: escrever fora da área pública falha muito
            // mais (pasta inexistente, dono diferente, open_basedir), e dizer
            // "instalado com sucesso" sem ter gravado a configuração deixa o
            // administrador com um banco pronto e um site que só redireciona
            // para o instalador.
            if (file_put_contents($configFile, $export) === false) {
                throw new RuntimeException('O banco foi preparado, mas não foi possível gravar a '
                    . 'configuração em ' . $configFile . '. Dê permissão de escrita nessa pasta e '
                    . 'rode o instalador de novo com ?force=1.');
            }
            // A senha do banco está aqui dentro: ninguém além do dono precisa ler.
            @chmod($configFile, 0600);

            // A pasta de dados e as subpastas de runtime. Sem isto, com o
            // código fora ou em subpasta, o portal subia sem diretório de
            // logs e os primeiros erros não eram registrados em lugar nenhum.
            $dados = (string) ($template['paths']['storage'] ?? '') ?: BASE_PATH . '/storage';
            if (!str_starts_with($dados, '/') && !preg_match('#^[A-Za-z]:[\\\\/]#', $dados)) {
                $dados = BASE_PATH . '/' . ltrim($dados, '/');
            }
            foreach (['', '/logs', '/cache', '/backups', '/uploads'] as $sub) {
                if (!@is_dir($dados . $sub)) {
                    @mkdir($dados . $sub, 0770, true);
                }
            }
            if (@is_dir($dados . '/backups') && !@is_file($dados . '/backups/.htaccess')) {
                @file_put_contents($dados . '/backups/.htaccess', "Require all denied\nDeny from all\n");
            }

            $success = true;
        } catch (Throwable $e) {
            $errors[] = 'Erro: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalação — Plataforma Unificada</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>body{background:linear-gradient(135deg,#0a4a74,#0d5c8f 60%,#1178b5);min-height:100vh}</style>
</head>
<body class="d-flex align-items-center justify-content-center py-4">
<div class="card shadow" style="width:640px;max-width:95vw">
    <div class="card-body p-4">
        <h1 class="h4 mb-1"><i class="bi bi-grid-3x3-gap-fill me-2"></i>Plataforma Unificada</h1>
        <p class="text-muted">Instalação — Documentos, Comunicação, RH, Manutenção, Intranet e Planejamento com login único.</p>

        <?php foreach ($errors as $err): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <strong>Instalação concluída!</strong>
                <p class="mb-2 mt-2">
                    A configuração foi gravada em
                    <code><?= htmlspecialchars($configFile, ENT_QUOTES) ?></code>
                    <?php if ($configFora): ?>
                        — <strong>fora da área pública</strong>, que é onde ela deve ficar: nenhuma URL
                        alcança esse arquivo, nem que o servidor web pare de processar PHP.
                    <?php else: ?>
                        — <strong>dentro da área pública</strong>, porque não foi possível criar a pasta
                        <code><?= htmlspecialchars($configIrmao !== '' ? $configIrmao : '(fora do site)', ENT_QUOTES) ?></code>. Enquanto o PHP
                        executa, um acesso direto devolve página em branco; mas no dia em que ele parar de
                        processar <code>.php</code>, sai o fonte com a senha do banco. Se puder, crie
                        aquela pasta e mova o arquivo para lá.
                    <?php endif; ?>
                </p>
                <ol class="mb-0 mt-2">
                    <li>Apague o arquivo <code>install.php</code> do servidor.</li>
                    <li><a href="index.php">Acesse a plataforma</a> com o usuário administrador criado.</li>
                    <li>Configure a integração Moodle e o e-mail no arquivo de configuração, se desejar.</li>
                    <li>Em <em>Administração › Checkup</em>, use <strong>Testar exposição das pastas</strong>
                        para confirmar que nada sensível é entregue pela web.</li>
                </ol>
            </div>
        <?php else: ?>
            <form method="post">
                <h2 class="h6 text-uppercase text-muted mt-3">Banco de dados (MySQL/MariaDB)</h2>
                <div class="row g-2">
                    <div class="col-8"><label class="form-label">Host</label><input class="form-control" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost') ?>"></div>
                    <div class="col-4"><label class="form-label">Porta</label><input class="form-control" name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306') ?>"></div>
                    <div class="col-12"><label class="form-label">Banco *</label><input class="form-control" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" required></div>
                    <div class="col-6"><label class="form-label">Usuário *</label><input class="form-control" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required></div>
                    <div class="col-6"><label class="form-label">Senha</label><input type="password" class="form-control" name="db_pass"></div>
                </div>

                <h2 class="h6 text-uppercase text-muted mt-4">Organização</h2>
                <input class="form-control" name="org_name" placeholder="Nome exibido no topo" value="<?= htmlspecialchars($_POST['org_name'] ?? '') ?>">

                <h2 class="h6 text-uppercase text-muted mt-4">Administrador global</h2>
                <div class="row g-2">
                    <div class="col-12"><label class="form-label">Nome *</label><input class="form-control" name="admin_name" value="<?= htmlspecialchars($_POST['admin_name'] ?? '') ?>" required></div>
                    <div class="col-6"><label class="form-label">Usuário *</label><input class="form-control" name="admin_username" value="<?= htmlspecialchars($_POST['admin_username'] ?? '') ?>" required></div>
                    <div class="col-6"><label class="form-label">E-mail *</label><input type="email" class="form-control" name="admin_email" value="<?= htmlspecialchars($_POST['admin_email'] ?? '') ?>" required></div>
                    <div class="col-12"><label class="form-label">Senha * (mín. 8)</label><input type="password" class="form-control" name="admin_password" minlength="8" required></div>
                </div>

                <button class="btn btn-primary w-100 mt-4"><i class="bi bi-rocket-takeoff me-1"></i>Instalar</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
