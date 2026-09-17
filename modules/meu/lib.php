<?php
/**
 * MEU ESPAÇO — funções compartilhadas pelas páginas do módulo.
 *
 * A regra que atravessa o arquivo inteiro: toda consulta é filtrada por
 * meu_uid(). Não existe tela, rota ou parâmetro neste módulo que leve ao
 * registro de outra pessoa — nem para o administrador global. Por isso as
 * funções de escrita recebem o id do registro E do usuário, e o UPDATE/DELETE
 * leva user_id na cláusula WHERE: mesmo que alguém adivinhe um id, a
 * instrução não casa nenhuma linha.
 */

declare(strict_types=1);

use Core\Auth;
use Core\DB;

/** Id do usuário logado. */
function meu_uid(): int
{
    return (int) Auth::id();
}

/** Início e fim do dia informado (ou de hoje), no formato do banco. */
function meu_dia(string $data = ''): array
{
    $d = $data !== '' ? $data : date('Y-m-d');
    $ts = strtotime($d) ?: time();
    return [date('Y-m-d 00:00:00', $ts), date('Y-m-d 23:59:59', $ts)];
}

/**
 * Compromissos que tocam o intervalo — não só os que começam dentro dele.
 * Um plantão que entra às 19h e sai às 7h do dia seguinte precisa aparecer
 * nos dois dias, e a comparação ingênua (inicio BETWEEN a AND b) o esconde
 * do segundo.
 */
function meu_eventos(string $de, string $ate): array
{
    return DB::query(
        'SELECT * FROM meu_eventos
          WHERE user_id = ? AND inicio <= ? AND fim >= ?
          ORDER BY dia_inteiro DESC, inicio',
        [meu_uid(), $ate, $de]
    );
}

/** Tarefas abertas, as com prazo mais apertado primeiro. */
function meu_tarefas_abertas(int $limite = 0): array
{
    $sql = "SELECT * FROM meu_tarefas
             WHERE user_id = ? AND situacao <> 'concluida'
             ORDER BY (prazo IS NULL), prazo,
                      FIELD(prioridade,'alta','normal','baixa'), ordem, id";
    if ($limite > 0) {
        $sql .= ' LIMIT ' . (int) $limite;
    }
    return DB::query($sql, [meu_uid()]);
}

/** Notas: fixadas primeiro, depois as mexidas mais recentemente. */
function meu_notas(bool $arquivadas = false, int $limite = 0): array
{
    $sql = 'SELECT * FROM meu_notas
             WHERE user_id = ? AND arquivada = ?
             ORDER BY fixada DESC, updated_at DESC';
    if ($limite > 0) {
        $sql .= ' LIMIT ' . (int) $limite;
    }
    return DB::query($sql, [meu_uid(), $arquivadas ? 1 : 0]);
}

/**
 * Carrega um registro do usuário, ou null.
 * $tabela é sempre literal no código chamador — nunca vem de request.
 */
function meu_registro(string $tabela, int $id): ?array
{
    if (!in_array($tabela, ['meu_eventos', 'meu_notas', 'meu_tarefas'], true)) {
        return null;
    }
    return DB::queryOne("SELECT * FROM {$tabela} WHERE id = ? AND user_id = ?", [$id, meu_uid()]);
}

/** Exclui um registro do usuário. Devolve true se algo saiu. */
function meu_excluir(string $tabela, int $id): bool
{
    if (!in_array($tabela, ['meu_eventos', 'meu_notas', 'meu_tarefas'], true)) {
        return false;
    }
    return DB::execute("DELETE FROM {$tabela} WHERE id = ? AND user_id = ?", [$id, meu_uid()]) > 0;
}

/** Situação de prazo de uma tarefa, para a cor do selo. */
function meu_prazo_estado(?string $prazo): string
{
    if (!$prazo) {
        return 'sem';
    }
    $hoje = date('Y-m-d');
    if ($prazo < $hoje)  { return 'vencida'; }
    if ($prazo === $hoje) { return 'hoje'; }
    if ($prazo <= date('Y-m-d', strtotime('+3 days'))) { return 'proxima'; }
    return 'futura';
}

/**
 * Data por extenso em português — "segunda-feira, 16 de setembro de 2026".
 *
 * Escrita à mão porque strftime() está depreciado desde o PHP 8.1 e
 * IntlDateFormatter depende da extensão intl, que falta em boa parte das
 * hospedagens compartilhadas — exatamente onde este sistema roda.
 */
