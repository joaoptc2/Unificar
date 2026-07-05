<?php
/**
 * Totp — implementação RFC 6238 (TOTP) e RFC 4648 (Base32) em PHP puro.
 *
 * Compatível com Google Authenticator, Microsoft Authenticator, Authy,
 * 1Password e demais apps TOTP padrão (HMAC-SHA1, 6 dígitos, 30s).
 */
class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;

    /**
     * Gera um segredo aleatório seguro (160 bits = recomendado por RFC 4226).
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Calcula o código TOTP atual para um segredo (Base32).
     */
    public static function code(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $counter   = intdiv($timestamp, self::PERIOD);
        return self::hotp(self::base32Decode($secret), $counter);
    }

    /**
     * Verifica um código informado pelo usuário, com tolerância de ±$window
     * janelas (30s cada). Window=1 = aceita o anterior, atual e próximo.
     */
    public static function verify(string $secret, string $userCode, int $window = 1): bool
    {
        $userCode = preg_replace('/\s+/', '', $userCode);
        if (!preg_match('/^\d{6}$/', $userCode)) return false;

        $key = self::base32Decode($secret);
        if ($key === '') return false;

        $currentCounter = intdiv(time(), self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            $candidate = self::hotp($key, $currentCounter + $i);
            if (hash_equals($candidate, $userCode)) return true;
        }
        return false;
    }

    /**
     * Gera o URI otpauth:// para QR Code.
     */
    public static function uri(string $issuer, string $accountName, string $secret): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);
        $params = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);
        return 'otpauth://totp/' . $label . '?' . $params;
    }

    /**
     * Gera N códigos de recuperação (formato 4-4-4 dígitos hex).
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $hex = bin2hex(random_bytes(6)); // 12 chars
            $codes[] = strtoupper(substr($hex, 0, 4) . '-' . substr($hex, 4, 4) . '-' . substr($hex, 8, 4));
        }
        return $codes;
    }

    public static function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn($c) => hash('sha256', strtoupper(trim($c))), $codes);
    }

    public static function matchRecoveryCode(array $hashedCodes, string $userInput): ?int
    {
        $hash = hash('sha256', strtoupper(trim($userInput)));
        foreach ($hashedCodes as $i => $h) {
            if (hash_equals($h, $hash)) return $i;
        }
        return null;
    }

    // -----------------------------------------------------------------
    // Base32 (RFC 4648 sem padding obrigatório)
    // -----------------------------------------------------------------
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Encode(string $bytes): string
    {
        if ($bytes === '') return '';
        $bits = '';
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out  .= self::ALPHABET[bindec($chunk)];
        }
        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(rtrim($encoded, '='));
        if ($encoded === '') return '';
        $bits = '';
        for ($i = 0, $n = strlen($encoded); $i < $n; $i++) {
            $pos = strpos(self::ALPHABET, $encoded[$i]);
            if ($pos === false) return '';
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }
        return $bytes;
    }

    // -----------------------------------------------------------------
    // HOTP (RFC 4226)
    // -----------------------------------------------------------------
    private static function hotp(string $key, int $counter): string
    {
        // Counter como 8 bytes big-endian.
        $bin = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $code = ((ord($hash[$offset]) & 0x7F) << 24)
              | ((ord($hash[$offset + 1]) & 0xFF) << 16)
              | ((ord($hash[$offset + 2]) & 0xFF) << 8)
              |  (ord($hash[$offset + 3]) & 0xFF);
        $code = $code % (10 ** self::DIGITS);
        return str_pad((string)$code, self::DIGITS, '0', STR_PAD_LEFT);
    }
}
