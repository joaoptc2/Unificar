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

A árvore abaixo é a arrumação de origem, com tudo junto. `config/`, a pasta de
dados e o CÓDIGO podem sair do `public_html` — cada um por vez, sem obrigação
e sem pressa; ver *Tirar config e dados do public_html* e *Tirar o CÓDIGO do
public_html*.

```
index.php                  Front controller (?m=<módulo>&...)
localizar.php              Acha o código (só faz diferença quando ele sai do público)
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

Também na Administração: **Aparência** (identidade visual — cores,
logotipo, favicon, nome; ver a seção abaixo), **E-mail** (configuração do
SMTP, teste de entrega, fila e diagnóstico), **Backup** (cópias, agendamento
e restauração), **Padronização → Layouts de documentos** (papel timbrado
compartilhado por Documentos e Intranet), **Atualizações de banco** (comunicados, pesquisas e alertas são
enfileirados e enviados pelo cron).

## E-mail (Administração → E-mail)

Uma tela só para o e-mail, com sete abas:

- **Configuração** — servidor SMTP, porta, segurança (STARTTLS, SSL/TLS ou
  sem criptografia), usuário e senha, endereço e nome do remetente,
  "responder para", nome na apresentação (EHLO) e tempo limite. O que é
  gravado aqui **sobrepõe** o bloco `mail` de `config/config.php`, e cada
  campo mostra de onde vem o valor em uso (tela, arquivo ou padrão). A senha
  fica cifrada no banco (AES-256-GCM com a `app.key`). Para travar tudo no
  arquivo, acrescente `'lock' => true` ao bloco `mail`.
- **Layout** — a casca de TODOS os e-mails do portal: cabeçalho com logo e
  cor, corpo, rodapé com assinatura e aviso, mais fonte, largura e cantos.
  A pré-visualização ao lado acompanha cada ajuste. A casca é aplicada no
  momento do envio, num ponto só, então vale inclusive para o que já está na
  fila — e pode ser desligada para voltar ao comportamento anterior (cada
  módulo com o seu HTML). O HTML gerado usa tabelas e estilo embutido, que é
  o que Outlook e Gmail renderizam de forma previsível.
- **Teste de entrega** — envia uma mensagem de teste, com assunto e corpo
  fixos escritos pelo próprio sistema, por um destes caminhos: direto pelo
  SMTP, pela função `mail()` do PHP ou **pela fila** (este último é o que
  prova que o cron está agendado na hospedagem). Limite de 12 testes por
  hora, para o botão não virar ferramenta de disparo.
- **Recebimento** — abre a caixa do portal por **IMAP ou POP3** e lista o
  que chegou. Com "ciclo completo" ela envia uma mensagem com um código no
  assunto e procura por ele na caixa, respondendo a pergunta inteira: saiu e
  chegou. Não depende da extensão `imap` do PHP (ausente na maioria das
  hospedagens) — fala o protocolo direto, como o envio faz com SMTP. A senha
  da caixa fica cifrada, como a do SMTP.
Toda mensagem sai como **multipart/alternative**: HTML e texto puro na mesma
mensagem. Isso pesa a favor nos filtros de spam e atende quem lê em texto —
por preferência, por leitor de tela ou por um cliente antigo. A conversão
preserva o endereço dos links ("texto (endereço)").

- **Fila** — pendentes, retidas, falhas e enviadas, com o motivo de cada
  falha e a via usada (SMTP ou `mail()`). É a antiga tela "Fila de e-mails";
  a rota `?m=admin&a=mailqueue` continua funcionando e cai aqui.
- **Diagnóstico** — o que dá para verificar sem sair do servidor (OpenSSL,
  sockets, `mail()`, tempo de execução, alinhamento entre o remetente e o
  servidor de saída, saúde da fila e do cron) e, sob clique, o que precisa de
  rede: **SPF, DKIM e DMARC** do domínio do remetente e **quais portas de
  saída** a hospedagem libera (25, 465, 587, 2525). A tela também diz, com
  todas as letras, o que **não** é possível saber daqui — como se a mensagem
  caiu na caixa de entrada ou no spam do destinatário.
- **Histórico de testes** — cada teste com o tempo de cada etapa (DNS,
  conexão, saudação, EHLO, TLS, autenticação, envelope, mensagem) e a
  **conversa completa com o servidor**, com usuário e senha mascarados.
  Os registros são apagados depois de 90 dias.

Quando um teste falha, a tela mostra o código do erro e o que fazer: porta
bloqueada pela hospedagem, certificado inválido, senha de aplicativo exigida
pelo provedor, remetente fora do domínio da conta, relay negado, e assim por
diante.

**Fila confiável.** Duas correções sentidas em produção: o processador agora
*reserva* as linhas antes de enviar (duas execuções sobrepostas — o cron e o
botão "Processar agora" — não mandam mais a mesma mensagem duas vezes), e uma
falha de **configuração** (envio desligado, sem servidor, senha recusada) não
gasta tentativa: a mensagem fica *retida* e sai sozinha assim que a
configuração for corrigida, em vez de virar "falha" permanente.

## Backup e restauração (Administração → Backup)

Não existia backup no sistema. Agora existe, em PHP puro — sem depender de
`mysqldump` nem de acesso a linha de comando, porque em hospedagem
compartilhada nenhum dos dois é garantido.

**O que entra no pacote** (um `.tar.gz` com manifesto):

- estrutura e dados de todas as tabelas, mais views, gatilhos, rotinas e
  eventos, se houver;
- os arquivos enviados (`uploads/` e `storage/uploads/`), quando marcado —
  inclusive os `.htaccess` ocultos que protegem as pastas;
- um **manifesto**: data, versão, quem gerou, PHP e MariaDB, migrações
  aplicadas e pendentes, e — por tabela — número de linhas e uma **soma de
  verificação do conteúdo**. É com ela que se prova, depois, que a
  restauração devolveu o mesmo dado.

`config/config.php` **não** entra por padrão: ele guarda a senha do banco e a
chave do aplicativo. Guarde-o à parte.

**Onde fica.** Fora da pasta pública sempre que possível (o diretório irmão da
instalação); se não der, em `storage/backups/` com `.htaccess` negando acesso
e nome de arquivo com 32 caracteres aleatórios. A tela diz qual dos dois está
em uso.

**Tela** (`Administração → Backup`): gerar agora, listar, conferir a
integridade de um pacote, baixar (com registro de quem baixou na auditoria) e
excluir. Mais o **agendamento** — hora, o que incluir e quanto guardar
(dias recentes, semanas, meses e um teto em MB) — que roda dentro da rotina
periódica (`cron.php`): o backup sai na primeira execução depois da hora
marcada, desde que o último tenha mais de 20 horas, para um cron atrasado não
deixar o dia sem cópia.

**Restaurar** é pela linha de comando, de propósito: a operação apaga e recria
as tabelas, não tem volta, e no dia em que ela é necessária — banco perdido —
a tela do sistema nem abre.

```bash
php scripts/restore.php --list
php scripts/restore.php --inspect=<id>              # confere; não toca no banco
php scripts/restore.php --restore=<id> --target=portal_teste   # ensaio
php scripts/restore.php --restore=<id> --target=producao       # de verdade
php scripts/backup_verify.php --b=portal_teste      # compara tabela a tabela
```

Antes de restaurar em produção o sistema gera **um backup de segurança
automático**, e depois cuida do que o dump traz de volta e faria estrago:
cancela os e-mails que ficaram pendentes (senão o cron reenviaria ao corpo
clínico uma leva já entregue), limpa tokens de redefinição de senha e
tentativas de login, apaga os caches em disco e derruba as sessões abertas
antes da restauração. O relatório final diz quantos de cada.

No **modo de teste** (restaurar em outro banco) as credenciais da cópia são
neutralizadas por padrão — senhas, segundo fator e envio de e-mail — para uma
base de ensaio não virar um segundo cofre com os dados de todo o hospital.

**Um backup que ninguém testou é uma promessa, não uma cópia.** O roteiro
acima — restaurar num banco separado e rodar o `backup_verify.php` — é o que
transforma uma coisa na outra, e leva menos de um minuto.

## Identidade visual (Administração → Aparência)

Tudo o que dá cara ao sistema fica em **Administração → Aparência**
(somente administradores globais). O que é escolhido lá vale para o
núcleo, para os seis módulos e para a tela de login, sem editar CSS:

- **Identidade** — nome da organização (aparece no título das páginas,
  nos e-mails e nos documentos), nome curto do topo, mensagem da tela de
  login, **logotipo**, **logotipo para fundo escuro**, **favicon** e
  **imagem de fundo do login**.
- **Cores** — cor principal, cor de destaque, fundo das páginas, fundo e
  texto do menu lateral, estilo do topo (degradê, sólido, escuro, claro)
  e cor própria do topo.
- **Cores de estado** — sucesso, alerta, erro e informação. São as cores de
  significado, usadas em mais de oitocentos lugares nos módulos (selos de
  "conforme" e "vencido", alertas, barras de progresso, colunas de situação).
  Os valores de fábrica são os do Bootstrap, então atualizar não muda nada em
  quem nunca abriu a tela.
- **Tema claro e escuro** — "sempre claro", "sempre escuro" ou **seguir o
  aparelho de cada pessoa** (plantão noturno com tela escura, expediente com
  tela clara). Opcionalmente cada pessoa alterna pelo menu do usuário, e a
  escolha fica no navegador dela. As cores do tema escuro (fundo, menu e a
  cor da marca) são configuráveis; deixando a cor da marca em branco, o
  sistema a clareia só o quanto for preciso para continuar legível sobre o
  fundo escuro.
- **Tipografia e formas** — família tipográfica, **tamanho da letra** (13 a
  20 px, e como todo o CSS usa `rem` isso escala o sistema inteiro),
  densidade, sombras, raio dos cantos, largura do menu e altura do topo.
- **Menu lateral** — sempre aberto, aberto com botão para recolher em ícones,
  ou só ícones abrindo ao passar o mouse. Em telas de 1366 px — posto de
  enfermagem, recepção — isso devolve espaço útil para as tabelas. No celular
  o menu continua deslizando pela lateral.
- **Tela de entrada** — cartão centralizado ou **imagem de um lado e
  formulário do outro**, largura do cartão e um rodapé institucional (aviso
  de uso restrito, LGPD, ramal do suporte).
- **CSS do administrador** — a válvula de escape para o ajuste que nenhum
  campo cobre. É servido como folha de estilo própria (não embutido na
  página), e passa por um filtro que remove `@import`, `expression()`,
  `javascript:` e endereços externos.
- **Exportar e importar tema** — leva a identidade de homologação para
  produção sem redigitar, e serve de cópia das escolhas. As imagens continuam
  por upload.
- **Temas prontos** — Azul institucional, Verde saúde, Teal moderno,
  Índigo, Bordô, Grafite (escuro) e Alto contraste. Aplicar um tema
  preenche o formulário; o botão **Restaurar padrão** volta tudo ao
  original (com a opção de manter as imagens enviadas).

A pré-visualização ao lado do formulário é a **página real** — montada pelo
servidor com as mesmas regras do sistema e com os valores que estão no
formulário —, com topo, menu, tabela, formulário, os quatro alertas, selos de
estado, botões e gráfico, e um botão para ver o mesmo no tema escuro. Nada é
salvo até clicar em *Salvar aparência*. Se uma combinação de cores ficar
ilegível (texto do menu quase sumindo no fundo escolhido), a tela avisa antes,
com a razão de contraste medida.

Ainda na **Administração → Módulos**: cada módulo pode receber o nome e o
ícone que o hospital usa ("Manutenção" vira "Engenharia Clínica") e pode sair
da barra superior sem perder o acesso — continua na tela inicial e por link
direto.

No celular, a barra do navegador recebe a cor da marca e o portal pode ser
instalado na tela inicial com o nome e o ícone do hospital (manifesto gerado
pelo PHP, em `?m=auth&a=manifest`).

**Como funciona por dentro.** `Core\Branding` guarda as escolhas em
`settings` (prefixo `brand.`) e publica um bloco `<style>` com as
variáveis `--portal-*` (e as `--bs-*` correspondentes) em todas as
páginas. As folhas do núcleo e dos módulos leem essas variáveis, então
uma cor nova alcança botões, abas, links, paginação, tabelas e
formulários de uma vez. As cores derivadas — tons claro/escuro, fundo
suave e **cor do texto sobre cada fundo** — são calculadas no servidor
pela razão de contraste da WCAG, de modo que a leitura continua legível
mesmo com cores claras (âmbar, amarelo) ou com um tema escuro. Ao
imprimir, o papel volta a ser branco com texto preto.

As imagens ficam em `uploads/branding/` (execução bloqueada por
`.htaccess`); SVG enviado é sanitizado (script, `on*`, referências
externas e afins são removidos) e o favicon aceita também `.ico`.

## Como a personalização é organizada

Toda superfície do portal — tela, e-mail e papel — sai da mesma base:

- **`Core\Tokens`** é a fundação: uma implementação de normalização (cor,
  número com faixa, opção de lista), uma da matemática de cor (mistura,
  luminância, contraste WCAG) e a **herança** de tokens.
- **Herança**: um valor vazio numa peça herda do módulo, e o do módulo herda
  da marca (`cartaz → impressão → marca`). É o que faz "mudei a cor do
  hospital" valer no e-mail, no cartaz de aniversário e na etiqueta sem
  repetir a cor em cinco telas.
- **`assets/core/preview.js`** é a pré-visualização única das telas de
  personalização; **`assets/core/contrast.js`** é o selo de legibilidade.

### Temas

Um tema guarda o **conjunto inteiro** de valores da aparência (não um
delta), então aplicar um tema dá o mesmo resultado independentemente do que
estava valendo antes. Antes de aplicar qualquer tema, o estado atual é
guardado automaticamente: os 5 últimos ficam disponíveis em *Desfazer*.
Há pré-visualização em aba nova, que mostra a página real com as cores do
tema sem aplicar nada.

### Legibilidade

Cada seletor de cor traz um selo ao vivo ("4,6:1 · AA") com as faixas da
WCAG 2.1. A conta existe no servidor (`Tokens::contrastReport`) e no
navegador — o selo precisa responder enquanto se arrasta o seletor — e
`scripts/test_contraste.php` compara as duas em 218 pares, falhando se
divergirem.

### Identidade por unidade

Quando existe mais de uma unidade ativa, cada uma pode ter nome, logotipo,
favicon e cores próprios; o que ficar em branco herda do portal. São poucas
chaves de propósito: quem circula entre as unidades precisa reconhecer o
mesmo sistema. Em instalação de unidade única a sobreposição é inerte.

### Página inicial

A tela que abre depois do login era a única sem nenhum ajuste: uma saudação
fixa e uma grade de cartões fixa, igual para a instalação de três módulos e
para a de quinze. *Administração › Aparência › Página inicial* abriu:

| Ajuste | Para quê |
| --- | --- |
| **Formato**: cartões, lista compacta ou mosaico de ícones | Com muitos módulos a grade de cartões obriga a rolar; a lista cabe numa tela só, e o mosaico funciona melhor em tela tocável |
| **Colunas** (2, 3, 4 ou 6) | Só divisores de 12, para a grade fechar certo |
| **Saudação** e **linha de apoio** | `{nome}` vira o primeiro nome de quem entrou; em branco, o texto padrão |
| **Mostrar ícones / descrição / saudação** | Cada um desligável |
| **Mural** | Aviso no topo, em HTML (negrito, listas, links), com o tom escolhido |

O mural aceita HTML porque um aviso de campanha de vacinação sem negrito
nem link não é um aviso — mas passa pelo mesmo filtro dos comunicados do
RH (`HtmlSanitizer`), que remove `<script>` e atributos de evento. Em
branco, o mural não aparece.

### Galeria de superfícies e CSS livre

*Administração › Aparência › Galeria de superfícies* mostra todas as peças
de uma vez, com o contraste de cada cor. A mesma página lista as **classes
estáveis** (`.portal-topbar`, `.portal-sidebar`, `.portal-main`…) que o CSS
livre do hospital pode usar sem medo de uma atualização renomear — prefira
as variáveis (`var(--portal-primary)`) a cores fixas, para o seu CSS
acompanhar o tema e o modo escuro.

### Testes da aparência

```
php scripts/test_contraste.php                      # paridade servidor/navegador
COOKIES=sessao.json node scripts/test_visual.mjs    # regressão visual
COOKIES=sessao.json node scripts/test_visual.mjs --atualizar   # aceita as mudanças
```

O teste visual decodifica os PNG e compara **pixel a pixel** (comparar os
bytes do arquivo faria qualquer mudança virar "100%"), com tolerância por
canal para o antialiasing.

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

- **Setores independentes**: cada usuário enxerga apenas os documentos,
  indicadores e planos de ação dos setores em que foi **incluído**
  (Administração → Documentos → Setores → botão *Usuários*). Não é filtro de
  tela: a restrição entra no `WHERE` de toda consulta, e abrir um id de outro
  setor pela URL devolve "não encontrado". Documento **sem setor** é
  institucional e aparece para todos. Quem tem `sectors.view_all` (ou é
  administrador da plataforma) vê tudo.
- **Seletor de setor** no topo de todas as telas, agora limitado aos setores
  do próprio usuário — "Todos os setores" significa "todos os MEUS setores".
  Ele apenas estreita a visão, nunca a amplia.
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
- **Ciência com ciclo**: toda mudança de status (e toda versão nova)
  **redefine** as confirmações de leitura — quem leu o texto anterior confirma
  de novo. As confirmações antigas não são apagadas: viram histórico, com o
  ciclo, a versão e o status em que foram dadas. É a prova que a acreditação
  pede.
- **Histórico de modificações** de cada documento: cadastro, edições (com os
  campos que mudaram, de/para), mudanças de status, versões, revisões,
  redefinições de ciência e as próprias ciências.
- **Planos de ação (PDCA)** com o indicador escolhido no próprio formulário,
  agrupado por setor, e a possibilidade de vincular um plano existente a
  outro indicador.
- **Conformidade** integrada ao dashboard.

## Módulo Comunicação (chat)

Chat interno por canais (públicos, privados e mensagens diretas) com
threads, reações, anexos, menções, mensagens fixadas, favoritos, busca e
presença. Funções extras do sistema antigo (tarefas, reuniões,
calendário, equipes, processos, enquetes, painel) foram descontinuadas;
categorias de canais e emojis personalizados são configurados na
Administração.

**Exclusão de mensagens**: só dentro de uma janela curta depois do envio (1
minuto por padrão, de "não permitir" a 24 h em Administração → Comunicação →
Mensagens). O prazo vale para **todos**, inclusive para quem tem
`chat.moderate` — há uma exceção que precisa ser ligada de propósito, para os
casos de conteúdo impróprio. O botão de excluir some da tela quando o tempo
acaba, sem recarregar a página, e a idade é medida pelo relógio do banco (o
mesmo que gravou a mensagem).

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
  personalizável **sem escrever HTML** (Administração → RH →
  Aniversariantes): modelo (cartões, lista, tabela ou faixas), número de
  colunas, cor de destaque, fundo, cor do texto, borda, tamanho do título e
  do nome, formato e tamanho da foto e quais informações aparecem — tudo com
  pré-visualização ao lado que acompanha cada ajuste. O modo **avançado**
  (HTML e CSS na mão) continua disponível, e um botão gera o código a partir
  do visual para servir de ponto de partida.
- Departamentos e cargos são configurados na Administração.

## Módulo Manutenção

- Todo equipamento recebe um **código de identificação único de 12
  dígitos** (com dígito verificador) que gera **código de barras** e
  **QR code**; equipamentos antigos recebem o código automaticamente.
- **Etiquetas** (50×30 mm, 70×40 mm, 100×50 mm ou folha A4) para impressão,
  individuais ou em lote, com **cada elemento ocultável** na barra de
  ferramentas (organização, nome, setor/código interno, QR, código de barras,
  código em texto) e o **layout se reajustando** ao que sobrou — ocultar o
  código de barras faz o QR crescer, ocultar o QR devolve a largura inteira
  ao nome. A escolha vai na URL (sobrevive à impressão) e fica guardada no
  navegador; **busca por código** (leitor USB ou digitação)
  e página de **histórico** do equipamento (OS, calibrações,
  preventivas, peças, custos, disponibilidade).
- Setores e categorias de equipamentos são configurados na
  Administração; a aba "Dados da unidade" foi descontinuada (o nome da
  organização vem das configurações do núcleo).

## Módulo Intranet (documentos institucionais)

Editor de texto com os layouts do hospital (página, capa, fontes),
versionamento das edições (visualizar/restaurar qualquer versão), cópia
pública opcional por link e exportação em PDF.

## Módulo Meu espaço (organização pessoal)

O módulo que **leva o nome de quem está usando**: quem entra como João vê
"João" no portal e no menu, não um rótulo genérico. É o único módulo cujo
conteúdo é inteiramente privado — agenda, notas e tarefas de cada pessoa.

| Tela | O que faz |
| --- | --- |
| **Hoje** | Abre por padrão: compromissos do dia (com selo *agora* no que está em curso), próximas tarefas, notas recentes e um aviso quando há prazo vencido |
| **Agenda** | Semana de segunda a domingo, com cor por compromisso, local, observação e lembrete |
| **Tarefas** | Prazo, prioridade e situação; as de prazo mais apertado primeiro, concluídas separadas |
| **Notas** | Bloco de notas com título, cor, fixação e arquivamento |
| **Solicitações** | O que me pediram e o que eu pedi; aceitar vira tarefa minha |
| **Formulários** | Os formulários que publico para receber pedidos, e os que posso preencher |
| **E-mail** | Caixa Zoho por IMAP: ler e responder sem trocar de aba |
| **Assistente** | Organizar anotações, resumir solicitações e rascunhar respostas (opcional) |

### Solicitações e formulários

As três primeiras telas são privadas. **Solicitações** e **Formulários** são o
contrário: existem para ligar duas pessoas.

- **Solicitações** — peça algo a um colega, com prazo e prioridade. Quem
  recebe aceita, recusa ou conclui. **Aceitar cria a tarefa** de quem aceitou,
  com o vínculo guardado: sem isso o "aceito" não vira trabalho em lugar
  nenhum e o pedido some da vista.
- **Formulários** — cada pessoa cria os seus ("Pedido de material",
  "Liberação de acesso"), com os mesmos oito tipos de campo das pesquisas do
  RH. Quem preenche gera uma solicitação para o dono, com as respostas
  anexadas. Não existe tabela de "envio": **o preenchimento É a solicitação**,
  o que elimina a possibilidade de resposta órfã.

#### O que muda no escopo

Com duas pontas, "toda consulta filtra por `user_id`" deixa de bastar. O
predicado passa a ser **"eu sou uma das pontas"**, e vai no `WHERE` de toda
leitura e de toda escrita — nunca se carrega pelo id para decidir depois o que
mostrar. Conferido com três usuários: o remetente e o destinatário veem; um
terceiro, com sessão válida e token CSRF válido, não vê nada e não consegue
responder nem cancelar.

Por isso estas telas **não** reutilizam as funções da etapa 1: a allowlist de
lá casa por `user_id`, que não descreve nenhuma das duas relações.

#### Perguntas congelam depois da primeira resposta

Editar um formulário já respondido apagaria campos cujos ids estão nas
respostas — o que sobrasse responderia a perguntas que não existem mais. A
tela avisa e o servidor recusa a regravação: conferido com um POST forjado
tentando trocar as perguntas de um formulário já usado.

#### Permissões num módulo universal

O módulo é `'todos' => true`, o que concede **toda** chave a **todo mundo**.
Logo, cada chave descreve um poder sobre o próprio envolvimento ("ver as que
me envolvem", "responder as que recebi") — nunca sobre o de terceiros, que
seria concedido à organização inteira. O escopo real vem do `WHERE`.

Um efeito colateral disso era grave e foi corrigido junto: a tela de
permissões listava o módulo, aceitava desmarcar, gravava e dizia "atualizado"
— **sem revogar nada**, porque o conjunto era devolvido antes de qualquer
leitura das concessões. Agora a negação explícita vale também aqui.

### Caixa de e-mail pessoal (IMAP)

Ler e responder o e-mail de trabalho sem trocar de aba. Configurado para o
Zoho por padrão (`imap.zoho.com:993`), serve qualquer servidor IMAP.

#### A senha NÃO é guardada — e isso é a decisão central

Uma análise de risco antes de escrever o código mudou o desenho. Três fatos
decidiram:

- a **senha de aplicativo do Zoho contorna a verificação em duas etapas** e
  **nunca expira** — nem quando a senha principal da conta é trocada. Um
  vazamento não se cura com o tempo nem com "todo mundo troque a senha": só
  com revogação individual, uma por uma, dentro do Zoho;
- `MailSecret::hide()` **nunca falha**: sem OpenSSL ela grava base64 e devolve
  normalmente. Quem confiasse no retorno gravaria texto claro sem perceber;
- o banco e o `config.php` (de onde sai a chave de cifra) ficam no **mesmo
  servidor**. "Só vaza se os dois vazarem" é uma leitura de arquivo, não dois
  incidentes independentes.

Então a tabela `meu_email_contas` **não tem coluna de senha**. A pessoa digita
a senha de aplicativo uma vez por sessão; ela vive em `$_SESSION` e morre com
ela. Conferido: um dump completo do banco tem **zero** ocorrências da senha, e
o pacote de backup também.

**O limite honesto:** enquanto a caixa está aberta, a senha está no arquivo de
sessão, no disco do servidor (`-rw-------`, em diretório sem leitura para
outros usuários). Ela não está no banco nem no backup, e some quando a sessão
expira — mas não é "em lugar nenhum".

**O caminho para não digitar toda vez** é OAuth 2.0 com o Zoho: guarda-se um
*refresh token* em vez da senha, com escopo só de e-mail, revogável pela
própria pessoa e sem anular o 2FA. Fica como evolução; exige registrar um
aplicativo no Zoho Developer Console.

#### O cliente IMAP é próprio

`Core\MailInbox` não serviu: ele é um **diagnóstico de um tiro** — conecta,
autentica, conta as mensagens, lê alguns cabeçalhos e sai. Não lê corpo, e
sobretudo **lê sempre linha a linha**. O corpo de uma mensagem chega num
*literal* (`{4096}` seguido de 4096 bytes crus) que pode conter qualquer
coisa, inclusive uma linha que começa com a etiqueta do comando — lendo linha
a linha, o cliente confunde conteúdo com protocolo.

`Core\ImapCliente` é uma instância (não estático), entende literais, busca por
UID e sabe marcar como lida. `Core\Mime` faz a leitura do que chega:
`=?UTF-8?B?...?=` no assunto, quoted-printable, base64, multipart aninhado,
conversão de charset e o preâmbulo que não é conteúdo.

Nada usa a extensão `imap` do PHP: ela foi separada do núcleo e falta na maior
parte das hospedagens compartilhadas — que é onde este sistema roda.

#### Como isso é testado

`imap.zoho.com` não é alcançável do ambiente de teste, e "funciona em teoria"
não é teste. `scripts/imap_falso.py` é um servidor IMAP que fala o protocolo
de verdade e serve três mensagens escolhidas para quebrar implementações
descuidadas: assunto em RFC2047, corpo quoted-printable, uma mensagem em
ISO-8859-1 com bytes altos crus, e um multipart com preâmbulo e parte base64.

Metade das mensagens vem com `UID` e `FLAGS` **depois** do literal — a RFC
3501 permite os *data items* em qualquer ordem, e servidores reais usam essa
ordem com frequência. Foi esse caso que revelou um defeito de verdade: o
cliente lia UID e FLAGS só da primeira linha do item, e nessas mensagens o UID
saía **zero** — a lista aparecia inteira e certa, com todos os links quebrados,
sem erro nenhum na tela. Reproduzido no servidor de teste antes de consertar.

#### O que ainda não faz

- **Anexo não baixa pelo portal.** A tela lista o nome e o tamanho; para abrir,
  é o webmail. Baixar exigiria uma rota de download com o conteúdo vindo do
  IMAP a cada clique, e ela seria o caminho mais curto para servir qualquer
  arquivo com qualquer tipo — fica para quando tiver revisão própria.
- **Cada carregamento de página abre uma conexão nova.** Sem cache: a lista é
  buscada de novo a cada visita. Numa caixa grande isso é lento. O caminho é
  guardar a lista na sessão por alguns minutos, com botão de atualizar.
- **A resposta não é encadeada.** Sai como mensagem nova com `Re:` no assunto,
  sem `In-Reply-To`/`References`, então o cliente de quem recebe não a coloca
  na mesma conversa.
- **Mensagem grande demais abre só o cabeçalho.** Acima de 2 MB o corpo é
  cortado e acima de 8 MB nem é pedido — a tela mostra remetente, assunto e
  data com um aviso. Antes disso, a mensagem simplesmente não abria.

#### Duas exigências da tela da senha

Como a senha digitada é a senha de aplicativo do Zoho — que contorna o 2FA e
nunca expira —, o formulário **se recusa a funcionar fora de https**: o campo
vem desabilitado com o motivo à vista, e o `POST` é recusado no servidor,
porque campo desabilitado na tela não impede requisição direta. A exceção é a
instalação local (`localhost`), a mesma que `Https::enforce()` já abre.

E a senha **não aparece em mensagem de erro**: o diagnóstico do envio ecoa o
diálogo SMTP, onde o `LOGIN` passou. `MailConfig::redact()` só conhece a senha
global do portal, então a do usuário passaria inteira para a tela — ela é
apagada explicitamente antes de virar mensagem.

Abrir a caixa e responder por ela ficam na **auditoria** (`meu.email.abrir`,
`meu.email.responder`): é quando credencial de e-mail entra no sistema e
quando sai mensagem com o nome da pessoa para fora do hospital.

### Assistente (IA)

Desligado por padrão. Ligado em *Administração › Assistente*, com chave da
API da Anthropic (guardada cifrada, nunca exibida de volta), modelo e **teto
mensal de gasto**.

Três funções, sobre o que está **no espaço do próprio usuário**: transformar
uma anotação solta em lista de tarefas, resumir as solicitações que ele
recebeu e estão abertas, e rascunhar uma resposta. O resumo é o único que
toca em algo escrito por outra pessoa — o título da solicitação; o nome de
quem pediu não vai (ver abaixo).

#### O que o sistema faz, e o que ele não faz

| Faz | Não faz |
| --- | --- |
| Mostra na tela **exatamente o que será enviado**, antes de enviar | Decidir o que pode sair do hospital — isso é política, e política é de quem responde pela instituição |
| Registra **que** houve a chamada, de quem, para quê e de que tamanho | Guardar o conteúdo enviado — seria criar uma segunda cópia do que se quer proteger |
| Recusa texto com marca de CPF, cartão do SUS ou palavras de contexto assistencial | Prometer que isso é suficiente |
| Impede o gasto acima do teto, conferido **antes** de cada chamada | Substituir a fatura real do painel da Anthropic |

#### O que sai de verdade no resumo de solicitações

"Todas sobre o que é do próprio usuário" era **impreciso**, e a revisão pegou:
o resumo das solicitações recebidas montava a lista com o **nome de quem
pediu** — um terceiro, que não escolheu ter o próprio nome enviado para fora
do hospital e nem sabe que o colega usou o assistente. Para priorizar, o nome
não acrescenta nada: o que decide é prazo, prioridade e situação. Hoje sai
`- <título> (pedido por um colega, prioridade alta, prazo 20/09/2026,
situação aberta)`.

Continua saindo o **título que o colega escreveu**, porque sem ele não há o
que resumir. Quem escreve um título de solicitação está escrevendo para uma
pessoa, não para um modelo — e por isso o texto exato aparece na tela antes
de enviar, para quem clica poder ver e desistir.

A trava é uma **rede, não uma garantia**: ela reconhece formato, e dado
clínico escrito em português corrido não tem formato. Quando ela pega algo, o
sistema **para** — não redige por cima. Apagar o CPF e mandar o resto daria a
impressão errada de que o texto foi conferido. Para seguir, é preciso marcar
"confirmo que não há dado de paciente", e essa confirmação vai para a
auditoria (com a marca encontrada, sem o texto).

#### Custo

A chamada é HTTPS direto, sem SDK e sem Composer — este sistema é entregue
por FTP a hospedagem compartilhada, e um `vendor/` é um problema maior que a
comodidade que traz.

O teto é do portal, em centavos de dólar por mês, conferido antes de gastar.
Ele conta **também as chamadas que falharam depois de o prompt sair** — tempo
esgotado no meio da resposta, conexão cortada: a Anthropic cobra do mesmo
jeito, e somar só o que deu certo deixaria o teto sempre abaixo do gasto real,
que é exatamente o erro que ele existe para evitar. A estimativa usa a tabela
pública de preços; a cobrança real é a do painel da Anthropic, e a tela diz
isso. Preços por milhão de tokens (entrada/saída):
Opus 5 US$ 5/25, Sonnet 5 US$ 2/10, Haiku 4.5 US$ 1/5. Uma anotação de meia
página custa frações de centavo.

O item do menu só aparece quando o hospital ligou o assistente — um menu que
leva a "não está ligado" é ruído para a organização inteira.

### Privacidade: como ela é garantida

Toda tabela do módulo tem `user_id` e **toda** consulta filtra por ele —
inclusive `UPDATE` e `DELETE`, que levam `user_id` no `WHERE`. Adivinhar o
id de um registro alheio não adianta: a instrução não casa nenhuma linha.
Nem o administrador global vê a agenda de outra pessoa por estas telas.
Conferido com dois usuários reais: nas três telas e no acesso direto por
id, nada do outro aparece.

Por isso o módulo é marcado `'todos' => true` no manifesto e toda pessoa
logada o recebe sem o administrador conceder nada. Conceder "tudo" num
módulo que só mostra o próprio espaço é conceder acesso a si mesmo; semear
permissões por usuário quebraria no primeiro funcionário admitido depois
da instalação.

### Dois detalhes que custam caro quando faltam

- **Plantão que vira a noite.** Fim antes do início vira o dia seguinte, e
  a consulta da semana busca por **interseção** (`inicio <= fim_periodo AND
  fim >= inicio_periodo`), não por "começa dentro do dia". Sem isso o
  plantão das 19h às 7h sumiria do segundo dia — que é justamente quando
  ele termina. Na tela, o dia seguinte mostra o mesmo compromisso com um
  marcador `+1`.
- **Nota colada de fora.** O conteúdo passa pelo `HtmlSanitizer` dos
  comunicados: o dono da nota é quem escreve, mas colar de um e-mail traz
  junto o que veio.

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

Cada módulo roda num **subprocesso próprio** (`php cron.php --module=<slug>`),
porque os módulos declaram funções globais com o mesmo nome (`e`, `redirect`,
`url`, `paginate`) e não podem conviver no mesmo processo — o segundo a
carregar mata tudo com *Fatal error*. O cron tenta `proc_open`, depois `popen`,
depois `exec`. Se a hospedagem desabilitou os três, ele **não finge**: roda só
o primeiro módulo, imprime a linha exata para agendar cada um dos outros, e o
checkup mostra em vermelho quais ficaram de fora até isso ser feito. Antes,
nessa situação, o cron morria no segundo módulo sem uma linha na tela, e a
rotina de manutenção simplesmente nunca rodava.

```
```

Executa, nesta ordem: a fila de e-mails, o **backup agendado**, a **limpeza
automática** (sempre depois do backup — o backup do dia é feito antes de
qualquer coisa ser apagada) e as rotinas de todos os módulos (vencimentos de
documentos, preventivas de manutenção e códigos de equipamentos,
aniversários/vencimentos do RH etc.). Use `--module=<slug>` (ou `&module=`)
para executar só um módulo.

A Administração usa a marca de execução do cron para avisar quando ele
parou, em vez de deixar tudo pendente em silêncio — ver o **checkup** abaixo.

## Checkup de saúde do sistema (Administração → Atualizações de banco)

A pergunta que aparece depois de toda atualização — "está tudo certo?" —
respondida numa página só, agrupada em banco de dados, PHP e extensões,
pastas e arquivos, segurança, rotinas/e-mail e backup. Cada problema vem com
**o que fazer a respeito**.

Entre o que ela detecta: migrações pendentes; tabelas que os módulos ativos
esperam e não existem; tabela fora de InnoDB ou de utf8mb4;
`max_allowed_packet` pequeno demais para restaurar um backup; extensões
faltando; `post_max_size` menor que `upload_max_filesize` (que faz o upload
falhar em silêncio); pasta sem permissão de escrita; disco quase cheio;
`install.php` esquecido no servidor; `app.key` ainda a do exemplo; `app.debug`
ligado em produção; cron parado; fila de e-mail travada; e backup velho ou
inexistente. A situação do HTTPS tem um diagnóstico próprio, descrito em
[HTTPS (diagnóstico e reforço)](#https-diagnóstico-e-reforço).

A página **só lê** — não altera nada — e é segura de abrir a qualquer
momento, inclusive em produção.

## Limpeza automática (Administração → Configurações)

Por quantos dias cada tipo de registro é guardado. Passado o prazo, o cron
apaga; **0 significa nunca apagar**.

| Registro | Padrão | Mínimo |
| --- | --- | --- |
| Auditoria | 365 dias | 90 |
| Notificações já lidas | 90 dias | 7 |
| Fila de e-mail (enviados/descartados) | 60 dias | 7 |
| Histórico de testes de e-mail | 30 dias | 1 |
| Tentativas de login | 30 dias | 7 |
| Pedidos de redefinição de senha | 7 dias | 1 |
| Histórico de documentos | nunca | 365 |
| Arquivos de log | 60 dias | 7 |
| Temporários de backup | 2 dias | 1 |

Três cuidados que valem a pena conhecer:

- **O que está em uso nunca sai**: notificação não lida, e-mail pendente na
  fila e o backup mais recente ficam, por mais velhos que sejam.
- **Cada prazo tem um piso**, então um "30" digitado no lugar errado não
  apaga um ano de auditoria — o valor é elevado ao mínimo da linha.
- **A exclusão vai em lotes** de 2 mil linhas: um `DELETE` de milhões trava a
  tabela e derrubaria o portal justamente durante a rotina noturna.

Há **simulação** ("só contar") antes de apagar, e um botão para executar na
hora. A retenção dos *pacotes* de backup (quantos diários, semanais e
mensais) fica em Administração → Backup, junto da lista dos pacotes.

## Notificações

O sino consulta o servidor a cada **5 segundos** com a aba à frente e **20**
em segundo plano (ambos configuráveis em Administração → Configurações),
atualiza a lista junto e anuncia a chegada num aviso de canto. Voltar para a
aba, focar a janela ou abrir o sino consulta na hora.

É **um** poller para o portal inteiro: os contadores dos módulos se penduram
nele (`window.PortalNotificacoes.aoAtualizar`) em vez de cada um abrir o seu,
então a atualização ficou muito mais rápida sem multiplicar os pedidos.

## Segurança

- Senhas bcrypt (cost 12); bloqueio de força bruta; CSRF em todos os POSTs;
- 2FA TOTP opcional por usuário (Perfil → Senha e 2FA);
- Sessão única com regeneração periódica de ID e expiração por inatividade;
- Uploads bloqueados para execução; diretórios internos negados no Apache
  (`.htaccess` da raiz e de `storage/`). **Em Nginx** (ou Apache sem
  `AllowOverride`), negue explicitamente os diretórios internos:

  ```nginx
  location ~ ^/(core|config|sql|storage|docs|scripts)/ { deny all; }
  location ~ ^/uploads/.*\.(php|phar|phtml)$ { deny all; }
  location ~ /\.(?!well-known) { deny all; }         # .git, .gitignore, .htaccess (não o ACME)
  location ~ ^/(README|CHANGELOG)[^/]*\.md$ { deny all; }
  ```

### `scripts/` executava por URL — e a tranca não podia ser o `.htaccess`

Uma varredura desta pasta achou o pior caso possível de "protegido por
configuração": `scripts/` **não estava** na lista do `.htaccess` da raiz nem
no trecho de Nginx aqui acima, e `migrate.php`, `migrate_role_grants.php` e
`test_contraste.php` não tinham nenhuma guarda de linha de comando.

Reproduzido, não deduzido: com um arquivo pendente em `sql/migrations/`, um
**GET anônimo** em `/scripts/migrate.php` — sem login, sem token, sem nada —
aplicou a migração e criou a tabela no banco. O `migrate_role_grants.php`
também rodava, e devolvia o resultado da conversão na tela.

A correção **não é** a linha nova no `.htaccess`. Essa linha entrou, e o
trecho de Nginx acima também, mas as duas são a segunda tranca. A que vale é
`scripts/_cli.php`: o primeiro `require` de todo script da pasta, **antes do
bootstrap**, que recusa qualquer coisa que não seja `PHP_SAPI === 'cli'`.
Carregar o núcleo primeiro abriria sessão e mandaria cabeçalho antes da
recusa sair — trabalho e rastro para uma requisição que não deveria existir.

O motivo de a guarda ficar no PHP é o mesmo que vale para o resto deste
sistema: `.htaccess` falha em silêncio. Nginx o ignora, Apache com
`AllowOverride None` o ignora, e uma cópia por FTP que perde arquivos ocultos
o deixa para trás — nos três casos sem um aviso sequer. Um script que só é
seguro quando o servidor colabora não é seguro.

`scripts/` também entrou na sonda de exposição do checkup, com nível de
**erro** — é a única pasta da lista que não apenas vaza, mas executa.

  Arquivos privados (anexos de comunicados, por exemplo) ficam em
  `storage/uploads/` e só são entregues pelo download autenticado do módulo.
  **Limite honesto:** os anexos do chat e os arquivos de mural continuam em
  `uploads/`, que é pública por natureza, e a única coisa entre eles e uma URL
  anônima é o `.htaccess` da pasta — que o Nginx ignora. O nome aleatório do
  arquivo é o que resta. Fechar isso de verdade é movê-los também para a pasta
  de dados e servi-los só pela rota autenticada, como já se fez com os do RH;
- Imagens da identidade visual (`uploads/branding/`) são públicas por
  natureza — o navegador precisa buscá-las —, mas nunca executáveis: SVG
  enviado é sanitizado antes de gravar e a extensão real é decidida pelo
  tipo do conteúdo, não pelo nome do arquivo;
- **O IP do cliente é `REMOTE_ADDR`**, a menos que `security.trust_proxy`
  esteja ligado. `X-Forwarded-For` e afins vinham PRIMEIRO, de qualquer
  origem: um atacante trocava o cabeçalho a cada requisição e o bloqueio de
  força bruta por IP nunca acumulava — 15 senhas em 15 contas, zero bloqueios,
  medido — e o IP gravado na auditoria era o inventado. A mesma regra que
  `Core\Https` já usava para `X-Forwarded-Proto`.
- **`app.base_url` não pode ficar vazia.** Vazia, toda URL absoluta sai do
  cabeçalho `Host` de quem faz o pedido — inclusive o link do e-mail de
  redefinição de senha, que passa a apontar para o domínio do atacante com
  token válido (reproduzido ponta a ponta). O instalador grava o endereço real
  e o checkup marca em vermelho quando está vazia.
- O código de 2FA tem limite de tentativas como o login (5 falhas descartam a
  etapa pendente); a página pública de acompanhamento de candidatos compara o
  token por igualdade (era `LIKE`, e doze sublinhados casavam com qualquer um);
  só um `cron.php` roda por vez (`cron.lock`), e a materialização de
  preventivas reivindica o plano antes de criar a OS.
- Auditoria unificada (Administração → Auditoria).

## Tirar config e dados do public_html

Backup, log e anexo privado **não são arquivos `.php`**: nenhum interpretador
se mete no caminho, o servidor simplesmente entrega. Entre a internet e um
dump completo do banco existe só a configuração do servidor web — que falha
em silêncio (o Nginx ignora `.htaccess` sem um aviso sequer, e o mesmo vale
para Apache com `AllowOverride None` ou para uma cópia por FTP que escondeu
arquivos ocultos).

Nada abaixo é obrigatório: quem não mexer em nada continua funcionando igual.

### A pasta de dados

Em `config.php`:

```php
'paths' => [
    'storage' => '/home/suaconta/portal-dados',
],
```

Copie o conteúdo de `storage/` para lá e pronto — logs, cache, backups e
anexos privados passam a morar fora. O caminho relativo conta a partir da
raiz da instalação; o absoluto vale como está.

**Se o caminho não existir, o sistema NÃO volta para `storage/`.** Ele
registra um erro crítico no log do servidor, o checkup acusa, e as gravações
falham — de propósito. Cair de volta para dentro da área pública espalharia
atestado e backup no `public_html` sem ninguém notar, que é exatamente o que
esta configuração existe para evitar.

### O arquivo de configuração

O sistema procura `config.php` nesta ordem, e o **primeiro que existir vence**:

| Ordem | Onde | Para quê |
| --- | --- | --- |
| 1 | constante `UNIFICAR_CONFIG` definida antes do bootstrap | testes, front controller próprio |
| 2 | variável de ambiente `UNIFICAR_CONFIG` | `SetEnv` no Apache, env do PHP-FPM, systemd |
| 3 | pasta irmã `<nome-da-pasta-pública>-config/config.php` | o caminho recomendado |
| 4 | `config/config.php` | o lugar de sempre |

A pasta irmã leva o nome da pasta pública (`public_html` → `public_html-config`)
porque em hospedagem com *addon domains* o diretório acima é o **home da
conta, compartilhado**: uma pasta chamada só `config` colidiria entre duas
instalações, e em silêncio.

**Se o portal fica numa subpasta do site** (`public_html/portal`), a irmã
`public_html/portal-config` **continua dentro da área pública** — responde
pela URL `/portal-config/`. O instalador detecta isso e sobe para a irmã da
raiz do site; o checkup compara com a raiz **servida** (`DOCUMENT_ROOT`), não
com a pasta da instalação, justamente para não dizer "está fora" quando não
está. Como o `DOCUMENT_ROOT` só existe em requisição web e o teste roda no
cron, o valor visto pelo navegador fica guardado para a linha de comando usar.

O instalador de uma instalação nova já grava fora quando consegue criar a
pasta irmã, com `chmod 0600`, e diz na tela onde gravou.

### O teste que prova (Administração › Checkup)

Verificar se existe um `.htaccess` não prova nada — é o mesmo erro de conferir
o artefato em vez do efeito. O sistema grava um **arquivo-isca com um segredo
aleatório** em cada pasta sensível e tenta baixá-lo pela própria URL. Se o
segredo voltar pela web, a pasta está aberta.

Detalhes que decidem se o teste vale:

- **O veredito é o segredo voltar, não o código 200.** Servidor que responde a
  tela de login com 200 para qualquer caminho daria falso positivo. E um 200
  que *não* traz o segredo vira **"não consegui testar"**, nunca "protegida":
  pode ser login, proxy ou regra que responde qualquer endereço.
- **O endereço vem de `app.base_url`**, nunca do cabeçalho `Host` — senão quem
  faz a requisição escolheria o alvo do teste.
- **Roda no cron, não na tela.** Uma requisição web que busca o próprio
  servidor precisa de um segundo processo: em hospedagem com **um** worker de
  PHP — justamente a barata, que é quem mais precisa deste aviso — a página
  espera uma resposta que só ela poderia dar. Medido: 1 worker estoura o tempo
  com 0 bytes; 4 workers respondem em 1 ms. O botão *Testar agora* existe,
  desiste em 8 s e relata **"não consegui testar"**.
- **Uma recusa só vale como prova se veio DESTE servidor.** Antes de acreditar
  em qualquer 403/404, a sonda grava uma **isca de controle** numa pasta que é
  obrigatoriamente servida (`assets/`) e a pede pela `app.base_url`. Se nem ela
  volta, `app.base_url` não chega a esta instalação — domínio antigo, cópia de
  homologação, `www`/não-`www` trocado — e o 404 estava vindo de outro lugar.
  Nesse caso nenhuma pasta é dada como "protegida": todas viram "não foi
  possível testar", com o motivo. Antes disso, uma instalação com `sql/` e
  `core/` escancarados era relatada como protegida porque o domínio errado
  respondia 404 para tudo.
- **Cada "não foi possível testar" diz o porquê e o que fazer** — e o que fazer
  muda com o porquê. "Rode pelo cron" só aparece quando foi estouro de tempo;
  pasta dentro do site mas fora da instalação manda mover a pasta; `app.base_url`
  vazia manda preenchê-la. Antes era um conselho só para tudo, e ele saía até
  quando tinha sido o cron que acabara de rodar.
- **Um teste inconclusivo não apaga um resultado conclusivo** guardado antes.
- A isca sai sempre, inclusive se a busca falhar no meio.

### O limite honesto

Isto protege contra **erro de configuração do servidor web**. Não protege
contra falha de leitura de arquivo no próprio PHP nem contra conta invadida:
ali o código já tem o caminho e lê do mesmo jeito.

## Tirar o CÓDIGO do public_html

Depois do config e dos dados, sobra o código: `core/`, `modules/`, `sql/`,
`docs/` e `scripts/`. Ele também pode sair, para uma pasta irmã chamada
`<nome>-codigo` — a mesma convenção do `-config`, para o administrador não ter
de aprender duas. A área servida fica com seis itens:

```
/home/conta/public_html/          ← DocumentRoot
├── index.php        front controller
├── localizar.php    acha o código (é o único que os outros precisam conhecer)
├── install.php      instalador (apague depois de instalar)
├── cron.php         rotina periódica — precisa ficar aqui, ver abaixo
├── assets/          CSS, JS, imagens do sistema
└── uploads/         o que é público por natureza (logo, avatar, anexo de mural)

