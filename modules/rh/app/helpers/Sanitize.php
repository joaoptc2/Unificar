<?php
/**
 * Sanitização e validação de inputs
 */
class Sanitize
{
    /**
     * Sanitiza string para saída HTML (proteção XSS)
     */
    public static function html(?string $value): string
    {
        if ($value === null) return '';
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Alias para html()
     */
    public static function e(?string $value): string
    {
        return self::html($value);
    }

    /**
     * Sanitiza string de input.
     *
     * IMPORTANTE: só aplicamos `trim()` e normalizamos quebras de linha.
     * Não usamos `strip_tags()` aqui porque ele corrompe conteúdo legítimo
     * (ex.: "Salário < R$3.000" vira "Salário"). O escape contra XSS é feito
     * somente na saída via `Sanitize::e()`.
     */
    public static function string(?string $value): string
    {
        if ($value === null) return '';
        // Remove bytes NUL (evita truncamento em drivers) e normaliza \r\n → \n.
        $value = str_replace("\0", '', $value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        return trim($value);
    }

    /**
     * Sanitiza e-mail. Retorna '' se inválido, ou o e-mail normalizado.
     *
     * Usa `FILTER_VALIDATE_EMAIL` (estável) em vez de `FILTER_SANITIZE_EMAIL`,
     * que foi deprecado no PHP 8.1.
     */
    public static function email(?string $value): string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') return '';
        $value = mb_strtolower($value);
        $valid = filter_var($value, FILTER_VALIDATE_EMAIL);
        return $valid ? (string)$valid : '';
    }

    /**
     * Sanitiza inteiro — cast direto evita filtros depreciados.
     */
    public static function int($value): int
    {
        if (is_int($value)) return $value;
        if (is_string($value)) {
            // Extrai dígitos e sinal para suportar entradas como "R$ 1.234".
            return (int) preg_replace('/[^\d\-]/', '', $value);
        }
        return (int)$value;
    }

    /**
     * Sanitiza CPF (apenas números)
     */
    public static function cpf(?string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value ?? '');
    }

    /**
     * Formata CPF para exibição
     */
    public static function formatCpf(?string $cpf): string
    {
        $cpf = self::cpf($cpf);
        if (strlen($cpf) !== 11) return $cpf;
        return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
    }

    /**
     * Valida CPF
     */
    public static function isValidCpf(?string $cpf): bool
    {
        $cpf = self::cpf($cpf);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += $cpf[$i] * (($t + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ($cpf[$t] != $digit) return false;
        }
        return true;
    }

    /**
     * Sanitiza telefone
     */
    public static function phone(?string $value): string
    {
        return preg_replace('/[^0-9()\-\s+]/', '', $value ?? '');
    }

    /**
     * Sanitiza data
     */
    public static function date(?string $value): ?string
    {
        if (empty($value)) return null;
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        if ($d && $d->format('Y-m-d') === $value) return $value;
        $d = \DateTime::createFromFormat('d/m/Y', $value);
        if ($d) return $d->format('Y-m-d');
        return null;
    }

    /**
     * Formata data para exibição (dd/mm/aaaa)
     */
    public static function formatDate(?string $date): string
    {
        if (empty($date)) return '-';
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d ? $d->format('d/m/Y') : $date;
    }

    /**
     * Formata data e hora para exibição
     */
    public static function formatDateTime(?string $datetime): string
    {
        if (empty($datetime)) return '-';
        $d = new \DateTime($datetime);
        return $d->format('d/m/Y H:i');
    }

    /**
     * Obtém valor do POST sanitizado
     */
    public static function post(string $key, $default = ''): string
    {
        return self::string($_POST[$key] ?? $default);
    }

    /**
     * Obtém valor do GET sanitizado
     */
    public static function get(string $key, $default = ''): string
    {
        return self::string($_GET[$key] ?? $default);
    }
}
