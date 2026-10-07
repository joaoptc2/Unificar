<?php
/** ENFERMAGEM — importar cirurgias de planilha (CSV). */

declare(strict_types=1);

use Core\Csrf;
use Core\DB;
use Core\Flash;
use Core\Layout;

core_require('ccih.import');

// ---- Download do modelo CSV ---------------------------------------------
if (($_GET['modelo'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="modelo_busca_fonada.csv"');
    echo "\xEF\xBB\xBF"; // BOM para o Excel abrir em UTF-8
    $out = fopen('php://output', 'w');
    // 5º arg ($escape='') explícito: no PHP 8.4 o padrão foi depreciado.
    fputcsv($out, ['DATA DA CIRURGIA', 'MEDICO', 'PACIENTE', 'CELULAR', 'E-MAIL', 'PROCEDIMENTO', 'COLOCOU PROTESE'], ',', '"', '');
    fputcsv($out, ['18/12/2025', 'DR. FULANO', 'MARIA DA SILVA', '31999990000', 'maria@exemplo.com', 'MAMOPLASTIA DE AUMENTO (PRÓTESE)', 'SIM'], ',', '"', '');
    fputcsv($out, ['11/12/2025', 'DRA. BELTRANA', 'JOANA SOUZA', '31988887777', '', 'ABDOMINOPLASTIA', 'NAO'], ',', '"', '');
    fclose($out);
    exit;
}

/** Normaliza um cabeçalho: sem acento, minúsculo, só letras. */
$norm = static function (string $s): string {
    $s = strtolower(trim($s));
    $map = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c'];
    $s = strtr($s, $map);
    return preg_replace('/[^a-z]/', '', $s);
};

/** dd/mm/aaaa, aaaa-mm-dd ou dd-mm-aaaa → Y-m-d, ou null. */
$parseData = static function (string $v): ?string {
    $v = trim($v);
    if ($v === '') { return null; }
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $v, $mm)) {
        return checkdate((int) $mm[2], (int) $mm[3], (int) $mm[1]) ? "{$mm[1]}-{$mm[2]}-{$mm[3]}" : null;
    }
    if (preg_match('#^(\d{1,2})[/\-](\d{1,2})[/\-](\d{2,4})#', $v, $mm)) {
        $d = (int) $mm[1]; $m = (int) $mm[2]; $y = (int) $mm[3];
        if ($y < 100) { $y += 2000; }
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }
    return null;
};

$resultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();

    if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
        Flash::set('error', 'Selecione um arquivo CSV válido.');
        core_redirect(core_module_url('enfermagem', ['page' => 'importar']));
    }
    $tmp = $_FILES['arquivo']['tmp_name'];
    if (!is_uploaded_file($tmp) || filesize($tmp) > 5 * 1024 * 1024) {
        Flash::set('error', 'Arquivo inválido ou grande demais (máx. 5 MB).');
        core_redirect(core_module_url('enfermagem', ['page' => 'importar']));
    }

    $conteudo = (string) file_get_contents($tmp);
    // Encoding: converte de Latin-1/Windows-1252 se não for UTF-8 válido.
    if (!mb_check_encoding($conteudo, 'UTF-8')) {
        $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
    }
    $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo); // tira BOM
    $linhas = preg_split('/\r\n|\r|\n/', $conteudo);
    // Delimitador: o que mais aparece no cabeçalho, entre ; e ,
    $cab = $linhas[0] ?? '';
    $delim = substr_count($cab, ';') > substr_count($cab, ',') ? ';' : ',';

    // Mapa cabeçalho → índice de coluna.
    $headers = str_getcsv($cab, $delim, '"', '');
    $idx = [];
    foreach ($headers as $i => $h) {
        $idx[$norm($h)] = $i;
    }
    $col = static function (array $row, array $idx, array $chaves) {
        foreach ($chaves as $k) {
            if (isset($idx[$k]) && isset($row[$idx[$k]])) {
                return trim((string) $row[$idx[$k]]);
            }
        }
        return '';
    };

    $inseridos = 0; $pulados = 0; $erros = [];
    $procRows  = enf_procedimentos(false);
    $procUpper = array_map(fn ($p) => mb_strtoupper((string) $p['nome']), $procRows);

    for ($n = 1; $n < count($linhas); $n++) {
        if (trim($linhas[$n]) === '') { continue; }
        $row = str_getcsv($linhas[$n], $delim, '"', '');

        $data = $parseData($col($row, $idx, ['datadacirurgia', 'datacirurgia', 'data']));
        $paciente = mb_substr($col($row, $idx, ['paciente', 'nome']), 0, 200);
        $medico = mb_substr($col($row, $idx, ['medico']), 0, 160);
        $proc = mb_substr($col($row, $idx, ['procedimento']), 0, 200);
        $celular = mb_substr(enf_celular($col($row, $idx, ['celular', 'telefone'])), 0, 20);
        $email = mb_substr($col($row, $idx, ['email', 'e-mail']), 0, 190);
        $protRaw = mb_strtolower($col($row, $idx, ['colocouprotese', 'protese', 'colocouprotesesimou nao']));
        $protese = in_array($protRaw, ['sim', 's', '1', 'true', 'x'], true) ? 1 : 0;

        if ($data === null || $paciente === '' || $medico === '' || $proc === '') {
            $pulados++;
            if (count($erros) < 15) {
                $erros[] = "Linha " . ($n + 1) . ": faltam dados obrigatórios (data/paciente/médico/procedimento).";
            }
            continue;
        }
        // Procedimento com prótese na lista força 90 dias mesmo sem coluna.
        $pIdx = array_search(mb_strtoupper($proc), $procUpper, true);
        if ($pIdx !== false && (int) $procRows[$pIdx]['protese_padrao'] === 1) {
            $protese = 1;
        }
        $prazo = enf_prazo_dias($protese === 1);

        // Dedupe: mesma pessoa + data + procedimento já existe → pula.
        $existe = DB::queryOne(
            'SELECT id FROM enf_cirurgias WHERE data_cirurgia=? AND paciente=? AND procedimento=? LIMIT 1',
            [$data, $paciente, $proc]
        );
        if ($existe) { $pulados++; continue; }

        DB::execute(
            'INSERT INTO enf_cirurgias (data_cirurgia, medico, paciente, celular, email, procedimento, protese, prazo_dias, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)',
            [$data, $medico, $paciente, $celular ?: null, $email ?: null, $proc, $protese, $prazo, enf_uid()]
        );
        if ($medico !== '') { DB::execute('INSERT IGNORE INTO enf_medicos (nome) VALUES (?)', [$medico]); }
        $inseridos++;
    }

    $resultado = ['inseridos' => $inseridos, 'pulados' => $pulados, 'erros' => $erros];
    Flash::set($inseridos > 0 ? 'success' : 'error',
        "Importação concluída: {$inseridos} cadastrada(s), {$pulados} pulada(s).");
}

ob_start(); ?>
<h1 class="h4 mb-3"><i class="bi bi-upload me-2"></i>Importar cirurgias</h1>

<?php if ($resultado): ?>
<div class="alert alert-<?= $resultado['inseridos'] > 0 ? 'success' : 'warning' ?>">
    <strong><?= (int) $resultado['inseridos'] ?></strong> cirurgia(s) importada(s),
    <strong><?= (int) $resultado['pulados'] ?></strong> pulada(s) (duplicadas ou incompletas).
    <?php if ($resultado['erros']): ?>
        <ul class="small mt-2 mb-0"><?php foreach ($resultado['erros'] as $e): ?><li><?= core_e($e) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header">Enviar arquivo CSV</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?= Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold" for="arq">Arquivo (.csv, até 5 MB)</label>
                        <input type="file" class="form-control" id="arq" name="arquivo" accept=".csv,text/csv" required>
                    </div>
                    <button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Importar</button>
                    <a class="btn btn-outline-secondary" href="<?= core_module_url('enfermagem', ['page' => 'importar', 'modelo' => 1]) ?>"><i class="bi bi-download me-1"></i>Baixar modelo</a>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-5">
        <div class="card">
            <div class="card-header">Como preparar</div>
            <div class="card-body small">
                <p>Na planilha atual, abra a aba <strong>PACIENTES</strong> e salve como <strong>CSV</strong> (ou monte um arquivo com estas colunas):</p>
                <ul class="mb-2">
                    <li>DATA DA CIRURGIA (dd/mm/aaaa)</li>
                    <li>MEDICO</li>
                    <li>PACIENTE</li>
                    <li>CELULAR (só números, com DDD)</li>
                    <li>E-MAIL (opcional)</li>
                    <li>PROCEDIMENTO</li>
                    <li>COLOCOU PROTESE (SIM/NAO)</li>
                </ul>
                <p class="mb-0 text-muted">Cadastros iguais (mesma pessoa, data e procedimento) são ignorados, então dá para reenviar sem duplicar. O prazo de vigilância e as datas de ligar são calculados sozinhos.</p>
            </div>
        </div>
    </div>
</div>
<?php
Layout::render(['title' => 'Importar', 'content' => (string) ob_get_clean(), 'active' => 'importar']);
