/**
 * Módulo Comunicação — motor do chat (polling, mensagens, threads,
 * reações, anexos, menções, fixados, favoritos, presença).
 *
 * Vanilla JS, sem dependências (Bootstrap do layout do núcleo é usado
 * apenas para modais/dropdowns, quando disponível).
 *
 * Endpoints: index.php?m=chat&page=api&action=<ação>  (ApiController)
 *            index.php?m=chat&page=channels&action=<ação> (membros/convite)
 */
class ChatApp {
    constructor(wrapper) {
        const meta = (n) => (document.querySelector('meta[name="' + n + '"]') || {}).content || '';

        this.wrapper   = wrapper;
        this.baseUrl   = meta('base-url');
        this.csrfToken = meta('csrf-token');
        this.userId    = parseInt(meta('user-id') || '0', 10);
        this.userName  = wrapper.dataset.userName || '';
        this.channelId = wrapper.dataset.channelId ? parseInt(wrapper.dataset.channelId, 10) : null;
        this.can = {
            moderate: wrapper.dataset.canModerate === '1',
            edit:     wrapper.dataset.canEdit === '1',
            delete:   wrapper.dataset.canDelete === '1',
        };
        this.POLL_ACTIVE = parseInt(meta('chat-poll-interval') || '2000', 10);
        this.POLL_IDLE   = parseInt(meta('chat-poll-idle') || '8000', 10);
        this.pollSpeed   = this.POLL_ACTIVE;

        this.container   = document.getElementById('messagesContainer');
        this.list        = document.getElementById('messagesList');
        this.lastMessageId = 0;
        this.pollTimer   = null;
        this.polling     = false;
        this.heartbeatTimer = null;
        this.emojiTarget = null;      // mensagem alvo do seletor (reação) ou null (compositor)
        this.pendingSeq  = 0;
        this.editing     = new Set(); // ids em edição (o polling não sobrescreve)
        this.customEmojis = this.loadCustomEmojis();
        this.audioCtx    = null;

        this.init();
    }

    /* ------------------------------------------------------------------
     *  Inicialização
     * ----------------------------------------------------------------*/
    init() {
        this.findLastMessageId();
        this.bindEvents();
        this.enrichAll();

        if (this.channelId) {
            this.scrollToBottom();
            this.markCurrentChannelRead();
            this.startPolling();
            this.initTypingIndicator();
            this.initDragDrop();
            this.initUnreadBar();
        }

        this.startHeartbeat();
        this.initPresence();
    }

    loadCustomEmojis() {
        const map = {};
        try {
            const el = document.getElementById('chatCustomEmojis');
            const list = el ? JSON.parse(el.textContent || '[]') : [];
            list.forEach(e => { if (e && e.name) map[e.name] = e.url; });
        } catch (e) { /* sem emojis personalizados */ }
        return map;
    }

    findLastMessageId() {
        const msgs = this.list ? this.list.querySelectorAll('[data-message-id]') : [];
        msgs.forEach(el => {
            const id = parseInt(el.dataset.messageId, 10);
            if (id > this.lastMessageId) this.lastMessageId = id;
        });
    }

    /* ------------------------------------------------------------------
     *  HTTP
     * ----------------------------------------------------------------*/
    url(path) {
        return (this.baseUrl ? this.baseUrl + '/' : '') + path;
    }

    async request(qs, method, data) {
        method = method || 'GET';
        let url = this.url('index.php?' + qs);
        const opts = { method, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' };

        if (method === 'GET' && data) {
            Object.keys(data).forEach(k => { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); });
        } else if (method !== 'GET') {
            opts.headers['X-CSRF-TOKEN'] = this.csrfToken;
            if (data instanceof FormData) {
                data.append('_csrf_token', this.csrfToken);
                opts.body = data;
            } else {
                const params = new URLSearchParams(data || {});
                params.append('_csrf_token', this.csrfToken);
                opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
                opts.body = params.toString();
            }
        }

        try {
            const res = await fetch(url, opts);
            if (res.status === 401) {
                window.location.href = this.url('index.php?m=auth&a=login');
                return null;
            }
            const json = await res.json().catch(() => null);
            if (json && json.success === false && !json.error && json.message) json.error = json.message;
            return json;
        } catch (e) {
            return null;
        }
    }

    api(action, method, data) {
        return this.request('m=chat&page=api&action=' + encodeURIComponent(action), method, data);
    }

    channelsApi(action, method, data) {
        return this.request('m=chat&page=channels&action=' + encodeURIComponent(action), method, data);
    }

    /* ------------------------------------------------------------------
     *  Eventos
     * ----------------------------------------------------------------*/
    bindEvents() {
        const $ = (id) => document.getElementById(id);

        $('messageForm')?.addEventListener('submit', e => { e.preventDefault(); this.sendMessage(); });

        const input = $('messageInput');
        if (input) {
            input.addEventListener('keydown', e => {
                const dd = $('mentionDropdown');
                if (dd && !dd.hidden && (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Tab')) {
                    e.preventDefault();
                    this.moveMentionSelection(e.key === 'ArrowUp' ? -1 : 1);
                    return;
                }
                if (dd && !dd.hidden && e.key === 'Enter') {
                    const sel = dd.querySelector('.mention-item.selected') || dd.querySelector('.mention-item');
                    if (sel) { e.preventDefault(); sel.click(); return; }
                }
                if (e.key === 'Escape') { this.hideMentions(); this.hideEmojiPicker(); }
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.sendMessage(); }
            });
            input.addEventListener('input', () => { this.autoGrow(input); this.checkMention(input); });
        }

        $('attachBtn')?.addEventListener('click', () => $('fileInput')?.click());
        $('fileInput')?.addEventListener('change', () => this.showAttachPreview());
        $('cancelReply')?.addEventListener('click', () => this.cancelReply());

        $('emojiBtn')?.addEventListener('click', e => {
            e.stopPropagation();
            this.emojiTarget = null;
            this.toggleEmojiPicker();
        });
        $('emojiSearch')?.addEventListener('input', e => this.filterEmojis(e.target.value));
        $('emojiPicker')?.addEventListener('click', e => {
            const btn = e.target.closest('.emoji-item');
            if (!btn) return;
            e.stopPropagation();
            const emoji = btn.dataset.emoji;
            if (this.emojiTarget) {
                this.toggleReaction(this.emojiTarget, emoji);
            } else {
                this.insertAtCursor($('messageInput'), emoji + ' ');
            }
            this.hideEmojiPicker();
        });

        document.addEventListener('click', e => {
            const picker = $('emojiPicker');
            if (picker && !picker.hidden && !picker.contains(e.target) && !e.target.closest('#emojiBtn') && !e.target.closest('[data-action="react"]')) {
                this.hideEmojiPicker();
            }
            const dd = $('mentionDropdown');
            if (dd && !dd.hidden && !dd.contains(e.target) && e.target !== $('messageInput')) this.hideMentions();
        });

        if (this.list) {
            this.list.addEventListener('click', e => {
                const btn = e.target.closest('[data-action]');
                if (!btn) return;
                const action = btn.dataset.action;
                const msgId  = btn.dataset.messageId;
                e.preventDefault();
                switch (action) {
                    case 'react':        e.stopPropagation(); this.emojiTarget = msgId; this.toggleEmojiPicker(btn); break;
                    case 'react-toggle': this.toggleReaction(msgId, btn.dataset.emoji); break;
                    case 'thread':       this.openThread(msgId); break;
                    case 'pin':          this.pinMessage(msgId); break;
                    case 'edit-msg':     this.editMessage(msgId); break;
                    case 'delete-msg':   this.deleteMessage(msgId); break;
                    case 'quote':        this.quoteReply(msgId); break;
                    case 'retry':        this.retryMessage(btn.closest('.message')); break;
                }
            });
        }

        $('loadOlderBtn')?.addEventListener('click', () => this.loadOlder());
        $('toggleMembersPanel')?.addEventListener('click', () => this.showMembersPanel());
        $('togglePinPanel')?.addEventListener('click', () => this.showPinnedPanel());
        $('closePanel')?.addEventListener('click', () => this.closePanel());
        $('toggleFavorite')?.addEventListener('click', () => this.toggleFavorite());

        $('userStatusSelect')?.addEventListener('change', e => this.setStatus(e.target.value));

        $('openSidebar')?.addEventListener('click', () => $('chatSidebar')?.classList.add('open'));
        $('closeSidebar')?.addEventListener('click', () => $('chatSidebar')?.classList.remove('open'));

        $('newDmBtn')?.addEventListener('click', () => this.openModal('newDmModal', 'dmUserSearch'));
        $('inviteMemberBtn')?.addEventListener('click', e => { e.preventDefault(); this.openModal('inviteMemberModal', 'inviteUserSearch'); });
        $('dmUserSearch')?.addEventListener('input', e => this.searchUsersForDm(e.target.value));
        $('inviteUserSearch')?.addEventListener('input', e => this.searchUsersForInvite(e.target.value));

        $('sidebarSearch')?.addEventListener('input', e => this.filterSidebar(e.target.value));

        // Âncora #msg-N (links de notificações/busca): destaca a mensagem
        if (location.hash && /^#msg-\d+$/.test(location.hash)) {
            const el = document.querySelector(location.hash);
            if (el) { setTimeout(() => { el.scrollIntoView({ block: 'center' }); el.classList.add('message-highlight'); }, 50); }
        }
    }

