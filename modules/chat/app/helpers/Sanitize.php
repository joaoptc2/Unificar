<?php
class Sanitize
{
    public static function e(?string $str): string
    {
        return htmlspecialchars($str ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function html(?string $str): string
    {
        return self::e($str);
    }

    public static function string(?string $str): string
    {
        return trim(preg_replace('/\r\n?/', "\n", $str ?? ''));
    }

    public static function email(?string $str): string
    {
        $str = trim(strtolower($str ?? ''));
        return filter_var($str, FILTER_VALIDATE_EMAIL) ? $str : '';
    }

    public static function int(mixed $val): int
    {
        return (int) $val;
    }

    public static function post(string $key, string $default = ''): string
    {
        return self::string($_POST[$key] ?? $default);
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::string($_GET[$key] ?? $default);
    }

    public static function slug(string $str): string
    {
        $str = mb_strtolower(trim($str));
        $str = preg_replace('/[àáâãäå]/u', 'a', $str);
        $str = preg_replace('/[èéêë]/u', 'e', $str);
        $str = preg_replace('/[ìíîï]/u', 'i', $str);
        $str = preg_replace('/[òóôõö]/u', 'o', $str);
        $str = preg_replace('/[ùúûü]/u', 'u', $str);
        $str = preg_replace('/[ç]/u', 'c', $str);
        $str = preg_replace('/[ñ]/u', 'n', $str);
        $str = preg_replace('/[^a-z0-9]+/', '-', $str);
        return trim($str, '-');
    }

    public static function formatDate(?string $str): string
    {
        if (!$str) return '-';
        $d = \DateTime::createFromFormat('Y-m-d', substr($str, 0, 10));
        return $d ? $d->format('d/m/Y') : '-';
    }

    public static function formatDateTime(?string $str): string
    {
        if (!$str) return '-';
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $str);
        if (!$d) $d = \DateTime::createFromFormat('Y-m-d H:i', $str);
        return $d ? $d->format('d/m/Y H:i') : '-';
    }

    public static function timeAgo(string $datetime): string
    {
        $now  = new \DateTime();
        $past = new \DateTime($datetime);
        $diff = $now->diff($past);

        if ($diff->y > 0) return $diff->y . 'a atrás';
        if ($diff->m > 0) return $diff->m . 'mês' . ($diff->m > 1 ? 'es' : '') . ' atrás';
        if ($diff->d > 0) {
            if ($diff->d === 1) return 'ontem';
            return $diff->d . 'd atrás';
        }
        if ($diff->h > 0) return $diff->h . 'h atrás';
        if ($diff->i > 0) return $diff->i . 'min atrás';
        return 'agora';
    }
}
