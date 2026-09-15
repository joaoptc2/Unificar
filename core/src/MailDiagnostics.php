<?php

declare(strict_types=1);

namespace Core;

/**
 * Diagnóstico de e-mail: o que dá para verificar sem sair da máquina, o que
 * depende de DNS e o que é simplesmente impossível saber daqui.
 *
 * A honestidade sobre o terceiro grupo é parte do recurso. Nenhum código em
 * PHP consegue provar que a mensagem chegou à caixa de entrada e não à pasta
 * de spam do destinatário — quem afirma isso está enganando o administrador.
 * O que se pode fazer é eliminar, uma a uma, as causas conhecidas.
 */
final class MailDiagnostics
{
    /**
     * Nível 1 — tudo local, sem rede. Seguro para rodar ao abrir a tela.
     *
     * @return array<int,array{nivel:string, titulo:string, detalhe:string}>
     *         nivel: ok | aviso | erro | info
     */
    public static function environment(): array
    {
        $out = [];
        $add = static function (string $nivel, string $titulo, string $detalhe) use (&$out): void {
            $out[] = ['nivel' => $nivel, 'titulo' => $titulo, 'detalhe' => $detalhe];
        };

        $add('info', 'PHP', PHP_VERSION . ' — ' . PHP_OS_FAMILY);

        $add(extension_loaded('openssl') ? 'ok' : 'erro', 'OpenSSL',
            extension_loaded('openssl')
                ? 'Disponível: conexões TLS/SSL com o servidor de e-mail são possíveis.'
                : 'Ausente: só é possível enviar sem criptografia, o que a maioria dos provedores recusa.');

        $sockets = function_exists('stream_socket_client');
        $add($sockets ? 'ok' : 'erro', 'Conexão por socket',
            $sockets ? 'stream_socket_client disponível (necessário para falar SMTP).'
                     : 'stream_socket_client desabilitado: o envio por SMTP não funciona nesta hospedagem.');

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $mailOff  = !function_exists('mail') || in_array('mail', $disabled, true);
        $add($mailOff ? 'aviso' : 'info', 'Função mail() do PHP',
            $mailOff ? 'Indisponível — só o caminho SMTP funciona aqui.'
                     : 'Disponível (sendmail: ' . ((string) ini_get('sendmail_path') ?: 'não informado') . ').');

        $max = (int) ini_get('max_execution_time');
        $add($max > 0 && $max < 20 ? 'aviso' : 'info', 'Tempo máximo de execução',
            $max <= 0 ? 'Sem limite.' : $max . 's — o teste usa no máximo ' . max(3, (int) floor($max * 0.6)) . 's.');

        // Alinhamento entre o "De" e o servidor de saída: causa nº 1 de e-mail
        // que chega na pasta de spam, e se detecta sem rede.
        $from   = MailConfig::from();
        $host   = MailConfig::host();
        $dFrom  = self::domain($from);
        // Relay interno informado por IP (comum em hospital) não tem domínio
        // para comparar — comparar assim mesmo produzia um aviso sobre um
        // "domínio" inexistente, tipo "0.1".
        $porIp  = $host !== '' && filter_var($host, FILTER_VALIDATE_IP) !== false;
        $dHost  = ($host !== '' && !$porIp) ? self::baseDomain($host) : '';
        if ($from === '' || $dFrom === '') {
            $add('erro', 'Endereço do remetente', 'Não configurado.');
        } elseif ($dHost !== '' && !str_ends_with('.' . $dFrom, '.' . $dHost) && $dFrom !== $dHost) {
            $add('aviso', 'Remetente e servidor em domínios diferentes',
                "O \"De\" usa {$dFrom} e o servidor de saída é {$dHost}. Muitos provedores recusam "
                . 'isso (ou marcam como spam). O ideal é que o remetente pertença ao domínio da conta SMTP.');
        } elseif ($porIp) {
            $add('info', 'Servidor informado por IP',
                'A conferência de alinhamento de domínio não se aplica a ' . $host . '.');
        } else {
            $add('ok', 'Remetente alinhado ao servidor', $from . ' ↔ ' . ($dHost ?: 'sendmail local'));
        }

        if (str_contains(strtolower($from), '@localhost') || str_contains(strtolower($from), 'exemplo.com')) {
            $add('erro', 'Remetente de exemplo',
                'O endereço "' . $from . '" é o valor de exemplo. Nenhum provedor sério aceita.');
        }

        if (MailConfig::get('encryption') === '' && MailConfig::get('user') !== '') {
            $add('aviso', 'Autenticação sem criptografia',
                'Usuário e senha trafegam em texto claro. Só use assim em rede interna.');
        }

        if (MailConfig::passwordIsSet() && MailSecret::appKeyIsDefault()) {
            $add('aviso', 'Chave do aplicativo padrão',
                'A senha do e-mail é guardada cifrada com app.key, que ainda é o valor de exemplo '
                . 'em config/config.php. Troque-a para a proteção valer.');
        }
        if (MailConfig::passwordIsSet() && !MailSecret::hasStrongCrypto()) {
            $add('aviso', 'Sem OpenSSL para cifrar a senha',
                'A senha do SMTP está apenas ofuscada no banco, não cifrada.');
        }

        return $out;
    }