/home/conta/public_html-codigo/   ← fora da web
├── core/  modules/  sql/  docs/  scripts/  config/config.example.php
/home/conta/public_html-config/   ← já era assim
/home/conta/public_html-dados/    ← já era assim
```

### O que este ganho é, medido — e o que ele não é

A varredura que precedeu a mudança procurou segredo embutido em fonte nas
50.877 linhas de `core/` e `modules/`, mais `sql/`, `docs/` e `scripts/`:
atribuição literal, DSN, hash, alta entropia, padrões de chave conhecidos.
Achou **zero**, fora o admin semente `admin`/`admin123` do `schema.sql`. Todo
segredo de verdade já estava no `config.php` ou cifrado no banco.

Então **este passo rende muito menos que os dois anteriores**, e é honesto
dizer isso: ele esconde código que não tem senha nenhuma. O que ele entrega,
medido nas duas arrumações rodando lado a lado em servidores de verdade:

| pasta | código junto | código na irmã |
| --- | --- | --- |
| `sql/` | **exposta** | fora |
| `modules/` | **exposta** | fora |
| `core/` | **exposta** | fora |
| `scripts/` | **exposta** | fora |
| `docs/` | **exposta** | fora |

"Exposta" ali não é teoria: é a sonda gravando um arquivo-isca e o servidor
devolvendo o conteúdo pela URL. O servidor de teste ignora `.htaccess` — como
o Nginx, e como o Apache com `AllowOverride None`.

### A decisão que faz a mudança ser segura

`BASE_PATH` **não mudou de significado nem de valor**. Ela continua sendo a
raiz servida pela web, e é dela que derivam as URLs, a pasta irmã do config, a
pasta irmã dos backups e o destino dos uploads. Quem nasceu foi `APP_PATH`,
para as quatro coisas que na verdade queriam dizer "onde está o código".

Isso não é preciosismo de nomenclatura. `BASE_PATH` era `dirname(core/)`:
mover `core/` mudaria o valor dela **sem ninguém editar uma linha**, e junto
mudariam o config procurado, a pasta dos backups e o destino dos uploads.
Nenhuma dessas mudanças daria erro. Todas dariam resultado errado em silêncio
— o histórico de backup do hospital ficaria invisível e o cron gravaria o
pacote de amanhã numa pasta nova e vazia, enquanto a tela mostraria "1 backup,
hoje" e o administrador concluiria que está tudo funcionando.

Por isso `BASE_PATH` passou a vir de onde está o `index.php`, que é a
definição de "raiz servida", e é definida **antes** do bootstrap.

### `cron.php` fica no público, de propósito

Em hospedagem compartilhada, agendar por URL costuma ser a **única** opção. A
rota `cron.php?token=<cron_secret>` está documentada e continua valendo. O
arquivo é minúsculo e a autorização é conferida nele; as rotinas de verdade
moram em `modules/<slug>/cron/`, fora do público, e **recusam** ser chamadas
sem a constante que o `cron.php` define depois de conferir o token.

### Como mover, com o portal no ar

O ensaio abaixo foi executado, passo a passo, contra uma instalação servida:

1. **Suba** `core/`, `modules/`, `sql/`, `docs/`, `scripts/` e
   `config/config.example.php` para uma pasta com nome **provisório** —
   `<nome>-codigo.subindo`, por exemplo. Quando a cópia terminar, **renomeie**
   para `<nome>-codigo`.

   A ordem importa. O localizador prefere a pasta irmã assim que ela existe e
   parece completa, então subir direto com o nome final abre uma janela em que
   o portal já escolheu a irmã e ela ainda está pela metade: **medido, o site
   fica fora do ar com página em branco durante parte da transferência.**
   Renomear é instantâneo e não tem essa janela. (O localizador também exige
   três arquivos do núcleo, e não só o `bootstrap.php`, o que encurta a
   janela de quem subir direto — mas encurtar não é fechar.)

   Depois do rename o portal já roda o código de lá, e **você não apagou
   nada**: é o momento de conferir de verdade. O checkup fica **vermelho** e
   nomeia as pastas que sobraram.
2. **Confira** o portal e o checkup. Se algo estiver errado, **apague a pasta
   irmã** — e tudo volta exatamente ao que era, sem tocar em configuração nem
   em banco.
3. **Apague** as pastas de código do `public_html`. O checkup fica verde:
   *"O código está em … e não sobrou cópia na área pública."*

Nenhum passo exige editar configuração ou mexer no banco. E nenhum derruba o
portal — **desde que a subida use nome provisório e rename**, como diz o passo
1; subir direto com o nome final tem, sim, uma janela de indisponibilidade.

**A armadilha é o passo 3**, e o checkup existe por causa dela: mover por FTP
é copiar-e-apagar, e é o apagar que falha — conexão caindo, servidor recusando
pasta não vazia, pessoa interrompida. Uma cópia esquecida de `core/` continua
sendo servida, e a sonda **não a vê** (depois da mudança ela procura essas
pastas em `APP_PATH`, que é onde está a cópia boa). A pasta perigosa é
justamente a que a sonda deixou de olhar — por isso a conferência de sobras é
um item separado do checkup, em vermelho, com os nomes.

### Se o portal mora numa SUBPASTA do site

Aqui a convenção falha, e falha de um jeito que parece certo. Com o portal em
`public_html/portal`, a irmã derivada é `public_html/portal-codigo` — que está
**dentro** do `DocumentRoot` e responde por `https://site/portal-codigo/`.
Conferido: `/portal-codigo/sql/schema.sql` devolve o schema inteiro.

