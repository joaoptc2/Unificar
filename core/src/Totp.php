<?php

declare(strict_types=1);

namespace Core;

/**
 * TOTP (RFC 6238) em PHP puro — compatível com Google Authenticator,
 * Microsoft Authenticator e Authy. 6 dígitos, período de 30s, HMAC-SHA1.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        $raw = random_bytes($bytes);
        return self::base32Encode($raw);
    }

    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }
        $slice = (int) floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $slice + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function code(string $secret, ?int $slice = null): string
    {
        $slice ??= (int) floor(time() / 30);
        $key    = self::base32Decode($secret);
        $bin    = pack('N*', 0) . pack('N*', $slice);
        $hash   = hash_hmac('sha1', $bin, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value  = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % 1000000;
        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private static function base32Decode(string $b32): string
    {
        $b32  = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $c) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr((int) bindec($chunk));
            }
        }
        return $out;
    }
}
