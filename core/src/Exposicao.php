<?php

declare(strict_types=1);

namespace Core;

/**
 * As pastas internas estão mesmo protegidas? — teste de verdade, não de fé.
 *
 * O checkup antes só perguntava "existe um .htaccess aqui?". É a mesma classe
 * de erro que o diagnóstico de HTTPS tinha: conferir o ARTEFATO em vez do
 * EFEITO. Um .htaccess presente não protege nada em Nginx (que o ignora sem
 * um aviso sequer), nem em Apache com AllowOverride None, nem numa cópia por
 * FTP que escondeu arquivos ocultos — e nos três casos a tela dizia "ok".
 *
 * Aqui o sistema grava um arquivo-isca com um segredo aleatório dentro de
 * cada pasta sensível, busca a própria URL desse arquivo e vê se o servidor
 * entrega. Se o segredo voltar pela web, a pasta está aberta para a internet.
 *
 * ── Por que o resultado fica GUARDADO, e o teste roda no cron ──────────────
 *
 * Uma requisição web que busca o próprio servidor precisa de um segundo
 * processo para atender. Em hospedagem com UM worker de PHP-FPM — justamente
 * a hospedagem barata, que é quem mais precisa deste aviso — o processo que
 * está montando a página do checkup é o único que existe: ele fica esperando
 * uma resposta que só ele poderia dar, e trava até estourar o tempo.
 *
 * Medido, não suposto: no servidor de um processo só, a auto-requisição
 * estourou 3 s com 0 bytes; com 4 workers, respondeu em 1 ms.
 *
 * Por isso o caminho normal é o cron (linha de comando, sem disputa por
 * worker), que guarda o resultado; o checkup mostra o que foi guardado e
 * quando. O botão "testar agora" existe, com tempo curto, e um estouro de
 * tempo é relatado como **não consegui testar** — nunca como "seguro".
 */
final class Exposicao
{
    /** Chave de Settings onde o último resultado é guardado. */
    private const CHAVE = 'seguranca.exposicao';

    /**
     * Prefixo que identifica um estouro de tempo no texto do erro. Só ESTE
     * caso alimenta a desistência antecipada: um "não testei" por outro
     * motivo (sem app.base_url, pasta fora da instalação) não indica servidor
     * ocupado, e contá-lo fazia as pastas seguintes serem descartadas com uma
     * justificativa falsa.
     */
    private const MARCA_TEMPO = "\u{200B}";

    /** Tempo máximo de cada busca, em segundos. Curto de propósito. */
    private const TEMPO_LIMITE = 4;

    /**
     * Pastas testadas, na ordem de gravidade. A chave é o caminho relativo à
     * raiz da instalação; 'url' é o caminho relativo ao BASE_URL.
     *
     * @return array<string, array{rotulo:string, porque:string, nivel:string}>
     */
    public static function pastas(): array
    {
        // A pasta de backup NÃO é necessariamente storage/backups: Backup
        // prefere um diretório irmão da instalação e só cai em storage/backups
        // como último recurso. Testar o literal fazia a sonda gravar a isca
        // num lugar e pedir a URL de outro — o 404 do endereço errado virava
        // "protegida", um falso "tudo certo" com selo de teste.
        $bk = Backup::dirInfo();
        $bkCaminho = rtrim((string) ($bk['caminho'] ?? ''), '/');
        $bkChave = 'storage/backups';
        if ($bkCaminho !== '') {
            $raiz = realpath(BASE_PATH);
            $bkChave = ($raiz !== false && str_starts_with($bkCaminho . '/', $raiz . '/'))
                ? ltrim(substr($bkCaminho, strlen($raiz)), '/')   // dentro: caminho relativo
                : $bkCaminho;                                      // fora: absoluto (vai dar 'fora')
        }

        return [
            $bkChave => [
                'rotulo' => 'pasta de backup',
                'porque' => 'Os pacotes de backup são o banco inteiro: hashes de senha, CPF, '
                          . 'dados de funcionários e documentos. São arquivos .tar.gz — nenhum '
                          . 'interpretador de PHP se mete no caminho, o servidor simplesmente entrega.',
                'nivel'  => 'erro',
            ],
            'storage' => [
                'rotulo' => 'storage',
                'porque' => 'Guarda log de erros, cache e os anexos privados (atestados, anexos de '
                          . 'comunicados) que só deveriam sair pelo download autenticado.',
                'nivel'  => 'erro',
            ],
            'config' => [
                'rotulo' => 'config',
                'porque' => 'Guarda a senha do banco, a app.key, o segredo do cron e a senha do '
                          . 'SMTP. Enquanto o PHP executa, o acesso direto devolve página em '
                          . 'branco; no dia em que o PHP parar de processar .php, sai o fonte.',
                'nivel'  => 'erro',
            ],
            'sql' => [
                'rotulo' => 'sql',
                'porque' => 'Expõe a estrutura inteira do banco — de graça para quem for procurar '
                          . 'por onde atacar.',
                'nivel'  => 'aviso',
            ],
            'core' => [
                'rotulo' => 'core',
                'porque' => 'O código do núcleo. Serve como mapa para quem procura falha.',
                'nivel'  => 'aviso',
            ],
            'docs' => [
                'rotulo' => 'docs',
                'porque' => 'Documentação interna da instalação.',
                'nivel'  => 'aviso',
            ],
        ];
    }

