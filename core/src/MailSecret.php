<?php

declare(strict_types=1);

namespace Core;

/**
 * Guarda a senha do SMTP no banco sem deixá-la em texto claro.
 *
 * Não é cofre: quem tem o banco E o config/config.php lê a senha de volta —
 * é o mesmo nível de proteção de qualquer sistema que precisa apresentar a
 * senha ao servidor de e-mail. O que isto evita é o caso real: um dump do
 * banco (backup, cópia para homologação, consulta de suporte) circulando com
 * a senha do e-mail do hospital legível em um SELECT.
 *
 * Formato gravado: `v1:<base64(nonce|tag|ciphertext)>` com AES-256-GCM.
 * Sem openssl, cai para `p1:<base64>` (apenas ofuscação) e o aviso aparece na
 * tela — melhor do que recusar a gravar e deixar o admin sem saída.
 */
final class MailSecret
{
    private const CIPHER = 'aes-256-gcm';

    public static function hasStrongCrypto(): bool
    {
        return function_exists('openssl_encrypt')
            && in_array(self::CIPHER, openssl_get_cipher_methods(), true);
    }

    /** A chave do app ainda é a do exemplo? (então cifrar protege pouco) */
    public static function appKeyIsDefault(): bool
    {
        $key = (string) Config::get('app.key', '');
        return $key === '' || str_contains($key, 'troque-esta-chave');
    }

    public static function hide(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        if (!self::hasStrongCrypto()) {
            return 'p1:' . base64_encode($plain);
        }
        $nonce = random_bytes(12);
        $tag   = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            return 'p1:' . base64_encode($plain);
        }
        return 'v1:' . base64_encode($nonce . $tag . $cipher);
    }

    /**
     * A senha está guardada num formato que ESTE servidor não consegue ler?
     * Acontece ao mudar de hospedagem: a tela usa isto para pedir que ela
     * seja digitada de novo, em vez de falhar em silêncio na hora do envio.
     */
    public static function unreadable(string $stored): bool
    {
        return str_starts_with($stored, 'v1:') && !self::hasStrongCrypto();
    }

    public static function reveal(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        if (str_starts_with($stored, 'p1:')) {
            return (string) base64_decode(substr($stored, 3), true);
        }
        if (!str_starts_with($stored, 'v1:')) {
            return $stored; // gravado antes deste formato: devolve como está
        }
        // Sem OpenSSL neste servidor (mudança de hospedagem, por exemplo) a
        // senha cifrada não tem como ser lida — mas isso não pode derrubar a
        // tela: devolve vazio e quem chama avisa para digitá-la de novo.
        if (!self::hasStrongCrypto()) {
            return '';
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $nonce  = substr($raw, 0, 12);
        $tag    = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain  = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        return $plain === false ? '' : $plain;
    }

    private static function key(): string
    {
        return hash('sha256', 'mail.secret|' . (string) Config::get('app.key', 'sem-chave'), true);
    }
}
