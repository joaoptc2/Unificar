<?php

declare(strict_types=1);

namespace Core;

/**
 * Checkup de saúde do sistema (Administração > Atualizações de banco).
 *
 * A pergunta que esta tela responde é a que aparece depois de toda
 * atualização: "está tudo certo?". Antes era preciso abrir cinco telas e
 * conferir cada uma; aqui tudo é verificado de uma vez e o que estiver
 * errado vem com o que fazer a respeito.
 *
 * Cada item tem: nível (ok | aviso | erro | info), título, detalhe e — quando
 * há problema — a ação sugerida. Nada aqui ALTERA o sistema: é só leitura,
 * segura para rodar a qualquer momento, inclusive em produção.
 */
final class HealthCheck
{
    /** Categorias na ordem em que aparecem na tela. */
    public const GRUPOS = [
        'banco'     => ['label' => 'Banco de dados',   'icon' => 'bi-database'],
        'php'       => ['label' => 'PHP e extensões',  'icon' => 'bi-filetype-php'],
        'arquivos'  => ['label' => 'Pastas e arquivos','icon' => 'bi-folder'],
        'seguranca' => ['label' => 'Segurança',        'icon' => 'bi-shield-lock'],
        'rotinas'   => ['label' => 'Rotinas e e-mail', 'icon' => 'bi-clock-history'],
        'backup'    => ['label' => 'Backup',           'icon' => 'bi-hdd-stack'],
    ];

    /**
     * Roda todos os grupos.
     * @return array<string, array<int, array{nivel:string,titulo:string,detalhe:string,acao?:string}>>
     */
    public static function all(): array
    {
        return [
            'banco'     => self::banco(),
            'php'       => self::php(),
            'arquivos'  => self::arquivos(),
            'seguranca' => self::seguranca(),
            'rotinas'   => self::rotinas(),
            'backup'    => self::backup(),
        ];
    }

    /** Resumo para o cabeçalho: quantos ok/aviso/erro. */
    public static function resumo(array $grupos): array
    {
        $r = ['ok' => 0, 'aviso' => 0, 'erro' => 0, 'info' => 0];
        foreach ($grupos as $itens) {
            foreach ($itens as $i) {
                $r[$i['nivel']] = ($r[$i['nivel']] ?? 0) + 1;
            }
        }
        $r['total']   = array_sum([$r['ok'], $r['aviso'], $r['erro'], $r['info']]);
        $r['saudavel'] = $r['erro'] === 0;
        return $r;
    }

    private static function item(string $nivel, string $titulo, string $detalhe, string $acao = ''): array
    {
        $i = ['nivel' => $nivel, 'titulo' => $titulo, 'detalhe' => $detalhe];
        if ($acao !== '') {
            $i['acao'] = $acao;
        }
        return $i;
    }

    // ── Banco de dados ─────────────────────────────────────────────────────

