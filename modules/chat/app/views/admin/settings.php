<?php
/**
 * Configuração → Mensagens (exibida na Administração central).
 * POST vai para ?m=chat&page=admin&action=saveSettings e volta para
 * core_admin_url('chat', 'settings').
 */
$canEdit = core_can('settings.edit');

// Atalhos comuns + o valor atual, caso tenha sido ajustado para outro número.
$opcoes = [0 => 'Não permitir excluir', 30 => '30 segundos', 60 => '1 minuto',
           120 => '2 minutos', 300 => '5 minutos', 900 => '15 minutos',
           3600 => '1 hora', 86400 => '24 horas'];
if (!isset($opcoes[$deleteWindow])) {
    $opcoes[$deleteWindow] = $deleteWindow . ' segundos';
    ksort($opcoes);
}
?>
<div class="page-header">
    <h1 class="h4"><i class="bi bi-chat-dots me-2"></i>Mensagens</h1>
</div>
<p class="text-muted">
    Regras que valem para todas as conversas do módulo Comunicação.
</p>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-trash me-1"></i>Exclusão de mensagens
            </div>
            <div class="card-body">
                <p class="small text-muted">
                    Depois do prazo abaixo a mensagem passa a ser histórico da conversa: apagá-la
                    reescreveria o que as outras pessoas já leram. O botão de excluir desaparece
                    sozinho quando o tempo acaba, e o servidor recusa a exclusão mesmo que alguém
                    tente por fora da tela.
                </p>

                <form method="POST" action="index.php?m=chat&page=admin&action=saveSettings">
                    <?= Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="chatDelWindow">Prazo para excluir a própria mensagem</label>
                        <select name="delete_window_seconds" id="chatDelWindow" class="form-select"
                                <?= $canEdit ? '' : 'disabled' ?>>
                            <?php foreach ($opcoes as $seg => $rotulo): ?>
                                <option value="<?= (int) $seg ?>" <?= (int) $seg === (int) $deleteWindow ? 'selected' : '' ?>>
                                    <?= Sanitize::e($rotulo) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Contado a partir do envio. O padrão do portal é 1 minuto.</div>
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch"
                               name="moderator_bypass" id="chatModBypass" value="1"
                               <?= $modBypass ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                        <label class="form-check-label" for="chatModBypass">
                            Moderadores podem excluir a qualquer momento
                        </label>
                        <div class="form-text">
                            Desligado, o prazo vale para todo mundo — inclusive para quem tem a permissão
                            <code>chat.moderate</code>. Ligue apenas se for preciso remover conteúdo impróprio
                            depois do prazo; a exclusão continua registrada na auditoria.
                        </div>
                    </div>

                    <?php if ($canEdit): ?>
                    <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar</button>
                    <?php else: ?>
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="bi bi-info-circle me-1"></i>Você pode consultar estas regras, mas não alterá-las
                        (permissão <code>settings.edit</code>).
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle me-1"></i>Como funciona</div>
            <div class="card-body small text-muted">
                <ul class="mb-0 ps-3">
                    <li class="mb-2">O prazo é medido pelo relógio do banco de dados, o mesmo que gravou a mensagem.</li>
                    <li class="mb-2">Editar a própria mensagem continua permitido sem prazo — a mensagem fica marcada como <em>(editada)</em>.</li>
                    <li class="mb-2">Excluir não apaga a linha: a mensagem some da conversa e o registro fica na auditoria.</li>
                    <li>"Não permitir excluir" bloqueia a exclusão para todos, inclusive moderadores.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
