<?php

declare(strict_types=1);

namespace Core;

/**
 * Situação real do HTTPS e, opcionalmente, o reforço dele.
 *
 * Por que não basta olhar o app.base_url
 * --------------------------------------
 * O checkup dizia "app.base_url não usa https" e mandava instalar um
 * certificado. Isso é impreciso em três situações comuns, e em duas delas o
 * conselho está errado:
 *
 *   • DESENVOLVIMENTO em 127.0.0.1 — não existe certificado para localhost e
 *     não há tráfego saindo da máquina. Cobrar HTTPS aqui é ruído, e ruído
 *     treina o administrador a ignorar a página inteira.
 *
 *   • O site JÁ ESTÁ em HTTPS e só o base_url ficou com http. Este caso é
 *     PIOR do que o aviso sugeria: o certificado existe, mas os links dos
 *     e-mails saem em http e a redefinição de senha manda o funcionário para
 *     a versão sem criptografia. Não é "instale um certificado" — é
 *     "corrija uma linha".
 *
 *   • Atrás de proxy reverso (o arranjo normal em hospedagem) o PHP só sabe
 *     que a visita veio por HTTPS se o proxy mandar X-Forwarded-Proto. Sem
 *     isso, o cookie de sessão sai SEM a marca Secure mesmo num site seguro —
 *     e aí o cookie pode vazar numa requisição http. É um problema de
 *     configuração do proxy, não de certificado.
 *
 * Esta classe separa os casos e, quando faz sentido, oferece o reforço:
 * redirecionar http → https e enviar HSTS.
 */
