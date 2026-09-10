# Módulo MANUTENÇÃO (ManuHosp portado)

Sistema de gestão de manutenção hospitalar rodando como **módulo** da
Plataforma Unificada (`/index.php?m=manutencao&page=...`).

Contrato do porte: `docs/PORTING.md` (raiz da plataforma).

## O que mudou em relação ao legado

- **Autenticação/sessão/CSRF/RBAC**: delegados ao núcleo (`core/`).
  `config.php` deste módulo é um **adaptador** — mantém a API legada
  (`db()`, `isLoggedIn()`, `verifyCsrf()`, `auditLog()`, `url()`,
  `redirect()`, ...) delegando para `Core\*`.
- **Micropermissões por função** (não mais níveis): o catálogo
  `<recurso>.<ação>` está no manifesto (`module.php`, chave
  `permissions`) e o conjunto efetivo do usuário chega por request em
  `$GLOBALS['MODULE_PERMS']`. As pages checam com
  `core_can('recurso.ação')` / `core_require('recurso.ação')`;
  `requireModule()` do `config.php` apenas traduz o nome da página para a
  chave `.view` correspondente. Todo POST passa por `manPostIsValid()`,
  que **rejeita** (mensagem + auditoria + redirecionamento) o envio sem
  token CSRF válido em vez de descartá-lo em silêncio. Os níveis
  legados (`admin`, `manager`, `maintenance`, `cleaning`, `viewer`)
  viraram `presets` no manifesto, usados pela UI de permissões e pelo
  conversor de grants.
- **Notificações de responsáveis**: `manModuleManagers()`/
  `manNotifyManagers()` e o cron usam
  `Core\Perms::usersWith('manutencao', '<perm>')` (OS →
  `service_orders.edit`, calibração → `calibration.edit`, estoque →
  `stock.edit`).
- **Login/registro/logout locais removidos** → telas do núcleo
  (`?m=auth&a=login|logout`). O CRUD de usuários saiu de `pages/admin.php`
  → administração central (`?m=admin&a=users`).
- **Configuração do módulo na Administração central**: setores e
  categorias de equipamentos ficam em `admin_panel.php` (abas `sectors` e
  `categories` de `?m=admin&a=module&slug=manutencao`; ações em
  `lib/admin_actions.php`). `?page=admin` e `?page=equipment&action=categories`
  só redirecionam (GET) ou processam POST legado. A aba "Hospital / Dados
  da unidade" foi descontinuada — o nome da unidade vem de
  `Core\Settings::get('org_name')` (`manOrgName()`).
- **Código de identificação único (12 dígitos, DV Luhn)** em
  `man_equipment.asset_code` (`lib/asset_code.php`), com código de barras
  Code 128 (`lib/barcode.php`) e QR Code (`lib/qrcode.php`) em PHP puro.
  Rotas: `equipment&action=lookup[&code=]` (busca/leitor → histórico),
  `action=history&id=` (linha do tempo consolidada + totais),
  `action=label&id=|ids=` (etiquetas 50×30, 70×40 ou A4). Equipamentos
  antigos recebem código **em lotes** (`man_asset_code_ensure_all($limite)`):
  `MAN_ASSET_CODE_BATCH` (200) por abertura da listagem e 5.000 por
  execução do cron, para que uma base grande não trave o request.
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
mapeamento de usuários por e-mail para a tabela global `users`. Os
papéis antigos são convertidos em grants de micropermissões a partir dos
`presets` do manifesto (`scripts/migrate_role_grants.php`).
