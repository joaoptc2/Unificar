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
