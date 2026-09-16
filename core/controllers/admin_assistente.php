<?php
/**
 * ADMINISTRAÇÃO › ASSISTENTE (IA)
 *
 * A tela existe tanto para configurar quanto para dizer a verdade sobre o que
 * a decisão implica: texto sai do hospital, custa dinheiro por uso, e nenhum
 * filtro automático substitui a política de quem responde pela instituição.
 */

declare(strict_types=1);

use Core\Assistente;
use Core\Audit;
use Core\Auth;
use Core\Csrf;
use Core\Flash;
use Core\MailSecret;
use Core\Settings;

function core_admin_assistente_save(): void
{
    Auth::requireGlobalAdmin();
    Csrf::check();

    $ativo = !empty($_POST['ativo']);
    $chave = trim((string) ($_POST['chave'] ?? ''));
    // Campo em branco NÃO apaga a chave: ele é exibido vazio de propósito
    // (a chave nunca volta para a tela), e um salvamento de outro campo
    // apagaria a configuração sem querer.
    if ($chave !== '') {
        Assistente::guardarChave($chave);
        Audit::log('ia.chave', 'settings', null, 'Chave da API do assistente atualizada');
    }
    if (!empty($_POST['remover_chave'])) {
        Assistente::guardarChave('');
        $ativo = false;
        Audit::log('ia.chave', 'settings', null, 'Chave da API do assistente removida');
    }

    $modelo = (string) ($_POST['modelo'] ?? '');
    Settings::set('ia.modelo', isset(Assistente::modelos()[$modelo]) ? $modelo : 'claude-opus-5');
    Settings::set('ia.teto_centavos', (string) max(0, min(1000000, (int) ($_POST['teto'] ?? 2000))));

    if ($ativo && !Assistente::temChave()) {
        Flash::set('warning', 'O assistente não foi ligado: falta a chave da API.');
        $ativo = false;
    }
    Settings::set('ia.ativo', $ativo ? '1' : '0');
    Audit::log('ia.config', 'settings', null, ['ativo' => $ativo, 'modelo' => Assistente::modelo()]);

    Flash::set('success', 'Configuração do assistente salva.');
    core_redirect('index.php?m=admin&a=assistente');
}

function core_admin_assistente_test(): void
{
    Auth::requireGlobalAdmin();
    Csrf::check();

    $r = Assistente::perguntar(
        'teste',
        'Você está sendo testado pela tela de configuração de um portal hospitalar. '
        . 'Responda em português, em uma única frase curta, confirmando que a conexão funciona.',
        'Responda apenas: a conexão está funcionando.',
        ['max_tokens' => 100]
    );
    if ($r['ok']) {
        Flash::set('success', 'Funcionou. A API respondeu: "' . mb_substr($r['texto'], 0, 160) . '" '
            . '(' . $r['tokens_in'] . ' tokens de entrada, ' . $r['tokens_out'] . ' de saída, '
            . $r['ms'] . ' ms).');
    } else {
        Flash::set('error', $r['erro']);
    }
    core_redirect('index.php?m=admin&a=assistente');
}

