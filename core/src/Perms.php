<?php

declare(strict_types=1);

namespace Core;

/**
 * Micropermissões por módulo.
 *
 * Cada módulo declara seu catálogo no manifesto:
 *   'permissions' => [
 *       'documents' => [
 *           'label'   => 'Documentos',
 *           'actions' => ['view' => 'Visualizar', 'create' => 'Criar',
 *                         'edit' => 'Editar', 'delete' => 'Excluir'],
 *       ], ...
 *   ]
 * A chave efetiva de uma permissão é "<recurso>.<ação>" (ex.: documents.edit).
 *
 * Resolução do acesso de um usuário:
 *   1. Admin global (users.is_admin) → todas as permissões;
 *   2. União das permissões concedidas aos GRUPOS do usuário;
 *   3. Registros individuais do usuário sobrepõem o grupo
 *      (allowed=1 concede; allowed=0 NEGA mesmo que um grupo conceda).
 */
final class Perms
{
    /** @var array<int, array<string, array<string, bool>>> userId => module => set efetivo */
    private static array $effective = [];

    /** @var array<int, bool> userId => todas as concessões já carregadas (1 consulta por tipo) */
    private static array $loadedAll = [];

    /** @var array<int, bool> userId => é administrador global (cache por request) */
    private static array $isAdmin = [];

    /** @var array<int, int[]>|null userId => groupIds (cache por request) */
    private static array $groupsOf = [];

    // ------------------------------------------------------------------
    // Catálogo
    // ------------------------------------------------------------------

    /** @return array<string, array{label: string, actions: array<string, string>}> */
    public static function catalog(string $module): array
    {
        $manifest = Modules::manifest($module);
        return (array) ($manifest['permissions'] ?? []);
    }

    /** Todas as chaves válidas do módulo (recurso.ação). @return string[] */
    public static function allKeys(string $module): array
    {
        $keys = [];
        foreach (self::catalog($module) as $resource => $def) {
            foreach (array_keys($def['actions'] ?? []) as $action) {
                $keys[] = $resource . '.' . $action;
            }
        }
        return $keys;
    }

    /**
     * Expande uma lista de chaves com curingas contra o catálogo:
     * '*' → tudo; 'documents.*' → todas as ações do recurso.
     * @param string[] $patterns @return string[]
     */
    public static function expand(string $module, array $patterns): array
    {
        $all = self::allKeys($module);
        $out = [];
        foreach ($patterns as $p) {
            if ($p === '*') {
                return $all;
            }
            if (str_ends_with($p, '.*')) {
                $prefix = substr($p, 0, -1); // mantém o ponto
                foreach ($all as $key) {
                    if (str_starts_with($key, $prefix)) {
                        $out[$key] = true;
                    }
                }
            } elseif (in_array($p, $all, true)) {
                $out[$p] = true;
            }
        }
        return array_keys($out);
    }

    // ------------------------------------------------------------------
    // Resolução
    // ------------------------------------------------------------------

    /** Conjunto efetivo de permissões do usuário no módulo. @return array<string, bool> */
    public static function effective(int $userId, string $module): array
    {
        if (isset(self::$effective[$userId][$module])) {
            return self::$effective[$userId][$module];
        }

        if (self::isGlobalAdmin($userId)) {
            $set = [];
            foreach (self::allKeys($module) as $key) {
                $set[$key] = true;
            }
            return self::$effective[$userId][$module] = $set;
        }

        // As concessões de TODOS os módulos são lidas de uma vez (duas
        // consultas por request): o menu superior, a administração e os
        // gates do módulo ativo consultam vários módulos em sequência.
        self::loadAllFor($userId);

        return self::$effective[$userId][$module] ??= [];
    }

