# Plataforma Unificada

Unificação dos sistemas internos — **Documentos/Qualidade**,
**Comunicação (chat)**, **RH**, **Manutenção**, **Intranet** e
**Planejamento** — em uma única plataforma modular com:

- **Login único (SSO interno)** — uma conta, uma sessão, todos os módulos;
- **Integração com Moodle** — o usuário pode entrar com as credenciais do
  Moodle (auto-provisionamento) e acessa o Moodle pelo menu superior;
- **Permissões gerenciáveis por módulo** — cada usuário tem um nível de
  acesso independente em cada sistema (ex.: Gestor no RH, Visualizador na
  Manutenção, sem acesso ao Chat);
- **Layout padronizado** — menu superior fixo para alternar entre módulos e
  menu lateral que muda conforme o módulo ativo;
- **Notificações, auditoria e 2FA unificados**;
- **Administração central** com a configuração de todos os módulos
  (setores, categorias, departamentos, cargos, emojis do chat…), os
  **layouts de documentos** do hospital (papel timbrado, capa, fundo,
  fontes) e a **fila de e-mails**;
- **Atualizações de banco** controladas por migrações idempotentes
  (`sql/migrations/`), aplicáveis pela interface ou pela linha de comando.

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
`config/config.php`, ajuste, importe `sql/schema.sql` +
`sql/modules/*.sql` no banco (nesta ordem) e marque as migrações como
aplicadas: `php scripts/migrate.php --mark-all` (ou execute
`php scripts/migrate.php`, que é idempotente).

### Atualizando uma instalação existente

1. Envie os arquivos novos por cima dos antigos (mantenha `config/config.php`
   e `uploads/`).
2. Entre como administrador global: um aviso "Atualizações de banco
   pendentes" aparece no topo. Abra **Administração → Atualizações de
   banco** e clique em **Aplicar**, ou rode `php scripts/migrate.php`
   no servidor.
3. As migrações (`sql/migrations/00N_*.sql`) são idempotentes: podem ser
   reaplicadas sem risco (o executor tolera "já existe"). Nada é apagado
   automaticamente — tabelas descontinuadas ficam no banco e a remoção,
   quando desejada, está documentada em comentários no próprio arquivo.
4. Se alguma migração falhar, o executor **para no comando com erro e não
   marca o arquivo como aplicado**: corrija a causa e mande aplicar de novo
   — o que já rodou é pulado ("já existe").
5. **Fuso do banco**: a plataforma passa a alinhar o fuso da sessão do MySQL
   ao do PHP (`app.timezone`), para que as datas gravadas pelos módulos e
   pelo banco (`NOW()`) marquem a mesma hora. Se a base já tiver histórico
   gravado em outro fuso e você preferir não misturar, use
   `'timezone' => 'server'` no bloco `db` do `config/config.php`.

## Estrutura

