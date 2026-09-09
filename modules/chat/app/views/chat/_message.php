<?php
/**
 * Partial: uma mensagem do canal (renderização inicial; o JS gera o mesmo
 * HTML para mensagens novas — ver ChatApp.renderMessage em assets/chat/chat.js).
 *
 * Variáveis esperadas:
 *   $msg           linha de chat_messages + user_name/user_avatar/attachments/reactions
 *   $currentUserId int
 *   $canModerate   bool   chat.moderate (fixar / excluir de terceiros)
 *   $canEdit       bool   chat.edit   (editar as próprias)
 *   $canDelete     bool   chat.delete (excluir as próprias, até 1 min)
 *   $compact       bool   mesma pessoa em sequência (sem avatar/cabeçalho)
 */
$msgId    = (int) $msg['id'];
$isMine   = (int) ($msg['user_id'] ?? 0) === (int) $currentUserId;
$isPinned = (int) ($msg['is_pinned'] ?? 0) === 1;
$replies  = (int) ($msg['reply_count'] ?? 0);
$time     = date('H:i', strtotime((string) $msg['created_at']));
$date     = date('Y-m-d', strtotime((string) $msg['created_at']));
$mayEdit  = $isMine && $canEdit && ($msg['type'] ?? 'text') !== 'system';
$mayDel   = $canModerate || ($isMine && $canDelete);
$classes  = ['message'];
if ($compact)  { $classes[] = 'message-compact'; }
if ($isMine)   { $classes[] = 'message-mine'; }
if ($isPinned) { $classes[] = 'message-pinned'; }
if ($replies)  { $classes[] = 'has-thread'; }
?>
<div class="<?= implode(' ', $classes) ?>" id="msg-<?= $msgId ?>" data-message-id="<?= $msgId ?>"
     data-user-id="<?= (int) ($msg['user_id'] ?? 0) ?>" data-date="<?= $date ?>" data-mine="<?= $isMine ? 1 : 0 ?>">
    <div class="message-avatar">
        <?php if (!empty($msg['user_avatar'])): ?>
            <img src="<?= Sanitize::e(Upload::url((string) $msg['user_avatar'])) ?>" alt="" class="avatar-img">
        <?php else: ?>
            <span class="avatar-initials"><?= Sanitize::e(User::initials($msg['user_name'] ?? '?')) ?></span>
        <?php endif; ?>
    </div>
    <div class="message-body">
        <div class="message-header">
            <span class="message-author"><?= Sanitize::e($msg['user_name'] ?? 'Usuário removido') ?></span>
            <span class="message-time" title="<?= Sanitize::e(Sanitize::formatDateTime($msg['created_at'])) ?>"><?= $time ?></span>
            <span class="message-edited" <?= (int) ($msg['is_edited'] ?? 0) ? '' : 'hidden' ?>>(editada)</span>
            <span class="message-pin-flag" <?= $isPinned ? '' : 'hidden' ?>><i class="bi bi-pin-angle-fill"></i> fixada</span>
        </div>
        <div class="message-content" data-raw="<?= Sanitize::e((string) $msg['content']) ?>"><?= nl2br(Sanitize::e((string) $msg['content'])) ?></div>

        <?php if (!empty($msg['attachments'])): ?>
        <div class="message-attachments">
            <?php foreach ($msg['attachments'] as $att): ?>
                <?php if (!empty($att['is_image'])): ?>
                    <a href="<?= Sanitize::e($att['url']) ?>" target="_blank" rel="noopener" class="msg-image-link" title="<?= Sanitize::e($att['original_name']) ?>">
                        <img src="<?= Sanitize::e($att['url']) ?>" alt="<?= Sanitize::e($att['original_name']) ?>" class="msg-image-preview" loading="lazy">
                    </a>
                <?php else: ?>
                    <a href="<?= Sanitize::e($att['url']) ?>" target="_blank" rel="noopener" class="attachment-card" download>
                        <i class="bi bi-file-earmark-arrow-down"></i>
                        <span class="attachment-name"><?= Sanitize::e($att['original_name']) ?></span>
                        <span class="attachment-size"><?= Sanitize::e($att['size_text'] ?? '') ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <button type="button" class="thread-link" data-action="thread" data-message-id="<?= $msgId ?>" <?= $replies ? '' : 'hidden' ?>>
            <i class="bi bi-chat-right-text me-1"></i><span class="thread-count"><?= $replies ?></span> resposta<?= $replies === 1 ? '' : 's' ?>
        </button>

        <div class="message-reactions" id="reactions-<?= $msgId ?>">
            <?php foreach ($msg['reactions'] ?? [] as $r):
                $ids    = array_map('intval', explode(',', (string) ($r['user_ids'] ?? '')));
                $active = in_array((int) $currentUserId, $ids, true); ?>
                <button type="button" class="reaction-badge <?= $active ? 'active' : '' ?>" data-action="react-toggle"
                        data-emoji="<?= Sanitize::e($r['emoji']) ?>" data-message-id="<?= $msgId ?>" title="<?= Sanitize::e($r['users'] ?? '') ?>">
                    <span class="reaction-emoji"><?= Sanitize::e($r['emoji']) ?></span> <small><?= (int) $r['count'] ?></small>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="message-actions">
        <button type="button" class="btn-action-sm" data-action="react" data-message-id="<?= $msgId ?>" title="Reagir"><i class="bi bi-emoji-smile"></i></button>
        <button type="button" class="btn-action-sm" data-action="quote" data-message-id="<?= $msgId ?>" title="Citar"><i class="bi bi-quote"></i></button>
        <button type="button" class="btn-action-sm" data-action="thread" data-message-id="<?= $msgId ?>" title="Responder em thread"><i class="bi bi-chat-right-text"></i></button>
        <?php if ($canModerate): ?>
        <button type="button" class="btn-action-sm" data-action="pin" data-message-id="<?= $msgId ?>" title="<?= $isPinned ? 'Desafixar' : 'Fixar' ?>"><i class="bi bi-pin-angle"></i></button>
        <?php endif; ?>
        <?php if ($mayEdit): ?>
        <button type="button" class="btn-action-sm" data-action="edit-msg" data-message-id="<?= $msgId ?>" title="Editar"><i class="bi bi-pencil"></i></button>
        <?php endif; ?>
        <?php if ($mayDel): ?>
        <button type="button" class="btn-action-sm text-danger" data-action="delete-msg" data-message-id="<?= $msgId ?>" title="Excluir"><i class="bi bi-trash"></i></button>
        <?php endif; ?>
    </div>
</div>
