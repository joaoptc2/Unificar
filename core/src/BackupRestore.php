<?php

declare(strict_types=1);

namespace Core;

use PDO;
use RuntimeException;

/**
 * Restauração de um pacote gerado por Core\Backup.
 *
 * O backup é escrito com calma; a restauração acontece no pior dia do ano,
 * com o hospital parado e alguém olhando por cima do ombro. Por isso aqui
 * tudo é conferido ANTES de destruir qualquer coisa, e o que não dá para
 * conferir vira aviso escrito, não silêncio.
 *
 * As regras que este arquivo NÃO pode perder:
 *
 *  1. inspect() não escreve NADA no banco. Ele lê (precisa ler para dizer
 *     "o backup tem 1643 linhas e o banco atual tem 1700"), mas não cria
 *     tabela, não roda migração e não abre transação. Por isso não usa
 *     Core\Migrations::applied(), que faria CREATE TABLE IF NOT EXISTS.
 *
 *  2. A verdade sobre o pacote está DENTRO do pacote. O manifest.json ao
 *     lado é atalho de listagem: quem confere sha256 lê o manifesto que
 *     viajou junto com os dados. Os dois são comparados e a diferença vira
 *     erro — pacote e manifesto separados costumam significar troca de
 *     arquivo, não coincidência.
 *
 *  3. DDL NÃO TEM ROLLBACK. Cada DROP TABLE do dump é definitivo no instante
 *     em que roda: não existe "desfazer" no meio da carga. É por isso que em
 *     produção a restauração gera um backup de segurança ANTES e se recusa a
 *     seguir se ele falhar — a rede vem primeiro, depois o salto.
 *
 *  4. O rodapé do dump devolve os valores que ele mesmo encontrou no
 *     cabeçalho. Se desligarmos foreign_key_checks ANTES de carregar, é o
 *     zero que o rodapé vai "devolver". Por isso guardamos os valores da
 *     sessão antes de mexer em qualquer coisa e religamos por valor
 *     EXPLÍCITO no fim, conferindo com uma leitura nova.
 *
 *  5. Os digests são conferidos ANTES da higiene pós-restauração. Cancelar a
 *     fila de e-mail e limpar tokens muda linhas de propósito: conferir
 *     depois acusaria divergência em tabelas que nós mesmos mexemos, e a
 *     prova de fidelidade viraria ruído.
 *
 *  6. Restaurar em outro banco é MODO DE TESTE de primeira classe. Uma cópia
 *     crua da produção é uma cópia funcional das credenciais de todo o corpo
 *     clínico: com sanitize (padrão no modo de teste) o segundo fator é
 *     zerado, o hash de senha é neutralizado e o envio de e-mail é desligado
 *     na base restaurada.
 */
final class BackupRestore
{
    /** Formatos de pacote que esta classe sabe restaurar. */
    /**
     * Formatos aceitos. O /2 mudou só onde mora a lista de entradas (foi do
     * manifesto para um entradas.ndjson dentro do pacote, para o manifesto
     * não crescer com o número de arquivos); o /1 continua restaurável.
     */
    public const FORMATOS = ['unificar-backup/1', 'unificar-backup/2'];

    /**
     * Marcador de época das sessões (tabela settings).
     *
     * Vive no banco de propósito: ele volta junto com a restauração, e é
     * justamente por isso que precisa ser AVANÇADO depois dela.
     */
    public const CHAVE_EPOCA = 'security.session_epoch';

    /** Marcadores gravados na base restaurada em MODO DE TESTE. */
    public const CHAVE_AMBIENTE = 'restauracao.ambiente';
    public const CHAVE_ORIGEM   = 'restauracao.origem';

    /**
     * Valor gravado em users.password_hash no modo de teste.
     *
     * Não é um hash válido de propósito: password_verify() devolve false para
     * qualquer senha, sem lançar erro, e ninguém entra na cópia com a senha
     * de verdade do funcionário.
     */
    public const SENHA_NEUTRA = 'restauracao-de-teste:sem-senha-valida';

    private const BLOCO = 512;      // bloco do tar
    private const CHUNK = 262144;   // leitura em blocos de 256 KB

    // =================================================================
    // Inspeção (não toca no banco)
    // =================================================================

    /**
     * Abre o pacote, confere o que der para conferir e monta o comparativo
     * com a base atual.
     *
     * Aceita o id do backup (backup-AAAAMMDD-HHMMSS-...) ou um caminho de
     * arquivo — quem acabou de copiar um pacote de outro servidor tem o
     * caminho, não o id.
     *
     * Opções: comparar (bool=true) liga a comparação com o banco atual.
     *
     * @return array{ok:bool, id:string, caminho:string, bytes:int, formato:string,
     *               formato_ok:bool, manifesto:array, resumo:array, entradas:array,
     *               comparativo:array, migracoes:array, erros:string[], avisos:string[],
     *               segundos:float}
     */
    public static function inspect(string $arquivo, array $opts = []): array
    {
        $inicio  = microtime(true);
        $caminho = self::resolvePacote($arquivo);
        clearstatcache(true, $caminho);
        $bytes = (int) @filesize($caminho);

        $erros  = [];
        $avisos = [];

        // ---- Manifesto: o de DENTRO do pacote é o que vale ---------------
        $manifesto = self::manifestoDoPacote($caminho);
        if ($manifesto === null) {
            throw new RuntimeException(
                'O pacote ' . self::relativo($caminho) . ' não tem um manifest.json legível: '
                . 'sem manifesto não há o que conferir, e restaurar às cegas está fora de cogitação.'
            );
        }

        $id = (string) ($manifesto['id'] ?? basename($caminho, Backup::EXT));
        self::confereManifestoAoLado($caminho, $manifesto, $erros, $avisos);

        // ---- Versão do formato -------------------------------------------
        $formato   = (string) ($manifesto['formato'] ?? '');
        $formatoOk = in_array($formato, self::FORMATOS, true);
        if (!$formatoOk) {
            $erros[] = 'Formato de pacote desconhecido: ' . ($formato === '' ? '(vazio)' : $formato)
                . ' — esta versão do sistema restaura ' . implode(', ', self::FORMATOS) . '.';
        }

        // ---- Cada entrada contra o manifesto ------------------------------
        $entradas = self::confereEntradas($caminho, $manifesto, $erros);

        // ---- Comparativo com o banco atual (somente leitura) --------------
        $comparativo = ($opts['comparar'] ?? true)
            ? self::comparativo($manifesto, $avisos)
            : ['disponivel' => false, 'motivo' => 'comparação desligada por opção'];

        // ---- Migrações: o pacote é mais velho que o código? ---------------
        $migracoes = self::comparaMigracoes($manifesto, $avisos);

        foreach ($manifesto['avisos'] ?? [] as $a) {
            $avisos[] = 'Do manifesto: ' . $a;
        }

        return [
            'ok'          => $erros === [],
            'id'          => $id,
            'caminho'     => $caminho,
            'bytes'       => $bytes,
            'formato'     => $formato,
            'formato_ok'  => $formatoOk,
            'manifesto'   => $manifesto,
            'resumo'      => [
                'criado_em'  => (string) ($manifesto['criado_em'] ?? ''),
                'motivo'     => (string) ($manifesto['motivo'] ?? ''),
                'gerado_por' => (string) ($manifesto['gerado_por']['usuario'] ?? '—'),
                'origem'     => (string) ($manifesto['gerado_por']['origem'] ?? ''),
                'aplicacao'  => (string) ($manifesto['sistema']['aplicacao'] ?? ''),
                'banco'      => (string) ($manifesto['sistema']['banco'] ?? ''),
                'servidor'   => (string) ($manifesto['sistema']['servidor'] ?? ''),
                'php'        => (string) ($manifesto['sistema']['php'] ?? ''),
                'com_banco'  => (bool) ($manifesto['banco']['incluido'] ?? false),
                'com_config' => (bool) ($manifesto['arquivos']['config_php'] ?? false),
                'arquivos'   => (int) ($manifesto['arquivos']['total'] ?? 0),
                'sql_bytes'  => (int) ($manifesto['banco']['bytes'] ?? 0),
            ],
            'entradas'    => $entradas,
            'comparativo' => $comparativo,
            'migracoes'   => $migracoes,
            'erros'       => $erros,
            'avisos'      => array_values(array_unique($avisos)),
            'segundos'    => round(microtime(true) - $inicio, 3),
        ];
    }