    private static function banco(): array
    {
        $out = [];

        try {
            $v = DB::queryOne('SELECT VERSION() AS v')['v'] ?? '?';
            $out[] = self::item('info', 'Servidor', (string) $v);
        } catch (\Throwable $e) {
            return [self::item('erro', 'Conexão', 'Não foi possível consultar o banco: ' . $e->getMessage(),
                'Confira o bloco db em config/config.php e se o servidor está no ar.')];
        }

        // Migrações pendentes — o motivo mais comum de "sumiu uma coluna".
        try {
            $pend = Migrations::pending();
            $out[] = count($pend) === 0
                ? self::item('ok', 'Atualizações de banco', 'Todas as migrações foram aplicadas.')
                : self::item('erro', 'Atualizações de banco',
                    count($pend) . ' migração(ões) pendente(s): ' . implode(', ', array_slice($pend, 0, 5))
                    . (count($pend) > 5 ? '…' : ''),
                    'Aplique as pendentes nesta mesma tela, de preferência depois de um backup.');
        } catch (\Throwable $e) {
            $out[] = self::item('aviso', 'Atualizações de banco', 'Não foi possível verificar: ' . $e->getMessage());
        }

        // Tabelas esperadas pelos módulos ativos.
        try {
            $faltando = self::tabelasFaltando();
            $out[] = $faltando === []
                ? self::item('ok', 'Tabelas dos módulos', 'Todas as tabelas esperadas existem.')
                : self::item('erro', 'Tabelas dos módulos',
                    count($faltando) . ' tabela(s) ausente(s): ' . implode(', ', array_slice($faltando, 0, 8))
                    . (count($faltando) > 8 ? '…' : ''),
                    'Aplique as atualizações de banco; se persistir, rode o SQL do módulo (sql/modules/).');
        } catch (\Throwable $e) {
            $out[] = self::item('aviso', 'Tabelas dos módulos', 'Não foi possível verificar: ' . $e->getMessage());
        }

        // Motor e collation: tabela em MyISAM não tem transação nem chave
        // estrangeira, e some da restauração consistente do backup.
        try {
            $ruins = DB::query(
                "SELECT table_name AS t, engine AS e FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND engine IS NOT NULL AND engine <> 'InnoDB'"
            );
            $out[] = $ruins === []
                ? self::item('ok', 'Motor das tabelas', 'Todas em InnoDB (transações e integridade garantidas).')
                : self::item('aviso', 'Motor das tabelas',
                    count($ruins) . ' fora de InnoDB: ' . implode(', ', array_map(fn ($r) => $r['t'] . ' (' . $r['e'] . ')', array_slice($ruins, 0, 5))),
                    'Converta com ALTER TABLE <tabela> ENGINE=InnoDB.');
        } catch (\Throwable $e) {
            $out[] = self::item('info', 'Motor das tabelas', 'Não verificado: ' . $e->getMessage());
        }

        try {
            $cs = DB::query(
                "SELECT table_name AS t, table_collation AS c FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_collation IS NOT NULL
                   AND table_collation NOT LIKE 'utf8mb4%'"
            );
            $out[] = $cs === []
                ? self::item('ok', 'Codificação', 'Todas as tabelas em utf8mb4 (acentos e emoji sem perda).')
                : self::item('aviso', 'Codificação',
                    count($cs) . ' tabela(s) fora de utf8mb4: ' . implode(', ', array_map(fn ($r) => $r['t'], array_slice($cs, 0, 5))),
                    'Converta para utf8mb4 para evitar perda de acentos.');
        } catch (\Throwable $e) {
            $out[] = self::item('info', 'Codificação', 'Não verificado.');
        }

        // Tamanho — para o admin saber o que esperar do backup.
        try {
            $r = DB::queryOne(
                "SELECT COUNT(*) AS n, COALESCE(SUM(data_length + index_length), 0) AS bytes
                 FROM information_schema.tables WHERE table_schema = DATABASE()"
            );
            $out[] = self::item('info', 'Tamanho',
                (int) $r['n'] . ' tabelas · ' . self::bytes((int) $r['bytes']));
        } catch (\Throwable $e) { /* informativo */ }

        // max_allowed_packet pequeno quebra a restauração de backups grandes.
        try {
            $p = (int) (DB::queryOne("SHOW VARIABLES LIKE 'max_allowed_packet'")['Value'] ?? 0);
            $out[] = $p >= 16 * 1024 * 1024
                ? self::item('ok', 'max_allowed_packet', self::bytes($p) . ' — suficiente para restaurar backups.')
                : self::item('aviso', 'max_allowed_packet', self::bytes($p) . ' é baixo.',
                    'Aumente para pelo menos 16M no servidor, senão a restauração de um backup grande falha no meio.');
        } catch (\Throwable $e) { /* informativo */ }

        return $out;
    }

