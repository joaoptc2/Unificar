<?php
/**
 * Tela do chat — renderizada DENTRO do layout do núcleo (topbar + sidebar
 * do módulo). A lista de canais/mensagens diretas é o painel esquerdo do
 * próprio conteúdo; à direita, o painel de thread/membros/fixados.
 *
 * Variáveis: $user, $channels, $currentChannel, $partner, $messages,
 * $members, $memberCount, $pinnedCount, $canManage, $customEmojis.
 */
$currentUserId = (int) ($user['id'] ?? Session::userId());
$currentId     = (int) ($currentChannel['id'] ?? 0);
$canModerate   = core_can('chat.moderate');
$canEdit       = core_can('chat.edit');
$canDelete     = core_can('chat.delete');
$canCreate     = core_can('chat.create');
$orgName       = View::appName();

// Agrupamento do painel esquerdo: favoritos, canais por categoria, DMs
$favorites = [];
$byCategory = [];
$uncategorized = [];
$dms = [];
foreach ($channels as $ch) {
    if ($ch['type'] === 'direct') {
        $dms[] = $ch;
        continue;
    }
    if (!empty($ch['is_favorite'])) {
        $favorites[] = $ch;
    }
    if (!empty($ch['category_name'])) {
        $byCategory[$ch['category_name']][] = $ch;
    } else {
        $uncategorized[] = $ch;
    }
}

