<?php

declare(strict_types=1);

namespace Core;

/**
 * Temas nomeados da identidade visual.
 *
 * O problema que resolve
 * ---------------------
 * Os "temas prontos" eram constantes no código e a personalização vivia
 * solta em settings. Para experimentar outra cara do portal, o
 * administrador tinha que anotar as cores antigas num papel, mexer em doze
 * campos e torcer para conseguir voltar. Ninguém experimenta nessas
 * condições — e o resultado é um portal que fica com a cara de fábrica para
 * sempre.
 *
 * Aqui um tema é um registro com o conjunto INTEIRO de valores da aparência.
 * Guardar tudo (e não um "delta") é o que faz aplicar um tema ser
 * previsível: o resultado não depende do que estava valendo antes.
 *
 * O desfazer sai de graça: antes de aplicar qualquer tema, o estado atual é
 * gravado como um tema de retorno. Aplicar deixa de ser uma decisão de mão
 * única.
 */
final class Themes
{
    /** Quantos retornos automáticos guardar (os mais antigos saem sozinhos). */
    private const MAX_SNAPSHOTS = 5;

    public static function disponivel(): bool
    {
        try {
            $r = DB::queryOne(
                "SELECT COUNT(*) AS n FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'brand_themes'"
            );
            return (int) ($r['n'] ?? 0) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return array<int, array<string,mixed>> mais recentes primeiro */
    public static function all(bool $incluirRetornos = true): array
    {
        if (!self::disponivel()) {
            return [];
        }
        $sql = 'SELECT t.*, u.name AS autor FROM brand_themes t
                LEFT JOIN users u ON u.id = t.created_by';
        if (!$incluirRetornos) {
            $sql .= ' WHERE t.is_snapshot = 0';
        }
        return DB::query($sql . ' ORDER BY t.is_snapshot ASC, t.id DESC');
    }

    public static function find(int $id): ?array
    {
        if (!self::disponivel()) {
            return null;
        }
        return DB::queryOne('SELECT * FROM brand_themes WHERE id = ?', [$id]);
    }

    /** Valores do tema, já filtrados pelas chaves conhecidas da aparência. */
    public static function values(array $tema): array
    {
        $v = json_decode((string) ($tema['values_json'] ?? ''), true);
        if (!is_array($v)) {
            return [];
        }
        return array_intersect_key($v, Branding::DEFAULTS);
    }

    /**
     * Guarda a aparência ATUAL como um tema.
     *
     * $snapshot marca o tema como retorno automático — o que o desfazer usa,
     * e o que a tela mostra separado dos temas que o administrador criou de
     * propósito.
     */
    public static function save(string $nome, string $descricao = '', bool $snapshot = false): int
    {
        if (!self::disponivel()) {
            return 0;
        }
        $nome = trim($nome) !== '' ? mb_substr(trim($nome), 0, 80) : ('Tema ' . date('d/m/Y H:i'));

        // Nome repetido vira "nome (2)": recusar a gravação faria o
        // administrador perder o que acabou de configurar.
        $base = $nome;
        for ($i = 2; self::nomeExiste($nome); $i++) {
            $nome = mb_substr($base, 0, 74) . ' (' . $i . ')';
            if ($i > 50) {
                break;
            }
        }

        DB::execute(
            'INSERT INTO brand_themes (name, description, values_json, is_snapshot, created_by)
             VALUES (?, ?, ?, ?, ?)',
            [
                $nome,
                mb_substr(trim($descricao), 0, 255) ?: null,
                json_encode(Branding::all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $snapshot ? 1 : 0,
                Auth::id(),
            ]
        );
        $id = DB::lastId();

        if ($snapshot) {
            self::podaSnapshots();
        }
        return $id;
    }

    private static function nomeExiste(string $nome): bool
    {
        $r = DB::queryOne('SELECT id FROM brand_themes WHERE name = ?', [$nome]);
        return !empty($r);
    }

    /** Mantém só os N retornos automáticos mais recentes. */
    private static function podaSnapshots(): void
    {
        $velhos = DB::query(
            'SELECT id FROM brand_themes WHERE is_snapshot = 1 ORDER BY id DESC LIMIT 50 OFFSET ' . self::MAX_SNAPSHOTS
        );
        foreach ($velhos as $v) {
            DB::execute('DELETE FROM brand_themes WHERE id = ? AND is_snapshot = 1', [(int) $v['id']]);
        }
    }

    /**
     * Aplica um tema. Antes de trocar qualquer coisa, guarda o estado atual
     * como retorno — é o que transforma "aplicar" numa decisão reversível.
     *
     * @return array{ok:bool, erro:string, retorno_id:int}
     */
    public static function apply(int $id): array
    {
        $tema = self::find($id);
        if (!$tema) {
            return ['ok' => false, 'erro' => 'Tema não encontrado.', 'retorno_id' => 0];
        }
        $valores = self::values($tema);
        if ($valores === []) {
            return ['ok' => false, 'erro' => 'O tema está vazio ou corrompido.', 'retorno_id' => 0];
        }

        // Nome do retorno: descreve QUANDO e para onde se ia. Sem o cuidado
        // abaixo, desfazer duas vezes produzia "Antes de "Antes de "X""".
        $alvo = (string) $tema['name'];
        if (str_starts_with($alvo, 'Antes de ')) {
            $alvo = 'o tema anterior';
        } else {
            $alvo = '"' . mb_substr($alvo, 0, 40) . '"';
        }
        $retorno = self::save(
            'Antes de ' . $alvo . ' — ' . date('d/m H:i'),
            'Gerado automaticamente para permitir desfazer.',
            true
        );

        // Grava o conjunto COMPLETO: uma chave ausente no tema volta ao
        // padrão de fábrica, em vez de manter um resquício do tema anterior.
        $completo = [];
        foreach (array_keys(Branding::DEFAULTS) as $k) {
            $completo[$k] = (string) ($valores[$k] ?? Branding::DEFAULTS[$k]);
        }
        Branding::save($completo);

        DB::execute('UPDATE brand_themes SET applied_at = NOW() WHERE id = ?', [$id]);
        Settings::set('brand.theme_id', (string) $id);
        Branding::forget();

        Audit::log('appearance.theme_apply', 'brand_themes', (string) $id,
            ['tema' => $tema['name']], null, 'admin');

        return ['ok' => true, 'erro' => '', 'retorno_id' => $retorno];
    }

    public static function delete(int $id): bool
    {
        if (!self::disponivel()) {
            return false;
        }
        $n = DB::execute('DELETE FROM brand_themes WHERE id = ?', [$id]);
        if ($n > 0) {
            Audit::log('appearance.theme_delete', 'brand_themes', (string) $id, null, null, 'admin');
        }
        return $n > 0;
    }

    public static function rename(int $id, string $nome, string $descricao): bool
    {
        if (!self::disponivel() || trim($nome) === '') {
            return false;
        }
        // Renomear tira a marca de retorno automático: o administrador deu um
        // nome, então passa a ser um tema dele — e some da poda.
        DB::execute(
            'UPDATE brand_themes SET name = ?, description = ?, is_snapshot = 0 WHERE id = ?',
            [mb_substr(trim($nome), 0, 80), mb_substr(trim($descricao), 0, 255) ?: null, $id]
        );
        return true;
    }

    /** Tema aplicado por último (para a tela marcar qual está valendo). */
    public static function atual(): int
    {
        return (int) Settings::get('brand.theme_id', '0');
    }

    /**
     * Semeia os temas prontos que existiam só no código, para que apareçam
     * na lista como qualquer outro. Roda uma vez (quando a tabela está
     * vazia) e não sobrescreve nada depois.
     */
    public static function seedPresets(): int
    {
        if (!self::disponivel()) {
            return 0;
        }
        $r = DB::queryOne('SELECT COUNT(*) AS n FROM brand_themes');
        if ((int) ($r['n'] ?? 0) > 0) {
            return 0;
        }
        $n = 0;
        foreach (Branding::presets() as $chave => $preset) {
            $valores = array_merge(Branding::DEFAULTS, $preset['values'] ?? []);
            DB::execute(
                'INSERT IGNORE INTO brand_themes (name, description, values_json, is_snapshot)
                 VALUES (?, ?, ?, 0)',
                [
                    (string) $preset['label'],
                    'Tema pronto do sistema (' . $chave . ')',
                    json_encode($valores, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
            $n++;
        }
        return $n;
    }
}