    /**
     * Saúde da fila e do agendamento — o problema silencioso mais comum:
     * o sistema enfileira, ninguém processa, e ninguém percebe.
     *
     * @return array<int,array{nivel:string, titulo:string, detalhe:string}>
     */
    public static function queueHealth(): array
    {
        $out   = [];
        $stats = MailQueue::stats();
        $last  = Settings::get('cron.last_run_at');

        $out[] = ['nivel' => $stats['pending'] > 0 ? 'aviso' : 'ok', 'titulo' => 'Fila',
                  'detalhe' => sprintf('%d pendente(s), %d retida(s) por configuração, %d falha(s), %d enviada(s).',
                      $stats['pending'], $stats['held'] ?? 0, $stats['failed'], $stats['sent'])];

        if ($last === null) {
            $out[] = ['nivel' => 'aviso', 'titulo' => 'Rotina automática (cron)',
                      'detalhe' => 'Nunca registrada. Sem o cron agendado na hospedagem, tudo o que for '
                                 . 'enfileirado (comunicados, pesquisas, avisos) fica parado para sempre.'];
        } else {
            $idade = time() - strtotime($last);
            $out[] = ['nivel' => $idade > 7200 ? 'aviso' : 'ok', 'titulo' => 'Rotina automática (cron)',
                      'detalhe' => 'Última execução em ' . date('d/m/Y H:i', strtotime($last))
                                 . ' (' . self::humanAge($idade) . ').'];
        }

        $velha = 0;
        try {
            $velha = (int) (DB::query(
                'SELECT COUNT(*) n FROM mail_queue WHERE status = "pending" AND created_at < (NOW() - INTERVAL 1 DAY)'
            )[0]['n'] ?? 0);
        } catch (\Throwable) {
        }
        if ($velha > 0) {
            $out[] = ['nivel' => 'erro', 'titulo' => 'Mensagens paradas há mais de um dia',
                      'detalhe' => $velha . ' mensagem(ns) pendente(s) com mais de 24 horas.'];
        }
        return $out;
    }