final class Https
{
    /** A requisição ATUAL chegou por HTTPS? */
    public static function requestIsSecure(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        // Proxy reverso. Só é considerado quando o administrador declara
        // confiar no proxy: aceitar este cabeçalho de qualquer origem
        // permitiria a um cliente afirmar "vim por https" e burlar o
        // redirecionamento.
        if (self::trustProxy()) {
            $proto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
            if ($proto !== '') {
                // Pode vir "https, http" numa cadeia de proxies: vale o primeiro.
                return str_starts_with(trim(explode(',', $proto)[0]), 'https');
            }
            if ((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on') {
                return true;
            }
            if ((string) ($_SERVER['HTTP_X_FORWARDED_PORT'] ?? '') === '443') {
                return true;
            }
        }
        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    public static function trustProxy(): bool
    {
        return (bool) Config::get('security.trust_proxy', false);
    }

    /** O endereço configurado em app.base_url usa https? */
    public static function baseUrlIsSecure(): bool
    {
        return str_starts_with(strtolower(trim((string) Config::get('app.base_url', ''))), 'https://');
    }

    /** O endereço aponta para a própria máquina (desenvolvimento)? */
    public static function baseUrlIsLocal(): bool
    {
        $host = strtolower((string) parse_url((string) Config::get('app.base_url', ''), PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || str_starts_with($host, '127.')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.localhost');
    }

    /** Há sinal de proxy reverso à frente? */
    public static function behindProxy(): bool
    {
        foreach (['HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_SSL',
                  'HTTP_X_REAL_IP', 'HTTP_CF_VISITOR'] as $h) {
            if (!empty($_SERVER[$h])) {
                return true;
            }
        }
        return false;
    }

    // ── Reforço ────────────────────────────────────────────────────────────

    public static function forceEnabled(): bool
    {
        return (string) Settings::get('security.force_https', '0') === '1';
    }

    /** Tempo do HSTS em segundos (0 = não enviar). */
    public static function hstsSeconds(): int
    {
        return Tokens::int(Settings::get('security.hsts_seconds', '0'), 0, 63072000, 0);
    }

    /**
     * Aplica o reforço. Chamado no bootstrap, antes de qualquer saída.
     *
     * Só age quando a requisição chegou por http E o reforço está ligado. O
     * padrão é desligado de propósito: ligar isso num servidor cujo TLS ainda
     * não funciona tranca todo mundo para fora, e o administrador precisaria
     * do banco de dados para voltar atrás.
     */
    public static function enforce(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        $seguro = self::requestIsSecure();

        // HSTS só faz sentido (e só é honrado) numa resposta já em HTTPS.
        // Enviá-lo por http é ignorado pelo navegador — e enviá-lo antes de o
        // certificado funcionar deixaria o navegador se recusando a voltar.
        if ($seguro) {
            $t = self::hstsSeconds();
            if ($t > 0) {
                $extra = (string) Settings::get('security.hsts_subdomains', '0') === '1'
                    ? '; includeSubDomains' : '';
                header('Strict-Transport-Security: max-age=' . $t . $extra);
            }
            return;
        }

        if (!self::forceEnabled()) {
            return;
        }
        // Em requisição local não redireciona: seria trancar o próprio
        // desenvolvimento por causa de uma opção ligada em produção e copiada
        // junto com o banco.
        if (self::isLocalRequest()) {
            return;
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
            return;   // Host suspeito: não monta redirecionamento com ele.
        }
        $destino = 'https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');

        header('Location: ' . $destino, true, 301);
        exit;
    }

    private static function isLocalRequest(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = explode(':', $host)[0];
        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1'
            || str_starts_with($host, '127.');
    }

    // ── Diagnóstico ────────────────────────────────────────────────────────

    /**
     * Situação atual, para o checkup de saúde.
     *
     * @return array{caso:string, nivel:string, titulo:string, detalhe:string, acao:string}
     */
    public static function diagnose(): array
    {
        $req    = self::requestIsSecure();
        $base   = self::baseUrlIsSecure();
        $local  = self::baseUrlIsLocal();
        $proxy  = self::behindProxy();
        $cli    = PHP_SAPI === 'cli';

        // 1. Desenvolvimento: não há o que cobrar.
        if ($local) {
            return [
                'caso'    => 'local',
                'nivel'   => 'info',
                'titulo'  => 'HTTPS',
                'detalhe' => 'O endereço configurado é local (' . (string) Config::get('app.base_url', '') . '), '
                           . 'típico de desenvolvimento. Não há tráfego saindo da máquina e não existe certificado para localhost.',
                'acao'    => 'Nada a fazer aqui. Em produção, o endereço precisa ser o domínio real em https.',
            ];
        }

        // 2. Proxy que não avisa o esquema: o site pode ser seguro e o PHP
        //    não saber — e aí o cookie de sessão sai sem a marca Secure.
        if (!$req && !$cli && $proxy && !self::trustProxy()) {
            return [
                'caso'    => 'proxy_sem_confianca',
                'nivel'   => 'aviso',
                'titulo'  => 'HTTPS (atrás de proxy)',
                'detalhe' => 'Há um proxy reverso à frente e o sistema está tratando a visita como http. '
                           . 'Se o site já atende em https, o cookie de sessão está saindo SEM a marca Secure, '
                           . 'e ele pode vazar numa requisição http.',
                'acao'    => 'Faça o proxy enviar o cabeçalho X-Forwarded-Proto e acrescente '
                           . "'trust_proxy' => true ao bloco security de config/config.php.",
            ];
        }

        // 3. Certificado existe, mas o base_url ficou para trás. É o caso que
        //    o aviso antigo classificava errado.
        if ($req && !$base) {
            return [
                'caso'    => 'base_url_desatualizado',
                'nivel'   => 'erro',
                'titulo'  => 'HTTPS (endereço configurado)',
                'detalhe' => 'Esta página chegou por HTTPS, mas app.base_url está em http. '
                           . 'Os links dos e-mails — inclusive o de redefinição de senha — apontam para a versão sem criptografia.',
                'acao'    => 'Corrija app.base_url em config/config.php para https://' . (string) ($_SERVER['HTTP_HOST'] ?? 'seu-dominio'),
            ];
        }

        // 4. Tudo certo — e o HSTS é o próximo degrau.
        if ($req && $base) {
            $hsts = self::hstsSeconds();
            return [
                'caso'    => 'ok',
                'nivel'   => $hsts > 0 ? 'ok' : 'aviso',
                'titulo'  => 'HTTPS',
                'detalhe' => $hsts > 0
                    ? 'A conexão é segura e o HSTS está ativo (' . (int) round($hsts / 86400) . ' dias).'
                    : 'A conexão é segura, mas sem HSTS: quem digitar o endereço sem "https://" faz a primeira '
                    . 'visita em http, e é nela que um interceptador age.',
                'acao'    => $hsts > 0 ? '' : 'Ligue o HSTS em Administração > Configurações (só depois de confirmar que o certificado funciona).',
            ];
        }

        // 5. Pela linha de comando não dá para saber o esquema da visita.
        if ($cli) {
            return [
                'caso'    => 'cli',
                'nivel'   => $base ? 'ok' : 'aviso',
                'titulo'  => 'HTTPS',
                'detalhe' => $base ? 'app.base_url usa https.' : 'app.base_url não usa https.',
                'acao'    => $base ? '' : 'Abra esta página pelo navegador para um diagnóstico preciso.',
            ];
        }

        // 6. Sem TLS mesmo.
        return [
            'caso'    => 'sem_tls',
            'nivel'   => 'erro',
            'titulo'  => 'HTTPS',
            'detalhe' => 'A visita chegou por http e o endereço configurado também é http. Senha de acesso, '
                       . 'documentos e dados de funcionários trafegam em texto claro — quem estiver na mesma '
                       . 'rede do hospital consegue ler.',
            'acao'    => 'Instale um certificado no servidor (o Let\'s Encrypt é gratuito e automático) e '
                       . 'ajuste app.base_url para https://. Depois, ligue o redirecionamento e o HSTS em '
                       . 'Administração > Configurações.',
        ];
    }
}
