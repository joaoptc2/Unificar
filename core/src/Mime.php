<?php

declare(strict_types=1);

namespace Core;

/**
 * Leitura de uma mensagem de e-mail crua (RFC 5322 / MIME).
 *
 * O portal já sabia MONTAR uma mensagem multipart (Core\Mailer). Ler é o
 * problema oposto e bem mais sujo, porque o que chega foi escrito por
 * programas alheios ao longo de trinta anos:
 *
 *   • o assunto vem em pedaços codificados — =?UTF-8?B?...?= — que podem
 *     estar partidos no meio de um caractere;
 *   • o corpo pode estar em quoted-printable, base64 ou cru;
 *   • o charset pode ser qualquer coisa, e ISO-8859-1 ainda é comum em
 *     sistemas hospitalares antigos;
 *   • multipart aninha: alternative dentro de mixed, com anexo no meio;
 *   • há um preâmbulo antes do primeiro limite que NÃO é conteúdo.
 *
 * Nada aqui usa a extensão imap do PHP: ela foi separada do núcleo e não
 * existe na maior parte das hospedagens compartilhadas — que é onde este
 * sistema roda.
 */
final class Mime
{
    /** Não desce mais que isto em multipart aninhado. */
    private const PROFUNDIDADE_MAX = 8;

    /**
     * Separa cabeçalhos e corpo de uma mensagem crua.
     *
     * @return array{0:array<string,string>, 1:string} [cabeçalhos em minúsculas, corpo]
     */
    public static function separar(string $bruto): array
    {
        // O separador é uma linha em branco. Aceita \n puro porque nem todo
        // servidor respeita CRLF — e uma mensagem inteira virando "cabeçalho"
        // é um erro silencioso difícil de rastrear depois.
        $pos = strpos($bruto, "\r\n\r\n");
        $tam = 4;
        $posN = strpos($bruto, "\n\n");
        if ($pos === false || ($posN !== false && $posN < $pos)) {
            $pos = $posN;
            $tam = 2;
        }
        if ($pos === false) {
            return [self::cabecalhos($bruto), ''];
        }
        return [self::cabecalhos(substr($bruto, 0, $pos)), substr($bruto, $pos + $tam)];
    }

    /**
     * Cabeçalhos em array, chave em minúsculas.
     *
     * Linhas continuadas (começando com espaço ou tab) pertencem ao cabeçalho
     * anterior: um Subject longo chega quebrado em várias linhas, e tratá-las
     * como cabeçalhos novos perderia metade do assunto.
     *
     * @return array<string,string>
     */
    public static function cabecalhos(string $bloco): array
    {
        $out    = [];
        $chave  = '';
        foreach (preg_split('/\r\n|\n|\r/', $bloco) ?: [] as $linha) {
            if ($linha === '') {
                continue;
            }
            if (($linha[0] === ' ' || $linha[0] === "\t") && $chave !== '') {
                $out[$chave] .= ' ' . trim($linha);
                continue;
            }
            $p = strpos($linha, ':');
            if ($p === false) {
                continue;
            }
            $chave = strtolower(trim(substr($linha, 0, $p)));
            $valor = trim(substr($linha, $p + 1));
            // Repetido (Received, por exemplo): fica o primeiro.
            $out[$chave] = $out[$chave] ?? '';
            if ($out[$chave] === '') {
                $out[$chave] = $valor;
            }
        }
        return $out;
    }

    /** Decodifica =?charset?B?...?= / =?charset?Q?...?= para UTF-8. */
    public static function decodeCabecalho(string $v): string
    {
        if ($v === '') {
            return '';
        }
        if (function_exists('iconv_mime_decode')) {
            $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($d !== false && $d !== '') {
                return self::limpa($d);
            }
        }
        if (function_exists('mb_decode_mimeheader')) {
            return self::limpa((string) @mb_decode_mimeheader($v));
        }
        return self::limpa($v);
    }

    /**
     * Nome e endereço de um cabeçalho From/To.
     *
     * @return array{nome:string, email:string}
     */
    public static function endereco(string $v): array
    {
        $v = self::decodeCabecalho($v);
        if (preg_match('/^\s*(.*?)\s*<([^>]+)>\s*$/', $v, $m)) {
            return ['nome' => trim($m[1], " \t\"'"), 'email' => trim($m[2])];
        }
        $v = trim($v, " \t\"'");
        return ['nome' => '', 'email' => $v];
    }

