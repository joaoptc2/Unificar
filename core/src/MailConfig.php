<?php

declare(strict_types=1);

namespace Core;

/**
 * Configuração efetiva de e-mail.
 *
 * A fonte histórica é o arquivo config/config.php (bloco 'mail'). Esta classe
 * permite que a Administração > E-mail sobrescreva cada campo sem editar
 * arquivo, guardando o valor em `settings` com o prefixo `mail.`.
 *
 * Três estados por chave — e é por isso que não dá para copiar o modelo do
 * Branding aqui:
 *   - chave AUSENTE em settings  → herda do config/config.php (ou do padrão);
 *   - chave presente com ''      → deliberadamente vazio (ex.: relay interno
 *                                  sem usuário/senha), NÃO cai de volta no arquivo;
 *   - chave presente com valor   → sobrescreve o arquivo.
 *
 * `mail.lock = true` no config/config.php trava tudo no arquivo: útil quando a
 * hospedagem gerencia o SMTP e ninguém deve mexer pela tela.
 *
 * A senha nunca é exibida nem devolvida em listagens: fica cifrada
 * (Core\MailSecret) e só sai por password().
 */
final class MailConfig
{
    /** Campos gravávies pela tela (chave => padrão de fábrica). */
    public const FIELDS = [
        'enabled'       => '0',
        'host'          => '',
        'port'          => '587',
        'encryption'    => 'tls',   // tls | ssl | '' (sem criptografia)
        'user'          => '',
        'from'          => '',
        'from_name'     => '',
        'reply_to'      => '',
        'ehlo'          => '',      // vazio = gethostname()
        'timeout'       => '15',
        'fallback_mail' => 'auto',  // auto | 1 | 0
    ];

    public const ENCRYPTIONS = [
        'tls' => 'STARTTLS (porta 587 — recomendado)',
        'ssl' => 'SSL/TLS direto (porta 465)',
        ''    => 'Sem criptografia (porta 25 — só em rede interna)',
    ];

    private static ?array $cache = null;

    public static function forget(): void
    {
        self::$cache = null;
    }

    /** O arquivo manda e a tela é só leitura? */
    public static function isLocked(): bool
    {
        return (bool) Config::get('mail.lock', false);
    }

    /**
     * Valor efetivo de cada campo (sem a senha).
     *
     * @return array<string,string>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = [];
        foreach (self::FIELDS as $key => $default) {
            $out[$key] = self::resolve($key, $default);
        }
        return self::$cache = $out;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::all()[$key] ?? $default;
    }

    /** De onde veio o valor efetivo: 'settings', 'config' ou 'padrao'. */
    public static function source(string $key): string
    {
        if (!self::isLocked() && self::stored($key) !== null) {
            return 'settings';
        }
        return Config::get('mail.' . $key) !== null ? 'config' : 'padrao';
    }

    private static function resolve(string $key, string $default): string
    {
        if (!self::isLocked()) {
            $stored = self::stored($key);
            if ($stored !== null) {
                return $stored;
            }
        }
        $fromFile = Config::get('mail.' . $key);
        if ($fromFile !== null) {
            return is_bool($fromFile) ? ($fromFile ? '1' : '0') : (string) $fromFile;
        }
        return $default;
    }

    /** Valor bruto em settings — null quando a chave nem existe (herda do arquivo). */
    private static function stored(string $key): ?string
    {
        try {
            $v = Settings::get('mail.' . $key);
        } catch (\Throwable) {
            return null; // banco fora do ar: o arquivo continua valendo
        }
        return $v === null ? null : (string) $v;
    }

    public static function enabled(): bool
    {
        return self::get('enabled') === '1';
    }

    public static function host(): string
    {
        return self::get('host');
    }

    public static function port(): int
    {
        $p = (int) self::get('port');
        return $p > 0 && $p < 65536 ? $p : 587;
    }

    public static function from(): string
    {
        $from = self::get('from');
        if ($from !== '') {
            return $from;
        }
        return (string) Config::get('mail.from', 'nao-responda@localhost');
    }

    public static function fromName(): string
    {
        $name = self::get('from_name');
        return $name !== '' ? $name : Branding::name();
    }