    /**
     * Lê a lista de entradas de DENTRO do pacote (entradas.ndjson).
     *
     * Ela saiu do manifesto porque custava cerca de 2 KB por arquivo mantido
     * em memória: numa instalação com dezenas de milhares de anexos, fechar
     * o backup (ou inspecioná-lo) estourava o memory_limit. Aqui ela é lida
     * em fluxo, linha a linha.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function leEntradasNdjson(string $pacote): array
    {
        $out   = [];
        $resto = '';
        try {
            self::percorre(
                $pacote,
                static function (): void {
                },
                static function (string $nome) use (&$out, &$resto): ?callable {
                    if ($nome !== Backup::ENTRADAS) {
                        return null;
                    }
                    return static function (?string $pedaco) use (&$out, &$resto): void {
                        if ($pedaco !== null) {
                            $resto .= $pedaco;
                        }
                        while (($nl = strpos($resto, "\n")) !== false) {
                            $linha = substr($resto, 0, $nl);
                            $resto = substr($resto, $nl + 1);
                            if (trim($linha) === '') {
                                continue;
                            }
                            $e = json_decode($linha, true);
                            if (is_array($e)) {
                                $out[] = $e;
                            }
                        }
                    };
                }
            );
        } catch (\Throwable) {
            return [];
        }
        return $out;
    }

    /**
     * Confere tamanho e sha256 de cada entrada relendo o pacote.
     *
     * O sha256 do database.sql sai destacado: é a entrada que, adulterada,
     * transforma a restauração em estrago.
     *
     * @return array{total:int, conferidas:int, faltando:string[], sobrando:string[],
     *               database_sql:array, itens:array<string, array>}
     */
    private static function confereEntradas(string $pacote, array $manifesto, array &$erros): array
    {
        // Pacotes do formato /2 trazem a lista em entradas.ndjson; os do /1
        // (antigos) traziam no próprio manifesto. Os dois continuam válidos.
        $fonte = (array) ($manifesto['entradas'] ?? []);
        if ($fonte === []) {
            $fonte = self::leEntradasNdjson($pacote);
        }
        $esperado = [];
        foreach ($fonte as $e) {
            $nome = (string) ($e['nome'] ?? '');
            if ($nome !== '') {
                $esperado[$nome] = ['bytes' => (int) ($e['bytes'] ?? -1), 'sha256' => (string) ($e['sha256'] ?? '')];
            }
        }

        $vistos = [];
        try {
            self::percorre($pacote, static function (string $nome, int $tamanho, string $sha, string $tipo) use (&$vistos): void {
                $vistos[$nome] = ['bytes' => $tamanho, 'sha256' => $sha, 'tipo' => $tipo];
            });
        } catch (\Throwable $e) {
            $erros[] = 'Não foi possível reler o pacote inteiro: ' . $e->getMessage();
        }

        $itens      = [];
        $faltando   = [];
        $sobrando   = [];
        $conferidas = 0;

        foreach ($esperado as $nome => $e) {
            if (!isset($vistos[$nome])) {
                $faltando[] = $nome;
                $erros[]    = 'Entrada do manifesto que não está no pacote: ' . $nome;
                continue;
            }
            $tamOk = $vistos[$nome]['bytes'] === $e['bytes'];
            $shaOk = hash_equals($e['sha256'], $vistos[$nome]['sha256']);
            if (!$tamOk) {
                $erros[] = $nome . ': tamanho ' . $vistos[$nome]['bytes'] . ' ≠ ' . $e['bytes'] . ' do manifesto.';
            }
            if (!$shaOk) {
                $erros[] = $nome . ': sha256 NÃO confere (esperado ' . substr($e['sha256'], 0, 16)
                    . '…, obtido ' . substr($vistos[$nome]['sha256'], 0, 16) . '…).';
            }
            if ($tamOk && $shaOk) {
                $conferidas++;
            }
            $itens[$nome] = [
                'bytes'    => $vistos[$nome]['bytes'],
                'esperado' => $e['sha256'],
                'obtido'   => $vistos[$nome]['sha256'],
                'ok'       => $tamOk && $shaOk,
            ];
        }

        foreach ($vistos as $nome => $_) {
            // manifest.json e entradas.ndjson não se descrevem: quem confere
            // o primeiro é o manifesto ao lado do pacote, e o segundo é o
            // campo 'entradas_arquivo' do manifesto.
            if ($nome !== 'manifest.json' && $nome !== Backup::ENTRADAS && !isset($esperado[$nome])) {
                $sobrando[] = $nome;
                $erros[]    = 'Entrada no pacote que o manifesto não declara: ' . $nome;
            }
        }

        if (!isset($vistos['manifest.json'])) {
            $erros[] = 'O pacote não traz manifest.json entre as entradas do tar.';
        }

        $comBanco = (bool) ($manifesto['banco']['incluido'] ?? false);
        if ($comBanco && !isset($itens['database.sql'])) {
            $erros[] = 'O manifesto diz que o banco está incluído, mas não há database.sql conferível no pacote.';
        }

        return [
            'total'        => count($esperado),
            'conferidas'   => $conferidas,
            'faltando'     => $faltando,
            'sobrando'     => $sobrando,
            'database_sql' => $itens['database.sql'] ?? ['ok' => false, 'ausente' => true],
            'itens'        => $itens,
        ];
    }

    /**
     * Compara o manifesto de dentro do pacote com o arquivo .manifest.json
     * ao lado, quando existe.
     *
     * Os dois diferentes não é detalhe: ou o pacote foi trocado, ou o
     * manifesto foi. Em qualquer dos casos alguém precisa olhar antes de
     * restaurar.
     */
    private static function confereManifestoAoLado(string $pacote, array $manifesto, array &$erros, array &$avisos): void
    {
        $lado = preg_replace('/' . preg_quote(Backup::EXT, '/') . '$/', '.manifest.json', $pacote);
        if (!is_string($lado) || !is_file($lado)) {
            $avisos[] = 'Não há manifesto ao lado do pacote (só o de dentro). '
                . 'Normal em pacote copiado de outro servidor.';
            return;
        }
        $json = json_decode((string) @file_get_contents($lado), true);
        if (!is_array($json)) {
            $avisos[] = 'O manifesto ao lado do pacote está ilegível; vale o de dentro do pacote.';
            return;
        }
        $a = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $b = json_encode($manifesto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($a !== $b) {
            $erros[] = 'O manifesto ao lado do pacote (' . basename((string) $lado)
                . ') não é o mesmo que está dentro do pacote: um dos dois foi trocado.';
        }
    }

    /**
     * "O backup tem X tabelas / Y linhas; o banco atual tem A / B."
     *
     * SOMENTE LEITURA: COUNT(*) e information_schema, em conexão própria.
     * Nada de Core\DB::pdo() (singleton da requisição) e nada de escrita —
     * quem pede inspeção ainda está decidindo se restaura.
     *
     * @return array<string, mixed>
     */
    private static function comparativo(array $manifesto, array &$avisos): array
    {
        $doBackup = [];
        foreach ($manifesto['banco']['tabelas'] ?? [] as $nome => $t) {
            $doBackup[(string) $nome] = (int) ($t['linhas'] ?? 0);
        }

        $out = [
            'disponivel' => false,
            'backup'     => ['tabelas' => count($doBackup), 'linhas' => array_sum($doBackup)],
            'atual'      => ['banco' => '', 'tabelas' => 0, 'linhas' => 0],
            'tabelas'    => [],
            'so_no_backup' => [],
            'so_no_banco'  => [],
            'diferentes'   => [],
        ];

        if ($doBackup === []) {
            $avisos[] = 'O pacote não traz banco (só arquivos): não há comparativo de tabelas.';
            return $out;
        }

        try {
            $pdo    = BackupDump::connect();
            $dbNome = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
            );
            $stmt->execute([$dbNome]);
            $reais = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            $atuais = [];
            foreach ($reais as $t) {
                // O nome vem do information_schema, não do manifesto: o
                // manifesto é arquivo, e arquivo entra em consulta como dado,
                // nunca como identificador.
                $atuais[$t] = (int) $pdo->query('SELECT COUNT(*) FROM ' . self::ident($t))->fetchColumn();
            }

            $out['disponivel'] = true;
            $out['atual'] = ['banco' => $dbNome, 'tabelas' => count($atuais), 'linhas' => array_sum($atuais)];

            foreach (array_unique(array_merge(array_keys($doBackup), array_keys($atuais))) as $t) {
                $nb = $doBackup[$t] ?? null;
                $na = $atuais[$t] ?? null;
                $out['tabelas'][$t] = ['backup' => $nb, 'atual' => $na];
                if ($nb === null) {
                    $out['so_no_banco'][] = $t;
                } elseif ($na === null) {
                    $out['so_no_backup'][] = $t;
                } elseif ($nb !== $na) {
                    $out['diferentes'][$t] = ['backup' => $nb, 'atual' => $na, 'delta' => $nb - $na];
                }
            }
            sort($out['so_no_banco']);
            sort($out['so_no_backup']);
            ksort($out['tabelas']);

            if ($out['so_no_banco'] !== []) {
                $avisos[] = count($out['so_no_banco']) . ' tabela(s) existem no banco atual e NÃO estão no pacote: '
                    . implode(', ', array_slice($out['so_no_banco'], 0, 8))
                    . '. A restauração não as apaga (o dump só recria o que ele trouxe), '
                    . 'mas elas ficarão fora de sincronia com o resto.';
            }
        } catch (\Throwable $e) {
            $out['motivo'] = $e->getMessage();
            $avisos[] = 'Não foi possível comparar com o banco atual (' . $e->getMessage()
                . '). O pacote continua conferível; só o comparativo ficou de fora.';
        }

        return $out;
    }

