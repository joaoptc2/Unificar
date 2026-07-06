# TeamChat — Sistema de Chat Interno Empresarial

> **PORTADO PARA A PLATAFORMA UNIFICADA** — este módulo agora roda em
> `/index.php?m=chat&page=...` sob o núcleo (`docs/PORTING.md`):
> autenticação/sessão/CSRF/layout/notificações/auditoria são do núcleo;
> tabelas com prefixo `chat_` (schema em `sql/modules/chat.sql`, migração
> do banco antigo em `sql/legacy-migration/chat.sql`); assets em
> `/assets/chat/`; uploads em `/uploads/chat/`; presença em `chat_presence`.
> As seções abaixo descrevem o sistema legado original e permanecem apenas
> como referência histórica — caminhos, login próprio, instalador e a
> configuração de banco própria do módulo NÃO existem mais.

Sistema de comunicação interna inspirado no Slack, desenvolvido como módulo
independente de um ERP corporativo. PHP 8.0+ puro (sem Composer), MySQL 5.7+,
Bootstrap 5.3, atualização em tempo real via AJAX polling.

---

## Stack Tecnológica

| Camada    | Tecnologia                                                         |
|-----------|--------------------------------------------------------------------|
| Backend   | PHP 8.0+ vanilla, PDO MySQL, arquitetura MVC                      |
| Frontend  | Bootstrap 5.3.2 + Bootstrap Icons 1.11.3 (CDN)                    |
| JS        | Vanilla ES6+, SortableJS 1.15 (Kanban drag-and-drop)              |
| Servidor  | Apache com mod_rewrite (.htaccess)                                 |
| Banco     | MySQL 5.7+ / MariaDB 10.3+, charset utf8mb4                       |
| Real-time | AJAX short polling (intervalo configurável, padrão 3s)             |

---

## Estrutura de Diretórios

