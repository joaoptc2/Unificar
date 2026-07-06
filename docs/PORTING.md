# Contrato de porte de módulos — Plataforma Unificada

Este documento define como cada sistema legado (DOCUMENTOS, chat, RH,
MANUTENCAO) é adaptado para rodar como **módulo** da plataforma.

## O que o núcleo já faz ANTES de entregar a request ao módulo

O front controller (`/index.php?m=<slug>&...`) executa, nesta ordem:

1. `core/bootstrap.php` — sessão única iniciada, `BASE_URL` definida,
   autoloader de `Core\*`, helpers `core_*()` disponíveis.
2. Autentica (`Core\Auth::requireLogin()`) — exceto rotas declaradas
   públicas pelo manifesto (`is_public`).
3. Verifica acesso ao módulo (`Core\Access::requireModule($slug)`).
4. Define:
   - `MODULE_SLUG`, `MODULE_PATH`, `MODULE_URL` (constantes);
   - `$GLOBALS['MODULE_ROLE']` — papel do usuário NESTE módulo
     (string do vocabulário do próprio módulo, ou `'none'`);
   - `chdir(MODULE_PATH)`.
5. Faz `require modules/<slug>/index.php` (entry do módulo).

Variáveis de sessão garantidas pelo núcleo após login:
`user_id`, `user_name`, `user_email`, `user_avatar`, `is_global_admin`,
`hospital_id` (unidade padrão, para os módulos multi-unidade legados),
`hospital_name`, `login_time`, `csrf_token`.

**A sessão NÃO contém `user_role`** — papel é por módulo, via
`$GLOBALS['MODULE_ROLE']` (já definido a cada request).

## Manifesto (modules/<slug>/module.php)

```php
<?php
return [
    'slug'        => 'chat',
    'name'        => 'Comunicação',
    'icon'        => 'bi-chat-dots',
    'description' => 'Chat, tarefas, reuniões e equipes.',
    'entry'       => 'index.php',

    // CATÁLOGO DE MICROPERMISSÕES: cada função do módulo, agrupada por
    // recurso. A chave efetiva é "<recurso>.<ação>" (ex.: tasks.create).
    'permissions' => [
        'chat'  => ['label' => 'Chat',    'actions' => ['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir']],
        'tasks' => ['label' => 'Tarefas', 'actions' => ['view' => 'Visualizar', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir']],
        // ... TODAS as funções do módulo
    ],

    // MODELOS (presets): atalhos na UI de permissões + base do conversor
    // de níveis legados (scripts/migrate_role_grants.php). A chave DEVE
    // ser o nível legado correspondente. Suporta curingas: '*' e 'rec.*'.
    'presets' => [
        'admin'   => ['label' => 'Administrador', 'keys' => ['*']],
        'manager' => ['label' => 'Gestor',        'keys' => ['chat.*', 'tasks.*']],
        'member'  => ['label' => 'Membro',        'keys' => ['chat.view', 'chat.create', 'tasks.view']],
    ],

    // menu lateral — recebe um verificador de micropermissões
    'menu'        => function (callable $can): array {
        $items = [];
        if ($can('chat.view')) {
            $items[] = ['label' => 'Chat', 'url' => core_module_url('chat', ['page' => 'chat']), 'icon' => 'bi-chat', 'key' => 'chat'];
        }
        // ...
        return [['heading' => 'Comunicação', 'items' => $items]];
    },
    // rotas públicas (sem login) — opcional
    'is_public'   => fn (array $get): bool => in_array($get['page'] ?? '', ['track-os'], true),
    // rotina de cron — opcional
    'cron'        => function (): void { require __DIR__ . '/cron/check.php'; },
];
```

### Micropermissões dentro do código do módulo

- O núcleo define `$GLOBALS['MODULE_PERMS']` (conjunto efetivo) por request
  e expõe os helpers globais:
  - `core_can('documents.edit')` → bool (módulo atual);
  - `core_require('documents.edit')` → interrompe com 403.
- Acesso ao módulo = ter QUALQUER permissão nele (o front controller já
  bloqueia; o menu superior só mostra módulos com alguma permissão).
- Admin global (`users.is_admin`) tem todas as permissões automaticamente.
- "Notificar gestores" → `Core\Perms::usersWith('<slug>', '<perm>')`.
- Os antigos papéis (`$GLOBALS['MODULE_ROLE']`, `user_module_access`) são
  LEGADO: nenhum código de módulo deve lê-los.

Atenção: o manifesto é carregado também FORA do módulo (topbar, admin),
quando `MODULE_URL` não existe. No closure `menu` use
`core_module_url('<slug>', ['page' => ...])` em vez de `MODULE_URL`.

## Regras de adaptação