    /**
     * Migrações do pacote × migrações que o código de hoje tem.
     *
     * Restaurar um backup velho num código novo deixa o banco ATRÁS dos
     * arquivos: as tabelas voltam como eram, sem as colunas que as migrações
     * seguintes criaram. Quem restaura precisa saber disso antes, não depois
     * do primeiro erro de coluna inexistente.
     *
     * Não usa Core\Migrations::applied(): aquele método faz CREATE TABLE IF
     * NOT EXISTS, e inspecionar não escreve no banco.
     *
     * @return array{no_pacote:int, no_codigo:int, faltarao:string[], adiante:string[]}
     */
    private static function comparaMigracoes(array $manifesto, array &$avisos): array
    {
        $noPacote = array_map('strval', (array) ($manifesto['migracoes']['aplicadas'] ?? []));
        $noCodigo = Migrations::files();

        $faltarao = array_values(array_diff($noCodigo, $noPacote));
        $adiante  = array_values(array_diff($noPacote, $noCodigo));

        if ($faltarao !== []) {
            $avisos[] = 'Depois de restaurar faltará(ão) ' . count($faltarao) . ' migração(ões) que o código já espera ('
                . implode(', ', array_slice($faltarao, 0, 5)) . '). Rode "php scripts/migrate.php" logo após a restauração.';
        }
        if ($adiante !== []) {
            $avisos[] = 'O pacote traz ' . count($adiante) . ' migração(ões) que NÃO existe(m) mais no código ('
                . implode(', ', array_slice($adiante, 0, 5)) . '): este backup é de uma versão mais nova da aplicação.';
        }

        return [
            'no_pacote' => count($noPacote),
            'no_codigo' => count($noCodigo),
            'faltarao'  => $faltarao,
            'adiante'   => $adiante,
        ];
    }

    // =================================================================
    // Restauração
    // =================================================================

