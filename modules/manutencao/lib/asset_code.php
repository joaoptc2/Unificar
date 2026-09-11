<?php
/**
 * CÓDIGO DE IDENTIFICAÇÃO ÚNICO DO EQUIPAMENTO (asset_code)
 *
 * 12 dígitos = 11 dígitos aleatórios + 1 dígito verificador (Luhn, o mesmo
 * algoritmo de cartões/IMEI). O código é impresso nas etiquetas como
 * código de barras (Code 128 C) e QR code e abre a página de histórico do
 * equipamento (?page=equipment&action=lookup&code=...).
 *
 * Coluna: man_equipment.asset_code CHAR(12) NULL UNIQUE (migração 007).
 * Equipamentos antigos sem código recebem um automaticamente
 * (man_asset_code_ensure_all — chamado na lista de equipamentos e no cron).
 */

/** Só os dígitos (aceita "0000 0000 0000", "0000-0000-0000", etc.). */
function man_asset_code_normalize(string $code): string
{
    return preg_replace('/\D+/', '', $code) ?? '';
}

/** Dígito verificador Luhn para uma sequência de dígitos (sem o DV). */
function man_asset_code_check_digit(string $digits): int
{
    $sum    = 0;
    $len    = strlen($digits);
    $double = true; // o dígito imediatamente à esquerda do DV é dobrado
    for ($i = $len - 1; $i >= 0; $i--) {
        $d = (int) $digits[$i];
        if ($double) {
            $d *= 2;
            if ($d > 9) {
                $d -= 9;
            }
        }
        $sum   += $d;
        $double = !$double;
    }
    return (10 - ($sum % 10)) % 10;
}

/** 12 dígitos com DV Luhn correto? (espaços/pontuação são ignorados) */
function man_asset_code_valid(string $code): bool
{
    $digits = man_asset_code_normalize($code);
    if (!preg_match('/^\d{12}$/', $digits)) {
        return false;
    }
    return man_asset_code_check_digit(substr($digits, 0, 11)) === (int) $digits[11];
}

/** "123456789012" → "1234 5678 9012" (para exibição/etiquetas). */
function man_asset_code_format(string $code): string
{
    $digits = man_asset_code_normalize($code);
    if ($digits === '') {
        return '';
    }
    return trim(chunk_split($digits, 4, ' '));
}

/** Existe algum equipamento com este código? (opcionalmente ignorando um id) */
function man_asset_code_exists(string $digits, int $exceptId = 0): bool
{
    $st = db()->prepare("SELECT id FROM man_equipment WHERE asset_code = ? AND id <> ? LIMIT 1");
    $st->execute([$digits, $exceptId]);
    return (bool) $st->fetchColumn();
}

/**
 * Gera um código novo, válido e único (verificado no banco).
 * O primeiro dígito nunca é zero, para o número não "perder" dígitos em
 * planilhas/leitores que tratam o código como inteiro.
 */
function man_asset_code_generate(): string
{
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $digits = (string) random_int(1, 9);
        for ($i = 1; $i < 11; $i++) {
            $digits .= (string) random_int(0, 9);
        }
        $code = $digits . man_asset_code_check_digit($digits);
        if (!man_asset_code_exists($code)) {
            return $code;
        }
    }
    throw new RuntimeException('Não foi possível gerar um código de identificação único.');
}

/**
 * Garante que o equipamento tenha código: devolve o existente ou gera,
 * grava e devolve um novo. Tolera corrida (chave única): tenta de novo.
 */
