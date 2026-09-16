<?php

declare(strict_types=1);

namespace Core;

use PDO;
use RuntimeException;

/**
 * Motor de backup da plataforma: empacota arquivos + banco em um único
 * .tar.gz, com manifesto, e confere o resultado antes de dar o backup por
 * pronto.
 *
 * Por que NÃO usamos ZipArchive: ZipArchive::addFile() carimba o tamanho do
 * arquivo no momento da chamada e só lê o conteúdo no close(). Um arquivo que
 * mude entre os dois momentos (um upload sobrescrito, um log girando) entra
 * TRUNCADO no zip, sem erro nenhum. Aqui o pacote é escrito por um escritor
 * tar próprio, em streaming: cada entrada é lida e escrita na hora, byte a
 * byte, e o tamanho gravado é o que realmente foi escrito.
 *
 * Ordem de trabalho, e a razão dela:
 *
 *  1. ARQUIVOS primeiro, banco depois. Assim o descasamento possível é
 *     "arquivo no pacote sem a linha correspondente" (inofensivo: um anexo
 *     órfão) e nunca "linha sem arquivo" (link quebrado na tela do usuário).
 *  2. Banco em seguida, por Core\BackupDump (snapshot consistente).
 *  3. manifest.json por último, com sha256 e tamanho de cada entrada.
 *  4. VERIFICAÇÃO: o pacote é reaberto e cada entrada é conferida contra o
 *     manifesto. Falhou, o arquivo é descartado.
 *  5. Só então o pacote sai de tmp/ e ganha o nome definitivo. Backup que não
 *     terminou não pode ter nome de backup pronto.
 *
 * O nome leva 32 hexadecimais aleatórios: se o .htaccess do diretório for
 * ignorado pelo servidor (acontece), a URL do pacote continua não sendo
 * adivinhável.
 *
 * Não existe tabela de backups no banco de propósito: ela seria apagada pela
 * própria restauração. A fonte da verdade é o DIRETÓRIO; o index.json ao lado
 * é só um atalho para a listagem e é reconstruído a partir dos manifestos.
 */
final class Backup
{
    public const PREFIXO = 'backup-';
    public const EXT     = '.tar.gz';

    /**
     * Versão do formato do pacote (entra no manifesto).
     *
     * /2 difere de /1 em uma coisa: a lista de entradas saiu do manifest.json
     * e virou uma entrada própria do pacote (entradas.ndjson, uma linha JSON
     * por entrada). Num pacote de 40.000 arquivos a lista tem dezenas de MB —
     * dentro do manifesto ela precisava caber INTEIRA na memória três vezes
     * (array, JSON serializado e a cópia da conferência). Em NDJSON ela é
     * escrita e relida em fluxo, e a memória para de crescer com o número de
     * arquivos. Pacotes /1 continuam sendo lidos e restaurados normalmente.
     */
    public const FORMATO = 'unificar-backup/2';

    /** Nome da entrada que guarda a lista de entradas (formato /2). */
    public const ENTRADAS = 'entradas.ndjson';

    private const INDICE   = 'index.json';
    private const LOCK     = 'backup.lock';
    private const BLOCO    = 512;      // bloco do tar
    private const CHUNK    = 262144;   // leitura/escrita em blocos de 256 KB

    /** Modo dos arquivos que o motor grava (o mesmo dos pacotes). */
    private const MODO_ARQUIVO = 0640;

    /** Quantos exemplos cada aviso agregado carrega. */
    private const AVISOS_EXEMPLOS = 5;

    /** Idade padrão (horas) a partir da qual um resto em tmp/ é lixo. */
    private const TMP_HORAS = 6;

    /** Padrões de retenção (sobrescritíveis por Settings/Config backup.*). */
    private const RET_DIARIOS  = 7;
    private const RET_SEMANAIS = 4;
    private const RET_MENSAIS  = 6;
    private const RET_MB       = 2048;

    /** Memória de dirInfo() (a decisão envolve mkdir/is_writable: não repete). */
    private static ?array $dirCache = null;

    // =================================================================
    // Diretório
    // =================================================================

    /** Diretório dos pacotes. Ver dirInfo() para a regra de escolha. */
    public static function dir(): string
    {
        return (string) self::dirInfo()['caminho'];
    }

    /**
     * ONDE os pacotes ficam e POR QUÊ.
     *
     * A ordem é esta, e a razão de cada degrau:
     *
     *  1. backup.path do config — manda em tudo. Quem escreveu essa linha
     *     sabe onde quer os pacotes (um disco montado, um diretório de
     *     backup da hospedagem) e o motor não discute.
     *
     *  2. O diretório IRMÃO da instalação (BASE_PATH/../backups), quando dá
     *     para criar e gravar. É o padrão desde a revisão de segurança: o
     *     pacote traz o banco inteiro (hashes de senha, prontuário, anexos) e
     *     dentro do webroot a única barreira é o .htaccess — que o nginx
     *     ignora por completo e o Apache ignora quando AllowOverride está
     *     desligado. Fora do webroot não existe URL que chegue ao arquivo.
     *
     *  3. storage/backups, só quando o irmão não é criável/gravável (conta
     *     travada na raiz, open_basedir). Aí o .htaccess volta a ser a única
     *     proteção — e isso é dito em voz alta na saída do CLI.
     *
     * @return array{caminho:string, origem:string, motivo:string,
     *               dentro_do_webroot:bool, legado:array{caminho:string, pacotes:int}|null}
     */
    public static function dirInfo(): array
    {
        $cfg = trim((string) Config::get('backup.path', ''));
        if (self::$dirCache !== null && (string) self::$dirCache['config'] === $cfg) {
            return self::$dirCache;
        }

        $storage = STORAGE_PATH . '/backups';
        $irmao   = dirname(BASE_PATH) . '/backups';

        if ($cfg !== '') {
            $p = $cfg;
            // Caminho relativo é relativo à raiz da instalação (hospedagem
            // compartilhada costuma dar um diretório fora do webroot, ex.: '../backups').
            if (!str_starts_with($p, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $p)) {
                $p = BASE_PATH . '/' . $p;
            }
            $info = [
                'caminho' => rtrim($p, '/\\'),
                'origem'  => 'config',
                'motivo'  => 'backup.path está definido em config/config.php e tem prioridade sobre tudo.',
            ];
        } elseif (self::utilizavel($irmao)) {
            $info = [
                'caminho' => $irmao,
                'origem'  => 'irmao',
                'motivo'  => 'diretório irmão da instalação, FORA do webroot: nenhuma URL chega até ele, '
                           . 'nem que o servidor ignore o .htaccess.',
            ];
        } else {
            // A pasta de dados pode ter sido movida para fora da área pública
            // (paths.storage no config.php). Afirmar "DENTRO do webroot" sem
            // conferir era verdade antes e virou mentira depois da mudança —
            // e uma mentira tranquilizadora é pior que um aviso a mais.
            $storageFora = !str_starts_with(rtrim($storage, '/') . '/', BASE_PATH . '/');
            $info = [
                'caminho' => $storage,
                'origem'  => 'storage',
                'motivo'  => $irmao . ' não pôde ser criado ou não é gravável, então os pacotes ficam na '
                           . 'pasta de dados. '
                           . ($storageFora
                                ? 'Ela está FORA da área pública, então nenhuma URL chega até eles.'
                                : 'Ela está DENTRO da área pública e a única proteção é o .htaccess (que o '
                                  . 'nginx ignora e o Apache só aplica com AllowOverride ligado). Assim que '
                                  . "puder, aponte backup.path ou paths.storage para fora."),
            ];
        }

        $info['dentro_do_webroot'] = str_starts_with($info['caminho'] . '/', BASE_PATH . '/');

        // Pacotes que ficaram no lugar antigo depois da mudança de padrão: a
        // listagem só enxerga o diretório em uso, e um backup que o admin
        // acha que tem mas não aparece é pior que não ter nenhum.
        $info['legado'] = null;
        if ($info['caminho'] !== $storage && is_dir($storage)) {
            $n = count(glob($storage . '/' . self::PREFIXO . '*' . self::EXT) ?: []);
            if ($n > 0) {
                $info['legado'] = ['caminho' => $storage, 'pacotes' => $n];
            }
        }

        $info['config'] = $cfg;
        self::$dirCache = $info;
        return $info;
    }