function meu_data_extenso(int $ts): string
{
    $dias = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira',
             'quinta-feira', 'sexta-feira', 'sábado'];
    $meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
              'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    return $dias[(int) date('w', $ts)] . ', ' . (int) date('j', $ts)
         . ' de ' . $meses[(int) date('n', $ts) - 1] . ' de ' . date('Y', $ts);
}

// ── Etapa 2: solicitações e formulários ────────────────────────────────────
//
// A regra de escopo MUDA aqui, e é a mudança mais importante do módulo. Nas
// três primeiras telas valia "toda consulta filtra por meu_uid()", porque
// cada linha tinha um dono só. Uma solicitação tem DUAS pontas — quem pediu e
// quem recebeu —, e um formulário tem dono e respondentes. Então o predicado
// passa a ser "eu sou uma das pontas", e ele vai no WHERE de toda leitura e
// de toda escrita.
//
// Por isso estas funções NÃO reaproveitam meu_registro()/meu_excluir(): a
// allowlist de lá casa por user_id, que não descreve nenhuma das duas
// relações. Reusar aquilo daria ou uma solicitação recebida que o
// destinatário não consegue abrir, ou — pior — uma que ele consegue e não
// deveria.

/** Situações possíveis de uma solicitação, com rótulo e cor do selo. */
function meu_situacoes(): array
{
    return [
        'pendente'  => ['Pendente',  'text-bg-warning'],
        'aceita'    => ['Aceita',    'text-bg-info'],
        'recusada'  => ['Recusada',  'text-bg-secondary'],
        'concluida' => ['Concluída', 'text-bg-success'],
        'cancelada' => ['Cancelada', 'text-bg-light border'],
    ];
}

/**
 * Carrega uma solicitação SOMENTE se o usuário for uma das duas pontas.
 *
 * Carregar pelo id e decidir depois o que mostrar é o erro clássico: basta
 * um campo escapar do if para vazar. Aqui o pertencimento está no WHERE, de
 * modo que um id alheio simplesmente não devolve linha.
 */
function meu_solicitacao(int $id): ?array
{
    $uid = meu_uid();
    return DB::queryOne(
        'SELECT s.*, r.name AS remetente_nome, d.name AS destinatario_nome, f.titulo AS formulario_titulo
           FROM meu_solicitacoes s
           JOIN users r ON r.id = s.remetente_id
           JOIN users d ON d.id = s.destinatario_id
           LEFT JOIN meu_formularios f ON f.id = s.formulario_id
          WHERE s.id = ? AND (s.remetente_id = ? OR s.destinatario_id = ?)',
        [$id, $uid, $uid]
    );
}

/** Solicitações recebidas ($papel='recebidas') ou enviadas ($papel='enviadas'). */
function meu_solicitacoes(string $papel = 'recebidas', bool $incluirFechadas = false): array
{
    $uid   = meu_uid();
    $campo = $papel === 'enviadas' ? 's.remetente_id' : 's.destinatario_id';
    $outro = $papel === 'enviadas' ? 'd.name' : 'r.name';
    $filtro = $incluirFechadas ? '' : " AND s.situacao IN ('pendente','aceita')";

    return DB::query(
        "SELECT s.*, {$outro} AS contraparte, f.titulo AS formulario_titulo
           FROM meu_solicitacoes s
           JOIN users r ON r.id = s.remetente_id
           JOIN users d ON d.id = s.destinatario_id
           LEFT JOIN meu_formularios f ON f.id = s.formulario_id
          WHERE {$campo} = ?{$filtro}
          ORDER BY FIELD(s.situacao,'pendente','aceita','concluida','recusada','cancelada'),
                   (s.prazo IS NULL), s.prazo, s.created_at DESC",
        [$uid]
    );
}

/** Quantas solicitações recebidas estão esperando resposta. */
function meu_solicitacoes_pendentes(): int
{
    $r = DB::queryOne(
        "SELECT COUNT(*) c FROM meu_solicitacoes WHERE destinatario_id = ? AND situacao = 'pendente'",
        [meu_uid()]
    );
    return (int) ($r['c'] ?? 0);
}

/**
 * Busca pessoas para escolher como destinatário.
 *
 * Duas travas que não são detalhe: só usuários ATIVOS (a tabela guarda
 * desligados, e um pedido para quem saiu do hospital nunca seria lido), e
 * escape dos curingas do LIKE — sem ele, digitar "%" lista a empresa inteira.
 */