    /**
     * Mensagem inteira, já decodificada.
     *
     * @return array{
     *   de:array{nome:string,email:string}, para:string, assunto:string,
     *   data:string, message_id:string, texto:string, html:string,
     *   anexos:array<int,array{nome:string,tipo:string,bytes:int}>
     * }
     */
    public static function mensagem(string $bruto): array
    {
        [$cab, $corpo] = self::separar($bruto);

        $partes = self::partes($cab, $corpo, 0);

        $texto = $html = '';
        $anexos = [];
        foreach ($partes as $p) {
            if ($p['anexo']) {
                $anexos[] = ['nome' => $p['nome'], 'tipo' => $p['tipo'], 'bytes' => strlen($p['conteudo'])];
                continue;
            }
            if ($p['tipo'] === 'text/plain' && $texto === '') {
                $texto = $p['conteudo'];
            } elseif ($p['tipo'] === 'text/html' && $html === '') {
                $html = $p['conteudo'];
            }
        }

        // Só HTML: um texto legível é derivado dele, para a lista e a prévia.
        if ($texto === '' && $html !== '') {
            $texto = self::htmlParaTexto($html);
        }

        $data = trim($cab['date'] ?? '');
        // O dia da semana é descartado antes de interpretar a data. Ele é
        // redundante e frequentemente ERRADO em mensagens reais — e quando
        // contradiz a data, o strtotime() obedece ao dia da semana e empurra
        // a mensagem para a frente (um "Mon, 15 Sep 2026", cuja data cai numa
        // terça, vira 21 de setembro). A caixa apareceria fora de ordem, com
        // mensagens no futuro.
        $data = (string) preg_replace('/^\s*[A-Za-z]{3,9},\s*/', '', $data);
        $ts   = $data !== '' ? strtotime($data) : false;

        return [
            'de'         => self::endereco($cab['from'] ?? ''),
            'para'       => self::decodeCabecalho($cab['to'] ?? ''),
            'assunto'    => self::decodeCabecalho($cab['subject'] ?? ''),
            'data'       => $ts !== false ? date('Y-m-d H:i:s', $ts) : '',
            'message_id' => trim($cab['message-id'] ?? '', '<> '),
            'texto'      => $texto,
            'html'       => $html,
            'anexos'     => $anexos,
        ];
    }

    /**
     * Desmonta o corpo em partes decodificadas.
     *
     * @param array<string,string> $cab
     * @return array<int,array{tipo:string,nome:string,anexo:bool,conteudo:string}>
     */
    private static function partes(array $cab, string $corpo, int $nivel): array
    {
        if ($nivel > self::PROFUNDIDADE_MAX) {
            return [];
        }
        // O cabeçalho ORIGINAL é preservado: o nome do tipo pode ser
        // comparado em minúsculas, mas boundary, filename e name são
        // SENSÍVEIS A MAIÚSCULAS. Extrair o boundary de uma versão
        // minúscula do cabeçalho devolve um limite que não casa com o do
        // corpo, e o multipart inteiro sai vazio — sem erro nenhum.
        $ctypeOrig = $cab['content-type'] ?? 'text/plain';
        $ctype     = strtolower($ctypeOrig);
        $tipo      = trim(explode(';', $ctype)[0]);

        if (str_starts_with($tipo, 'multipart/')) {
            $limite = self::parametro($ctypeOrig, 'boundary');
            if ($limite === '') {
                return [];
            }
            $out = [];
            foreach (self::fatiar($corpo, $limite) as $pedaco) {
                [$c2, $b2] = self::separar($pedaco);
                foreach (self::partes($c2, $b2, $nivel + 1) as $p) {
                    $out[] = $p;
                }
            }
            return $out;
        }

        $codificacao = strtolower(trim($cab['content-transfer-encoding'] ?? '7bit'));
        $conteudo    = self::decodeCorpo($corpo, $codificacao);

        $dispOrig = $cab['content-disposition'] ?? '';
        $disp     = strtolower($dispOrig);
        $nome     = self::parametro($dispOrig, 'filename') ?: self::parametro($ctypeOrig, 'name');
        $anexo    = str_starts_with($disp, 'attachment')
                 || ($nome !== '' && !str_starts_with($tipo, 'text/'));

        if (!$anexo && str_starts_with($tipo, 'text/')) {
            $conteudo = self::paraUtf8($conteudo, self::parametro($ctypeOrig, 'charset'));
        }

        return [[
            'tipo'     => $tipo,
            'nome'     => self::decodeCabecalho($nome),
            'anexo'    => $anexo,
            'conteudo' => $conteudo,
        ]];
    }