    public static function ehlo(): string
    {
        $ehlo = self::get('ehlo');
        if ($ehlo !== '') {
            return $ehlo;
        }
        $host = (string) (gethostname() ?: '');
        // Um EHLO sem ponto é recusado por parte dos servidores.
        return $host !== '' && str_contains($host, '.') ? $host : 'localhost.localdomain';
    }

    public static function timeout(): int
    {
        $t = (int) self::get('timeout');
        return max(3, min(60, $t ?: 15));
    }

    /**
     * Cair para a função mail() do PHP quando o SMTP falha?
     *
     * 'auto' (padrão) = só quando NÃO há servidor SMTP configurado. Com host
     * configurado, uma falha tem de aparecer como falha: o silêncio aqui é a
     * origem clássica do "o sistema disse que enviou e ninguém recebeu".
     */
    public static function fallbackToMail(): bool
    {
        return match (self::get('fallback_mail')) {
            '1'     => true,
            '0'     => false,
            default => self::host() === '',
        };
    }

    public static function passwordIsSet(): bool
    {
        return self::password() !== '';
    }

    public static function password(): string
    {
        if (!self::isLocked()) {
            $stored = self::stored('pass');
            if ($stored !== null) {
                return $stored === '' ? '' : MailSecret::reveal($stored);
            }
        }
        return (string) Config::get('mail.pass', '');
    }

    /**
     * Grava as escolhas da tela.
     *
     * @param array<string,mixed> $values  campos de FIELDS presentes no POST
     * @param string|null $password        null = não mexer; '' = limpar
     */
    public static function save(array $values, ?string $password = null): void
    {
        if (self::isLocked()) {
            throw new \RuntimeException('A configuração de e-mail está travada em config/config.php (mail.lock).');
        }
        foreach (self::FIELDS as $key => $default) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $raw = self::clean((string) $values[$key]);
            $value = match ($key) {
                'enabled'       => $raw === '1' ? '1' : '0',
                'port'          => (string) max(1, min(65535, (int) $raw ?: 587)),
                'timeout'       => (string) max(3, min(60, (int) $raw ?: 15)),
                'encryption'    => array_key_exists($raw, self::ENCRYPTIONS) ? $raw : 'tls',
                'fallback_mail' => in_array($raw, ['auto', '0', '1'], true) ? $raw : 'auto',
                'from', 'reply_to' => $raw === '' || self::isEmail($raw) ? $raw : self::get($key),
                default         => mb_substr($raw, 0, 190),
            };
            // '' é gravado como '' (vazio deliberado), nunca como null —
            // null aqui significaria "herdar do arquivo".
            Settings::set('mail.' . $key, $value);
        }
        if ($password !== null) {
            Settings::set('mail.pass', $password === '' ? '' : MailSecret::hide($password));
        }
        self::forget();
    }

    /** Volta tudo para o que está em config/config.php. */
    public static function reset(): void
    {
        foreach (array_keys(self::FIELDS) as $key) {
            Settings::forget('mail.' . $key);
        }
        Settings::forget('mail.pass');
        self::forget();
    }

    /** Endereço de e-mail aceitável e sem injeção de cabeçalho. */
    public static function isEmail(string $value): bool
    {
        if ($value === '' || strlen($value) > 254) {
            return false;
        }
        if (preg_match('/[\r\n\0]/', $value)) {
            return false;
        }
        return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
    }

    /** Remove quebras de linha e nulos — a porta de entrada da injeção de cabeçalho. */
    public static function clean(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }

    /**
     * Apaga a senha (e o base64 dela) de qualquer texto antes de exibir/gravar.
     * Usado no transcript do teste e nas mensagens de erro.
     */
    public static function redact(string $text): string
    {
        $secrets = array_filter([
            self::password(),
            self::password() !== '' ? base64_encode(self::password()) : '',
            self::get('user') !== '' ? base64_encode(self::get('user')) : '',
        ]);
        foreach ($secrets as $secret) {
            if (strlen($secret) >= 3) {
                $text = str_replace($secret, '••••••', $text);
            }
        }
        return $text;
    }
}
