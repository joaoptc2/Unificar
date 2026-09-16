<?php
/**
 * ============================================================
 * PLATAFORMA UNIFICADA — Bootstrap do núcleo
 * Carrega configuração, autoloader das classes Core\*,
 * sessão única e helpers globais (prefixados com core_).
 * ============================================================
 */

declare(strict_types=1);

if (defined('CORE_BOOTSTRAPPED')) {
    return;
}
define('CORE_BOOTSTRAPPED', true);

/**
 * A raiz servida pela web, quando quem chamou não soube dizer.
 *
 * Só é usada fora do front controller — pela linha de comando, onde não
 * existe requisição nenhuma de onde deduzir. Os degraus vão do mais
 * explícito ao mais antigo, e o primeiro que servir vence.
 *
 * O degrau da pasta irmã é o INVERSO do que o localizar.php faz: lá se vai
 * de "Unificar" para "Unificar-codigo"; aqui se volta. A convenção do
 * sufixo é a mesma que o config já usa há tempos ("-config"), para o
 * administrador não ter de aprender duas.
 */
function unificar_raiz_publica(string $app): string
{
    // 1. Dito explicitamente. É o degrau de quem tem um arranjo próprio.
    $env = getenv('UNIFICAR_PUBLIC');
    if ($env !== false && $env !== '' && @is_dir($env)) {
        return rtrim($env, '/');
    }

    // 2. Convenção: o código está em "<raiz>-codigo", a raiz é "<raiz>".
    //    Só vale se o lugar de volta realmente parecer uma instalação — um
    //    index.php lá dentro. Sem essa conferência, uma pasta chamada
    //    "backup-codigo" apontaria para "backup" e o sistema passaria a
    //    gravar upload dentro dela.
    if (str_ends_with($app, '-codigo')) {
        $publico = substr($app, 0, -strlen('-codigo'));
        if (@is_file($publico . '/index.php')) {
            return $publico;
        }
    }

    // 3. Arrumação de sempre: o código E a área pública são a mesma pasta.
    return $app;
}

/**
 * DUAS RAÍZES, e é preciso saber qual é qual.
 *
 * APP_PATH  — onde o CÓDIGO mora: core/, modules/, sql/, docs/, scripts/.
 * BASE_PATH — a raiz da instalação SERVIDA PELA WEB: index.php, assets/,
 *             uploads/. É dela que saem as URLs, e é dela que derivam a
 *             pasta irmã do config e a pasta irmã dos backups.
 *
 * Enquanto tudo mora junto, as duas são a MESMA STRING, e é por isso que
 * dava para viver com uma constante só. Quando o código vai para uma pasta
 * irmã, elas se separam — e todo uso de BASE_PATH que na verdade queria
 * dizer "onde está o código" passaria a apontar para o lugar errado. São
 * exatamente quatro, e estão marcados com APP_PATH abaixo.
 *
 * BASE_PATH NÃO é mais derivada daqui. Ela é derivada de onde está o
 * index.php — que é a definição de "raiz servida" — e o front controller a
 * define antes de nos chamar (ver localizar.php). Esta ordem importa: se
 * BASE_PATH continuasse sendo dirname(__DIR__), mover core/ mudaria o valor
 * dela SEM ninguém editar uma linha, e junto mudariam a pasta do config, a
 * pasta dos backups e o destino dos uploads. Nenhuma dessas mudanças daria
 * erro; todas dariam resultado errado em silêncio.
 */
define('CORE_PATH', __DIR__);
define('APP_PATH', dirname(__DIR__));

if (!defined('BASE_PATH')) {
    // Chegamos aqui sem passar pelo front controller: linha de comando
    // (cron, scripts/) ou um include direto. Descobrir a raiz servida.
    define('BASE_PATH', unificar_raiz_publica(APP_PATH));
}

define('MODULES_PATH', APP_PATH . '/modules');
define('UPLOADS_PATH', BASE_PATH . '/uploads');
// STORAGE_PATH é definida DEPOIS da configuração (ela pode apontar a pasta
// para fora do public_html). Ver "Onde ficam config e storage", abaixo.

// ---- Autoloader das classes Core\* ------------------------------------
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Core\\')) {
        $file = CORE_PATH . '/src/' . substr($class, 5) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// ---- Onde ficam config e storage ---------------------------------------
/**
 * O arquivo de configuração pode morar FORA da área pública. Motivo: ele
 * guarda a senha do banco, a app.key, o segredo do cron e a senha do SMTP.
 * Enquanto o PHP executa, um acesso direto a config.php devolve página em
 * branco (o arquivo só faz `return [...]`); no dia em que o PHP parar de
 * processar .php — troca de versão, vhost novo mal configurado, handler
 * perdido numa migração de hospedagem — sai o fonte inteiro em texto puro.
 *
 * A busca vai do mais explícito ao mais antigo, e o PRIMEIRO que existir
 * vence. Cada degrau é protegido por @is_readable: em hospedagem com
 * open_basedir, subir um nível pode simplesmente não ser permitido, e um
 * warning aqui viraria página em branco — o tratador de exceções ainda nem
 * foi instalado neste ponto do arquivo.
 */