```
/
├── index.php                    # Bootstrap: define BASE_PATH/BASE_URL, inclui public/index.php
├── install.php                  # Assistente de instalação (2 passos: DB + admin)
├── .htaccess                    # Rewrite, bloqueio de diretórios, headers de segurança
│
├── app/
│   ├── controllers/             # 11 controllers (1 classe por arquivo)
│   │   ├── AuthController.php       # Login, registro, logout
│   │   ├── ChatController.php       # Interface principal do chat (Slack-like)
│   │   ├── ApiController.php        # Endpoints JSON para AJAX (mensagens, reações, polling)
│   │   ├── ChannelController.php    # CRUD de canais, membros, config por canal
│   │   ├── TaskController.php       # Tarefas com Kanban e atribuição
│   │   ├── TeamController.php       # Equipes (cria canal privado ao criar)
│   │   ├── MeetingController.php    # Reuniões com RSVP e calendário
│   │   ├── ProcessController.php    # Workflows com etapas e progresso
│   │   ├── ProfileController.php    # Perfil e alteração de senha
│   │   ├── SearchController.php     # Busca global (mensagens, pessoas, canais, tarefas)
│   │   └── AdminController.php      # Dashboard admin, gestão de usuários, configurações
│   │
│   ├── helpers/                 # 12 classes utilitárias estáticas
│   │   ├── Autoloader.php           # spl_autoload_register para helpers/models/controllers
│   │   ├── Database.php             # PDO singleton
│   │   ├── Session.php              # Sessão segura + flash messages
│   │   ├── Auth.php                 # Adaptador do núcleo (micropermissões)
│   │   ├── Csrf.php                 # Token CSRF (field(), check(), checkAjax())
│   │   ├── Sanitize.php             # Escape XSS, validação, formatação de datas, slug
│   │   ├── Upload.php               # Upload com MIME check, paths públicos vs privados
│   │   ├── View.php                 # render (com layout), renderRaw, renderChat, capture
│   │   ├── AuditLog.php             # Log de auditoria em JSON
│   │   ├── Pagination.php           # Paginação Bootstrap
│   │   └── Notification.php         # Notificações in-app (create, unreadCount, recent)
│   │
│   ├── models/                  # 8 models (herdam de Model.php)
│   │   ├── Model.php                # Base: find/all/count/insert/update/delete + $fillable
│   │   ├── User.php                 # findByEmail, online, search, avatarUrl, initials
│   │   ├── Channel.php              # userChannels, directChannel, members, addMember, updateLastRead
│   │   ├── Message.php              # channelMessages, newMessages, threadReplies, reactions, search
│   │   ├── Task.php                 # byStatus (filtrado por user), withDetails, setAssignees
│   │   ├── Team.php                 # withMembers, userTeams, allActive, memberIds
│   │   ├── Meeting.php              # withParticipants, upcoming, respond, allForCalendar
│   │   └── Process.php              # withSteps, addStep, updateStepStatus, recalculateProgress
│   │
│   └── views/                   # Templates PHP (12 subpastas)
│       ├── layout/
│       │   ├── header.php           # Navbar + sidebar + flash messages (páginas padrão)
│       │   ├── footer.php           # Scripts Bootstrap + SortableJS + app.js
│       │   └── chat_layout.php      # Layout full-screen para o chat (sem navbar)
│       ├── auth/                    # login.php, register.php
│       ├── chat/                    # index.php (interface Slack-like completa)
│       ├── channels/                # form.php, browse.php, settings.php
│       ├── tasks/                   # index.php (kanban), form.php, show.php, my.php
│       ├── teams/                   # index.php, form.php, show.php
│       ├── meetings/                # index.php, form.php, show.php, calendar.php
│       ├── processes/               # index.php, form.php, show.php
│       ├── profile/                 # index.php, form.php
│       ├── search/                  # index.php
│       └── admin/                   # index.php, users.php, user_form.php, settings.php
│
├── config/
│   ├── app.php                  # Configuração geral (timezone, upload, polling, paginação)
│   ├── database.php             # Credenciais MySQL (gerado pelo install.php)
│   └── .installed               # Marker criado após instalação
│
├── public/
│   ├── index.php                # FRONT CONTROLLER (roteamento principal)
│   ├── .htaccess                # Rewrite para front controller
│   ├── css/style.css            # CSS completo (~600 linhas, variáveis, dark sidebar)
│   ├── js/
│   │   ├── app.js               # JS global: confirmações, auto-dismiss, Kanban, process steps
│   │   └── chat.js              # Engine de chat: polling, mensagens, reações, threads, menções
│   └── uploads/
│       ├── avatars/             # Fotos de perfil (público)
│       └── attachments/         # Arquivos anexados (público)
│
├── storage/                     # FORA do webroot (bloqueado por .htaccess)
│   ├── uploads/                 # Uploads privados
│   └── cache/                   # Cache em arquivo
│
├── sql/
│   ├── schema.sql               # Schema completo (20 tabelas)
│   └── migrations/
│       └── 001_channel_settings.sql  # Adiciona colunas de config por canal
│
└── logs/                        # Logs de erro PHP
```

---

## Ciclo de Vida de uma Requisição

```
1. Apache → .htaccess redireciona tudo para index.php (raiz)
2. index.php (raiz) → define BASE_PATH/BASE_URL, inclui public/index.php
3. public/index.php (front controller):
   a. Carrega config/app.php
   b. Verifica config/.installed → redireciona para install.php se ausente
   c. Registra Autoloader (spl_autoload_register)
   d. Session::start()
   e. Extrai $page e $action da query string (?page=X&action=Y)
   f. Verifica se $page é pública (login, auth); se não → Auth::requireLogin()
   g. Lookup no array $routes → nome do Controller
   h. Instancia o controller → chama $controller->$action()
   i. O controller faz core_require('<recurso>.<ação>'), Csrf::check(),
      consulta DB, e chama View::render() ou retorna JSON
```

