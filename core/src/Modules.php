<?php

declare(strict_types=1);

namespace Core;

/**
 * Registro de módulos. Cada módulo vive em modules/<slug>/ e possui um
 * manifesto module.php que retorna:
 *
 *  [
 *    'slug'        => 'chat',
 *    'name'        => 'Comunicação',
 *    'icon'        => 'bi-chat-dots',           // Bootstrap Icons
 *    'description' => '...',
 *    'roles'       => ['admin' => 'Administrador', ...], // maior → menor
 *    'admin_role'  => 'admin',  // papel dado a admins globais
 *    'entry'       => 'index.php',
 *    'menu'        => fn (string $role): array => [...], // sidebar
 *    'is_public'   => fn (array $get): bool => false,    // rotas sem login
 *  ]
 */
final class Modules
{
    private static ?array $manifests = null;

    /**
     * Slugs cujo nome foi escolhido pelo hospital em Administração > Módulos
     * (coluna modules.label). Um rótulo próprio vence o nome dinâmico de
     * forUser(): quem renomeou o módulo quer ver o nome que escolheu.
     *
     * @var array<string,bool>
     */
    private static array $rotuloProprio = [];

    /** @return array<string, array> slug => manifesto (ordenado) */
    public static function all(): array
    {
        if (self::$manifests !== null) {
            return self::$manifests;
        }

        self::$manifests = [];
        foreach (glob(MODULES_PATH . '/*/module.php') ?: [] as $file) {
            $manifest = require $file;
            if (!is_array($manifest) || empty($manifest['slug'])) {
                continue;
            }
            $manifest['path'] = dirname($file);
            self::$manifests[$manifest['slug']] = $manifest;
        }

        // Ordem, ativação, nome e ícone escolhidos na Administração > Módulos.
        // O nome e o ícone do hospital ficam em colunas PRÓPRIAS (label,
        // custom_icon): name/icon são reescritos com os valores do manifesto
        // toda vez que a tela de Módulos é aberta.
        try {
            $rows = DB::query('SELECT slug, label, custom_icon, sort_order, active, show_in_topbar FROM modules');
            $meta = array_column($rows, null, 'slug');
            foreach (self::$manifests as $slug => &$m) {
                $m['sort_order']     = (int) ($meta[$slug]['sort_order'] ?? 999);
                $m['active']         = (bool) ($meta[$slug]['active'] ?? true);
                $m['show_in_topbar'] = (bool) ($meta[$slug]['show_in_topbar'] ?? true);
                if (!empty($meta[$slug]['label'])) {
                    $m['name'] = (string) $meta[$slug]['label'];
                    self::$rotuloProprio[$slug] = true;
                }
                if (!empty($meta[$slug]['custom_icon'])) {
                    $m['icon'] = (string) $meta[$slug]['custom_icon'];
                }
            }
            unset($m);
            uasort(self::$manifests, fn ($a, $b) => ($a['sort_order'] ?? 999) <=> ($b['sort_order'] ?? 999));
        } catch (\Throwable) {
            // instalador ainda não rodou — segue com defaults
        }

        return self::$manifests;
    }

    public static function manifest(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    public static function exists(string $slug): bool
    {
        $m = self::manifest($slug);
        return $m !== null && ($m['active'] ?? true);
    }

    /** Módulos visíveis no menu superior para o usuário logado. */
    public static function forUser(int $userId): array
    {
        $user   = Auth::user();
        $result = [];
        foreach (self::all() as $slug => $manifest) {
            if (!($manifest['active'] ?? true)) {
                continue;
            }
            if (!Perms::hasAny($userId, $slug)) {
                continue;
            }
            // Nome de exibição por pessoa (o módulo "Meu espaço" mostra o
            // primeiro nome de quem está logado). Só vale aqui, nas telas do
            // usuário: Administração > Módulos continua vendo o nome
            // canônico do manifesto, e é esse que vai para o banco.
            // Um rótulo escolhido pelo hospital manda mais que os dois.
            if (isset($manifest['display_name']) && is_callable($manifest['display_name'])
                && empty(self::$rotuloProprio[$slug]) && $user !== null) {
                $nome = ($manifest['display_name'])($user);
                if (is_string($nome) && trim($nome) !== '') {
                    $manifest['name'] = trim($nome);
                }
            }
            $result[$slug] = $manifest;
        }
        return $result;
    }
}