    openModal(modalId, focusId) {
        const el = document.getElementById(modalId);
        if (!el) return;
        if (window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(el).show();
        } else {
            el.classList.add('show', 'd-block');
        }
        setTimeout(() => document.getElementById(focusId)?.focus(), 150);
    }

    /* ------------------------------------------------------------------
     *  Presença / status
     * ----------------------------------------------------------------*/
    initPresence() {
        this.setStatus('online', true);
        document.addEventListener('visibilitychange', () => {
            const hidden = document.hidden;
            const sel = document.getElementById('userStatusSelect');
            if (!sel || sel.value !== 'dnd') {
                this.setStatus(hidden ? 'away' : 'online', true);
                if (sel) sel.value = hidden ? 'away' : 'online';
            }
            this.pollSpeed = hidden ? this.POLL_IDLE : this.POLL_ACTIVE;
            if (this.channelId) this.startPolling();
        });
        window.addEventListener('pagehide', () => {
            if (!navigator.sendBeacon) return;
            const body = new URLSearchParams({ status: 'offline', _csrf_token: this.csrfToken });
            navigator.sendBeacon(this.url('index.php?m=chat&page=api&action=userStatus'), body);
        });
    }

    async setStatus(status, silent) {
        const data = await this.api('userStatus', 'POST', { status });
        const av = document.getElementById('selfAvatar');
        if (av && data?.success) {
            av.classList.remove('online', 'away', 'dnd', 'offline');
            av.classList.add(status);
        }
        if (!silent && data?.success) this.toast('Status atualizado.');
    }

    /* ------------------------------------------------------------------
     *  Envio de mensagens (UI otimista)
     * ----------------------------------------------------------------*/
    async sendMessage() {
        const input     = document.getElementById('messageInput');
        const fileInput = document.getElementById('fileInput');
        const content   = (input?.value || '').trim();
        const file      = fileInput?.files?.[0] || null;
        if (!content && !file) return;
        if (!this.channelId) return;

        this.hideMentions();
        this.requestBrowserNotifications();

        const parentId = document.getElementById('replyParentId')?.value || '';
        const tempId   = 'tmp-' + (++this.pendingSeq);

        // Respostas de thread não aparecem na lista principal
        let tempEl = null;
        if (!parentId) {
            tempEl = this.appendMessage({
                id: tempId, user_id: this.userId, user_name: this.userName, user_avatar: '',
                content: content || (file ? file.name : ''), type: 'text', reply_count: 0,
                created_at: new Date().toISOString(), attachments: [], reactions: [], _pending: true,
            });
            this.scrollToBottom();
        }

        const fd = new FormData();
        fd.append('channel_id', this.channelId);
        fd.append('content', content);
        if (parentId) fd.append('parent_id', parentId);
        if (file) fd.append('attachment', file);

        input.value = '';
        input.style.height = 'auto';
        this.cancelReply();
        this.clearAttachPreview();
        this.stopTyping();

        const data = await this.api('sendMessage', 'POST', fd);

        if (data?.success && data.message) {
            if (tempEl) this.replaceMessage(tempEl, data.message);
            else this.toast('Resposta enviada na thread.');
            this.lastMessageId = Math.max(this.lastMessageId, parseInt(data.message.id, 10));
        } else {
            const err = data?.error || 'Falha ao enviar a mensagem.';
            if (tempEl) {
                tempEl.classList.remove('message-pending');
                tempEl.classList.add('message-failed');
                tempEl.dataset.failedContent = content;
                tempEl.dataset.failedParent  = parentId;
                const c = tempEl.querySelector('.message-content');
                if (c) c.insertAdjacentHTML('beforeend', '<div class="send-error"><i class="bi bi-exclamation-triangle me-1"></i>' + this.esc(err) + ' <button type="button" class="btn btn-link btn-sm p-0" data-action="retry">Reenviar</button></div>');
            } else {
                this.toast(err, true);
            }
            if (file && input) input.value = content; // devolve o texto ao compositor se o anexo falhou
        }
    }

    async retryMessage(el) {
        if (!el) return;
        const content = el.dataset.failedContent || '';
        el.remove();
        const input = document.getElementById('messageInput');
        if (input) { input.value = content; this.autoGrow(input); }
        await this.sendMessage();
    }

    replaceMessage(tempEl, msg) {
        const html = this.renderMessage(msg, this.isCompactWith(tempEl.previousElementSibling, msg));
        const tmp  = document.createElement('div');
        tmp.innerHTML = html;
        const fresh = tmp.firstElementChild;
        tempEl.replaceWith(fresh);
        this.enrichContent(fresh.querySelector('.message-content'));
    }