function man_asset_code_ensure(int $equipmentId): string
{
    $st = db()->prepare("SELECT asset_code FROM man_equipment WHERE id = ?");
    $st->execute([$equipmentId]);
    $current = $st->fetchColumn();
    if ($current === false) {
        throw new RuntimeException('Equipamento não encontrado: #' . $equipmentId);
    }
    if (is_string($current) && man_asset_code_valid($current)) {
        return $current;
    }

    $upd = db()->prepare("UPDATE man_equipment SET asset_code = ? WHERE id = ? AND (asset_code IS NULL OR asset_code = '')");
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = man_asset_code_generate();
        try {
            $upd->execute([$code, $equipmentId]);
        } catch (PDOException $ex) {
            if (($ex->errorInfo[1] ?? 0) === 1062) { // duplicado — outra requisição usou o mesmo número
                continue;
            }
            throw $ex;
        }
        if ($upd->rowCount() > 0) {
            return $code;
        }
        // Outra requisição preencheu antes: devolve o que ficou gravado
        $st->execute([$equipmentId]);
        $saved = $st->fetchColumn();
        if (is_string($saved) && $saved !== '') {
            return $saved;
        }
    }
    throw new RuntimeException('Não foi possível atribuir código ao equipamento #' . $equipmentId);
}

/** Limite padrão de códigos atribuídos por execução (ver man_asset_code_ensure_all). */
if (!defined('MAN_ASSET_CODE_BATCH')) {
    define('MAN_ASSET_CODE_BATCH', 200);
}

/**
 * Preenche o código dos equipamentos que ainda não têm, NO MÁXIMO $limit
 * por execução. Devolve quantos foram preenchidos.
 *
 * O limite é essencial: cada equipamento custa ~3 consultas (SELECT +
 * geração + UPDATE) e a chamada acontece dentro do request da lista de
 * equipamentos — sem teto, uma base grande (milhares de equipamentos sem
 * código) faria a primeira abertura da tela demorar dezenas de segundos ou
 * estourar o tempo limite. Com o teto, cada visita converte um lote e o
 * cron do módulo (limite maior) termina o serviço em segundo plano.
 *
 * @param int $limit 0 ou negativo = sem limite (use apenas em CLI/cron).
 */
function man_asset_code_ensure_all(int $limit = MAN_ASSET_CODE_BATCH): int
{
    $sql = "SELECT id FROM man_equipment WHERE asset_code IS NULL OR asset_code = '' ORDER BY id";
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit; // inteiro forçado — seguro na interpolação
    }
    $ids = db()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $n = 0;
    foreach ($ids as $id) {
        try {
            man_asset_code_ensure((int) $id);
            $n++;
        } catch (Throwable $ex) {
            error_log('man_asset_code_ensure_all: ' . $ex->getMessage());
        }
    }
    return $n;
}

/**
 * Executa $insert($code) com um código novo e único, repetindo com outro
 * código se o banco recusar por violação da chave única de asset_code
 * (duas requisições simultâneas podem sortear o mesmo número entre a
 * verificação de unicidade e o INSERT — a checagem prévia NÃO é atômica,
 * só a chave única do banco é).
 *
 * @template T
 * @param callable(string):T $insert
 * @return T
 */
function man_asset_code_with_new_code(callable $insert, int $attempts = 5)
{
    $last = null;
    for ($i = 0; $i < max(1, $attempts); $i++) {
        $code = man_asset_code_generate();
        try {
            return $insert($code);
        } catch (PDOException $ex) {
            if (!man_asset_code_is_duplicate_error($ex)) {
                throw $ex;
            }
            $last = $ex;
        }
    }
    throw $last ?? new RuntimeException('Não foi possível gerar um código de identificação único.');
}

/** A exceção é uma violação de unicidade do asset_code (erro 1062)? */
function man_asset_code_is_duplicate_error(Throwable $ex): bool
{
    if ($ex instanceof PDOException && (int) ($ex->errorInfo[1] ?? 0) === 1062) {
        return stripos($ex->getMessage(), 'asset_code') !== false;
    }
    return false;
}

/** Quantos equipamentos ainda estão sem código (para decidir se vale chamar ensure_all). */
function man_asset_code_missing_count(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM man_equipment WHERE asset_code IS NULL OR asset_code = ''")->fetchColumn();
}

/** URL absoluta codificada no QR da etiqueta (abre a busca por código → histórico). */
function man_asset_code_url(string $code): string
{
    return core_url('index.php') . '?m=manutencao&page=equipment&action=lookup&code=' . man_asset_code_normalize($code);
}