    /**
     * Nível 2 — precisa de DNS. Só roda sob clique: dns_get_record não aceita
     * tempo limite e trava por segundos quando o resolvedor da hospedagem
     * não responde.
     *
     * @return array<int,array{nivel:string, titulo:string, detalhe:string}>
     */
    public static function dns(): array
    {
        $out    = [];
        $domain = self::domain(MailConfig::from());
        if ($domain === '') {
            return [['nivel' => 'erro', 'titulo' => 'Domínio do remetente',
                     'detalhe' => 'Configure o endereço "De" antes de verificar o DNS.']];
        }
        if (!function_exists('dns_get_record')) {
            return [['nivel' => 'aviso', 'titulo' => 'Consulta de DNS indisponível',
                     'detalhe' => 'A função dns_get_record está desabilitada nesta hospedagem.']];
        }

        $out[] = ['nivel' => 'info', 'titulo' => 'Domínio verificado', 'detalhe' => $domain];

        // SPF
        $txt = self::txt($domain);
        $spf = array_values(array_filter($txt, static fn ($t) => stripos($t, 'v=spf1') === 0));
        if ($spf === []) {
            $out[] = ['nivel' => 'aviso', 'titulo' => 'SPF ausente',
                      'detalhe' => 'Sem registro SPF em ' . $domain . '. Provedores tratam o e-mail com '
                                 . 'desconfiança. Peça ao responsável pelo domínio para publicar um SPF que '
                                 . 'inclua o servidor de saída do hospital.'];
        } elseif (count($spf) > 1) {
            $out[] = ['nivel' => 'erro', 'titulo' => 'Mais de um SPF',
                      'detalhe' => 'Há ' . count($spf) . ' registros v=spf1. A regra permite apenas um — '
                                 . 'com dois, a verificação falha e o e-mail é recusado.'];
        } else {
            $out[] = ['nivel' => 'ok', 'titulo' => 'SPF encontrado', 'detalhe' => $spf[0]];
        }

        // DMARC
        $dmarc = array_values(array_filter(self::txt('_dmarc.' . $domain),
            static fn ($t) => stripos($t, 'v=DMARC1') === 0));
        $out[] = $dmarc === []
            ? ['nivel' => 'aviso', 'titulo' => 'DMARC ausente',
               'detalhe' => 'Sem política DMARC em _dmarc.' . $domain . '. Não impede o envio, mas reduz a '
                          . 'confiança e deixa o domínio do hospital aberto para falsificação.']
            : ['nivel' => 'ok', 'titulo' => 'DMARC encontrado', 'detalhe' => $dmarc[0]];

        // DKIM: só dá para verificar sabendo o seletor.
        $sel = (string) Config::get('mail.dkim_selector', '');
        if ($sel === '') {
            $out[] = ['nivel' => 'info', 'titulo' => 'DKIM',
                      'detalhe' => 'Não verificável daqui: depende do seletor usado pelo provedor. '
                                 . 'Informe-o em config/config.php (mail.dkim_selector) para checar.'];
        } else {
            $dk = self::txt($sel . '._domainkey.' . $domain);
            $out[] = $dk === []
                ? ['nivel' => 'aviso', 'titulo' => 'DKIM não encontrado',
                   'detalhe' => 'Nada em ' . $sel . '._domainkey.' . $domain . '.']
                : ['nivel' => 'ok', 'titulo' => 'DKIM encontrado', 'detalhe' => substr($dk[0], 0, 120) . '…'];
        }

        // MX do domínio do remetente (ajuda a identificar domínio sem e-mail).
        $mx = @dns_get_record($domain, DNS_MX) ?: [];
        $out[] = $mx === []
            ? ['nivel' => 'aviso', 'titulo' => 'Sem MX', 'detalhe' => $domain . ' não recebe e-mail.']
            : ['nivel' => 'ok', 'titulo' => 'MX', 'detalhe' => implode(', ',
                array_map(static fn ($r) => $r['target'] . ' (' . $r['pri'] . ')', array_slice($mx, 0, 3)))];

        return $out;
    }

