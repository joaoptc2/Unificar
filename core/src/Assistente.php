<?php

declare(strict_types=1);

namespace Core;

/**
 * Assistente com IA — chamada direta à API do Claude, por HTTPS.
 *
 * Sem SDK e sem Composer, de propósito: este sistema é entregue por FTP a
 * hospedagem compartilhada, e um `vendor/` de centenas de arquivos é uma
 * fonte de problema maior que a comodidade que traz. A API é um POST com
 * três cabeçalhos; a extensão curl já está aqui.
 *
 * ── O que esta classe NÃO faz ──────────────────────────────────────────────
 *
 * Ela não decide o que pode sair do hospital. Isso é política, e política é
 * de quem responde pelo hospital — não de código. O que ela faz é:
 *
 *   • exigir que a função chamadora declare o que está enviando, e registrar
 *     ISSO (função, tamanho, custo), nunca o conteúdo. Guardar o texto
 *     enviado criaria uma segunda cópia justamente do que se quer proteger;
 *   • recusar texto com marca de dado sensível óbvio (CPF, cartão SUS,
 *     prontuário) antes de sair, como rede de proteção contra o descuido —
 *     não como garantia. Nenhum filtro reconhece um relato clínico escrito
 *     em português corrido;
 *   • impor um teto mensal de gasto, porque a API não avisa e a conta chega
 *     depois.
 *
 * Desligado por padrão. Sem chave configurada, nada acontece e nenhuma tela
 * some — o assistente apenas não aparece.
 */
