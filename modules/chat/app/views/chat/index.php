<div class="chat-wrapper">
    <!-- ============================================================ -->
    <!-- SIDEBAR ESQUERDA — Canais, DMs, Equipes                     -->
    <!-- ============================================================ -->
    <aside class="chat-sidebar" id="chatSidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <i class="bi bi-chat-dots-fill me-2"></i>
                <span>TeamChat</span>
            </div>
            <button class="btn btn-sm btn-icon sidebar-close d-lg-none" id="closeSidebar">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- User Status -->
        <div class="sidebar-user">
            <div class="user-avatar-sm <?= Sanitize::e($user['status'] ?? 'offline') ?>">
                <?php if (!empty($user['avatar'])): ?>
                    <img src="<?= BASE_URL ?>/<?= Sanitize::e($user['avatar']) ?>" alt="">
                <?php else: ?>
                    <span class="avatar-initials"><?= Sanitize::e(User::initials($user['name'])) ?></span>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <span class="user-name"><?= Sanitize::e($user['name']) ?></span>
                <div class="user-status-selector">
                    <select id="userStatusSelect" class="form-select form-select-sm">
                        <option value="online" <?= ($user['status'] ?? '') === 'online' ? 'selected' : '' ?>>Disponível</option>
                        <option value="away" <?= ($user['status'] ?? '') === 'away' ? 'selected' : '' ?>>Ausente</option>
                        <option value="dnd" <?= ($user['status'] ?? '') === 'dnd' ? 'selected' : '' ?>>Não perturbe</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Search -->
        <div class="sidebar-search">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" placeholder="Buscar (Ctrl+K)" id="sidebarSearch">
            </div>
        </div>

        <!-- Navigation -->
        <nav class="sidebar-nav">
            <div class="nav-section">
                <a href="index.php?m=chat&page=tasks" class="nav-link-item">
                    <i class="bi bi-kanban"></i> <span>Tarefas</span>
                    <?php if (!empty($taskCount)): ?>
                        <span class="badge bg-primary rounded-pill ms-auto"><?= $taskCount ?></span>
                    <?php endif; ?>
                </a>
                <a href="index.php?m=chat&page=meetings" class="nav-link-item">
                    <i class="bi bi-calendar-event"></i> <span>Reuniões</span>
                </a>
                <a href="index.php?m=chat&page=processes" class="nav-link-item">
                    <i class="bi bi-diagram-3"></i> <span>Processos</span>
                </a>
            </div>

            <!-- Channels -->
            <div class="nav-section">
                <div class="nav-section-header">
                    <button class="btn btn-sm section-toggle" data-bs-toggle="collapse" data-bs-target="#channelsList">
                        <i class="bi bi-chevron-down"></i> Canais
                    </button>
                    <?php if (core_can('channels.create')): ?>
                    <a href="index.php?m=chat&page=channels&action=create" class="btn btn-sm btn-icon" title="Criar canal">
                        <i class="bi bi-plus-lg"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <div class="collapse show" id="channelsList">
                    <?php
                    $publicChannels = array_filter($channels, fn($c) => $c['type'] !== 'direct');
                    foreach ($publicChannels as $ch):
                        $isActive = ($currentChannel['id'] ?? 0) == $ch['id'];
                        $unread = (int)($ch['unread_count'] ?? 0);
                    ?>
                    <a href="index.php?m=chat&page=chat&channel_id=<?= $ch['id'] ?>"
                       class="channel-item <?= $isActive ? 'active' : '' ?>"
                       data-channel-id="<?= $ch['id'] ?>">
                        <i class="bi bi-<?= $ch['type'] === 'private' ? 'lock' : 'hash' ?>"></i>
                        <span class="channel-name"><?= Sanitize::e($ch['name']) ?></span>
                        <?php if ($unread > 0): ?>
                            <span class="badge-unread"><?= $unread ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                    <a href="index.php?m=chat&page=channels&action=browse" class="channel-item channel-browse">
                        <i class="bi bi-plus-circle"></i> <span>Explorar canais</span>
                    </a>
                </div>
            </div>

            <!-- Direct Messages -->
            <div class="nav-section">
                <div class="nav-section-header">
                    <button class="btn btn-sm section-toggle" data-bs-toggle="collapse" data-bs-target="#dmList">
                        <i class="bi bi-chevron-down"></i> Mensagens diretas
                    </button>
                    <button class="btn btn-sm btn-icon" id="newDmBtn" title="Nova mensagem direta">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                </div>
                <div class="collapse show" id="dmList">
                    <?php
                    $dmChannels = array_filter($channels, fn($c) => $c['type'] === 'direct');
                    foreach ($dmChannels as $ch):
                        $isActive = ($currentChannel['id'] ?? 0) == $ch['id'];
                        $unread = (int)($ch['unread_count'] ?? 0);
                        $partner = Channel::dmPartner($ch['id'], $user['id']);
                    ?>
                    <a href="index.php?m=chat&page=chat&channel_id=<?= $ch['id'] ?>"
                       class="channel-item dm-item <?= $isActive ? 'active' : '' ?>"
                       data-channel-id="<?= $ch['id'] ?>">
                        <span class="dm-status <?= Sanitize::e($partner['status'] ?? 'offline') ?>"></span>
                        <span class="channel-name"><?= Sanitize::e($partner['name'] ?? 'Usuário') ?></span>
                        <?php if ($unread > 0): ?>
                            <span class="badge-unread"><?= $unread ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Teams -->
            <?php if (!empty($teams)): ?>
            <div class="nav-section">
                <div class="nav-section-header">
                    <button class="btn btn-sm section-toggle" data-bs-toggle="collapse" data-bs-target="#teamsList">
                        <i class="bi bi-chevron-down"></i> Equipes
                    </button>
                </div>
                <div class="collapse show" id="teamsList">
                    <?php foreach ($teams as $team): ?>
                    <a href="index.php?m=chat&page=teams&action=show&id=<?= $team['id'] ?>" class="channel-item">
                        <span class="team-dot" style="background:<?= Sanitize::e($team['color']) ?>"></span>
                        <span class="channel-name"><?= Sanitize::e($team['name']) ?></span>
                        <span class="text-muted small"><?= $team['member_count'] ?? 0 ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </nav>
    </aside>

    <!-- ============================================================ -->
    <!-- ÁREA PRINCIPAL — Mensagens do canal                         -->
    <!-- ============================================================ -->
    <main class="chat-main" id="chatMain">
        <?php if (!empty($currentChannel)): ?>
        <!-- Channel Header -->
        <div class="channel-header">
            <button class="btn btn-sm btn-icon d-lg-none me-2" id="openSidebar">
                <i class="bi bi-list"></i>
            </button>
            <div class="channel-header-info">
                <?php if ($currentChannel['type'] === 'direct'): ?>
                    <?php $partner = Channel::dmPartner($currentChannel['id'], $user['id']); ?>
                    <h2 class="channel-title">
                        <span class="dm-status <?= Sanitize::e($partner['status'] ?? 'offline') ?>"></span>
                        <?= Sanitize::e($partner['name'] ?? 'Usuário') ?>
                    </h2>
                    <span class="channel-meta"><?= Sanitize::e($partner['title'] ?? '') ?></span>
                <?php else: ?>
                    <h2 class="channel-title">
                        <i class="bi bi-<?= $currentChannel['type'] === 'private' ? 'lock' : 'hash' ?> me-1"></i>
                        <?= Sanitize::e($currentChannel['name']) ?>
                    </h2>
                    <?php if (!empty($currentChannel['topic'])): ?>
                        <span class="channel-meta"><?= Sanitize::e($currentChannel['topic']) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="channel-header-actions">
                <button class="btn btn-sm btn-icon" id="toggleThreadPanel" title="Threads">
                    <i class="bi bi-chat-right-text"></i>
                </button>
                <button class="btn btn-sm btn-icon" id="togglePinPanel" title="Mensagens fixadas">
                    <i class="bi bi-pin-angle"></i>
                    <?php if (!empty($pinnedCount)): ?>
                        <span class="badge-count"><?= $pinnedCount ?></span>
                    <?php endif; ?>
                </button>
                <button class="btn btn-sm btn-icon" id="toggleMembersPanel" title="Membros">
                    <i class="bi bi-people"></i>
                    <span class="badge-count"><?= $memberCount ?? 0 ?></span>
                </button>
                <?php if ($currentChannel['type'] !== 'direct'): ?>
                <div class="dropdown">
                    <button class="btn btn-sm btn-icon" data-bs-toggle="dropdown">
                        <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="index.php?m=chat&page=channels&action=edit&id=<?= $currentChannel['id'] ?>"><i class="bi bi-pencil me-2"></i>Editar canal</a></li>
                        <?php if (core_can('channels.edit')): ?>
                        <li><a class="dropdown-item" href="index.php?m=chat&page=channels&action=settings&id=<?= $currentChannel['id'] ?>"><i class="bi bi-gear me-2"></i>Configurações do canal</a></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item" href="#" data-action="invite-member"><i class="bi bi-person-plus me-2"></i>Convidar pessoas</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="index.php?m=chat&page=tasks&action=create&channel_id=<?= $currentChannel['id'] ?>"><i class="bi bi-kanban me-2"></i>Nova tarefa</a></li>
                        <li><a class="dropdown-item" href="index.php?m=chat&page=meetings&action=create&channel_id=<?= $currentChannel['id'] ?>"><i class="bi bi-calendar-plus me-2"></i>Agendar reunião</a></li>
                        <li><a class="dropdown-item" href="index.php?m=chat&page=processes&action=create&channel_id=<?= $currentChannel['id'] ?>"><i class="bi bi-diagram-3 me-2"></i>Novo processo</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="index.php?m=chat&page=channels&action=leave" class="px-3">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="channel_id" value="<?= $currentChannel['id'] ?>">
                                <button type="submit" class="dropdown-item text-danger" data-confirm="Sair deste canal?">
                                    <i class="bi bi-box-arrow-left me-2"></i>Sair do canal
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Messages Area -->
        <div class="messages-container" id="messagesContainer" data-channel-id="<?= $currentChannel['id'] ?>">
            <div class="messages-list" id="messagesList">
                <?php if (empty($messages)): ?>
                    <div class="messages-empty">
                        <div class="empty-icon">
                            <?php if ($currentChannel['type'] === 'direct'): ?>
                                <i class="bi bi-chat-heart"></i>
                            <?php else: ?>
                                <i class="bi bi-hash"></i>
                            <?php endif; ?>
                        </div>
                        <h3>
                            <?php if ($currentChannel['type'] === 'direct'): ?>
                                Início da conversa com <?= Sanitize::e($partner['name'] ?? 'Usuário') ?>
                            <?php else: ?>
                                Bem-vindo ao #<?= Sanitize::e($currentChannel['name']) ?>
                            <?php endif; ?>
                        </h3>
                        <p class="text-muted">
                            <?php if (!empty($currentChannel['description'])): ?>
                                <?= Sanitize::e($currentChannel['description']) ?>
                            <?php else: ?>
                                Envie a primeira mensagem para iniciar a conversa.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <?php
                    $lastDate = '';
                    $lastUserId = 0;
                    foreach ($messages as $msg):
                        $msgDate = date('Y-m-d', strtotime($msg['created_at']));
                        if ($msgDate !== $lastDate):
                            $lastDate = $msgDate;
                            $lastUserId = 0;
                    ?>
                        <div class="message-date-divider">
                            <span><?= Sanitize::formatDate($msgDate) ?></span>
                        </div>
                    <?php endif; ?>

                        <?php if ($msg['type'] === 'system'): ?>
                            <div class="message-system-highlight">
                                <i class="bi bi-info-circle-fill me-2"></i>
                                <?= Sanitize::e($msg['content']) ?>
                                <small class="text-muted ms-2"><?= date('H:i', strtotime($msg['created_at'])) ?></small>
                            </div>
                        <?php else:
                            $isCompact = ($lastUserId === (int)$msg['user_id'] && $msgDate === $lastDate);
                            $isMine = ((int)$msg['user_id'] === Session::userId());
                        ?>
                            <div class="message <?= $isCompact ? 'message-compact' : '' ?> <?= $isMine ? 'message-mine' : '' ?> <?= ($msg['reply_count'] ?? 0) > 0 ? 'has-thread' : '' ?>"
                                 id="msg-<?= $msg['id'] ?>" data-message-id="<?= $msg['id'] ?>" data-user-id="<?= $msg['user_id'] ?>">
                                <?php if (!$isCompact): ?>
                                <div class="message-avatar">
                                    <?php if (!empty($msg['user_avatar'])): ?>
                                        <img src="<?= BASE_URL ?>/<?= Sanitize::e($msg['user_avatar']) ?>" alt="" class="avatar-img">
                                    <?php else: ?>
                                        <span class="avatar-initials"><?= Sanitize::e(User::initials($msg['user_name'] ?? '?')) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <div class="message-body">
                                    <?php if (!$isCompact): ?>
                                    <div class="message-header">
                                        <span class="message-author"><?= Sanitize::e($msg['user_name'] ?? 'Removido') ?></span>
                                        <span class="message-time" title="<?= Sanitize::e($msg['created_at']) ?>"><?= date('H:i', strtotime($msg['created_at'])) ?></span>
                                        <?php if ($msg['is_edited']): ?>
                                            <span class="message-edited">(editada)</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php else: ?>
                                    <span class="message-time-compact" title="<?= Sanitize::e($msg['created_at']) ?>"><?= date('H:i', strtotime($msg['created_at'])) ?></span>
                                    <?php endif; ?>
                                    <div class="message-content"><?= nl2br(Sanitize::e($msg['content'])) ?></div>

                                    <?php if ($msg['reply_count'] > 0): ?>
                                    <button class="thread-link" data-message-id="<?= $msg['id'] ?>">
                                        <i class="bi bi-chat-right-text me-1"></i>
                                        <?= $msg['reply_count'] ?> resposta<?= $msg['reply_count'] > 1 ? 's' : '' ?>
                                    </button>
                                    <?php endif; ?>

                                    <div class="message-reactions" id="reactions-<?= $msg['id'] ?>"></div>
                                </div>

                                <!-- Message Actions (hover) -->
                                <div class="message-actions">
                                    <button class="btn-action-sm" data-action="react" data-message-id="<?= $msg['id'] ?>" title="Reagir">
                                        <i class="bi bi-emoji-smile"></i>
                                    </button>
                                    <button class="btn-action-sm" data-action="quote" data-message-id="<?= $msg['id'] ?>" title="Citar">
                                        <i class="bi bi-quote"></i>
                                    </button>
                                    <button class="btn-action-sm" data-action="thread" data-message-id="<?= $msg['id'] ?>" title="Responder em thread">
                                        <i class="bi bi-chat-right-text"></i>
                                    </button>
                                    <?php $canModerate = core_can('chat.moderate'); ?>
                                    <?php if ($canModerate): ?>
                                    <button class="btn-action-sm" data-action="pin" data-message-id="<?= $msg['id'] ?>" title="Fixar">
                                        <i class="bi bi-pin-angle"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php
                                    $canEdit   = $isMine && core_can('chat.edit');
                                    $canDelete = $canModerate
                                        || ($isMine && core_can('chat.delete') && (time() - strtotime($msg['created_at'])) <= 60);
                                    if ($canEdit || $canDelete):
                                    ?>
                                    <div class="dropdown d-inline">
                                        <button class="btn-action-sm" data-bs-toggle="dropdown">
                                            <i class="bi bi-three-dots"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <?php if ($canEdit): ?>
                                            <li><a class="dropdown-item" href="#" data-action="edit-msg" data-message-id="<?= $msg['id'] ?>"><i class="bi bi-pencil me-2"></i>Editar</a></li>
                                            <?php endif; ?>
                                            <?php if ($canDelete): ?>
                                            <li><a class="dropdown-item text-danger" href="#" data-action="delete-msg" data-message-id="<?= $msg['id'] ?>"><i class="bi bi-trash me-2"></i>Excluir</a></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php
                            $lastUserId = (int) $msg['user_id'];
                        endif;
                    endforeach;
                    ?>
                <?php endif; ?>
            </div>

            <!-- Typing indicator -->
            <div class="typing-indicator" id="typingIndicator" style="display:none">
                <div class="typing-dots"><span></span><span></span><span></span></div>
                <span id="typingText">Alguém está digitando...</span>
            </div>
        </div>

        <!-- Message Input -->
        <?php
        // Sem chat.create não há envio; canal somente leitura exige chat.moderate.
        $isReadonly = !core_can('chat.create')
            || ((int)($currentChannel['is_readonly'] ?? 0) === 1 && !core_can('chat.moderate'));
        ?>
        <div class="message-input-area">
            <?php if ($isReadonly): ?>
            <div class="readonly-notice">
                <i class="bi bi-lock-fill me-2"></i>Este canal está em modo somente leitura ou você não tem permissão para enviar mensagens.
            </div>
            <?php else: ?>
            <form id="messageForm" enctype="multipart/form-data">
                <input type="hidden" name="channel_id" value="<?= $currentChannel['id'] ?>">
                <input type="hidden" name="parent_id" id="replyParentId" value="">
                <div id="replyPreview" class="reply-preview" style="display:none">
                    <span id="replyText"></span>
                    <button type="button" class="btn-close btn-close-sm" id="cancelReply"></button>
                </div>
                <div class="input-row">
                    <button type="button" class="btn btn-sm btn-icon" id="attachBtn" title="Anexar arquivo">
                        <i class="bi bi-plus-circle-fill"></i>
                    </button>
                    <input type="file" id="fileInput" name="attachment" style="display:none" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.txt,.csv">
                    <div class="message-input-wrapper">
                        <textarea id="messageInput" name="content" class="message-input"
                                  placeholder="Envie uma mensagem para #<?= Sanitize::e($currentChannel['name'] ?? 'chat') ?>"
                                  rows="1" autofocus></textarea>
                    </div>
                    <div class="input-actions">
                        <button type="button" class="btn btn-sm btn-icon" id="emojiBtn" title="Emoji">
                            <i class="bi bi-emoji-smile"></i>
                        </button>
                        <button type="submit" class="btn btn-primary btn-sm btn-send" id="sendBtn">
                            <i class="bi bi-send-fill"></i>
                        </button>
                    </div>
                </div>
                <div id="attachPreview" class="attach-preview" style="display:none"></div>
            </form>

            <!-- Emoji Picker (inside input area for absolute positioning) -->
            <div class="emoji-picker" id="emojiPicker" style="display:none">
                <div class="emoji-picker-header">
                    <input type="text" class="form-control form-control-sm" id="emojiSearch" placeholder="Buscar emoji...">
                </div>
                <div class="emoji-picker-body">
                    <?php
                    $emojis = ['👍','👎','❤️','😂','😮','😢','🎉','🚀','👀','✅','❌','🔥','💯','🙏','👏','💪','🤔','😍','🎯','⚡','📌','💡','📎','🔔','⏰','📅','✨','🌟'];
                    foreach ($emojis as $emoji):
                    ?>
                    <button type="button" class="emoji-item" data-emoji="<?= $emoji ?>"><?= $emoji ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Mention Dropdown -->
            <div class="mention-dropdown" id="mentionDropdown" style="display:none">
                <div id="mentionResults"></div>
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- No Channel Selected -->
        <div class="no-channel-selected">
            <button class="btn btn-sm btn-icon d-lg-none mb-3" id="openSidebar">
                <i class="bi bi-list fs-4"></i>
            </button>
            <div class="empty-state">
                <i class="bi bi-chat-dots display-1 text-muted"></i>
                <h3 class="mt-3">Bem-vindo ao TeamChat</h3>
                <p class="text-muted">Selecione um canal ou inicie uma conversa</p>
                <div class="d-flex gap-2 mt-3">
                    <a href="index.php?m=chat&page=channels&action=browse" class="btn btn-primary">
                        <i class="bi bi-hash me-1"></i> Explorar canais
                    </a>
                    <a href="index.php?m=chat&page=channels&action=create" class="btn btn-outline-primary">
                        <i class="bi bi-plus-lg me-1"></i> Criar canal
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <!-- ============================================================ -->
    <!-- PAINEL LATERAL DIREITO — Thread, Membros, Pins              -->
    <!-- ============================================================ -->
    <aside class="chat-panel" id="chatPanel" style="display:none">
        <div class="panel-header">
            <h3 id="panelTitle">Thread</h3>
            <button class="btn btn-sm btn-icon" id="closePanel">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="panel-body" id="panelBody"></div>
    </aside>
</div>

<!-- New DM Modal -->
<div class="modal fade" id="newDmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nova mensagem direta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <input type="text" class="form-control" id="dmUserSearch" placeholder="Buscar usuário...">
                </div>
                <div id="dmUserResults" class="user-results-list"></div>
            </div>
        </div>
    </div>
</div>

<!-- Invite Member Modal -->
<div class="modal fade" id="inviteMemberModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Convidar para o canal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <input type="text" class="form-control" id="inviteUserSearch" placeholder="Buscar usuário...">
                </div>
                <div id="inviteUserResults" class="user-results-list"></div>
            </div>
        </div>
    </div>
</div>