```
index.php                  Front controller (?m=<módulo>&...)
install.php                Instalador (remover após instalar)
cron.php                   Cron unificado (chama o cron de cada módulo)
config/config.php          Configuração (gerada pelo instalador)
core/                      Núcleo: SSO, RBAC, Moodle, layout, admin
assets/core|<módulo>/      CSS/JS do padrão visual e de cada módulo
modules/<módulo>/          Código de cada sistema (documentos, chat, rh, manutencao, intranet, planejamento)
modules/<módulo>/admin_panel.php  Abas de configuração do módulo (renderizadas na Administração)
sql/schema.sql             Schema do núcleo
sql/modules/*.sql          Schema de cada módulo (tabelas prefixadas) — instalação limpa
sql/migrations/*.sql       Atualizações incrementais para instalações existentes
sql/legacy-migration/*.sql Scripts de migração dos bancos antigos
scripts/migrate.php        Aplica as migrações pela linha de comando
uploads/<módulo>/          Arquivos enviados (públicos, protegidos por .htaccess)
storage/uploads/           Arquivos privados (fora do webroot; servidos só via download autenticado)
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
| `?m=intranet&page=documents` | módulo Intranet |
| `?m=planejamento&page=boards` | módulo Planejamento |
| `?m=admin` | administração central (admins globais e quem configura algum módulo) |
| `?m=admin&a=module&slug=rh&tab=departments` | painel de configuração de um módulo |
| `?m=admin&a=layouts` | layouts de documentos (papel timbrado) |
| `?m=admin&a=migrations` | atualizações de banco |
| `?m=admin&a=mailqueue` | fila de e-mails |
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

## Administração central e configuração dos módulos

Cada módulo declara no manifesto (`modules/<slug>/module.php`, chave
`admin`) as abas de configuração que aparecem em **Administração →
Configuração dos módulos**, renderizadas pelo arquivo `admin_panel.php`
do módulo dentro do visual da administração. Quem tem a permissão de
alguma aba (ex.: `sectors.view` no módulo Documentos) enxerga o link
**Configurações do módulo** no fim do menu lateral do módulo e acessa a
administração central mesmo sem ser administrador global.

| Módulo | Abas na Administração |
|---|---|
| Documentos | Setores, Categorias |
| Comunicação | Categorias de canais, Emojis personalizados |
| RH | Departamentos, Cargos, Acessos dos funcionários, Aniversariantes (A4) |
| Manutenção | Setores, Categorias de equipamentos |

Também na Administração: **Padronização → Layouts de documentos** (papel
timbrado compartilhado por Documentos e Intranet), **Atualizações de
banco** e **Fila de e-mails** (comunicados, pesquisas e alertas são
enfileirados e enviados pelo cron).

## Layouts de documentos (papel timbrado)

Em **Administração → Layouts de documentos** cadastre os modelos do
hospital: tamanho de página (A4, A3, A5, Carta, Ofício), orientação,
margens, cabeçalho/rodapé em HTML com variáveis (`{{logo}}`, `{{org}}`,
`{{titulo}}`, `{{subtitulo}}`, `{{autor}}`, `{{data}}`, `{{versao}}`,
`{{codigo}}`, `{{setor}}`), logo, **imagem de fundo (PNG/JPG)** da
página, **layout de capa** (com fundo e conteúdo próprios), lista de
**fontes e tamanhos permitidos** e CSS extra. Um layout pode ser "de
página", "de capa" ou ambos. O editor de texto do sistema (Quill) só
oferece as fontes/tamanhos do layout escolhido e emula a largura útil
da folha; a impressão (`Imprimir / PDF`) usa CSS `@page` fiel ao
timbrado — use "Salvar como PDF" do navegador.

## Módulo Documentos (gestão documental / qualidade)

- **Seletor de setor** no topo de todas as telas ("Todos os setores" ou
  um setor específico), filtrando documentos, indicadores, planos de ação
  e dashboard.
- **Documentos controlados** (status, validade, revisão periódica,
  aprovação, ciência digital, alertas de vencimento) e **documentos não
  controlados** (apenas armazenados para consulta, sem validade nem
  workflow).
- Ao cadastrar, escolha **enviar um arquivo** ou **escrever no sistema**:
  editor completo com layout de página, capa opcional, fonte e tamanho
  do layout; cada alteração gera uma versão, e qualquer versão pode ser
  impressa/exportada em PDF.
- **Indicadores** em uma única tela (filtros, cartões total / na meta /
  fora da meta / dentro da tolerância, gráficos e tabela), modelos por
  categoria — inclusive **Financeiro** — e planos de ação (PDCA).
- **Conformidade** integrada ao dashboard.

## Módulo Comunicação (chat)

Chat interno por canais (públicos, privados e mensagens diretas) com
threads, reações, anexos, menções, mensagens fixadas, favoritos, busca e
presença. Funções extras do sistema antigo (tarefas, reuniões,
calendário, equipes, processos, enquetes, painel) foram descontinuadas;
categorias de canais e emojis personalizados são configurados na
Administração.

## Módulo RH

- **Login padrão do funcionário**: ao cadastrar um funcionário o sistema
  cria o usuário com **login = CPF** (aceito com ou sem pontuação) e
  **senha inicial = data de nascimento (ddmmaaaa)**, com troca obrigatória
  no primeiro acesso, vinculado ao funcionário e com o perfil
  "Funcionário". Na ficha do funcionário é possível criar/redefinir o
  acesso ou vincular um usuário existente; a aba **Acessos dos
  funcionários** (Administração) faz isso em lote.
- **Minha Área** (portal do funcionário): solicitar férias (a solicitação
  aparece como pendente em **Solicitações** para o RH aprovar/rejeitar),
  ler comunicados, responder pesquisas, trocar pontos por **brindes** e
  acompanhar solicitações.
- **Comunicados** com editor de texto, resumo, imagem de capa, anexo,
  departamento-alvo, exibição no portal e envio por e-mail.
- **Pesquisas** com vários tipos de pergunta (avaliação, escala,
  sim/não, escolha única, múltipla escolha, texto livre, número, data),
  obrigatoriedade, anonimato, público-alvo, portal e e-mail; resultados
  com estatísticas e taxa de participação.
- **Brindes**: o RH cadastra brindes com custo em pontos e estoque; o
  funcionário resgata pelo portal e o RH aprova/entrega.
- **Aniversariantes**: exportação em A4 (imprimir/PDF) com layout
  personalizável (Administração → RH → Aniversariantes).
- Departamentos e cargos são configurados na Administração.

## Módulo Manutenção

- Todo equipamento recebe um **código de identificação único de 12
  dígitos** (com dígito verificador) que gera **código de barras** e
  **QR code**; equipamentos antigos recebem o código automaticamente.
- **Etiquetas** (50×30 mm, 70×40 mm ou folha A4) para impressão,
  individuais ou em lote; **busca por código** (leitor USB ou digitação)
  e página de **histórico** do equipamento (OS, calibrações,
  preventivas, peças, custos, disponibilidade).
- Setores e categorias de equipamentos são configurados na
  Administração; a aba "Dados da unidade" foi descontinuada (o nome da
  organização vem das configurações do núcleo).

## Módulo Intranet (documentos institucionais)

Editor de texto com os layouts do hospital (página, capa, fontes),
versionamento das edições (visualizar/restaurar qualquer versão), cópia
pública opcional por link e exportação em PDF.

## Módulo Planejamento (planejamento e gestão de processos)

- **Planos de trabalho e planejamento organizacional**: objetivos → metas
  → ações no formato 5W2H, responsáveis, prazos, progresso, tabela e
  Gantt, impressão com o timbrado.
- **Quadros** Kanban e Scrum (colunas, limites WIP, cartões com
  responsável, prioridade, pontos, etiquetas, checklist e comentários,
  arrastar e soltar, burndown).
- **Fluxogramas, mapas de processo e diagramas** em um editor visual
  próprio (formas, conectores, raias, alinhamento, desfazer/refazer,
  exportação SVG/PNG, impressão) com versionamento.
- **Modelos** predefinidos e editáveis (Kanban, Scrum, tratamento de não
  conformidades, plano 5W2H, PDCA, planejamento estratégico, fluxograma,
  mapa de processo, SIPOC, SWOT, organograma).

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

Executa a fila de e-mails do núcleo e as rotinas de todos os módulos
(vencimentos de documentos, preventivas de manutenção e códigos de
equipamentos, aniversários/vencimentos do RH etc.). Use
`--module=<slug>` (ou `&module=`) para executar só um módulo.

## Segurança

- Senhas bcrypt (cost 12); bloqueio de força bruta; CSRF em todos os POSTs;
- 2FA TOTP opcional por usuário (Perfil → Senha e 2FA);
- Sessão única com regeneração periódica de ID e expiração por inatividade;
- Uploads bloqueados para execução; diretórios internos negados no Apache
  (`.htaccess` da raiz e de `storage/`). **Em Nginx** (ou Apache sem
  `AllowOverride`), negue explicitamente os diretórios internos:

  ```nginx
  location ~ ^/(core|config|sql|storage|docs)/ { deny all; }
  location ~ ^/uploads/.*\.(php|phar|phtml)$ { deny all; }
  ```

  Arquivos privados (anexos de comunicados, por exemplo) ficam em
  `storage/uploads/` e só são entregues pelo download autenticado do módulo;
- Auditoria unificada (Administração → Auditoria).
