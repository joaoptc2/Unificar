# Sistema de Gestao de RH Hospitalar

PHP 8.0+ puro (sem Composer) | MySQL 5.7+ | Bootstrap 5.3 | Hospedagem compartilhada

---

## Requisitos

- PHP 8.0+ com extensoes: `pdo_mysql`, `mbstring`, `json`, `fileinfo`
- Apache com `mod_rewrite` ou LiteSpeed
- MySQL 5.7+ ou MariaDB 10.3+
- Pastas `config/`, `public/`, `storage/` e `logs/` gravaveis (755)

## Instalacao (Hostinger / cPanel)

1. Upload de todos os arquivos para `public_html/`
2. Crie um banco MySQL no painel e anote host/nome/usuario/senha
3. Acesse `https://seudominio.com.br/install.php` no navegador
4. Passo 1: verificacao de ambiente (PHP, extensoes, permissoes)
5. Passo 2: dados do banco + conta admin + nome do hospital
6. Pos-instalacao: **exclua `install.php`** do servidor

O instalador cria 23 tabelas, 10 departamentos padrao, diretorios de upload e arquivos `.htaccess` de protecao.

## Criar admin manualmente (sem install.php)

```sql
-- Gere o hash: php -r 'echo password_hash("SuaSenha", PASSWORD_BCRYPT, ["cost"=>12]);'
INSERT INTO users (name, email, password, role, active)
VALUES ('Admin', 'admin@empresa.com', '$2y$12$HASH_AQUI', 'admin', 1);
```

## CRON Jobs

```bash
# Vencimentos (diario 07:00)
0 7 * * * php /home/usuario/public_html/cron/check_expirations.php

# Aniversarios (diario 08:00)
0 8 * * * php /home/usuario/public_html/cron/check_birthdays.php

# Limpeza (domingo 03:00)
0 3 * * 0 php /home/usuario/public_html/cron/cleanup.php
```

Os crons funcionam em CLI, CGI-FCGI e LiteSpeed (deteccao automatica). Opcionalmente aceitam token via URL (`cron_token` em `config/app.php`).

## E-mail SMTP

Edite `config/app.php`:

```php
'mail_enabled'    => true,
'mail_use_smtp'   => true,
'mail_host'       => 'smtp.hostinger.com',
'mail_port'       => 587,
'mail_encryption' => 'tls',
'mail_username'   => 'rh@seudominio.com',
'mail_password'   => '***',
```

Cliente SMTP em PHP puro (`app/helpers/SmtpClient.php`), sem Composer.

## Migracoes (para instalacoes existentes)

```sql
SOURCE sql/migrations/001_p0_security.sql;
SOURCE sql/migrations/002_password_resets.sql;
SOURCE sql/migrations/003_lgpd.sql;
SOURCE sql/migrations/004_2fa.sql;
SOURCE sql/migrations/005_employee_enhancements.sql;
SOURCE sql/migrations/006_schedules_from_expirations.sql;
```

---

## Estrutura de Diretorios

```
/
├── index.php              Bootstrap (define BASE_PATH, inclui public/index.php)
├── install.php            Assistente de instalacao (excluir apos usar)
├── .htaccess              Rewrite + bloqueio de pastas internas
│
├── app/
│   ├── controllers/       25 controllers (1 classe = 1 arquivo)
│   ├── helpers/           17 classes utilitarias estaticas
│   ├── models/            6 models (herdam de Model.php)
│   └── views/             16 subpastas de modulo + layout/
│       └── layout/
│           ├── header.php   Navbar + sidebar + CSS dinamico + flash messages
│           └── footer.php   Modal busca global + scripts JS
│
├── config/
│   ├── app.php            Configuracao geral (editavel)
│   ├── database.php       Credenciais MySQL (gerado pelo install)
│   └── .installed         Marker de instalacao concluida
│
├── cron/
│   ├── bootstrap.php      Validacao CLI/token + autoloader
│   ├── check_expirations.php
│   ├── check_birthdays.php
│   └── cleanup.php        Limpeza de dados, arquivos orfaos e cache
│
├── public/
│   ├── index.php          FRONT CONTROLLER (router principal)
│   ├── css/style.css      CSS custom do sistema
│   ├── js/app.js          Mascaras, preview, confirmacao
│   ├── js/global-search.js  Busca global (Ctrl+K)
│   └── uploads/           Apenas fotos (publico)
│
├── storage/               Fora do webroot (Require all denied)
│   ├── uploads/           Documentos, CVs, atestados (privado)
│   └── cache/             FileCache
│
├── sql/
│   ├── schema.sql         Schema completo (23 tabelas)
│   └── migrations/        6 migracoes incrementais
│
├── logs/                  Error logs (bloqueado via .htaccess)
└── boilerplate/           Kit reutilizavel para novos sistemas
```

