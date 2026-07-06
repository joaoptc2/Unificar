# Plataforma Unificada

Unificação dos quatro sistemas internos — **Documentos/Qualidade**,
**Comunicação (chat)**, **RH** e **Manutenção** — em uma única plataforma
modular com:

- **Login único (SSO interno)** — uma conta, uma sessão, todos os módulos;
- **Integração com Moodle** — o usuário pode entrar com as credenciais do
  Moodle (auto-provisionamento) e acessa o Moodle pelo menu superior;
- **Permissões gerenciáveis por módulo** — cada usuário tem um nível de
  acesso independente em cada sistema (ex.: Gestor no RH, Visualizador na
  Manutenção, sem acesso ao Chat);
- **Layout padronizado** — menu superior fixo para alternar entre módulos e
  menu lateral que muda conforme o módulo ativo;
- **Notificações, auditoria e 2FA unificados**.

## Requisitos

- PHP 8.1+ (PDO MySQL, cURL recomendado)
- MySQL 5.7+/MariaDB 10.3+
- Apache com `mod_rewrite` (ou equivalente) — funciona em hospedagem
  compartilhada (Hostinger/cPanel)

## Instalação

1. Envie todos os arquivos para a raiz pública do site (`public_html`).
2. Crie um banco MySQL vazio.
3. Acesse `https://seu-dominio/install.php` e siga o assistente
   (ele cria as tabelas do núcleo e de todos os módulos, o administrador
   global e o arquivo `config/config.php`).
4. **Apague `install.php`** após concluir.
5. Entre com o administrador criado e libere os acessos em
   **Administração → Usuários e permissões**.

Instalação manual (alternativa): copie `config/config.example.php` para
`config/config.php`, ajuste, e importe `sql/schema.sql` +
`sql/modules/*.sql` no banco (nesta ordem).

## Estrutura

```
index.php                  Front controller (?m=<módulo>&...)
install.php                Instalador (remover após instalar)
cron.php                   Cron unificado (chama o cron de cada módulo)
config/config.php          Configuração (gerada pelo instalador)
core/                      Núcleo: SSO, RBAC, Moodle, layout, admin
assets/core|<módulo>/      CSS/JS do padrão visual e de cada módulo
modules/<módulo>/          Código de cada sistema (documentos, chat, rh, manutencao)
sql/schema.sql             Schema do núcleo
sql/modules/*.sql          Schema de cada módulo (tabelas prefixadas)
sql/legacy-migration/*.sql Scripts de migração dos bancos antigos
uploads/<módulo>/          Arquivos enviados
storage/logs|cache/        Logs e cache
```

### Como o roteamento funciona

`index.php?m=<módulo>` resolve autenticação e permissão e delega ao
módulo, que mantém seu roteamento interno:

| URL | Destino |
|---|---|
| `?m=documentos&url=documents` | módulo Documentos |
| `?m=chat&page=chat` | módulo Comunicação |
| `?m=rh&page=employees` | módulo RH |
| `?m=manutencao&page=service-orders` | módulo Manutenção |
| `?m=admin` | administração central (admins globais) |
| `?m=auth&a=login/profile/security` | login, perfil, senha/2FA |

## Permissões (micropermissões + grupos)

Cada função de cada módulo é uma **micropermissão** no formato
`recurso.ação` (ex.: `documents.edit`, `service_orders.create`), declarada
no manifesto do módulo (`modules/<slug>/module.php`, chave `permissions`).

Como gerenciar (em **Administração**):

- **Grupos de permissões** — crie um grupo (ex.: "Gestores de RH"), marque
  as permissões na árvore (módulo → recurso → Visualizar/Criar/Editar/
  Excluir/...) e adicione os membros: todos herdam as permissões do grupo.
- **Usuários → Permissões** — a mesma árvore por usuário, mostrando o que
  vem herdado dos grupos (ícone <i>grupo</i>). Marcar algo não herdado cria
  uma concessão individual; **desmarcar algo herdado cria uma exceção
  (negação) individual**, que prevalece sobre o grupo.
- **Modelos** — botões de atalho em cada módulo (ex.: "Gestor", "Membro")
  preenchem a árvore com o conjunto típico; ajuste depois caixa a caixa.

Regras de resolução: admin global tem tudo; senão, união dos grupos do
usuário, sobreposta pelas exceções individuais. Um usuário só vê no menu
superior os módulos em que possui **alguma** permissão; dentro do módulo,
menus, botões e ações são filtrados permissão a permissão.

## Integração com o Moodle

Edite o bloco `moodle` em `config/config.php`:

```php
'moodle' => [
    'enabled' => true,
    'url'     => 'https://moodle.suaescola.com.br',
    'service' => 'moodle_mobile_app',
    'admin_token' => '...',           // opcional (importa e-mail/nome)
    'default_access' => ['chat' => 'member'], // acessos automáticos
],
```

No Moodle, habilite os web services REST (Administração do site →
Plugins → Serviços web) — o serviço do app móvel (`moodle_mobile_app`),
ativo por padrão na maioria das instalações, é suficiente.

Fluxo: no login, se a senha local não conferir, a plataforma valida as
credenciais no Moodle (`login/token.php`); se válidas, cria/atualiza o
usuário local automaticamente e inicia a sessão. O menu superior ganha um
atalho para o Moodle.

## Migração dos dados dos sistemas antigos

Os scripts em `sql/legacy-migration/` migram os dados de cada banco antigo
para o banco unificado (tabelas prefixadas: `doc_`, `chat_`, `rh_`,
`man_`). Roteiro:

1. Importe cada banco antigo no mesmo servidor com os nomes
   `legado_documentos`, `legado_chat`, `legado_rh`, `legado_manutencao`.
2. Execute os scripts de `sql/legacy-migration/` **depois** da instalação.
3. Usuários são unificados **por e-mail**: quem existia em mais de um
   sistema vira uma conta única com os acessos correspondentes em cada
   módulo (os papéis antigos são gravados em `user_module_access`).
4. Converta os papéis antigos em micropermissões:
   `php scripts/migrate_role_grants.php` (use `--dry-run` para simular).
   Cada nível legado vira o conjunto de permissões do modelo homônimo do
   módulo; depois organize os usuários em grupos pela administração.
5. Senhas migradas continuam válidas (bcrypt preservado). Contas
   duplicadas com senhas diferentes mantêm a senha do primeiro sistema
   migrado — os demais acessos ficam sob o mesmo login.

## Cron

Agende (uma vez por hora, por exemplo):

```
php /caminho/para/cron.php            # CLI
https://seu-dominio/cron.php?token=<cron_secret do config>   # HTTP
```

Executa as rotinas de todos os módulos (vencimentos de documentos,
preventivas de manutenção, aniversários/vencimentos do RH etc.).

## Segurança

- Senhas bcrypt (cost 12); bloqueio de força bruta; CSRF em todos os POSTs;
- 2FA TOTP opcional por usuário (Perfil → Senha e 2FA);
- Sessão única com regeneração periódica de ID e expiração por inatividade;
- Uploads bloqueados para execução; diretórios internos negados no Apache;
- Auditoria unificada (Administração → Auditoria).