    /* ------------------------------------------------------------------
     *  Renderização de mensagens (espelho do partial chat/_message.php)
     * ----------------------------------------------------------------*/
    initials(name) {
        const parts = (name || '?').trim().split(/\s+/);
        const first = parts[0]?.[0] || '?';
        const last  = parts.length > 1 ? parts[parts.length - 1][0] : '';
        return (first + last).toUpperCase();
    }

    formatTime(iso) {
        const d = this.parseDate(iso);
        return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    }

    parseDate(v) {
        if (!v) return new Date();
        // "YYYY-MM-DD HH:MM:SS" (servidor, fuso local) ou ISO
        const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/.exec(v);
        if (m) return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0));
        return new Date(v);
    }

    dateKey(iso) {
        const d = this.parseDate(iso);
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    dateLabel(key) {
        const [y, m, d] = key.split('-');
        return d + '/' + m + '/' + y;
    }

    renderAttachment(att) {
        if (att.is_image) {
            return `<a href="${this.esc(att.url)}" target="_blank" rel="noopener" class="msg-image-link" title="${this.esc(att.original_name)}"><img src="${this.esc(att.url)}" alt="${this.esc(att.original_name)}" class="msg-image-preview" loading="lazy"></a>`;
        }
        return `<a href="${this.esc(att.url)}" target="_blank" rel="noopener" class="attachment-card" download><i class="bi bi-file-earmark-arrow-down"></i><span class="attachment-name">${this.esc(att.original_name)}</span><span class="attachment-size">${this.esc(att.size_text || '')}</span></a>`;
    }

    renderReactionBadges(messageId, reactions) {
        return (reactions || []).map(r => {
            const ids = String(r.user_ids || '').split(',').map(s => parseInt(s, 10));
            const active = ids.includes(this.userId);
            return `<button type="button" class="reaction-badge ${active ? 'active' : ''}" data-action="react-toggle" data-emoji="${this.esc(r.emoji)}" data-message-id="${messageId}" title="${this.esc(r.users || '')}"><span class="reaction-emoji">${this.emojiHtml(r.emoji)}</span> <small>${parseInt(r.count, 10) || 0}</small></button>`;
        }).join('');
    }

    emojiHtml(emoji) {
        const m = /^:([a-z0-9_]+):$/.exec(emoji || '');
        if (m && this.customEmojis[m[1]]) {
            return `<img src="${this.esc(this.customEmojis[m[1]])}" alt="${this.esc(emoji)}" class="custom-emoji-img">`;
        }
        return this.esc(emoji);
    }

    renderMessage(msg, compact) {
        const id      = msg.id;
        const isMine  = parseInt(msg.user_id, 10) === this.userId;
        const pinned  = parseInt(msg.is_pinned, 10) === 1;
        const replies = parseInt(msg.reply_count, 10) || 0;
        const edited  = parseInt(msg.is_edited, 10) === 1;
        const mayEdit = isMine && this.can.edit && msg.type !== 'system';
        const mayDel  = this.can.moderate || (isMine && this.can.delete);
        const classes = ['message'];
        if (compact) classes.push('message-compact');
        if (isMine) classes.push('message-mine');
        if (pinned) classes.push('message-pinned');
        if (replies) classes.push('has-thread');
        if (msg._pending) classes.push('message-pending');

        const avatar = msg.user_avatar
            ? `<img src="${this.esc(this.url(String(msg.user_avatar).replace(/^\//, '')))}" alt="" class="avatar-img">`
            : `<span class="avatar-initials">${this.esc(this.initials(msg.user_name))}</span>`;

        const atts = (msg.attachments || []).length
            ? `<div class="message-attachments">${msg.attachments.map(a => this.renderAttachment(a)).join('')}</div>` : '';

        let actions = `
            <button type="button" class="btn-action-sm" data-action="react" data-message-id="${id}" title="Reagir"><i class="bi bi-emoji-smile"></i></button>
            <button type="button" class="btn-action-sm" data-action="quote" data-message-id="${id}" title="Citar"><i class="bi bi-quote"></i></button>
            <button type="button" class="btn-action-sm" data-action="thread" data-message-id="${id}" title="Responder em thread"><i class="bi bi-chat-right-text"></i></button>`;
        if (this.can.moderate) actions += `<button type="button" class="btn-action-sm" data-action="pin" data-message-id="${id}" title="${pinned ? 'Desafixar' : 'Fixar'}"><i class="bi bi-pin-angle"></i></button>`;
        if (mayEdit) actions += `<button type="button" class="btn-action-sm" data-action="edit-msg" data-message-id="${id}" title="Editar"><i class="bi bi-pencil"></i></button>`;
        if (mayDel)  actions += `<button type="button" class="btn-action-sm text-danger" data-action="delete-msg" data-message-id="${id}" title="Excluir"><i class="bi bi-trash"></i></button>`;

        return `
        <div class="${classes.join(' ')}" id="msg-${id}" data-message-id="${id}" data-user-id="${parseInt(msg.user_id, 10) || 0}" data-date="${this.dateKey(msg.created_at)}" data-mine="${isMine ? 1 : 0}">
            <div class="message-avatar">${avatar}</div>
            <div class="message-body">
                <div class="message-header">
                    <span class="message-author">${this.esc(msg.user_name || 'Usuário removido')}</span>
                    <span class="message-time">${this.formatTime(msg.created_at)}</span>
                    <span class="message-edited" ${edited ? '' : 'hidden'}>(editada)</span>
                    <span class="message-pin-flag" ${pinned ? '' : 'hidden'}><i class="bi bi-pin-angle-fill"></i> fixada</span>
                </div>
                <div class="message-content" data-raw="${this.esc(msg.content || '')}">${this.esc(msg.content || '').replace(/\n/g, '<br>')}</div>
                ${atts}
                <button type="button" class="thread-link" data-action="thread" data-message-id="${id}" ${replies ? '' : 'hidden'}><i class="bi bi-chat-right-text me-1"></i><span class="thread-count">${replies}</span> resposta${replies === 1 ? '' : 's'}</button>
                <div class="message-reactions" id="reactions-${id}">${this.renderReactionBadges(id, msg.reactions)}</div>
            </div>
            <div class="message-actions">${actions}</div>
        </div>`;
    }

    renderSystem(msg) {
        return `<div class="message-system" id="msg-${msg.id}" data-message-id="${msg.id}" data-date="${this.dateKey(msg.created_at)}"><i class="bi bi-info-circle me-1"></i>${this.esc(msg.content)} <small class="text-muted ms-2">${this.formatTime(msg.created_at)}</small></div>`;
    }

    isCompactWith(prevEl, msg) {
        if (!prevEl || !prevEl.classList || !prevEl.classList.contains('message')) return false;
        if (prevEl.dataset.userId !== String(msg.user_id)) return false;
        if (prevEl.dataset.date !== this.dateKey(msg.created_at)) return false;
        return true;
    }

    /** Acrescenta ao fim da lista (com divisor de data quando muda o dia). */
    appendMessage(msg) {
        if (!this.list) return null;
        document.getElementById('messagesEmpty')?.setAttribute('hidden', '');

        const key  = this.dateKey(msg.created_at);
        const last = this.list.lastElementChild;
        if (!last || (last.dataset.date && last.dataset.date !== key) || (!last.dataset.date && !last.classList.contains('message-date-divider'))) {
            const lastKey = last ? last.dataset.date : null;
            if (lastKey !== key) this.list.insertAdjacentHTML('beforeend', `<div class="message-date-divider" data-date="${key}"><span>${this.dateLabel(key)}</span></div>`);
        }

        const prev = this.list.lastElementChild;
        const html = msg.type === 'system' ? this.renderSystem(msg) : this.renderMessage(msg, this.isCompactWith(prev, msg));
        this.list.insertAdjacentHTML('beforeend', html);
        const el = this.list.lastElementChild;
        const content = el.querySelector('.message-content');
        if (content) this.enrichContent(content);
        const idNum = parseInt(msg.id, 10);
        if (idNum > this.lastMessageId) this.lastMessageId = idNum;
        return el;
    }

    async loadOlder() {
        const btn = document.getElementById('loadOlderBtn');
        const first = this.list?.querySelector('.message[data-message-id], .message-system[data-message-id]');
        if (!btn || !first) return;
        btn.disabled = true;
        const data = await this.api('getOlderMessages', 'GET', { channel_id: this.channelId, before_id: first.dataset.messageId });
        btn.disabled = false;
        if (!data?.success) { this.toast(data?.error || 'Não foi possível carregar.', true); return; }
        if (!data.messages.length) { btn.hidden = true; return; }

        // Constrói o bloco em ordem e insere logo após o botão
        const holder = document.createElement('div');
        let lastDate = '', lastUser = -1;
        data.messages.forEach(msg => {
            const key = this.dateKey(msg.created_at);
            if (key !== lastDate) { holder.insertAdjacentHTML('beforeend', `<div class="message-date-divider" data-date="${key}"><span>${this.dateLabel(key)}</span></div>`); lastDate = key; lastUser = -1; }
            if (msg.type === 'system') { holder.insertAdjacentHTML('beforeend', this.renderSystem(msg)); lastUser = -1; return; }
            holder.insertAdjacentHTML('beforeend', this.renderMessage(msg, lastUser === parseInt(msg.user_id, 10)));
            lastUser = parseInt(msg.user_id, 10);
        });
        const nextDivider = btn.nextElementSibling?.classList.contains('messages-empty') ? btn.nextElementSibling.nextElementSibling : btn.nextElementSibling;
        if (nextDivider && nextDivider.classList.contains('message-date-divider') && nextDivider.dataset.date === lastDate) nextDivider.remove();

        const prevHeight = this.container.scrollHeight;
        const nodes = Array.from(holder.children);
        let ref = btn;
        nodes.forEach(n => { ref.after(n); ref = n; });
        nodes.forEach(n => n.querySelectorAll('.message-content').forEach(el => this.enrichContent(el)));
        this.container.scrollTop += this.container.scrollHeight - prevHeight;
        if (!data.has_more) btn.hidden = true;
    }

    /* ------------------------------------------------------------------
     *  Conteúdo: links, menções, emojis personalizados, citações
     * ----------------------------------------------------------------*/
    enrichAll() {
        document.querySelectorAll('.message-content[data-raw]').forEach(el => this.enrichContent(el));
    }

    contentHtml(raw) {
        const lines = String(raw || '').split('\n').map(line => {
            let html = this.esc(line);
            const quoted = /^&gt;\s?/.test(html);
            if (quoted) html = html.replace(/^&gt;\s?/, '');
            html = html.replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)"'])/gi, url => {
                if (/\.(jpg|jpeg|png|gif|webp)(\?.*)?$/i.test(url)) {
                    return `<a href="${url}" target="_blank" rel="noopener" class="msg-image-link"><img src="${url}" class="msg-image-preview" alt="imagem" loading="lazy"></a>`;
                }
                return `<a href="${url}" target="_blank" rel="noopener" class="msg-link">${url}</a>`;
            });
            html = html.replace(/(^|[^\w@])@([\p{L}\p{N}_.\-]+)/gu, (m, pre, name) => {
                const key = name.replace(/\.$/, '');
                const isGroup = /^(canal|channel|todos|here|aqui)$/i.test(key);
                const mine = !isGroup && this.userName && key.toLowerCase().replace(/\./g, '') === this.userName.toLowerCase().replace(/\s+/g, '');
                return `${pre}<span class="mention ${isGroup ? 'mention-group' : ''} ${mine ? 'mention-me' : ''}">@${key}</span>${name.endsWith('.') ? '.' : ''}`;
            });
            html = html.replace(/:([a-z0-9_]+):/g, (m, name) => this.customEmojis[name]
                ? `<img src="${this.esc(this.customEmojis[name])}" alt=":${name}:" class="custom-emoji-img" title=":${name}:">` : m);
            return quoted ? `<span class="quote-line">${html}</span>` : html;
        });
        return lines.join('<br>');
    }

    enrichContent(el) {
        if (!el) return;
        const raw = el.dataset.raw !== undefined ? el.dataset.raw : el.textContent;
        el.dataset.raw = raw;
        el.innerHTML = this.contentHtml(raw);
    }

    setContent(messageId, raw) {
        const el = document.querySelector(`#msg-${messageId} .message-content`);
        if (!el) return;
        el.dataset.raw = raw;
        el.innerHTML = this.contentHtml(raw);
    }

    /* ------------------------------------------------------------------
     *  Polling
     * ----------------------------------------------------------------*/
    startPolling() {
        if (this.pollTimer) clearInterval(this.pollTimer);
        this.pollTimer = setInterval(() => this.pollMessages(), this.pollSpeed);
    }

    async pollMessages() {
        if (!this.channelId || this.polling) return;
        this.polling = true;
        try {
            const data = await this.api('getMessages', 'GET', { channel_id: this.channelId, after_id: this.lastMessageId });
            if (!data?.success) return;

            let newFromOthers = 0;
            let lastOther = null;
            const atBottom = this.container && (this.container.scrollHeight - this.container.scrollTop - this.container.clientHeight < 120);
            (data.messages || []).forEach(msg => {
                if (document.getElementById('msg-' + msg.id)) return;
                this.appendMessage(msg);
                if (parseInt(msg.user_id, 10) !== this.userId && msg.type !== 'system') { newFromOthers++; lastOther = msg; }
            });

            (data.changed || []).forEach(ch => this.applyChange(ch));
            if (data.reactions) Object.keys(data.reactions).forEach(id => this.renderReactions(id, data.reactions[id]));

            if (newFromOthers > 0) {
                this.playSound();
                if (lastOther) this.notifyBrowser(lastOther.user_name || 'Nova mensagem', (lastOther.content || '').substring(0, 80));
                if (atBottom) this.scrollToBottom(); else this.showUnreadBar(newFromOthers);
            } else if ((data.messages || []).length && atBottom) {
                this.scrollToBottom();
            }

            this.renderTyping(data.typing || []);
        } finally {
            this.polling = false;
        }
    }

    applyChange(ch) {
        const el = document.getElementById('msg-' + ch.id);
        if (!el) return;
        if (parseInt(ch.is_deleted, 10) === 1) { el.remove(); return; }
        if (!this.editing.has(String(ch.id))) {
            const c = el.querySelector('.message-content');
            if (c && c.dataset.raw !== ch.content) this.setContent(ch.id, ch.content);
        }
        el.querySelector('.message-edited')?.toggleAttribute('hidden', parseInt(ch.is_edited, 10) !== 1);
        const pinned = parseInt(ch.is_pinned, 10) === 1;
        el.classList.toggle('message-pinned', pinned);
        el.querySelector('.message-pin-flag')?.toggleAttribute('hidden', !pinned);
        const replies = parseInt(ch.reply_count, 10) || 0;
        const tl = el.querySelector('.thread-link');
        if (tl) {
            tl.hidden = replies === 0;
            tl.innerHTML = `<i class="bi bi-chat-right-text me-1"></i><span class="thread-count">${replies}</span> resposta${replies === 1 ? '' : 's'}`;
        }
        el.classList.toggle('has-thread', replies > 0);
    }

    renderTyping(list) {
        const box = document.getElementById('typingIndicator');
        const txt = document.getElementById('typingText');
        if (!box || !txt) return;
        const names = list.filter(t => parseInt(t.user_id, 10) !== this.userId).map(t => t.user_name);
        if (names.length) {
            txt.textContent = names.join(', ') + (names.length > 1 ? ' estão digitando...' : ' está digitando...');
            box.hidden = false;
        } else {
            box.hidden = true;
        }
    }

    /* ------------------------------------------------------------------
     *  Heartbeat: não lidas, presença, sino
     * ----------------------------------------------------------------*/
    startHeartbeat() {
        this.heartbeatTimer = setInterval(() => this.heartbeat(), 30000);
    }

    async heartbeat() {
        const data = await this.api('heartbeat', 'GET');
        if (!data?.success) return;

        const unread = data.unread_channels || {};
        document.querySelectorAll('.channel-item[data-channel-id]').forEach(item => {
            const id    = item.dataset.channelId;
            const count = String(id) === String(this.channelId) ? 0 : (parseInt(unread[id], 10) || 0);
            const badge = item.querySelector('.badge-unread');
            if (badge) { badge.textContent = count; badge.hidden = count === 0; }
            item.classList.toggle('has-unread', count > 0);
        });

        const presence = data.presence || {};
        document.querySelectorAll('[data-partner-id]').forEach(el => {
            const st  = presence[el.dataset.partnerId] || 'offline';
            const dot = el.classList.contains('dm-status') ? el : el.querySelector('.dm-status');
            if (dot) { dot.classList.remove('online', 'away', 'dnd', 'offline'); dot.classList.add(st); }
        });

        const bell = document.getElementById('portalBellBadge');
        if (bell && typeof data.notification_count === 'number') {
            bell.textContent = data.notification_count > 99 ? '99+' : data.notification_count;
            bell.classList.toggle('d-none', data.notification_count === 0);
        }
    }

    /* ------------------------------------------------------------------
     *  Threads
     * ----------------------------------------------------------------*/
    async openThread(messageId) {
        const data = await this.api('getThread', 'GET', { message_id: messageId });
        if (!data?.success || !data.parent) { this.toast(data?.error || 'Thread não encontrada.', true); return; }

        this.showPanel('Thread');
        const body = document.getElementById('panelBody');
        const item = (m) => `
            <div class="thread-msg">
                <div class="message-avatar"><span class="avatar-initials">${this.esc(this.initials(m.user_name))}</span></div>
                <div class="message-body">
                    <div class="message-header"><span class="message-author">${this.esc(m.user_name || 'Usuário removido')}</span><span class="message-time">${this.formatTime(m.created_at)}</span></div>
                    <div class="message-content">${this.contentHtml(m.content)}</div>
                    ${(m.attachments || []).length ? `<div class="message-attachments">${m.attachments.map(a => this.renderAttachment(a)).join('')}</div>` : ''}
                </div>
            </div>`;

        const canReply = !!document.getElementById('messageForm');
        body.innerHTML = `
            <div class="thread-parent">${item(data.parent)}</div>
            <div class="thread-replies" id="threadReplies">${(data.replies || []).map(item).join('') || '<p class="text-muted small text-center my-3">Nenhuma resposta ainda.</p>'}</div>
            ${canReply ? `<div class="thread-reply-box">
                <textarea id="threadReplyInput" class="message-input" rows="2" placeholder="Responder na thread..."></textarea>
                <button type="button" class="btn btn-primary btn-sm mt-2" id="sendThreadReply"><i class="bi bi-reply me-1"></i>Responder</button>
            </div>` : ''}`;

        const send = async () => {
            const inp = document.getElementById('threadReplyInput');
            const content = (inp?.value || '').trim();
            if (!content) return;
            const btn = document.getElementById('sendThreadReply');
            if (btn) btn.disabled = true;
            const fd = new FormData();
            fd.append('channel_id', this.channelId);
            fd.append('content', content);
            fd.append('parent_id', messageId);
            const res = await this.api('sendMessage', 'POST', fd);
            if (btn) btn.disabled = false;
            if (res?.success) {
                this.openThread(messageId);
                const parentEl = document.getElementById('msg-' + messageId);
                if (parentEl) {
                    const cnt = parentEl.querySelector('.thread-count');
                    const n = (parseInt(cnt?.textContent, 10) || 0) + 1;
                    this.applyChange({ id: messageId, content: parentEl.querySelector('.message-content')?.dataset.raw, is_edited: parentEl.querySelector('.message-edited')?.hidden ? 0 : 1, is_pinned: parentEl.classList.contains('message-pinned') ? 1 : 0, reply_count: n, is_deleted: 0 });
                }
            } else {
                this.toast(res?.error || 'Falha ao responder.', true);
            }
        };
        document.getElementById('sendThreadReply')?.addEventListener('click', send);
        document.getElementById('threadReplyInput')?.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
        document.getElementById('threadReplyInput')?.focus();
    }

    /* ------------------------------------------------------------------
     *  Reações
     * ----------------------------------------------------------------*/
    renderReactions(messageId, reactions) {
        const box = document.getElementById('reactions-' + messageId);
        if (box) box.innerHTML = this.renderReactionBadges(messageId, reactions);
    }

    async toggleReaction(messageId, emoji) {
        const data = await this.api('toggleReaction', 'POST', { message_id: messageId, emoji });
        if (data?.success) this.renderReactions(messageId, data.reactions);
        else this.toast(data?.error || 'Não foi possível reagir.', true);
    }

    /* ------------------------------------------------------------------
     *  Editar / excluir / fixar / citar
     * ----------------------------------------------------------------*/
    editMessage(messageId) {
        const el = document.querySelector(`#msg-${messageId} .message-content`);
        if (!el || this.editing.has(String(messageId))) return;
        const original = el.dataset.raw || el.textContent;
        this.editing.add(String(messageId));

        el.innerHTML = `<textarea class="message-input edit-box" rows="2"></textarea>
            <div class="edit-actions"><button type="button" class="btn btn-primary btn-sm save-edit">Salvar</button>
            <button type="button" class="btn btn-outline-secondary btn-sm cancel-edit ms-1">Cancelar</button>
            <small class="text-muted ms-2">Enter salva · Esc cancela</small></div>`;
        const ta = el.querySelector('textarea');
        ta.value = original;
        ta.focus();
        ta.setSelectionRange(ta.value.length, ta.value.length);
        this.autoGrow(ta);

        const finish = (raw) => { this.editing.delete(String(messageId)); this.setContent(messageId, raw); };
        const save = async () => {
            const val = ta.value.trim();
            if (!val) return;
            if (val === original) { finish(original); return; }
            const data = await this.api('editMessage', 'POST', { message_id: messageId, content: val });
            if (data?.success) {
                finish(val);
                document.querySelector(`#msg-${messageId} .message-edited`)?.removeAttribute('hidden');
            } else {
                finish(original);
                this.toast(data?.error || 'Não foi possível editar.', true);
            }
        };
        ta.addEventListener('input', () => this.autoGrow(ta));
        ta.addEventListener('keydown', e => {
            if (e.key === 'Escape') finish(original);
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); save(); }
        });
        el.querySelector('.save-edit').addEventListener('click', save);
        el.querySelector('.cancel-edit').addEventListener('click', () => finish(original));
    }

    async deleteMessage(messageId) {
        if (!window.confirm('Excluir esta mensagem?')) return;
        const data = await this.api('deleteMessage', 'POST', { message_id: messageId });
        if (data?.success) {
            document.getElementById('msg-' + messageId)?.remove();
            this.toast('Mensagem excluída.');
        } else {
            this.toast(data?.error || 'Não foi possível excluir.', true);
        }
    }

    async pinMessage(messageId) {
        const data = await this.api('pinMessage', 'POST', { message_id: messageId });
        if (!data?.success) { this.toast(data?.error || 'Não foi possível fixar.', true); return; }
        const el = document.getElementById('msg-' + messageId);
        if (el) {
            el.classList.toggle('message-pinned', !!data.pinned);
            el.querySelector('.message-pin-flag')?.toggleAttribute('hidden', !data.pinned);
            const btn = el.querySelector('[data-action="pin"]');
            if (btn) btn.title = data.pinned ? 'Desafixar' : 'Fixar';
        }
        const cnt = document.getElementById('pinnedCount');
        if (cnt) { cnt.textContent = data.pinned_count; cnt.hidden = !data.pinned_count; }
        this.toast(data.pinned ? 'Mensagem fixada.' : 'Mensagem desafixada.');
    }

    quoteReply(messageId) {
        const msgEl = document.querySelector(`#msg-${messageId} .message-content`);
        const author = document.querySelector(`#msg-${messageId} .message-author`)?.textContent?.trim() || '';
        const input = document.getElementById('messageInput');
        if (!msgEl || !input) return;
        const text = (msgEl.dataset.raw || msgEl.textContent).trim().replace(/\n/g, ' ');
        input.value = `> ${author}: ${text.substring(0, 140)}${text.length > 140 ? '…' : ''}\n` + input.value;
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
        this.autoGrow(input);
    }

    /* ------------------------------------------------------------------
     *  Painel direito: membros / fixados
     * ----------------------------------------------------------------*/
    showPanel(title) {
        const panel = document.getElementById('chatPanel');
        document.getElementById('panelTitle').textContent = title;
        panel.hidden = false;
        document.getElementById('panelBody').innerHTML = '<p class="text-muted small">Carregando...</p>';
    }

    closePanel() {
        document.getElementById('chatPanel').hidden = true;
    }

    async showMembersPanel() {
        this.showPanel('Membros');
        const body = document.getElementById('panelBody');
        const data = await this.channelsApi('members', 'GET', { id: this.channelId });
        if (!data?.success) { body.innerHTML = `<p class="text-muted">${this.esc(data?.error || data?.message || 'Erro ao carregar.')}</p>`; return; }

        body.innerHTML = `<div class="small text-muted mb-2">${data.count} participante${data.count === 1 ? '' : 's'}</div>` + data.members.map(u => `
            <div class="user-result-item">
                <span class="dm-status ${this.esc(u.status)}"></span>
                <div class="flex-grow-1 min-w-0">
                    <strong>${this.esc(u.name)}</strong>${u.channel_role && u.channel_role !== 'member' ? ` <span class="badge text-bg-light border">${this.esc(u.channel_role)}</span>` : ''}
                    <br><small class="text-muted">${this.esc(u.title || u.email || '')}</small>
                </div>
                ${parseInt(u.id, 10) !== this.userId ? `<a href="${this.url('index.php?m=chat&page=channels&action=direct&user_id=' + u.id)}" class="btn btn-outline-primary btn-sm" title="Mensagem direta"><i class="bi bi-chat"></i></a>` : ''}
                ${data.can_manage && parseInt(u.id, 10) !== this.userId ? `<button type="button" class="btn btn-outline-danger btn-sm ms-1 remove-member" data-user-id="${u.id}" title="Remover do canal"><i class="bi bi-person-dash"></i></button>` : ''}
            </div>`).join('');

        body.querySelectorAll('.remove-member').forEach(btn => btn.addEventListener('click', async () => {
            if (!window.confirm('Remover esta pessoa do canal?')) return;
            const res = await this.channelsApi('removeMember', 'POST', { channel_id: this.channelId, user_id: btn.dataset.userId });
            if (res?.success) { this.toast('Membro removido.'); this.showMembersPanel(); }
            else this.toast(res?.message || res?.error || 'Falha ao remover.', true);
        }));
    }

    async showPinnedPanel() {
        this.showPanel('Mensagens fixadas');
        const body = document.getElementById('panelBody');
        const data = await this.api('getPinnedMessages', 'GET', { channel_id: this.channelId });
        if (!data?.messages?.length) { body.innerHTML = '<p class="text-muted text-center py-4"><i class="bi bi-pin-angle display-6 d-block mb-2"></i>Nenhuma mensagem fixada.</p>'; return; }
        body.innerHTML = data.messages.map(m => `
            <a href="#msg-${m.id}" class="pinned-message-card" data-goto="${m.id}">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="avatar-initials-sm">${this.esc(this.initials(m.user_name))}</span>
                    <strong class="small">${this.esc(m.user_name || 'Usuário removido')}</strong>
                    <span class="text-muted small ms-auto">${this.formatTime(m.created_at)}</span>
                </div>
                <div class="small">${this.contentHtml(m.content)}</div>
                ${m.pinned_by_name ? `<div class="text-muted small mt-1"><i class="bi bi-pin-angle me-1"></i>Fixada por ${this.esc(m.pinned_by_name)}</div>` : ''}
            </a>`).join('');
        body.querySelectorAll('[data-goto]').forEach(a => a.addEventListener('click', e => {
            const el = document.getElementById('msg-' + a.dataset.goto);
            if (el) { e.preventDefault(); el.scrollIntoView({ block: 'center', behavior: 'smooth' }); el.classList.add('message-highlight'); setTimeout(() => el.classList.remove('message-highlight'), 2500); }
        }));
    }

    async toggleFavorite() {
        const btn = document.getElementById('toggleFavorite');
        const data = await this.api('toggleFavorite', 'POST', { channel_id: this.channelId });
        if (!data?.success) { this.toast(data?.error || 'Falha ao favoritar.', true); return; }
        if (btn) {
            btn.classList.toggle('is-favorite', !!data.favorite);
            const i = btn.querySelector('i');
            if (i) i.className = data.favorite ? 'bi bi-star-fill' : 'bi bi-star';
        }
        this.toast(data.favorite ? 'Canal adicionado aos favoritos.' : 'Canal removido dos favoritos.');
    }

    /* ------------------------------------------------------------------
     *  Menções (@)
     * ----------------------------------------------------------------*/
    async checkMention(input) {
        const val = input.value;
        const cursor = input.selectionStart;
        const before = val.substring(0, cursor);
        const match = /(^|\s)@([\p{L}\p{N}_.\-]{1,30})$/u.exec(before);
        const dd = document.getElementById('mentionDropdown');
        if (!dd) return;
        if (!match) { this.hideMentions(); return; }

        const query = match[2];
        const seq = (this._mentionSeq = (this._mentionSeq || 0) + 1);
        const data = await this.api('searchUsers', 'GET', { query });
        if (seq !== this._mentionSeq) return;

        const groups = [
            { key: 'canal', label: 'Todos do canal' },
            { key: 'here',  label: 'Quem está online' },
        ].filter(g => g.key.startsWith(query.toLowerCase()));
        const users = (data?.users || []).filter(u => parseInt(u.id, 10) !== this.userId);
        if (!groups.length && !users.length) { this.hideMentions(); return; }

        const res = document.getElementById('mentionResults');
        res.innerHTML = groups.map(g => `<div class="mention-item" data-token="${g.key}"><span class="mention-group-icon"><i class="bi bi-people"></i></span><span><strong>@${g.key}</strong> <small class="text-muted">${g.label}</small></span></div>`).join('')
            + users.map(u => `<div class="mention-item" data-token="${this.esc(u.name.trim().replace(/\s+/g, '.'))}"><span class="dm-status ${this.esc(u.status)}"></span><span>${this.esc(u.name)}${u.title ? ` <small class="text-muted">${this.esc(u.title)}</small>` : ''}</span></div>`).join('');
        dd.hidden = false;
        res.querySelector('.mention-item')?.classList.add('selected');

        res.querySelectorAll('.mention-item').forEach(item => item.addEventListener('click', () => {
            const token = item.dataset.token;
            const newBefore = before.replace(/@[\p{L}\p{N}_.\-]{1,30}$/u, '@' + token + ' ');
            input.value = newBefore + val.substring(cursor);
            input.focus();
            input.setSelectionRange(newBefore.length, newBefore.length);
            this.hideMentions();
        }));
    }

    moveMentionSelection(delta) {
        const items = Array.from(document.querySelectorAll('#mentionResults .mention-item'));
        if (!items.length) return;
        let idx = items.findIndex(i => i.classList.contains('selected'));
        items[idx]?.classList.remove('selected');
        idx = (idx + delta + items.length) % items.length;
        items[idx].classList.add('selected');
        items[idx].scrollIntoView({ block: 'nearest' });
    }

    hideMentions() {
        const dd = document.getElementById('mentionDropdown');
        if (dd) dd.hidden = true;
    }

    /* ------------------------------------------------------------------
     *  Nova DM / convite
     * ----------------------------------------------------------------*/
    async searchUsersForDm(query) {
        const box = document.getElementById('dmUserResults');
        if (!box) return;
        if (query.trim().length < 2) { box.innerHTML = '<p class="text-muted small px-2">Digite ao menos 2 letras.</p>'; return; }
        const data = await this.api('searchUsers', 'GET', { query });
        const users = (data?.users || []).filter(u => parseInt(u.id, 10) !== this.userId);
        box.innerHTML = users.length ? users.map(u => `
            <a href="${this.url('index.php?m=chat&page=channels&action=direct&user_id=' + u.id)}" class="user-result-item">
                <span class="dm-status ${this.esc(u.status)}"></span>
                <div><strong>${this.esc(u.name)}</strong><br><small class="text-muted">${this.esc(u.title || u.email)}</small></div>
                <i class="bi bi-chevron-right ms-auto text-muted"></i>
            </a>`).join('') : '<p class="text-muted small px-2">Ninguém encontrado.</p>';
    }

    async searchUsersForInvite(query) {
        const box = document.getElementById('inviteUserResults');
        if (!box) return;
        if (query.trim().length < 2) { box.innerHTML = '<p class="text-muted small px-2">Digite ao menos 2 letras.</p>'; return; }
        const data = await this.api('searchUsers', 'GET', { query });
        const users = (data?.users || []).filter(u => parseInt(u.id, 10) !== this.userId);
        box.innerHTML = users.length ? users.map(u => `
            <div class="user-result-item">
                <span class="dm-status ${this.esc(u.status)}"></span>
                <div><strong>${this.esc(u.name)}</strong><br><small class="text-muted">${this.esc(u.title || u.email)}</small></div>
                <button type="button" class="btn btn-outline-primary btn-sm ms-auto invite-btn" data-user-id="${u.id}">Convidar</button>
            </div>`).join('') : '<p class="text-muted small px-2">Ninguém encontrado.</p>';

        box.querySelectorAll('.invite-btn').forEach(btn => btn.addEventListener('click', async () => {
            btn.disabled = true;
            const res = await this.channelsApi('addMember', 'POST', { channel_id: this.channelId, user_id: btn.dataset.userId });
            if (res?.success) { btn.textContent = 'Adicionado'; btn.classList.replace('btn-outline-primary', 'btn-success'); }
            else { btn.disabled = false; this.toast(res?.message || res?.error || 'Falha ao convidar.', true); }
        }));
    }

    /* ------------------------------------------------------------------
     *  Digitando / arrastar-soltar / barra de não lidas
     * ----------------------------------------------------------------*/
    initTypingIndicator() {
        this._typingTimeout = null;
        this._isTyping = false;
        const input = document.getElementById('messageInput');
        if (!input) return;
        input.addEventListener('input', () => {
            if (!input.value.trim()) { this.stopTyping(); return; }
            if (!this._isTyping) {
                this._isTyping = true;
                this.api('typing', 'POST', { channel_id: this.channelId, typing: '1' });
            }
            clearTimeout(this._typingTimeout);
            this._typingTimeout = setTimeout(() => this.stopTyping(), 3000);
        });
    }

    stopTyping() {
        clearTimeout(this._typingTimeout);
        if (!this._isTyping) return;
        this._isTyping = false;
        this.api('typing', 'POST', { channel_id: this.channelId, typing: '0' });
    }

    initDragDrop() {
        const c = this.container;
        if (!c || !document.getElementById('fileInput')) return;
        c.addEventListener('dragover', e => { e.preventDefault(); c.classList.add('drag-over'); });
        c.addEventListener('dragleave', () => c.classList.remove('drag-over'));
        c.addEventListener('drop', e => {
            e.preventDefault();
            c.classList.remove('drag-over');
            const files = e.dataTransfer?.files;
            const fi = document.getElementById('fileInput');
            if (files?.length && fi) { fi.files = files; this.showAttachPreview(); }
        });
    }

    initUnreadBar() {
        if (!this.container) return;
        this.container.addEventListener('scroll', () => {
            const bar = document.getElementById('unreadBar');
            if (bar && this.container.scrollTop >= this.container.scrollHeight - this.container.clientHeight - 50) bar.hidden = true;
        });
    }

    showUnreadBar(count) {
        let bar = document.getElementById('unreadBar');
        if (!bar) {
            bar = document.createElement('button');
            bar.type = 'button';
            bar.id = 'unreadBar';
            bar.className = 'unread-bar';
            bar.addEventListener('click', () => { this.scrollToBottom(); bar.hidden = true; });
            this.container.parentNode.insertBefore(bar, this.container.nextSibling);
        }
        bar.innerHTML = `<i class="bi bi-arrow-down me-1"></i> ${count} nova${count > 1 ? 's' : ''} mensage${count > 1 ? 'ns' : 'm'}`;
        bar.hidden = false;
    }

    /* ------------------------------------------------------------------
     *  Som / notificações do navegador
     * ----------------------------------------------------------------*/
    playSound() {
        try {
            if (!this.audioCtx) this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const ctx = this.audioCtx;
            if (ctx.state === 'suspended') return; // sem interação do usuário ainda
            const o = ctx.createOscillator();
            const g = ctx.createGain();
            o.connect(g); g.connect(ctx.destination);
            o.type = 'sine'; o.frequency.value = 880;
            g.gain.value = 0.06;
            g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25);
            o.start(ctx.currentTime); o.stop(ctx.currentTime + 0.25);
        } catch (e) { /* sem áudio */ }
    }

    requestBrowserNotifications() {
        if ('Notification' in window && Notification.permission === 'default') {
            try { Notification.requestPermission(); } catch (e) { /* ignorado */ }
        }
    }

    notifyBrowser(title, body) {
        if ('Notification' in window && Notification.permission === 'granted' && document.hidden) {
            try { new Notification(title, { body }); } catch (e) { /* ignorado */ }
        }
    }

    /* ------------------------------------------------------------------
     *  Utilitários
     * ----------------------------------------------------------------*/
    filterSidebar(query) {
        const q = query.trim().toLowerCase();
        document.querySelectorAll('.channel-item').forEach(el => {
            const name = el.querySelector('.channel-name')?.textContent?.toLowerCase() || '';
            el.hidden = q !== '' && !name.includes(q);
        });
        document.querySelectorAll('.category-header').forEach(h => {
            let visible = false, n = h.nextElementSibling;
            while (n && n.classList.contains('channel-item')) { if (!n.hidden) visible = true; n = n.nextElementSibling; }
            h.hidden = !visible;
        });
    }

    filterEmojis(query) {
        const q = query.trim().toLowerCase();
        document.querySelectorAll('#emojiPickerBody .emoji-item').forEach(b => {
            const kw = (b.dataset.keywords || '') + ' ' + (b.dataset.emoji || '');
            b.hidden = q !== '' && !kw.toLowerCase().includes(q);
        });
    }

    showAttachPreview() {
        const fi = document.getElementById('fileInput');
        const preview = document.getElementById('attachPreview');
        const file = fi?.files?.[0];
        if (!file || !preview) return;
        const size = file.size > 1048576 ? (file.size / 1048576).toFixed(1) + ' MB' : Math.round(file.size / 1024) + ' KB';
        preview.hidden = false;
        preview.innerHTML = `<div class="attach-item"><i class="bi bi-paperclip me-1"></i>${this.esc(file.name)} <small class="text-muted ms-1">${size}</small>
            <button type="button" class="btn-close btn-close-sm ms-2" id="removeAttach" aria-label="Remover anexo"></button></div>`;
        document.getElementById('removeAttach')?.addEventListener('click', () => this.clearAttachPreview());
        document.getElementById('messageInput')?.focus();
    }

    clearAttachPreview() {
        const fi = document.getElementById('fileInput');
        const preview = document.getElementById('attachPreview');
        if (fi) fi.value = '';
        if (preview) { preview.hidden = true; preview.innerHTML = ''; }
    }

    cancelReply() {
        const p = document.getElementById('replyParentId');
        const box = document.getElementById('replyPreview');
        if (p) p.value = '';
        if (box) box.hidden = true;
    }

    toggleEmojiPicker(anchorBtn) {
        const p = document.getElementById('emojiPicker');
        if (!p) return;
        if (!p.hidden && !anchorBtn) { this.hideEmojiPicker(); return; }
        p.hidden = false;
        p.classList.toggle('for-reaction', !!this.emojiTarget);
        const s = document.getElementById('emojiSearch');
        if (s) { s.value = ''; this.filterEmojis(''); s.focus(); }
    }

    hideEmojiPicker() {
        const p = document.getElementById('emojiPicker');
        if (p) p.hidden = true;
        this.emojiTarget = null;
    }

    insertAtCursor(el, text) {
        if (!el) return;
        const start = el.selectionStart, end = el.selectionEnd;
        el.value = el.value.substring(0, start) + text + el.value.substring(end);
        el.selectionStart = el.selectionEnd = start + text.length;
        el.focus();
        this.autoGrow(el);
    }

    async markCurrentChannelRead() {
        if (!this.channelId) return;
        this.api('markChannelRead', 'POST', { channel_id: this.channelId });
        const item = document.querySelector(`.channel-item[data-channel-id="${this.channelId}"]`);
        if (item) { item.classList.remove('has-unread'); const b = item.querySelector('.badge-unread'); if (b) b.hidden = true; }
    }

    toast(text, isError) {
        let t = document.getElementById('chatToast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'chatToast';
            t.className = 'chat-toast';
            t.setAttribute('role', 'status');
            document.body.appendChild(t);
        }
        t.textContent = text;
        t.classList.toggle('error', !!isError);
        t.classList.add('show');
        clearTimeout(this._toastTimer);
        this._toastTimer = setTimeout(() => t.classList.remove('show'), 3000);
    }

    autoGrow(el) { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 160) + 'px'; }
    scrollToBottom() { if (this.container) this.container.scrollTop = this.container.scrollHeight; }
    esc(str) { const d = document.createElement('div'); d.textContent = str == null ? '' : String(str); return d.innerHTML; }
}

document.addEventListener('DOMContentLoaded', () => {
    const wrapper = document.getElementById('chatWrapper');
    if (wrapper) window.chatApp = new ChatApp(wrapper);
});