---

## Ciclo de Vida de uma Requisicao

```
Browser → Apache .htaccess → index.php (raiz)
  → define BASE_PATH / BASE_URL
  → require public/index.php

public/index.php:
  1. Carrega config/app.php
  2. Registra Autoloader (app/{helpers,models,controllers}/)
  3. Session::start()
  4. Extrai $page e $action da query string (?page=X&action=Y)
  5. Checa se pagina e publica; senao Auth::requireLogin()
  6. Se role=funcionario, restringe rotas permitidas
  7. Lookup $routes[$page] → nome do Controller
  8. Autoloader carrega a classe → new Controller() → $action()
  9. Controller faz: Auth::requirePermission() → Csrf::check()
     → queries no banco → View::render('modulo/template', $data)
```


## Modulos do Sistema

| Modulo | Rota (?page=) | Controller | Descricao |
|--------|---------------|------------|-----------|
| Dashboard | dashboard | DashboardController | KPIs, graficos Chart.js, cache 5min |
| Funcionarios | employees | EmployeeController | CRUD + ficha funcional + print/PDF |
| Documentos | documents | DocumentController | Upload RG/CPF/contratos/treinamento/EPI |
| Atestados | certificates | CertificateController | Cadastro + afasta funcionario automaticamente |
| Vencimentos | expirations | ExpirationController | ASO/conselho/treinamento + espelho na agenda |
| Agenda | schedules | ScheduleController | FullCalendar v6, endpoint JSON |
| Aniversariantes | birthdays | BirthdayController | Listagem por mes/departamento |
| Recrutamento | recruitment | RecruitmentController | Vagas + Kanban SortableJS drag-drop |
| Recrutamento Publico | public_recruitment | PublicRecruitmentController | Formulario + acompanhamento |
| Banco de Talentos | talent_pool | TalentPoolController | Candidatos reprovados/avulsos |
| Notificacoes | notifications | NotificationController | Alertas in-app + badge no header |
| Usuarios | users | UserController | CRUD + log de auditoria |
| Departamentos | departments | DepartmentController | CRUD |
| Cargos | positions | PositionController | CRUD |
| Pontuacao | scores | ScoreController | Lancamento de pontos por funcionario |
| Elogios | compliments | ComplimentController | Registro de elogios recebidos |
| Perfil | profile | ProfileController | Alterar nome/email/senha proprio |
| 2FA | two_factor | TwoFactorController | TOTP setup/challenge/verify |
| Busca Global | search | SearchController | JSON multimodulo (Ctrl+K) |
| Esqueci Senha | password_reset | PasswordResetController | Token por email, 1h validade |
| Privacidade | privacy | PrivacyController | Politica LGPD + exclusao de candidato |
| Personalizacao | settings | SettingsController | Cores, fonte, logo, badges (admin) |
| Portal Funcionario | my | MyController | Pontos, elogios, vencimentos proprios |
| Download | files | DownloadController | Serve arquivos privados com auth |

## Perfis e Permissoes (RBAC)

Definidos em `app/helpers/Auth.php` no array `$permissions`:

| Role | Acesso |
|------|--------|
| `admin` | Tudo (wildcard `*`) |
| `rh` | Funcionarios CRUD, documentos, vencimentos, recrutamento, agenda, pontos, elogios |
| `gestor` | Visualizacao + agenda (criar/editar) |
| `visualizador` | Dashboard + funcionarios (view) + aniversariantes |
| `funcionario` | Apenas portal "Minha Area" (?page=my) + perfil + logout |

Verificacao no controller: `Auth::requirePermission('modulo', 'acao');`
Verificacao na view: `<?php if (Auth::can('modulo', 'acao')): ?>`

## Tabelas do Banco (23)

**Entidades principais:**
- `departments` — setores
- `job_positions` — cargos (FK departments)
- `users` — usuarios do sistema (5 roles + employee_id FK)
- `employees` — funcionarios (dados pessoais, contratuais, ASO, conselho)

**Documentos e historico:**
- `employee_documents` — uploads (doc_type: RG/CPF/Contrato/Certificado/Atestado/Treinamento/EPI/Outro)
- `employee_records` — timeline de acoes (admissao, promocao, afastamento...)
- `medical_certificates` — atestados medicos (CID, CRM, dias)

**Vencimentos:**
- `expirations` — ASO, conselho, treinamento, certificacao
- `expiration_history` — historico de renovacoes

**Agenda:**
- `schedules` — compromissos (com expiration_id FK para espelhamento)

**Recrutamento:**
- `recruitment_jobs` — vagas
- `recruitment_steps` — etapas Kanban
- `candidates` — candidatos (com lgpd_consent_at/ip)
- `candidate_progress` — avaliacao por etapa

**Pontuacao e elogios:**
- `employee_scores` — pontos (+/-) com motivo e categoria
- `employee_compliments` — elogios com origem

**Infraestrutura:**
- `notifications` — alertas in-app
- `audit_log` — log de todas as acoes
- `settings` — chave-valor (config visual + geral)
- `login_attempts` — rate-limit de login
- `public_submissions` — rate-limit formulario publico
- `password_resets` — tokens de reset de senha
- `user_2fa` — segredos TOTP + recovery codes


---

## Helpers Disponiveis (API Rapida)

### Autenticacao (Auth.php)
```
Auth::attempt($email, $password)           → 'success' | '2fa' | 'failed'
Auth::requireLogin()                       → redireciona se nao logado
Auth::requirePermission($module, $action)  → 403 se sem permissao
Auth::can($module, $action)                → bool
Auth::isAdmin()                            → bool
Auth::logout()
Auth::isLoginLocked($email)                → int (segundos, 0=livre)
```

### Sessao (Session.php)
```
Session::set($key, $value)
Session::get($key, $default)
Session::flash('success', 'Msg')      → armazena para proximo request
Session::flash('success')             → le e apaga
Session::isLoggedIn() / userId() / userRole() / userName()
```

### CSRF (Csrf.php)
```
Csrf::field()    → <input hidden> com token (usar em todo form POST)
Csrf::check()    → aborta 403 se invalido
Csrf::token()    → string pura
```

### Sanitizacao (Sanitize.php)
```
Sanitize::e($str)              → htmlspecialchars (XSS) — USAR EM TODA SAIDA
Sanitize::string($str)         → trim + normaliza quebras
Sanitize::email($str)          → valida e normaliza ('' se invalido)
Sanitize::int($val)            → cast seguro
Sanitize::cpf($str)            → so digitos
Sanitize::isValidCpf($str)     → bool
Sanitize::formatCpf($str)      → 000.000.000-00
Sanitize::date($str)           → 'Y-m-d' ou null
Sanitize::formatDate($str)     → 'dd/mm/aaaa'
Sanitize::formatDateTime($str) → 'dd/mm/aaaa HH:MM'
Sanitize::post($key, $default) → string do $_POST sanitizada
Sanitize::get($key, $default)  → string do $_GET sanitizada
```

### Banco (Database.php)
```
Database::getInstance() → PDO singleton (FETCH_ASSOC, ERRMODE_EXCEPTION)
```