$candidatosConfig = [];

// 1. Constante definida antes deste require. É o degrau de teste e de
//    front-controllers alternativos; não depende de ambiente nenhum.
if (defined('UNIFICAR_CONFIG')) {
    $candidatosConfig[] = ['constante UNIFICAR_CONFIG', (string) UNIFICAR_CONFIG];
}
// 2. Variável de ambiente (SetEnv no Apache, env do PHP-FPM, systemd).
if (($env = getenv('UNIFICAR_CONFIG')) !== false && $env !== '') {
    $candidatosConfig[] = ['variável de ambiente UNIFICAR_CONFIG', $env];
}
// 3. Pasta irmã da instalação, com o nome DERIVADO da pasta pública.
//    Não é "../config": em hospedagem com addon domains, dirname(BASE_PATH)
//    é o home da conta, compartilhado por vários sites — uma pasta chamada
//    só "config" colidiria entre duas instalações, e em silêncio.
$candidatosConfig[] = [
    'pasta irmã',
    dirname(BASE_PATH) . '/' . basename(BASE_PATH) . '-config/config.php',
];
// 4. O lugar de sempre, dentro da área pública.
$candidatosConfig[] = ['pasta config/ da instalação', BASE_PATH . '/config/config.php'];

$configFile = null;
$configOrigem = '';
$configIlegivel = null;
foreach ($candidatosConfig as [$rotulo, $caminho]) {
    // EXISTIR é is_file(); is_readable() só decide se dá para LER. Tratar
    // "existe mas não consigo ler" como "não existe" mandava o site para o
    // instalador — oferecendo reinstalação por cima de uma instalação viva
    // por causa de uma permissão errada.
    if ($caminho !== '' && @is_file($caminho)) {
        if (@is_readable($caminho)) {
            $configFile   = $caminho;
            $configOrigem = $rotulo;
        } else {
            $configIlegivel = $caminho;
        }
        break;
    }
}

if ($configFile === null && $configIlegivel !== null) {
    // Morrer com a causa é melhor que redirecionar para o instalador.
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("A configuração existe em {$configIlegivel}, mas o servidor web não consegue lê-la.\n"
       . "Corrija o dono ou a permissão desse arquivo. O instalador NÃO será oferecido: "
       . "reinstalar por cima apagaria a instalação atual.\n");
}

if ($configFile === null) {
    // Sem configuração: manda para o instalador (quando em contexto web)
    if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        header('Location: install.php');
        exit;
    }
    // Pela linha de comando o fallback para o exemplo CONTINUA existindo (o
    // instalador precisa dele), mas não em silêncio: sem este aviso, um cron
    // da hospedagem passaria a rodar contra o banco de exemplo e a falhar por
    // um motivo que ninguém liga ao arquivo que sumiu.
    $configFile   = APP_PATH . '/config/config.example.php';
    $configOrigem = 'EXEMPLO (nenhuma configuração encontrada)';
    if (PHP_SAPI === 'cli' && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        fwrite(STDERR, "AVISO: nenhum config.php encontrado; usando config.example.php. "
                     . "As rotinas vão rodar contra o banco de exemplo.\n");
    }
}

define('CONFIG_FILE', $configFile);
define('CONFIG_PATH', dirname($configFile));
define('CONFIG_ORIGEM', $configOrigem);

Core\Config::load(require $configFile);

/**
 * A pasta de dados (logs, cache, backups, anexos privados) também pode ficar
 * fora da área pública — e o ganho aqui é MAIOR que o do config: backup, log
 * e anexo não são arquivos .php, então nenhum interpretador se mete no
 * caminho. Entre a internet e um dump completo do banco existe só a
 * configuração do servidor web, que falha em silêncio (o Nginx ignora
 * .htaccess sem um aviso sequer).
 *
 * O caminho relativo é resolvido a partir de BASE_PATH; o absoluto vale como
 * está. Um caminho configurado que não existe NÃO é inventado aqui: cair
 * de volta no padrão é melhor que gravar backup num lugar que ninguém
 * procura. O checkup avisa quando isso acontece.
 */
