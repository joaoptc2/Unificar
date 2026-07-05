/**
 * TeamChat — Real-time Chat Engine
 */
class TeamChat {
    constructor() {
        this.baseUrl = document.querySelector('meta[name="base-url"]')?.content || '';
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        this.userId = parseInt(document.querySelector('meta[name="user-id"]')?.content || '0');
        this.container = document.getElementById('messagesContainer');
        this.currentChannelId = this.container?.dataset.channelId || null;
        this.lastMessageId = 0;
        this.pollTimer = null;
        this.heartbeatTimer = null;
        this.emojiTarget = null;
        this.POLL_ACTIVE = 1500;
        this.POLL_IDLE = 8000;
        this.pollSpeed = this.POLL_ACTIVE;
        this._pendingMsgId = 0;
        this.init();
    }

    init() {
        this.findLastMessageId();
        this.bindEvents();
        if (this.currentChannelId) {
            this.startPolling();
            this.scrollToBottom();
            this.loadReactions();
            this.markCurrentChannelRead();
        }
        this.startHeartbeat();
        this.initPresence();
        this.initNotificationSound();
        this.initBrowserNotifications();
        this.initTypingIndicator();
        this.initDragDrop();
        this.initUnreadBar();
        this.processAllMessageContent();
    }

    initPresence() {
        this.api('userStatus', 'POST', { status: 'online' });
        document.addEventListener('visibilitychange', () => {
            const hidden = document.hidden;
            this.api('userStatus', 'POST', { status: hidden ? 'away' : 'online' });
            const sel = document.getElementById('userStatusSelect');
            if (sel) sel.value = hidden ? 'away' : 'online';
            this.pollSpeed = hidden ? this.POLL_IDLE : this.POLL_ACTIVE;
            this.startPolling();
        });
        window.addEventListener('beforeunload', () => {
            navigator.sendBeacon((this.baseUrl ? this.baseUrl + '/' : '') + 'index.php?m=chat&page=api&action=userStatus', new URLSearchParams({
                status: 'offline', _csrf_token: this.csrfToken
            }));
        });
    }

    // ---- Fix 8: Notification sound ----
    initNotificationSound() {
        try {
            const actx = new (window.AudioContext || window.webkitAudioContext)();
            this.playSound = () => {
                const o = actx.createOscillator();
                const g = actx.createGain();
                o.connect(g); g.connect(actx.destination);
                o.type = 'sine'; o.frequency.value = 880;
                g.gain.value = 0.08;
                g.gain.exponentialRampToValueAtTime(0.001, actx.currentTime + 0.3);
                o.start(actx.currentTime); o.stop(actx.currentTime + 0.3);
            };
        } catch(e) { this.playSound = () => {}; }
    }

    // ---- #4: Browser push notifications ----
    initBrowserNotifications() {
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }
    notifyBrowser(title, body) {
        if ('Notification' in window && Notification.permission === 'granted' && document.hidden) {
            new Notification(title, { body: body });
        }
    }

    // ---- #5: Typing indicator ----
    initTypingIndicator() {
        this._typingTimeout = null;
        this._isTyping = false;
        const input = document.getElementById('messageInput');
        if (!input || !this.currentChannelId) return;
        input.addEventListener('input', () => {
            if (!this._isTyping) {
                this._isTyping = true;
                this.api('typing', 'POST', { channel_id: this.currentChannelId, typing: '1' });
            }
            clearTimeout(this._typingTimeout);
            this._typingTimeout = setTimeout(() => {
                this._isTyping = false;
                this.api('typing', 'POST', { channel_id: this.currentChannelId, typing: '0' });
            }, 3000);
        });
    }

    // ---- #21: Drag and drop file upload ----
    initDragDrop() {
        const container = this.container;
        if (!container) return;
        container.addEventListener('dragover', e => { e.preventDefault(); container.classList.add('drag-over'); });
        container.addEventListener('dragleave', () => container.classList.remove('drag-over'));
        container.addEventListener('drop', e => {
            e.preventDefault();
            container.classList.remove('drag-over');
            const files = e.dataTransfer?.files;
            if (files?.length) {
                const fi = document.getElementById('fileInput');
                if (fi) { fi.files = files; this.showAttachPreview({ target: fi }); }
            }
        });
    }

