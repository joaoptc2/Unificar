# Módulo Comunicação (`chat`)

Chat interno da Plataforma Unificada: canais públicos/privados, mensagens
diretas, threads, reações, anexos, menções, mensagens fixadas, favoritos,
busca, presença/status e notificações — tudo renderizado dentro do layout
padrão do núcleo (`Core\Layout`: topbar + sidebar do módulo).

O módulo é **somente chat**. Tarefas, reuniões, calendário, equipes,
processos, enquetes, exportações, painel/estatísticas e configurações
visuais foram descontinuados (as rotas antigas redirecionam para o chat
com um aviso; as tabelas correspondentes permanecem no banco apenas por
preservação de dados — ver `sql/migrations/005_chat.sql`).

O nome exibido é o da organização (`Core\Settings::get('org_name')`,
com fallback em `app.name`) ou "Comunicação".

---

## Rotas

Front controller da plataforma: `index.php?m=chat&page=<página>&action=<ação>`.

| Página / ação | Método | Permissão | Descrição |
|---|---|---|---|
| `page=chat` (`&channel_id=N`) | GET | `chat.view` | Tela do chat (abre o canal indicado ou o primeiro) |
| `page=chat&action=channel&id=N` (`&ajax=1`) | GET | `chat.view` | Abre o canal (público: entra automaticamente); com `ajax=1` retorna JSON |
| `page=channels&action=browse` | GET | `channels.view` | Explorar canais (entrar/sair) |
| `page=channels&action=create` / `store` | GET / POST | `channels.create` | Criar canal |
| `page=channels&action=edit&id=N` / `update` | GET / POST | `channels.view` + gestão do canal | Editar nome/descrição/tipo/categoria |
| `page=channels&action=settings&id=N` / `updateSettings` | GET / POST | gestão do canal | Tópico, somente leitura, limites |
| `page=channels&action=members&id=N` | GET (JSON) | membro | Lista de membros |
| `page=channels&action=addMember` / `removeMember` | POST (JSON) | gestão do canal | Membros |
| `page=channels&action=join` / `leave` / `archive` / `direct` | POST | `channels.view` / `chat.view` / gestão / `chat.view` | Entrar, sair, arquivar, abrir DM |
| `page=search` (`&query=`) / `action=api` | GET | `search.view` | Busca de mensagens (HTML / JSON) |
| `page=api&action=…` | GET/POST (JSON) | ver abaixo | Endpoints usados por `chat.js` |
| `page=admin` (qualquer GET) | GET | — | Redireciona para a Administração central |
| `page=admin&action=saveCategory` / `deleteCategory` | POST | `categories.*` | CRUD de categorias |
| `page=admin&action=addEmoji` / `deleteEmoji` | POST | `emojis.*` | CRUD de emojis personalizados |
| `page=tasks|teams|meetings|calendar|processes|polls|export` | GET | — | Descontinuadas → redirect para o chat com flash |

"Gestão do canal" = `channels.edit` **ou** criador do canal **ou** papel
`owner`/`admin` em `chat_channel_members`.

### Endpoints da API (`page=api`)

| Ação | Método | Permissão |
|---|---|---|
| `sendMessage` (`channel_id`, `content`, `parent_id?`, `attachment?`) | POST | `chat.create` |
| `getMessages` (`channel_id`, `after_id`) — polling | GET | `chat.view` |
| `getOlderMessages` (`channel_id`, `before_id`) | GET | `chat.view` |
| `editMessage` (`message_id`, `content`) | POST | `chat.edit` (autor, membro do canal) |
| `deleteMessage` (`message_id`) | POST | `chat.delete` (autor, até 1 min) ou `chat.moderate` — sempre limitado aos canais de que participa (ou públicos) |
| `toggleReaction` (`message_id`, `emoji`) | POST | `chat.view` |
| `pinMessage` (`message_id`) | POST | `chat.moderate` + membro do canal |
| `getPinnedMessages` (`channel_id`) | GET | `chat.view` |
| `getThread` (`message_id`) | GET | `chat.view` |
| `heartbeat` — não lidas por canal, presença, contador de notificações | GET | `chat.view` |
| `userStatus` (`status`) | POST | `chat.view` |
| `searchUsers` (`query`) | GET | `chat.view` |
| `markChannelRead` (`channel_id`) | POST | `chat.view` |
| `toggleFavorite` (`channel_id`) | POST | `chat.view` |
| `typing` (`channel_id`, `typing`) | POST | `chat.view` |
| `downloadAttachment` (`id`) | GET | `chat.view` + membro do canal |
| `getCustomEmojis` | GET | `chat.view` |

Todo POST exige o token CSRF do núcleo (`_csrf_token` no corpo ou
cabeçalho `X-CSRF-TOKEN`); erros de permissão em AJAX retornam JSON 403.

---

## Configuração na Administração central