function core_admin_assistente(): string
{
    Auth::requireGlobalAdmin();

    $resumo = Assistente::resumoDoMes();
    $teto   = Assistente::teto();
    $pct    = $teto > 0 ? min(100, (int) round($resumo['centavos'] / $teto * 100)) : 0;

    ob_start(); ?>
    <h1 class="h4 mb-3"><i class="bi bi-stars me-2"></i>Assistente (IA)</h1>

    <div class="alert alert-warning">
        <h2 class="h6 mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Antes de ligar, entenda o que isto significa</h2>
        <ul class="mb-0 small">
            <li>O texto enviado <strong>sai do hospital</strong> e é processado pela Anthropic. Não é um
                modelo rodando aqui dentro.</li>
            <li>O sistema recusa texto com marca de CPF, cartão do SUS ou palavras de contexto
                assistencial. Isso é uma <strong>rede, não uma garantia</strong>: nenhum filtro reconhece
                um relato clínico escrito em português corrido. <strong>A política é de vocês.</strong></li>
            <li>Fica registrado <strong>que</strong> houve uma chamada, de quem, para quê e de que tamanho —
                <strong>nunca o conteúdo</strong>. Guardar o texto criaria uma segunda cópia do que se
                quer proteger.</li>
            <li>Cada uso custa. O teto abaixo é do portal, não da Anthropic: ele impede o gasto, mas a
                conta verdadeira é a do painel da Anthropic.</li>
        </ul>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header">Configuração</div>
                <div class="card-body">
                    <form method="post" action="<?= core_module_url('admin', ['a' => 'assistente_save']) ?>">
                        <?= Csrf::field() ?>
                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="ativo" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" name="ativo" id="ia_ativo"
                                   value="1" <?= Assistente::ligado() ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ia_ativo">
                                Assistente ligado
                                <?php if (!Assistente::temChave()): ?>
                                    <span class="text-muted small">— precisa da chave abaixo</span>
                                <?php endif; ?>
                            </label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold" for="ia_chave">Chave da API (Anthropic)</label>
                            <input class="form-control" id="ia_chave" name="chave" type="password" autocomplete="off"
                                   placeholder="<?= Assistente::temChave() ? '•••••••• (guardada — deixe em branco para manter)' : 'sk-ant-...' ?>">
                            <div class="form-text small">
                                Guardada cifrada com a app.key desta instalação. Nunca é exibida de volta.
                                <?php if (MailSecret::appKeyIsDefault()): ?>
                                    <br><span class="text-danger fw-semibold">A app.key ainda é a do exemplo:
                                    cifrar aqui protege pouco. Troque-a em config.php antes.</span>
                                <?php endif; ?>
                            </div>
                            <?php if (Assistente::temChave()): ?>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" name="remover_chave" id="ia_rm" value="1">
                                    <label class="form-check-label small" for="ia_rm">Remover a chave guardada</label>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-12 col-md-7">
                                <label class="form-label small fw-semibold" for="ia_modelo">Modelo</label>
                                <select class="form-select" id="ia_modelo" name="modelo">
                                    <?php foreach (Assistente::modelos() as $k => $m): ?>
                                        <option value="<?= core_e($k) ?>" <?= Assistente::modelo() === $k ? 'selected' : '' ?>>
                                            <?= core_e($m['rotulo']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text small">
                                    <?php foreach (Assistente::modelos() as $k => $m): ?>
                                        <?= core_e(explode(' —', $m['rotulo'])[0]) ?>:
                                        US$ <?= number_format($m['entrada'] / 100, 2, ',', '.') ?> /
                                        US$ <?= number_format($m['saida'] / 100, 2, ',', '.') ?> por milhão
                                        (entrada/saída)<?= $k !== array_key_last(Assistente::modelos()) ? ' · ' : '' ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12 col-md-5">
                                <label class="form-label small fw-semibold" for="ia_teto">Teto do mês (centavos de dólar)</label>
                                <input class="form-control" id="ia_teto" name="teto" type="number" min="0" max="1000000"
                                       value="<?= (int) $teto ?>">
                                <div class="form-text small">
                                    <?= $teto > 0 ? 'US$ ' . number_format($teto / 100, 2, ',', '.') . ' por mês.' : 'Sem teto — desaconselhado.' ?>
                                </div>
                            </div>
                        </div>

                        <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Salvar</button>
                    </form>

                    <?php if (Assistente::temChave()): ?>
                        <form method="post" action="<?= core_module_url('admin', ['a' => 'assistente_test']) ?>" class="mt-2">
                            <?= Csrf::field() ?>
                            <button class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-plug me-1"></i>Testar a conexão
                            </button>
                            <span class="form-text small ms-2">Gasta alguns centésimos de centavo.</span>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header">Uso deste mês</div>
                <div class="card-body">
                    <?php if ($teto > 0): ?>
                        <div class="progress mb-2" style="height:22px" role="progressbar"
                             aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"
                             aria-label="<?= $pct ?>% do teto do mês">
                            <div class="progress-bar <?= $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : '') ?>"
                                 style="width:<?= $pct ?>%"><?= $pct ?>%</div>
                        </div>
                    <?php endif; ?>
                    <dl class="row mb-0 small">
                        <dt class="col-7 fw-normal text-muted">Chamadas</dt>
                        <dd class="col-5 text-end"><?= (int) $resumo['chamadas'] ?></dd>
                        <dt class="col-7 fw-normal text-muted">Gasto estimado</dt>
                        <dd class="col-5 text-end">US$ <?= number_format($resumo['centavos'] / 100, 2, ',', '.') ?></dd>
                        <dt class="col-7 fw-normal text-muted">Tokens</dt>
                        <dd class="col-5 text-end"><?= number_format($resumo['tokens'], 0, ',', '.') ?></dd>
                        <dt class="col-7 fw-normal text-muted">Falhas</dt>
                        <dd class="col-5 text-end"><?= (int) $resumo['falhas'] ?></dd>
                    </dl>
                    <p class="form-text small mt-2 mb-0">
                        Estimativa pela tabela pública de preços. A cobrança real é a do painel da
                        Anthropic — confira lá antes de tirar conclusões sobre a fatura.
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}