E é **pior que antes de mover**: dentro da instalação, `core/`, `sql/` e
`docs/` eram cobertas pela regra do `.htaccess` da raiz; na pasta irmã não há
`.htaccess` nenhum para elas (só `modules/` e `scripts/` levam o seu).

Nesse arranjo, ponha o código fora do `DocumentRoot` — ao lado do **site**, e
não ao lado da subpasta — e aponte-o com `UNIFICAR_APP`. O checkup confere
isso contra o `DocumentRoot` de verdade, não contra a pasta da instalação, e
fica **vermelho** enquanto o código estiver servido por alguma URL.

### Se a convenção não servir

`UNIFICAR_APP` (variável de ambiente) aponta a pasta do código, e
`UNIFICAR_PUBLIC` aponta a pasta servida — esta última só é consultada pela
linha de comando, onde não existe requisição de onde deduzir. É a mesma saída
que `UNIFICAR_CONFIG` já oferecia para o arquivo de configuração.

### O limite honesto, de novo

Vale o mesmo de antes, e vale mais aqui: isto protege contra **erro de
configuração do servidor web**. Não protege contra falha de leitura de arquivo
no próprio PHP, nem contra conta de FTP invadida — ali o código já tem o
caminho e lê do mesmo jeito. E, como a varredura mostrou, o que está sendo
escondido não contém segredo: o ganho real é não entregar de graça o mapa da
instalação a quem for procurar por onde atacar.