    /**
     * Carrega, em duas consultas, todas as concessões do usuário (grupos +
     * individuais) e monta o conjunto efetivo de cada módulo.
     */
    private static function loadAllFor(int $userId): void
    {
        if (!empty(self::$loadedAll[$userId])) {
            return;
        }
        self::$loadedAll[$userId] = true;

        $sets = [];

        // 1) grupos
        $groupIds = self::groupIdsOf($userId);
        if ($groupIds) {
            $in   = implode(',', array_fill(0, count($groupIds), '?'));
            $rows = DB::query(
                "SELECT module_slug, perm_key FROM permission_grants
                 WHERE subject_type = 'group' AND subject_id IN ({$in}) AND allowed = 1",
                $groupIds
            );
            foreach ($rows as $r) {
                $sets[$r['module_slug']][$r['perm_key']] = true;
            }
        }

        // 2) registros individuais (sobrepõem: allowed=0 nega)
        $rows = DB::query(
            "SELECT module_slug, perm_key, allowed FROM permission_grants
             WHERE subject_type = 'user' AND subject_id = ?",
            [$userId]
        );
        foreach ($rows as $r) {
            if ((int) $r['allowed'] === 1) {
                $sets[$r['module_slug']][$r['perm_key']] = true;
            } else {
                unset($sets[$r['module_slug']][$r['perm_key']]);
            }
        }

        foreach ($sets as $slug => $set) {
            self::$effective[$userId][(string) $slug] = $set;
        }
    }

    public static function can(int $userId, string $module, string $permKey): bool
    {
        return isset(self::effective($userId, $module)[$permKey]);
    }

    /** true se o usuário tem QUALQUER permissão no módulo (visibilidade no menu superior). */
    public static function hasAny(int $userId, string $module): bool
    {
        return self::effective($userId, $module) !== [];
    }

    /** Interrompe com 403 quando o usuário logado não tem a permissão. */
    public static function require(string $module, string $permKey): void
    {
        Auth::requireLogin();
        if (!self::can((int) Auth::id(), $module, $permKey)) {
            http_response_code(403);
            Layout::renderError(403, 'Você não tem permissão para esta ação. Solicite ao administrador.');
            exit;
        }
    }

    /**
     * IDs dos usuários ativos que possuem a permissão (inclui admins globais).
     * Útil para "notificar responsáveis do módulo". @return int[]
     */
    public static function usersWith(string $module, string $permKey): array
    {
        $ids = [];
        foreach (DB::query('SELECT id FROM users WHERE active = 1 AND is_admin = 1') as $r) {
            $ids[(int) $r['id']] = true;
        }
        // diretos
        $rows = DB::query(
            "SELECT g.subject_id AS uid FROM permission_grants g
             JOIN users u ON u.id = g.subject_id AND u.active = 1
             WHERE g.subject_type = 'user' AND g.module_slug = ? AND g.perm_key = ? AND g.allowed = 1",
            [$module, $permKey]
        );
        foreach ($rows as $r) {
            $ids[(int) $r['uid']] = true;
        }
        // via grupos
        $rows = DB::query(
            "SELECT m.user_id AS uid FROM permission_grants g
             JOIN user_group_members m ON m.group_id = g.subject_id
             JOIN users u ON u.id = m.user_id AND u.active = 1
             WHERE g.subject_type = 'group' AND g.module_slug = ? AND g.perm_key = ? AND g.allowed = 1",
            [$module, $permKey]
        );
        foreach ($rows as $r) {
            $ids[(int) $r['uid']] = true;
        }
        // remove quem tem negação individual
        $result = [];
        foreach (array_keys($ids) as $uid) {
            if (self::isGlobalAdmin($uid) || self::can($uid, $module, $permKey)) {
                $result[] = $uid;
            }
        }
        return $result;
    }

    // ------------------------------------------------------------------
    // Administração de grants
    // ------------------------------------------------------------------

    /** Grants brutos de um sujeito. @return array<string, array<string, int>> module => key => allowed */
    public static function grantsOf(string $type, int $id): array
    {
        $out = [];
        $rows = DB::query(
            'SELECT module_slug, perm_key, allowed FROM permission_grants WHERE subject_type = ? AND subject_id = ?',
            [$type, $id]
        );
        foreach ($rows as $r) {
            $out[$r['module_slug']][$r['perm_key']] = (int) $r['allowed'];
        }
        return $out;
    }