$storageCfg = trim((string) Core\Config::get('paths.storage', ''));
if ($storageCfg !== '') {
    $abs = (str_starts_with($storageCfg, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $storageCfg))
        ? $storageCfg
        : BASE_PATH . '/' . ltrim($storageCfg, '/');
    $abs = rtrim($abs, '/');
    if (!@is_dir($abs)) {
        // NÃO voltar para BASE_PATH/storage. Cair para dentro da área pública
        // é exatamente o estado que esta configuração existe para evitar: o
        // administrador apontou os dados para fora, o disco não respondeu, e
        // o sistema passaria a espalhar atestado e backup no public_html sem
        // ninguém notar. Falhar a gravação é visível; vazar não é.
        //
        // Este error_log sai ANTES do ini_set('error_log') abaixo, então vai
        // para o log do servidor (Apache/FPM) — que é onde uma falha de boot
        // deve aparecer, e não dentro da pasta que está fora do ar.
        error_log('CRÍTICO: paths.storage = ' . $abs . ' não existe ou não está acessível. '
                . 'As gravações de dados vão falhar até o caminho voltar.');
    }
    define('STORAGE_PATH', $abs);
    define('STORAGE_CONFIGURADO', $storageCfg);
} else {
    define('STORAGE_PATH', BASE_PATH . '/storage');
    define('STORAGE_CONFIGURADO', '');
}

date_default_timezone_set(Core\Config::get('app.timezone', 'America/Sao_Paulo'));

// ---- Erros / debug -----------------------------------------------------
if (Core\Config::get('app.debug', false)) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
}
ini_set('log_errors', '1');
// A pasta de logs é criada se faltar. Antes nada a criava: com a pasta de
// dados apontada para um lugar novo (ou numa instalação em que ela nunca
// existiu), o PHP simplesmente PARAVA DE REGISTRAR erros, em silêncio — e o
// primeiro sintoma seria um problema de produção sem nenhum rastro para
// investigar. O @ é deliberado: falhar ao criar não pode derrubar o boot.
// Só cria a SUBPASTA de logs, e só quando a pasta de dados já existe. Um
// mkdir recursivo aqui criaria a própria pasta de dados — inclusive quando o
// caminho configurado tem um erro de digitação, que é justamente o caso em
// que o sistema precisa reclamar em vez de inventar um diretório novo e
// gravar lá dentro em silêncio.
if (@is_dir(STORAGE_PATH) && !@is_dir(STORAGE_PATH . '/logs')) {
    @mkdir(STORAGE_PATH . '/logs', 0770);
}
ini_set('error_log', STORAGE_PATH . '/logs/php_errors.log');

/**
 * Último recurso: uma exceção que escape até aqui vira uma página legível, e
 * não um 500 em branco.
 *
 * O caso que motivou isto é o banco fora do ar: quem já está logado batia em
 * Auth::user() → PDOException e recebia uma tela vazia, sem nenhuma pista do
 * que houve. Com debug ligado o erro continua aparecendo inteiro.
 */
if (PHP_SAPI !== 'cli') {
    set_exception_handler(static function (\Throwable $e): void {
        error_log('não tratada: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
        if (headers_sent()) {
            return;
        }
        http_response_code(500);
        if (Core\Config::get('app.debug', false)) {
            echo '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES) . '</pre>';
            return;
        }
        $indisponivel = $e instanceof \PDOException;
        try {
            Core\Layout::renderError(
                500,
                $indisponivel
                    ? 'O sistema está temporariamente indisponível (falha ao falar com o banco de dados). '
                      . 'Tente novamente em alguns minutos; se continuar, avise o suporte de TI.'
                    : 'Ocorreu um erro inesperado. O ocorrido foi registrado para o suporte de TI.'
            );
        } catch (\Throwable) {
            // Nem o layout conseguiu: texto puro, mas nunca uma página vazia.
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
               . '<title>Sistema indisponível</title></head><body style="font-family:system-ui;padding:2rem">'
               . '<h1>Sistema temporariamente indisponível</h1>'
               . '<p>Tente novamente em alguns minutos. Se continuar, avise o suporte de TI.</p>'
               . '</body></html>';
        }
    });
}

// ---- URL base ----------------------------------------------------------
if (!defined('BASE_URL')) {
    $configured = Core\Config::get('app.base_url');
    if ($configured) {
        define('BASE_URL', rtrim((string) $configured, '/'));
    } elseif (PHP_SAPI === 'cli') {
        define('BASE_URL', '');
    } else {
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $https ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        define('BASE_URL', $scheme . '://' . $host . $dir);
    }
}

// ---- Headers de segurança + sessão (somente web) ------------------------
if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Redirecionamento para https e HSTS, quando ligados em Administração >
    // Configurações. Vem ANTES da sessão: não faz sentido abrir sessão numa
    // requisição que está prestes a ser redirecionada.
    Core\Https::enforce();
    Core\Session::start();
}

require_once CORE_PATH . '/helpers.php';
