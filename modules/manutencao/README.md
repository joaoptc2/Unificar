# ManuHosp - Sistema de Gestão de Manutenção Hospitalar

**Versão:** 3.0 Final  
**Compatibilidade:** PHP 7.4+ | MySQL 5.7+ | Hospedagem Compartilhada Hostinger  
**Tamanho:** ~100 KB (sem dependências externas)

---

## Instalação Rápida (5 minutos)

### 1. Preparar o Hostinger

No painel Hostinger, crie um banco de dados MySQL:
- **Banco de Dados → Gerenciamento → Criar Novo**
- Anote: nome do banco, usuário e senha

### 2. Fazer Upload

Descompacte o ZIP e faça upload de **todos os arquivos** para a pasta `public_html/` do Hostinger via Gerenciador de Arquivos ou FTP.

### 3. Executar o Instalador

Acesse no navegador:

```
https://seu-dominio.com.br/install.php
```

Siga os 3 passos:
1. **Banco de Dados** - Informe host, usuário, senha e nome do banco
2. **Criar Tabelas** - Clique para criar as 12 tabelas automaticamente
3. **Criar Admin** - Informe nome do hospital, seu nome, email e senha

### 4. Acessar o Sistema

```
https://seu-dominio.com.br/
```

Use o email e senha que você criou no passo 3.

### 5. Segurança Pós-Instalação

**IMPORTANTE:** Após instalar, delete o arquivo `install.php` pelo Gerenciador de Arquivos do Hostinger.

---

## Módulos do Sistema

### 1. Dashboard
- Resumo geral com KPIs
- Total de equipamentos, OS abertas, estoque baixo
- OS recentes e equipamentos críticos

### 2. Equipamentos
- Cadastro com código único e QR Code
- Filtro por nome, status e criticidade
- Editar e excluir equipamentos
- Gerenciar categorias de equipamento

### 3. Ordens de Serviço
- Criar OS (preventiva, corretiva, preditiva)
- Upload de foto e campo de observação
- Filtro por status, tipo e prioridade
- Alterar status (aberta → em andamento → concluída)
- Editar e cancelar OS
- **Link anônimo** para abertura de OS sem login

### 4. Estoque de Peças
- Cadastro de peças com código, fornecedor e localização
- Movimentações: entrada, saída e ajuste
- Histórico completo de movimentações
- Filtro por nome, status e estoque baixo
- Editar e excluir peças

### 5. Administração
- Editar dados do hospital
- Gerenciar setores (adicionar, editar, excluir)
- Gerenciar usuários com níveis de permissão:
  - **Admin** - Acesso total
  - **Técnico** - Equipamentos, OS, estoque
  - **Visualizador** - Apenas consulta

### 6. Manutenção Preventiva
- Planos de manutenção programados
- Frequências: diária, semanal, quinzenal, mensal, trimestral, semestral, anual
- Controle de próxima execução
- Marcar como executado com atualização automática
- **Geração automática de OS** via `cron.php` quando `next_date` vence

### 6a. Calibração (ANVISA RDC 02/2010)
- Histórico de calibrações por equipamento
- Data, próxima data, órgão/empresa e responsável técnico
- Resultado (conforme / não conforme / com ressalvas)
- Upload do certificado (PDF/JPG/PNG)
- Alertas automáticos 30, 15 dias antes e quando vencida
- **Exportação CSV e impressão / PDF** do histórico

### 6b. Limpeza Hospitalar
- Checklists por setor e tipo (concorrente / terminal / preparatória)
- Frequências: diária, semanal, quinzenal, mensal, sob demanda
- Registro de execução com % de conformidade calculado
- **Foto** e **assinatura digital** (canvas) do responsável
- Filtros por setor, tipo e período
- Exportação CSV e impressão

### 7. Equipe Técnica
- Cadastro de técnicos com especialidade e CREA
- Vincular técnico a usuário do sistema
- Editar e excluir técnicos

### 8. Indicadores (KPIs)
- Total de OS por tipo e status
- Taxa de conclusão
- Tempo médio de resolução
- Equipamentos mais problemáticos

### 9. Notificações
- Alertas de manutenção vencida
- Alertas de estoque baixo
- Marcar como lida

---

## Estrutura de Arquivos

```
public_html/
├── index.php              # Ponto de entrada e roteamento
├── config.php             # Configuração e funções auxiliares
├── install.php            # Instalador (deletar após uso)
├── database.sql           # Schema do banco (referência)
├── .htaccess              # Configuração Apache
├── README.md              # Esta documentação
│
├── pages/
│   ├── layout.php         # Template HTML compartilhado
│   ├── login.php          # Página de login
│   ├── register.php       # Registro de novo hospital
│   ├── logout.php         # Logout
│   ├── dashboard.php      # Dashboard principal
│   ├── equipment.php      # Gestão de equipamentos
│   ├── service_orders.php # Ordens de serviço
│   ├── anonymous_os.php   # OS anônima (acesso público)
│   ├── stock.php          # Gestão de estoque
│   ├── admin.php          # Administração
│   ├── maintenance.php    # Manutenção preventiva
│   ├── technicians.php    # Equipe técnica
│   ├── indicators.php     # Indicadores e KPIs
│   └── notifications.php  # Notificações
│
├── uploads/               # Fotos de OS (criado automaticamente)
└── logs/                  # Logs de auditoria (criado automaticamente)
```