---

## Roteamento

Todas as rotas usam query string: `index.php?m=chat&page=X&action=Y`

| page       | Controller            | Ações principais                                        |
|------------|-----------------------|---------------------------------------------------------|
| auth/login | AuthController        | login, doLogin, register, doRegister, logout            |
| chat       | ChatController        | index, channel                                          |
| api        | ApiController         | sendMessage, getMessages, editMessage, deleteMessage, toggleReaction, getReactions, pinMessage, getThread, uploadFile, searchMessages, getNotifications, markNotificationRead, heartbeat, userStatus, searchUsers, markChannelRead |
| channels   | ChannelController     | create, store, edit, update, archive, members, addMember, removeMember, join, leave, browse, direct, settings, updateSettings |
| tasks      | TaskController        | index (kanban), show, create, store, edit, update, updateStatus, comment, delete, my |
| teams      | TeamController        | index, show, create, store, edit, update, addMember, removeMember, delete |
| meetings   | MeetingController     | index, show, create, store, edit, update, respond, cancel, calendar |
| processes  | ProcessController     | index, show, create, store, edit, update, updateStep, pause, resume, complete, delete |
| profile    | ProfileController     | index, edit, update, updateStatus, password             |
| search     | SearchController      | index, api                                              |
| admin      | AdminController       | index, users, editUser, updateUser, settings, updateSettings |

---

## Micropermissões (RBAC do núcleo)

O módulo NÃO tem mais níveis próprios (admin/manager/member). O acesso é
por MICROPERMISSÕES "<recurso>.<ação>" declaradas no manifesto
(`module.php`, chave `permissions`) e resolvidas pelo núcleo
(`Core\Perms`, `$GLOBALS['MODULE_PERMS']`). Os níveis legados viraram
`presets` no manifesto (atalhos de concessão na UI de permissões).

Uso no código:
```php
core_require('tasks.create');   // no controller — 403 se ausente
core_can('chat.moderate');      // em views/condições — bool
Auth::requirePermission('tasks', 'create'); // wrapper legado → core_require()
Auth::can('tasks', 'create');               // wrapper legado → core_can()
```

Recursos do catálogo: chat (view/create/edit/delete/moderate), channels,
tasks, meetings, calendar, teams, processes, polls, search, categories,
emojis e admin (view/settings/export).

### Regras de Negócio Importantes

- **Exclusão de mensagens:** `chat.moderate` exclui qualquer mensagem;
  quem tem só `chat.delete` exclui as próprias e somente até 1 minuto
  após o envio.
- **Fixar mensagens:** requer `chat.moderate`.
- **Canais readonly:** `chat.moderate` fura o modo somente leitura;
  configurar o canal requer `channels.edit` (ou ser dono do canal).
- **Tarefas:** cada usuário vê as suas (criadas ou atribuídas); quem tem
  `admin.view` vê todas.
- **Presença automática:** Online quando na página, away ao trocar de aba, offline ao fechar.
- **Equipes → Canal:** Criar uma equipe automaticamente cria um canal privado associado.

---

## Banco de Dados (20 tabelas)

### Entidades Principais
- `users` — usuários com status de presença, avatar, role
- `channels` — públicos, privados, DMs + configurações (readonly, retenção, slow mode)
- `channel_members` — membros com role, notificações, last_read
- `messages` — com threads (parent_id), pins, soft delete, metadata JSON
- `message_reactions` — emoji por usuário por mensagem
- `message_attachments` — arquivos anexados
- `mentions` — @menções (user, team, channel, here)

### Colaboração
- `teams` / `team_members` — equipes com líder e membros
- `tasks` / `task_assignees` / `task_comments` — tarefas Kanban
- `meetings` / `meeting_participants` — reuniões com RSVP
- `processes` / `process_steps` — workflows com etapas