/** Item da lista de canais/DMs. */
$renderItem = static function (array $ch) use ($currentId): void {
    $isDm    = $ch['type'] === 'direct';
    $active  = (int) $ch['id'] === $currentId;
    $unread  = (int) ($ch['unread_count'] ?? 0);
    $label   = $isDm ? ($ch['partner_name'] ?? 'Usuário') : $ch['name'];
    ?>
    <a href="index.php?m=chat&page=chat&channel_id=<?= (int) $ch['id'] ?>"
       class="channel-item <?= $active ? 'active' : '' ?> <?= $unread ? 'has-unread' : '' ?>"
       data-channel-id="<?= (int) $ch['id'] ?>" <?= $isDm && !empty($ch['partner_id']) ? 'data-partner-id="' . (int) $ch['partner_id'] . '"' : '' ?>>
        <?php if ($isDm): ?>
            <span class="dm-status <?= Sanitize::e($ch['partner_status'] ?? 'offline') ?>"></span>
        <?php else: ?>
            <i class="bi bi-<?= $ch['type'] === 'private' ? 'lock' : 'hash' ?>"></i>
        <?php endif; ?>
        <span class="channel-name"><?= Sanitize::e($label) ?></span>
        <span class="badge-unread" <?= $unread ? '' : 'hidden' ?>><?= $unread ?></span>
    </a>
    <?php
};
?>
<div class="chat-wrapper" id="chatWrapper"
     data-channel-id="<?= $currentId ?: '' ?>"
     data-can-moderate="<?= $canModerate ? 1 : 0 ?>"
     data-can-edit="<?= $canEdit ? 1 : 0 ?>"
     data-can-delete="<?= $canDelete ? 1 : 0 ?>"
     data-user-name="<?= Sanitize::e($user['name'] ?? '') ?>">

    <!-- ============================================================ -->
    <!-- PAINEL ESQUERDO — canais e mensagens diretas                 -->
    <!-- ============================================================ -->
    <aside class="chat-sidebar" id="chatSidebar" aria-label="Canais e conversas">
        <div class="sidebar-user">
            <div class="user-avatar-sm <?= Sanitize::e($user['status'] ?? 'offline') ?>" id="selfAvatar">
                <?php if (!empty($user['avatar'])): ?>
                    <img src="<?= Sanitize::e(Upload::url((string) $user['avatar'])) ?>" alt="">
                <?php else: ?>
                    <span class="avatar-initials"><?= Sanitize::e(User::initials($user['name'] ?? '?')) ?></span>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <span class="user-name"><?= Sanitize::e($user['name'] ?? '') ?></span>
                <select id="userStatusSelect" class="user-status-select" aria-label="Meu status">
                    <option value="online" <?= ($user['status'] ?? '') === 'online' ? 'selected' : '' ?>>Disponível</option>
                    <option value="away"   <?= ($user['status'] ?? '') === 'away'   ? 'selected' : '' ?>>Ausente</option>
                    <option value="dnd"    <?= ($user['status'] ?? '') === 'dnd'    ? 'selected' : '' ?>>Não perturbe</option>
                </select>
            </div>
            <button type="button" class="btn btn-sm btn-icon d-lg-none" id="closeSidebar" aria-label="Fechar lista"><i class="bi bi-x-lg"></i></button>
        </div>

        <div class="sidebar-search">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" placeholder="Filtrar conversas" id="sidebarSearch" aria-label="Filtrar conversas">
            </div>
        </div>

        <nav class="sidebar-nav">
            <?php if ($favorites): ?>
            <div class="nav-section" data-section="favorites">
                <div class="nav-section-header"><span class="section-title"><i class="bi bi-star-fill"></i> Favoritos</span></div>
                <?php foreach ($favorites as $ch) { $renderItem($ch); } ?>
            </div>
            <?php endif; ?>

            <div class="nav-section">
                <div class="nav-section-header">
                    <span class="section-title"><i class="bi bi-hash"></i> Canais</span>
                    <span class="section-actions">
                        <a href="index.php?m=chat&page=channels&action=browse" class="btn-icon" title="Explorar canais"><i class="bi bi-compass"></i></a>
                        <?php if (core_can('channels.create')): ?>
                        <a href="index.php?m=chat&page=channels&action=create" class="btn-icon" title="Criar canal"><i class="bi bi-plus-lg"></i></a>
                        <?php endif; ?>
                    </span>
                </div>
                <?php foreach ($uncategorized as $ch) { $renderItem($ch); } ?>
                <?php foreach ($byCategory as $catName => $list): ?>
                    <div class="category-header"><?= Sanitize::e($catName) ?></div>
                    <?php foreach ($list as $ch) { $renderItem($ch); } ?>
                <?php endforeach; ?>
                <?php if (!$uncategorized && !$byCategory): ?>
                    <div class="sidebar-empty">Você ainda não participa de nenhum canal.</div>
                <?php endif; ?>
            </div>

            <div class="nav-section">
                <div class="nav-section-header">
                    <span class="section-title"><i class="bi bi-person"></i> Mensagens diretas</span>
                    <span class="section-actions">
                        <button type="button" class="btn-icon" id="newDmBtn" title="Nova mensagem direta"><i class="bi bi-plus-lg"></i></button>
                    </span>
                </div>
                <?php foreach ($dms as $ch) { $renderItem($ch); } ?>
                <?php if (!$dms): ?>
                    <div class="sidebar-empty">Nenhuma conversa direta.</div>
                <?php endif; ?>
            </div>
        </nav>
    </aside>

    <!-- ============================================================ -->
    <!-- ÁREA PRINCIPAL — mensagens do canal atual                    -->
    <!-- ============================================================ -->
    <section class="chat-main" id="chatMain">
        <?php if ($currentChannel): ?>
        <?php $isDm = $currentChannel['type'] === 'direct'; ?>
        <div class="channel-header">
            <button type="button" class="btn btn-sm btn-icon d-lg-none me-1" id="openSidebar" aria-label="Abrir lista de conversas"><i class="bi bi-list"></i></button>
            <div class="channel-header-info">
                <?php if ($isDm): ?>
                    <h2 class="channel-title">
                        <span class="dm-status <?= Sanitize::e($partner['status'] ?? 'offline') ?>" id="headerPresence" data-partner-id="<?= (int) ($partner['id'] ?? 0) ?>"></span>
                        <?= Sanitize::e($partner['name'] ?? 'Mensagem direta') ?>
                    </h2>
                    <?php if (!empty($partner['title'])): ?>
                        <span class="channel-meta"><?= Sanitize::e($partner['title']) ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <h2 class="channel-title">
                        <i class="bi bi-<?= $currentChannel['type'] === 'private' ? 'lock' : 'hash' ?> me-1"></i><?= Sanitize::e($currentChannel['name']) ?>
                    </h2>
                    <span class="channel-meta"><?= Sanitize::e($currentChannel['topic'] ?: ($currentChannel['description'] ?: '')) ?></span>
                <?php endif; ?>
            </div>
            <div class="channel-header-actions">
                <button type="button" class="btn-icon <?= !empty($currentChannel['is_favorite']) ? 'is-favorite' : '' ?>" id="toggleFavorite" title="Favorito">
                    <i class="bi <?= !empty($currentChannel['is_favorite']) ? 'bi-star-fill' : 'bi-star' ?>"></i>
                </button>
                <button type="button" class="btn-icon" id="togglePinPanel" title="Mensagens fixadas">
                    <i class="bi bi-pin-angle"></i><span class="badge-count" id="pinnedCount" <?= $pinnedCount ? '' : 'hidden' ?>><?= (int) $pinnedCount ?></span>
                </button>
                <button type="button" class="btn-icon" id="toggleMembersPanel" title="Membros">
                    <i class="bi bi-people"></i><span class="badge-count"><?= (int) ($memberCount ?? 0) ?></span>
                </button>
                <?php if (!$isDm): ?>
                <div class="dropdown">
                    <button type="button" class="btn-icon" data-bs-toggle="dropdown" aria-expanded="false" title="Mais opções"><i class="bi bi-three-dots-vertical"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php if ($canManage): ?>
                        <li><a class="dropdown-item" href="index.php?m=chat&page=channels&action=edit&id=<?= $currentId ?>"><i class="bi bi-pencil me-2"></i>Editar canal</a></li>
                        <li><a class="dropdown-item" href="index.php?m=chat&page=channels&action=settings&id=<?= $currentId ?>"><i class="bi bi-gear me-2"></i>Configurações do canal</a></li>
                        <li><a class="dropdown-item" href="#" id="inviteMemberBtn"><i class="bi bi-person-plus me-2"></i>Convidar pessoas</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>
                        <?php if ((int) $currentChannel['is_general'] !== 1): ?>
                        <li>
                            <form method="POST" action="index.php?m=chat&page=channels&action=leave">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="channel_id" value="<?= $currentId ?>">
                                <button type="submit" class="dropdown-item text-danger" data-confirm="Sair deste canal?"><i class="bi bi-box-arrow-left me-2"></i>Sair do canal</button>
                            </form>
                        </li>
                        <?php else: ?>
                        <li><span class="dropdown-item-text text-muted small">Canal geral: todos participam.</span></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="messages-container" id="messagesContainer">
            <div class="messages-list" id="messagesList">
                <button type="button" class="btn btn-sm btn-outline-secondary load-older" id="loadOlderBtn" <?= count($messages) >= 50 ? '' : 'hidden' ?>>
                    <i class="bi bi-arrow-up-circle me-1"></i>Carregar mensagens anteriores
                </button>

                <div class="messages-empty" id="messagesEmpty" <?= $messages ? 'hidden' : '' ?>>
                    <div class="empty-icon"><i class="bi bi-<?= $isDm ? 'chat-heart' : 'hash' ?>"></i></div>
                    <h3><?= $isDm ? 'Início da conversa com ' . Sanitize::e($partner['name'] ?? 'Usuário') : 'Bem-vindo ao #' . Sanitize::e($currentChannel['name']) ?></h3>
                    <p class="text-muted"><?= !empty($currentChannel['description']) && !$isDm ? Sanitize::e($currentChannel['description']) : 'Envie a primeira mensagem para iniciar a conversa.' ?></p>
                </div>

                <?php
                $lastDate   = '';
                $lastUserId = -1;
                $lastTime   = 0;
                foreach ($messages as $msg):
                    $ts      = strtotime((string) $msg['created_at']);
                    $msgDate = date('Y-m-d', $ts);
                    if ($msgDate !== $lastDate):
                        $lastDate   = $msgDate;
                        $lastUserId = -1; ?>
                        <div class="message-date-divider" data-date="<?= $msgDate ?>"><span><?= Sanitize::formatDate($msgDate) ?></span></div>
                    <?php endif;

                    if (($msg['type'] ?? 'text') === 'system'): ?>
                        <div class="message-system" id="msg-<?= (int) $msg['id'] ?>" data-message-id="<?= (int) $msg['id'] ?>" data-date="<?= $msgDate ?>">
                            <i class="bi bi-info-circle me-1"></i><?= Sanitize::e((string) $msg['content']) ?>
                            <small class="text-muted ms-2"><?= date('H:i', $ts) ?></small>
                        </div>
                        <?php $lastUserId = -1; ?>
                    <?php else:
                        $compact = $lastUserId === (int) ($msg['user_id'] ?? 0) && ($ts - $lastTime) < 300;
                        View::partial('chat/_message', [
                            'msg'           => $msg,
                            'currentUserId' => $currentUserId,
                            'canModerate'   => $canModerate,
                            'canEdit'       => $canEdit,
                            'canDelete'     => $canDelete,
                            'compact'       => $compact,
                        ]);
                        $lastUserId = (int) ($msg['user_id'] ?? 0);
                        $lastTime   = $ts;
                    endif;
                endforeach; ?>
            </div>

            <div class="typing-indicator" id="typingIndicator" hidden>
                <div class="typing-dots"><span></span><span></span><span></span></div>
                <span id="typingText">Alguém está digitando...</span>
            </div>
        </div>

        <?php
        // Sem chat.create não há envio; canal somente leitura exige chat.moderate.
        $readonly = !$canCreate || ((int) ($currentChannel['is_readonly'] ?? 0) === 1 && !$canModerate);
        ?>
        <div class="message-input-area">
            <?php if ($readonly): ?>
                <div class="readonly-notice">
                    <i class="bi bi-lock-fill me-2"></i>
                    <?= !$canCreate ? 'Você não tem permissão para enviar mensagens.' : 'Este canal está em modo somente leitura.' ?>
                </div>
            <?php else: ?>
                <form id="messageForm" enctype="multipart/form-data" autocomplete="off">
                    <input type="hidden" name="channel_id" value="<?= $currentId ?>">
                    <input type="hidden" name="parent_id" id="replyParentId" value="">
                    <div id="replyPreview" class="reply-preview" hidden>
                        <span id="replyText"></span>
                        <button type="button" class="btn-close btn-close-sm" id="cancelReply" aria-label="Cancelar resposta"></button>
                    </div>
                    <div class="input-row">
                        <button type="button" class="btn-icon" id="attachBtn" title="Anexar arquivo"><i class="bi bi-paperclip"></i></button>
                        <input type="file" id="fileInput" name="attachment" hidden accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.txt,.csv,.md">
                        <div class="message-input-wrapper">
                            <textarea id="messageInput" name="content" class="message-input" rows="1" autofocus
                                      placeholder="<?= $isDm ? 'Mensagem para ' . Sanitize::e($partner['name'] ?? '') : 'Mensagem para #' . Sanitize::e($currentChannel['name']) ?>"></textarea>
                        </div>
                        <button type="button" class="btn-icon" id="emojiBtn" title="Emoji"><i class="bi bi-emoji-smile"></i></button>
                        <button type="submit" class="btn btn-primary btn-sm btn-send" id="sendBtn" title="Enviar (Enter)"><i class="bi bi-send-fill"></i></button>
                    </div>
                    <div id="attachPreview" class="attach-preview" hidden></div>
                    <div class="composer-hint">Enter envia · Shift+Enter quebra linha · @nome menciona · @canal / @here avisam a todos</div>
                </form>

                <div class="emoji-picker" id="emojiPicker" hidden>
                    <div class="emoji-picker-header">
                        <input type="text" class="form-control form-control-sm" id="emojiSearch" placeholder="Buscar emoji..." aria-label="Buscar emoji">
                    </div>
                    <div class="emoji-picker-body" id="emojiPickerBody">
                        <?php
                        $emojiList = [
                            '👍' => 'joinha positivo', '👎' => 'negativo', '❤️' => 'coracao amor', '😂' => 'riso', '😮' => 'surpresa',
                            '😢' => 'triste', '🎉' => 'festa comemorar', '🚀' => 'foguete', '👀' => 'olhos', '✅' => 'check ok feito',
                            '❌' => 'erro nao', '🔥' => 'fogo', '💯' => 'cem', '🙏' => 'obrigado', '👏' => 'palmas', '💪' => 'forca',
                            '🤔' => 'pensando', '😍' => 'apaixonado', '🎯' => 'alvo', '⚡' => 'raio', '📌' => 'pin', '💡' => 'ideia',
                            '📎' => 'clipe', '🔔' => 'sino', '⏰' => 'relogio', '📅' => 'calendario', '✨' => 'brilho', '🌟' => 'estrela',
                            '😀' => 'sorriso', '😅' => 'suor', '😉' => 'piscar', '😎' => 'oculos', '🙌' => 'maos', '🤝' => 'aperto de mao',
                            '☕' => 'cafe', '🍕' => 'pizza', '🎂' => 'bolo aniversario', '🏆' => 'trofeu', '📣' => 'megafone', '🛠️' => 'ferramentas',
                        ];
                        foreach ($emojiList as $e => $kw): ?>
                            <button type="button" class="emoji-item" data-emoji="<?= $e ?>" data-keywords="<?= Sanitize::e($kw) ?>" title="<?= Sanitize::e($kw) ?>"><?= $e ?></button>
                        <?php endforeach; ?>
                        <?php foreach ($customEmojis ?? [] as $ce): ?>
                            <button type="button" class="emoji-item emoji-custom" data-emoji=":<?= Sanitize::e($ce['name']) ?>:" data-keywords="<?= Sanitize::e($ce['name']) ?>" title=":<?= Sanitize::e($ce['name']) ?>:">
                                <img src="<?= Sanitize::e($ce['url']) ?>" alt=":<?= Sanitize::e($ce['name']) ?>:" class="custom-emoji-img">
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mention-dropdown" id="mentionDropdown" hidden>
                    <div id="mentionResults"></div>
                </div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <div class="no-channel-selected">
            <button type="button" class="btn btn-sm btn-icon d-lg-none mb-3" id="openSidebar" aria-label="Abrir lista de conversas"><i class="bi bi-list fs-4"></i></button>
            <div class="empty-state">
                <i class="bi bi-chat-dots display-1 text-muted"></i>
                <h3 class="h4 mt-3">Comunicação — <?= Sanitize::e($orgName) ?></h3>
                <p class="text-muted">Selecione um canal ou inicie uma conversa direta.</p>
                <div class="d-flex gap-2 mt-3 justify-content-center flex-wrap">
                    <a href="index.php?m=chat&page=channels&action=browse" class="btn btn-primary btn-sm"><i class="bi bi-hash me-1"></i> Explorar canais</a>
                    <?php if (core_can('channels.create')): ?>
                    <a href="index.php?m=chat&page=channels&action=create" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Criar canal</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <!-- ============================================================ -->
    <!-- PAINEL DIREITO — thread / membros / fixados                  -->
    <!-- ============================================================ -->
    <aside class="chat-panel" id="chatPanel" hidden>
        <div class="panel-header">
            <h3 id="panelTitle" class="h6 mb-0">Thread</h3>
            <button type="button" class="btn-icon" id="closePanel" aria-label="Fechar painel"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="panel-body" id="panelBody"></div>
    </aside>
</div>

<script type="application/json" id="chatCustomEmojis"><?= json_encode(array_values($customEmojis ?? []), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

<!-- Nova mensagem direta -->
<div class="modal fade" id="newDmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Nova mensagem direta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <input type="text" class="form-control mb-3" id="dmUserSearch" placeholder="Buscar pessoa por nome ou e-mail..." autocomplete="off">
                <div id="dmUserResults" class="user-results-list"></div>
            </div>
        </div>
    </div>
</div>

<!-- Convidar para o canal -->
<div class="modal fade" id="inviteMemberModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-people me-2"></i>Convidar para o canal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <input type="text" class="form-control mb-3" id="inviteUserSearch" placeholder="Buscar pessoa por nome ou e-mail..." autocomplete="off">
                <div id="inviteUserResults" class="user-results-list"></div>
            </div>
        </div>
    </div>
</div>