### Autenticação e papéis
- Helpers de sessão/auth do módulo viram **adaptadores** do núcleo:
  - "está logado?" → `Core\Auth::check()`;
  - id/nome/email → `$_SESSION['user_id']` etc. (mantidos pelo núcleo);
  - papel do usuário → `$GLOBALS['MODULE_ROLE']` (traduzir para o formato
    legado quando necessário — ex.: DOCUMENTOS usa 1/2/3);
  - `requireLogin()` → redireciona para `index.php?m=auth&a=login` (na raiz).
- **Remover** fluxos locais de: login, registro, reset de senha, troca de
  senha, 2FA → redirecionar para as telas do núcleo
  (`?m=auth&a=login|security|profile`).
- CRUD de usuários dentro do módulo: remover do menu e redirecionar para a
  administração central (`?m=admin&a=users`). Telas de domínio (setores,
  categorias, equipamentos, etc.) permanecem no módulo.
- CSRF: delegar para `Core\Csrf` (token único em `$_SESSION['csrf_token']`,
  aceita `_csrf_token`, `csrf_token` ou header `X-CSRF-TOKEN`).

### Banco de dados (banco único)
- Conexão: sempre `Core\DB::pdo()` (adaptar o helper de conexão do módulo).
- **Prefixar todas as tabelas do módulo**: `doc_`, `chat_`, `rh_`, `man_`.
- Tabelas que DEIXAM de existir no módulo (agora são do núcleo):
  - `users` → usar a tabela global `users` (sem prefixo). Colunas novas:
    `password_hash` (não `password`), `active` (não `is_active`/`status`),
    SEM coluna de papel (`role`/`role_id`) — papel vem do RBAC. Atributos
    específicos do módulo (vínculos) vão para uma tabela de perfil própria
    (ex.: `rh_user_profile(user_id, employee_id, department_id)`).
  - `notifications` → tabela global `notifications`
    (`user_id, module, type, title, message, link, read_at, created_at`).
    Gravar com `module = '<slug>'` (ou `Core\Notifications::add()`).
  - `audit_log`/`audit_logs` → global `audit_log` via `Core\Audit::log()`.
  - `login_attempts`, `password_resets`, `user_2fa` → núcleo (remover).
- Entregáveis SQL:
  - `sql/modules/<slug>.sql` — schema consolidado (schema + migrations
    legadas) com prefixo, FKs para `users(id)` do núcleo, seeds mínimos.
    Idempotente (`CREATE TABLE IF NOT EXISTS`).
  - `sql/legacy-migration/<slug>.sql` — script comentado de migração dos
    dados do banco antigo para o novo (INSERT ... SELECT), assumindo o banco
    antigo acessível como `legado_<slug>`. Incluir o mapeamento de usuários
    (por e-mail) e de papéis antigos → `user_module_access`.

### Layout e views
- O layout legado (navbar própria, sidebar própria, <html>/<head>) é
  substituído: a view do módulo produz APENAS o HTML do conteúdo e o
  renderiza via `Core\Layout::render([...])`:
  ```php
  Core\Layout::render([
      'title'   => $pageTitle,
      'content' => $html,
      'active'  => 'chave-do-item-do-menu',   // marca o item da sidebar
      'head'    => '<link rel="stylesheet" href="' . core_asset('chat/style.css') . '">',
      'scripts' => '<script src="' . core_asset('chat/chat.js') . '"></script>',
      'fluid'   => false, // true para telas full-screen (ex.: chat)
  ]);
  ```
  Na prática: reescrever o helper/arquivo de layout do módulo para capturar
  o conteúdo e delegar ao `Core\Layout` — o resto das views muda pouco.
- O menu lateral do módulo é declarado no manifesto (`menu`), filtrando
  itens pelo papel — reproduzir a lógica de visibilidade legada.
- Flash messages: o módulo pode manter seu próprio mecanismo, desde que as
  mensagens sejam renderizadas dentro do conteúdo.

### URLs e assets
- Toda URL interna do módulo precisa carregar `m=<slug>`:
  `index.php?page=x` → `index.php?m=<slug>&page=x` (inclusive no JS).
  Reescrever os helpers de URL do módulo para já incluir o prefixo.
- Redirecionamentos absolutos: usar `MODULE_URL . '&page=...'`.
- Assets: mover CSS/JS do módulo para `/assets/<slug>/` e referenciar com
  `core_asset('<slug>/arquivo.css')`.
- Uploads: gravar em `/uploads/<slug>/...` (constante no bootstrap do
  módulo). Manter os `.htaccess` de bloqueio.

### O que preservar
- TODA a lógica de negócio, queries (além do prefixo), validações e
  fluxos existentes. Este é um porte, não uma reescrita.
- Bootstrap 5.3.2 + Bootstrap Icons (já carregados pelo layout do núcleo —
  não carregar de novo nas views).

### Qualidade
- `php -l` limpo em todos os arquivos do módulo.
- Nenhuma ocorrência restante de: tabela sem prefixo do módulo,
  `index.php?page=` sem `m=`, `session_start()`, `header('Location: index.php?page=login')`,
  referências a colunas removidas de `users` (`role`, `role_id`, `is_active`,
  `password` como hash, `deleted_at`).