### Model Base (Model.php)
```
Model::find($id)                 → ?array
Model::all($options)             → array (where, params, order, limit, offset)
Model::count($where, $params)    → int
Model::insert($data)             → int (lastInsertId)
Model::update($id, $data)        → int (rowCount)
Model::delete($id)               → int
```
Subclasses definem `$table` e `$fillable` (whitelist de campos).

### Upload (Upload.php)
```
Upload::handle($field, $subDir)  → ['success'=>bool, 'path'=>'...', ...]
  subDir 'employees'/'photos'    → public/uploads/ (URL direta)
  subDir 'documents'/'resumes'/  → storage/uploads/ (privado)
         'certificates'
Upload::delete($path)
Upload::url($path, $type, $id)   → URL direta ou via DownloadController
Upload::isPrivatePath($path)     → bool
```

### View (View.php)
```
View::render('modulo/template', $data)    → com header+footer
View::renderRaw('modulo/template', $data) → sem layout
View::capture('modulo/template', $data)   → retorna string
```

### Cache (FileCache.php)
```
FileCache::remember($key, $ttl, fn() => ...) → pega ou calcula
FileCache::get($key) / put($key, $val, $ttl) / forget($key)
```

### Outros
```
AuditLog::log($action, $table, $recordId, $oldData, $newData)
Pagination::__construct($total, $page, $perPage) → render()
Export::csv($filename, $headers, $rows) → download + exit
Mailer::send($to, $subject, $html) → bool
RateLimit::clientIp() / recordLogin() / recentLoginFailures()
Lgpd::anonymizeEmployee($id) / deleteCandidate($id)
Totp::generateSecret() / verify($secret, $code) / uri(...)
```

---

## Como Criar um Novo Modulo

### 1. Tabela
Adicione em `sql/schema.sql` (novas instalacoes) e crie `sql/migrations/007_xxx.sql` (existentes):
```sql
CREATE TABLE IF NOT EXISTS `meu_modulo` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `status` ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_meu_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2. Model (`app/models/MeuModulo.php`)
```php
<?php
class MeuModulo extends Model
{
    protected static string $table = 'meu_modulo';
    protected static array  $fillable = ['title', 'status', 'created_by'];
}
```

### 3. Controller (`app/controllers/MeuModuloController.php`)
```php
<?php
class MeuModuloController
{
    public function index(): void
    {
        Auth::requirePermission('meu_modulo', 'view');
        $items = MeuModulo::all(['order' => 'created_at DESC']);
        View::render('meu_modulo/index', [
            'pageTitle' => 'Meu Modulo', 'page' => 'meu_modulo', 'items' => $items,
        ]);
    }

    public function store(): void
    {
        Auth::requirePermission('meu_modulo', 'create');
        Csrf::check();
        $id = MeuModulo::insert([
            'title' => Sanitize::post('title'),
            'created_by' => Session::userId(),
        ]);
        AuditLog::log('create', 'meu_modulo', $id);
        Session::flash('success', 'Cadastrado.');
        header('Location: index.php?page=meu_modulo'); exit;
    }
    // ... create(), edit(), update(), delete() seguem o mesmo padrao.
}
```

### 4. Rota (`public/index.php`)
```php
$routes['meu_modulo'] = 'MeuModuloController';
```

### 5. Permissoes (`app/helpers/Auth.php`)
```php
'rh' => [
    ...
    'meu_modulo' => ['view', 'create', 'edit', 'delete'],
],
```

### 6. Views (`app/views/meu_modulo/`)
Criar `index.php` e `form.php`. Esqueleto da listagem:
```php
<div class="page-header">
    <h1><i class="bi bi-ICONE me-2"></i>Titulo</h1>
    <div class="d-flex gap-2">
        <?php if (Auth::can('meu_modulo', 'create')): ?>
            <a href="index.php?page=meu_modulo&action=create" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i> Novo
            </a>
        <?php endif; ?>
    </div>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Titulo</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= Sanitize::e($item['title']) ?></td>
                    <td class="text-end">
                        <a href="index.php?page=meu_modulo&action=edit&id=<?= $item['id'] ?>"
                           class="btn btn-outline-warning btn-action"><i class="bi bi-pencil"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
