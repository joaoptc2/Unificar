<?php
/**
 * Extrato mensal da escala — folha imprimível (A4 paisagem), sem o layout
 * do portal. Renderizado por View::renderRaw().
 */
$iniciais = ['1' => 'S', '2' => 'T', '3' => 'Q', '4' => 'Q', '5' => 'S', '6' => 'S', '7' => 'D'];
$titulo = 'Escala de plantão — ' . $mesRotulo . ($deptNome !== '' ? ' — ' . $deptNome : '');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title><?= Sanitize::e($titulo) ?></title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #1a1a1a; margin: 0; padding: 10px 14px; font-size: 11px; }
        .cab { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid #0d5c8f; padding-bottom: 6px; margin-bottom: 8px; }
        .cab h1 { font-size: 15px; margin: 0; color: #0d5c8f; }
        .cab .sub { color: #555; font-size: 11px; }
        .legenda { margin: 6px 0 10px; font-size: 10px; }
        .legenda span { margin-right: 14px; }
        .k { display: inline-block; width: 15px; height: 15px; line-height: 15px; text-align: center; border-radius: 3px; font-weight: 700; color: #fff; }
        .k.p { background: #0d6efd; } .k.f { background: #f0ad00; color:#222; } .k.a { background: #9aa2ab; color:#222; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #b9c2cc; text-align: center; padding: 1px; height: 18px; overflow: hidden; }
        th.nome, td.nome { text-align: left; width: 150px; padding-left: 4px; white-space: nowrap; font-weight: normal; }
        thead th { background: #eef3f7; font-size: 9px; line-height: 1.05; }
        .num { font-weight: 700; display:block; } .dow { color:#667; font-size:8px; display:block; }
        .fds { background: #f2f4f6; }
        td.cel { font-weight: 700; font-size: 10px; }
        td.p { background: #0d6efd; color: #fff; }
        td.f { background: #ffd24d; color: #222; }
        td.a { background: #c7ccd2; color: #222; }
        td.tot, th.tot { width: 22px; background: #f6f8fa; font-weight: 700; }
        .rodape { margin-top: 8px; font-size: 9px; color: #666; display:flex; justify-content:space-between; }
        .noprint { margin: 8px 0; }
        @media print { .noprint { display: none; } }
    </style>
</head>
<body>
    <div class="cab">
        <div>
            <h1><?= Sanitize::e(Core\Branding::name()) ?></h1>
            <div class="sub">Escala de plantão · <?= Sanitize::e($mesRotulo) ?> · <?= $deptNome !== '' ? Sanitize::e($deptNome) : 'Todos os setores' ?></div>
        </div>
        <div class="sub">Emitido em <?= date('d/m/Y H:i') ?></div>
    </div>

    <div class="legenda">
        <span><i class="k p">P</i> Plantão</span>
        <span><i class="k f">F</i> Férias</span>
        <span><i class="k a">A</i> Afastado</span>
        <span>célula vazia = folga</span>
    </div>

    <div class="noprint">
        <button onclick="window.print()">Imprimir</button>
    </div>

    <?php if (empty($employees)): ?>
        <p>Nenhum funcionário ativo neste setor para <?= Sanitize::e($mesRotulo) ?>.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th class="nome">Funcionário</th>
                <?php for ($dia = 1; $dia <= $diasNoMes; $dia++):
                    $dow = (int) date('N', (int) strtotime(sprintf('%s-%02d', $mes, $dia)));
                    $fds = $dow >= 6; ?>
                    <th class="<?= $fds ? 'fds' : '' ?>"><span class="num"><?= $dia ?></span><span class="dow"><?= $iniciais[(string) $dow] ?></span></th>
                <?php endfor; ?>
                <th class="tot">P</th><th class="tot">F</th><th class="tot">A</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($employees as $e): $eid = (int) $e['id']; ?>
                <tr>
                    <td class="nome"><?= Sanitize::e($e['full_name']) ?><?= $e['status'] === 'afastado' ? ' *' : '' ?></td>
                    <?php for ($dia = 1; $dia <= $diasNoMes; $dia++):
                        $cel = $grade[$eid][$dia] ?? null;
                        $dow = (int) date('N', (int) strtotime(sprintf('%s-%02d', $mes, $dia)));
                        $fds = $dow >= 6;
                        if ($cel === null) {
                            echo '<td class="cel ' . ($fds ? 'fds' : '') . '"></td>';
                        } elseif ($cel['tipo'] === 'P') {
                            echo '<td class="cel p" title="' . Sanitize::e(trim(($cel['tpl'] ?: '') . ' ' . $cel['ini'] . '-' . $cel['fim'])) . '">P</td>';
                        } elseif ($cel['tipo'] === 'F') {
                            echo '<td class="cel f">F</td>';
                        } else {
                            echo '<td class="cel a">A</td>';
                        }
                    endfor; ?>
                    <td class="tot"><?= $totais[$eid]['P'] ?></td>
                    <td class="tot"><?= $totais[$eid]['F'] ?: '' ?></td>
                    <td class="tot"><?= $totais[$eid]['A'] ?: '' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="rodape">
        <span>* funcionário afastado no período.</span>
        <span>P = plantão · F = férias · A = afastado</span>
    </div>
    <?php endif; ?>

    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
</body>
</html>
