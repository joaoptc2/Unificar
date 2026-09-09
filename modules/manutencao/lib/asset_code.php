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

/** Preenche o código de TODOS os equipamentos sem código. Devolve quantos foram preenchidos. */
function man_asset_code_ensure_all(): int
{
    $ids = db()->query("SELECT id FROM man_equipment WHERE asset_code IS NULL OR asset_code = '' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
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