function meu_pessoas(string $busca = '', int $limite = 20): array
{
    $uid = meu_uid();
    if (trim($busca) === '') {
        return DB::query(
            'SELECT id, name, job_title, sector FROM users WHERE active = 1 AND id <> ? ORDER BY name LIMIT ' . (int) $limite,
            [$uid]
        );
    }
    $t = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($busca)) . '%';
    return DB::query(
        'SELECT id, name, job_title, sector FROM users
          WHERE active = 1 AND id <> ? AND (name LIKE ? OR username LIKE ? OR sector LIKE ?)
          ORDER BY name LIMIT ' . (int) $limite,
        [$uid, $t, $t, $t]
    );
}

/** O id de usuário veio do POST: confirme que existe e está ativo. */
function meu_usuario_valido(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    return DB::queryOne('SELECT id, name FROM users WHERE id = ? AND active = 1', [$id]);
}

/** Tipos de campo de formulário — os mesmos das pesquisas do RH. */
function meu_tipos_campo(): array
{
    return [
        'text'     => 'Texto curto',
        'textarea' => 'Texto longo',
        'choice'   => 'Escolha única',
        'multiple' => 'Múltipla escolha',
        'yes_no'   => 'Sim / Não',
        'number'   => 'Número',
        'date'     => 'Data',
        'rating'   => 'Nota de 1 a 5',
    ];
}

/** Formulário do usuário, com os campos. Só o DONO carrega por esta função. */
function meu_formulario(int $id, bool $doDono = true): ?array
{
    $sql = 'SELECT f.*, u.name AS dono_nome FROM meu_formularios f JOIN users u ON u.id = f.user_id WHERE f.id = ?';
    $p   = [$id];
    if ($doDono) {
        $sql .= ' AND f.user_id = ?';
        $p[]  = meu_uid();
    }
    $f = DB::queryOne($sql, $p);
    if ($f === null) {
        return null;
    }
    $f['campos'] = DB::query('SELECT * FROM meu_formulario_campos WHERE formulario_id = ? ORDER BY ordem, id', [$id]);
    // Quantas solicitações já nasceram dele: é o que decide se os campos
    // ainda podem ser editados (ver meu_formulario_congelado).
    $r = DB::queryOne('SELECT COUNT(*) c FROM meu_solicitacoes WHERE formulario_id = ?', [$id]);
    $f['usos'] = (int) ($r['c'] ?? 0);
    return $f;
}

/**
 * Já houve resposta? Então os campos congelam.
 *
 * Sem esta trava, editar um formulário já respondido apaga campos cujos ids
 * estão referenciados nas respostas: o que sobra passa a responder perguntas
 * que não existem mais, e não há como saber o que a pessoa quis dizer.
 */
function meu_formulario_congelado(array $formulario): bool
{
    return (int) ($formulario['usos'] ?? 0) > 0;
}

/** Monta os campos a partir do POST do construtor. */
function meu_campos_do_post(array $post): array
{
    $tipos = meu_tipos_campo();
    $out   = [];
    $ordem = 0;
    foreach ($post['campos'] ?? [] as $c) {
        $rotulo = trim((string) ($c['rotulo'] ?? ''));
        if ($rotulo === '') {
            continue;   // linha em branco do construtor: ignorada, não é erro
        }
        $tipo = isset($tipos[$c['tipo'] ?? '']) ? (string) $c['tipo'] : 'text';
        $ops  = [];
        if (in_array($tipo, ['choice', 'multiple'], true)) {
            foreach (explode("\n", (string) ($c['opcoes'] ?? '')) as $o) {
                $o = trim($o);
                if ($o !== '') {
                    $ops[] = mb_substr($o, 0, 200);
                }
            }
            if ($ops === []) {
                // Escolha sem opção nenhuma vira texto: um <select> vazio é
                // um campo que o usuário não consegue preencher.
                $tipo = 'text';
            }
        }
        $out[] = [
            'rotulo'      => mb_substr($rotulo, 0, 500),
            'tipo'        => $tipo,
            'opcoes'      => $ops ? json_encode($ops, JSON_UNESCAPED_UNICODE) : null,
            'obrigatorio' => !empty($c['obrigatorio']) ? 1 : 0,
            'ajuda'       => mb_substr(trim((string) ($c['ajuda'] ?? '')), 0, 255) ?: null,
            'ordem'       => $ordem++,
        ];
    }
    return $out;
}

/**
 * Valida uma resposta contra o campo. Devolve [nota, texto] ou null se o
 * campo obrigatório ficou vazio.
 */