## HTTPS (diagnóstico e reforço)

O checkup não pergunta só se `app.base_url` começa com `https://` — essa
resposta sozinha dá o mesmo conselho ("instale um certificado") em situações
bem diferentes, e em duas delas o conselho está errado. `Core\Https` separa
os casos:

| Situação | Nível | O que ela diz |
| --- | --- | --- |
| `base_url` é local (`127.0.0.1`, `.test`, `.local`) | informação | Desenvolvimento: não existe certificado para localhost e nada sai da máquina. Nada a fazer. |
| Proxy reverso à frente, sem `trust_proxy` | aviso | O site pode já ser https sem o PHP saber — e aí **o cookie de sessão sai sem a marca `Secure`**. Conserto é no proxy, não no certificado. |
| A visita chegou por https, mas `base_url` está em http | erro | O certificado existe; o que está errado é uma linha. Os links dos e-mails, **inclusive o de redefinição de senha**, apontam para a versão sem criptografia. |
| Tudo em https, sem HSTS | aviso | Quem digita o endereço sem `https://` faz a primeira visita em http — e é nela que um interceptador age. |
| Tudo em https, com HSTS | ok | Mostra por quantos dias. |
| http de ponta a ponta | erro | Aí sim: instale um certificado (Let's Encrypt é gratuito) e ajuste `base_url`. |

### Atrás de proxy reverso

Quando há Nginx, Apache ou um balanceador terminando o TLS, o PHP só sabe que
a visita veio por HTTPS se o proxy contar. Faça o proxy enviar o cabeçalho:

```nginx
proxy_set_header X-Forwarded-Proto $scheme;
```

e declare que ele é confiável em `config/config.php`:

```php
'security' => [
    'trust_proxy' => true,
],
```

**Só ligue isso se houver mesmo um proxy à frente.** Com `trust_proxy` ligado
e nenhum proxy, qualquer cliente pode mandar `X-Forwarded-Proto: https` e o
sistema acredita — o que anula o redirecionamento e faz o cookie ganhar a
marca `Secure` numa conexão que não é segura. O padrão é `false` justamente
por isso.

### Redirecionamento e HSTS (Administração → Configurações)

Dois interruptores, **ambos desligados por padrão**:

- **Forçar HTTPS** — responde `301` para `https://` em toda visita que chegar
  por http;
- **HSTS (dias)**, com *incluir subdomínios* opcional — manda o navegador
  recusar http para este domínio pelo prazo informado.

Três travas impedem que ligá-los tranque todo mundo para fora:

- Os campos ficam **desabilitados enquanto a própria página não estiver em
  HTTPS**, e o salvamento recusa a mudança de novo no servidor. Ligar
  redirecionamento num servidor cujo TLS ainda não funciona deixaria o
  administrador sem acesso à tela para desfazer — precisaria do banco de
  dados para voltar atrás.
- O **HSTS só é enviado em resposta já segura**. Enviá-lo por http é ignorado
  pelo navegador de qualquer forma, mas enviá-lo antes de o certificado
  funcionar deixaria o navegador se recusando a voltar ao http.
- O redirecionamento **nunca age em requisição local** e ignora `Host`
  suspeito (só aceita letras, números, ponto, hífen e porta), para não montar
  um `Location` com cabeçalho injetado.

Comece pelo redirecionamento, confirme que o site responde bem por alguns
dias, e só então ligue o HSTS — ele é a parte difícil de desfazer, porque
quem já visitou guarda a instrução pelo prazo inteiro.

### Um só detector

`Core\Https::requestIsSecure()` é o único lugar que decide se a visita é
segura: o cookie de sessão (`Core\Session`), o redirecionamento, o HSTS e o
checkup usam todos ele. Antes, a sessão tinha a sua própria cópia — que
aceitava `X-Forwarded-Proto` de qualquer origem e divergia do que o checkup
enxergava.
