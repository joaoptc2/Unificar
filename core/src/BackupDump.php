<?php

declare(strict_types=1);

namespace Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Dump lógico do banco (o database.sql do pacote), escrito em PHP puro.
 *
 * Por que PHP puro: a hospedagem do hospital é compartilhada. exec()/shell_exec
 * podem estar desabilitados, mysqldump pode não existir e o tempo/memória são
 * apertados. O binário externo, quando existe, é atalho — o caminho principal
 * precisa funcionar sem ele.
 *
 * Decisões que vieram de erro REAL e não devem ser "simplificadas":
 *
 * 1. Conexão PRÓPRIA. Core\DB::pdo() é singleton compartilhado com o resto da
 *    requisição: mudar atributo dele, deixar transação aberta ou mandar um USE
 *    quebra quem vier depois. Aqui abrimos uma segunda conexão com as mesmas
 *    credenciais de Core\Config.
 *
 * 2. Todo valor volta como STRING. Com prepares emulados o MariaDB responde no
 *    protocolo de texto e devolve exatamente os dígitos que ele próprio
 *    imprimiria. DECIMAL passado por float mente: '0.100000' vira
 *    0.10000000000000001. O dump nunca converte número — repassa o texto.
 *
 * 3. Conexão BUFFERIZADA com paginação por chave. Em conexão não-bufferizada
 *    qualquer consulta intercalada morre com SQLSTATE 2014 ("Cannot execute
 *    queries while other unbuffered queries are active"); paginando por chave
 *    primária a memória fica limitada ao tamanho da página e nada impede
 *    consultas no meio do caminho.
 *
 * 4. FOREIGN_KEY_CHECKS desligado no cabeçalho e RELIGADO no rodapé. Há FKs
 *    auto-referentes (chat_messages, plan_plan_items): com as checagens ligadas
 *    NENHUMA ordem de tabelas resolve. SQL_MODE, UNIQUE_CHECKS, charset e fuso
 *    são salvos e devolvidos junto — perder o STRICT_TRANS_TABLES faria a
 *    restauração truncar dado em silêncio.
 *
 * 5. Toda escrita tem o retorno conferido. Cota de disco estourada devolve
 *    escrita curta, não exceção: sem conferir, o dump "termina bem" pela metade.
 */
final class BackupDump
{
    /** Tamanho alvo de cada INSERT. Fica bem abaixo de max_allowed_packet. */
    public const MAX_INSERT_BYTES = 2097152;

    /** Linhas lidas por página (limita a memória de cada SELECT). */
    public const PAGE_ROWS = 500;

    /** Tipos emitidos sem aspas (o texto do servidor vai literal para o SQL). */
    private const NUMERICOS = [
        'tinyint' => 1, 'smallint' => 1, 'mediumint' => 1, 'int' => 1, 'integer' => 1,
        'bigint' => 1, 'decimal' => 1, 'dec' => 1, 'numeric' => 1, 'float' => 1,
        'double' => 1, 'real' => 1, 'year' => 1,
    ];

    /** Tipos emitidos como literal hexadecimal (0x...). */
    private const BINARIOS = [
        'binary' => 1, 'varbinary' => 1, 'tinyblob' => 1, 'blob' => 1,
        'mediumblob' => 1, 'longblob' => 1, 'geometry' => 1, 'point' => 1,
        'linestring' => 1, 'polygon' => 1, 'multipoint' => 1,
        'multilinestring' => 1, 'multipolygon' => 1, 'geometrycollection' => 1,
    ];

    // ------------------------------------------------------------------
    // Conexão
    // ------------------------------------------------------------------

    /**
     * Segunda conexão PDO, exclusiva do dump.
     *
     * NUNCA use Core\DB::pdo() aqui: o dump abre transação longa, troca
     * atributos e lê tabela por tabela; o singleton é de toda a aplicação.
     */
    public static function connect(): PDO
    {
        $host    = (string) Config::get('db.host', 'localhost');
        $port    = (int) Config::get('db.port', 3306);
        $name    = (string) Config::get('db.name', '');
        $charset = (string) Config::get('db.charset', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        return new PDO($dsn, (string) Config::get('db.user'), (string) Config::get('db.pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Prepares emulados + stringify: o resultado chega pelo protocolo
            // de texto e TODA coluna volta como string, com os mesmos dígitos
            // que o servidor imprimiria. É isto que impede DECIMAL/DOUBLE de
            // passar por float em algum ponto do caminho.
            PDO::ATTR_EMULATE_PREPARES   => true,
            PDO::ATTR_STRINGIFY_FETCHES  => true,
            // Bufferizada de propósito: ver nota 3 no topo da classe.
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
    }

    // ------------------------------------------------------------------
    // Dump
    // ------------------------------------------------------------------

    /**
     * Gera o dump completo em $sink (recurso de arquivo ou callable(string)).
     *
     * Opções: pdo, database, tables (lista branca), exclude, data (bool),
     * max_insert_bytes, page_rows, snapshot (bool).
     *
     * @return array{
     *   tabelas: array<string, array{linhas:int, digest:string, bytes:int, chave:string}>,
     *   avisos: string[], bytes: int, sessao: array<string,string>,
     *   objetos: array<string, string[]>, servidor: array<string,string>,
     *   segundos: float
     * }
     */
    public static function dump(mixed $sink, array $opts = []): array
    {
        $inicio  = microtime(true);
        $pdo     = $opts['pdo'] ?? self::connect();
        $escreve = self::writer($sink);

        $avisos  = [];
        $tabelas = [];
        $bytes   = 0;
        $objetos = ['views' => [], 'triggers' => [], 'rotinas' => [], 'eventos' => []];

        // --- Metainformação ANTES de qualquer leitura de dados ------------
        // (em conexão não-bufferizada isto seria obrigatório; aqui é só higiene)
        $sessao = self::sessionInfo($pdo);
        $db     = (string) ($opts['database'] ?? $sessao['database']);
        if ($db === '') {
            throw new RuntimeException('Não foi possível determinar o banco a copiar (db.name vazio).');
        }

        $packet    = max(65536, (int) $sessao['max_allowed_packet']);
        $maxInsert = (int) ($opts['max_insert_bytes'] ?? self::MAX_INSERT_BYTES);
        // Mesmo que alguém peça um INSERT gigante, nunca passamos de 40% do
        // pacote: o servidor derruba a conexão sem dó em "packet too large".
        $maxInsert = max(65536, min($maxInsert, (int) floor($packet * 0.4)));
        $pageRows  = max(1, (int) ($opts['page_rows'] ?? self::PAGE_ROWS));
        $comDados  = (bool) ($opts['data'] ?? true);

        $snapshot = (bool) ($opts['snapshot'] ?? true);
        if ($snapshot) {
            // Sem LOCK TABLES / FLUSH TABLES WITH READ LOCK: hospedagem
            // compartilhada nega os dois. REPEATABLE READ + snapshot
            // consistente dá a mesma coerência para tabelas InnoDB.
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }

        try {
            $lista = self::tableNames($pdo, $db, $opts);

            // --- Cabeçalho -----------------------------------------------
            $bytes += $escreve(self::header($pdo, $sessao, $db, $lista, $avisos));

            // --- Tabelas: estrutura + dados -------------------------------
            foreach ($lista as $tabela) {
                $antes = $bytes;

                $bytes += $escreve(self::estrutura($pdo, $tabela));

                $linhas = 0;
                $digest = str_repeat('0', 64);
                $chave  = 'sem-dados';

                if ($comDados) {
                    $r = self::dados($pdo, $db, $tabela, $escreve, $maxInsert, $pageRows, $avisos, $packet);
                    $linhas = $r['linhas'];
                    $digest = $r['digest'];
                    $chave  = $r['chave'];
                    $bytes += $r['bytes'];
                }

                $tabelas[$tabela] = [
                    'linhas' => $linhas,
                    'digest' => $digest,
                    'bytes'  => $bytes - $antes,
                    'chave'  => $chave,
                ];
            }

            // --- Views, triggers, rotinas e eventos -----------------------
            // Aqui não existem, mas produção pode ter: um dump que os ignora
            // em silêncio devolve um banco incompleto na hora da desgraça.
            $bytes += $escreve(self::views($pdo, $db, $objetos, $avisos));
            $bytes += $escreve(self::triggers($pdo, $db, $objetos, $avisos));
            $bytes += $escreve(self::rotinas($pdo, $db, $objetos, $avisos));
            $bytes += $escreve(self::eventos($pdo, $db, $objetos, $avisos));

            // --- Rodapé ---------------------------------------------------
            $bytes += $escreve(self::footer($tabelas, $bytes));

            if ($snapshot) {
                $pdo->exec('COMMIT');
            }
        } catch (\Throwable $e) {
            if ($snapshot) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (\Throwable) {
                    // conexão já caiu: nada a desfazer
                }
            }
            throw $e;
        }

        return [
            'tabelas'  => $tabelas,
            'avisos'   => $avisos,
            'bytes'    => $bytes,
            'sessao'   => $sessao,
            'objetos'  => $objetos,
            'servidor' => ['versao' => $sessao['versao'], 'banco' => $db],
            'segundos' => round(microtime(true) - $inicio, 3),
        ];
    }

    // ------------------------------------------------------------------
    // Cabeçalho e rodapé
    // ------------------------------------------------------------------

    /** @param string[] $lista */
    private static function header(PDO $pdo, array $sessao, string $db, array $lista, array &$avisos): string
    {
        $modo = self::modoRestauracao($sessao['sql_mode']);
        if ($modo !== $sessao['sql_mode']) {
            // Só informativo: NO_AUTO_VALUE_ON_ZERO preserva o zero explícito
            // em colunas AUTO_INCREMENT, que sem ele viraria "próximo id".
            $avisos[] = 'O dump restaura com o SQL_MODE de origem acrescido de NO_AUTO_VALUE_ON_ZERO.';
        }

        $h  = "-- =============================================================\n";
        $h .= "-- Plataforma Unificada — dump gerado por Core\\BackupDump\n";
        $h .= '-- Banco: ' . $db . '  |  Servidor: ' . $sessao['versao'] . "\n";
        $h .= '-- Gerado em: ' . date('Y-m-d H:i:s') . ' (' . date_default_timezone_get()
            . ', deslocamento do banco ' . $sessao['offset'] . ")\n";
        $h .= '-- Tabelas: ' . count($lista) . "\n";
        $h .= "--\n";
        $h .= "-- As checagens abaixo são DESLIGADAS para a carga e RELIGADAS no\n";
        $h .= "-- rodapé. Não retire: há chaves estrangeiras auto-referentes, e com\n";
        $h .= "-- FOREIGN_KEY_CHECKS ligado nenhuma ordem de tabelas funciona.\n";
        $h .= "-- =============================================================\n\n";

        $h .= "SET @UNIFICAR_SQL_MODE           = @@SESSION.sql_mode;\n";
        $h .= "SET @UNIFICAR_FOREIGN_KEY_CHECKS = @@SESSION.foreign_key_checks;\n";
        $h .= "SET @UNIFICAR_UNIQUE_CHECKS      = @@SESSION.unique_checks;\n";
        $h .= "SET @UNIFICAR_TIME_ZONE          = @@SESSION.time_zone;\n";
        $h .= "SET @UNIFICAR_CS_CLIENT          = @@SESSION.character_set_client;\n";
        $h .= "SET @UNIFICAR_CS_RESULTS         = @@SESSION.character_set_results;\n";
        $h .= "SET @UNIFICAR_COLL_CONNECTION    = @@SESSION.collation_connection;\n\n";

        // O sql_mode de origem vai inteiro: perdê-lo (é o que o mysqldump faz)
        // troca STRICT_TRANS_TABLES por modo frouxo e a carga passa a truncar
        // dado em silêncio em vez de reclamar.
        $h .= 'SET SESSION sql_mode = ' . $pdo->quote($modo) . ";\n";
        $h .= "SET SESSION foreign_key_checks = 0;\n";
        $h .= "SET SESSION unique_checks = 0;\n";
        $h .= 'SET SESSION time_zone = ' . $pdo->quote($sessao['offset']) . ";\n";
        $h .= 'SET NAMES ' . self::identificadorSimples($sessao['charset']) . ";\n\n";

        return $h;
    }

    /** @param array<string, array> $tabelas */
    private static function footer(array $tabelas, int $bytes): string
    {
        $linhas = 0;
        foreach ($tabelas as $t) {
            $linhas += (int) $t['linhas'];
        }

        $f  = "\n-- Religa tudo o que o cabeçalho desligou. Se a restauração parar\n";
        $f .= "-- antes daqui, a sessão morre junto e nada fica desligado de forma\n";
        $f .= "-- permanente — mas o banco estará incompleto: confira o manifesto.\n";
        $f .= "SET SESSION foreign_key_checks = @UNIFICAR_FOREIGN_KEY_CHECKS;\n";
        $f .= "SET SESSION unique_checks      = @UNIFICAR_UNIQUE_CHECKS;\n";
        $f .= "SET SESSION sql_mode           = @UNIFICAR_SQL_MODE;\n";
        $f .= "SET SESSION time_zone          = @UNIFICAR_TIME_ZONE;\n";
        $f .= "SET SESSION character_set_client  = @UNIFICAR_CS_CLIENT;\n";
        $f .= "SET SESSION character_set_results = @UNIFICAR_CS_RESULTS;\n";
        $f .= "SET SESSION collation_connection  = @UNIFICAR_COLL_CONNECTION;\n\n";
        $f .= '-- Fim do dump: ' . count($tabelas) . ' tabela(s), ' . $linhas . ' linha(s), '
            . $bytes . " byte(s).\n";
        $f .= "-- UNIFICAR-DUMP-FIM\n";

        return $f;
    }

    /** O sql_mode que a restauração deve usar (o de origem + o zero explícito). */
    private static function modoRestauracao(string $modo): string
    {
        $partes = array_filter(array_map('trim', explode(',', $modo)), static fn ($m) => $m !== '');
        if (!in_array('NO_AUTO_VALUE_ON_ZERO', $partes, true)) {
            $partes[] = 'NO_AUTO_VALUE_ON_ZERO';
        }
        return implode(',', $partes);
    }

    // ------------------------------------------------------------------
    // Estrutura
    // ------------------------------------------------------------------

    private static function estrutura(PDO $pdo, string $tabela): string
    {
        $row = $pdo->query('SHOW CREATE TABLE ' . self::id($tabela))->fetch(PDO::FETCH_NUM);
        $ddl = (string) ($row[1] ?? '');

        $s  = "\n--\n-- Estrutura de " . $tabela . "\n--\n";
        $s .= 'DROP TABLE IF EXISTS ' . self::id($tabela) . ";\n";
        $s .= $ddl . ";\n";
        return $s;
    }

    // ------------------------------------------------------------------
    // Dados
    // ------------------------------------------------------------------

    /**
     * @return array{linhas:int, digest:string, bytes:int, chave:string}
     */
    private static function dados(
        PDO $pdo,
        string $db,
        string $tabela,
        callable $escreve,
        int $maxInsert,
        int $pageRows,
        array &$avisos,
        int $packet
    ): array {
        $colunas = self::colunas($pdo, $db, $tabela);
        if ($colunas === []) {
            $avisos[] = "Tabela {$tabela}: nenhuma coluna gravável (só colunas geradas?); dados não copiados.";
            return ['linhas' => 0, 'digest' => str_repeat('0', 64), 'bytes' => 0, 'chave' => 'sem-colunas'];
        }

        $nomes = array_column($colunas, 'nome');
        $pk    = self::chavePrimaria($pdo, $db, $tabela, $nomes);
        $modo  = $pk === [] ? 'limit-offset' : 'chave:' . implode(',', $pk);

        if ($pk === []) {
            // Sem PK a paginação é por OFFSET: dentro do snapshot a ordem é
            // estável, mas o digest só vale como indicativo (ver aviso).
            $avisos[] = "Tabela {$tabela}: sem PRIMARY KEY utilizável — paginada por LIMIT/OFFSET "
                . 'e o digest dela é apenas INDICATIVO (a ordem de leitura não é garantida pelo servidor).';
        }

        $listaCols = implode(', ', array_map([self::class, 'id'], $nomes));
        $bytes     = 0;
        $linhas    = 0;
        $acc       = array_fill(0, 8, 0);

        $cabecalho = 'INSERT INTO ' . self::id($tabela) . ' (' . $listaCols . ") VALUES\n";
        $buffer    = '';
        $primeiro  = true;

        $flush = static function () use (&$buffer, &$bytes, $escreve, &$primeiro): void {
            if ($buffer === '') {
                return;
            }
            $bytes += $escreve($buffer . ";\n");
            $buffer   = '';
            $primeiro = true;
        };

        $bytes += $escreve("\n--\n-- Dados de " . $tabela . "\n--\n");

        self::eachRow($pdo, $tabela, $colunas, $pk, $pageRows, function (array $row) use (
            $pdo, $colunas, $cabecalho, $maxInsert, $packet, $tabela,
            &$buffer, &$primeiro, &$linhas, &$acc, &$avisos, $flush
        ): void {
            $vals = [];
            $crus = [];
            foreach ($colunas as $col) {
                $v = $row[$col['nome']] ?? null;
                $v = $v === null ? null : (string) $v;
                $crus[] = $v;
                $vals[] = self::literal($pdo, $v, $col);
            }
            $tupla = '(' . implode(',', $vals) . ')';

            // Uma linha sozinha maior que o pacote não tem como ser dividida:
            // avisa em vez de gerar um INSERT que o servidor recusa.
            if (strlen($tupla) > (int) floor($packet * 0.9)) {
                $avisos[] = "Tabela {$tabela}: uma linha ocupa " . strlen($tupla)
                    . ' bytes, perto do max_allowed_packet (' . $packet
                    . '). A restauração pode exigir aumentar esse limite.';
            }

            if (!$primeiro && strlen($buffer) + strlen($tupla) + 2 > $maxInsert) {
                $flush();
            }
            if ($primeiro) {
                $buffer   = $cabecalho . $tupla;
                $primeiro = false;
            } else {
                $buffer .= ",\n" . $tupla;
            }

            self::acumula($acc, self::linhaTexto($crus));
            $linhas++;
        });

        $flush();

        return [
            'linhas' => $linhas,
            'digest' => self::digestHex($acc),
            'bytes'  => $bytes,
            'chave'  => $modo,
        ];
    }

    /**
     * Percorre todas as linhas da tabela chamando $fn($row).
     *
     * Paginação por chave primária quando existe (usa o índice, não relê o que
     * já passou); LIMIT/OFFSET só como último recurso.
     *
     * @param array<int, array{nome:string, tipo:string, inteiro:bool}> $colunas
     * @param string[] $pk
     */
    private static function eachRow(PDO $pdo, string $tabela, array $colunas, array $pk, int $pageRows, callable $fn): void
    {
        $nomes  = array_column($colunas, 'nome');
        $select = implode(', ', array_map([self::class, 'id'], $nomes));
        $tbl    = self::id($tabela);

        if ($pk === []) {
            $offset = 0;
            while (true) {
                $stmt = $pdo->query("SELECT {$select} FROM {$tbl} LIMIT {$pageRows} OFFSET {$offset}");
                $rows = $stmt->fetchAll();
                $stmt->closeCursor();
                foreach ($rows as $row) {
                    $fn($row);
                }
                if (count($rows) < $pageRows) {
                    return;
                }
                $offset += $pageRows;
            }
        }

        $tipos = array_column($colunas, null, 'nome');
        $ordem = implode(', ', array_map([self::class, 'id'], $pk));
        $lhs   = '(' . $ordem . ')';
        $ph    = '(' . implode(',', array_fill(0, count($pk), '?')) . ')';
        $ultima = null;

        while (true) {
            if ($ultima === null) {
                $stmt = $pdo->query("SELECT {$select} FROM {$tbl} ORDER BY {$ordem} LIMIT {$pageRows}");
            } else {
                $stmt = $pdo->prepare("SELECT {$select} FROM {$tbl} WHERE {$lhs} > {$ph} ORDER BY {$ordem} LIMIT {$pageRows}");
                $i = 1;
                foreach ($pk as $col) {
                    $v = $ultima[$col];
                    // Coluna inteira recebe o valor como inteiro: comparar
                    // string com inteiro força conversão e pode descartar o índice.
                    $inteiro = ($tipos[$col]['inteiro'] ?? false)
                        && $v !== null
                        && preg_match('/^-?\d{1,18}$/', (string) $v) === 1;
                    $stmt->bindValue($i++, $v, $inteiro ? PDO::PARAM_INT : PDO::PARAM_STR);
                }
                $stmt->execute();
            }

            $rows = $stmt->fetchAll();
            $stmt->closeCursor();
            foreach ($rows as $row) {
                $fn($row);
            }
            if (count($rows) < $pageRows) {
                return;
            }

            $fim    = $rows[count($rows) - 1];
            $ultima = [];
            foreach ($pk as $col) {
                $ultima[$col] = $fim[$col];
            }
        }
    }

    // ------------------------------------------------------------------
    // Digest por tabela (prova de fidelidade)
    // ------------------------------------------------------------------

    /**
     * Digest de uma tabela já existente (usado para conferir a restauração).
     *
     * @return array{linhas:int, digest:string, chave:string}
     */
    public static function digestTable(PDO $pdo, string $tabela, ?string $db = null, int $pageRows = self::PAGE_ROWS): array
    {
        $db    ??= (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $colunas = self::colunas($pdo, $db, $tabela);
        $pk      = self::chavePrimaria($pdo, $db, $tabela, array_column($colunas, 'nome'));

        $acc    = array_fill(0, 8, 0);
        $linhas = 0;

        self::eachRow($pdo, $tabela, $colunas, $pk, $pageRows, static function (array $row) use ($colunas, &$acc, &$linhas): void {
            $crus = [];
            foreach ($colunas as $col) {
                $v = $row[$col['nome']] ?? null;
                $crus[] = $v === null ? null : (string) $v;
            }
            self::acumula($acc, self::linhaTexto($crus));
            $linhas++;
        });

        return [
            'linhas' => $linhas,
            'digest' => self::digestHex($acc),
            'chave'  => $pk === [] ? 'limit-offset' : 'chave:' . implode(',', $pk),
        ];
    }

    /**
     * Texto canônico de uma linha.
     *
     * O comprimento vai junto de cada valor e o NULL tem marcador próprio:
     * sem isso, ('a','b') e ('ab','') — ou NULL e a string 'NULL' — teriam a
     * mesma impressão digital.
     *
     * @param array<int, string|null> $valores
     */
    private static function linhaTexto(array $valores): string
    {
        $partes = [];
        foreach ($valores as $v) {
            $partes[] = $v === null ? "\x00~NULO~\x00" : strlen($v) . ':' . $v;
        }
        return implode("\x1f", $partes);
    }

    /**
     * Acumula a linha no digest de forma ORDEM-INDEPENDENTE.
     *
     * São oito somas de 32 bits com estouro (wrap), não XOR: com XOR duas
     * linhas idênticas se cancelam e uma tabela com dois registros repetidos
     * teria o mesmo digest de uma tabela vazia.
     *
     * @param int[] $acc
     */
    private static function acumula(array &$acc, string $linha): void
    {
        $w = unpack('N8', hash('sha256', $linha, true));
        for ($i = 0; $i < 8; $i++) {
            $acc[$i] = ($acc[$i] + $w[$i + 1]) & 0xFFFFFFFF;
        }
    }

    /** @param int[] $acc */
    private static function digestHex(array $acc): string
    {
        $hex = '';
        foreach ($acc as $w) {
            $hex .= sprintf('%08x', $w);
        }
        return $hex;
    }

    // ------------------------------------------------------------------
    // Views / triggers / rotinas / eventos
    // ------------------------------------------------------------------

    private static function views(PDO $pdo, string $db, array &$objetos, array &$avisos): string
    {
        $rows = self::fetchAll($pdo, 'SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$db]);
        if ($rows === []) {
            return '';
        }
        $s = "\n--\n-- Views\n--\n";
        foreach ($rows as $r) {
            $nome = (string) $r['TABLE_NAME'];
            $row  = $pdo->query('SHOW CREATE VIEW ' . self::id($nome))->fetch(PDO::FETCH_NUM);
            $ddl  = self::semDefiner((string) ($row[1] ?? ''));
            $s   .= 'DROP VIEW IF EXISTS ' . self::id($nome) . ";\n";
            $s   .= 'DROP TABLE IF EXISTS ' . self::id($nome) . ";\n";
            $s   .= $ddl . ";\n";
            $objetos['views'][] = $nome;
        }
        $avisos[] = count($rows) . ' view(s) no dump com o DEFINER removido (o usuário original pode não existir no destino).';
        return $s;
    }

    private static function triggers(PDO $pdo, string $db, array &$objetos, array &$avisos): string
    {
        $rows = self::fetchAll($pdo, 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME', [$db]);
        if ($rows === []) {
            return '';
        }
        // Depois dos dados de propósito: gatilho criado antes dispararia a
        // cada INSERT da carga e duplicaria efeito colateral.
        $s = "\n--\n-- Gatilhos (criados DEPOIS dos dados para não dispararem na carga)\n--\n";
        foreach ($rows as $r) {
            $nome = (string) $r['TRIGGER_NAME'];
            $row  = $pdo->query('SHOW CREATE TRIGGER ' . self::id($nome))->fetch(PDO::FETCH_ASSOC);
            $ddl  = self::semDefiner((string) ($row['SQL Original Statement'] ?? ''));
            if ($ddl === '') {
                continue;
            }
            $s .= 'DROP TRIGGER IF EXISTS ' . self::id($nome) . ";\n";
            $s .= "DELIMITER ;;\n" . $ddl . ";;\nDELIMITER ;\n";
            $objetos['triggers'][] = $nome;
        }
        $avisos[] = count($rows) . ' gatilho(s) no dump com o DEFINER removido.';
        return $s;
    }

    private static function rotinas(PDO $pdo, string $db, array &$objetos, array &$avisos): string
    {
        $rows = self::fetchAll($pdo, 'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_NAME', [$db]);
        if ($rows === []) {
            return '';
        }
        $s = "\n--\n-- Procedimentos e funções\n--\n";
        foreach ($rows as $r) {
            $nome = (string) $r['ROUTINE_NAME'];
            $tipo = strtoupper((string) $r['ROUTINE_TYPE']) === 'FUNCTION' ? 'FUNCTION' : 'PROCEDURE';
            $row  = $pdo->query('SHOW CREATE ' . $tipo . ' ' . self::id($nome))->fetch(PDO::FETCH_ASSOC);
            $ddl  = self::semDefiner((string) ($row['Create Procedure'] ?? $row['Create Function'] ?? ''));
            if ($ddl === '') {
                continue;
            }
            $s .= 'DROP ' . $tipo . ' IF EXISTS ' . self::id($nome) . ";\n";
            $s .= "DELIMITER ;;\n" . $ddl . ";;\nDELIMITER ;\n";
            $objetos['rotinas'][] = $tipo . ' ' . $nome;
        }
        $avisos[] = count($rows) . ' rotina(s) no dump com o DEFINER removido.';
        return $s;
    }

    private static function eventos(PDO $pdo, string $db, array &$objetos, array &$avisos): string
    {
        $rows = self::fetchAll($pdo, 'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? ORDER BY EVENT_NAME', [$db]);
        if ($rows === []) {
            return '';
        }
        $s = "\n--\n-- Eventos agendados\n--\n";
        foreach ($rows as $r) {
            $nome = (string) $r['EVENT_NAME'];
            $row  = $pdo->query('SHOW CREATE EVENT ' . self::id($nome))->fetch(PDO::FETCH_ASSOC);
            $ddl  = self::semDefiner((string) ($row['Create Event'] ?? ''));
            if ($ddl === '') {
                continue;
            }
            $s .= 'DROP EVENT IF EXISTS ' . self::id($nome) . ";\n";
            $s .= "DELIMITER ;;\n" . $ddl . ";;\nDELIMITER ;\n";
            $objetos['eventos'][] = $nome;
        }
        $avisos[] = count($rows) . ' evento(s) no dump; confira se o event_scheduler está ligado no destino.';
        return $s;
    }

    /**
     * Remove a cláusula DEFINER=... do DDL.
     *
     * Em hospedagem compartilhada o usuário do banco muda de nome entre a
     * conta antiga e a nova; com o DEFINER original a restauração para com
     * "The user specified as a definer does not exist".
     */
    private static function semDefiner(string $ddl): string
    {
        return (string) preg_replace(
            '/\sDEFINER\s*=\s*(?:CURRENT_USER(?:\(\))?|`(?:[^`]|``)*`@`(?:[^`]|``)*`|\'(?:[^\']|\'\')*\'@\'(?:[^\']|\'\')*\'|\S+)/i',
            '',
            $ddl
        );
    }

    // ------------------------------------------------------------------
    // Restauração
    // ------------------------------------------------------------------

    /**
     * Executa um script SQL vindo de um stream (o database.sql do pacote).
     *
     * Lê em blocos e separa os comandos respeitando aspas, crases, comentários
     * e DELIMITER — carregar um dump de 50 MB com file_get_contents estoura o
     * memory_limit da hospedagem compartilhada.
     *
     * @param resource $stream
     * @return array{statements:int, avisos:string[], fim_encontrado:bool}
     */
    public static function restore(PDO $pdo, $stream, array $opts = []): array
    {
        $avisos    = [];
        $executados = 0;
        $fim        = false;
        $parar      = (bool) ($opts['stop_on_error'] ?? true);

        self::statements($stream, function (string $sql) use ($pdo, &$executados, &$avisos, &$fim, $parar): void {
            if (str_contains($sql, 'UNIFICAR-DUMP-FIM')) {
                $fim = true;
            }
            try {
                $pdo->exec($sql);
                $executados++;
            } catch (PDOException $e) {
                // Servidor de destino de outra versão pode não conhecer algum
                // modo do sql_mode de origem (NO_AUTO_CREATE_USER saiu no
                // MySQL 8). Perder o modo inteiro é pior que perder um item:
                // tenta de novo com o mínimo que protege o dado.
                if (preg_match('/^\s*SET\s+SESSION\s+sql_mode\s*=/i', $sql) === 1) {
                    $avisos[] = 'O SQL_MODE de origem não foi aceito pelo servidor de destino ('
                        . $e->getMessage() . '); usando STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO.';
                    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_AUTO_VALUE_ON_ZERO'");
                    $executados++;
                    return;
                }
                if ($parar) {
                    throw new RuntimeException(
                        'Falha ao restaurar: ' . $e->getMessage() . ' — comando: '
                        . mb_substr(preg_replace('/\s+/', ' ', $sql) ?? '', 0, 200),
                        0,
                        $e
                    );
                }
                $avisos[] = mb_substr(preg_replace('/\s+/', ' ', $sql) ?? '', 0, 120) . ' → ' . $e->getMessage();
            }
        });

        if (!$fim) {
            $avisos[] = 'O marcador de fim do dump (UNIFICAR-DUMP-FIM) não apareceu: '
                . 'o arquivo pode estar truncado.';
        }

        return ['statements' => $executados, 'avisos' => $avisos, 'fim_encontrado' => $fim];
    }

    /**
     * Separa o script em comandos, chamando $emit para cada um.
     *
     * @param resource $stream
     */
    private static function statements($stream, callable $emit): int
    {
        $buf   = '';
        $i     = 0;      // posição de varredura
        $aspas = null;   // ', " ou ` quando dentro de literal
        $delim = ';';
        $n     = 0;
        $eof   = false;

        while (true) {
            if (!$eof) {
                $chunk = fread($stream, 262144);
                if ($chunk === false || $chunk === '') {
                    $eof = true;
                } else {
                    $buf .= $chunk;
                }
            }

            while (true) {
                // DELIMITER só vale no começo de um comando.
                if ($aspas === null && $i === 0
                    && preg_match('/^[\s]*DELIMITER[ \t]+(\S+)[ \t]*(\r?\n)/i', $buf, $m) === 1) {
                    $delim = $m[1];
                    $buf   = substr($buf, strlen($m[0]));
                    continue;
                }

                $corte = self::proximoComando($buf, $i, $aspas, $delim, $eof);
                if ($corte === null) {
                    break;
                }
                $sql = trim(substr($buf, 0, $corte['fim']));
                $buf = substr($buf, $corte['proximo']);
                $i   = 0;
                if ($sql !== '' && !self::soComentario($sql)) {
                    $emit($sql);
                    $n++;
                }
            }

            if ($eof) {
                $sql = trim($buf);
                if ($sql !== '' && !self::soComentario($sql)) {
                    $emit($sql);
                    $n++;
                }
                return $n;
            }
        }
    }

    /**
     * Acha o fim do próximo comando em $buf a partir de $i.
     *
     * @return array{fim:int, proximo:int}|null null = precisa de mais bytes
     */
    private static function proximoComando(string $buf, int &$i, ?string &$aspas, string $delim, bool $eof): ?array
    {
        $len  = strlen($buf);
        $dlen = strlen($delim);

        while ($i < $len) {
            $ch   = $buf[$i];
            $next = $i + 1 < $len ? $buf[$i + 1] : '';

            if ($aspas !== null) {
                if ($ch === '\\' && $aspas !== '`') {
                    if ($i + 1 >= $len && !$eof) {
                        return null; // a barra pode escapar o byte que ainda não chegou
                    }
                    $i += 2;
                    continue;
                }
                if ($ch === $aspas) {
                    // '' dentro de string é uma aspa literal, não o fim
                    if ($next === $aspas) {
                        $i += 2;
                        continue;
                    }
                    if ($i + 1 >= $len && !$eof) {
                        return null;
                    }
                    $aspas = null;
                }
                $i++;
                continue;
            }

            if ($ch === '-' && $next === '-') {
                $eol = strpos($buf, "\n", $i);
                if ($eol === false) {
                    return $eof ? ['fim' => $len, 'proximo' => $len] : null;
                }
                $i = $eol + 1;
                continue;
            }
            if ($ch === '#') {
                $eol = strpos($buf, "\n", $i);
                if ($eol === false) {
                    return $eof ? ['fim' => $len, 'proximo' => $len] : null;
                }
                $i = $eol + 1;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $fim = strpos($buf, '*/', $i + 2);
                if ($fim === false) {
                    return $eof ? ['fim' => $len, 'proximo' => $len] : null;
                }
                $i = $fim + 2;
                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $aspas = $ch;
                $i++;
                continue;
            }

            if ($ch === $delim[0] && ($dlen === 1 || substr($buf, $i, $dlen) === $delim)) {
                return ['fim' => $i, 'proximo' => $i + $dlen];
            }

            $i++;
        }

        return null;
    }

    private static function soComentario(string $sql): bool
    {
        $limpo = preg_replace('#(^|\n)\s*(--[^\n]*|\#[^\n]*)#', '', $sql) ?? $sql;
        $limpo = preg_replace('#/\*.*?\*/#s', '', $limpo) ?? $limpo;
        return trim($limpo) === '';
    }

    // ------------------------------------------------------------------
    // Metainformação
    // ------------------------------------------------------------------

    /** @return array<string, string> */
    private static function sessionInfo(PDO $pdo): array
    {
        $row = $pdo->query(
            'SELECT @@SESSION.sql_mode             AS sql_mode,
                    @@SESSION.unique_checks        AS unique_checks,
                    @@SESSION.foreign_key_checks   AS foreign_key_checks,
                    @@SESSION.character_set_client AS charset,
                    @@SESSION.collation_connection AS collation,
                    @@SESSION.time_zone            AS time_zone,
                    @@SESSION.max_allowed_packet   AS max_allowed_packet,
                    TIMEDIFF(NOW(), UTC_TIMESTAMP()) AS deslocamento,
                    VERSION()                      AS versao,
                    DATABASE()                     AS db'
        )->fetch();

        $tz = (string) ($row['time_zone'] ?? 'SYSTEM');
        // 'SYSTEM' não serve no destino (o servidor de lá tem outro sistema):
        // grava o deslocamento numérico efetivo no momento do dump.
        $offset = self::offset((string) ($row['deslocamento'] ?? '00:00:00'));

        return [
            'sql_mode'           => (string) ($row['sql_mode'] ?? ''),
            'unique_checks'      => (string) ($row['unique_checks'] ?? '1'),
            'foreign_key_checks' => (string) ($row['foreign_key_checks'] ?? '1'),
            'charset'            => (string) ($row['charset'] ?? 'utf8mb4'),
            'collation'          => (string) ($row['collation'] ?? ''),
            'time_zone'          => $tz,
            'offset'             => $offset,
            'max_allowed_packet' => (string) ($row['max_allowed_packet'] ?? '4194304'),
            'versao'             => (string) ($row['versao'] ?? ''),
            'database'           => (string) ($row['db'] ?? ''),
        ];
    }

    /** '-03:00:00' → '-03:00' (formato aceito por SET time_zone). */
    private static function offset(string $timediff): string
    {
        if (preg_match('/^(-?)(\d{1,3}):(\d{2})/', $timediff, $m) !== 1) {
            return '+00:00';
        }
        $sinal = $m[1] === '-' ? '-' : '+';
        return $sinal . str_pad($m[2], 2, '0', STR_PAD_LEFT) . ':' . $m[3];
    }

    /** @return string[] */
    private static function tableNames(PDO $pdo, string $db, array $opts): array
    {
        $rows = self::fetchAll(
            $pdo,
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
              ORDER BY TABLE_NAME",
            [$db]
        );
        $nomes = array_map(static fn ($r) => (string) $r['TABLE_NAME'], $rows);

        $somente = $opts['tables'] ?? null;
        if (is_array($somente) && $somente !== []) {
            $nomes = array_values(array_intersect($nomes, $somente));
        }
        $fora = $opts['exclude'] ?? [];
        if (is_array($fora) && $fora !== []) {
            $nomes = array_values(array_diff($nomes, $fora));
        }
        return $nomes;
    }

    /**
     * Colunas graváveis da tabela, na ordem do CREATE TABLE.
     *
     * Colunas GENERATED (virtuais ou armazenadas) ficam de fora: o servidor
     * recusa INSERT que tente escrever nelas.
     *
     * @return array<int, array{nome:string, tipo:string, inteiro:bool}>
     */
    private static function colunas(PDO $pdo, string $db, string $tabela): array
    {
        $rows = self::fetchAll(
            $pdo,
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, EXTRA
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION',
            [$db, $tabela]
        );

        $out = [];
        foreach ($rows as $r) {
            if (stripos((string) $r['EXTRA'], 'GENERATED') !== false) {
                continue;
            }
            $tipo = strtolower((string) $r['DATA_TYPE']);
            $out[] = [
                'nome'    => (string) $r['COLUMN_NAME'],
                'tipo'    => $tipo,
                'coltipo' => strtolower((string) $r['COLUMN_TYPE']),
                'inteiro' => in_array($tipo, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true),
            ];
        }
        return $out;
    }

    /**
     * Colunas da PRIMARY KEY (vazio quando não há, ou quando alguma delas não
     * está entre as colunas graváveis — nesse caso a paginação por chave não
     * teria o valor de onde continuar).
     *
     * @param string[] $disponiveis
     * @return string[]
     */
    private static function chavePrimaria(PDO $pdo, string $db, string $tabela, array $disponiveis): array
    {
        $rows = self::fetchAll(
            $pdo,
            "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'
              ORDER BY ORDINAL_POSITION",
            [$db, $tabela]
        );
        $pk = array_map(static fn ($r) => (string) $r['COLUMN_NAME'], $rows);
        foreach ($pk as $col) {
            if (!in_array($col, $disponiveis, true)) {
                return [];
            }
        }
        return $pk;
    }

    /** @return array<int, array<string, mixed>> */
    private static function fetchAll(PDO $pdo, string $sql, array $params = []): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();
        return $rows;
    }

    // ------------------------------------------------------------------
    // Valores
    // ------------------------------------------------------------------

    /**
     * Literal SQL de um valor já lido como string.
     *
     * Número vai como veio do servidor, SEM passar por float: sprintf('%.17G')
     * em '0.100000' devolve 0.10000000000000001 e a restauração grava outro
     * número. Texto é citado por PDO::quote (que respeita o charset e o
     * NO_BACKSLASH_ESCAPES da conexão). Binário vira 0x....
     *
     * @param array{nome:string, tipo:string, coltipo:string} $col
     */
    private static function literal(PDO $pdo, ?string $v, array $col): string
    {
        if ($v === null) {
            return 'NULL';
        }
        $tipo = $col['tipo'];

        if (isset(self::NUMERICOS[$tipo])) {
            // Confere que é mesmo um literal numérico antes de emitir sem
            // aspas — valor estranho é citado, nunca colado cru no SQL.
            if ($v !== '' && preg_match('/^-?(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?$/', $v) === 1) {
                return $v;
            }
            return $pdo->quote($v);
        }

        if (isset(self::BINARIOS[$tipo])) {
            return $v === '' ? "''" : '0x' . bin2hex($v);
        }

        if ($tipo === 'bit') {
            return "b'" . self::bits($v) . "'";
        }

        return $pdo->quote($v);
    }

    /** Bytes de uma coluna BIT → literal binário (b'1010'). */
    private static function bits(string $v): string
    {
        $bin = '';
        for ($i = 0, $n = strlen($v); $i < $n; $i++) {
            $bin .= sprintf('%08b', ord($v[$i]));
        }
        $bin = ltrim($bin, '0');
        return $bin === '' ? '0' : $bin;
    }

    /** Identificador entre crases, com a crase interna dobrada. */
    private static function id(string $nome): string
    {
        return '`' . str_replace('`', '``', $nome) . '`';
    }

    /** Nome de charset/collation validado (vai cru no SET NAMES). */
    private static function identificadorSimples(string $nome): string
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $nome) === 1 ? $nome : 'utf8mb4';
    }

    // ------------------------------------------------------------------
    // Escrita
    // ------------------------------------------------------------------

    /**
     * Devolve a função de escrita para o destino (recurso ou callable).
     *
     * Toda escrita tem o retorno conferido: cota de disco estourada devolve
     * escrita curta (ou zero), nunca exceção. Sem isto, o dump "termina com
     * sucesso" pela metade e só se descobre no dia da restauração.
     */
    private static function writer(mixed $sink): callable
    {
        if (is_resource($sink)) {
            return static function (string $s) use ($sink): int {
                $total = strlen($s);
                $pos   = 0;
                while ($pos < $total) {
                    $n = @fwrite($sink, $pos === 0 ? $s : substr($s, $pos));
                    if ($n === false || $n === 0) {
                        throw new RuntimeException(
                            'Falha ao escrever o dump: escrita curta em ' . $pos . '/' . $total
                            . ' bytes (disco cheio ou cota da conta estourada).'
                        );
                    }
                    $pos += $n;
                }
                return $total;
            };
        }

        if (is_callable($sink)) {
            return static function (string $s) use ($sink): int {
                $sink($s);
                return strlen($s);
            };
        }

        throw new RuntimeException('Destino do dump inválido: informe um recurso de arquivo ou um callable.');
    }
}
