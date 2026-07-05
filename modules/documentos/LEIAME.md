# Sistema de Gestão Documental — v1.1

## Visão Geral

Sistema web modular para gestão de documentos e indicadores hospitalares,
desenvolvido em PHP 8+ com MySQL, otimizado para **hospedagem compartilhada
Hostinger**.

**Módulos:**
- **Documentos** — cadastro, upload, controle de validade, notificações e-mail + in-app
- **Indicadores de Enfermagem** — lançamento, gráficos, meta, exportação CSV
- **Multi-Hospital** — isolamento de dados, gestão de usuários/perfis
- **Segurança** — CSRF, bcrypt, rate limiting, recuperação de senha, auditoria

---

## Requisitos

| Requisito | Versão Mínima |
|-----------|---------------|
| PHP | 8.0+ |
| MySQL | 5.7+ / MariaDB 10.3+ |
| Extensões PHP | pdo, pdo_mysql, mbstring, fileinfo, openssl, json, session |
| Apache | mod_rewrite, mod_headers, mod_expires, mod_deflate |

---

## Instalação Nova na Hostinger

### 1. Banco de dados

1. **hPanel > Bancos de Dados > MySQL** — crie um banco
2. Abra o **phpMyAdmin**, selecione o banco
3. Importe `database/schema.sql`

### 2. Credenciais (.env)

1. Copie `.env.example` para `.env`
2. **IDEAL:** coloque o `.env` UMA PASTA ACIMA do `public_html/`
3. Edite com seus dados reais (DB, SMTP, URL)

```ini
APP_DEBUG=false
APP_URL=https://seudominio.com.br
DB_HOST=localhost
DB_NAME=u000000000_documentos
DB_USER=u000000000_documentos
DB_PASS=sua_senha_real
MAIL_HOST=smtp.hostinger.com
MAIL_USER=noreply@seudominio.com.br
MAIL_PASS=senha_do_email
```

### 3. Upload dos arquivos

Faça upload de **todo o conteúdo** para `public_html/`.

### 4. Permissões

Crie (se não existirem) e defina permissão **755**:
- `uploads/`
- `logs/`
- `cache/`

### 5. Primeiro acesso

- Acesse `https://seudominio.com.br`
- Credenciais padrão:
  - **E-mail:** `admin@hospital.com`
  - **Senha:** `admin123`
- **O sistema vai FORÇAR a troca de senha antes de qualquer outra ação.**

### 6. Cron Job

**hPanel > Avançado > Cron Jobs**:
- **Comando:** `/usr/bin/php -f /home/uXXXX/domains/SEUDOMINIO/public_html/cron/check_expiring.php`
- **Frequência:** diária (00:00)

O cron envia **e-mails** e cria notificações de vencimento, além de limpar
logs antigos e tokens expirados.

---

## Atualizando uma Instalação Antiga (v1.0 → v1.1)

1. Backup do banco e dos arquivos
2. Aplicar a migration:
   ```bash
   mysql -u usuario -p banco < database/migrations/002_security_and_improvements.sql
   ```
   Ou via phpMyAdmin: Importar > `002_security_and_improvements.sql`

3. Criar `.env` conforme `.env.example` (as configs saíram do `config.php`)
4. Substituir os arquivos do projeto pelos novos
5. Forçar troca da senha do admin padrão (a migration já marca)

---

## Perfis de Usuário

| Perfil | Código | Permissões |
|--------|--------|------------|
| Admin Global | 1 | Tudo: hospitais, usuários, documentos, indicadores, troca de contexto |
| Gestor | 2 | Usuários/docs/indicadores do próprio hospital |
| Operador | 3 | Visualiza e cadastra docs/indicadores do próprio hospital |

---

## Novidades da v1.1

### Segurança
- ✅ Credenciais em `.env` (fora do `public_html`)
- ✅ Rate limiting de login (5 tentativas / 15min)
- ✅ Força troca de senha no primeiro acesso
- ✅ Política de senha forte (8+ caracteres, letra+número)
- ✅ CSRF token preservado entre requisições (não quebra multi-aba)
- ✅ Detecção de HTTPS atrás de proxy (Cloudflare/Hostinger)
- ✅ Stream de download (não estoura memória em arquivos grandes)
- ✅ `sanitize()` agora não corrompe dados — escape é feito na saída
- ✅ `.htaccess` em `uploads/` bloqueia execução de scripts
- ✅ Headers de segurança reforçados (Permissions-Policy, etc.)

### Funcionalidades
- ✅ **Recuperação de senha** via e-mail
- ✅ **Página de perfil** + troca da própria senha
- ✅ **Envio de e-mails** SMTP (avisos de vencimento)
- ✅ **Exportação CSV** (documentos e indicadores)
- ✅ **Paginação** em todas as listas
- ✅ **Busca** em documentos, indicadores e usuários
- ✅ **Hospital switcher** para admin global no menu
- ✅ **Upsert** em lançamentos de indicadores (evita duplicatas)

### Qualidade / Performance
- ✅ Models separados dos controllers
- ✅ Cache em arquivo (hospitais ativos, etc.)
- ✅ Índices compostos no banco (dashboard mais rápido)
- ✅ OpCache configurado via `.user.ini`
- ✅ Cache de assets (30 dias) via `.htaccess`
- ✅ Migrations versionadas em `database/migrations/`
- ✅ CSS/JS movidos para `assets/` (não inline)
- ✅ Tratamento de erros padronizado (log em vez de silencioso)

---

## Estrutura

```
public_html/
├── index.php              # Entrypoint / router
├── .htaccess              # Rewrite + segurança + cache
├── .user.ini              # Configs PHP (Hostinger)
├── .env.example           # Template de ambiente
├── assets/                # CSS/JS da aplicação
├── includes/              # Infraestrutura (env, db, cache, mailer, etc.)
├── models/                # Camada de acesso a dados (por entidade)
├── controllers/           # Funções de cada rota
├── views/                 # Templates PHP
├── database/
│   ├── schema.sql         # Schema consolidado (instalação nova)
│   └── migrations/        # Migrações versionadas
├── cron/                  # Scripts de cron (check_expiring.php)
├── uploads/               # Arquivos enviados (isolados por hospital)
├── cache/                 # Cache em arquivo (gerado)
└── logs/                  # Logs da aplicação
```

---

## Troubleshooting

- **Erro "Erro interno. Tente novamente mais tarde."** — verifique `logs/php_errors.log` e `logs/db_errors.log`
- **E-mails não chegam** — verifique `MAIL_*` no `.env`, tente porta 587 com `MAIL_ENCRYPTION=tls`
- **Login retorna "Muitas tentativas"** — aguarde 15 min ou delete a linha em `login_attempts`
- **Upload falha** — confirme `upload_max_filesize` no `.user.ini` e permissões de `uploads/`
- **Admin esqueceu senha sem SMTP funcionando** — via phpMyAdmin:
  ```sql
  UPDATE users SET password='$2y$12$LJ3m4ys3Gzf0U5OUQqGxneFBjRCAJRo8Ahi/B1/4FpiWCiJfmGHKu',
                    force_password_change=1
  WHERE email='admin@hospital.com';
  ```
  (isso restaura para `admin123` e força troca)

---

## Tecnologias

- **Backend:** PHP 8+ procedural (sem Composer, sem frameworks)
- **Banco:** MySQL/MariaDB + PDO
- **Frontend:** Bootstrap 5.3, Font Awesome 6, Chart.js 4 (via CDN)
- **SMTP:** implementação própria leve (sem PHPMailer)
- **Cache:** arquivo local
- **Compatibilidade:** hospedagem compartilhada Hostinger