    /**
     * Substitui os grants de um GRUPO em um módulo pela lista dada (só allow).
     * @param string[] $keys
     */
    public static function setGroupGrants(int $groupId, string $module, array $keys, ?int $by): void
    {
        $valid = array_flip(self::allKeys($module));
        DB::execute(
            "DELETE FROM permission_grants WHERE subject_type = 'group' AND subject_id = ? AND module_slug = ?",
            [$groupId, $module]
        );
        foreach (array_unique($keys) as $key) {
            if (!isset($valid[$key])) {
                continue;
            }
            DB::execute(
                "INSERT INTO permission_grants (subject_type, subject_id, module_slug, perm_key, allowed, granted_by)
                 VALUES ('group', ?, ?, ?, 1, ?)",
                [$groupId, $module, $key, $by]
            );
        }
        self::flush();
    }

    /**
     * Define os overrides individuais de um USUÁRIO em um módulo a partir do
     * estado desejado (checkboxes) comparado ao herdado dos grupos:
     *  - desejado e não herdado → allow individual;
     *  - não desejado e herdado → deny individual;
     *  - igual ao herdado → sem registro individual.
     * @param string[] $wanted chaves marcadas
     */
    public static function setUserGrants(int $userId, string $module, array $wanted, ?int $by): void
    {
        $valid  = self::allKeys($module);
        $wanted = array_flip(array_intersect(array_unique($wanted), $valid));

        // herdado dos grupos (sem overrides individuais)
        $inherited = [];
        $groupIds  = self::groupIdsOf($userId);
        if ($groupIds) {
            $in   = implode(',', array_fill(0, count($groupIds), '?'));
            $rows = DB::query(
                "SELECT DISTINCT perm_key FROM permission_grants
                 WHERE subject_type = 'group' AND subject_id IN ({$in}) AND module_slug = ? AND allowed = 1",
                [...$groupIds, $module]
            );
            $inherited = array_flip(array_column($rows, 'perm_key'));
        }

        DB::execute(
            "DELETE FROM permission_grants WHERE subject_type = 'user' AND subject_id = ? AND module_slug = ?",
            [$userId, $module]
        );

        foreach ($valid as $key) {
            $want = isset($wanted[$key]);
            $inh  = isset($inherited[$key]);
            if ($want === $inh) {
                continue; // herança já resolve
            }
            DB::execute(
                "INSERT INTO permission_grants (subject_type, subject_id, module_slug, perm_key, allowed, granted_by)
                 VALUES ('user', ?, ?, ?, ?, ?)",
                [$userId, $module, $key, $want ? 1 : 0, $by]
            );
        }
        self::flush();
    }

    /** Permissões herdadas dos grupos (sem overrides). @return array<string,bool> */
    public static function inheritedFor(int $userId, string $module): array
    {
        $groupIds = self::groupIdsOf($userId);
        if (!$groupIds) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($groupIds), '?'));
        $rows = DB::query(
            "SELECT DISTINCT perm_key FROM permission_grants
             WHERE subject_type = 'group' AND subject_id IN ({$in}) AND module_slug = ? AND allowed = 1",
            [...$groupIds, $module]
        );
        $set = [];
        foreach ($rows as $r) {
            $set[$r['perm_key']] = true;
        }
        return $set;
    }

    /** @return int[] */
    public static function groupIdsOf(int $userId): array
    {
        if (!isset(self::$groupsOf[$userId])) {
            $rows = DB::query('SELECT group_id FROM user_group_members WHERE user_id = ?', [$userId]);
            self::$groupsOf[$userId] = array_map('intval', array_column($rows, 'group_id'));
        }
        return self::$groupsOf[$userId];
    }

    public static function flush(): void
    {
        self::$effective = [];
        self::$groupsOf  = [];
        self::$loadedAll = [];
        self::$isAdmin   = [];
    }

    public static function isGlobalAdmin(int $userId): bool
    {
        if (Auth::id() === $userId) {
            return Auth::isGlobalAdmin();
        }
        if (!isset(self::$isAdmin[$userId])) {
            $row = DB::queryOne('SELECT is_admin FROM users WHERE id = ?', [$userId]);
            self::$isAdmin[$userId] = (bool) ($row['is_admin'] ?? false);
        }
        return self::$isAdmin[$userId];
    }
}