    /** Tabelas declaradas nos SQL dos módulos ativos que não existem no banco. */
    private static function tabelasFaltando(): array
    {
        $existentes = [];
        foreach (DB::query('SHOW TABLES') as $linha) {
            $existentes[strtolower((string) reset($linha))] = true;
        }

        $esperadas = [];
        $sqlRaiz   = Migrations::raizSql();
        $arquivos  = array_merge(
            [$sqlRaiz . '/schema.sql'],
            glob($sqlRaiz . '/modules/*.sql') ?: []
        );
        foreach ($arquivos as $f) {
            if (!is_file($f)) {
                continue;
            }
            // Só módulos ativos: um módulo desligado não precisa das tabelas.
            $slug = basename($f, '.sql');
            if (str_contains($f, '/modules/') && !self::moduloAtivo($slug)) {
                continue;
            }
            // Comentários fora antes de procurar: os cabeçalhos destes
            // arquivos citam "CREATE TABLE IF NOT EXISTS" em texto corrido, e
            // a busca ingênua tomava "IF" por nome de tabela.
            $sql = (string) file_get_contents($f);
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
            $sql = preg_replace('#/\*.*?\*/#s', '', $sql) ?? $sql;

            if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i',
                               $sql, $m)) {
                foreach ($m[1] as $t) {
                    $esperadas[strtolower($t)] = true;
                }
            }
        }
        return array_values(array_diff(array_keys($esperadas), array_keys($existentes)));
    }

    private static function moduloAtivo(string $slug): bool
    {
        try {
            $m = Modules::manifest($slug);
            return $m !== null && ($m['active'] ?? true);
        } catch (\Throwable $e) {
            return true;   // na dúvida, cobra a tabela
        }
    }

    // ── PHP ────────────────────────────────────────────────────────────────

    private static function php(): array
    {
        $out = [];

        $out[] = version_compare(PHP_VERSION, '8.1', '>=')
            ? self::item('ok', 'Versão do PHP', PHP_VERSION . ' — suportada.')
            : self::item('erro', 'Versão do PHP', PHP_VERSION . ' é antiga demais.',
                'O portal precisa de PHP 8.1 ou mais novo.');

        $obrig = [
            'pdo_mysql' => 'acesso ao banco de dados',
            'mbstring'  => 'acentos e textos em UTF-8',
            'json'      => 'comunicação entre as telas e o servidor',
            'openssl'   => 'TLS do e-mail e cifra da senha do SMTP',
            'zlib'      => 'compactação dos backups',
        ];
        foreach ($obrig as $ext => $para) {
            $out[] = extension_loaded($ext)
                ? self::item('ok', 'Extensão ' . $ext, 'Disponível — ' . $para . '.')
                : self::item('erro', 'Extensão ' . $ext, 'Ausente — ' . $para . ' não funciona.',
                    'Habilite a extensão ' . $ext . ' no PHP desta hospedagem.');
        }

        $opcionais = [
            'gd'       => 'gerar PNG do QR code e do código de barras',
            'zip'      => 'exportações em .zip',
            'curl'     => 'integrações externas (Moodle)',
            'intl'     => 'formatação de datas e números por idioma',
        ];
        foreach ($opcionais as $ext => $para) {
            $out[] = extension_loaded($ext)
                ? self::item('ok', 'Extensão ' . $ext, 'Disponível — ' . $para . '.')
                : self::item('aviso', 'Extensão ' . $ext, 'Ausente — ' . $para . ' fica indisponível.',
                    'Opcional: habilite se precisar deste recurso.');
        }

        // Limites que costumam morder no upload de documento e no backup.
        $mem  = self::emBytes((string) ini_get('memory_limit'));
        $out[] = ($mem <= 0 || $mem >= 128 * 1024 * 1024)
            ? self::item('ok', 'memory_limit', ini_get('memory_limit') ?: 'sem limite')
            : self::item('aviso', 'memory_limit', ini_get('memory_limit') . ' é baixo para gerar backup.',
                'Recomendado 128M ou mais.');

        $post = self::emBytes((string) ini_get('post_max_size'));
        $upl  = self::emBytes((string) ini_get('upload_max_filesize'));
        $out[] = ($upl > 0 && $post > 0 && $post < $upl)
            ? self::item('aviso', 'Tamanho de upload',
                'post_max_size (' . ini_get('post_max_size') . ') é MENOR que upload_max_filesize ('
                . ini_get('upload_max_filesize') . '): o envio falha em silêncio.',
                'Deixe post_max_size maior que upload_max_filesize.')
            : self::item('ok', 'Tamanho de upload',
                'upload_max_filesize ' . ini_get('upload_max_filesize') . ' · post_max_size ' . ini_get('post_max_size'));

        $maxExec = (int) ini_get('max_execution_time');
        $out[] = ($maxExec === 0 || $maxExec >= 30)
            ? self::item('ok', 'max_execution_time', $maxExec === 0 ? 'sem limite' : $maxExec . 's')
            : self::item('aviso', 'max_execution_time', $maxExec . 's pode interromper backup e restauração.',
                'Recomendado 30s ou mais (o backup já pede mais tempo por conta própria).');

        $out[] = self::item('info', 'Fuso horário', date_default_timezone_get() . ' · agora ' . date('d/m/Y H:i'));

        return $out;
    }

    // ── Pastas e arquivos ──────────────────────────────────────────────────

    private static function arquivos(): array
    {
        // Tudo o que este método olha — uploads/ e o .htaccess dela — vive na
        // área SERVIDA, não junto do código. Com as duas raízes separadas,
        // dirname(CORE_PATH) apontaria para a pasta irmã e o checkup diria
        // "pasta uploads não existe" sobre uma instalação sadia.
        $raiz = BASE_PATH;
        $out  = [];

        // As pastas que o sistema precisa ESCREVER. config saiu desta lista:
        // nada em runtime grava lá (só o instalador, uma vez), e exigir
        // escrita virava aviso permanente para quem mudou o arquivo de lugar
        // ou tirou a permissão — que é justamente a configuração mais segura.
        $pastas = [
            'uploads'      => ['arquivos de documentos, fotos e anexos', $raiz . '/uploads'],
            'dados'        => ['logs, cache, backups e anexos privados', STORAGE_PATH],
        ];
        foreach ($pastas as $rel => [$para, $caminho]) {
            if (!is_dir($caminho)) {
                $out[] = self::item('erro', 'Pasta ' . $rel, 'Não existe: ' . $caminho . ' — ' . $para . '.',
                    'Crie a pasta com permissão de escrita para o usuário do servidor web.');
                continue;
            }
            $out[] = is_writable($caminho)
                ? self::item('ok', 'Pasta ' . $rel, 'Existe e pode ser escrita (' . $para . ') — ' . $caminho . '.')
                : self::item('erro', 'Pasta ' . $rel, 'Existe mas NÃO pode ser escrita: ' . $caminho . ' — ' . $para . ' falha.',
                    'Dê permissão de escrita ao usuário do servidor web (geralmente www-data).');
        }

        // Onde config e storage realmente estão — e se isso é bom.
        //
        // A comparação é com a raiz SERVIDA pelo servidor web, não com a
        // pasta da instalação: um portal numa subpasta do site
        // (public_html/portal) tem a "pasta irmã" public_html/portal-config
        // ainda dentro da área pública, e comparar com BASE_PATH diria
        // "está fora" — uma mentira tranquilizadora.
        [$raizPublica, $raizConfiavel] = Exposicao::raizPublica();
        $refere = $raizPublica ?? $raiz;
        $foraConfig  = !str_starts_with(CONFIG_PATH . '/', $refere . '/');
        $foraStorage = !str_starts_with(rtrim(STORAGE_PATH, '/') . '/', $refere . '/');
        $ressalva = $raizConfiavel ? '' : ' (comparado com a pasta da instalação: a raiz do site '
                  . 'só é conhecida depois de abrir esta página pelo navegador)';
        $out[] = self::item($foraConfig ? 'ok' : 'aviso', 'Onde fica a configuração',
            CONFIG_FILE . ' (' . CONFIG_ORIGEM . ').'
            . ($foraConfig ? ' Fora da área pública — nenhuma URL alcança.' . $ressalva
                           : ' Dentro da área pública: enquanto o PHP executa, o acesso direto devolve '
                             . 'página em branco, mas no dia em que ele parar de processar .php sai o fonte '
                             . 'com a senha do banco.'),
            $foraConfig ? '' : 'Mova config.php para uma pasta irmã da instalação — veja "Tirar config e dados '
                             . 'do public_html" no README.');
        $out[] = self::item($foraStorage ? 'ok' : 'aviso', 'Onde ficam os dados',
            STORAGE_PATH . '.'
            . ($foraStorage ? ' Fora da área pública — nenhuma URL alcança.' . $ressalva
                            : ' Dentro da área pública: backup, log e anexo privado não são arquivos .php, '
                              . 'então nenhum interpretador protege — só a configuração do servidor, que '
                              . 'falha em silêncio no Nginx.'),
            $foraStorage ? '' : "Aponte 'paths' => ['storage' => '/caminho/fora/do/public_html'] em config.php.");

        // Caminho configurado que não existe: o boot volta para o padrão, e
        // sem este aviso o backup passaria a ser gravado num lugar que o
        // administrador não procura.
        if (STORAGE_CONFIGURADO !== '' && !is_dir(STORAGE_PATH)) {
            $out[] = self::item('erro', 'Pasta de dados configurada não existe',
                "config.php pede paths.storage = '" . STORAGE_CONFIGURADO . "', que não existe ou não "
                . 'está acessível. Toda gravação de log, cache, backup e anexo privado vai falhar.',
                'Crie a pasta (ou corrija o caminho em config.php). O sistema NÃO volta sozinho para '
                . 'a pasta pública: fazer isso espalharia atestado e backup dentro do public_html sem '
                . 'ninguém notar.');
        }

        // Espaço em disco: é o que faz o backup falhar no meio sem aviso.
        $livre = @disk_free_space($raiz);
        $total = @disk_total_space($raiz);
        if ($livre !== false && $total !== false && $total > 0) {
            $pct = $livre / $total * 100;
            $nivel = $pct < 5 ? 'erro' : ($pct < 15 ? 'aviso' : 'ok');
            $out[] = self::item($nivel, 'Espaço em disco',
                self::bytes((int) $livre) . ' livres de ' . self::bytes((int) $total)
                . ' (' . number_format($pct, 1, ',', '.') . '%).',
                $nivel === 'ok' ? '' : 'Libere espaço: backups antigos e logs são os primeiros candidatos (ver a limpeza automática nas configurações).');
        }

        // A presença do .htaccess continua valendo como pista — mas só como
        // pista. Quem responde de verdade se as pastas estão fechadas é o
        // teste de exposição, na seção de segurança.
        $ht = $raiz . '/uploads/.htaccess';
        if (is_dir($raiz . '/uploads')) {
            $out[] = is_file($ht)
                ? self::item('ok', 'Proteção da pasta uploads',
                    'Há um .htaccess restringindo o acesso direto. Em Nginx ele é ignorado — '
                    . 'veja o teste de exposição das pastas, na seção de segurança.')
                : self::item('aviso', 'Proteção da pasta uploads',
                    'Sem .htaccess: em servidores Apache os arquivos podem ser baixados direto pela URL.',
                    'Em Nginx, bloqueie /uploads na configuração do site; em Apache, adicione um .htaccess.');
        }

        // Log de erros crescendo sem parar.
        //
        // Este item vigiava "<raiz>/logs/app_errors.log" — um arquivo que
        // NÃO EXISTE em instalação nenhuma. O log do PHP é definido em
        // core/bootstrap.php como STORAGE_PATH/logs/php_errors.log, e
        // app_errors.log é de um módulo (documentos), dentro da pasta dele.
        // Como o is_file() dava falso, o item simplesmente NÃO APARECIA na
        // tela: o checkup parecia cobrir o assunto e não cobria nada,
        // enquanto o log de verdade crescia sem ninguém olhar. Numa conta com
        // cota, o primeiro sintoma seria upload parando de funcionar.
        $logs = array_merge(
            [STORAGE_PATH . '/logs/php_errors.log'],
            glob(MODULES_PATH . '/*/logs/*.log') ?: []
        );
        $maior = null;
        $total = 0;
        foreach ($logs as $log) {
            if (!@is_file($log)) {
                continue;
            }
            $tam    = (int) @filesize($log);
            $total += $tam;
            if ($maior === null || $tam > $maior[1]) {
                $maior = [$log, $tam];
            }
        }
        if ($maior === null) {
            $out[] = self::item('ok', 'Log de erros', 'Nenhum arquivo de log com conteúdo ainda.');
        } else {
            $out[] = $total > 50 * 1024 * 1024
                ? self::item('aviso', 'Log de erros',
                    self::bytes($total) . ' em log(s); o maior é ' . basename($maior[0])
                    . ' (' . self::bytes($maior[1]) . ') em ' . dirname($maior[0]) . '.',
                    'Veja os erros recorrentes — um log que cresce assim costuma ser o MESMO erro '
                    . 'repetindo — e ligue a limpeza automática nas configurações.')
                : self::item('ok', 'Log de erros',
                    self::bytes($total) . ' em log(s); o maior é ' . basename($maior[0])
                    . ' (' . self::bytes($maior[1]) . ').');
        }

        return $out;
    }

    /**
     * Itens do checkup vindos do último teste de exposição das pastas.
     *
     * Um item por pasta seria ruído: seis linhas quase iguais empurram o
     * resto da página para baixo. Então: uma linha de resumo quando está
     * tudo bem, e uma linha POR PASTA apenas quando há algo a fazer.
     *
     * @return array<int, array<string,mixed>>
     */
    private static function exposicao(): array
    {
        $r = Exposicao::ultimo();
        if ($r === null) {
            return [self::item('info', 'Exposição das pastas internas',
                'Ainda não foi testado. O teste grava um arquivo temporário em cada pasta '
                . 'sensível e tenta baixá-lo pela web — é a única forma de saber se a proteção '
                . 'realmente funciona neste servidor (um .htaccess não vale nada em Nginx).',
                'Use o botão "Testar agora" nesta página, ou espere o cron rodar.')];
        }

        $quando = 'testado em ' . date('d/m/Y H:i', (int) strtotime((string) $r['em']))
                . ' (' . ($r['origem'] === 'cron' ? 'pelo cron' : 'pela tela') . ')';

        $expostas = $duvidosas = [];
        $meta = Exposicao::pastas();
        foreach ($r['itens'] as $i) {
            if (($i['estado'] ?? '') === 'exposta')       { $expostas[]  = $i; }
            elseif (($i['estado'] ?? '') === 'indeterminado') { $duvidosas[] = $i; }
        }

        // "A pasta não existe nesta instalação" não é prova de nada: sem este
        // ramo, uma instalação em que a sonda não achou nada para testar
        // recebia a mesma linha verde de quem testou tudo e passou.
        $testadas = array_filter($r['itens'], fn ($i) => in_array($i['estado'] ?? '', ['exposta', 'protegida', 'fora'], true));

        $out = [];
        if ($expostas === [] && $duvidosas === []) {
            $out[] = $testadas === []
                ? self::item('aviso', 'Exposição das pastas internas',
                    'O teste rodou (' . $quando . ') mas não encontrou nenhuma pasta para verificar — '
                    . 'o resultado não diz nada sobre a segurança desta instalação.',
                    'Confira se os caminhos de config e dados estão corretos no checkup acima.')
                : self::item('ok', 'Exposição das pastas internas',
                    count($testadas) . ' pasta(s) verificada(s), nenhuma é entregue pela web — ' . $quando . '.');
            return $out;
        }

        foreach ($expostas as $i) {
            $pasta = (string) $i['pasta'];
            $out[] = self::item($meta[$pasta]['nivel'] ?? 'erro',
                'Pasta ABERTA na web: ' . $pasta,
                ($meta[$pasta]['porque'] ?? '') . ' Confirmado por teste: o arquivo-isca foi baixado pela URL (' . $quando . ').',
                'Mova a pasta para fora do public_html (ver o README) ou bloqueie o caminho na '
                . 'configuração do servidor. Em Nginx o .htaccess não é lido.');
        }
        foreach ($duvidosas as $i) {
            $out[] = self::item('aviso', 'Não foi possível testar: ' . (string) $i['pasta'],
                (string) $i['detalhe'] . ' (' . $quando . ')',
                'Rode o teste pelo cron — sem a disputa por processo do servidor web, ele costuma concluir.');
        }
        return $out;
    }

    // ── Segurança ──────────────────────────────────────────────────────────

    private static function seguranca(): array
    {
        // A raiz SERVIDA, não a do código. O install.php é um artefato da
        // área pública: quando o código vai para a pasta irmã, procurá-lo por
        // dirname(CORE_PATH) faz o checkup anunciar em verde "install.php já
        // foi removido" com o install.php vivo e servido — o único aviso que
        // existe sobre isso passaria a mentir, com selo de verificado.
        $raiz = BASE_PATH;
        $out  = [];

        // Pastas internas realmente fechadas? Resultado do teste de exposição
        // (Core\Exposicao), que grava um arquivo-isca e tenta baixá-lo pela
        // web. Aqui só LEMOS o último resultado: o checkup não pode disparar
        // a auto-requisição sozinho, porque em hospedagem com um worker de
        // PHP só ela trava a própria página até estourar o tempo.
        foreach (self::exposicao() as $item) {
            $out[] = $item;
        }

        // ── Mudança pela metade ────────────────────────────────────────
        // Mover pasta por FTP é COPIAR e depois APAGAR, e é o apagar que
        // falha: o cliente perde a conexão, o servidor recusa uma pasta não
        // vazia, ou a pessoa é interrompida. O resultado é o pior dos dois
        // mundos — o portal roda do lugar novo (o localizador prefere a pasta
        // irmã) e a cópia VELHA continua servida pela web, com o mesmo código
        // e o mesmo estrago de antes.
        //
        // A sonda de exposição NÃO pega isso: depois da mudança ela procura
        // essas pastas sob APP_PATH, que é justamente onde está a cópia boa.
        // A perigosa é a que ela deixou de olhar, e por isso a conferência
        // mora aqui.
        if (APP_PATH !== BASE_PATH) {
            $sobras = [];
            foreach (['core', 'modules', 'sql', 'docs', 'scripts'] as $pasta) {
                if (@is_dir($raiz . '/' . $pasta)) {
                    $sobras[] = $pasta . '/';
                }
            }
            $out[] = $sobras === []
                ? self::item('ok', 'Separação do código',
                    'O código está em ' . APP_PATH . ' e não sobrou cópia na área pública.')
                : self::item('erro', 'Separação do código',
                    'O código foi movido para ' . APP_PATH . ', mas sobrou cópia na área '
                    . 'pública: ' . implode(', ', $sobras),
                    'Apague essas pastas de ' . $raiz . '. Enquanto elas existirem, a mudança '
                    . 'não protegeu nada: o servidor continua entregando o código por URL, e o '
                    . 'portal roda a cópia de fora — ou seja, uma correção aplicada numa delas '
                    . 'não tem efeito nenhum.');
        }

        $out[] = is_file($raiz . '/install.php')
            ? self::item('erro', 'Instalador', 'O arquivo install.php ainda está no servidor.',
                'Apague install.php: com ele, qualquer pessoa pode tentar reinstalar o portal.')
            : self::item('ok', 'Instalador', 'install.php já foi removido.');

        $chave = (string) Config::get('app.key', '');
        $out[] = ($chave === '' || str_contains($chave, 'troque-esta-chave'))
            ? self::item('erro', 'Chave da aplicação', 'app.key está vazia ou ainda é a do exemplo.',
                'Defina uma chave longa e aleatória em config/config.php: ela cifra a senha do SMTP e assina dados da sessão.')
            : self::item(strlen($chave) >= 32 ? 'ok' : 'aviso', 'Chave da aplicação',
                strlen($chave) >= 32 ? 'Definida e com tamanho adequado.' : 'Definida, mas curta (' . strlen($chave) . ' caracteres).',
                strlen($chave) >= 32 ? '' : 'Use pelo menos 32 caracteres aleatórios.');

        $debug = (bool) Config::get('app.debug', false);
        $out[] = $debug
            ? self::item('aviso', 'Modo de depuração', 'app.debug está LIGADO: mensagens de erro detalhadas aparecem na tela.',
                'Em produção, deixe app.debug como false — o detalhe do erro ajuda quem ataca.')
            : self::item('ok', 'Modo de depuração', 'Desligado, como deve ser em produção.');

        // Diagnóstico de verdade: distingue desenvolvimento, proxy que não
        // informa o esquema, base_url desatualizado e ausência real de TLS.
        // A versão anterior só lia o texto do app.base_url e dava o mesmo
        // conselho ("instale um certificado") nos quatro casos — errado em
        // dois deles, e ruído em desenvolvimento.
        $d = Https::diagnose();
        $out[] = self::item($d['nivel'], $d['titulo'], $d['detalhe'], $d['acao']);

        // A marca Secure do cookie não é teórica: sem ela o cookie de sessão
        // acompanha uma requisição http e pode ser lido no caminho.
        if (Https::requestIsSecure()) {
            $p = session_get_cookie_params();
            $out[] = !empty($p['secure'])
                ? self::item('ok', 'Cookie de sessão', 'Marcado como Secure e HttpOnly.')
                : self::item('erro', 'Cookie de sessão',
                    'A conexão é HTTPS mas o cookie de sessão NÃO está marcado como Secure.',
                    'Costuma ser proxy sem X-Forwarded-Proto: veja o item de HTTPS acima.');
        }

        $out[] = MailSecret::hasStrongCrypto()
            ? self::item('ok', 'Cifra das senhas guardadas', 'AES-256-GCM disponível.')
            : self::item('aviso', 'Cifra das senhas guardadas', 'Sem OpenSSL: a senha do SMTP fica apenas ofuscada.',
                'Habilite a extensão openssl.');

        // Administradores e contas sem senha forte não são verificáveis aqui,
        // mas o número de administradores é um sinal útil.
        try {
            $n = (int) (DB::queryOne('SELECT COUNT(*) AS n FROM users WHERE is_admin = 1 AND active = 1')['n'] ?? 0);
            $out[] = $n === 0
                ? self::item('erro', 'Administradores', 'Nenhum administrador ativo.',
                    'Sem administrador ativo ninguém consegue configurar o portal.')
                : self::item($n > 5 ? 'aviso' : 'ok', 'Administradores',
                    $n . ' administrador(es) da plataforma ativo(s).',
                    $n > 5 ? 'Revise: administrador global vê e altera tudo, inclusive de outros setores.' : '');
        } catch (\Throwable $e) { /* já reportado no grupo banco */ }

        return $out;
    }

    // ── Rotinas e e-mail ───────────────────────────────────────────────────

    private static function rotinas(): array
    {
        $out = [];

        $ultimo = (string) Settings::get('cron.last_run_at', '');
        if ($ultimo === '') {
            $out[] = self::item('aviso', 'Rotina periódica (cron)', 'Nunca executou.',
                'Agende "php cron.php" (ou a URL cron.php?token=… com o cron_secret do config) para rodar de hora em hora: é o que envia avisos de vencimento, processa a fila de e-mail e faz o backup agendado.');
        } else {
            $idade = time() - (strtotime($ultimo) ?: 0);
            $nivel = $idade > 86400 ? 'erro' : ($idade > 7200 ? 'aviso' : 'ok');
            $out[] = self::item($nivel, 'Rotina periódica (cron)',
                'Última execução em ' . date('d/m/Y H:i', strtotime($ultimo)) . ' (' . self::duracao($idade) . ' atrás).',
                $nivel === 'ok' ? '' : 'A rotina parou. Confira o agendamento no painel da hospedagem.');
        }

        $out[] = MailConfig::enabled()
            ? self::item('ok', 'Envio de e-mail', 'Ligado' . (MailConfig::host() !== '' ? ' via SMTP ' . MailConfig::host() : ' pela função mail() do PHP') . '.')
            : self::item('aviso', 'Envio de e-mail', 'Desligado: avisos, comunicados e redefinição de senha não saem.',
                'Configure em Administração > E-mail.');

        try {
            $fila = DB::queryOne(
                "SELECT
                    SUM(status = 'pending') AS pendentes,
                    SUM(status = 'failed')  AS falhas,
                    SUM(status = 'pending' AND created_at < NOW() - INTERVAL 1 DAY) AS velhas
                 FROM mail_queue"
            ) ?: [];
            $pend  = (int) ($fila['pendentes'] ?? 0);
            $falh  = (int) ($fila['falhas'] ?? 0);
            $velha = (int) ($fila['velhas'] ?? 0);
            $nivel = $velha > 0 ? 'erro' : ($falh > 0 ? 'aviso' : 'ok');
            $out[] = self::item($nivel, 'Fila de e-mail',
                $pend . ' pendente(s), ' . $falh . ' com falha' . ($velha > 0 ? ', ' . $velha . ' parada(s) há mais de um dia' : '') . '.',
                $nivel === 'ok' ? '' : 'Veja Administração > E-mail > Fila; fila parada em geral significa cron parado ou SMTP recusando.');
        } catch (\Throwable $e) { /* tabela ausente já aparece no grupo banco */ }

        try {
            $n = (int) (DB::queryOne('SELECT COUNT(*) AS n FROM notifications WHERE read_at IS NULL')['n'] ?? 0);
            $out[] = self::item('info', 'Notificações não lidas', (string) $n . ' no portal inteiro.');
        } catch (\Throwable $e) { /* idem */ }

        return $out;
    }

    // ── Backup ─────────────────────────────────────────────────────────────

    private static function backup(): array
    {
        $out = [];

        try {
            $info    = Backup::dirInfo();
            $caminho = (string) ($info['caminho'] ?? '');
            $gravavel = $caminho !== '' && is_dir($caminho) && is_writable($caminho);
            $out[] = $gravavel
                ? self::item('ok', 'Pasta de backup', $caminho . ' — pode ser escrita.')
                : self::item('erro', 'Pasta de backup',
                    ($caminho ?: '(não definida)') . (is_dir($caminho) ? ' não pode ser escrita.' : ' não existe.'),
                    'Sem isso não há backup. Ajuste a permissão ou escolha outra pasta em Administração > Backup.');

            // Pacotes deixados na pasta antiga não aparecem na listagem: um
            // backup que o admin acha que tem é pior do que não ter nenhum.
            if (!empty($info['legado'])) {
                $out[] = self::item('aviso', 'Backups na pasta antiga',
                    $info['legado']['pacotes'] . ' pacote(s) em ' . $info['legado']['caminho'] . ' não aparecem na listagem.',
                    'Mova-os para a pasta atual ou aponte backup.path para a antiga.');
            }
        } catch (\Throwable $e) {
            $out[] = self::item('aviso', 'Pasta de backup', 'Não foi possível verificar: ' . $e->getMessage());
        }

        try {
            $lista = Backup::list();
            if ($lista === []) {
                $out[] = self::item('erro', 'Backups existentes', 'Nenhum backup foi gerado ainda.',
                    'Gere um agora em Administração > Backup — e ative o backup agendado.');
            } else {
                $mais = $lista[0];
                $ts   = strtotime((string) ($mais['criado_em'] ?? '')) ?: (int) ($mais['mtime'] ?? 0);
                $idade = time() - $ts;
                $nivel = $idade > 7 * 86400 ? 'erro' : ($idade > 2 * 86400 ? 'aviso' : 'ok');
                $out[] = self::item($nivel, 'Backup mais recente',
                    date('d/m/Y H:i', $ts) . ' (' . self::duracao($idade) . ' atrás) · '
                    . count($lista) . ' no total.',
                    $nivel === 'ok' ? '' : 'O backup está velho. Confira o agendamento e se o cron está rodando.');
            }
        } catch (\Throwable $e) {
            $out[] = self::item('aviso', 'Backups existentes', 'Não foi possível listar: ' . $e->getMessage());
        }

        $ligado = (string) Settings::get('backup.schedule_enabled', '0') === '1';
        $hora   = str_pad((string) max(0, min(23, (int) Settings::get('backup.schedule_hour', '3'))), 2, '0', STR_PAD_LEFT);
        $minuto = str_pad((string) max(0, min(59, (int) Settings::get('backup.schedule_minute', '0'))), 2, '0', STR_PAD_LEFT);
        $out[] = $ligado
            ? self::item('ok', 'Backup agendado', 'Ativo, todos os dias às ' . $hora . ':' . $minuto . ' (executado pelo cron).')
            : self::item('aviso', 'Backup agendado', 'Desativado: os backups dependem de alguém lembrar.',
                'Ative em Administração > Backup — depende do cron estar rodando.');

        return $out;
    }

    // ── Auxiliares ─────────────────────────────────────────────────────────

    public static function bytes(int $b): string
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

    public static function duracao(int $seg): string
    {
        if ($seg < 60)    return $seg . ' s';
        if ($seg < 3600)  return intdiv($seg, 60) . ' min';
        if ($seg < 86400) return intdiv($seg, 3600) . ' h';
        return intdiv($seg, 86400) . ' dia(s)';
    }

    private static function emBytes(string $ini): int
    {
        $ini = trim($ini);
        if ($ini === '' || $ini === '-1') {
            return -1;
        }
        $n = (int) $ini;
        return match (strtolower(substr($ini, -1))) {
            'g'     => $n * 1024 * 1024 * 1024,
            'm'     => $n * 1024 * 1024,
            'k'     => $n * 1024,
            default => $n,
        };
    }
}
