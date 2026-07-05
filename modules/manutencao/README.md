# Módulo MANUTENÇÃO (ManuHosp portado)

Sistema de gestão de manutenção hospitalar rodando como **módulo** da
Plataforma Unificada (`/index.php?m=manutencao&page=...`).

Contrato do porte: `docs/PORTING.md` (raiz da plataforma).

## O que mudou em relação ao legado

- **Autenticação/sessão/CSRF/RBAC**: delegados ao núcleo (`core/`).
  `config.php` deste módulo é um **adaptador** — mantém a API legada
  (`db()`, `isLoggedIn()`, `hasRole()`, `canWrite()`, `verifyCsrf()`,
  `auditLog()`, `url()`, `redirect()`, ...) delegando para `Core\*`.
- **Papéis** (`admin`, `manager`, `maintenance`, `cleaning`, `viewer`)
  agora vêm de `user_module_access` (module_slug `manutencao`) via
  `$GLOBALS['MODULE_ROLE']`; a coluna `users.role` deixou de existir.
- **Login/registro/logout locais removidos** → telas do núcleo
  (`?m=auth&a=login|logout`). O CRUD de usuários saiu de `pages/admin.php`
  → administração central (`?m=admin&a=users`); o módulo mantém apenas
  Hospital e Setores.
- **Banco único**: todas as tabelas do módulo têm prefixo `man_`
  (`sql/modules/manutencao.sql`). `users`, `notifications` e `audit_log`
  são as tabelas GLOBAIS do núcleo (`notifications.module='manutencao'`,
  `read_at` no lugar de `is_read`).
- **Layout**: `pages/layout.php` captura o conteúdo e chama
  `Core\Layout::render()` (topbar + sidebar do manifesto `module.php`).
- **Assets**: `/assets/manutencao/style.css` e `/assets/manutencao/app.js`
  (via `core_asset()`). **Uploads**: `/uploads/manutencao/`.
- **Cron**: `cron/run.php`, executado pelo cron unificado da raiz
  (`php cron.php --module=manutencao`); a autenticação por token é do
  cron da raiz — o segredo local do legado foi removido.
- **Instalador/PWA removidos** (`install.php`, `sw.js`, `manifest.json`,
  arquivo local de credenciais) — o instalador da raiz cuida do schema e
  da configuração.

## Páginas públicas (sem login)

`anonymous-os`, `qr-scan`, `track-os` — declaradas em `module.php`
(`is_public`) e renderizadas standalone (sem o chrome da plataforma).

## Migração de dados do sistema antigo

`sql/legacy-migration/manutencao.sql` — INSERT...SELECT a partir do banco
legado (assumido acessível como `legado_manutencao`), incluindo o
mapeamento de usuários por e-mail para a tabela global `users` e dos
papéis antigos para `user_module_access`.