    /**
     * Fatia um corpo multipart pelos limites.
     *
     * O que vem ANTES do primeiro limite é preâmbulo (texto para clientes
     * antigos, do tipo "esta mensagem está em formato MIME") e o que vem
     * depois do limite final é epílogo — nenhum dos dois é conteúdo.
     *
     * @return string[]
     */
    private static function fatiar(string $corpo, string $limite): array
    {
        $marca = '--' . $limite;
        $pos   = strpos($corpo, $marca);
        if ($pos === false) {
            return [];
        }
        $corpo  = substr($corpo, $pos);
        $brutas = explode($marca, $corpo);
        array_shift($brutas);   // o pedaço vazio antes do primeiro limite

        $out = [];
        foreach ($brutas as $p) {
            if (str_starts_with($p, '--')) {
                break;          // limite final: acabou
            }
            $out[] = ltrim($p, "\r\n");
        }
        return $out;
    }

    /** Valor de um parâmetro de cabeçalho (charset, boundary, filename). */
    public static function parametro(string $cabecalho, string $nome): string
    {
        // Com aspas primeiro: boundary="a;b" tem ponto-e-vírgula dentro.
        if (preg_match('/;\s*' . preg_quote($nome, '/') . '\s*=\s*"([^"]*)"/i', $cabecalho, $m)) {
            return $m[1];
        }
        if (preg_match('/;\s*' . preg_quote($nome, '/') . '\s*=\s*([^;\s]+)/i', $cabecalho, $m)) {
            return trim($m[1], '"\'');
        }
        return '';
    }

    /** Desfaz a codificação de transporte. */
    public static function decodeCorpo(string $corpo, string $codificacao): string
    {
        return match ($codificacao) {
            'base64'           => (string) base64_decode(preg_replace('/\s+/', '', $corpo) ?? '', false),
            'quoted-printable' => quoted_printable_decode($corpo),
            default            => $corpo,
        };
    }

    /**
     * Converte para UTF-8.
     *
     * Um texto que JÁ é UTF-8 válido é devolvido intacto: reconverter um
     * UTF-8 declarado erradamente como ISO-8859-1 produz o clássico "Ã§",
     * que é pior que o problema original.
     */
    public static function paraUtf8(string $s, string $charset): string
    {
        $charset = strtoupper(trim($charset)) ?: 'UTF-8';
        if ($charset === 'UTF-8' || $charset === 'UTF8' || $charset === 'US-ASCII' || $charset === '') {
            return self::limpa($s);
        }
        if (function_exists('mb_check_encoding') && mb_check_encoding($s, 'UTF-8')
            && !mb_check_encoding($s, 'ASCII')) {
            // Declarou outro charset mas o conteúdo é UTF-8 válido com
            // caracteres altos: acreditar no conteúdo, não na etiqueta.
            return self::limpa($s);
        }
        if (function_exists('iconv')) {
            $r = @iconv($charset, 'UTF-8//TRANSLIT', $s);
            if ($r !== false) {
                return self::limpa($r);
            }
        }
        if (function_exists('mb_convert_encoding')) {
            return self::limpa((string) @mb_convert_encoding($s, 'UTF-8', $charset));
        }
        return self::limpa($s);
    }

    /** Remove bytes de controle que quebram a página, menos quebra e tab. */
    private static function limpa(string $s): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s)
            ?: (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
    }

    /** HTML para texto legível — mesma regra do Mailer, no sentido inverso. */
    public static function htmlParaTexto(string $html): string
    {
        $t = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $t = preg_replace('#<br\s*/?>#i', "\n", $t) ?? $t;
        $t = preg_replace('#</(p|div|li|tr|h[1-6])>#i', "\n", $t) ?? $t;
        $t = preg_replace('#<li\b[^>]*>#i', '• ', $t) ?? $t;
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        return trim($t);
    }
}