function meu_valida_resposta(array $campo, mixed $bruto): ?array
{
    $ops = $campo['opcoes'] ? (json_decode((string) $campo['opcoes'], true) ?: []) : [];

    switch ($campo['tipo']) {
        case 'rating':
        case 'number':
            $v = is_scalar($bruto) ? trim((string) $bruto) : '';
            if ($v === '') { break; }
            $n = (int) $v;
            if ($campo['tipo'] === 'rating') {
                $n = max(1, min(5, $n));
            }
            return [$n, (string) $n];

        case 'multiple':
            $sel = array_values(array_intersect(is_array($bruto) ? $bruto : [], $ops));
            if ($sel === []) { break; }
            return [null, implode(' · ', $sel)];

        case 'choice':
            $v = is_scalar($bruto) ? (string) $bruto : '';
            if ($v === '' || !in_array($v, $ops, true)) { break; }
            return [null, $v];

        case 'yes_no':
            $v = is_scalar($bruto) ? (string) $bruto : '';
            if ($v !== 'sim' && $v !== 'nao') { break; }
            return [$v === 'sim' ? 1 : 0, $v === 'sim' ? 'Sim' : 'Não'];

        case 'date':
            $v = is_scalar($bruto) ? trim((string) $bruto) : '';
            if ($v === '' || !strtotime($v)) { break; }
            return [null, date('Y-m-d', (int) strtotime($v))];

        default:
            $v = is_scalar($bruto) ? trim((string) $bruto) : '';
            if ($v === '') { break; }
            return [null, mb_substr($v, 0, 5000)];
    }

    return null;
}

// ── Etapa 3: caixa de e-mail pessoal ───────────────────────────────────────
//
// A SENHA NUNCA É GRAVADA. Ela vive em $_SESSION e morre com a sessão.
// O motivo está no comentário da tabela meu_email_contas; o resumo é que a
// senha de aplicativo do Zoho contorna o 2FA e nunca expira, então guardá-la
// transformaria o hospital em custodiante da chave da caixa de cada pessoa —
// com o banco e a chave de cifra morando no mesmo servidor.

/** Chave da senha na sessão, por usuário (uma sessão pode trocar de conta). */
function meu_email_chave_sessao(): string
{
    return '_meu_email_senha_' . meu_uid();
}

/** Configuração da caixa do usuário, ou null. */
function meu_email_conta(): ?array
{
    return DB::queryOne('SELECT * FROM meu_email_contas WHERE user_id = ?', [meu_uid()]);
}

/** A senha desta sessão, ou '' se ainda não foi digitada. */
function meu_email_senha(): string
{
    return (string) ($_SESSION[meu_email_chave_sessao()] ?? '');
}

function meu_email_destrancar(string $senha): void
{
    $_SESSION[meu_email_chave_sessao()] = $senha;
}

function meu_email_trancar(): void
{
    unset($_SESSION[meu_email_chave_sessao()]);
}

/**
 * Abre a conexão IMAP com a conta do usuário e a senha da sessão.
 * Quem chamar é responsável por fechar.
 */
function meu_email_conectar(array $conta): Core\ImapCliente
{
    $senha = meu_email_senha();
    if ($senha === '') {
        throw new RuntimeException('A senha desta sessão não foi informada.');
    }
    $c = new Core\ImapCliente(
        (string) $conta['host'], (int) $conta['porta'], (string) $conta['seguranca'], 20
    );
    $c->conectar();
    $c->autenticar((string) $conta['usuario'], $senha);
    return $c;
}

/**
 * Assunto de resposta: "Re:" só uma vez, em qualquer capitalização, e
 * reconhecendo também o "Res:" que clientes em português usam.
 */
function meu_email_assunto_resposta(string $assunto): string
{
    $limpo = (string) preg_replace('/^\s*(re|res|rv|enc|fwd?)\s*:\s*/iu', '', $assunto);
    return 'Re: ' . mb_substr(trim($limpo), 0, 180);
}

/** Citação do original, no formato que todo cliente de e-mail entende. */
function meu_email_citar(array $msg): string
{
    $quando = $msg['data'] !== '' ? date('d/m/Y \à\s H:i', (int) strtotime($msg['data'])) : '';
    $quem   = $msg['de']['nome'] !== '' ? $msg['de']['nome'] : $msg['de']['email'];
    $linhas = preg_split('/\r\n|\n|\r/', trim((string) $msg['texto'])) ?: [];
    // Corta a citação: responder a uma mensagem que já tem dez respostas
    // dentro geraria um e-mail de páginas.
    if (count($linhas) > 40) {
        $linhas = array_slice($linhas, 0, 40);
        $linhas[] = '[...]';
    }
    return "\n\nEm " . $quando . ', ' . $quem . " escreveu:\n> "
         . implode("\n> ", $linhas);
}