    // ---- #25: Unread messages bar ----
    initUnreadBar() {
        if (!this.container) return;
        this._lastScrollTop = this.container.scrollTop;
        this.container.addEventListener('scroll', () => {
            const bar = document.getElementById('unreadBar');
            if (bar && this.container.scrollTop >= this.container.scrollHeight - this.container.clientHeight - 50) {
                bar.style.display = 'none';
            }
        });
    }
    showUnreadBar(count) {
        let bar = document.getElementById('unreadBar');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'unreadBar';
            bar.className = 'unread-bar';
            bar.addEventListener('click', () => { this.scrollToBottom(); bar.style.display = 'none'; });
            this.container?.parentNode?.insertBefore(bar, this.container.nextSibling);
        }
        bar.innerHTML = `<i class="bi bi-arrow-down me-1"></i> ${count} nova${count > 1 ? 's' : ''} mensage${count > 1 ? 'ns' : 'm'}`;
        bar.style.display = 'flex';
    }

    // ---- #6 + #20: Process message content (links, images) ----
    processAllMessageContent() {
        document.querySelectorAll('.message-content').forEach(el => this.enrichContent(el));
    }
    enrichContent(el) {
        if (el.dataset.enriched) return;
        el.dataset.enriched = '1';
        let html = el.innerHTML;
        html = html.replace(/(https?:\/\/[^\s<]+)/gi, (url) => {
            const lower = url.toLowerCase();
            if (/\.(jpg|jpeg|png|gif|webp)(\?.*)?$/i.test(lower)) {
                return `<a href="${url}" target="_blank" class="msg-image-link"><img src="${url}" class="msg-image-preview" alt="imagem" loading="lazy"></a>`;
            }
            return `<a href="${url}" target="_blank" rel="noopener" class="msg-link">${url}</a>`;
        });
        el.innerHTML = html;
        el.querySelectorAll('.msg-link').forEach(a => this.fetchLinkPreview(a));
    }
    async fetchLinkPreview(anchor) {
        const url = anchor.href;
        try {
            const data = await this.api('linkPreview', 'GET', { url: url });
            if (data?.title) {
                const preview = document.createElement('div');
                preview.className = 'link-preview';
                preview.innerHTML = `
                    ${data.image ? `<img src="${this.esc(data.image)}" class="link-preview-img" alt="">` : ''}
                    <div class="link-preview-text">
                        <div class="link-preview-title">${this.esc(data.title)}</div>
                        ${data.description ? `<div class="link-preview-desc">${this.esc(data.description.substring(0, 150))}</div>` : ''}
                        <div class="link-preview-url">${this.esc(data.domain || '')}</div>
                    </div>`;
                anchor.parentNode.insertBefore(preview, anchor.nextSibling);
            }
        } catch(e) {}
    }

    findLastMessageId() {
        const msgs = document.querySelectorAll('[data-message-id]');
        if (msgs.length) {
            this.lastMessageId = parseInt(msgs[msgs.length - 1].dataset.messageId) || 0;
        }
    }

    // ---- API ----
    async api(action, method, data) {
        let qs = 'm=chat&page=api&action=' + encodeURIComponent(action);

        if (method === 'GET' && data) {
            Object.keys(data).forEach(k => { qs += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); });
        }

        const url = (this.baseUrl ? this.baseUrl + '/' : '') + 'index.php?' + qs;
        const opts = { method: method || 'GET', headers: {} };

        if (method !== 'GET') {
            if (data instanceof FormData) {
                data.append('_csrf_token', this.csrfToken);
                opts.body = data;
            } else if (data) {
                data['_csrf_token'] = this.csrfToken;
                opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
                opts.body = new URLSearchParams(data).toString();
            }
            opts.headers['X-CSRF-TOKEN'] = this.csrfToken;
        }

        try {
            const res = await fetch(url, opts);
            if (res.status === 401) { window.location.href = (this.baseUrl ? this.baseUrl + '/' : '') + 'index.php?m=auth&a=login'; return null; }
            return await res.json();
        } catch (e) {
            console.error('API error:', e);
            return null;
        }
    }

    // ---- EVENTS ----
    bindEvents() {
        const form = document.getElementById('messageForm');
        if (form) form.addEventListener('submit', e => { e.preventDefault(); this.sendMessage(); });

        const input = document.getElementById('messageInput');
        if (input) {
            input.addEventListener('keydown', e => {
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.sendMessage(); }
            });
            input.addEventListener('input', () => this.autoGrow(input));
            input.addEventListener('input', () => this.checkMention(input));
        }

        document.getElementById('attachBtn')?.addEventListener('click', () => {
            document.getElementById('fileInput')?.click();
        });
        document.getElementById('fileInput')?.addEventListener('change', e => this.showAttachPreview(e));
        document.getElementById('cancelReply')?.addEventListener('click', () => this.cancelReply());

        document.getElementById('emojiBtn')?.addEventListener('click', e => {
            e.stopPropagation();
            this.emojiTarget = null;
            this.toggleEmojiPicker();
        });

        document.getElementById('emojiPicker')?.addEventListener('click', e => {
            const btn = e.target.closest('.emoji-item');
            if (!btn) return;
            const emoji = btn.dataset.emoji;
            if (this.emojiTarget) {
                this.toggleReaction(this.emojiTarget, emoji);
                this.hideEmojiPicker();
            } else {
                this.insertAtCursor(document.getElementById('messageInput'), emoji);
                this.hideEmojiPicker();
            }
        });

        document.addEventListener('click', e => {
            const picker = document.getElementById('emojiPicker');
            if (picker && !picker.contains(e.target) && !e.target.closest('#emojiBtn')) {
                this.hideEmojiPicker();
            }
            const mention = document.getElementById('mentionDropdown');
            if (mention && !mention.contains(e.target)) mention.style.display = 'none';
        });

        const msgList = document.getElementById('messagesList');
        if (msgList) {
            msgList.addEventListener('click', e => {
                const btn = e.target.closest('[data-action]');
                if (!btn) return;
                const action = btn.dataset.action;
                const msgId = btn.dataset.messageId;
                e.preventDefault();
                if (action === 'react') { e.stopPropagation(); this.emojiTarget = msgId; this.toggleEmojiPicker(); }
                else if (action === 'thread') this.openThread(msgId);
                else if (action === 'pin') this.pinMessage(msgId);
                else if (action === 'edit-msg') this.editMessage(msgId);
                else if (action === 'delete-msg') this.deleteMessage(msgId);
                else if (action === 'quote') this.quoteReply(msgId);
            });
            msgList.addEventListener('click', e => {
                const link = e.target.closest('.thread-link');
                if (link) this.openThread(link.dataset.messageId);
            });
        }

        document.getElementById('toggleMembersPanel')?.addEventListener('click', () => this.showMembersPanel());
        document.getElementById('togglePinPanel')?.addEventListener('click', () => this.showPinnedPanel());
        document.getElementById('closePanel')?.addEventListener('click', () => this.closePanel());

        document.getElementById('userStatusSelect')?.addEventListener('change', e => {
            this.api('userStatus', 'POST', { status: e.target.value });
        });

        document.getElementById('openSidebar')?.addEventListener('click', () => {
            document.getElementById('chatSidebar')?.classList.add('open');
        });
        document.getElementById('closeSidebar')?.addEventListener('click', () => {
            document.getElementById('chatSidebar')?.classList.remove('open');
        });

        document.getElementById('newDmBtn')?.addEventListener('click', () => {
            new bootstrap.Modal(document.getElementById('newDmModal')).show();
        });

        document.getElementById('dmUserSearch')?.addEventListener('input', e => this.searchUsersForDm(e.target.value));
        document.getElementById('inviteUserSearch')?.addEventListener('input', e => this.searchUsersForInvite(e.target.value));

        document.querySelector('[data-action="invite-member"]')?.addEventListener('click', e => {
            e.preventDefault();
            new bootstrap.Modal(document.getElementById('inviteMemberModal')).show();
        });

        document.getElementById('sidebarSearch')?.addEventListener('input', e => this.filterSidebar(e.target.value));
    }

    // ---- SEND MESSAGE (optimistic UI) ----
    async sendMessage() {
        const input = document.getElementById('messageInput');
        const content = input?.value.trim();
        const fileInput = document.getElementById('fileInput');
        const file = fileInput?.files[0];

        if (!content && !file) return;

        const userName = document.querySelector('.user-name')?.textContent || '';
        const tempId = 'tmp-' + (++this._pendingMsgId);

        // Optimistic: show message immediately
        if (content) {
            this.appendMessage({
                id: tempId,
                user_id: this.userId,
                user_name: userName,
                user_avatar: '',
                content: content,
                type: 'text',
                reply_count: 0,
                created_at: new Date().toISOString(),
                _pending: true,
            });
        }

        input.value = '';
        input.style.height = 'auto';
        this.cancelReply();
        const attachPreview = document.getElementById('attachPreview');
        if (attachPreview) attachPreview.style.display = 'none';
        this.scrollToBottom();

        const fd = new FormData();
        fd.append('channel_id', this.currentChannelId);
        fd.append('content', content || '');

        const parentId = document.getElementById('replyParentId')?.value;
        if (parentId) fd.append('parent_id', parentId);
        if (file) fd.append('attachment', file);

        const data = await this.api('sendMessage', 'POST', fd);

        // Replace temp message with real one
        const tempEl = document.getElementById('msg-' + tempId);
        if (data?.success && data.message) {
            if (tempEl) {
                tempEl.id = 'msg-' + data.message.id;
                tempEl.dataset.messageId = data.message.id;
                tempEl.classList.remove('message-pending');
            }
            this.lastMessageId = Math.max(this.lastMessageId, parseInt(data.message.id));
        } else if (tempEl) {
            tempEl.classList.add('message-failed');
            tempEl.querySelector('.message-content').innerHTML += '<br><small class="text-danger">Falha ao enviar. Clique para reenviar.</small>';
        }

        if (fileInput) fileInput.value = '';
    }

    // ---- APPEND MESSAGE ----
    appendMessage(msg) {
        const list = document.getElementById('messagesList');
        if (!list) return;

        const empty = list.querySelector('.messages-empty');
        if (empty) empty.remove();

        // System messages (Fix 7)
        if (msg.type === 'system') {
            const sys = document.createElement('div');
            sys.className = 'message-system-highlight';
            sys.innerHTML = `<i class="bi bi-info-circle-fill me-2"></i>${this.esc(msg.content)}`;
            list.appendChild(sys);
            this.lastMessageId = Math.max(this.lastMessageId, parseInt(msg.id));
            return;
        }

        const isMine = parseInt(msg.user_id) === this.userId;
        const initials = (msg.user_name || '?').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();

        const div = document.createElement('div');
        div.className = 'message' + (isMine ? ' message-mine' : '') + (msg._pending ? ' message-pending' : '');
        div.id = 'msg-' + msg.id;
        div.dataset.messageId = msg.id;
        div.dataset.userId = msg.user_id;

        const time = new Date(msg.created_at).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

        let avatarHtml = '';
        if (msg.user_avatar) {
            avatarHtml = `<img src="${this.esc(msg.user_avatar)}" alt="" class="avatar-img">`;
        } else {
            avatarHtml = `<span class="avatar-initials">${this.esc(initials)}</span>`;
        }

        const replyCount = parseInt(msg.reply_count) || 0;
        const threadHtml = replyCount > 0
            ? `<button class="thread-link" data-message-id="${msg.id}"><i class="bi bi-chat-right-text me-1"></i>${replyCount} resposta${replyCount > 1 ? 's' : ''}</button>`
            : '';

        let actionsHtml = `
            <button class="btn-action-sm" data-action="react" data-message-id="${msg.id}" title="Reagir"><i class="bi bi-emoji-smile"></i></button>
            <button class="btn-action-sm" data-action="quote" data-message-id="${msg.id}" title="Citar"><i class="bi bi-quote"></i></button>
            <button class="btn-action-sm" data-action="thread" data-message-id="${msg.id}" title="Thread"><i class="bi bi-chat-right-text"></i></button>`;

        let menuItems = '';
        if (isMine) {
            menuItems += `<li><a class="dropdown-item" href="#" data-action="edit-msg" data-message-id="${msg.id}"><i class="bi bi-pencil me-2"></i>Editar</a></li>`;
            menuItems += `<li><a class="dropdown-item text-danger" href="#" data-action="delete-msg" data-message-id="${msg.id}"><i class="bi bi-trash me-2"></i>Excluir</a></li>`;
        }
        if (menuItems) {
            actionsHtml += `<div class="dropdown d-inline"><button class="btn-action-sm" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button><ul class="dropdown-menu dropdown-menu-end">${menuItems}</ul></div>`;
        }

        div.innerHTML = `
            <div class="message-avatar">${avatarHtml}</div>
            <div class="message-body">
                <div class="message-header">
                    <span class="message-author">${this.esc(msg.user_name || 'Removido')}</span>
                    <span class="message-time">${time}</span>
                </div>
                <div class="message-content">${this.esc(msg.content).replace(/\n/g, '<br>')}</div>
                ${threadHtml}
                <div class="message-reactions" id="reactions-${msg.id}"></div>
            </div>
            <div class="message-actions">${actionsHtml}</div>`;

        list.appendChild(div);
        this.lastMessageId = Math.max(this.lastMessageId, parseInt(msg.id));
        const contentEl = div.querySelector('.message-content');
        if (contentEl) this.enrichContent(contentEl);
    }

    // ---- POLLING ----
    startPolling() {
        if (this.pollTimer) clearInterval(this.pollTimer);
        this.pollTimer = setInterval(() => this.pollMessages(), this.pollSpeed);
    }

    async pollMessages() {
        if (!this.currentChannelId) return;
        const data = await this.api('getMessages', 'GET', {
            channel_id: this.currentChannelId,
            after_id: this.lastMessageId
        });
        if (!data) return;

        // 1. New messages
        let newCount = 0;
        if (data.messages?.length) {
            data.messages.forEach(msg => {
                if (!document.getElementById('msg-' + msg.id)) {
                    this.appendMessage(msg);
                    if (parseInt(msg.user_id) !== this.userId) newCount++;
                }
            });
        }

        // 2. Deleted messages — remove from DOM
        if (data.deleted?.length) {
            data.deleted.forEach(id => {
                document.getElementById('msg-' + id)?.remove();
            });
        }

        // 3. Edited messages — update content in DOM
        if (data.edited?.length) {
            data.edited.forEach(e => {
                const el = document.querySelector(`#msg-${e.id} .message-content`);
                if (el) {
                    el.innerHTML = this.esc(e.content).replace(/\n/g, '<br>');
                    this.enrichContent(el);
                    const header = document.querySelector(`#msg-${e.id} .message-header`);
                    if (header && !header.querySelector('.message-edited')) {
                        header.insertAdjacentHTML('beforeend', '<span class="message-edited">(editada)</span>');
                    }
                }
            });
        }

        // 4. Reaction updates — re-render affected messages
        if (data.reactions && Object.keys(data.reactions).length) {
            Object.keys(data.reactions).forEach(msgId => {
                this.renderReactions(msgId, data.reactions[msgId]);
            });
        }

        // 5. Sound + notifications for new messages from others
        if (newCount > 0) {
            if (this.playSound) this.playSound();
            const last = data.messages[data.messages.length - 1];
            this.notifyBrowser(last.user_name || 'Nova mensagem', last.content?.substring(0, 80) || '');
            const atBottom = this.container && (this.container.scrollHeight - this.container.scrollTop - this.container.clientHeight < 100);
            if (atBottom) this.scrollToBottom();
            else this.showUnreadBar(newCount);
        }

        // 6. Typing indicators
        const tyEl = document.getElementById('typingIndicator');
        const tyTxt = document.getElementById('typingText');
        if (tyEl && tyTxt) {
            const names = (data.typing || []).filter(t => t.user_id != this.userId).map(t => t.user_name);
            if (names.length) {
                tyTxt.textContent = names.join(', ') + (names.length > 1 ? ' estão digitando...' : ' está digitando...');
                tyEl.style.display = 'flex';
            } else {
                tyEl.style.display = 'none';
            }
        }
    }

    startHeartbeat() {
        this.heartbeatTimer = setInterval(() => this.heartbeat(), 30000);
    }

    async heartbeat() {
        const data = await this.api('heartbeat', 'GET');
        if (!data) return;
        if (data.unread) {
            Object.keys(data.unread).forEach(chId => {
                const badge = document.querySelector(`[data-channel-id="${chId}"] .badge-unread`);
                const count = data.unread[chId];
                if (badge) {
                    if (count > 0) { badge.textContent = count; badge.style.display = ''; }
                    else { badge.style.display = 'none'; }
                }
            });
        }
    }

    // ---- THREAD ----
    async openThread(messageId) {
        const data = await this.api('getThread', 'GET', { message_id: messageId });
        if (!data?.parent) return;

        const panel = document.getElementById('chatPanel');
        const body = document.getElementById('panelBody');
        document.getElementById('panelTitle').textContent = 'Thread';
        panel.style.display = 'flex';

        let html = `<div class="message mb-3 pb-3" style="border-bottom:1px solid var(--border)">
            <div class="message-avatar"><span class="avatar-initials">${this.esc(this.getInitials(data.parent.user_name))}</span></div>
            <div class="message-body">
                <div class="message-header"><span class="message-author">${this.esc(data.parent.user_name || '')}</span></div>
                <div class="message-content">${this.esc(data.parent.content).replace(/\n/g, '<br>')}</div>
            </div></div>`;

        (data.replies || []).forEach(r => {
            html += `<div class="message mb-2">
                <div class="message-avatar"><span class="avatar-initials">${this.esc(this.getInitials(r.user_name))}</span></div>
                <div class="message-body">
                    <div class="message-header"><span class="message-author">${this.esc(r.user_name || '')}</span>
                    <span class="message-time">${new Date(r.created_at).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'})}</span></div>
                    <div class="message-content">${this.esc(r.content).replace(/\n/g, '<br>')}</div>
                </div></div>`;
        });

        html += `<div class="mt-3"><textarea id="threadReplyInput" class="message-input w-100" rows="2" placeholder="Responder na thread..."></textarea>
            <button class="btn btn-primary btn-sm mt-2" id="sendThreadReply">Responder</button></div>`;

        body.innerHTML = html;

        document.getElementById('sendThreadReply')?.addEventListener('click', async () => {
            const inp = document.getElementById('threadReplyInput');
            const content = inp?.value.trim();
            if (!content) return;
            const fd = new FormData();
            fd.append('channel_id', this.currentChannelId);
            fd.append('content', content);
            fd.append('parent_id', messageId);
            fd.append('_csrf_token', this.csrfToken);
            const res = await this.api('sendMessage', 'POST', fd);
            if (res?.success) { this.openThread(messageId); }
        });
    }

    // ---- REACTIONS ----
    async loadReactions() {
        const ids = [];
        document.querySelectorAll('.message[data-message-id]').forEach(el => ids.push(el.dataset.messageId));
        if (!ids.length) return;
        const data = await this.api('getReactionsBatch', 'GET', { ids: ids.join(',') });
        if (data?.reactions) {
            Object.keys(data.reactions).forEach(msgId => {
                this.renderReactions(msgId, data.reactions[msgId]);
            });
        }
    }

    renderReactions(messageId, reactions, container) {
        if (!container) container = document.getElementById('reactions-' + messageId);
        if (!container) return;
        if (!reactions || !reactions.length) { container.innerHTML = ''; return; }
        container.innerHTML = reactions.map(r => {
            const isActive = r.user_ids && r.user_ids.split(',').includes(String(this.userId));
            return `<span class="reaction-badge ${isActive ? 'active' : ''}" data-emoji="${this.esc(r.emoji)}" data-message-id="${messageId}">${r.emoji} <small>${r.count}</small></span>`;
        }).join('');
        container.querySelectorAll('.reaction-badge').forEach(b => {
            b.addEventListener('click', () => this.toggleReaction(b.dataset.messageId, b.dataset.emoji));
        });
    }

    async toggleReaction(messageId, emoji) {
        // Optimistic: toggle visually first
        const container = document.getElementById('reactions-' + messageId);
        if (container) {
            const existing = container.querySelector(`[data-emoji="${CSS.escape(emoji)}"][data-message-id="${messageId}"]`);
            if (existing) {
                existing.classList.toggle('active');
                const small = existing.querySelector('small');
                if (small) {
                    let c = parseInt(small.textContent) || 0;
                    small.textContent = existing.classList.contains('active') ? c + 1 : Math.max(0, c - 1);
                }
            }
        }
        const data = await this.api('toggleReaction', 'POST', { message_id: messageId, emoji: emoji });
        if (data?.reactions) this.renderReactions(messageId, data.reactions);
    }

    // ---- EDIT / DELETE / PIN ----
    editMessage(messageId) {
        const el = document.querySelector(`#msg-${messageId} .message-content`);
        if (!el) return;
        const old = el.textContent;
        el.innerHTML = `<textarea class="message-input w-100" rows="2">${this.esc(old)}</textarea>
            <div class="mt-1"><button class="btn btn-primary btn-sm save-edit">Salvar</button>
            <button class="btn btn-outline-secondary btn-sm cancel-edit ms-1">Cancelar</button>
            <small class="text-muted ms-2">Esc para cancelar</small></div>`;

        const textarea = el.querySelector('textarea');
        textarea.focus();
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);

        textarea.addEventListener('keydown', e => {
            if (e.key === 'Escape') { el.innerHTML = this.esc(old).replace(/\n/g, '<br>'); this.enrichContent(el); }
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); el.querySelector('.save-edit').click(); }
        });

        el.querySelector('.save-edit').addEventListener('click', async () => {
            const val = textarea.value.trim();
            if (!val) return;
            // Optimistic: update immediately
            el.innerHTML = this.esc(val).replace(/\n/g, '<br>');
            this.enrichContent(el);
            const header = document.querySelector(`#msg-${messageId} .message-header`);
            if (header && !header.querySelector('.message-edited')) {
                header.insertAdjacentHTML('beforeend', '<span class="message-edited">(editada)</span>');
            }
            await this.api('editMessage', 'POST', { message_id: messageId, content: val });
        });
        el.querySelector('.cancel-edit').addEventListener('click', () => {
            el.innerHTML = this.esc(old).replace(/\n/g, '<br>');
            this.enrichContent(el);
        });
    }

    async deleteMessage(messageId) {
        if (!confirm('Excluir esta mensagem?')) return;
        const data = await this.api('deleteMessage', 'POST', { message_id: messageId });
        if (data?.success) {
            document.getElementById('msg-' + messageId)?.remove();
        } else {
            alert(data?.error || 'Erro ao excluir mensagem.');
        }
    }

    quoteReply(messageId) {
        const msgEl = document.querySelector(`#msg-${messageId} .message-content`);
        const authorEl = document.querySelector(`#msg-${messageId} .message-author`);
        if (!msgEl) return;
        const text = msgEl.textContent.trim();
        const author = authorEl?.textContent?.trim() || '';
        const input = document.getElementById('messageInput');
        if (input) {
            const quote = `> ${author}: ${text.substring(0, 100)}${text.length > 100 ? '...' : ''}\n`;
            input.value = quote + input.value;
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
            this.autoGrow(input);
        }
    }

    async pinMessage(messageId) {
        const data = await this.api('pinMessage', 'POST', { message_id: messageId });
        if (data?.success) {
            const msg = document.getElementById('msg-' + messageId);
            if (msg) {
                if (data.pinned) {
                    msg.classList.add('message-pinned');
                    this.showToast('Mensagem fixada.');
                } else {
                    msg.classList.remove('message-pinned');
                    this.showToast('Mensagem desafixada.');
                }
            }
        } else {
            alert(data?.error || 'Erro ao fixar mensagem.');
        }
    }
    showToast(text) {
        let t = document.getElementById('chatToast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'chatToast';
            t.className = 'chat-toast';
            document.body.appendChild(t);
        }
        t.textContent = text;
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3000);
    }

    // ---- PANELS ----
    async showMembersPanel() {
        const panel = document.getElementById('chatPanel');
        const body = document.getElementById('panelBody');
        document.getElementById('panelTitle').textContent = 'Membros';
        panel.style.display = 'flex';

        const data = await this.api('searchUsers', 'GET', { channel_id: this.currentChannelId });
        if (!data?.users) { body.innerHTML = '<p class="text-muted">Erro ao carregar.</p>'; return; }

        body.innerHTML = data.users.map(u => `
            <div class="user-result-item">
                <span class="dm-status ${this.esc(u.status)}"></span>
                <div><strong>${this.esc(u.name)}</strong><br><small class="text-muted">${this.esc(u.title || u.email)}</small></div>
            </div>`).join('');
    }

    async showPinnedPanel() {
        const panel = document.getElementById('chatPanel');
        const body = document.getElementById('panelBody');
        document.getElementById('panelTitle').textContent = 'Mensagens Fixadas';
        panel.style.display = 'flex';
        body.innerHTML = '<p class="text-muted">Carregando...</p>';

        const data = await this.api('getPinnedMessages', 'GET', { channel_id: this.currentChannelId });
        if (!data?.messages?.length) { body.innerHTML = '<p class="text-muted text-center py-4"><i class="bi bi-pin-angle display-6 d-block mb-2"></i>Nenhuma mensagem fixada.</p>'; return; }

        body.innerHTML = data.messages.map(m => `
            <div class="pinned-message-card">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="avatar-initials-sm">${this.esc(this.getInitials(m.user_name))}</span>
                    <strong class="small">${this.esc(m.user_name || '')}</strong>
                    <span class="text-muted small ms-auto">${this.esc(m.pinned_by_name ? 'Fixada por ' + m.pinned_by_name : '')}</span>
                </div>
                <div class="small">${this.esc(m.content)}</div>
            </div>`).join('');
    }

    closePanel() {
        document.getElementById('chatPanel').style.display = 'none';
    }

    // ---- MENTIONS ----
    async checkMention(input) {
        const val = input.value;
        const cursor = input.selectionStart;
        const before = val.substring(0, cursor);
        const match = before.match(/@(\w{1,20})$/);
        const dropdown = document.getElementById('mentionDropdown');
        if (!dropdown) return;

        if (!match) { dropdown.style.display = 'none'; return; }

        const query = match[1];
        const data = await this.api('searchUsers', 'GET', { query: query });
        if (!data?.users?.length) { dropdown.style.display = 'none'; return; }

        dropdown.style.display = 'block';
        document.getElementById('mentionResults').innerHTML = data.users.map(u =>
            `<div class="mention-item" data-name="${this.esc(u.name)}">
                <span class="dm-status ${this.esc(u.status)}"></span>
                <span>${this.esc(u.name)}</span>
            </div>`
        ).join('');

        dropdown.querySelectorAll('.mention-item').forEach(item => {
            item.addEventListener('click', () => {
                const name = item.dataset.name;
                const newVal = before.replace(/@\w{1,20}$/, '@' + name + ' ') + val.substring(cursor);
                input.value = newVal;
                input.focus();
                dropdown.style.display = 'none';
            });
        });
    }

    // ---- DM SEARCH ----
    async searchUsersForDm(query) {
        if (query.length < 2) return;
        const data = await this.api('searchUsers', 'GET', { query: query });
        const container = document.getElementById('dmUserResults');
        if (!data?.users || !container) return;

        container.innerHTML = data.users.map(u =>
            `<a href="index.php?m=chat&page=channels&action=direct&user_id=${u.id}" class="user-result-item">
                <span class="dm-status ${this.esc(u.status)}"></span>
                <div><strong>${this.esc(u.name)}</strong><br><small class="text-muted">${this.esc(u.email)}</small></div>
            </a>`).join('');
    }

    async searchUsersForInvite(query) {
        if (query.length < 2) return;
        const data = await this.api('searchUsers', 'GET', { query: query });
        const container = document.getElementById('inviteUserResults');
        if (!data?.users || !container) return;

        container.innerHTML = data.users.map(u =>
            `<div class="user-result-item invite-user-item" data-user-id="${u.id}">
                <span class="dm-status ${this.esc(u.status)}"></span>
                <div><strong>${this.esc(u.name)}</strong></div>
                <button class="btn btn-outline-primary btn-sm ms-auto">Convidar</button>
            </div>`).join('');

        container.querySelectorAll('.invite-user-item button').forEach(btn => {
            btn.addEventListener('click', async () => {
                const uid = btn.closest('.invite-user-item').dataset.userId;
                await this.api('addMember', 'POST', {
                    channel_id: this.currentChannelId, user_id: uid, _csrf_token: this.csrfToken
                });
                btn.textContent = 'Adicionado';
                btn.disabled = true;
            });
        });
    }

    // ---- HELPERS ----
    filterSidebar(query) {
        const q = query.toLowerCase();
        document.querySelectorAll('.channel-item').forEach(el => {
            const name = el.querySelector('.channel-name')?.textContent?.toLowerCase() || '';
            el.style.display = name.includes(q) ? '' : 'none';
        });
    }

    showAttachPreview(e) {
        const file = e.target.files[0];
        const preview = document.getElementById('attachPreview');
        if (!file || !preview) return;
        preview.style.display = 'flex';
        preview.innerHTML = `<div class="attach-item"><i class="bi bi-paperclip me-1"></i>${this.esc(file.name)}
            <button type="button" class="btn-close btn-close-sm ms-2" onclick="document.getElementById('fileInput').value='';this.closest('.attach-preview').style.display='none'"></button></div>`;
    }

    cancelReply() {
        document.getElementById('replyParentId').value = '';
        document.getElementById('replyPreview').style.display = 'none';
    }

    toggleEmojiPicker() {
        const p = document.getElementById('emojiPicker');
        p.style.display = p.style.display === 'none' ? 'block' : 'none';
    }
    hideEmojiPicker() { document.getElementById('emojiPicker').style.display = 'none'; }

    insertAtCursor(el, text) {
        if (!el) return;
        const start = el.selectionStart;
        el.value = el.value.substring(0, start) + text + el.value.substring(el.selectionEnd);
        el.selectionStart = el.selectionEnd = start + text.length;
        el.focus();
    }

    markCurrentChannelRead() {
        if (!this.currentChannelId) return;
        this.api('markChannelRead', 'POST', { channel_id: this.currentChannelId });
        const badge = document.querySelector(`[data-channel-id="${this.currentChannelId}"] .badge-unread`);
        if (badge) badge.style.display = 'none';
    }

    autoGrow(el) { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 150) + 'px'; }
    scrollToBottom() { const c = this.container; if (c) c.scrollTop = c.scrollHeight; }
    getInitials(name) { return (name || '?').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase(); }
    esc(str) { const d = document.createElement('div'); d.textContent = str || ''; return d.innerHTML; }
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.querySelector('.chat-wrapper')) new TeamChat();
});