    /**
     * Restaura um pacote.
     *
     * Opções:
     *   target       'producao' (padrão) ou o nome de OUTRO banco → MODO DE TESTE
     *   with_files   (bool) restaurar os arquivos do pacote
     *                padrão: sim em produção, não no modo de teste
     *   files_dir    (string) raiz onde gravar os arquivos (padrão: a instalação
     *                em produção; um diretório separado no modo de teste)
     *   sanitize     (bool) neutralizar credenciais na base restaurada
     *                padrão: sim no modo de teste, PROIBIDO em produção
     *   with_db      (bool=true)  carregar o database.sql
     *   verify       (bool=true)  conferir o pacote inteiro antes de mexer em nada
     *   comparar     (bool=true)  contar as linhas do banco atual para o comparativo
     *                             (desligue em base grande: é um COUNT(*) por tabela)
     *   confirmado   (bool)       obrigatório para target='producao'
     *   backup_seguranca (bool=true) gerar o backup automático antes (produção)
     *   sem_backup_seguranca (bool=false) seguir mesmo se o de segurança falhar
     *   criar_banco  (bool=true)  CREATE DATABASE IF NOT EXISTS no modo de teste
     *   fila_email   'cancelar' (padrão) | 'apagar' | 'manter'
     *   restore_config (bool=false) sobrescrever config/config.php
     *
     * A ordem é a do relatório: validar → backup de segurança → desligar
     * checagens → carregar → religar → arquivos → caches → conferir digests →
     * higiene → relatório.
     *
     * @return array<string, mixed>
     */
    public static function restore(string $arquivo, array $opts = []): array
    {
        $inicio = microtime(true);
        $avisos = [];
        $erros  = [];

        // Fôlego de tempo ANTES de qualquer coisa. A restauração é a única
        // operação sem volta, e era a única que não pedia: interrompida na
        // metade, deixa o banco com parte das tabelas do pacote e parte das
        // antigas. O backup já fazia isso desde o começo (Backup::folego).
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ini_set('memory_limit', '-1');
        $limiteTempo = (int) ini_get('max_execution_time');
        if ($limiteTempo > 0 && $limiteTempo < 120) {
            $avisos[] = 'max_execution_time está em ' . $limiteTempo . 's e não pôde ser aumentado. '
                . 'Em base grande a restauração pode ser interrompida no meio — prefira a linha de '
                . 'comando (php scripts/restore.php).';
        }

        // ---- 1) VALIDAR --------------------------------------------------
        // Conferência completa antes de tocar em qualquer coisa: o pacote é
        // relido inteiro e cada sha256 é recalculado. Ler duas vezes custa
        // segundos; restaurar metade de um pacote corrompido custa o dia.
        $ins = self::inspect($arquivo, ['comparar' => (bool) ($opts['comparar'] ?? true)]);
        if ((bool) ($opts['verify'] ?? true) && !$ins['ok']) {
            throw new RuntimeException(
                'Pacote RECUSADO — a restauração não começou. ' . implode(' | ', $ins['erros'])
            );
        }
        if (!$ins['ok']) {
            // verify=false é para emergência ("é isto ou nada"): segue, mas o
            // relatório carrega o motivo para sempre.
            foreach ($ins['erros'] as $e) {
                $avisos[] = 'CONFERÊNCIA IGNORADA (verify=false): ' . $e;
            }
        }

        $caminho   = (string) $ins['caminho'];
        $manifesto = (array) $ins['manifesto'];
        $pacoteId  = (string) $ins['id'];

        // ---- Alvo: produção ou modo de teste ------------------------------
        $dbProducao = (string) Config::get('db.name', '');
        $alvoOpt    = trim((string) ($opts['target'] ?? 'producao'));
        $producao   = ($alvoOpt === '' || $alvoOpt === 'producao' || $alvoOpt === 'produção');

        if (!$producao && $alvoOpt === $dbProducao) {
            throw new RuntimeException(
                'O banco "' . $alvoOpt . '" É o banco de produção desta instalação. '
                . 'Restaurar nele não é modo de teste: use target=producao e assuma o que vem junto.'
            );
        }
        $alvo = $producao ? $dbProducao : $alvoOpt;
        if ($alvo === '') {
            throw new RuntimeException('Não sei em que banco restaurar: db.name está vazio no config.');
        }
        if (!$producao && preg_match('/^[A-Za-z0-9_$]{1,64}$/', $alvo) !== 1) {
            throw new RuntimeException('Nome de banco inválido para o modo de teste: ' . $alvo);
        }

        $comBanco = (bool) ($opts['with_db'] ?? true) && (bool) ($manifesto['banco']['incluido'] ?? false);
        if ((bool) ($opts['with_db'] ?? true) && !$comBanco) {
            $avisos[] = 'O pacote não traz banco: só os arquivos serão restaurados.';
        }
        if (!$producao && !$comBanco) {
            throw new RuntimeException('Modo de teste sem banco no pacote não faz sentido: não há o que restaurar.');
        }

        $comArquivos = array_key_exists('with_files', $opts) ? (bool) $opts['with_files'] : $producao;
        if ($comArquivos && !(bool) ($manifesto['arquivos']['incluidos'] ?? false)) {
            $avisos[] = 'Pediram os arquivos, mas este pacote foi gerado só com o banco (--no-files).';
            $comArquivos = false;
        }

        $sanitize = array_key_exists('sanitize', $opts) ? (bool) $opts['sanitize'] : !$producao;
        if ($producao && $sanitize) {
            throw new RuntimeException(
                'sanitize=true em PRODUÇÃO apagaria o segundo fator e a senha de todos os funcionários. '
                . 'Ele existe para o MODO DE TESTE (target=<outro banco>).'
            );
        }

        // Guarda de nível de biblioteca: um botão mal ligado numa tela de
        // administração não pode apagar o banco do hospital por engano.
        if ($producao && ($opts['confirmado'] ?? false) !== true) {
            throw new RuntimeException(
                'Restaurar em PRODUÇÃO apaga e recria as tabelas de "' . $alvo . '" e NÃO TEM ROLLBACK. '
                . 'Passe confirmado=true depois de confirmar com quem é de direito '
                . '(a linha de comando pede a palavra "restaurar").'
            );
        }

        // Raiz dos arquivos: em modo de teste NUNCA é a instalação — os
        // uploads de produção são estes mesmos arquivos, e sobrescrevê-los
        // "para testar" é um estrago de verdade.
        $raizArquivos = BASE_PATH;
        if ($comArquivos) {
            $raizArquivos = (string) ($opts['files_dir'] ?? ($producao
                ? BASE_PATH
                : Backup::dir() . '/restauracoes/' . $pacoteId));
            $raizArquivos = rtrim($raizArquivos, '/\\');
            if ($raizArquivos !== BASE_PATH) {
                $avisos[] = 'Os arquivos do pacote vão para ' . $raizArquivos
                    . ' (fora da instalação): nada da produção é sobrescrito.';
            }
        }

        $relatorio = [
            'ok'        => false,
            'modo'      => $producao ? 'producao' : 'teste',
            'alvo'      => $alvo,
            'pacote'    => [
                'id'        => $pacoteId,
                'caminho'   => $caminho,
                'bytes'     => (int) $ins['bytes'],
                'formato'   => (string) $ins['formato'],
                'criado_em' => (string) ($manifesto['criado_em'] ?? ''),
                'motivo'    => (string) ($manifesto['motivo'] ?? ''),
            ],
            'inspecao'  => [
                'entradas_conferidas' => (int) $ins['entradas']['conferidas'],
                'entradas_total'      => (int) $ins['entradas']['total'],
                'database_sql_sha256' => (string) ($ins['entradas']['database_sql']['obtido'] ?? ''),
                'comparativo'         => $ins['comparativo'],
                'migracoes'           => $ins['migracoes'],
            ],
            'backup_seguranca' => null,
            'banco'     => ['carregado' => false, 'statements' => 0, 'segundos' => 0.0, 'sessao' => []],
            'conferencia' => ['tabelas' => 0, 'iguais' => 0, 'linhas' => 0, 'divergentes' => []],
            'arquivos'  => ['restaurados' => 0, 'bytes' => 0, 'ignorados' => 0, 'destino' => $comArquivos ? $raizArquivos : ''],
            'caches'    => 0,
            'higiene'   => [],
            'sanitize'  => [],
            'ddl'       => 'DDL não tem rollback: cada DROP/CREATE do dump é definitivo no instante em que roda. '
                         . 'Se a carga parar no meio, o banco fica pela metade e o caminho de volta é restaurar de novo '
                         . '(o backup de segurança gerado antes é exatamente para isto).',
            'integracao' => self::sessionEpochHint(),
            'avisos'    => [],
            'erros'     => [],
            'segundos'  => 0.0,
        ];

        // ---- 2) BACKUP DE SEGURANÇA (só em produção) ----------------------
        if ($producao && (bool) ($opts['backup_seguranca'] ?? true)) {
            try {
                $seg = Backup::create([
                    'motivo' => 'segurança automática antes de restaurar ' . $pacoteId,
                    // Sem retenção agora: apagar backups antigos justo no
                    // minuto em que o admin mais pode precisar deles seria a
                    // hora mais infeliz possível para fazer faxina.
                    'retencao' => false,
                ]);
                $relatorio['backup_seguranca'] = [
                    'id'      => $seg['id'],
                    'caminho' => $seg['caminho'],
                    'bytes'   => $seg['bytes'],
                ];
            } catch (\Throwable $e) {
                if (($opts['sem_backup_seguranca'] ?? false) !== true) {
                    throw new RuntimeException(
                        'O backup de segurança falhou (' . $e->getMessage() . ') e a restauração foi CANCELADA: '
                        . 'sem ele não há caminho de volta, porque DDL não tem rollback. '
                        . 'Resolva o motivo (disco/cota/permissão) ou assuma o risco com sem_backup_seguranca=true.'
                    );
                }
                $avisos[] = 'SEM REDE: o backup de segurança falhou (' . $e->getMessage()
                    . ') e a restauração seguiu assim mesmo, por pedido explícito.';
            }
        } elseif ($producao) {
            $avisos[] = 'SEM REDE: backup de segurança desligado por opção. Se algo der errado, não há volta.';
        }

        // Trava compartilhada com Core\Backup: um backup agendado que caia no
        // meio da restauração empacotaria um banco pela metade e ainda o
        // chamaria de backup. A trava é tomada DEPOIS do backup de segurança
        // (ele mesmo a usa, e ela é não-bloqueante).
        $lock = self::trava();

        $trabalho = Backup::tmpDir() . '/restore-' . bin2hex(random_bytes(8));
        if (!@mkdir($trabalho, 0770, true) && !is_dir($trabalho)) {
            self::destrava($lock);
            throw new RuntimeException('Não foi possível criar o diretório de trabalho da restauração.');
        }

        $pdo = null;
        try {
            // ---- 3) CARREGAR O BANCO -------------------------------------
            if ($comBanco) {
                $sqlTmp = $trabalho . '/database.sql';
                self::extraiEntrada($caminho, 'database.sql', $sqlTmp, $manifesto);

                $pdo = BackupDump::connect();
                self::preparaAlvo($pdo, $alvo, $producao, (bool) ($opts['criar_banco'] ?? true), $manifesto);

                // Valores ORIGINAIS da sessão, guardados antes de qualquer
                // mudança: é por eles que religamos no fim. Não dá para
                // confiar no rodapé do dump, que devolve o que encontrou no
                // cabeçalho — ou seja, o que nós tivermos acabado de desligar.
                $sessaoAntes = self::sessaoAtual($pdo);

                $pdo->exec('SET SESSION foreign_key_checks = 0');
                $pdo->exec('SET SESSION unique_checks = 0');

                $fh = @fopen($sqlTmp, 'rb');
                if ($fh === false) {
                    throw new RuntimeException('Não foi possível ler o database.sql extraído.');
                }
                $t0 = microtime(true);
                try {
                    $r = BackupDump::restore($pdo, $fh, ['stop_on_error' => true]);
                } finally {
                    fclose($fh);
                }

                // ---- 4) RELIGAR e devolver o SQL_MODE --------------------
                $pdo->exec('SET SESSION foreign_key_checks = 1');
                $pdo->exec('SET SESSION unique_checks = 1');
                $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($sessaoAntes['sql_mode']));
                $pdo->exec('SET SESSION time_zone = ' . $pdo->quote($sessaoAntes['time_zone']));
                $sessaoDepois = self::sessaoAtual($pdo);

                foreach (['foreign_key_checks', 'unique_checks', 'sql_mode', 'time_zone'] as $k) {
                    if ($k === 'foreign_key_checks' || $k === 'unique_checks') {
                        if ($sessaoDepois[$k] !== '1') {
                            $erros[] = 'A sessão terminou com ' . $k . ' = ' . $sessaoDepois[$k] . ' (deveria ser 1).';
                        }
                        continue;
                    }
                    if ($sessaoDepois[$k] !== $sessaoAntes[$k]) {
                        $erros[] = 'A sessão terminou com ' . $k . ' = ' . $sessaoDepois[$k]
                            . ' (era ' . $sessaoAntes[$k] . ').';
                    }
                }

                $relatorio['banco'] = [
                    'carregado'  => true,
                    'statements' => (int) $r['statements'],
                    'segundos'   => round(microtime(true) - $t0, 3),
                    'sessao'     => ['antes' => $sessaoAntes, 'depois' => $sessaoDepois],
                ];
                foreach ($r['avisos'] as $a) {
                    $avisos[] = $a;
                }
                if (!$r['fim_encontrado']) {
                    $erros[] = 'O marcador de fim do dump não apareceu: a carga não chegou ao fim do arquivo.';
                }
            }

            // ---- 5) ARQUIVOS (depois do banco) ---------------------------
            if ($comArquivos) {
                $relatorio['arquivos'] = self::restauraArquivos(
                    $caminho,
                    $manifesto,
                    $raizArquivos,
                    (bool) ($opts['restore_config'] ?? false),
                    $avisos
                );
            }

            // ---- 6) CACHES EM DISCO --------------------------------------
            // Só quando a restauração mexeu na instalação de verdade. Em modo
            // de teste a produção continua apontando para o banco dela: apagar
            // o cache dela seria estrago gratuito.
            if ($producao) {
                $relatorio['caches'] = Backup::limpaCachesEmDisco();
                // Caches EM MEMÓRIA (Settings, MailConfig) morrem no fim da
                // requisição sozinhos; o flush aqui é só para o resto DESTA
                // execução não continuar lendo o banco que não existe mais.
                Settings::flush();
                MailConfig::forget();
            } else {
                $avisos[] = 'Modo de teste: os caches em disco da instalação NÃO foram apagados '
                    . '(eles pertencem à produção, que não foi tocada).';
            }

            // ---- 7) CONFERIR OS DIGESTS ----------------------------------
            // Antes da higiene, de propósito: a higiene muda linhas por
            // decisão nossa, e conferir depois acusaria divergência onde nós
            // mesmos mexemos.
            if ($comBanco && $pdo !== null) {
                $relatorio['conferencia'] = self::confereDigests($pdo, $alvo, $manifesto, $avisos);
                if ($relatorio['conferencia']['divergentes'] !== []) {
                    $erros[] = count($relatorio['conferencia']['divergentes'])
                        . ' tabela(s) não bateram com o digest do manifesto.';
                }
            }

            // ---- 8) HIGIENE PÓS-RESTAURAÇÃO ------------------------------
            if ($comBanco && $pdo !== null) {
                if ($producao) {
                    $relatorio['higiene'] = self::higieneProducao(
                        $pdo,
                        $pacoteId,
                        (string) ($opts['fila_email'] ?? 'cancelar'),
                        $avisos
                    );
                }
                if ($sanitize) {
                    $relatorio['sanitize'] = self::sanitiza($pdo, $pacoteId, $avisos);
                }
            }
        } finally {
            self::rmDir($trabalho);
            self::destrava($lock);
        }

        $relatorio['avisos']   = array_values(array_unique(array_merge($avisos, $ins['avisos'])));
        $relatorio['erros']    = $erros;
        $relatorio['ok']       = $erros === [];
        $relatorio['segundos'] = round(microtime(true) - $inicio, 3);