---

## Banco de Dados (15 Tabelas)

| Tabela | Descrição |
|--------|-----------|
| hospitals | Dados do hospital |
| sectors | Setores do hospital |
| users | Usuários do sistema |
| equipment_categories | Categorias de equipamento |
| equipment | Equipamentos cadastrados |
| service_orders | Ordens de serviço |
| parts | Peças e materiais |
| stock_movements | Movimentações de estoque |
| technicians | Técnicos de manutenção |
| maintenance_plans | Planos de manutenção preventiva |
| equipment_calibrations | Histórico de calibrações (ANVISA RDC 02/2010) |
| cleaning_schedules | Checklists de limpeza hospitalar |
| cleaning_executions | Execuções de limpeza com foto e assinatura |
| notifications | Notificações do sistema |
| audit_log | Log de auditoria |

### Migrations para instalações existentes

Ao atualizar de versões anteriores, aplique os arquivos em
`migrations/*.sql` no banco existente (via phpMyAdmin). Eles são
idempotentes (usam `CREATE TABLE IF NOT EXISTS`).

## Cron (automação)

O arquivo `cron.php` unifica as tarefas periódicas:

- Gera ordens de serviço preventivas a partir de `maintenance_plans`
  vencidos, ajustando automaticamente a próxima data.
- Cria notificações de calibração (vencida / urgente ≤15d / a vencer ≤30d).
- Cria notificações de estoque abaixo do mínimo.
- Limpa notificações lidas com mais de 60 dias e reseta o rate-limit
  do login.

### Configurar o cron no Hostinger

No painel Hostinger: **Avançado → Cron Jobs → Adicionar novo** e
informe o comando diário (ex. 03:00):

```
/usr/bin/php -f /home/USUARIO/public_html/cron.php
```

Opcionalmente, também é possível disparar o cron por HTTP (com token):

```
curl "https://seu-dominio.com.br/cron.php?token=SUA_STRING_SECRETA"
```

Para habilitar o modo HTTP, defina `CRON_SECRET` em `db-config.php`.

### Notificações por email

Toda notificação gerada pelo cron (preventiva atrasada, calibração
vencida, estoque baixo) é enviada também por email para os usuários
com papel `admin` ou `manager`. Use a função `mail()` nativa do PHP —
no Hostinger, basta configurar em `db-config.php`:

```php
define('MAIL_FROM',      'sistema@seudominio.com.br');
define('MAIL_FROM_NAME', 'ManuHosp');
```

## Relatórios e exportação

Todas as listas principais permitem exportação:

- **CSV (UTF-8 com BOM)** — abre direto no Excel com acentuação.
- **Impressão / PDF** — gera página otimizada; usar Ctrl+P para salvar
  como PDF no navegador.

Endpoints diretos (exigem login):

```
/export.php?type=calibrations&format=csv
/export.php?type=calibrations&format=print&status=overdue
/export.php?type=service_orders&format=csv
/export.php?type=cleaning&format=csv
```

---

## Segurança

- Senhas criptografadas com **Bcrypt** (cost 12)
- Proteção **CSRF** em todos os formulários (inclusive login)
- **Prepared Statements** (PDO) contra SQL Injection
- Sanitização de entrada contra **XSS**
- Controle de acesso por **roles** (`admin`, `manager`, `technician`, `viewer`)
  - Escrita (`requireWrite()`) bloqueada para `viewer` em todos os módulos
- `session_regenerate_id(true)` após login (anti session fixation)
- Cookie de sessão com `HttpOnly`, `SameSite=Lax` e `Secure` (quando HTTPS)
- Rate-limit de login: 5 tentativas em 10 min → bloqueio de 15 min por IP
- Upload validado por **MIME real** (`finfo_file`) + whitelist de extensões
- Pasta `uploads/` com `.htaccess` que desliga o motor PHP
- Headers `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, CSP
- `db-config.php` fora do controle de versão (`.gitignore`)
- **Auditoria** completa (`audit_log`, incluindo tentativas falhas de login)
- Proteção de arquivos sensíveis via `.htaccess` (`.sql`, `.md`, `.log`, `.env`)

---

## Requisitos

- PHP 7.4 ou superior
- MySQL 5.7 ou superior (ou MariaDB 10.3+)
- Extensão PDO MySQL habilitada
- Hospedagem compartilhada com Apache (Hostinger recomendado)

---

## Suporte

Para dúvidas ou problemas, verifique:
1. Se o banco de dados foi criado corretamente
2. Se as credenciais em `db-config.php` estão corretas
3. Se a extensão PDO MySQL está habilitada no PHP
4. Se as pastas `uploads/` e `logs/` têm permissão de escrita