    /**
     * Nível 2 — as portas de saída estão liberadas nesta hospedagem?
     * É a resposta para "funciona no meu computador e não no servidor".
     *
     * @return array<int,array{nivel:string, titulo:string, detalhe:string}>
     */
    public static function ports(string $host, array $ports = [25, 465, 587, 2525]): array
    {
        $out = [];
        if ($host === '') {
            return [['nivel' => 'info', 'titulo' => 'Portas',
                     'detalhe' => 'Informe o servidor SMTP para testar as portas.']];
        }
        // A própria tela, uma linha acima, avisa quando a hospedagem fecha
        // stream_socket_client. O botão não pode derrubar a página por causa
        // exatamente daquilo que ele acabou de diagnosticar.
        if (!function_exists('stream_socket_client')) {
            return [['nivel' => 'aviso', 'titulo' => 'Não é possível testar as portas',
                     'detalhe' => 'Esta hospedagem não permite abrir conexões pelo PHP '
                                . '(stream_socket_client desabilitado).']];
        }
        // Nome que não resolve dá erro de conexão em todas as portas e faria a
        // tela dizer "bloqueada" quatro vezes, escondendo a causa real.
        if (!filter_var($host, FILTER_VALIDATE_IP) && function_exists('gethostbyname')
            && gethostbyname($host) === $host) {
            return [['nivel' => 'erro', 'titulo' => 'Nome do servidor não resolvido',
                     'detalhe' => 'Não foi possível descobrir o endereço de "' . $host
                                . '". Confira o nome antes de testar as portas.']];
        }
        foreach ($ports as $port) {
            $t0 = hrtime(true);
            $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 4);
            $ms = round((hrtime(true) - $t0) / 1e6);
            if ($fp) {
                fclose($fp);
                $out[] = ['nivel' => 'ok', 'titulo' => 'Porta ' . $port . ' aberta',
                          'detalhe' => 'Conexão aceita em ' . $ms . ' ms.'];
            } else {
                $out[] = ['nivel' => 'aviso', 'titulo' => 'Porta ' . $port . ' bloqueada',
                          'detalhe' => ($errstr ?: 'sem resposta') . ' (' . $ms . ' ms).'];
            }
        }
        $out[] = ['nivel' => 'info', 'titulo' => 'Como ler',
                  'detalhe' => 'Hospedagens costumam liberar 587 e 465 e bloquear a 25. Se todas estiverem '
                             . 'bloqueadas, o SMTP externo não é possível ali — use o servidor da própria '
                             . 'hospedagem ou a função mail().'];
        return $out;
    }

    /** O que NÃO dá para saber daqui — dito de forma explícita na tela. */
    public static function limits(): array
    {
        return [
            'Se a mensagem caiu na caixa de entrada ou na pasta de spam do destinatário. '
            . 'Nenhum servidor informa isso ao remetente.',
            'Se o destinatário leu. Rastreamento por imagem invisível não é confiável e, num hospital, '
            . 'levanta questão de privacidade.',
            'A reputação do IP de saída da hospedagem, que é compartilhado com outros sites.',
        ];
    }

    private static function txt(string $name): array
    {
        $recs = @dns_get_record($name, DNS_TXT) ?: [];
        $out  = [];
        foreach ($recs as $r) {
            $out[] = (string) ($r['txt'] ?? implode('', (array) ($r['entries'] ?? [])));
        }
        return $out;
    }

    private static function domain(string $email): string
    {
        $at = strrchr($email, '@');
        return $at === false ? '' : strtolower(substr($at, 1));
    }

    /** smtp.provedor.com.br → provedor.com.br (comparação tolerante de domínio). */
    private static function baseDomain(string $host): string
    {
        $parts = explode('.', strtolower(trim($host)));
        $n = count($parts);
        if ($n <= 2) {
            return implode('.', $parts);
        }
        // Domínios brasileiros costumam ter três níveis (com.br, org.br…).
        $tail = array_slice($parts, -3);
        if (in_array($tail[1] ?? '', ['com', 'org', 'net', 'gov', 'edu'], true) && ($tail[2] ?? '') === 'br') {
            return implode('.', $tail);
        }
        return implode('.', array_slice($parts, -2));
    }

    private static function humanAge(int $seconds): string
    {
        if ($seconds < 3600) {
            return 'há ' . max(1, (int) round($seconds / 60)) . ' min';
        }
        if ($seconds < 86400) {
            return 'há ' . (int) round($seconds / 3600) . ' h';
        }
        return 'há ' . (int) round($seconds / 86400) . ' dia(s)';
    }
}