```

### 7. Sidebar (`app/views/layout/header.php`)
```php
<?php if (Auth::can('meu_modulo', 'view')): ?>
<li class="nav-item">
    <a class="nav-link <?= ($page ?? '') === 'meu_modulo' ? 'active' : '' ?>"
       href="index.php?page=meu_modulo">
        <i class="bi bi-ICONE me-2"></i> Meu Modulo
    </a>
</li>
<?php endif; ?>
```

---

## Seguranca

| Camada | Implementacao |
|--------|---------------|
| SQL Injection | PDO prepared statements exclusivo |
| XSS | `Sanitize::e()` em toda saida HTML |
| CSRF | `Csrf::field()` + `Csrf::check()` em todo POST |
| Senhas | bcrypt cost 12 |
| Rate-limit login | Tabela `login_attempts`, bloqueio apos N falhas |
| 2FA | TOTP RFC 6238 + 8 recovery codes |
| Uploads privados | `storage/uploads/` fora do webroot, servido via `DownloadController` |
| Uploads publicos | `.htaccess` bloqueia execucao PHP em `public/uploads/` |
| Diretorios | `.htaccess` deny em `app/`, `config/`, `cron/`, `sql/`, `storage/`, `logs/` |
| Sessao | HttpOnly, SameSite=Lax, regeneracao a cada 30min |
| Anti-bot | Honeypot + tempo minimo + rate-limit no formulario publico |
| Auditoria | Toda acao (login/falha/CRUD/download/reset/2FA) em `audit_log` |
| LGPD | Consentimento, anonimizacao, exclusao, politica publica |

## Personalizacao Visual

Administracao > Personalizacao (`?page=settings`) permite alterar via interface:
- Nome do sistema e icone do logo (Bootstrap Icons)
- Cores: primaria, navbar, fundo de pagina, sidebar (fundo/texto/hover)
- Badges de status (ativo/alerta/perigo)
- Gradiente da tela de login
- Familia de fontes (6 opcoes)
- Raio de arredondamento dos cards

Valores salvos na tabela `settings`, cacheados 10min via FileCache. O `header.php` gera um bloco `<style>` inline sobrescrevendo as variaveis CSS.

## Boilerplate para Novos Sistemas

A pasta `boilerplate/` contem um kit completo (43 arquivos) para iniciar um novo projeto com a mesma infraestrutura:
- 15 helpers + Autoloader + Model base
- Layout responsivo (sidebar + navbar)
- Login com rate-limiting + CSRF + auditoria
- Modulo de exemplo com CRUD completo
- CSS + JS identicos

Copie `boilerplate/` para o `public_html/` do novo dominio, edite `config/database.php`, importe `sql/schema.sql` e insira um admin.

---

## Checklist para Alteracoes

```
[ ] Tabela no banco (schema.sql + migration avulsa)
[ ] Model em app/models/ (herda de Model, define $table e $fillable)
[ ] Controller em app/controllers/ (sufixo "Controller")
[ ] Rota em public/index.php → $routes
[ ] Permissoes em Auth.php → $permissions por role
[ ] Views em app/views/<modulo>/ (index + form no minimo)
[ ] Link na sidebar em header.php (com Auth::can)
[ ] Csrf::field() em todo form POST
[ ] Sanitize::e() em toda saida HTML
[ ] AuditLog::log() em create/update/delete
[ ] Validacao server-side antes de insert/update
[ ] Flash messages (Session::flash)
[ ] Paginacao se listagem grande
[ ] data-confirm em botoes de exclusao
[ ] FileCache::forget() se afeta dashboard
[ ] Upload::handle() + Upload::url() se houver arquivos
[ ] php -l em todos os arquivos alterados
```