    // ── Execução ───────────────────────────────────────────────────────────

    /**
     * Roda o teste e guarda o resultado.
     *
     * @param bool $viaWeb true quando chamado de dentro de uma requisição web
     *                     (habilita o alerta de travamento por worker único).
     * @return array{em:string, origem:string, itens:array<int,array<string,mixed>>}
     */
    public static function testar(bool $viaWeb = false): array
    {
        $itens = [];
        $seguidos = 0;

        foreach (self::pastas() as $rel => $meta) {
            // Desiste cedo. Um estouro de tempo por worker único não é
            // azar de uma pasta: se a primeira não respondeu, nenhuma vai.
            // Sem isto o administrador espera 4 s por pasta — 24 s olhando
            // para uma tela parada, para seis vezes a mesma resposta.
            if ($seguidos >= 2) {
                $itens[] = [
                    'pasta'   => $rel,
                    'estado'  => 'indeterminado',
                    'detalhe' => 'Não testado: as duas primeiras pastas já não responderam a tempo, '
                               . 'o que indica servidor com um processo de PHP só.',
                    'http'    => 0,
                ];
                continue;
            }

            $r = self::testarPasta($rel, $meta) + ['pasta' => $rel];
            $seguidos = !empty($r['por_tempo']) ? $seguidos + 1 : 0;
            $itens[] = $r;
        }

        $resultado = [
            'em'     => date('Y-m-d H:i:s'),
            'origem' => $viaWeb ? 'web' : 'cron',
            'itens'  => $itens,
        ];

        // Um teste que não concluiu NADA não pode apagar um resultado que
        // concluiu. É o caso normal do botão na tela em hospedagem de worker
        // único: se o cron já tinha dito "storage está aberta", essa
        // informação vale mais que um "não sei" mais recente.
        $conclusivo = array_filter($itens, fn ($i) => $i['estado'] !== 'indeterminado');
        if ($conclusivo === [] && self::ultimo() !== null) {
            $resultado['nao_guardado'] = true;
            // O resultado antigo continua valendo, MAS a tentativa precisa
            // ficar marcada: o portão de 20 h do cron olha essa marca, e sem
            // ela um site que o servidor não consegue alcançar (NAT sem
            // hairpin, firewall de saída) refaria a sonda inteira em TODA
            // execução do cron, para sempre.
            try {
                Settings::set(self::CHAVE . '.tentado_em', $resultado['em']);
            } catch (\Throwable) {
                // sem banco, o cron tenta de novo no próximo ciclo: aceitável.
            }
            return $resultado;
        }

        // Guardar nunca pode derrubar o teste: sem banco, o resultado ainda
        // serve para a tela que pediu o teste agora.
        try {
            Settings::set(self::CHAVE, json_encode($resultado, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            error_log('Exposicao: não foi possível guardar o resultado: ' . $e->getMessage());
        }

        return $resultado;
    }

    /**
     * Testa UMA pasta.
     *
     * @param array{rotulo:string, porque:string, nivel:string} $meta
     * @return array{estado:string, detalhe:string, http:int}
     */
    private static function testarPasta(string $rel, array $meta): array
    {
        $dir = self::caminhoFisico($rel);

        // 1. A pasta existe?
        if ($dir === null || !is_dir($dir)) {
            return ['estado' => 'ausente', 'detalhe' => 'A pasta não existe nesta instalação.', 'http' => 0];
        }

        // 2. Está fisicamente FORA da área pública? Então não há URL que
        //    chegue nela, e o teste é desnecessário — é o melhor resultado
        //    possível, e o único que independe de configuração do servidor.
        if (!self::dentroDaAreaPublica($dir)) {
            return [
                'estado'  => 'fora',
                'detalhe' => 'Fica fora da pasta pública (' . $dir . '). Nenhuma URL alcança.',
                'http'    => 0,
            ];
        }

        // 3. Dentro da área servida, mas fora da instalação: existe URL que
        //    chega nela, só que ela não deriva de BASE_URL (que aponta para a
        //    instalação). Dizer "fora" seria falso; testar com o endereço
        //    errado daria um 404 que viraria "protegida". Fica indeterminado,
        //    com o texto explicando o que fazer.
        $raizApp = realpath(BASE_PATH);
        if ($raizApp !== false && !str_starts_with($dir . DIRECTORY_SEPARATOR, $raizApp . DIRECTORY_SEPARATOR)) {
            return [
                'estado'  => 'indeterminado',
                'detalhe' => 'A pasta (' . $dir . ') está dentro da área servida pelo servidor web, mas '
                           . 'fora da instalação — provavelmente o portal fica numa subpasta do site. '
                           . 'Não dá para montar o endereço dela a partir de app.base_url.',
                'http'    => 0,
            ];
        }

        // 4. Sem endereço não há teste — e a checagem vem ANTES de gravar
        //    qualquer coisa. app.base_url vazia é o PADRÃO do config de
        //    exemplo, e pela linha de comando (o caminho normal deste teste)
        //    não existe cabeçalho Host de onde tirar o endereço: sem isto a
        //    isca era gravada, o curl recusava a URL sem host, e o resultado
        //    culpava o servidor por um problema de configuração.
        $base = rtrim(trim((string) Config::get('app.base_url', '')), '/');
        if ($base === '' || parse_url($base, PHP_URL_SCHEME) === null || parse_url($base, PHP_URL_HOST) === null) {
            return [
                'estado'  => 'indeterminado',
                'detalhe' => 'app.base_url não está preenchida na configuração. Sem ela não há endereço '
                           . 'para testar: pela linha de comando não existe requisição de onde tirá-lo, e '
                           . 'pela tela o endereço viria do cabeçalho Host — ou seja, de quem faz o pedido.',
                'http'    => 0,
            ];
        }

        // 5. Dentro da instalação: só o teste real responde.
        if (!is_writable($dir)) {
            return [
                'estado'  => 'indeterminado',
                'detalhe' => 'A pasta não aceita escrita, então não foi possível deixar o arquivo de teste.',
                'http'    => 0,
            ];
        }

        $segredo = bin2hex(random_bytes(16));
        $nome    = '.teste-exposicao-' . substr($segredo, 0, 12) . '.txt';
        $arquivo = $dir . '/' . $nome;

        if (@file_put_contents($arquivo, $segredo) === false) {
            return [
                'estado'  => 'indeterminado',
                'detalhe' => 'Não foi possível criar o arquivo de teste na pasta.',
                'http'    => 0,
            ];
        }

        try {
            // O endereço vem de app.base_url, NUNCA de BASE_URL. Sem
            // app.base_url preenchida, o bootstrap monta BASE_URL a partir do
            // cabeçalho Host da requisição — ou seja, QUEM FAZ A REQUISIÇÃO
            // escolheria o alvo do teste, e o resultado não valeria nada.
            // Pela linha de comando não existe Host nenhum, e a sonda pediria
            // um caminho relativo que nunca conclui.
            // O caminho da URL é derivado do diretório FÍSICO, não do nome da
            // chave. Os dois divergem sempre que a pasta real tem outro nome
            // (paths.storage apontando para uma pasta dentro da área pública
            // com nome diferente, por exemplo): a isca ia para um lugar e o
            // pedido para outro, o 404 do endereço errado virava "protegida",
            // e isso é um falso "tudo certo" com selo de teste.
            $raizReal = realpath(BASE_PATH);
            $relUrl   = ($raizReal !== false && str_starts_with($dir, $raizReal . '/'))
                ? ltrim(substr($dir, strlen($raizReal)), '/')
                : $rel;
            $url = $base . '/' . $relUrl . '/' . $nome;
            [$http, $corpo, $erro, $tlsOk] = self::buscar($url);

            // O que prova exposição é o SEGREDO voltar — não o código 200.
            // Servidor que responde a página de login com 200 para qualquer
            // caminho inexistente é comum, e daria falso positivo.
            if ($corpo !== null && str_contains($corpo, $segredo)) {
                return [
                    'estado'  => 'exposta',
                    'detalhe' => 'O servidor entregou o arquivo de teste pela URL. A pasta está aberta.',
                    'http'    => $http,
                ];
            }
            if ($erro !== '') {
                $porTempo = str_starts_with($erro, self::MARCA_TEMPO);
                return [
                    'estado'   => 'indeterminado',
                    'detalhe'  => 'Não consegui testar: ' . ltrim($erro, self::MARCA_TEMPO),
                    'http'     => $http,
                    'por_tempo' => $porTempo,
                ];
            }

            // Recusa explícita é prova de proteção — desde que se saiba QUEM
            // recusou. Se o certificado não pôde ser verificado, a resposta
            // pode ter vindo de outra ponta; o veredito "exposta" continua
            // valendo mesmo assim (ali a prova é o segredo que acabamos de
            // gravar voltar), mas "protegida" não.
            if (in_array($http, [401, 403, 404, 410, 451], true)) {
                if (!$tlsOk) {
                    return [
                        'estado'  => 'indeterminado',
                        'detalhe' => 'Chegou uma recusa (HTTP ' . $http . '), mas o certificado não pôde '
                                   . 'ser verificado — não dá para afirmar que quem respondeu foi este servidor.',
                        'http'    => $http,
                    ];
                }
                return [
                    'estado'  => 'protegida',
                    'detalhe' => 'O servidor recusou (HTTP ' . $http . ') — o arquivo de teste não saiu.',
                    'http'    => $http,
                ];
            }

            // Respondeu, mas não era o nosso arquivo. NÃO é prova de proteção:
            // pode ser a tela de login devolvida com 200, um proxy no caminho,
            // ou um "catch-all" que responde qualquer caminho. Dizer
            // "protegida" aqui seria o mesmo pecado do .htaccess — afirmar
            // segurança sem ter verificado.
            return [
                'estado'  => 'indeterminado',
                'detalhe' => 'Algo respondeu (HTTP ' . $http . '), mas não era o arquivo de teste. '
                           . 'Pode ser a tela de login, um proxy no caminho ou uma regra que responde '
                           . 'qualquer endereço — nenhum dos três prova que a pasta está fechada.',
                'http'    => $http,
            ];
        } finally {
            // A isca sai SEMPRE, inclusive se a busca lançar. Deixar para trás
            // um arquivo numa pasta que talvez esteja aberta seria criar
            // exatamente o problema que se quer detectar.
            @unlink($arquivo);
        }
    }

    /**
     * Busca uma URL com tempo curto.
     *
     * @return array{0:int, 1:?string, 2:string, 3:bool} [http, corpo, erro, tlsVerificado]
     */
    private static function buscar(string $url, bool $verificarTls = true): array
    {
        if (!function_exists('curl_init')) {
            return [0, null, 'a extensão curl não está disponível neste servidor.', $verificarTls];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TEMPO_LIMITE,
            CURLOPT_CONNECTTIMEOUT => 3,
            // Redirecionamento NÃO é seguido: um 301 para a tela de login
            // significa "não entreguei", e seguir só gastaria tempo.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => $verificarTls,
            CURLOPT_SSL_VERIFYHOST => $verificarTls ? 2 : 0,
            CURLOPT_USERAGENT      => 'Portal/checkup-exposicao',
        ]);
        $corpo = curl_exec($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $nErro = curl_errno($ch);
        $sErro = (string) curl_error($ch);
        curl_close($ch);

        if ($corpo !== false) {
            return [$http, (string) $corpo, '', $verificarTls];
        }

        // Certificado próprio (interno, autoassinado) é comum em hospital.
        // Uma segunda tentativa sem verificar o certificado é aceitável AQUI
        // porque o que prova o resultado é o segredo que acabamos de gravar,
        // não a identidade do servidor — e a qualidade do TLS já é avaliada
        // em Core\Https::diagnose().
        // Os códigos são consultados por nome com defined(): CURLE_* varia
        // conforme o build do PHP (neste servidor, por exemplo,
        // CURLE_PEER_FAILED_VERIFICATION não existe) e referenciar um ausente
        // derrubaria o checkup inteiro com "Undefined constant".
        if ($verificarTls && in_array($nErro, self::codigos([
            'CURLE_SSL_CACERT', 'CURLE_PEER_FAILED_VERIFICATION',
            'CURLE_SSL_CERTPROBLEM', 'CURLE_SSL_CACERT_BADFILE', 'CURLE_SSL_CONNECT_ERROR',
        ]), true)) {
            return self::buscar($url, false);
        }

        if (in_array($nErro, self::codigos(['CURLE_OPERATION_TIMEDOUT', 'CURLE_OPERATION_TIMEOUTED']), true)) {
            return [0, null, self::MARCA_TEMPO . 'o servidor não respondeu a tempo. Costuma ser '
                           . 'hospedagem com um processo de PHP só: ele está ocupado montando esta página '
                           . 'e não sobra quem atenda o pedido. Rode o teste pelo cron.', $verificarTls];
        }
        return [0, null, $sErro !== '' ? $sErro : 'falha na requisição.', $verificarTls];
    }

    /**
     * Valores dos CURLE_* que existem neste build.
     *
     * @param string[] $nomes
     * @return int[]
     */
    private static function codigos(array $nomes): array
    {
        $out = [];
        foreach ($nomes as $n) {
            if (defined($n)) {
                $out[] = (int) constant($n);
            }
        }
        return $out;
    }

    // ── Caminhos ───────────────────────────────────────────────────────────

    /** Caminho físico de uma pasta relativa, honrando storage/config movidos. */
    private static function caminhoFisico(string $rel): ?string
    {
        // storage e config podem ter sido movidos para fora da área pública;
        // as constantes sabem onde eles realmente estão.
        if ($rel === 'storage' && defined('STORAGE_PATH')) {
            return realpath(STORAGE_PATH) ?: null;
        }
        // A chave da pasta de backup é dinâmica (ver pastas()): quando ela é
        // absoluta, já É o caminho físico.
        if (str_starts_with($rel, '/')) {
            return realpath($rel) ?: null;
        }
        if ($rel === 'storage/backups' && defined('STORAGE_PATH')) {
            return realpath(STORAGE_PATH . '/backups') ?: null;
        }
        if ($rel === 'config' && defined('CONFIG_PATH')) {
            return realpath(CONFIG_PATH) ?: null;
        }
        return realpath(BASE_PATH . '/' . $rel) ?: null;
    }

    /**
     * A raiz REALMENTE servida pelo servidor web.
     *
     * Não é BASE_PATH. Uma instalação pode morar numa SUBPASTA do docroot
     * (/home/conta/public_html/portal), e nesse caso a "pasta irmã"
     * public_html/portal-config continua dentro da área pública — servida
     * pela URL /portal-config/. Comparar com BASE_PATH diria "está fora" e
     * seria mentira.
     *
     * DOCUMENT_ROOT só existe em requisição web. Como o caminho normal desta
     * sonda é o cron, o valor visto pela web fica guardado para a linha de
     * comando usar.
     *
     * @return array{0:?string, 1:bool} [raiz, veioDoServidor]
     */
    public static function raizPublica(): array
    {
        $dr = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($dr !== '' && ($real = realpath($dr)) !== false) {
            // Guarda para o cron. Só grava quando muda, para não escrever a
            // cada requisição.
            try {
                if ((string) Settings::get('seguranca.docroot', '') !== $real) {
                    Settings::set('seguranca.docroot', $real);
                }
            } catch (\Throwable) {
                // sem banco: o valor em memória já serve para esta execução.
            }
            return [$real, true];
        }
        $guardado = (string) Settings::get('seguranca.docroot', '');
        if ($guardado !== '' && ($real = realpath($guardado)) !== false) {
            return [$real, true];
        }
        // Nunca foi visto pela web: BASE_PATH é o melhor palpite disponível.
        $base = realpath(BASE_PATH);
        return [$base === false ? null : $base, false];
    }

    /** A pasta está sob a raiz servida pelo servidor web? */
    private static function dentroDaAreaPublica(string $dir): bool
    {
        [$raiz] = self::raizPublica();
        if ($raiz === null) {
            return true;   // na dúvida, testa: um falso teste é melhor que um falso "ok".
        }
        return str_starts_with($dir . DIRECTORY_SEPARATOR, $raiz . DIRECTORY_SEPARATOR);
    }

    // ── Leitura do que ficou guardado ──────────────────────────────────────

    /** Último resultado guardado, ou null se nunca rodou. */
    public static function ultimo(): ?array
    {
        $bruto = (string) Settings::get(self::CHAVE, '');
        if ($bruto === '') {
            return null;
        }
        $d = json_decode($bruto, true);
        return is_array($d) && isset($d['itens']) && is_array($d['itens']) ? $d : null;
    }

    /**
     * Quando a sonda rodou pela última vez, tenha ou não concluído algo.
     * É esta marca que o cron usa para não repetir o teste a cada ciclo.
     */
    public static function tentadoEm(): ?string
    {
        $t = (string) Settings::get(self::CHAVE . '.tentado_em', '');
        $u = (string) (self::ultimo()['em'] ?? '');
        // Ambos no formato 'Y-m-d H:i:s': o máximo lexicográfico é o cronológico.
        $m = max($t, $u);
        return $m !== '' ? $m : null;
    }

    /** Há alguma pasta comprovadamente aberta no último teste? */
    public static function temPastaExposta(): bool
    {
        foreach ((self::ultimo()['itens'] ?? []) as $i) {
            if (($i['estado'] ?? '') === 'exposta') {
                return true;
            }
        }
        return false;
    }
}