### Infraestrutura
- `notifications` — alertas in-app por usuário
- `bookmarks` — mensagens salvas
- `audit_log` — log de todas as ações
- `settings` — chave-valor (config do sistema)

---

## Helpers Disponíveis (API Rápida)

### Auth.php
```php
Auth::attempt($email, $password)              // bool
Auth::requireLogin()                          // redireciona se não logado
Auth::requirePermission($module, $action)     // 403 se sem a micropermissão
Auth::can($module, $action)                   // bool (wrapper de core_can)
core_require('recurso.acao')                  // helper do núcleo (preferido)
core_can('recurso.acao')                      // bool (helper do núcleo)
Auth::logout()
Auth::user()                                  // array do usuário logado
```

### Session.php
```php
Session::set($key, $value)
Session::get($key, $default)
Session::flash('success', 'Mensagem')         // armazena para próximo request
Session::flash('success')                     // lê e apaga
Session::isLoggedIn()                         // bool
Session::userId() / userName() / userAvatar()
Session::destroy()
```

### Csrf.php
```php
Csrf::field()      // <input hidden> para forms
Csrf::check()      // aborta com 403 se inválido (usar em todo POST)
Csrf::checkAjax()  // retorna bool (verifica POST body ou header X-CSRF-TOKEN)
Csrf::token()      // string pura
```

### Sanitize.php
```php
Sanitize::e($str)               // htmlspecialchars (TODA saída HTML)
Sanitize::string($str)          // trim + normaliza quebras
Sanitize::email($str)           // valida e normaliza
Sanitize::int($val)             // cast seguro
Sanitize::post($key, $default)  // string sanitizada do $_POST
Sanitize::get($key, $default)   // string sanitizada do $_GET
Sanitize::slug($str)            // url-safe slug
Sanitize::formatDate($str)      // 'dd/mm/aaaa'
Sanitize::formatDateTime($str)  // 'dd/mm/aaaa HH:MM'
Sanitize::timeAgo($datetime)    // 'há X min', 'ontem', etc.
```

### Outros
```php
Database::getInstance()                              // PDO singleton
View::render('module/template', $data)               // com header+footer
View::renderChat('chat/index', $data)                // layout full-screen
View::renderRaw('auth/login', $data)                 // sem layout
Upload::handle($field, $subDir)                      // retorna [success, path, ...]
Upload::url($path)                                   // URL pública ou via download
AuditLog::log($action, $table, $id, $old, $new)
Notification::create($userId, $type, $title, $content, $link)
Notification::createForMany($userIds, ...)
Notification::unreadCount($userId)
$p = new Pagination($total, $currentPage, $perPage)  // $p->render()
```

---

## Chat em Tempo Real (chat.js)

Classe `TeamChat` (instanciada automaticamente quando `.chat-wrapper` existe):

| Funcionalidade      | Mecanismo                                                       |
|---------------------|-----------------------------------------------------------------|
| Polling de mensagens | `setInterval` a cada 3s → `GET api/getMessages?after_id=N`    |
| Heartbeat           | A cada 30s → `GET api/heartbeat` (unread counts, presença)     |
| Presença automática | `visibilitychange` → away/online; `beforeunload` → offline     |
| Envio de mensagem   | `POST api/sendMessage` com FormData (suporta anexos)            |
| Reações             | `POST api/toggleReaction` → renderiza badges visuais            |
| Threads             | `GET api/getThread` → painel lateral direito                    |
| @Menções            | Autocomplete enquanto digita `@` → dropdown com usuários        |
| Som de notificação  | Web Audio API (senoidal 880Hz, 0.3s) em mensagens de terceiros  |
| Emoji picker        | Grid de emojis dentro do input area, posição absoluta           |

### Meta tags necessárias (geradas pelo chat_layout.php):
```html
<meta name="csrf-token" content="...">
<meta name="base-url" content="...">
<meta name="user-id" content="...">
<meta name="poll-interval" content="3000">
```

