<?php
/**
 * Converte os acessos legados por NÍVEL (tabela user_module_access,
 * preenchida pelos scripts de migração dos sistemas antigos) em
 * MICROPERMISSÕES (permission_grants), usando os modelos ('presets')
 * declarados no manifesto de cada módulo.
 *
 * Uso: php scripts/migrate_role_grants.php [--dry-run]
 *
 * Idempotente: só cria grants individuais para usuários que ainda não
 * possuem NENHUM grant no módulo em questão.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/core/bootstrap.php';

use Core\DB;
use Core\Modules;
use Core\Perms;

$dryRun = in_array('--dry-run', $argv ?? [], true);

$rows = DB::query('SELECT user_id, module_slug, role FROM user_module_access ORDER BY user_id');
if (!$rows) {
    echo "Nada a converter (user_module_access vazia).\n";
    exit(0);
}

$converted = $skipped = 0;

foreach ($rows as $row) {
    $userId = (int) $row['user_id'];
    $module = (string) $row['module_slug'];
    $role   = (string) $row['role'];

    $manifest = Modules::manifest($module);
    if (!$manifest) {
        echo "AVISO: módulo '{$module}' não encontrado — pulando usuário {$userId}.\n";
        $skipped++;
        continue;
    }

    $preset = $manifest['presets'][$role] ?? null;
    if ($preset === null) {
        echo "AVISO: módulo '{$module}' não tem preset para o nível '{$role}' — pulando usuário {$userId}.\n";
        $skipped++;
        continue;
    }

    // Já tem grants neste módulo? Não sobrescreve.
    $existing = DB::queryOne(
        "SELECT COUNT(*) n FROM permission_grants WHERE subject_type = 'user' AND subject_id = ? AND module_slug = ?",
        [$userId, $module]
    );
    if ((int) ($existing['n'] ?? 0) > 0) {
        $skipped++;
        continue;
    }

    $keys = Perms::expand($module, (array) ($preset['keys'] ?? []));
    echo sprintf("usuário %d · %s · %s → %d permissões%s\n", $userId, $module, $role, count($keys), $dryRun ? ' [dry-run]' : '');

    if (!$dryRun) {
        Perms::setUserGrants($userId, $module, $keys, null);
        $converted++;
    }
}

echo "\nConcluído: {$converted} convertidos, {$skipped} pulados.\n";
if (!$dryRun) {
    echo "Dica: crie GRUPOS na administração e mova os usuários para eles — "
       . "as permissões individuais criadas aqui podem depois ser limpas em favor dos grupos.\n";
}