        // audit_log.entity_id é varchar(40) e o id de um pacote tem 55
        // caracteres: passar o id inteiro faz o INSERT estourar e o
        // Core\Audit engole a falha no log de erros — ou seja, a restauração
        // não ficaria registrada em lugar nenhum. Vai o prefixo (que já
        // identifica data, hora e começo do sorteio) e o id completo nos
        // detalhes.
        Audit::log(
            $producao ? 'backup.restore.producao' : 'backup.restore.teste',
            'backup',
            substr($pacoteId, 0, 40),
            [
                'pacote'      => $pacoteId,
                'alvo'        => $alvo,
                'statements'  => $relatorio['banco']['statements'],
                'arquivos'    => $relatorio['arquivos']['restaurados'],
                'divergentes' => count($relatorio['conferencia']['divergentes']),
                'sanitizado'  => $sanitize,
                'ok'          => $relatorio['ok'],
            ],
            null,
            'core'
        );

        return $relatorio;
    }

    // =================================================================
    // Etapas da restauração
    // =================================================================

    /**
     * Deixa a conexão apontada para o banco certo.
     *
     * O USE é dado NESTA conexão, que é só nossa. O singleton Core\DB::pdo()
     * é da aplicação inteira: um USE nele faria o resto da requisição
     * escrever no banco errado.
     */
    private static function preparaAlvo(PDO $pdo, string $alvo, bool $producao, bool $criar, array $manifesto): void
    {
        if (!$producao) {
            if ($criar) {
                $charset = (string) ($manifesto['banco']['sessao']['charset'] ?? '');
                if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $charset) !== 1) {
                    $charset = (string) Config::get('db.charset', 'utf8mb4');
                }
                if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $charset) !== 1) {
                    $charset = 'utf8mb4';
                }
                // A collation do banco vem do manifesto: criar sem ela deixava
                // o banco restaurado em utf8mb4_general_ci, com ordenação de
                // acentos diferente da origem.
                $coll = (string) ($manifesto['banco']['sessao']['banco_collation'] ?? '');
                $bcs  = (string) ($manifesto['banco']['sessao']['banco_charset'] ?? '');
                if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $bcs) === 1) {
                    $charset = $bcs;
                }
                $sqlCriar = 'CREATE DATABASE IF NOT EXISTS ' . self::ident($alvo)
                          . ' DEFAULT CHARACTER SET ' . $charset;
                if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $coll) === 1) {
                    $sqlCriar .= ' DEFAULT COLLATE ' . $coll;
                }
                $pdo->exec($sqlCriar);
            }
            $pdo->exec('USE ' . self::ident($alvo));
        }

        // O pacote pode ter vindo de um servidor mais folgado: um comando maior
        // que o max_allowed_packet DAQUI derruba a conexão no meio da carga —
        // e a carga não tem volta. Melhor recusar antes de apagar qualquer
        // tabela do que parar na metade.
        $maior = (int) ($manifesto['banco']['maior_comando'] ?? 0);
        if ($maior > 0) {
            try {
                $daqui = (int) $pdo->query('SELECT @@SESSION.max_allowed_packet')->fetchColumn();
            } catch (\Throwable) {
                $daqui = 0;
            }
            if ($daqui > 0 && $maior > $daqui) {
                throw new RuntimeException(sprintf(
                    'Restauração RECUSADA antes de começar: o maior comando do pacote tem %s bytes '
                    . 'e o max_allowed_packet DESTE servidor é %s. A carga morreria no meio. '
                    . 'Peça ao provedor para aumentar o max_allowed_packet (ou restaure num servidor '
                    . 'com o mesmo limite da origem).',
                    number_format($maior, 0, ',', '.'),
                    number_format($daqui, 0, ',', '.')
                ));
            }
        }

        $atual = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($atual !== $alvo) {
            throw new RuntimeException(
                'A conexão está no banco "' . $atual . '" e a restauração é para "' . $alvo . '". '
                . 'Nada foi executado.'
            );
        }
    }

    /**
     * Variáveis de sessão que a carga mexe (para religar por valor no fim).
     *
     * @return array<string, string>
     */
    private static function sessaoAtual(PDO $pdo): array
    {
        $row = $pdo->query(
            'SELECT @@SESSION.sql_mode           AS sql_mode,
                    @@SESSION.foreign_key_checks AS foreign_key_checks,
                    @@SESSION.unique_checks      AS unique_checks,
                    @@SESSION.time_zone          AS time_zone'
        )->fetch(PDO::FETCH_ASSOC);

        return [
            'sql_mode'           => (string) ($row['sql_mode'] ?? ''),
            'foreign_key_checks' => (string) ($row['foreign_key_checks'] ?? ''),
            'unique_checks'      => (string) ($row['unique_checks'] ?? ''),
            'time_zone'          => (string) ($row['time_zone'] ?? ''),
        ];
    }

    /**
     * Extrai UMA entrada do pacote para um arquivo, conferindo o sha256 na
     * saída.
     *
     * Conferir de novo aqui não é paranoia repetida: entre a inspeção e a
     * extração o arquivo pode ter sido trocado, e é ESTE conteúdo que vai
     * virar comando SQL.
     */
    private static function extraiEntrada(string $pacote, string $entrada, string $destino, array $manifesto): void
    {
        $esperado = '';
        foreach ($manifesto['entradas'] ?? [] as $e) {
            if ((string) ($e['nome'] ?? '') === $entrada) {
                $esperado = (string) ($e['sha256'] ?? '');
            }
        }

        $achou = false;
        self::percorre(
            $pacote,
            static function (string $nome, int $tamanho, string $sha, string $tipo) use ($entrada, $esperado, &$achou): void {
                if ($nome !== $entrada) {
                    return;
                }
                $achou = true;
                if ($esperado !== '' && !hash_equals($esperado, $sha)) {
                    throw new RuntimeException(
                        'A entrada ' . $nome . ' saiu do pacote diferente do manifesto (sha256 '
                        . substr($sha, 0, 16) . '… ≠ ' . substr($esperado, 0, 16) . '…). Restauração abortada.'
                    );
                }
            },
            static function (string $nome, string $tipo, int $tamanho) use ($entrada, $destino) {
                return $nome === $entrada ? self::gravador($destino) : null;
            }
        );

        if (!$achou) {
            throw new RuntimeException('O pacote não traz ' . $entrada . '.');
        }
    }

    /**
     * Grava os arquivos do pacote sob $raiz.
     *
     * Segunda passagem pelo pacote em vez de uma área de estágio: a
     * hospedagem tem cota, e duplicar todos os uploads em disco é o jeito
     * mais fácil de estourá-la justo durante a restauração.
     *
     * @return array{restaurados:int, bytes:int, ignorados:int, destino:string}
     */
    private static function restauraArquivos(
        string $pacote,
        array $manifesto,
        string $raiz,
        bool $comConfig,
        array &$avisos
    ): array {
        if (!is_dir($raiz) && !@mkdir($raiz, 0770, true) && !is_dir($raiz)) {
            throw new RuntimeException('Não foi possível criar a raiz de restauração: ' . $raiz);
        }
        $raizReal = realpath($raiz);
        if ($raizReal === false) {
            throw new RuntimeException('Raiz de restauração inacessível: ' . $raiz);
        }

        $esperado = [];
        foreach ($manifesto['entradas'] ?? [] as $e) {
            $esperado[(string) ($e['nome'] ?? '')] = (string) ($e['sha256'] ?? '');
        }

        $stats = ['restaurados' => 0, 'bytes' => 0, 'ignorados' => 0, 'destino' => $raizReal];

        self::percorre(
            $pacote,
            static function (string $nome, int $tamanho, string $sha, string $tipo) use ($esperado): void {
                if (isset($esperado[$nome]) && $esperado[$nome] !== '' && !hash_equals($esperado[$nome], $sha)) {
                    throw new RuntimeException('A entrada ' . $nome . ' saiu do pacote diferente do manifesto.');
                }
            },
            function (string $nome, string $tipo, int $tamanho) use ($raizReal, $comConfig, &$stats, &$avisos) {
                if (!str_starts_with($nome, 'arquivos/')) {
                    return null;
                }
                $rel = substr($nome, strlen('arquivos/'));

                if (str_starts_with($rel, 'config/') && !$comConfig) {
                    $avisos[] = 'config/config.php estava no pacote e NÃO foi restaurado: ele traz a senha do '
                        . 'banco do ambiente de ORIGEM e derrubaria esta instalação. Use restore_config para insistir.';
                    $stats['ignorados']++;
                    return null;
                }

                $destino = self::caminhoSeguro($raizReal, $rel);
                if ($destino === null) {
                    $avisos[] = 'Entrada com caminho suspeito, ignorada: ' . $nome;
                    $stats['ignorados']++;
                    return null;
                }

                if ($tipo === '5') {
                    @mkdir($destino, 0770, true);
                    return null;
                }

                $dir = dirname($destino);
                if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                    $avisos[] = 'Não foi possível criar o diretório de ' . $rel . '; entrada ignorada.';
                    $stats['ignorados']++;
                    return null;
                }
                $stats['restaurados']++;
                $stats['bytes'] += $tamanho;
                if ($stats['restaurados'] === 1 && $raizReal === BASE_PATH) {
                    // O pacote carimba 0640 em toda entrada (o motor não
                    // guarda o modo original), então é com 0640 que os
                    // arquivos voltam. Onde o servidor web roda com outro
                    // usuário, isso muda quem consegue ler os anexos.
                    $avisos[] = 'Os arquivos voltaram com modo 0640 (só o dono lê e escreve) — é o modo que o '
                        . 'pacote carimba. Se o servidor web roda com outro usuário, confira as permissões de uploads/.';
                }
                // O temporário fica no MESMO diretório do destino: rename
                // entre sistemas de arquivos diferentes falha, e é assim que
                // um arquivo ficaria pela metade no lugar do bom.
                return self::gravador($destino);
            }
        );

        return $stats;
    }

    /**
     * Confere linhas e digest de cada tabela do manifesto contra o banco
     * restaurado.
     *
     * É a prova de que o DADO voltou igual — "o SQL rodou sem erro" não é a
     * mesma coisa.
     *
     * @return array{tabelas:int, iguais:int, linhas:int, divergentes:string[], indicativas:string[]}
     */
    private static function confereDigests(PDO $pdo, string $alvo, array $manifesto, array &$avisos): array
    {
        $out = ['tabelas' => 0, 'iguais' => 0, 'linhas' => 0, 'divergentes' => [],
                'indicativas' => [], 'sobras' => []];

        // Tabela que existe no ALVO e não no pacote sobrevive à restauração:
        // o banco "restaurado" fica sendo o pacote MAIS o que já estava lá.
        // Não dá para apagar por conta própria (pode ser de outro sistema que
        // divide o banco), mas tem de aparecer no relatório — senão a
        // conferência diz "tudo certo" sobre um banco que não é o do backup.
        try {
            $stmtSobras = $pdo->prepare(
                'SELECT TABLE_NAME FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\''
            );
            $stmtSobras->execute([$alvo]);
            $noAlvo = array_map('strval', $stmtSobras->fetchAll(PDO::FETCH_COLUMN));
            $doPacote = array_map('strval', array_keys((array) ($manifesto['banco']['tabelas'] ?? [])));
            $out['sobras'] = array_values(array_diff($noAlvo, $doPacote));
            if ($out['sobras'] !== []) {
                $avisos[] = count($out['sobras']) . ' tabela(s) já existiam no banco de destino e NÃO estão '
                    . 'no pacote — continuam lá como estavam: '
                    . implode(', ', array_slice($out['sobras'], 0, 8))
                    . (count($out['sobras']) > 8 ? '…' : '') . '.';
            }
        } catch (\Throwable $e) {
            $avisos[] = 'Não foi possível listar as tabelas do destino: ' . $e->getMessage();
        }

        foreach ($manifesto['banco']['tabelas'] ?? [] as $tabela => $esperado) {
            $tabela = (string) $tabela;
            $out['tabelas']++;
            try {
                $d = BackupDump::digestTable($pdo, $tabela, $alvo);
            } catch (\Throwable $e) {
                $out['divergentes'][] = $tabela . ': não foi possível calcular o digest — ' . $e->getMessage();
                continue;
            }

            $out['linhas'] += $d['linhas'];
            $linhasOk = $d['linhas'] === (int) ($esperado['linhas'] ?? -1);
            $digestOk = hash_equals((string) ($esperado['digest'] ?? ''), $d['digest']);

            if ($linhasOk && $digestOk) {
                $out['iguais']++;
                if (($esperado['chave'] ?? '') === 'limit-offset') {
                    // Sem PRIMARY KEY o manifesto já avisa que o digest é
                    // indicativo: bateu, mas não vale como prova.
                    $out['indicativas'][] = $tabela;
                }
                continue;
            }

            $out['divergentes'][] = $tabela . ': ' . $d['linhas'] . ' linha(s) / digest '
                . substr($d['digest'], 0, 12) . '… — manifesto: ' . (int) ($esperado['linhas'] ?? -1)
                . ' / ' . substr((string) ($esperado['digest'] ?? ''), 0, 12) . '…'
                . (($esperado['chave'] ?? '') === 'limit-offset' ? ' (tabela sem PK: digest indicativo)' : '');
        }

        if ($out['indicativas'] !== []) {
            $avisos[] = count($out['indicativas']) . ' tabela(s) sem PRIMARY KEY conferiram, mas o digest delas é '
                . 'apenas INDICATIVO (a ordem de leitura não é garantida pelo servidor): '
                . implode(', ', array_slice($out['indicativas'], 0, 6)) . '.';
        }

        return $out;
    }

    // =================================================================
    // Higiene pós-restauração (produção)
    // =================================================================

    /**
     * O que o dump traz de volta e faria estrago se ficasse como está.
     *
     * @return array<string, mixed>
     */
    private static function higieneProducao(PDO $pdo, string $pacoteId, string $modoFila, array &$avisos): array
    {
        $quando = date('d/m/Y H:i');
        $out = [
            'mail_queue'       => ['modo' => $modoFila, 'afetadas' => 0],
            'password_resets'  => 0,
            'login_attempts'   => 0,
            'epoca_de_sessao'  => 0,
        ];

        // --- Fila de e-mail ------------------------------------------------
        // A fila voltou como estava no dia do backup. O que estava 'pending'
        // ali JÁ FOI ENTREGUE no mundo real: deixar assim faz o cron mandar
        // ao corpo clínico uma leva inteira de e-mails repetidos.
        try {
            if ($modoFila === 'manter') {
                $pend = (int) $pdo->query("SELECT COUNT(*) FROM mail_queue WHERE status = 'pending'")->fetchColumn();
                $out['mail_queue']['afetadas'] = 0;
                if ($pend > 0) {
                    $avisos[] = 'ATENÇÃO: ' . $pend . ' mensagem(ns) ficaram como "pending" na fila por pedido seu '
                        . '(fila_email=manter). A próxima rodada do cron vai ENVIAR todas de novo.';
                }
            } elseif ($modoFila === 'apagar') {
                $st = $pdo->prepare("DELETE FROM mail_queue WHERE status = 'pending'");
                $st->execute();
                $out['mail_queue']['afetadas'] = $st->rowCount();
            } else {
                $st = $pdo->prepare(
                    "UPDATE mail_queue
                        SET status = 'failed', error_code = 'RESTAURACAO', last_error = ?,
                            reserved_by = NULL, reserved_at = NULL
                      WHERE status = 'pending'"
                );
                $st->execute([
                    'Cancelada pela restauração do backup ' . $pacoteId . ' em ' . $quando
                    . ': a fila voltou do pacote e reenviaria e-mails já entregues.',
                ]);
                $out['mail_queue']['afetadas'] = $st->rowCount();
                if ($out['mail_queue']['afetadas'] > 0) {
                    $avisos[] = $out['mail_queue']['afetadas'] . ' mensagem(ns) pendentes foram CANCELADAS '
                        . '(marcadas como "failed" com o código RESTAURACAO). Elas ficam registradas para consulta — '
                        . 'mas "Reenviar falhas" na tela de e-mail devolveria todas à fila: se não quiser correr esse '
                        . 'risco, apague-as (fila_email=apagar).';
                }
            }
        } catch (\Throwable $e) {
            $avisos[] = 'Não foi possível tratar a fila de e-mail (' . $e->getMessage()
                . '). CONFIRA mail_queue ANTES da próxima rodada do cron.';
        }

        // --- Tokens de redefinição de senha --------------------------------
        // Um token vale para quem o recebeu por e-mail. Os que voltaram do
        // pacote podem já ter sido usados (e o "usado" se perdeu na volta);
        // os emitidos depois do backup não existem mais no banco. Nos dois
        // casos, o certo é zerar e pedir um link novo.
        try {
            $st = $pdo->prepare('DELETE FROM password_resets');
            $st->execute();
            $out['password_resets'] = $st->rowCount();
        } catch (\Throwable $e) {
            $avisos[] = 'Não foi possível limpar password_resets: ' . $e->getMessage();
        }

        // --- Tentativas de login -------------------------------------------
        // O contador do bloqueio por tentativas voltou ao que era: sem limpar,
        // funcionário que errou a senha há meses volta bloqueado sem motivo.
        try {
            $st = $pdo->prepare('DELETE FROM login_attempts');
            $st->execute();
            $out['login_attempts'] = $st->rowCount();
        } catch (\Throwable $e) {
            $avisos[] = 'Não foi possível limpar login_attempts: ' . $e->getMessage();
        }

        // --- Época das sessões ---------------------------------------------
        $out['epoca_de_sessao'] = self::sessionEpochBump($pdo);
        $avisos[] = 'As sessões abertas antes da restauração devem cair: a época foi avançada para '
            . $out['epoca_de_sessao'] . '. Enquanto o trecho de integração não estiver colado em '
            . 'core/bootstrap.php, o marcador é gravado mas NINGUÉM o confere (veja "integracao" no relatório).';

        return $out;
    }

    /**
     * Neutraliza credenciais na base restaurada (MODO DE TESTE).
     *
     * Sem isto, cada restauração de teste cria uma cópia funcional das
     * credenciais de todos os funcionários — inclusive o segundo fator, que
     * é justamente o que deveria salvar a situação quando a senha vaza.
     *
     * @return array<string, mixed>
     */
    private static function sanitiza(PDO $pdo, string $pacoteId, array &$avisos): array
    {
        $out = ['usuarios' => 0, 'com_2fa' => 0, 'email' => false, 'fila' => 0, 'tokens' => 0, 'tentativas' => 0];

        try {
            $out['com_2fa'] = (int) $pdo->query(
                'SELECT COUNT(*) FROM users WHERE two_factor_enabled = 1 OR two_factor_secret IS NOT NULL'
            )->fetchColumn();

            $st = $pdo->prepare(
                'UPDATE users SET two_factor_secret = NULL, two_factor_enabled = 0, password_hash = ?'
            );
            $st->execute([self::SENHA_NEUTRA]);
            $out['usuarios'] = $st->rowCount();
        } catch (\Throwable $e) {
            $avisos[] = 'FALHA AO NEUTRALIZAR CREDENCIAIS (' . $e->getMessage()
                . '): trate esta base como se fosse produção — ela ainda tem as senhas de todo mundo.';
        }

        // Envio de e-mail desligado na base restaurada: uma cópia de teste
        // apontada para o SMTP de verdade manda e-mail de verdade para
        // paciente e funcionário.
        try {
            $up = $pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            foreach ([
                'mail.enabled'        => '0',
                'mail.host'           => '',
                'mail.user'           => '',
                'mail.pass'           => '',
                self::CHAVE_AMBIENTE  => 'teste',
                self::CHAVE_ORIGEM    => $pacoteId . ' restaurado em ' . date('c'),
            ] as $k => $v) {
                $up->execute([$k, $v]);
            }
            $out['email'] = true;
        } catch (\Throwable $e) {
            $avisos[] = 'Não foi possível desligar o e-mail na base de teste: ' . $e->getMessage();
        }

        try {
            $st = $pdo->prepare("UPDATE mail_queue SET status = 'failed', error_code = 'RESTAURACAO',
                                        last_error = 'Base de teste: envio cancelado na restauração.',
                                        reserved_by = NULL, reserved_at = NULL
                                  WHERE status = 'pending'");
            $st->execute();
            $out['fila'] = $st->rowCount();

            $st = $pdo->prepare('DELETE FROM password_resets');
            $st->execute();
            $out['tokens'] = $st->rowCount();

            $st = $pdo->prepare('DELETE FROM login_attempts');
            $st->execute();
            $out['tentativas'] = $st->rowCount();
        } catch (\Throwable $e) {
            $avisos[] = 'Limpeza parcial na base de teste: ' . $e->getMessage();
        }

        if ((bool) Config::get('moodle.enabled', false)) {
            $avisos[] = 'O login por Moodle está LIGADO no config desta instalação: se alguém apontar uma cópia '
                . 'do sistema para a base de teste, o Moodle ainda autenticaria os usuários dela.';
        }

        $avisos[] = 'Base de teste sanitizada: senhas neutralizadas, segundo fator zerado e e-mail desligado. '
            . 'Ninguém entra nela com as credenciais de produção.';

        return $out;
    }

    // =================================================================
    // Época das sessões
    // =================================================================

    /**
     * Época atual das sessões (0 = nunca restaurado / banco fora do ar).
     *
     * Quem chama é o bootstrap, a cada requisição: se a sessão do usuário for
     * mais velha que esta época, ela nasceu num banco que não existe mais e
     * precisa cair.
     */
    public static function sessionEpoch(): int
    {
        try {
            return (int) (Settings::get(self::CHAVE_EPOCA, '0') ?? '0');
        } catch (\Throwable) {
            // Banco fora do ar: melhor não derrubar ninguém por causa disso.
            return 0;
        }
    }

    /**
     * Avança a época (derruba todas as sessões abertas).
     *
     * O valor é o horário, mas nunca menor que o anterior + 1: restaurar um
     * backup ANTIGO traz de volta uma época antiga, e ela não pode fazer o
     * marcador andar para trás.
     *
     * @param PDO|null $pdo conexão já apontada para o banco certo (a da
     *                      restauração); sem ela, usa Core\Settings.
     */
    public static function sessionEpochBump(?PDO $pdo = null): int
    {
        if ($pdo === null) {
            $nova = max(time(), self::sessionEpoch() + 1);
            Settings::set(self::CHAVE_EPOCA, (string) $nova);
            return $nova;
        }

        $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $st->execute([self::CHAVE_EPOCA]);
        $atual = (int) ($st->fetchColumn() ?: 0);
        $nova  = max(time(), $atual + 1);

        $up = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $up->execute([self::CHAVE_EPOCA, (string) $nova]);

        // O cache em memória do Settings é desta requisição; o flush evita
        // que o resto dela leia a época antiga.
        Settings::flush();
        return $nova;
    }

    /**
     * O trecho que o integrador precisa colar para que a época seja
     * conferida — com arquivo e linha.
     *
     * Esta classe NÃO altera core/bootstrap.php nem index.php de propósito:
     * mexer no arranque da aplicação é decisão de quem integra, não efeito
     * colateral de uma restauração.
     *
     * @return array{arquivo:string, linha:int, ancora:string, trecho:string}
     */
    public static function sessionEpochHint(): array
    {
        // core/ viaja com o CÓDIGO. Este era o quinto uso de BASE_PATH com
        // sentido de "onde está o código" — o commit da separação dizia
        // serem exatamente quatro, e estava errado. Com as raízes separadas o
        // arquivo não existia, o @file() engolia o aviso e a instrução dada
        // ao administrador virava "cole em core/bootstrap.php, logo depois da
        // linha 0".
        $rel     = 'core/bootstrap.php';
        $arquivo = APP_PATH . '/' . $rel;
        $ancora  = 'Core\Session::start();';
        $linha   = 0;

        foreach (@file($arquivo) ?: [] as $i => $l) {
            if (str_contains($l, $ancora)) {
                $linha = $i + 1;
                break;
            }
        }

        $trecho = <<<'PHP'
    // Uma restauração de backup derruba as sessões abertas antes dela: o
    // usuário logado pode não existir mais no banco que acabou de voltar, e
    // as permissões dele com certeza são as de antes. O marcador vive em
    // settings (volta junto com o banco) e Core\BackupRestore o avança
    // depois de cada restauração em produção.
    $epocaBackup = Core\BackupRestore::sessionEpoch();
    if ((int) ($_SESSION['_backup_epoch'] ?? 0) < $epocaBackup) {
        Core\Session::destroy();
        Core\Session::start();
        $_SESSION['_backup_epoch'] = $epocaBackup;
    }
PHP;

        return [
            // Caminho ABSOLUTO: com o código fora do public_html, "core/
            // bootstrap.php" não diz a ninguém onde o arquivo está. E linha 0
            // significa que não achei a âncora — dizer isso é melhor que
            // mandar colar "depois da linha 0".
            'arquivo' => $arquivo,
            'linha'   => $linha,
            'ancora'  => $ancora,
            'trecho'  => $trecho,
        ];
    }

    // =================================================================
    // Leitura do pacote (tar.gz)
    // =================================================================

    /**
     * Percorre o .tar.gz chamando $fn(nome, tamanho, sha256, tipo) por
     * entrada, e $extrai(nome, tipo, tamanho) quando for para gravar.
     *
     * Leitor próprio, e não o do Core\Backup: aquele é privado, e o motor não
     * se altera por conveniência da restauração. De quebra, quem confere o
     * pacote na hora de restaurar não é o mesmo código que o escreveu — erro
     * simétrico (escrever e ler torto do mesmo jeito) não passaria batido.
     *
     * @param callable|null $extrai callable(string,string,int): (callable(?string):void)|null
     */
    private static function percorre(string $pacote, callable $fn, ?callable $extrai = null): void
    {
        $gz = @gzopen($pacote, 'rb');
        if ($gz === false) {
            throw new RuntimeException('Não foi possível abrir o pacote ' . self::relativo($pacote) . '.');
        }

        try {
            $nomeLongo = null;
            while (true) {
                $h = self::leExato($gz, self::BLOCO);
                if ($h === '') {
                    // Sem os dois blocos zerados do fim, o arquivo acabou
                    // antes da hora: pacote truncado.
                    throw new RuntimeException('o pacote termina no meio (faltam os blocos de fim do tar)');
                }
                if (strlen($h) < self::BLOCO) {
                    throw new RuntimeException('bloco de cabeçalho incompleto (' . strlen($h) . ' bytes)');
                }
                if (trim($h, "\0") === '') {
                    return; // fim normal
                }

                $soma = (int) octdec(trim(substr($h, 148, 8), "\0 "));
                $calc = 0;
                for ($i = 0; $i < self::BLOCO; $i++) {
                    $calc += ($i >= 148 && $i < 156) ? 32 : ord($h[$i]);
                }
                if ($soma !== $calc) {
                    throw new RuntimeException('cabeçalho de entrada corrompido (soma de verificação não confere)');
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

                if ($tipo === 'L') { // GNU LongName: o nome real vem no corpo
                    $nomeLongo = rtrim(self::leExato($gz, $tamanho), "\0");
                    self::pula($gz, $tamanho);
                    continue;
                }

                $ctx   = hash_init('sha256');
                $resta = $tamanho;
                $saida = $extrai !== null ? $extrai($nome, $tipo, $tamanho) : null;
                try {
                    while ($resta > 0) {
                        $bloco = self::leExato($gz, (int) min(self::CHUNK, $resta));
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
                        $saida(null); // fecha e renomeia
                    }
                } catch (\Throwable $e) {
                    if ($saida !== null) {
                        // Descarta o temporário: entrada pela metade não vira
                        // arquivo com nome de bom.
                        try {
                            $saida(false);
                        } catch (\Throwable) {
                        }
                    }
                    throw $e;
                }
                self::pula($gz, $tamanho);

                $fn($nome, $tamanho, hash_final($ctx), $tipo);
            }
        } finally {
            @gzclose($gz);
        }
    }

    /** Lê o manifest.json de dentro do pacote (é ele que vale). */
    private static function manifestoDoPacote(string $pacote): ?array
    {
        $json = null;
        try {
            self::percorre(
                $pacote,
                static function (): void {
                },
                static function (string $nome, string $tipo, int $tamanho) use (&$json) {
                    if ($nome !== 'manifest.json') {
                        return null;
                    }
                    $buf = '';
                    return static function (string|false|null $bloco) use (&$buf, &$json): void {
                        if ($bloco === false) {
                            return; // abortado
                        }
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

    /** @param resource $gz */
    private static function leExato($gz, int $n): string
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

    /** Pula o enchimento até fechar o bloco de 512. @param resource $gz */
    private static function pula($gz, int $tamanho): void
    {
        $resto = $tamanho % self::BLOCO;
        if ($resto !== 0) {
            self::leExato($gz, self::BLOCO - $resto);
        }
    }

    /**
     * Gravador de uma entrada: escreve em .part e só renomeia ao fechar.
     *
     * Toda escrita tem o retorno conferido: cota estourada devolve escrita
     * curta, não exceção — sem conferir, o arquivo "termina bem" pela metade.
     * Receber false significa abortar e apagar o temporário.
     */
    private static function gravador(string $destino): callable
    {
        $tmp = dirname($destino) . '/.' . basename($destino) . '.' . bin2hex(random_bytes(4)) . '.part';
        $fh  = @fopen($tmp, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Não foi possível gravar ' . self::relativo($destino) . '.');
        }

        return static function (string|false|null $bloco) use (&$fh, $tmp, $destino): void {
            if ($bloco === false) {
                if (is_resource($fh)) {
                    @fclose($fh);
                }
                @unlink($tmp);
                return;
            }
            if ($bloco === null) {
                if (!fclose($fh)) {
                    @unlink($tmp);
                    throw new RuntimeException('Falha ao fechar ' . self::relativo($destino)
                        . ' (a escrita pode não ter chegado ao disco).');
                }
                if (!@rename($tmp, $destino)) {
                    @unlink($tmp);
                    throw new RuntimeException('Falha ao mover o arquivo para ' . self::relativo($destino) . '.');
                }
                // 0640 é exatamente o modo que Core\Backup carimba em cada
                // entrada do pacote: restaurar com outro modo faria o arquivo
                // voltar diferente do que o backup diz que ele é.
                @chmod($destino, 0640);
                return;
            }
            $total = strlen($bloco);
            $pos   = 0;
            while ($pos < $total) {
                $n = @fwrite($fh, $pos === 0 ? $bloco : substr($bloco, $pos));
                if ($n === false || $n === 0) {
                    throw new RuntimeException('Escrita curta ao restaurar ' . self::relativo($destino)
                        . ' (disco cheio ou cota da conta estourada).');
                }
                $pos += $n;
            }
        };
    }

    // =================================================================
    // Utilidades
    // =================================================================

    /**
     * Destino absoluto e seguro para uma entrada, ou null.
     *
     * Um pacote é um arquivo: ele pode ter sido montado por qualquer um. Sem
     * esta checagem, uma entrada chamada "arquivos/../../etc/cron.d/x" sairia
     * do diretório de destino.
     */
    private static function caminhoSeguro(string $raizReal, string $rel): ?string
    {
        if ($rel === '' || str_contains($rel, "\0") || str_starts_with($rel, '/') || str_contains($rel, '\\')) {
            return null;
        }
        if (preg_match('/^[A-Za-z]:/', $rel) === 1) {
            return null; // caminho absoluto do Windows
        }
        foreach (explode('/', $rel) as $parte) {
            if ($parte === '..' || $parte === '.') {
                return null;
            }
        }
        // Só as raízes que o backup empacota. 'config/' NÃO é prefixo: o
        // único arquivo de configuração que um pacote pode trazer é
        // config/config.php, e é igualdade exata. Como prefixo, um pacote
        // adulterado (íntegro, com hashes certos, só com NOMES hostis) fazia
        // esta rota gravar config/shell.php e config/.ssh/authorized_keys
        // dentro de CONFIG_PATH — a mesma regra que Backup::destinoSeguro()
        // já aplicava na outra rota de restauração, e que esta não aplicava.
        // As duas rotas passam a usar o MESMO predicado, para não divergirem
        // de novo.
        if (!Backup::destinoPermitido($rel)) {
            return null;
        }

        // "storage/" e "config/" no pacote são TOKENS, não endereços: as
        // pastas podem ter sido movidas para fora da área pública, e a
        // tradução mora em Backup::caminhoFisico(), um lugar só.
        //
        // MAS ela só vale quando a raiz pedida É a instalação. A restauração
        // de teste (e o --files-dir) aponta a raiz para um diretório
        // descartável, e ali o pacote precisa cair INTEIRO lá dentro: traduzir
        // 'storage/...' para o STORAGE_PATH real faria o ensaio escrever na
        // instalação de produção, que é o oposto de um ensaio.
        $base = realpath(BASE_PATH);
        if ($base !== false && $raizReal === $base) {
            $destino    = Backup::caminhoFisico(rtrim($rel, '/'));
            $candidatas = [BASE_PATH, defined('STORAGE_PATH') ? STORAGE_PATH : null,
                           defined('CONFIG_PATH') ? CONFIG_PATH : null];
        } else {
            $destino    = $raizReal . '/' . rtrim($rel, '/');
            $candidatas = [$raizReal];
        }

        // Segunda barreira: se algum diretório do caminho for um link
        // simbólico para fora das raízes conhecidas, o realpath do que já
        // existe entrega.
        $raizes = [];
        foreach ($candidatas as $r) {
            if ($r !== null && ($rr = realpath($r)) !== false) {
                $raizes[$rr] = true;
            }
        }

        $existente = $destino;
        while (!file_exists($existente) && dirname($existente) !== $existente) {
            $existente = dirname($existente);
        }
        $real = realpath($existente);
        if ($real === false) {
            return null;
        }
        foreach (array_keys($raizes) as $raiz) {
            if ($real === $raiz || str_starts_with($real, $raiz . '/')) {
                return $destino;
            }
        }
        return null;
    }

    /** Identificador citado (o nome vem de configuração/manifesto, nunca cru). */
    private static function ident(string $nome): string
    {
        return '`' . str_replace('`', '``', $nome) . '`';
    }

    /** Aceita id de backup, nome de arquivo ou caminho completo. */
    private static function resolvePacote(string $arquivo): string
    {
        $a = trim($arquivo);
        if ($a === '') {
            throw new RuntimeException('Informe o backup a restaurar (id ou caminho do arquivo).');
        }
        if (is_file($a)) {
            return realpath($a) ?: $a;
        }
        $item = Backup::find($a);
        if ($item !== null && is_file((string) $item['caminho'])) {
            return (string) $item['caminho'];
        }
        $noDir = Backup::dir() . '/' . basename($a);
        if (is_file($noDir)) {
            return $noDir;
        }
        throw new RuntimeException('Pacote não encontrado: ' . $arquivo);
    }

    /**
     * Trava compartilhada com Core\Backup (mesmo arquivo de trava).
     *
     * @return resource
     */
    private static function trava()
    {
        Backup::ensureDir();
        $fh = @fopen(Backup::tmpDir() . '/backup.lock', 'c');
        if ($fh === false) {
            throw new RuntimeException('Não foi possível abrir a trava de backup/restauração.');
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new RuntimeException(
                'Há um backup (ou outra restauração) em andamento. Espere terminar: '
                . 'as duas coisas ao mesmo tempo empacotariam um banco pela metade.'
            );
        }
        return $fh;
    }

    /** @param resource $fh */
    private static function destrava($fh): void
    {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }

    /** Apaga o diretório de trabalho. */
    private static function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . '/' . $item;
            if (is_dir($p) && !is_link($p)) {
                self::rmDir($p);
                continue;
            }
            @unlink($p);
        }
        @rmdir($dir);
    }

    /** Caminho relativo à instalação (mensagem de erro não expõe o caminho do servidor). */
    private static function relativo(string $caminho): string
    {
        return str_starts_with($caminho, BASE_PATH . '/')
            ? substr($caminho, strlen(BASE_PATH) + 1)
            : basename($caminho);
    }
}