---

## Como Criar um Novo Módulo

### 1. Tabela
Criar em `sql/schema.sql` + `sql/migrations/NNN_nome.sql`:
```sql
CREATE TABLE IF NOT EXISTS `nova_tabela` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ...
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2. Model
Criar `app/models/NovaEntidade.php`:
```php
class NovaEntidade extends Model {
    protected static string $table = 'nova_tabela';
    protected static array  $fillable = ['campo1', 'campo2'];
}
```

### 3. Controller
Criar `app/controllers/NovoController.php` com padrão:
- `__construct()` → `$this->db = Database::getInstance()`
- `index()` → `Auth::requireLogin()`, query, `View::render()`
- `store()` → `Csrf::check()`, validação, `Model::insert()`, `AuditLog::log()`, redirect
- Retornos JSON: `header('Content-Type: application/json')` + `json_encode()`

### 4. Registrar rota
Em `public/index.php`, adicionar ao `$routes`:
```php
'novo' => 'NovoController',
```

### 5. Permissões
No manifesto `module.php`, adicionar o recurso/ações em `permissions`
(e, se fizer sentido, aos `presets`); usar core_require()/core_can().

### 6. Views
Criar `app/views/novo/index.php`, `form.php`, etc.

### 7. Sidebar
No manifesto `module.php` (closure `menu`), adicionar o item filtrado por `$can('<recurso>.view')`.

---

## Convenções de Código

### PHP
- Sem Composer, sem frameworks — PHP vanilla
- Models herdam de `Model.php` com `$table` e `$fillable`
- Controllers com nome `XxxController`, actions como métodos públicos
- `Csrf::check()` em TODO form POST
- `Sanitize::e()` em TODA saída HTML
- `AuditLog::log()` em create/update/delete
- Flash messages: `Session::flash('success'|'error', 'texto')`
- Validação server-side antes de insert/update

### CSS
- Cards: `card border-0 shadow-sm`
- Tabelas: `table table-sm table-hover` dentro de `table-responsive`
- Labels: `form-label` (obrigatórios: `form-label required`)
- Forms: `row g-3` com `col-md-*`
- Botão principal: `btn btn-primary`. Voltar: `btn btn-outline-secondary btn-sm`

### JavaScript
- Vanilla ES6+ (sem jQuery)
- API calls via `fetch()` com CSRF token no header `X-CSRF-TOKEN`
- URLs relativas: `index.php?m=chat&page=api&action=xxx`
- Confirmação: atributo `data-confirm="Mensagem?"` em qualquer elemento

---

## Instalação

1. Faça upload dos arquivos para o servidor (document root aponta para a pasta raiz ou `public/`)
2. Acesse `install.php` no navegador
3. **Passo 1:** Informe as credenciais do MySQL (o instalador cria o banco e executa o schema)
4. **Passo 2:** Crie a conta de administrador
5. Acesse o sistema e delete `install.php`
6. Se atualizar de uma versão anterior, rode as migrations em `sql/migrations/` no banco

### Requisitos do Servidor
- PHP 8.0+ com extensões: `pdo_mysql`, `mbstring`, `json`
- MySQL 5.7+ ou MariaDB 10.3+
- Apache com `mod_rewrite` e `AllowOverride All`
- Permissão de escrita em: `storage/`, `logs/`, `public/uploads/`

---

## Checklist para Alterações

- [ ] `php -l` em todos os arquivos PHP alterados
- [ ] CSRF em todo formulário POST
- [ ] `Sanitize::e()` em toda saída HTML
- [ ] `AuditLog::log()` em operações de escrita
- [ ] Validação server-side antes de insert/update
- [ ] Flash messages para feedback ao usuário
- [ ] Paginação em listagens que possam crescer
- [ ] `data-confirm` em botões de exclusão
- [ ] Testar com perfis admin, manager e member
- [ ] Migrations para alterações em tabelas existentes