    /** O diretório existe (ou pôde ser criado) e aceita escrita? */
    private static function utilizavel(string $dir): bool
    {
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return false;
        }
        return is_dir($dir) && is_writable($dir);
    }

    /** Esquece a decisão de dirInfo() (usado quando o config muda em execução). */
    public static function esqueceDir(): void
    {
        self::$dirCache = null;
    }

    /** Diretório de trabalho (pacotes incompletos e trava). */
    public static function tmpDir(): string
    {
        return self::dir() . '/tmp';
    }

    /**
     * Garante o diretório, o .htaccess e o .gitkeep.
     *
     * O .htaccess é gravado com o MESMO padrão de storage/.htaccess: um
     * "Require all denied" solto quebra em Apache 2.2, que ainda aparece em
     * hospedagem compartilhada.
     */
    public static function ensureDir(): void
    {
        foreach ([self::dir(), self::tmpDir()] as $d) {
            if (!is_dir($d) && !@mkdir($d, 0770, true) && !is_dir($d)) {
                throw new RuntimeException('Não foi possível criar o diretório de backups: ' . $d);
            }
        }
        $ht = self::dir() . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, self::htaccess());
        }
        $gk = self::dir() . '/.gitkeep';
        if (!is_file($gk)) {
            @file_put_contents($gk, '');
        }
    }

    // =================================================================
    // Limpeza do tmp/
    // =================================================================

    /**
     * Apaga de tmp/ o que sobrou de execuções que morreram no meio.
     *
     * POR QUÊ ISTO EXISTE: o diretório de trabalho é apagado num finally, e
     * finally NÃO RODA quando o processo morre de fatal do PHP (memória
     * estourada), de kill do supervisor ou de queda da máquina. Cada morte
     * dessas deixa em tmp/ um backup-<id>/ com o database.sql inteiro e SEM
     * COMPRESSÃO — em base de hospital isso é a maior coisa do disco. Duas ou
     * três mortes enchem a cota da conta e aí nenhum backup novo consegue ser
     * escrito, justamente quando mais se precisa dele.
     *
     * Só apaga o que está PARADO há horas. Um backup em andamento dura
     * minutos; e a idade considerada é a do arquivo mais recente lá dentro,
     * não a do diretório — o mtime do diretório não muda enquanto o
     * database.sql cresce, e sem isso uma restauração demorada poderia
     * varrer a si mesma.
     *
     * @param int|null $idadeSegundos idade mínima (padrão: backup.tmp_horas, 6 h)
     * @return array{diretorios:int, bytes:int, nomes:string[]}
     */
    public static function limpaTmp(?int $idadeSegundos = null, ?array &$avisos = null): array
    {
        $tmp = self::tmpDir();
        $out = ['diretorios' => 0, 'bytes' => 0, 'nomes' => []];
        if (!is_dir($tmp)) {
            return $out;
        }

        $idade = $idadeSegundos ?? max(1, (int) self::conf('backup.tmp_horas', (string) self::TMP_HORAS)) * 3600;
        $corte = time() - $idade;

        foreach (scandir($tmp) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            // Só os nossos: backup-<id>/ e restore-<hex>/. Qualquer outra
            // coisa em tmp/ foi alguém que pôs lá, e não é nossa para apagar.
            if (!str_starts_with($item, self::PREFIXO) && !str_starts_with($item, 'restore-')) {
                continue;
            }
            $p = $tmp . '/' . $item;
            if (!is_dir($p) || is_link($p)) {
                continue;
            }
            if (self::mtimeRecente($p) > $corte) {
                continue; // ainda pode estar em uso
            }
            $bytes = self::pesoDe($p);
            self::rmTree($p);
            if (is_dir($p)) {
                continue; // não conseguiu apagar (permissão): não conta como recuperado
            }
            $out['diretorios']++;
            $out['bytes'] += $bytes;
            if (count($out['nomes']) < self::AVISOS_EXEMPLOS) {
                $out['nomes'][] = $item;
            }
        }

        if ($out['diretorios'] > 0 && $avisos !== null) {
            $avisos[] = 'Limpeza do tmp/: ' . $out['diretorios'] . ' diretório(s) de execução interrompida foram '
                . 'apagados e ' . self::humano($out['bytes']) . ' voltaram para o disco ('
                . implode(', ', $out['nomes']) . ($out['diretorios'] > count($out['nomes']) ? ', …' : '')
                . '). Eles ficam para trás quando o PHP morre de fatal ou o processo é morto — e cada um guardava '
                . 'o database.sql SEM COMPRESSÃO. Se isto se repetir, veja no log por que a execução anterior morreu.';
        }

        return $out;
    }

    /** mtime mais recente da árvore (o diretório sozinho não acusa arquivo crescendo). */
    private static function mtimeRecente(string $dir, int $limite = 2000): int
    {
        $maior = (int) @filemtime($dir);
        $pilha = [$dir];
        $vistos = 0;
        while ($pilha !== [] && $vistos < $limite) {
            $d = array_pop($pilha);
            foreach (scandir($d) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $vistos++;
                $p = $d . '/' . $item;
                $m = (int) @filemtime($p);
                if ($m > $maior) {
                    $maior = $m;
                }
                if (is_dir($p) && !is_link($p)) {
                    $pilha[] = $p;
                }
            }
        }
        return $maior;
    }

    /** Soma dos bytes de uma árvore (para dizer quanto a limpeza recuperou). */
    private static function pesoDe(string $dir, int $limite = 20000): int
    {
        $bytes = 0;
        $pilha = [$dir];
        $vistos = 0;
        while ($pilha !== [] && $vistos < $limite) {
            $d = array_pop($pilha);
            foreach (scandir($d) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $vistos++;
                $p = $d . '/' . $item;
                if (is_link($p)) {
                    continue;
                }
                if (is_dir($p)) {
                    $pilha[] = $p;
                    continue;
                }
                $bytes += (int) @filesize($p);
            }
        }
        return $bytes;
    }

    // =================================================================
    // Avisos agregados
    // =================================================================

    /**
     * Junta avisos repetitivos num só.
     *
     * Um aviso por arquivo que sumiu (ou por linha grande) é a mesma doença
     * que a lista de entradas em memória: com 40.000 arquivos são 40.000
     * strings guardadas até o fim, e o admin não lê nenhuma delas. Fica a
     * CONTA e alguns exemplos.
     *
     * @param array<string, array{n:int, exemplos:string[]}> $grupos
     */
    private static function agrega(array &$grupos, string $assunto, string $exemplo): void
    {
        if (!isset($grupos[$assunto])) {
            $grupos[$assunto] = ['n' => 0, 'exemplos' => []];
        }
        $grupos[$assunto]['n']++;
        if (count($grupos[$assunto]['exemplos']) < self::AVISOS_EXEMPLOS) {
            $grupos[$assunto]['exemplos'][] = $exemplo;
        }
    }

    /**
     * Despeja os avisos agregados na lista final.
     *
     * @param array<string, array{n:int, exemplos:string[]}> $grupos
     * @param string[] $avisos
     */
    private static function despeja(array $grupos, array &$avisos): void
    {
        foreach ($grupos as $assunto => $g) {
            $sobra = $g['n'] - count($g['exemplos']);
            $avisos[] = $g['n'] . ' ' . $assunto . ' — ex.: ' . implode('; ', $g['exemplos'])
                . ($sobra > 0 ? ' (e mais ' . $sobra . ')' : '') . '.';
        }
    }

    /** Bytes em texto curto (para mensagens). */
    private static function humano(int $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $b;
        while ($v >= 1024 && $i < count($u) - 1) {
            $v /= 1024;
            $i++;
        }
        return number_format($v, $i === 0 ? 0 : 1, ',', '.') . ' ' . $u[$i];
    }

    /** Conteúdo do .htaccess do diretório de backups. */
    public static function htaccess(): string
    {
        return <<<HT
        # Os pacotes de backup contêm o banco inteiro (inclusive hashes de
        # senha) e os arquivos enviados. Nada aqui é servido pelo servidor web.
        # O .htaccess da raiz já bloqueia /storage, esta é a segunda barreira
        # para quando o mod_rewrite não estiver disponível.
        <IfModule mod_authz_core.c>
            Require all denied
        </IfModule>
        <IfModule !mod_authz_core.c>
            Order allow,deny
            Deny from all
        </IfModule>

        HT;
    }

    // =================================================================
    // Criação
    // =================================================================

    /**
     * Gera um backup completo.
     *
     * Opções:
     *   files (bool=true)          incluir uploads/ e storage/uploads/
     *   database (bool=true)       incluir o dump do banco
     *   include_config (bool=false) incluir config/config.php (SEGREDOS!)
     *   roots (string[])           raízes de arquivo (relativas à instalação)
     *   retencao (bool=true)       aplicar a política de retenção ao final
     *   motivo (string)            texto livre gravado no manifesto
     *
     * @return array{id:string, arquivo:string, caminho:string, bytes:int,
     *               manifesto:array, avisos:string[], segundos:float}
     */
    public static function create(array $opts = []): array
    {
        $inicio = microtime(true);
        self::ensureDir();

        $lock = self::lock();
        try {
            return self::criar($opts, $inicio);
        } finally {
            self::unlock($lock);
        }
    }

    /** @return array{id:string, arquivo:string, caminho:string, bytes:int, manifesto:array, avisos:string[], segundos:float} */
    private static function criar(array $opts, float $inicio): array
    {
        $avisos = [];
        $grupos = [];
        self::folego($avisos);

        // Antes de escrever o primeiro byte: recolhe o que execuções mortas
        // deixaram em tmp/. Se a cota já estiver cheia por causa delas, o
        // backup que começa agora não teria para onde ir.
        self::limpaTmp(null, $avisos);

        $comArquivos = (bool) ($opts['files'] ?? true);
        $comBanco    = (bool) ($opts['database'] ?? true);
        $comConfig   = (bool) ($opts['include_config'] ?? false);

        $id      = self::novoId();
        $trabalho = self::tmpDir() . '/' . $id;
        if (!@mkdir($trabalho, 0770, true) && !is_dir($trabalho)) {
            throw new RuntimeException('Não foi possível criar o diretório de trabalho: ' . $trabalho);
        }

        $pacote  = $trabalho . '/pacote' . self::EXT;
        $listaNd = $trabalho . '/' . self::ENTRADAS;
        $dump     = null;
        $arqStats = ['total' => 0, 'bytes' => 0, 'diretorios' => 0];
        $totais   = ['entradas' => 0, 'bytes' => 0];

        // A lista de entradas é escrita em NDJSON (uma linha JSON por entrada)
        // CONFORME o pacote é escrito, e entra no pacote como uma entrada
        // própria. Guardá-la em memória custa ~2 KB por arquivo: 40.000
        // anexos são 80 MB só de lista, mais o JSON serializado, mais a cópia
        // da conferência. Em NDJSON o custo de memória é o de UMA linha.
        $nd = @fopen($listaNd, 'wb');
        if ($nd === false) {
            self::rmTree($trabalho);
            throw new RuntimeException('Não foi possível abrir a lista de entradas para escrita: ' . $listaNd);
        }
        $anota = static function (?array $entrada) use ($nd, $listaNd, &$totais): void {
            if ($entrada === null) {
                return; // entrada que não entrou no pacote (arquivo sumiu)
            }
            $linha = json_encode($entrada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($linha === false) {
                throw new RuntimeException('Não foi possível anotar a entrada ' . ($entrada['nome'] ?? '?')
                    . ' na lista: ' . json_last_error_msg());
            }
            $linha .= "\n";
            $total = strlen($linha);
            $pos   = 0;
            while ($pos < $total) {
                $n = @fwrite($nd, $pos === 0 ? $linha : substr($linha, $pos));
                if ($n === false || $n === 0) {
                    throw new RuntimeException('Falha ao escrever ' . basename($listaNd)
                        . ' (disco cheio ou cota da conta estourada).');
                }
                $pos += $n;
            }
            $totais['entradas']++;
            $totais['bytes'] += (int) ($entrada['bytes'] ?? 0);
        };

        try {
            $gz = @gzopen($pacote, 'wb6');
            if ($gz === false) {
                throw new RuntimeException('Não foi possível abrir o pacote para escrita: ' . $pacote);
            }
            // Escritor com retorno conferido: cota estourada devolve escrita
            // curta, não exceção (ver nota no topo da classe).
            $escreve = static function (string $s) use ($gz): void {
                if ($s === '') {
                    return;
                }
                $n = @gzwrite($gz, $s);
                if ($n === false || $n !== strlen($s)) {
                    throw new RuntimeException(
                        'Falha ao escrever no pacote: gravou ' . var_export($n, true) . ' de ' . strlen($s)
                        . ' bytes (disco cheio ou cota da conta estourada).'
                    );
                }
            };

            $falha = null;
            try {
                // ---- 1) ARQUIVOS primeiro (ver nota no topo) -------------
                if ($comArquivos) {
                    $raizes = $opts['roots'] ?? ['uploads', 'storage/uploads'];
                    foreach ($raizes as $raiz) {
                        self::empacotaRaiz($escreve, (string) $raiz, $anota, $arqStats, $grupos);
                    }
                }
                if ($comConfig) {
                    // O arquivo REALMENTE em uso, que pode estar fora da área
                    // pública. Antes isto era um caminho fixo: com o config
                    // movido, o pacote saía sem ele e sem uma linha de
                    // reclamação — o administrador só descobria na hora de
                    // restaurar, que é a pior hora possível.
                    $cfg = defined('CONFIG_FILE') ? CONFIG_FILE : BASE_PATH . '/config/config.php';
                    if (is_file($cfg)) {
                        $anota(self::tarArquivo($escreve, 'arquivos/config/config.php', $cfg, $grupos));
                        $avisos[] = 'ATENÇÃO: a configuração (' . $cfg . ') foi incluída no pacote. '
                            . 'Ela traz a senha do banco e a chave do aplicativo — guarde este backup como segredo.';
                    } else {
                        $avisos[] = 'A configuração foi pedida no pacote mas NÃO foi encontrada em ' . $cfg
                            . ' — o backup saiu sem ela.';
                    }
                }

                // ---- 2) Banco --------------------------------------------
                if ($comBanco) {
                    $sql = $trabalho . '/database.sql';
                    $fh  = @fopen($sql, 'wb');
                    if ($fh === false) {
                        throw new RuntimeException('Não foi possível abrir ' . $sql . ' para escrita.');
                    }
                    // Fecha SEMPRE, mas sem deixar a falha do fclose apagar a
                    // exceção original: quem precisa do motivo é o admin.
                    $falhaDump = null;
                    try {
                        $dump = BackupDump::dump($fh, $opts['dump'] ?? []);
                    } catch (\Throwable $e) {
                        $falhaDump = $e;
                    }
                    $fechou = @fclose($fh);
                    if ($falhaDump !== null) {
                        throw $falhaDump;
                    }
                    if (!$fechou) {
                        throw new RuntimeException('Falha ao fechar o dump (escrita pendente pode ter se perdido).');
                    }
                    clearstatcache(true, $sql);
                    if ((int) filesize($sql) !== $dump['bytes']) {
                        throw new RuntimeException(
                            'O dump gravado (' . filesize($sql) . ' bytes) não bate com o que foi escrito ('
                            . $dump['bytes'] . ' bytes).'
                        );
                    }
                    foreach ($dump['avisos'] as $a) {
                        $avisos[] = $a;
                    }
                    // Gerado em diretório temporário estável e SÓ ENTÃO
                    // empacotado: nada mexe neste arquivo entre uma coisa e outra.
                    $anota(self::tarArquivo($escreve, 'database.sql', $sql, $grupos));
                }

                // ---- 3) Lista de entradas (penúltima entrada) ------------
                // Fechada ANTES de ser empacotada: o que o zlib ainda não
                // despejou no disco não estaria no tar.
                if (!fclose($nd)) {
                    $nd = null;
                    throw new RuntimeException('Falha ao fechar a lista de entradas (a escrita pode ter se perdido).');
                }
                $nd = null;
                // A própria lista não se descreve: quem a confere é o
                // manifesto, que guarda o sha256 e o tamanho dela.
                $entradaLista = self::tarArquivo($escreve, self::ENTRADAS, $listaNd, $grupos);
                if ($entradaLista === null) {
                    throw new RuntimeException('A lista de entradas sumiu antes de entrar no pacote.');
                }

                // ---- 4) Manifesto (por último) ---------------------------
                self::despeja($grupos, $avisos);
                $grupos = [];
                $manifesto = self::manifesto(
                    $id,
                    $entradaLista,
                    $totais,
                    $dump,
                    $arqStats,
                    $comArquivos,
                    $comConfig,
                    $avisos,
                    $opts
                );
                // O manifesto é a lista completa de avisos (ele acrescenta os
                // seus): quem chamou precisa ver os mesmos que ficaram gravados.
                $avisos = $manifesto['avisos'];
                $json = json_encode($manifesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    throw new RuntimeException('Não foi possível gerar o manifesto: ' . json_last_error_msg());
                }
                self::tarDados($escreve, 'manifest.json', $json);

                // Dois blocos zerados fecham o tar.
                $escreve(str_repeat("\0", self::BLOCO * 2));
            } catch (\Throwable $e) {
                $falha = $e;
            }
            // O gzclose é o que despeja o buffer do zlib no disco — é aqui que
            // "disco cheio" costuma aparecer. Fecha sempre, mas se já havia um
            // erro, é o erro original que sobe: ele é que explica o problema.
            $fechou = @gzclose($gz);
            if ($falha !== null) {
                throw $falha;
            }
            if (!$fechou) {
                throw new RuntimeException('Falha ao fechar o pacote (os dados podem não ter chegado ao disco).');
            }

            clearstatcache(true, $pacote);
            $tamanho = (int) @filesize($pacote);
            if ($tamanho <= 0) {
                throw new RuntimeException('O pacote ficou vazio.');
            }

            // ---- 5) Verificação obrigatória ------------------------------
            // A lista de entradas ainda está aqui em tmp/: a conferência lê o
            // pacote e a lista LADO A LADO, em fluxo, sem carregar nenhuma
            // das duas na memória.
            $conf = self::verify($pacote, $manifesto, $listaNd);
            if (!$conf['ok']) {
                throw new RuntimeException('O pacote não passou na conferência: ' . implode(' | ', $conf['erros']));
            }

            // ---- 6) O pacote é RESTAURÁVEL? ------------------------------
            // Um comando maior que o max_allowed_packet do destino derruba a
            // conexão no meio da carga. Entregar isto como "backup pronto" é
            // pior que não ter backup: ninguém desconfia até o dia em que
            // precisa dele. O pacote é recusado AQUI, e não na restauração.
            $maior = (int) ($manifesto['banco']['maior_comando'] ?? 0);
            $packet = (int) ($manifesto['banco']['sessao']['max_allowed_packet'] ?? 0);
            if ($maior > 0 && $packet > 0 && $maior > $packet) {
                throw new RuntimeException(
                    'O pacote foi RECUSADO: o maior comando do dump tem ' . self::humano($maior)
                    . ' e o max_allowed_packet do servidor é ' . self::humano($packet)
                    . '. Uma restauração deste arquivo morreria no meio ("Got a packet bigger than '
                    . 'max_allowed_packet"). Aumente o max_allowed_packet e gere o backup de novo, '
                    . 'ou reduza a linha responsável (veja os avisos do dump).'
                );
            }

            // ---- 7) Nome definitivo (só agora) ---------------------------
            $destino  = self::dir() . '/' . $id . self::EXT;
            $destMan  = self::dir() . '/' . $id . '.manifest.json';

            self::gravaAtomico($destMan, $json);
            if (!@rename($pacote, $destino)) {
                @unlink($destMan);
                throw new RuntimeException('Não foi possível mover o pacote para ' . $destino);
            }
            @chmod($destino, self::MODO_ARQUIVO);
            @chmod($destMan, self::MODO_ARQUIVO);
        } finally {
            if (is_resource($nd)) {
                @fclose($nd);
            }
            self::rmTree($trabalho);
        }

        self::indiceAtualiza();

        if ((bool) ($opts['retencao'] ?? true)) {
            $ret = self::retention();
            if ($ret['apagados'] !== []) {
                $avisos[] = 'Retenção removeu ' . count($ret['apagados']) . ' pacote(s) antigo(s).';
            }
        }

        // audit_log.entity_id é varchar(40) e o id do pacote tem 55 caracteres.
        // Com STRICT_TRANS_TABLES o INSERT morre com 1406 (Data too long) e o
        // Core\Audit engole a exceção no log de erros: o resultado era que
        // NENHUM backup ficava auditado. Vai o prefixo de 40 (que já carrega
        // data, hora e o começo do sorteio) e o id INTEIRO nos detalhes.
        Audit::log('backup.create', 'backup', substr($id, 0, 40), [
            'pacote'   => $id,
            'bytes'    => $tamanho,
            'tabelas'  => $dump !== null ? count($dump['tabelas']) : 0,
            'arquivos' => $arqStats['total'],
            'avisos'   => count($avisos),
        ], null, 'core');

        return [
            'id'        => $id,
            'arquivo'   => $id . self::EXT,
            'caminho'   => $destino,
            'bytes'     => $tamanho,
            'manifesto' => $manifesto,
            'avisos'    => $avisos,
            'segundos'  => round(microtime(true) - $inicio, 3),
        ];
    }

    /** Pede mais tempo ao PHP; se a hospedagem não deixar, avisa em vez de morrer no meio. */
    private static function folego(array &$avisos): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        $limite = (int) ini_get('max_execution_time');
        if ($limite > 0 && $limite < 120 && PHP_SAPI !== 'cli') {
            $avisos[] = 'max_execution_time está em ' . $limite . 's e não pôde ser aumentado: '
                . 'em bases grandes o backup pode ser interrompido. Prefira rodar pelo cron (php scripts/backup.php).';
        }
        // disk_free_space não enxerga a COTA da conta em hospedagem
        // compartilhada: serve como alerta, nunca como garantia.
        $livre = @disk_free_space(self::dir());
        if (is_float($livre) && $livre > 0 && $livre < 52428800) {
            $avisos[] = 'O sistema de arquivos informa menos de 50 MB livres. '
                . '(A cota da conta pode ser ainda menor — este número não a enxerga.)';
        }
    }

    // =================================================================
    // Empacotamento (tar em streaming)
    // =================================================================

    /**
     * @param callable(?array):void $anota grava a entrada na lista (NDJSON)
     * @param array{total:int, bytes:int, diretorios:int} $stats
     * @param array<string, array{n:int, exemplos:string[]}> $grupos
     */
    private static function empacotaRaiz(
        callable $escreve,
        string $raiz,
        callable $anota,
        array &$stats,
        array &$grupos
    ): void {
        $base = self::caminhoFisico(trim($raiz, '/'));
        if (!is_dir($base)) {
            return;
        }
        $backupReal = realpath(self::dir()) ?: self::dir();

        foreach (self::caminha(rtrim($base, '/'), '', $backupReal, $grupos) as $item) {
            $nome = 'arquivos/' . trim($raiz, '/') . ($item['rel'] === '' ? '' : '/' . $item['rel']);
            if ($item['dir']) {
                self::tarCabecalho($escreve, $nome . '/', 0, $item['mtime'], '5', 0750);
                $anota(['nome' => $nome . '/', 'bytes' => 0, 'sha256' => hash('sha256', ''), 'tipo' => 'dir']);
                $stats['diretorios']++;
                continue;
            }
            $entrada = self::tarArquivo($escreve, $nome, $item['path'], $grupos);
            if ($entrada === null) {
                continue; // sumiu entre a listagem e a cópia: já virou aviso
            }
            $anota($entrada);
            $stats['total']++;
            $stats['bytes'] += $entrada['bytes'];
        }
    }

    /**
     * Percorre recursivamente arquivos e diretórios (inclusive os ocultos,
     * que é onde moram os .htaccess que protegem uploads/).
     *
     * É um GERADOR, e não uma lista: em 40.000 anexos a lista pronta já
     * custava megabytes antes de o primeiro byte entrar no pacote. A ordem
     * continua estável porque scandir() devolve cada diretório em ordem
     * alfabética e a descida é sempre na mesma sequência — dois backups da
     * mesma árvore geram o mesmo pacote.
     *
     * @param array<string, array{n:int, exemplos:string[]}> $grupos
     * @return \Generator<int, array{path:string, rel:string, dir:bool, mtime:int}>
     */
    private static function caminha(string $base, string $rel, string $backupReal, array &$grupos): \Generator
    {
        $dir   = $rel === '' ? $base : $base . '/' . $rel;
        $itens = @scandir($dir);
        if ($itens === false) {
            self::agrega($grupos, 'diretório(s) não puderam ser lidos e ficaram de fora do pacote', self::relativo($dir));
            return;
        }

        foreach ($itens as $nome) {
            if ($nome === '.' || $nome === '..') {
                continue;
            }
            $path = $dir . '/' . $nome;
            $r    = $rel === '' ? $nome : $rel . '/' . $nome;

            // Link simbólico sai fora: seguir um deles pode puxar um diretório
            // inteiro de fora da instalação para dentro do pacote.
            if (is_link($path)) {
                self::agrega($grupos, 'link(s) simbólico(s) ignorado(s) no backup', self::relativo($path));
                continue;
            }
            // Nunca empacota o próprio diretório de backups (recursão infinita
            // quando backup.path aponta para dentro de uploads/).
            $real = realpath($path);
            if ($real !== false && ($real === $backupReal || str_starts_with($real, $backupReal . '/'))) {
                continue;
            }
            if (!is_readable($path)) {
                self::agrega($grupos, 'arquivo(s) sem permissão de leitura ficaram de fora', self::relativo($path));
                continue;
            }

            if (is_dir($path)) {
                yield ['path' => $path, 'rel' => $r, 'dir' => true, 'mtime' => (int) @filemtime($path)];
                yield from self::caminha($base, $r, $backupReal, $grupos);
                continue;
            }
            yield ['path' => $path, 'rel' => $r, 'dir' => false, 'mtime' => (int) @filemtime($path)];
        }
    }

    /**
     * Grava um arquivo do disco dentro do tar, em streaming.
     *
     * Devolve null quando o arquivo SUMIU entre a listagem e a hora de
     * copiá-lo. Num hospital isso é rotina — anexo apagado às três da manhã,
     * log que gira, fila que se limpa — e derrubar o backup inteiro por causa
     * de um arquivo a menos troca "backup com um anexo faltando" por "nenhum
     * backup", que é muito pior. O que NÃO vira aviso: arquivo que existe e
     * não pode ser lido (permissão, disco com erro) — isso é problema de
     * verdade e continua estourando o backup.
     *
     * @param array<string, array{n:int, exemplos:string[]}> $grupos
     * @return array{nome:string, bytes:int, sha256:string, tipo:string, mtime:int}|null
     */
    private static function tarArquivo(
        callable $escreve,
        string $nome,
        string $caminho,
        array &$grupos
    ): ?array {
        // Abrir PRIMEIRO e medir pelo descritor: entre um filesize() e um
        // fopen() cabe uma exclusão, e o tamanho carimbado no cabeçalho
        // precisa ser o do arquivo que estamos realmente lendo.
        $fh = @fopen($caminho, 'rb');
        if ($fh === false) {
            clearstatcache(true, $caminho);
            if (!file_exists($caminho)) {
                self::agrega(
                    $grupos,
                    'arquivo(s) sumiram entre a listagem e a cópia e ficaram de fora do pacote',
                    self::relativo($caminho)
                );
                return null;
            }
            throw new RuntimeException(
                'O arquivo ' . self::relativo($caminho) . ' existe mas não pôde ser aberto para leitura '
                . '(permissão, disco com erro ou open_basedir). O backup foi interrompido: '
                . 'copiar a instalação pela metade em silêncio é o defeito que este motor existe para evitar.'
            );
        }

        $st      = @fstat($fh);
        $tamanho = (int) ($st['size'] ?? 0);
        $mtime   = (int) ($st['mtime'] ?? 0);

        try {
            self::tarCabecalho($escreve, $nome, $tamanho, $mtime, '0', self::MODO_ARQUIVO);

            $ctx  = hash_init('sha256');
            $lido = 0;
            while ($lido < $tamanho) {
                $bloco = fread($fh, (int) min(self::CHUNK, $tamanho - $lido));
                if ($bloco === false || $bloco === '') {
                    break;
                }
                $escreve($bloco);
                hash_update($ctx, $bloco);
                $lido += strlen($bloco);
            }

            // O tamanho foi carimbado no cabeçalho ANTES da leitura: se o
            // arquivo encolheu no meio do caminho (log girando, upload
            // sobrescrito), completamos com zeros para não desalinhar o tar —
            // e o enchimento ENTRA no sha256, para que a entrada continue
            // conferindo consigo mesma. O que aconteceu fica escrito no aviso;
            // o que não pode acontecer é o pacote dizer uma coisa e trazer outra.
            if ($lido !== $tamanho) {
                $zeros = str_repeat("\0", $tamanho - $lido);
                $escreve($zeros);
                hash_update($ctx, $zeros);
                self::agrega(
                    $grupos,
                    'arquivo(s) encolheram durante a cópia e entraram completados com zeros',
                    self::relativo($caminho) . ' (' . $lido . ' de ' . $tamanho . ' bytes)'
                );
            }
            self::tarPadding($escreve, $tamanho);
        } finally {
            fclose($fh);
        }

        // Cresceu ou foi reescrito no meio da leitura: o que entrou no pacote
        // é consistente (o tamanho carimbado foi lido inteiro), mas pode ser
        // uma versão antiga ou parcial. Isso vai para os avisos — quem
        // restaurar precisa saber quais arquivos estavam em movimento.
        clearstatcache(true, $caminho);
        if (file_exists($caminho)
            && ((int) @filesize($caminho) !== $tamanho || (int) @filemtime($caminho) !== $mtime)) {
            self::agrega(
                $grupos,
                'arquivo(s) mudaram durante o backup; o pacote guarda a versão do início da cópia',
                self::relativo($caminho)
            );
        }

        return [
            'nome'   => $nome,
            'bytes'  => $tamanho,
            'sha256' => hash_final($ctx),
            'tipo'   => 'arquivo',
            'mtime'  => $mtime,
        ];
    }

    /** Grava um conteúdo já em memória dentro do tar. */
    private static function tarDados(callable $escreve, string $nome, string $dados): array
    {
        $tamanho = strlen($dados);
        self::tarCabecalho($escreve, $nome, $tamanho, time(), '0', self::MODO_ARQUIVO);
        $escreve($dados);
        self::tarPadding($escreve, $tamanho);

        return [
            'nome'   => $nome,
            'bytes'  => $tamanho,
            'sha256' => hash('sha256', $dados),
            'tipo'   => 'arquivo',
            'mtime'  => time(),
        ];
    }

    private static function tarPadding(callable $escreve, int $tamanho): void
    {
        $resto = $tamanho % self::BLOCO;
        if ($resto !== 0) {
            $escreve(str_repeat("\0", self::BLOCO - $resto));
        }
    }

    /**
     * Cabeçalho ustar de 512 bytes (com extensão GNU para nomes longos).
     */
    private static function tarCabecalho(callable $escreve, string $nome, int $tamanho, int $mtime, string $tipo, int $modo): void
    {
        $nome   = ltrim(str_replace('\\', '/', $nome), '/');
        $prefixo = '';
        $curto   = $nome;

        if (strlen($nome) > 100) {
            // ustar divide o caminho em prefixo (155) + nome (100).
            $corte = false;
            for ($i = strlen($nome) - 101; $i < strlen($nome); $i++) {
                if ($i > 0 && $nome[$i] === '/' && strlen(substr($nome, $i + 1)) <= 100 && $i <= 155) {
                    $corte = $i;
                    break;
                }
            }
            if ($corte !== false) {
                $prefixo = substr($nome, 0, $corte);
                $curto   = substr($nome, $corte + 1);
            } else {
                // Não cabe nem dividido: entrada GNU LongName antes da real.
                $dados = $nome . "\0";
                self::tarCabecalho($escreve, '././@LongLink', strlen($dados), $mtime, 'L', 0644);
                $escreve($dados);
                self::tarPadding($escreve, strlen($dados));
                $curto = substr($nome, 0, 100);
            }
        }

        $h = pack('a100', $curto)
           . pack('a8', sprintf('%07o', $modo))
           . pack('a8', sprintf('%07o', 0))
           . pack('a8', sprintf('%07o', 0))
           . pack('a12', sprintf('%011o', $tamanho))
           . pack('a12', sprintf('%011o', max(0, $mtime)))
           . '        '                      // espaço reservado da soma
           . $tipo
           . pack('a100', '')                // linkname
           . 'ustar' . "\0" . '00'
           . pack('a32', 'root')
           . pack('a32', 'root')
           . pack('a8', '')
           . pack('a8', '')
           . pack('a155', $prefixo)
           . pack('a12', '');

        $soma = 0;
        for ($i = 0; $i < self::BLOCO; $i++) {
            $soma += ord($h[$i]);
        }
        $h = substr_replace($h, sprintf('%06o', $soma) . "\0 ", 148, 8);

        $escreve($h);
    }

    // =================================================================
    // Verificação
    // =================================================================

    /**
     * Reabre o pacote e confere CADA entrada contra a lista de entradas.
     *
     * É o que separa "o backup rodou" de "o backup existe": tamanho e sha256
     * são recalculados lendo o artefato do disco, não reaproveitados da
     * memória de quem o escreveu.
     *
     * A conferência é INCREMENTAL: o pacote e a lista (entradas.ndjson) são
     * lidos lado a lado, na mesma ordem em que foram escritos, uma entrada de
     * cada vez. Antes, as duas coisas viravam arrays inteiros na memória — um
     * pacote de 40.000 arquivos não passava de 64 MB. Agora o custo é o de
     * uma linha, seja o pacote de 10 ou de 400.000 entradas.
     *
     * @param string|null $listaNd caminho da lista já pronta em disco (quem
     *        acabou de gerar o pacote a tem ali e não precisa reextraí-la)
     * @return array{ok:bool, erros:string[], entradas:int, bytes:int}
     */
    public static function verify(string $pacote, ?array $manifesto = null, ?string $listaNd = null): array
    {
        $erros = [];
        $doProprioPacote = $manifesto === null;

        if ($manifesto === null) {
            $manifesto = self::manifestoDoPacote($pacote);
            if ($manifesto === null) {
                return ['ok' => false, 'erros' => ['O pacote não tem manifest.json legível.'], 'entradas' => 0, 'bytes' => 0];
            }
        }

        // De onde vem a lista esperada:
        //  - a que acabamos de escrever (create), quando informada;
        //  - a entrada entradas.ndjson do pacote (formato /2);
        //  - manifesto['entradas'], em pacotes do formato /1 (antigos).
        $temporaria = null;
        $lista      = $listaNd !== null && is_file($listaNd) ? $listaNd : null;
        $legado     = [];

        if ($lista === null && isset($manifesto['entradas_arquivo'])) {
            $temporaria = self::extraiPara($pacote, self::ENTRADAS);
            if ($temporaria === null) {
                return [
                    'ok' => false,
                    'erros' => ['O manifesto declara ' . self::ENTRADAS . ', mas essa entrada não está no pacote.'],
                    'entradas' => 0,
                    'bytes' => 0,
                ];
            }
            $lista = $temporaria;
        } elseif ($lista === null) {
            $legado = array_values((array) ($manifesto['entradas'] ?? []));
        }

        $cursor = self::cursorEntradas($lista, $legado);
        $vistos = 0;
        $bytes  = 0;
        $shaManifesto = null;
        $viuLista     = false;

        try {
            self::tarLe(
                $pacote,
                static function (string $nome, int $tamanho, string $sha, string $tipo) use (
                    $cursor, &$erros, &$vistos, &$bytes, &$shaManifesto, &$viuLista, $manifesto
                ): void {
                    $vistos++;
                    $bytes += $tamanho;

                    if ($nome === 'manifest.json') {
                        $shaManifesto = $sha;
                        return;
                    }
                    if ($nome === self::ENTRADAS) {
                        // A lista não se descreve: quem a confere é o manifesto.
                        $viuLista = true;
                        $decl = (array) ($manifesto['entradas_arquivo'] ?? []);
                        if ($decl !== []) {
                            if ((int) ($decl['bytes'] ?? -1) !== $tamanho) {
                                $erros[] = self::ENTRADAS . ': tamanho ' . $tamanho . ' ≠ '
                                    . (int) ($decl['bytes'] ?? -1) . ' do manifesto';
                            }
                            if (!hash_equals((string) ($decl['sha256'] ?? ''), $sha)) {
                                $erros[] = self::ENTRADAS . ': sha256 não confere com o manifesto';
                            }
                        }
                        return;
                    }

                    $e = $cursor->proxima($nome);
                    if ($e === null) {
                        $erros[] = 'entrada a mais no pacote (não está na lista de entradas): ' . $nome;
                        return;
                    }
                    if ((int) ($e['bytes'] ?? -1) !== $tamanho) {
                        $erros[] = $nome . ': tamanho ' . $tamanho . ' ≠ ' . (int) ($e['bytes'] ?? -1) . ' da lista';
                    }
                    if (!hash_equals((string) ($e['sha256'] ?? ''), $sha)) {
                        $erros[] = $nome . ': sha256 não confere';
                    }
                }
            );
        } catch (\Throwable $e) {
            $cursor->fecha();
            if ($temporaria !== null) {
                @unlink($temporaria);
            }
            return ['ok' => false, 'erros' => ['Não foi possível reler o pacote: ' . $e->getMessage()], 'entradas' => 0, 'bytes' => 0];
        }

        // Sobrou entrada na lista que não apareceu no pacote.
        foreach ($cursor->faltantes() as $nome) {
            $erros[] = 'faltou no pacote: ' . $nome;
        }
        $conferidas = $cursor->consumidas();
        $cursor->fecha();
        if ($temporaria !== null) {
            @unlink($temporaria);
        }

        if ($lista !== null && !$viuLista) {
            $erros[] = 'faltou no pacote: ' . self::ENTRADAS;
        }
        $totalDeclarado = (int) ($manifesto['entradas_arquivo']['total'] ?? -1);
        if ($totalDeclarado >= 0 && $totalDeclarado !== $conferidas) {
            $erros[] = 'o manifesto declara ' . $totalDeclarado . ' entrada(s) e o pacote trouxe ' . $conferidas . '.';
        }

        // O manifesto não pode conferir a si mesmo pelo sha256 (ele estaria
        // dentro de si). Conferimos que existe e que é o mesmo JSON.
        if ($shaManifesto === null) {
            $erros[] = 'faltou no pacote: manifest.json';
        } elseif (!$doProprioPacote) {
            // Só faz sentido comparar quando o manifesto veio de FORA (o que
            // acabamos de gerar, ou o arquivo ao lado): se ele saiu de dentro
            // do pacote, comparar com ele mesmo não prova nada.
            $json = json_encode($manifesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false && !hash_equals(hash('sha256', $json), $shaManifesto)) {
                $erros[] = 'manifest.json dentro do pacote difere do manifesto conferido';
            }
        }

        return ['ok' => $erros === [], 'erros' => $erros, 'entradas' => $vistos, 'bytes' => $bytes];
    }

    /**
     * Cursor sequencial sobre as entradas esperadas.
     *
     * O pacote e a lista são escritos na MESMA ordem, então basta andar nos
     * dois ao mesmo tempo. O cursor enxerga uma entrada à frente para
     * distinguir os dois acidentes que interessam — "faltou esta" e "veio uma
     * a mais" — sem nunca guardar a lista inteira.
     *
     * @param string|null $arquivo lista em NDJSON
     * @param array<int, array> $lista entradas do manifesto (formato /1)
     */
    private static function cursorEntradas(?string $arquivo, array $lista = []): object
    {
        return new class ($arquivo, $lista) {
            /** @var resource|null */
            private $fh;
            private array $lista;
            private int $i = 0;
            private array $buffer = [];      // até duas entradas lidas à frente
            private int $consumidas = 0;
            private array $faltantes = [];   // nomes que a lista tinha e o pacote não trouxe
            private bool $erroLeitura = false;

            public function __construct(?string $arquivo, array $lista)
            {
                $this->fh    = $arquivo !== null ? (@fopen($arquivo, 'rb') ?: null) : null;
                $this->lista = $lista;
            }

            private function le(): ?array
            {
                if ($this->fh !== null) {
                    while (($l = fgets($this->fh)) !== false) {
                        $l = trim($l);
                        if ($l === '') {
                            continue;
                        }
                        $j = json_decode($l, true);
                        if (!is_array($j) || !isset($j['nome'])) {
                            $this->erroLeitura = true;
                            return null;
                        }
                        return $j;
                    }
                    return null;
                }
                return $this->lista[$this->i++] ?? null;
            }

            private function espia(int $quantas): void
            {
                while (count($this->buffer) < $quantas) {
                    $e = $this->le();
                    if ($e === null) {
                        return;
                    }
                    $this->buffer[] = $e;
                }
            }

            /** A entrada esperada para $nome, ou null se ela não está na lista. */
            public function proxima(string $nome): ?array
            {
                $this->espia(1);
                if ($this->buffer === []) {
                    return null;
                }
                if ((string) $this->buffer[0]['nome'] === $nome) {
                    $this->consumidas++;
                    return array_shift($this->buffer);
                }
                // Não bateu: ou a entrada da lista não veio no pacote, ou esta
                // do pacote não está na lista. Uma espiada resolve qual é.
                $this->espia(2);
                if (count($this->buffer) >= 2 && (string) $this->buffer[1]['nome'] === $nome) {
                    $this->faltantes[] = (string) $this->buffer[0]['nome'];
                    array_shift($this->buffer);
                    $this->consumidas++;
                    return array_shift($this->buffer);
                }
                return null;
            }

            /** @return string[] nomes esperados que o pacote não trouxe */
            public function faltantes(): array
            {
                while (($e = $this->le()) !== null) {
                    $this->faltantes[] = (string) $e['nome'];
                    if (count($this->faltantes) > 50) {
                        $this->faltantes[] = '… (e mais entradas)';
                        break;
                    }
                }
                foreach ($this->buffer as $e) {
                    $this->faltantes[] = (string) $e['nome'];
                }
                $this->buffer = [];
                if ($this->erroLeitura) {
                    $this->faltantes[] = '(a lista de entradas tem linha ilegível: pacote corrompido)';
                }
                return $this->faltantes;
            }

            public function consumidas(): int
            {
                return $this->consumidas;
            }

            public function fecha(): void
            {
                if ($this->fh !== null) {
                    @fclose($this->fh);
                    $this->fh = null;
                }
            }
        };
    }

    /**
     * Extrai UMA entrada do pacote para um arquivo temporário em tmp/.
     *
     * Serve para a lista de entradas: ela precisa estar em disco para ser
     * lida em fluxo, e um pacote grande não cabe na memória.
     */
    private static function extraiPara(string $pacote, string $entrada): ?string
    {
        self::ensureDir();
        $destino = self::tmpDir() . '/.lista-' . bin2hex(random_bytes(6)) . '.ndjson';
        $achou   = false;

        try {
            self::tarLe(
                $pacote,
                static function (): void {
                },
                static function (string $nome, string $tipo, int $tamanho) use ($entrada, $destino, &$achou) {
                    if ($nome !== $entrada) {
                        return null;
                    }
                    $achou = true;
                    return self::gravador($destino);
                }
            );
        } catch (\Throwable) {
            @unlink($destino);
            return null;
        }

        if (!$achou || !is_file($destino)) {
            @unlink($destino);
            return null;
        }
        return $destino;
    }

    /**
     * Percorre o .tar.gz chamando $fn(nome, tamanho, sha256, tipo).
     *
     * @param callable|null $extrai callable(nome, tipo, tamanho): callable(?string)|null
     *        — devolve o gravador da entrada (chamado com null ao terminar) ou
     *        null para só conferir, sem extrair.
     */
    private static function tarLe(string $pacote, callable $fn, ?callable $extrai = null): void
    {
        $gz = @gzopen($pacote, 'rb');
        if ($gz === false) {
            throw new RuntimeException('Não foi possível abrir ' . $pacote);
        }

        try {
            $nomeLongo = null;
            while (true) {
                $h = self::gzLeExato($gz, self::BLOCO);
                if ($h === '') {
                    // Sem os dois blocos zerados finais: arquivo truncado.
                    throw new RuntimeException('o pacote termina no meio (faltam os blocos de fim)');
                }
                if (strlen($h) < self::BLOCO) {
                    throw new RuntimeException('bloco final incompleto (' . strlen($h) . ' bytes)');
                }
                if (trim($h, "\0") === '') {
                    return; // fim normal
                }

                $soma = (int) octdec(trim(substr($h, 148, 8), "\0 "));
                $calc = 0;
                for ($i = 0; $i < self::BLOCO; $i++) {
                    $calc += $i >= 148 && $i < 156 ? 32 : ord($h[$i]);
                }
                if ($soma !== $calc) {
                    throw new RuntimeException('cabeçalho corrompido (soma de verificação não confere)');
                }

                $tipo    = $h[156];
                $tamanho = (int) octdec(trim(substr($h, 124, 12), "\0 "));
                $nome    = rtrim(substr($h, 0, 100), "\0");
                $prefixo = rtrim(substr($h, 345, 155), "\0");
                if ($prefixo !== '') {
                    $nome = $prefixo . '/' . $nome;
                }
                if ($nomeLongo !== null) {
                    $nome      = $nomeLongo;
                    $nomeLongo = null;
                }

                if ($tipo === 'L') { // GNU LongName
                    $nomeLongo = rtrim(self::gzLeExato($gz, $tamanho), "\0");
                    self::gzPula($gz, $tamanho);
                    continue;
                }

                $ctx    = hash_init('sha256');
                $resta  = $tamanho;
                $saida  = $extrai !== null ? $extrai($nome, $tipo, $tamanho) : null;
                while ($resta > 0) {
                    $bloco = self::gzLeExato($gz, (int) min(self::CHUNK, $resta));
                    if ($bloco === '') {
                        throw new RuntimeException('pacote truncado dentro de ' . $nome);
                    }
                    hash_update($ctx, $bloco);
                    if ($saida !== null) {
                        $saida($bloco);
                    }
                    $resta -= strlen($bloco);
                }
                if ($saida !== null) {
                    $saida(null); // fecha
                }
                self::gzPula($gz, $tamanho);

                $fn($nome, $tamanho, hash_final($ctx), $tipo);
            }
        } finally {
            @gzclose($gz);
        }
    }

    /** @param resource $gz */
    private static function gzLeExato($gz, int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $parte = gzread($gz, $n - strlen($buf));
            if ($parte === false || $parte === '') {
                break;
            }
            $buf .= $parte;
        }
        return $buf;
    }

    /** @param resource $gz */
    private static function gzPula($gz, int $tamanho): void
    {
        $resto = $tamanho % self::BLOCO;
        if ($resto !== 0) {
            self::gzLeExato($gz, self::BLOCO - $resto);
        }
    }

    // =================================================================
    // Manifesto
    // =================================================================

    /**
     * @param array<int, array> $entradas
     */
    /**
     * Monta o manifesto.
     *
     * A lista de entradas NÃO vem aqui dentro: ela vai no próprio pacote, em
     * entradas.ndjson, uma linha por arquivo. Mantê-la em memória custava
     * ~2 KB por entrada, e uma instalação com dezenas de milhares de anexos
     * estourava o memory_limit justamente na hora de fechar o backup. O que
     * o manifesto guarda é a DESCRIÇÃO dessa lista (nome, tamanho, sha256 e
     * totais) — o bastante para a conferência ser incremental.
     *
     * @param array|null $entradaLista entrada do tar referente ao entradas.ndjson
     * @param array{entradas:int, bytes:int} $totais
     */
    private static function manifesto(
        string $id,
        ?array $entradaLista,
        array $totais,
        ?array $dump,
        array $arqStats,
        bool $comArquivos,
        bool $comConfig,
        array $avisos,
        array $opts
    ): array {
        $migr = ['aplicadas' => [], 'pendentes' => []];
        try {
            $migr['aplicadas'] = array_keys(Migrations::applied());
            $migr['pendentes'] = Migrations::pending();
        } catch (\Throwable $e) {
            $avisos[] = 'Não foi possível ler o estado das migrações: ' . $e->getMessage();
        }
        if ($migr['pendentes'] !== []) {
            $avisos[] = 'Havia ' . count($migr['pendentes']) . ' migração(ões) PENDENTE(S) quando este backup foi gerado: '
                . 'o banco copiado está atrás dos arquivos da aplicação.';
        }
        if (!$comConfig) {
            $avisos[] = 'config/config.php NÃO está no pacote (contém a senha do banco e a chave do aplicativo). '
                . 'Guarde-o à parte: sem ele, restaurar exige reconfigurar a instalação.';
        }

        $tabelas = [];
        foreach ($dump['tabelas'] ?? [] as $nome => $t) {
            $tabelas[$nome] = $t;
        }

        return [
            'formato'    => self::FORMATO,
            'id'         => $id,
            'arquivo'    => $id . self::EXT,
            'criado_em'  => date('c'),
            'fuso'       => date_default_timezone_get(),
            'motivo'     => (string) ($opts['motivo'] ?? ''),
            'gerado_por' => self::quemGerou(),
            'sistema'    => [
                'aplicacao' => (string) Config::get('app.name', 'Plataforma Unificada'),
                'versao'    => (string) Config::get('app.version', 'não declarada'),
                'schema'    => $migr['aplicadas'] === [] ? '' : (string) end($migr['aplicadas']),
                'base_url'  => (string) Config::get('app.base_url', ''),
                'php'       => PHP_VERSION,
                'servidor'  => $dump['servidor']['versao'] ?? '',
                'banco'     => $dump['servidor']['banco'] ?? (string) Config::get('db.name', ''),
            ],
            'migracoes'  => $migr,
            'banco'      => [
                'incluido' => $dump !== null,
                'bytes'    => $dump['bytes'] ?? 0,
                'segundos' => $dump['segundos'] ?? 0,
                // Maior comando SQL do dump: é o que decide se este pacote é
                // restaurável no destino (ver a checagem em criar()).
                'maior_comando' => (int) ($dump['maior_comando'] ?? 0),
                'sessao'   => $dump['sessao'] ?? [],
                'objetos'  => $dump['objetos'] ?? [],
                'tabelas'  => $tabelas,
            ],
            'arquivos'   => [
                'incluidos'   => $comArquivos,
                'total'       => $arqStats['total'],
                'diretorios'  => $arqStats['diretorios'],
                'bytes'       => $arqStats['bytes'],
                'config_php'  => $comConfig,
            ],
            'digest'     => [
                'algoritmo' => 'sha256 por linha, somado em 8 palavras de 32 bits com estouro',
                'nota'      => 'A soma é ORDEM-INDEPENDENTE de propósito (a leitura pagina por chave). '
                             . 'Não é XOR: com XOR duas linhas iguais se cancelariam.',
            ],
            // A lista completa está em entradas.ndjson, dentro do pacote.
            'entradas_arquivo' => [
                'nome'         => self::ENTRADAS,
                'bytes'        => (int) ($entradaLista['bytes'] ?? 0),
                'sha256'       => (string) ($entradaLista['sha256'] ?? ''),
                'total'        => (int) $totais['entradas'],
                'bytes_totais' => (int) $totais['bytes'],
            ],
            'avisos'     => array_values(array_unique($avisos)),
        ];
    }

    /** @return array<string, string|int|null> */
    private static function quemGerou(): array
    {
        $origem = PHP_SAPI === 'cli' ? 'cli' : 'web';
        $nome   = $origem === 'cli' ? 'linha de comando' : 'desconhecido';
        $uid    = null;
        try {
            $u = Auth::user();
            if ($u !== null) {
                $nome = (string) $u['name'];
                $uid  = (int) $u['id'];
            }
        } catch (\Throwable) {
            // sem sessão/banco: fica o padrão
        }
        return ['usuario' => $nome, 'user_id' => $uid, 'origem' => $origem, 'ip' => $origem === 'web' ? Audit::ip() : ''];
    }

    /** Lê o manifest.json de dentro de um pacote. */
    public static function manifestoDoPacote(string $pacote): ?array
    {
        $json = null;
        try {
            self::tarLe(
                $pacote,
                static function (): void {
                },
                static function (string $nome, string $tipo) use (&$json) {
                    if ($nome !== 'manifest.json') {
                        return null;
                    }
                    $buf = '';
                    return static function (?string $bloco) use (&$buf, &$json): void {
                        if ($bloco === null) {
                            $json = $buf;
                            return;
                        }
                        $buf .= $bloco;
                    };
                }
            );
        } catch (\Throwable) {
            return null;
        }
        if ($json === null) {
            return null;
        }
        $m = json_decode($json, true);
        return is_array($m) ? $m : null;
    }

    // =================================================================
    // Listagem / índice
    // =================================================================

    /**
     * Pacotes existentes, do mais novo para o mais antigo.
     *
     * A fonte da verdade é o diretório. O index.json só evita reler manifesto
     * por manifesto e é refeito sozinho quando não bate.
     *
     * @return array<int, array>
     */
    public static function list(bool $reconstruir = false): array
    {
        if (!is_dir(self::dir())) {
            return [];
        }
        $indice = $reconstruir ? [] : self::indiceLe();
        $itens  = [];
        $mudou  = false;

        foreach (glob(self::dir() . '/' . self::PREFIXO . '*' . self::EXT) ?: [] as $caminho) {
            $arquivo = basename($caminho);
            $id      = substr($arquivo, 0, -strlen(self::EXT));
            clearstatcache(true, $caminho);
            $bytes = (int) @filesize($caminho);
            $mtime = (int) @filemtime($caminho);

            $cache = $indice['itens'][$arquivo] ?? null;
            if (is_array($cache) && (int) ($cache['bytes'] ?? -1) === $bytes && (int) ($cache['mtime'] ?? -1) === $mtime) {
                $itens[] = $cache;
                continue;
            }

            $mudou = true;
            $itens[] = self::resumo($id, $caminho, $bytes, $mtime);
        }

        usort($itens, static fn ($a, $b) => strcmp((string) $b['id'], (string) $a['id']));

        // Some do índice o que sumiu do disco.
        if (count($indice['itens'] ?? []) !== count($itens)) {
            $mudou = true;
        }
        if ($mudou) {
            self::indiceGrava($itens);
        }
        return $itens;
    }

    /** @return array<string, mixed> */
    private static function resumo(string $id, string $caminho, int $bytes, int $mtime): array
    {
        $man = null;
        $lado = self::dir() . '/' . $id . '.manifest.json';
        if (is_file($lado)) {
            $j = json_decode((string) @file_get_contents($lado), true);
            $man = is_array($j) ? $j : null;
        }
        if ($man === null) {
            // Sem o manifesto ao lado (apagado, copiado só o .tar.gz):
            // o pacote ainda carrega o seu dentro.
            $man = self::manifestoDoPacote($caminho);
        }

        $linhas = 0;
        foreach ($man['banco']['tabelas'] ?? [] as $t) {
            $linhas += (int) ($t['linhas'] ?? 0);
        }

        return [
            'id'         => $id,
            'arquivo'    => basename($caminho),
            'caminho'    => $caminho,
            'bytes'      => $bytes,
            'mtime'      => $mtime,
            'criado_em'  => (string) ($man['criado_em'] ?? date('c', $mtime)),
            'gerado_por' => (string) ($man['gerado_por']['usuario'] ?? '—'),
            'motivo'     => (string) ($man['motivo'] ?? ''),
            'tabelas'    => count($man['banco']['tabelas'] ?? []),
            'linhas'     => $linhas,
            'arquivos'   => (int) ($man['arquivos']['total'] ?? 0),
            'com_banco'  => (bool) ($man['banco']['incluido'] ?? false),
            'com_config' => (bool) ($man['arquivos']['config_php'] ?? false),
            'avisos'     => (int) count($man['avisos'] ?? []),
            'manifesto'  => $man !== null,
        ];
    }

    public static function find(string $id): ?array
    {
        $id = self::normalizaId($id);
        if ($id === null) {
            return null;
        }
        foreach (self::list() as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
        return null;
    }

    /** Manifesto completo de um backup (do arquivo ao lado ou de dentro do pacote). */
    public static function manifest(string $id): ?array
    {
        $item = self::find($id);
        if ($item === null) {
            return null;
        }
        $lado = self::dir() . '/' . $item['id'] . '.manifest.json';
        if (is_file($lado)) {
            $j = json_decode((string) @file_get_contents($lado), true);
            if (is_array($j)) {
                return $j;
            }
        }
        return self::manifestoDoPacote($item['caminho']);
    }

    public static function delete(string $id): bool
    {
        $item = self::find($id);
        if ($item === null) {
            return false;
        }
        $ok = @unlink($item['caminho']);
        @unlink(self::dir() . '/' . $item['id'] . '.manifest.json');
        if ($ok) {
            self::indiceAtualiza();
            // entity_id é varchar(40) e o id do pacote tem 55 caracteres: sem
            // o corte, o INSERT estoura com 1406 e o Audit engole a exceção —
            // exclusões deixavam de ser registradas em silêncio.
            Audit::log('backup.delete', 'backup', substr((string) $item['id'], 0, 40),
                ['pacote' => $item['id'], 'bytes' => $item['bytes']], null, 'core');
        }
        return $ok;
    }

    /** Só aceita o formato dos nossos nomes (evita ../ e vizinhos). */
    private static function normalizaId(string $id): ?string
    {
        $id = basename(trim($id));
        if (str_ends_with($id, self::EXT)) {
            $id = substr($id, 0, -strlen(self::EXT));
        }
        if (str_ends_with($id, '.manifest.json')) {
            $id = substr($id, 0, -strlen('.manifest.json'));
        }
        return preg_match('/^' . preg_quote(self::PREFIXO, '/') . '\d{8}-\d{6}-[0-9a-f]{32}$/', $id) === 1 ? $id : null;
    }

    private static function novoId(): string
    {
        // 32 hexadecimais: se o .htaccess for ignorado pelo servidor, a URL do
        // pacote continua não sendo adivinhável por quem passar raspando.
        return self::PREFIXO . date('Ymd-His') . '-' . bin2hex(random_bytes(16));
    }

    /** @return array{itens: array<string, array>} */
    private static function indiceLe(): array
    {
        $f = self::dir() . '/' . self::INDICE;
        if (!is_file($f)) {
            return ['itens' => []];
        }
        $j = json_decode((string) @file_get_contents($f), true);
        if (!is_array($j) || !isset($j['itens']) || !is_array($j['itens'])) {
            return ['itens' => []];
        }
        return ['itens' => $j['itens']];
    }

    /** @param array<int, array> $itens */
    private static function indiceGrava(array $itens): void
    {
        $mapa = [];
        foreach ($itens as $i) {
            $mapa[(string) $i['arquivo']] = $i;
        }
        $json = json_encode([
            'formato'       => 'unificar-backup-indice/1',
            'atualizado_em' => date('c'),
            'itens'         => $mapa,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json !== false) {
            try {
                self::gravaAtomico(self::dir() . '/' . self::INDICE, $json);
            } catch (\Throwable $e) {
                // Índice é só atalho: sem ele a listagem relê os manifestos.
                error_log('backup: não foi possível gravar o índice: ' . $e->getMessage());
            }
        }
    }

    /** Reconstrói o índice lendo o diretório (list(true) já o regrava). */
    private static function indiceAtualiza(): void
    {
        self::list(true);
    }

    // =================================================================
    // Retenção
    // =================================================================

    /**
     * Aplica a política de retenção: TUDO dos últimos N dias, mais um por
     * semana nas N semanas anteriores, mais um por mês nos N meses anteriores,
     * e um teto total em MB.
     *
     * O mais recente NUNCA é apagado, aconteça o que acontecer com o teto —
     * ficar sem nenhum backup é pior que estourar a cota.
     *
     * @return array{apagados: string[], mantidos: int, bytes: int}
     */
    public static function retention(array $opts = []): array
    {
        $diarios  = (int) ($opts['diarios']  ?? self::conf('backup.keep_daily', (string) self::RET_DIARIOS));
        $semanais = (int) ($opts['semanais'] ?? self::conf('backup.keep_weekly', (string) self::RET_SEMANAIS));
        $mensais  = (int) ($opts['mensais']  ?? self::conf('backup.keep_monthly', (string) self::RET_MENSAIS));
        $tetoMb   = (int) ($opts['max_mb']   ?? self::conf('backup.max_mb', (string) self::RET_MB));

        $itens = self::list(); // já vem do mais novo para o mais antigo
        if ($itens === []) {
            return ['apagados' => [], 'mantidos' => 0, 'bytes' => 0];
        }

        $manter = [$itens[0]['id'] => true];
        $baldes = ['s' => [], 'm' => []];

        // Janela dos "diários": tudo o que foi gerado nos últimos N dias fica.
        // Guardar só UM por dia apagaria o backup que o admin tirou de manhã,
        // antes da atualização, assim que o da noite entrasse — justamente o
        // que ele quer de volta quando a atualização dá errado.
        $janela = $diarios > 0 ? strtotime('today -' . ($diarios - 1) . ' days') : PHP_INT_MAX;

        foreach ($itens as $i) {
            $ts = strtotime((string) $i['criado_em']) ?: (int) $i['mtime'];
            if ($ts >= $janela) {
                $manter[$i['id']] = true;
                continue;
            }
            // Fora da janela: um por semana e um por mês, sempre o mais recente
            // de cada período (a lista vem do mais novo para o mais antigo).
            foreach (['s' => ['o-W', $semanais], 'm' => ['Y-m', $mensais]] as $tipo => [$fmt, $quantos]) {
                $chave = date($fmt, $ts);
                if (count($baldes[$tipo]) >= $quantos || isset($baldes[$tipo][$chave])) {
                    continue;
                }
                $baldes[$tipo][$chave] = $i['id'];
                $manter[$i['id']] = true;
            }
        }

        $apagados = [];
        foreach ($itens as $i) {
            if (!isset($manter[$i['id']]) && self::delete((string) $i['id'])) {
                $apagados[] = (string) $i['id'];
            }
        }

        // Teto de espaço: apaga do mais antigo para o mais novo até caber.
        if ($tetoMb > 0) {
            $restantes = self::list();
            $total = 0;
            foreach ($restantes as $i) {
                $total += (int) $i['bytes'];
            }
            $teto = $tetoMb * 1024 * 1024;
            for ($k = count($restantes) - 1; $k > 0 && $total > $teto; $k--) {
                if (self::delete((string) $restantes[$k]['id'])) {
                    $apagados[] = (string) $restantes[$k]['id'];
                    $total -= (int) $restantes[$k]['bytes'];
                }
            }
        }

        $mantidos = self::list();
        $bytes = 0;
        foreach ($mantidos as $i) {
            $bytes += (int) $i['bytes'];
        }
        return ['apagados' => $apagados, 'mantidos' => count($mantidos), 'bytes' => $bytes];
    }

    // =================================================================
    // Agendamento (cron)
    // =================================================================

    /**
     * Roda o backup agendado, se for a hora.
     *
     * Regra: passou da hora marcada de hoje E o último backup tem mais de 20h.
     * As 20h (e não 24h) existem porque o cron da hospedagem atrasa: exigir
     * 24h faria o backup "pular" um dia sempre que o cron rodasse uns minutos
     * mais tarde que na véspera.
     *
     * @return array{executou:bool, motivo:string, backup?:array}
     */
    public static function runScheduled(array $opts = []): array
    {
        if (self::conf('backup.schedule_enabled', '0') !== '1') {
            return ['executou' => false, 'motivo' => 'Backup automático desligado.'];
        }

        $hora   = max(0, min(23, (int) self::conf('backup.schedule_hour', '3')));
        $minuto = max(0, min(59, (int) self::conf('backup.schedule_minute', '0')));
        $marca  = mktime($hora, $minuto, 0) ?: time();

        if (time() < $marca) {
            return ['executou' => false, 'motivo' => 'Ainda não deu a hora marcada (' . sprintf('%02d:%02d', $hora, $minuto) . ').'];
        }

        $itens = self::list();
        if ($itens !== []) {
            $ultimo = strtotime((string) $itens[0]['criado_em']) ?: (int) $itens[0]['mtime'];
            $horas  = (time() - $ultimo) / 3600;
            if ($horas < 20) {
                return ['executou' => false, 'motivo' => sprintf('O último backup tem %.1f h (mínimo 20 h).', $horas)];
            }
        }

        try {
            $r = self::create($opts + [
                'files'    => self::conf('backup.schedule_files', '1') === '1',
                'motivo'   => 'agendado',
                'retencao' => true,
            ]);
        } catch (\Throwable $e) {
            Audit::log('backup.scheduled_failed', 'backup', null, $e->getMessage(), null, 'core');
            return ['executou' => false, 'motivo' => 'Falhou: ' . $e->getMessage()];
        }

        return ['executou' => true, 'motivo' => 'Backup gerado.', 'backup' => $r];
    }

    /** Settings (banco) tem prioridade; depois config/config.php; depois o padrão. */
    private static function conf(string $chave, string $default): string
    {
        try {
            $v = Settings::get($chave);
            if ($v !== null && $v !== '') {
                return $v;
            }
        } catch (\Throwable) {
            // banco fora do ar: cai para o arquivo
        }
        $c = Config::get($chave, null);
        return $c === null || $c === '' ? $default : (string) $c;
    }

    // =================================================================
    // Restauração
    // =================================================================

    /**
     * Restaura um pacote.
     *
     * Opções: database (bool=true), files (bool=true), verify (bool=true),
     *         database_name (string) para restaurar em outro banco.
     *
     * @return array{ok:bool, banco:array, arquivos:array, avisos:string[], segundos:float}
     */
    public static function restore(string $id, array $opts = []): array
    {
        $inicio = microtime(true);
        $item   = self::find($id);
        if ($item === null) {
            throw new RuntimeException('Backup não encontrado: ' . $id);
        }
        $manifesto = self::manifest($item['id']);
        if ($manifesto === null) {
            throw new RuntimeException('O pacote não tem manifesto legível; restauração recusada.');
        }

        $avisos = [];
        self::folego($avisos);

        if ((bool) ($opts['verify'] ?? true)) {
            $conf = self::verify($item['caminho'], $manifesto);
            if (!$conf['ok']) {
                throw new RuntimeException('O pacote não confere com o manifesto: ' . implode(' | ', $conf['erros']));
            }
        }

        $comBanco    = (bool) ($opts['database'] ?? true);
        $comArquivos = (bool) ($opts['files'] ?? true);
        // config/config.php só volta se pedirem: ele traz a senha do banco do
        // AMBIENTE DE ORIGEM e sobrescrevê-lo derruba a instalação atual.
        $comConfig   = (bool) ($opts['restore_config'] ?? false);

        $trabalho = self::tmpDir() . '/restore-' . bin2hex(random_bytes(8));
        if (!@mkdir($trabalho, 0770, true) && !is_dir($trabalho)) {
            throw new RuntimeException('Não foi possível criar o diretório de restauração.');
        }

        $sqlTmp   = $trabalho . '/database.sql';
        $arquivos = ['restaurados' => 0, 'bytes' => 0, 'ignorados' => 0];
        $banco    = ['restaurado' => false, 'statements' => 0, 'tabelas_conferidas' => 0, 'divergentes' => []];

        try {
            // Extrai: o SQL para o disco, os arquivos direto para o destino
            // (cada um por tmp + rename, para nunca deixar arquivo pela metade
            // no lugar do bom).
            $esperado = [];
            foreach ($manifesto['entradas'] ?? [] as $e) {
                $esperado[(string) $e['nome']] = (string) $e['sha256'];
            }

            self::tarLe(
                $item['caminho'],
                // Confere de novo, agora com o arquivo saindo do pacote: pega
                // corrupção que tenha aparecido entre a conferência e a extração.
                static function (string $nome, int $tamanho, string $sha, string $tipo) use ($esperado): void {
                    if (isset($esperado[$nome]) && !hash_equals($esperado[$nome], $sha)) {
                        throw new RuntimeException('A entrada ' . $nome . ' saiu do pacote diferente do manifesto.');
                    }
                },
                function (string $nome, string $tipo, int $tamanho) use ($sqlTmp, $comBanco, $comArquivos, $comConfig, $trabalho, &$arquivos, &$avisos) {
                    if ($nome === 'database.sql' && $comBanco) {
                        return self::gravador($sqlTmp);
                    }
                    if ($comArquivos && str_starts_with($nome, 'arquivos/')) {
                        $rel = substr($nome, strlen('arquivos/'));
                        if ($tipo === '5') {
                            if (self::destinoSeguro(rtrim($rel, '/') . '/')) {
                                @mkdir(self::caminhoFisico(rtrim($rel, '/')), 0770, true);
                            }
                            return null;
                        }
                        if (str_starts_with($rel, 'config/') && !$comConfig) {
                            $avisos[] = 'config/config.php estava no pacote e NÃO foi restaurado '
                                . '(use restore_config para sobrescrever a configuração atual).';
                            $arquivos['ignorados']++;
                            return null;
                        }
                        if (!self::destinoSeguro($rel)) {
                            $avisos[] = 'Entrada com caminho suspeito, ignorada: ' . $nome;
                            $arquivos['ignorados']++;
                            return null;
                        }
                        $destino = self::caminhoFisico($rel);
                        @mkdir(dirname($destino), 0770, true);
                        $arquivos['restaurados']++;
                        $arquivos['bytes'] += $tamanho;
                        return self::gravador($destino, $trabalho);
                    }
                    return null;
                }
            );

            if ($comBanco) {
                if (!is_file($sqlTmp)) {
                    throw new RuntimeException('O pacote não traz database.sql.');
                }
                $pdo = BackupDump::connect();
                if (!empty($opts['database_name'])) {
                    // Banco alternativo (homologação): conexão própria, nunca
                    // um USE na conexão do sistema.
                    $pdo->exec('USE ' . '`' . str_replace('`', '``', (string) $opts['database_name']) . '`');
                }
                $fh = @fopen($sqlTmp, 'rb');
                if ($fh === false) {
                    throw new RuntimeException('Não foi possível ler o database.sql extraído.');
                }
                try {
                    $r = BackupDump::restore($pdo, $fh, $opts);
                } finally {
                    fclose($fh);
                }
                $banco['restaurado'] = true;
                $banco['statements'] = $r['statements'];
                foreach ($r['avisos'] as $a) {
                    $avisos[] = $a;
                }

                // Confere o digest de cada tabela contra o manifesto: é a
                // prova de que o dado voltou igual, não só de que o SQL rodou.
                foreach ($manifesto['banco']['tabelas'] ?? [] as $tabela => $esperado) {
                    try {
                        $d = BackupDump::digestTable($pdo, (string) $tabela);
                    } catch (\Throwable $e) {
                        $banco['divergentes'][] = $tabela . ': ' . $e->getMessage();
                        continue;
                    }
                    $banco['tabelas_conferidas']++;
                    if ($d['linhas'] !== (int) $esperado['linhas'] || !hash_equals((string) $esperado['digest'], $d['digest'])) {
                        $banco['divergentes'][] = $tabela . ': ' . $d['linhas'] . ' linha(s) / digest '
                            . substr($d['digest'], 0, 12) . '… (manifesto: ' . (int) $esperado['linhas']
                            . ' / ' . substr((string) $esperado['digest'], 0, 12) . '…)';
                    }
                }
            }
        } finally {
            self::rmTree($trabalho);
        }

        // Caches EM DISCO precisam morrer: eles guardam consultas do banco
        // anterior e sobrevivem à requisição. Os caches em memória morrem
        // sozinhos no fim da requisição — limpá-los aqui não adiantaria nada.
        $limpos = self::limpaCachesEmDisco();
        if ($limpos > 0) {
            $avisos[] = $limpos . ' arquivo(s) de cache em disco removidos após a restauração.';
        }

        Audit::log('backup.restore', 'backup', substr((string) $item['id'], 0, 40), [
            'banco'       => $banco['restaurado'],
            'arquivos'    => $arquivos['restaurados'],
            'divergentes' => count($banco['divergentes']),
        ], null, 'core');

        return [
            'ok'       => $banco['divergentes'] === [],
            'banco'    => $banco,
            'arquivos' => $arquivos,
            'avisos'   => $avisos,
            'segundos' => round(microtime(true) - $inicio, 3),
        ];
    }

    /** Escritor de entrada extraída: grava em .part e renomeia ao fechar. */
    private static function gravador(string $destino, ?string $tmpDir = null): callable
    {
        $tmp = ($tmpDir ?? dirname($destino)) . '/.' . basename($destino) . '.' . bin2hex(random_bytes(4)) . '.part';
        $fh  = @fopen($tmp, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Não foi possível gravar ' . self::relativo($destino));
        }
        return static function (?string $bloco) use (&$fh, $tmp, $destino): void {
            if ($bloco === null) {
                if (!fclose($fh)) {
                    @unlink($tmp);
                    throw new RuntimeException('Falha ao fechar ' . self::relativo($destino));
                }
                if (!@rename($tmp, $destino)) {
                    @unlink($tmp);
                    throw new RuntimeException('Falha ao mover para ' . self::relativo($destino));
                }
                return;
            }
            $total = strlen($bloco);
            $pos   = 0;
            while ($pos < $total) {
                $n = @fwrite($fh, $pos === 0 ? $bloco : substr($bloco, $pos));
                if ($n === false || $n === 0) {
                    throw new RuntimeException('Escrita curta ao restaurar ' . self::relativo($destino) . ' (disco cheio?).');
                }
                $pos += $n;
            }
        };
    }

    /** Recusa caminhos absolutos, ../ e qualquer coisa fora das raízes esperadas. */
    /**
     * Traduz um caminho LÓGICO do pacote para o caminho FÍSICO no servidor.
     *
     * Dentro do pacote — e dentro do banco, na coluna file_path do RH — os
     * caminhos continuam começando por "storage/" e "config/" mesmo depois
     * que essas pastas saem do public_html. Isso é de propósito: o prefixo é
     * um TOKEN, não um endereço. Trocá-lo obrigaria a migrar dados gravados e
     * invalidaria todo pacote de backup gerado antes da mudança.
     *
     * Quem sabe onde as pastas realmente estão são as constantes do
     * bootstrap — e este é o único lugar que faz a conversão, para os dois
     * lados (empacotar e restaurar) nunca discordarem.
     */
    public static function caminhoFisico(string $rel): string
    {
        $rel = ltrim($rel, '/');
        if ($rel === 'storage' || str_starts_with($rel, 'storage/')) {
            $resto = substr($rel, strlen('storage'));
            return rtrim(STORAGE_PATH, '/') . $resto;
        }
        // O ÚNICO arquivo de configuração que o pacote carrega é
        // 'config/config.php', e ele tem de voltar para o arquivo REALMENTE
        // EM USO — que pode nem se chamar config.php, quando o caminho vem da
        // constante ou da variável de ambiente UNIFICAR_CONFIG. Montar
        // CONFIG_PATH . '/config.php' criaria um arquivo novo ao lado do
        // verdadeiro: a restauração diria "pronto" e a instalação continuaria
        // lendo a configuração antiga.
        if ($rel === 'config/config.php' && defined('CONFIG_FILE')) {
            return CONFIG_FILE;
        }
        if ($rel === 'config' || str_starts_with($rel, 'config/')) {
            $resto = substr($rel, strlen('config'));
            return rtrim(CONFIG_PATH, '/') . $resto;
        }
        return BASE_PATH . '/' . $rel;
    }

    /** As raízes físicas em que a restauração pode escrever. */
    private static function raizesPermitidas(): array
    {
        $r = [BASE_PATH, STORAGE_PATH, CONFIG_PATH];
        $out = [];
        foreach ($r as $x) {
            $real = realpath($x);
            if ($real !== false) {
                $out[$real] = true;
            }
        }
        return array_keys($out);
    }

    private static function destinoSeguro(string $rel): bool
    {
        if ($rel === '' || str_starts_with($rel, '/') || str_contains($rel, "\0")) {
            return false;
        }
        foreach (explode('/', $rel) as $parte) {
            if ($parte === '..') {
                return false;
            }
        }
        $permitido = false;
        foreach (['uploads/', 'storage/uploads/'] as $ok) {
            if (str_starts_with($rel, $ok)) {
                $permitido = true;
                break;
            }
        }
        // config é IGUALDADE EXATA, não prefixo. Com 'config/' como prefixo,
        // um pacote adulterado podia trazer 'config/shell.php' ou
        // 'config/.ssh/authorized_keys' e a restauração gravaria os dois —
        // e o único arquivo que o empacotamento grava ali é config/config.php.
        if (!$permitido && $rel === 'config/config.php') {
            $permitido = true;
        }
        if (!$permitido) {
            return false;
        }

        // Segunda barreira, agora que o destino pode ficar fora de BASE_PATH:
        // o caminho FÍSICO resolvido precisa cair dentro de uma das raízes
        // conhecidas. Sem isto, bastaria um 'storage/uploads/...' com
        // STORAGE_PATH apontando para um lugar inesperado para a restauração
        // escrever onde não deve.
        $destino   = self::caminhoFisico($rel);
        $existente = $destino;
        while (!file_exists($existente) && dirname($existente) !== $existente) {
            $existente = dirname($existente);
        }
        $real = realpath($existente);
        if ($real === false) {
            return false;
        }
        foreach (self::raizesPermitidas() as $raiz) {
            if ($real === $raiz || str_starts_with($real, $raiz . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Apaga os caches EM DISCO (storage/cache e modules/<slug>/storage/cache).
     *
     * Depois de restaurar, eles guardam resposta do banco antigo e o usuário
     * vê dado que não existe mais.
     */
    public static function limpaCachesEmDisco(): int
    {
        $n = 0;
        $alvos = array_merge(
            glob(STORAGE_PATH . '/cache/*') ?: [],
            glob(MODULES_PATH . '/*/storage/cache/*') ?: []
        );
        foreach ($alvos as $alvo) {
            if (basename($alvo) === '.gitkeep') {
                continue;
            }
            if (is_dir($alvo)) {
                $n += self::rmTree($alvo);
                continue;
            }
            if (@unlink($alvo)) {
                $n++;
            }
        }
        return $n;
    }

    // =================================================================
    // Utilidades
    // =================================================================

    /**
     * Trava de execução única.
     *
     * Dois backups ao mesmo tempo (o cron e o botão da administração) brigam
     * pelo mesmo tmp/ e dobram o uso de disco na hora errada.
     *
     * @return resource
     */
    private static function lock()
    {
        $fh = @fopen(self::tmpDir() . '/' . self::LOCK, 'c');
        if ($fh === false) {
            throw new RuntimeException('Não foi possível abrir a trava de backup.');
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new RuntimeException('Já existe um backup em andamento. Tente de novo daqui a pouco.');
        }
        return $fh;
    }

    /** @param resource $fh */
    private static function unlock($fh): void
    {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }

    /** Grava por arquivo temporário + rename: ou o conteúdo novo, ou o antigo. */
    private static function gravaAtomico(string $destino, string $conteudo): void
    {
        $tmp = $destino . '.' . bin2hex(random_bytes(4)) . '.part';
        $fh  = @fopen($tmp, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Não foi possível gravar ' . self::relativo($destino));
        }
        $total = strlen($conteudo);
        $pos   = 0;
        try {
            while ($pos < $total) {
                $n = @fwrite($fh, $pos === 0 ? $conteudo : substr($conteudo, $pos));
                if ($n === false || $n === 0) {
                    throw new RuntimeException('Escrita curta em ' . self::relativo($destino) . ' (disco cheio ou cota estourada).');
                }
                $pos += $n;
            }
        } catch (\Throwable $e) {
            fclose($fh);
            @unlink($tmp);
            throw $e;
        }
        if (!fclose($fh)) {
            @unlink($tmp);
            throw new RuntimeException('Falha ao fechar ' . self::relativo($destino));
        }
        clearstatcache(true, $tmp);
        if ((int) filesize($tmp) !== $total) {
            @unlink($tmp);
            throw new RuntimeException('O arquivo ' . self::relativo($destino) . ' saiu com tamanho errado.');
        }
        if (!@rename($tmp, $destino)) {
            @unlink($tmp);
            throw new RuntimeException('Não foi possível mover ' . self::relativo($destino));
        }
    }

    private static function rmTree(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $n = 0;
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            if (is_dir($p) && !is_link($p)) {
                $n += self::rmTree($p);
                continue;
            }
            if (@unlink($p)) {
                $n++;
            }
        }
        @rmdir($dir);
        return $n;
    }

    /** Caminho relativo à instalação (mensagens de erro não expõem o caminho do servidor). */
    private static function relativo(string $caminho): string
    {
        return str_starts_with($caminho, BASE_PATH . '/') ? substr($caminho, strlen(BASE_PATH) + 1) : basename($caminho);
    }
}