final class Assistente
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const VERSAO   = '2023-06-01';

    /** Tempo máximo de uma chamada. Acima disso a página fica parada demais. */
    private const TIMEOUT = 60;

    /** Teto de caracteres por envio. Corta antes de sair, não depois. */
    public const TAMANHO_MAX = 24000;

    /**
     * Preço por milhão de tokens, em centavos de dólar.
     * Fonte: tabela pública da Anthropic. Usado SÓ para estimar o teto —
     * a cobrança real é a da conta, e a tela diz isso.
     *
     * @return array<string, array{rotulo:string, entrada:int, saida:int}>
     */
    public static function modelos(): array
    {
        return [
            'claude-opus-5' => [
                'rotulo'  => 'Opus 5 — o mais capaz (padrão)',
                'entrada' => 500, 'saida' => 2500,
            ],
            'claude-sonnet-5' => [
                'rotulo'  => 'Sonnet 5 — equilíbrio entre qualidade e custo',
                'entrada' => 200, 'saida' => 1000,
            ],
            'claude-haiku-4-5' => [
                'rotulo'  => 'Haiku 4.5 — o mais barato e o mais rápido',
                'entrada' => 100, 'saida' => 500,
            ],
        ];
    }

    // ── Configuração ───────────────────────────────────────────────────────

    public static function ligado(): bool
    {
        return Settings::get('ia.ativo', '0') === '1' && self::temChave();
    }

    public static function temChave(): bool
    {
        return trim((string) Settings::get('ia.chave', '')) !== '';
    }

    public static function chave(): string
    {
        $guardada = (string) Settings::get('ia.chave', '');
        return $guardada === '' ? '' : MailSecret::reveal($guardada);
    }

    public static function guardarChave(string $chave): void
    {
        $chave = trim($chave);
        if ($chave === '') {
            Settings::set('ia.chave', null);
            return;
        }
        Settings::set('ia.chave', MailSecret::hide($chave));
    }

    public static function modelo(): string
    {
        $m = (string) Settings::get('ia.modelo', 'claude-opus-5');
        return isset(self::modelos()[$m]) ? $m : 'claude-opus-5';
    }

    /** Teto mensal em centavos. 0 = sem teto (desaconselhado). */
    public static function teto(): int
    {
        return max(0, (int) Settings::get('ia.teto_centavos', '2000'));
    }

    // ── Custo ──────────────────────────────────────────────────────────────

    /** Gasto do mês corrente, em milésimos de centavo. */
    public static function gastoDoMes(): int
    {
        // SEM filtrar por ok = 1. Uma chamada que estourou o tempo DEPOIS de
        // o prompt sair, ou que morreu no meio da resposta, foi cobrada pela
        // Anthropic do mesmo jeito — contar só o que deu certo faria o teto
        // ficar sempre abaixo do gasto real, que é o erro que ele existe para
        // evitar.
        $r = DB::queryOne(
            'SELECT COALESCE(SUM(milicentavos),0) t FROM ia_uso WHERE created_at >= ?',
            [date('Y-m-01 00:00:00')]
        );
        return (int) ($r['t'] ?? 0);
    }

    /** Quanto ainda cabe no mês, em centavos. PHP_INT_MAX se não há teto. */
    public static function saldo(): int
    {
        $teto = self::teto();
        if ($teto <= 0) {
            return PHP_INT_MAX;
        }
        return max(0, $teto - (int) floor(self::gastoDoMes() / 1000));
    }

    /** Custo de uma chamada, em milésimos de centavo. */
    private static function custo(string $modelo, int $entrada, int $saida): int
    {
        $p = self::modelos()[$modelo] ?? self::modelos()['claude-opus-5'];
        // centavos por milhão → milésimos de centavo por token
        return (int) round(($entrada * $p['entrada'] + $saida * $p['saida']) / 1000);
    }

    // ── Trava de dados ─────────────────────────────────────────────────────

    /**
     * Procura marcas de dado que não deve sair do hospital.
     *
     * É uma REDE, não uma garantia — está dito no docblock da classe e na
     * tela. Reconhece formato, e dado clínico não tem formato.
     *
     * @return string[] motivos encontrados (vazio = nada reconhecido)
     */
    public static function marcasSensiveis(string $texto): array
    {
        $achados = [];

        // CPF com pontuação ou 11 dígitos isolados.
        if (preg_match('/\b\d{3}\.\d{3}\.\d{3}-\d{2}\b/', $texto)
            || preg_match('/(?<!\d)\d{11}(?!\d)/', $texto)) {
            $achados[] = 'algo com formato de CPF';
        }
        // Cartão Nacional de Saúde: 15 dígitos.
        if (preg_match('/(?<!\d)\d{15}(?!\d)/', $texto)) {
            $achados[] = 'algo com formato de Cartão Nacional de Saúde';
        }
        // Palavras que quase sempre acompanham dado de paciente.
        if (preg_match('/\b(prontu[áa]rio|paciente|leito|CID[- ]?10|diagn[óo]stico|interna[çc][ãa]o)\b/iu', $texto)) {
            $achados[] = 'palavras de contexto assistencial (prontuário, paciente, leito, CID, diagnóstico)';
        }
        return $achados;
    }

    // ── A chamada ──────────────────────────────────────────────────────────

    /**
     * Pergunta ao modelo.
     *
     * @param string $funcao   rótulo curto do que está sendo feito (fica no registro)
     * @param string $sistema  instrução de sistema
     * @param string $usuario  o texto do usuário
     * @return array{ok:bool, texto:string, erro:string, tokens_in:int, tokens_out:int, ms:int}
     */
    public static function perguntar(string $funcao, string $sistema, string $usuario, array $opts = []): array
    {
        $t0  = microtime(true);
        $uid = Auth::check() ? (int) Auth::id() : null;
        $res = ['ok' => false, 'texto' => '', 'erro' => '', 'tokens_in' => 0, 'tokens_out' => 0, 'ms' => 0];

        // $enviou diz se o prompt chegou a sair. Quando saiu, a falha ainda
        // custa: a estimativa entra no teto, para ele não ficar abaixo do
        // gasto real.
        $enviou = false;
        $falhar = function (string $erro) use (&$res, &$enviou, $t0, $funcao, $uid, $usuario): array {
            $res['erro'] = $erro;
            $res['ms']   = (int) round((microtime(true) - $t0) * 1000);
            // 4 caracteres por token é a regra de bolso; serve para o teto,
            // não para a contabilidade.
            $estimado = $enviou ? self::custo(self::modelo(), (int) ceil(mb_strlen($usuario) / 4), 0) : 0;
            self::registrar($uid, $funcao, self::modelo(), 0, 0, $estimado,
                mb_strlen($usuario), false, $erro, $res['ms']);
            return $res;
        };

        if (!self::ligado()) {
            return $falhar('O assistente está desligado ou sem chave configurada.');
        }
        if (trim($usuario) === '') {
            return $falhar('Não havia texto para enviar.');
        }
        // O teto é conferido ANTES de gastar. Conferir depois seria contar o
        // prejuízo em vez de evitá-lo.
        if (self::saldo() <= 0) {
            return $falhar('O teto de gasto do mês foi atingido. Ele volta no dia 1º, '
                . 'ou pode ser aumentado em Administração › Assistente.');
        }

        // Sem curl não há chamada. Exposicao e MoodleAuth já guardam assim;
        // aqui faltava, e numa hospedagem sem a extensão isto era Error fatal
        // — página branca em vez de mensagem.
        if (!function_exists('curl_init')) {
            return $falhar('A extensão curl não está disponível neste servidor, '
                . 'e sem ela não é possível falar com a API.');
        }

        $usuario = mb_substr($usuario, 0, self::TAMANHO_MAX);

        $corpo = [
            'model'      => self::modelo(),
            'max_tokens' => max(256, min(8000, (int) ($opts['max_tokens'] ?? 2000))),
            'system'     => [['type' => 'text', 'text' => $sistema]],
            'messages'   => [['role' => 'user', 'content' => $usuario]],
        ];
        // Esforço baixo por padrão: as funções aqui são curtas (resumir,
        // redigir, organizar) e o esforço alto multiplica o custo sem
        // melhorar esse tipo de tarefa.
        if (!empty($opts['esforco'])) {
            $corpo['output_config'] = ['effort' => (string) $opts['esforco']];
        }

        $json = json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return $falhar('Não foi possível montar a requisição.');
        }

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . self::chave(),
                'anthropic-version: ' . self::VERSAO,
            ],
        ]);
        $enviou = true;
        $bruto  = curl_exec($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroC = (string) curl_error($ch);
        curl_close($ch);

        $res['ms'] = (int) round((microtime(true) - $t0) * 1000);

        if ($bruto === false) {
            return $falhar('Não foi possível falar com a API: ' . ($erroC ?: 'falha de rede') . '.');
        }
        $d = json_decode((string) $bruto, true);
        if (!is_array($d)) {
            return $falhar('A API respondeu algo que não é JSON (HTTP ' . $http . ').');
        }

        if ($http !== 200) {
            return $falhar(self::explicarErro($http, (string) ($d['error']['message'] ?? '')));
        }

        // A resposta é uma LISTA de blocos; só os de texto interessam. Pegar
        // content[0] quebraria quando o primeiro bloco for de raciocínio.
        $texto = '';
        foreach ($d['content'] ?? [] as $bloco) {
            if (($bloco['type'] ?? '') === 'text') {
                $texto .= $bloco['text'] ?? '';
            }
        }
        if (trim($texto) === '') {
            $motivo = (string) ($d['stop_reason'] ?? '');
            return $falhar($motivo === 'refusal'
                ? 'O modelo recusou responder a este pedido.'
                : 'A resposta veio vazia.');
        }

        $res['ok']         = true;
        $res['texto']      = trim($texto);
        $res['tokens_in']  = (int) ($d['usage']['input_tokens'] ?? 0);
        $res['tokens_out'] = (int) ($d['usage']['output_tokens'] ?? 0);

        self::registrar($uid, $funcao, self::modelo(), $res['tokens_in'], $res['tokens_out'],
            self::custo(self::modelo(), $res['tokens_in'], $res['tokens_out']),
            mb_strlen($usuario), true, '', $res['ms']);

        return $res;
    }

    /** Mensagem útil para cada erro da API. */
    private static function explicarErro(int $http, string $msg): string
    {
        return match (true) {
            $http === 401 => 'A chave da API foi recusada. Confira em Administração › Assistente.',
            $http === 402 => 'A conta da Anthropic está sem crédito ou com problema de pagamento.',
            $http === 404 => 'O modelo escolhido não existe ou não está liberado para esta conta.',
            $http === 413 => 'O texto enviado é grande demais.',
            $http === 429 => 'Muitas chamadas em pouco tempo. Tente de novo em alguns segundos.',
            $http >= 500  => 'A API está indisponível no momento (HTTP ' . $http . '). Tente mais tarde.',
            default       => 'A API recusou a chamada (HTTP ' . $http . ')' . ($msg !== '' ? ': ' . $msg : '.'),
        };
    }

    /** Grava o uso. O CONTEÚDO enviado nunca entra aqui. */
    private static function registrar(?int $uid, string $funcao, string $modelo, int $in, int $out,
                                      int $mili, int $chars, bool $ok, string $erro, int $ms): void
    {
        try {
            DB::execute(
                'INSERT INTO ia_uso (user_id, funcao, modelo, tokens_in, tokens_out, milicentavos,
                                     chars_enviados, ok, erro, ms)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$uid, mb_substr($funcao, 0, 40), mb_substr($modelo, 0, 60), $in, $out, $mili,
                 $chars, $ok ? 1 : 0, $erro !== '' ? mb_substr($erro, 0, 255) : null, $ms]
            );
        } catch (\Throwable $e) {
            error_log('ia_uso: ' . $e->getMessage());
        }
    }

    /** Resumo de uso para a tela de administração. */
    public static function resumoDoMes(): array
    {
        $r = DB::queryOne(
            "SELECT COUNT(*) chamadas, COALESCE(SUM(milicentavos),0) mili,
                    COALESCE(SUM(tokens_in),0) tin, COALESCE(SUM(tokens_out),0) tout,
                    SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END) falhas
               FROM ia_uso WHERE created_at >= ?",
            [date('Y-m-01 00:00:00')]
        ) ?: [];
        return [
            'chamadas' => (int) ($r['chamadas'] ?? 0),
            'centavos' => (int) floor(((int) ($r['mili'] ?? 0)) / 1000),
            'tokens'   => (int) ($r['tin'] ?? 0) + (int) ($r['tout'] ?? 0),
            'falhas'   => (int) ($r['falhas'] ?? 0),
        ];
    }
}