O manifesto declara `'admin' => ['entry' => 'admin_panel.php', 'tabs' => …]`.
O núcleo abre `index.php?m=admin&a=module&slug=chat&tab=categories|emojis`,
que inclui `admin_panel.php`; este faz o mesmo bootstrap do `index.php`
(config + autoloader) e chama `AdminController::categories()` ou
`::emojis()`, cujas views são renderizadas dentro do "chrome" da
administração. Os formulários enviam para as rotas POST do módulo
(`?m=chat&page=admin&action=…`) e voltam para `core_admin_url('chat', <aba>)`.

---

## Micropermissões (manifesto `module.php`)

| Recurso | Ações |
|---|---|
| `chat` | `view`, `create`, `edit`, `delete`, `moderate` |
| `channels` | `view`, `create`, `edit`, `delete` (arquivar) |
| `search` | `view` |
| `categories` | `view`, `create`, `edit`, `delete` |
| `emojis` | `view`, `create`, `delete` |

`Auth::can($recurso, $acao)` / `Auth::requirePermission()` são apenas
adaptadores para `core_can()` / `core_require()`; recursos fora do
manifesto resolvem para uma chave inexistente (sempre negada).

---

## Estrutura

```
modules/chat/
├── module.php               # Manifesto (permissões, presets, menu, painel admin)
├── index.php                # Roteador do módulo (page → controller)
├── admin_panel.php          # Entry do painel na Administração central
├── config/app.php           # timezone, upload (tamanho/tipos), poll_interval, paginação
└── app/
    ├── controllers/         # Chat, Channel, Search, Api, Admin
    ├── models/              # Model (base), User, Channel, Message
    ├── helpers/             # Autoloader, Database, Session, Auth, Csrf, Sanitize,
    │                        # Upload, AuditLog, Notification, Pagination, View
    └── views/
        ├── chat/            # index.php (tela do chat) e _message.php (parcial)
        ├── channels/        # browse.php, form.php, settings.php
        ├── search/          # index.php
        └── admin/           # categories.php, emojis.php (painel central)

assets/chat/
├── style.css                # Enquadramento no layout do núcleo + estilos do chat
└── chat.js                  # Motor do chat (classe ChatApp)

uploads/chat/{attachments,avatars,emojis}/
sql/modules/chat.sql         # Schema das tabelas chat_*
sql/migrations/005_chat.sql  # Migração desta versão (índice + limpeza de settings)
```

`View::render()` (páginas comuns) e `View::renderChat()` (tela do chat,
`fluid` + `body_class="chat-app"`) usam `Core\Layout::render`;
`View::renderRaw()` devolve o template sem layout (AJAX).

---

## Front-end (`chat.js`)

Classe `ChatApp`, instanciada quando existe `.chat-wrapper`. Vanilla JS,
sem dependências (usa `bootstrap.Modal` do layout apenas se disponível).

| Funcionalidade | Mecanismo |
|---|---|
| Polling de mensagens | `getMessages?after_id=N` a cada `poll_interval` (aba oculta: `poll_interval_idle`) |
| Heartbeat | a cada 30 s → contadores de não lidas, presença, notificações |
| Presença | `visibilitychange` → away/online; `pagehide` → `sendBeacon` offline |
| Envio | `sendMessage` via `FormData` (UI otimista; anexo com preview e arrastar-soltar) |
| Edição / exclusão / fixar | ações inline por mensagem (conforme permissões em `data-can-*`) |
| Reações | seletor de emoji (padrão + personalizados `:nome:`) |
| Threads / membros / fixados | painel direito (`#chatPanel`) |
| Menções | autocomplete `@nome`, `@canal`, `@here` |
| Digitando | `typing` + indicador no rodapé da lista |
| Não lidas | badge por canal e barra "N novas mensagens" |

Metatags lidas: `base-url`, `csrf-token`, `user-id` (núcleo),
`chat-poll-interval` e `chat-poll-idle` (injetadas por `View::scripts()`).

---

## Banco de dados (tabelas em uso)

`chat_channels`, `chat_channel_members`, `chat_channel_categories`,
`chat_channel_favorites`, `chat_messages` (índice
`idx_chat_msg_channel_deleted` em `channel_id, deleted_at, created_at`),
`chat_message_attachments`, `chat_message_reactions`, `chat_mentions`,
`chat_custom_emojis`, `chat_presence`, `chat_settings` (`max_upload_size`,
`default_channel`). O estado "digitando" não vai ao banco: são arquivos
temporários em `STORAGE_PATH/cache/chat_typing_<canal>_<usuário>.json`.

---

## Uploads

`Upload::handle()` valida extensão **e** MIME (lista em `config/app.php`),
gera nome aleatório e grava em `uploads/chat/<subpasta>/`. Anexos de
mensagem: `attachments/`; emojis personalizados: `emojis/` (PNG/GIF/JPG/WEBP).

Os **anexos nunca são servidos pelo caminho público**: `uploads/chat/attachments/`
tem um `.htaccess` com `Require all denied` e a entrega passa por
`page=api&action=downloadAttachment&id=N`, que confirma se o usuário participa
do canal da mensagem antes de mandar o arquivo (`Content-Disposition: attachment`
para tudo que não é imagem, `X-Content-Type-Options: nosniff`). Emojis
personalizados continuam públicos (não têm conteúdo sensível).
