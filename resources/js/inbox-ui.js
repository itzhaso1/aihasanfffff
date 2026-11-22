const SCROLL_STORAGE_PREFIX = 'hasem.inbox.listScroll.';
const CONVERSATION_STORAGE_PREFIX = 'hasem.inbox.conversation.';

const escapeHtml = (str) => {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
};

const isNearBottom = (element, threshold = 120) => {
    if (!element) {
        return true;
    }

    return element.scrollHeight - element.scrollTop - element.clientHeight <= threshold;
};

const scrollMessagesToBottom = (force = false) => {
    const list = document.getElementById('inbox-message-list');
    if (!list) {
        return;
    }

    if (force || isNearBottom(list)) {
        list.scrollTop = list.scrollHeight;
    }
};

const rememberConversation = (conversationId, workspaceId) => {
    if (!conversationId || !workspaceId) {
        return;
    }

    try {
        localStorage.setItem(`${CONVERSATION_STORAGE_PREFIX}${workspaceId}`, String(conversationId));
    } catch (_) {
        // ignore storage failures
    }
};

const restoreConversationFromStorage = (workspaceId) => {
    if (!workspaceId) {
        return;
    }

    // On mobile screens (< 768px), do not auto-redirect so the user can browse their inbox list
    if (window.innerWidth < 768) {
        return;
    }

    const params = new URLSearchParams(window.location.search);
    if (params.has('conversation')) {
        return;
    }

    let stored = null;
    try {
        stored = localStorage.getItem(`${CONVERSATION_STORAGE_PREFIX}${workspaceId}`);
    } catch (_) {
        return;
    }

    if (!stored) {
        return;
    }

    params.set('conversation', stored);
    const next = `${window.location.pathname}?${params.toString()}`;
    window.location.replace(next);
};

const bindConversationListScroll = (workspaceId) => {
    const list = document.querySelector('[data-inbox-conversation-list]');
    if (!list || !workspaceId || list.dataset.hsBound === 'scroll') {
        return;
    }

    list.dataset.hsBound = 'scroll';

    const key = `${SCROLL_STORAGE_PREFIX}${workspaceId}`;
    const saved = sessionStorage.getItem(key);
    if (saved) {
        list.scrollTop = Number(saved) || 0;
    }

    let timer = null;
    list.addEventListener('scroll', () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            sessionStorage.setItem(key, String(list.scrollTop));
        }, 150);
    });
};

const bindConversationLinks = (workspaceId) => {
    document.querySelectorAll('[data-conversation-id]').forEach((link) => {
        if (link.dataset.hsBound === 'conversation') {
            return;
        }
        link.dataset.hsBound = 'conversation';
        link.addEventListener('click', () => {
            const id = link.getAttribute('data-conversation-id');
            rememberConversation(id, workspaceId);
        });
    });

    document.querySelectorAll('[data-inbox-back-button]').forEach((btn) => {
        if (btn.dataset.hsBound === 'back') {
            return;
        }
        btn.dataset.hsBound = 'back';
        btn.addEventListener('click', () => {
            if (workspaceId) {
                try {
                    localStorage.removeItem(`${CONVERSATION_STORAGE_PREFIX}${workspaceId}`);
                } catch (_) {}
            }
        });
    });
};

const formatFileSize = (bytes) => {
    if (!bytes || isNaN(bytes)) return '';
    if (bytes >= 1048576) {
        return `${(bytes / 1048576).toFixed(1)} MB`;
    }
    return `${Math.round(bytes / 1024)} KB`;
};

const renderAttachmentsGridHtml = (attachments, messagePayload = {}) => {
    if (!Array.isArray(attachments) || attachments.length === 0) {
        return '';
    }

    const images = attachments.filter((a) => a.kind === 'image');
    const others = attachments.filter((a) => a.kind !== 'image');
    let html = '';

    const sender = messagePayload.sender_name || (messagePayload.direction === 'outbound' ? 'أنت' : 'العميل');
    const time = messagePayload.created_at_time || formatTimeNow();

    if (images.length > 0) {
        if (images.length === 1) {
            const single = images[0];
            html += `
                <div class="relative group cursor-pointer overflow-hidden rounded-xl border border-slate-200/80 bg-slate-900/5 max-w-sm mb-1.5"
                     data-media-viewer="true"
                     data-media-url="${single.download_url}"
                     data-media-name="${single.original_name || 'صورة'}"
                     data-media-sender="${sender}"
                     data-media-time="${time}">
                    <img src="${single.download_url}" alt="${single.original_name || 'صورة'}" class="w-full max-h-80 object-cover rounded-xl transition duration-300 group-hover:scale-[1.01]" loading="lazy" />
                    <div class="absolute inset-0 bg-black/0 group-hover:bg-black/15 transition flex items-center justify-center opacity-0 group-hover:opacity-100">
                        <span class="p-2 rounded-full bg-black/50 text-white backdrop-blur-xs">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"/></svg>
                        </span>
                    </div>
                </div>`;
        } else if (images.length === 2) {
            html += `<div class="grid grid-cols-2 gap-1.5 max-w-sm rounded-xl overflow-hidden mb-1.5">`;
            images.forEach((img) => {
                html += `
                    <div class="relative group aspect-square cursor-pointer overflow-hidden rounded-lg bg-slate-100"
                         data-media-viewer="true"
                         data-media-url="${img.download_url}"
                         data-media-name="${img.original_name || 'صورة'}"
                         data-media-sender="${sender}"
                         data-media-time="${time}">
                        <img src="${img.download_url}" alt="${img.original_name || 'صورة'}" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" loading="lazy" />
                    </div>`;
            });
            html += `</div>`;
        } else if (images.length === 3) {
            html += `
                <div class="grid grid-cols-2 gap-1.5 max-w-sm rounded-xl overflow-hidden mb-1.5">
                    <div class="col-span-2 relative group h-44 cursor-pointer overflow-hidden rounded-lg bg-slate-100"
                         data-media-viewer="true"
                         data-media-url="${images[0].download_url}"
                         data-media-name="${images[0].original_name || 'صورة'}"
                         data-media-sender="${sender}"
                         data-media-time="${time}">
                        <img src="${images[0].download_url}" alt="${images[0].original_name || 'صورة'}" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" loading="lazy" />
                    </div>
                    <div class="relative group h-28 cursor-pointer overflow-hidden rounded-lg bg-slate-100"
                         data-media-viewer="true"
                         data-media-url="${images[1].download_url}"
                         data-media-name="${images[1].original_name || 'صورة'}"
                         data-media-sender="${sender}"
                         data-media-time="${time}">
                        <img src="${images[1].download_url}" alt="${images[1].original_name || 'صورة'}" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" loading="lazy" />
                    </div>
                    <div class="relative group h-28 cursor-pointer overflow-hidden rounded-lg bg-slate-100"
                         data-media-viewer="true"
                         data-media-url="${images[2].download_url}"
                         data-media-name="${images[2].original_name || 'صورة'}"
                         data-media-sender="${sender}"
                         data-media-time="${time}">
                        <img src="${images[2].download_url}" alt="${images[2].original_name || 'صورة'}" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" loading="lazy" />
                    </div>
                </div>`;
        } else {
            html += `<div class="grid grid-cols-2 gap-1.5 max-w-sm rounded-xl overflow-hidden mb-1.5">`;
            images.slice(0, 4).forEach((img, idx) => {
                const isFourth = idx === 3 && images.length > 4;
                html += `
                    <div class="relative group aspect-square cursor-pointer overflow-hidden rounded-lg bg-slate-100"
                         data-media-viewer="true"
                         data-media-url="${img.download_url}"
                         data-media-name="${img.original_name || 'صورة'}"
                         data-media-sender="${sender}"
                         data-media-time="${time}">
                        <img src="${img.download_url}" alt="${img.original_name || 'صورة'}" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" loading="lazy" />
                        ${isFourth ? `<div class="absolute inset-0 bg-black/60 backdrop-blur-xs flex items-center justify-center text-white text-xl font-black">+${images.length - 3}</div>` : ''}
                    </div>`;
            });
            html += `</div>`;
        }
    }

    if (others.length > 0) {
        html += `<div class="space-y-1.5 mb-1.5">`;
        others.forEach((other) => {
            const ext = (other.original_name || '').split('.').pop()?.toUpperCase() || 'FILE';
            const sizeStr = formatFileSize(other.size_bytes);
            if (other.kind === 'audio') {
                html += `
                    <div class="rounded-xl border border-slate-200/80 bg-white/80 p-2 shadow-2xs">
                        <audio controls preload="none" class="w-full max-w-xs">
                            <source src="${other.download_url}" type="${other.mime_type || 'audio/mpeg'}">
                        </audio>
                    </div>`;
            } else if (other.kind === 'video') {
                html += `
                    <video controls preload="metadata" class="max-h-64 w-full rounded-xl border border-slate-200 shadow-2xs">
                        <source src="${other.download_url}" type="${other.mime_type || 'video/mp4'}">
                    </video>`;
            } else {
                html += `
                    <a href="${other.download_url}" download="${other.original_name || 'file'}" class="flex items-center gap-3 rounded-xl border border-slate-200/90 bg-white/90 p-2.5 shadow-2xs transition hover:bg-slate-50 group max-w-xs">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-teal-50 text-teal-700 font-bold text-[10px] uppercase border border-teal-200">
                            ${ext.substring(0, 4)}
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-xs font-bold text-slate-800 group-hover:text-teal-700 transition">${other.original_name || 'مستند'}</div>
                            <div class="flex items-center gap-1.5 text-[10px] text-slate-400 mt-0.5">
                                <span>${ext}</span>
                                ${sizeStr ? `<span>·</span><span>${sizeStr}</span>` : ''}
                            </div>
                        </div>
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500 group-hover:bg-teal-500 group-hover:text-white transition">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        </div>
                    </a>`;
            }
        });
        html += `</div>`;
    }

    return html;
};

const formatTimeNow = () => {
    const d = new Date();
    let hours = d.getHours();
    const minutes = String(d.getMinutes()).padStart(2, '0');
    const ampm = hours >= 12 ? 'م' : 'ص';
    hours = hours % 12 || 12;
    return `${hours}:${minutes} ${ampm}`;
};

const appendInboxMessage = (payload, { optimistic = false } = {}) => {
    const list = document.getElementById('inbox-message-list');
    if (!list || !payload) {
        return null;
    }

    // Remove empty-state placeholder if conversation had no messages
    const emptyState = list.querySelector('.flex.h-full.items-center.justify-center');
    if (emptyState) {
        emptyState.remove();
    }

    const existingId = payload.id ? list.querySelector(`[data-message-id="${payload.id}"]`) : null;
    if (existingId) {
        return existingId;
    }

    const tempId = payload.temp_id || payload.client_id || payload.idempotency_key;
    if (tempId && !payload.id) {
        const tempNode = list.querySelector(`[data-temp-id="${tempId}"]`);
        if (tempNode) {
            return tempNode;
        }
    }

    // Reconcile optimistic temporary node with real server message
    if (payload.id) {
        let matchingTemp = null;
        if (tempId) {
            matchingTemp = list.querySelector(`[data-temp-id="${tempId}"]`);
        }
        if (!matchingTemp) {
            const pendingNodes = list.querySelectorAll('[data-temp-id]:not([data-message-id])');
            for (const p of pendingNodes) {
                const txt = p.querySelector('.whitespace-pre-wrap');
                if (txt && txt.textContent.trim() === String(payload.content || '').trim()) {
                    matchingTemp = p;
                    break;
                }
            }
        }
        if (matchingTemp) {
            matchingTemp.dataset.messageId = String(payload.id);
            matchingTemp.removeAttribute('data-temp-id');
            const timeEl = matchingTemp.querySelector('.mt-1 span');
            if (timeEl && payload.created_at_time) {
                timeEl.textContent = payload.created_at_time;
            }
            return matchingTemp;
        }
    }

    const isInternalNote = Boolean(payload.is_internal_note || payload.direction === 'internal_note' || payload.message_type === 'system');
    const outbound = inArray(payload.direction, ['outbound', 'internal_note']) || isInternalNote;

    const wrap = document.createElement('div');
    wrap.className = `hs-inbox-message-row ${isInternalNote ? 'justify-center' : (outbound ? 'hs-inbox-message-row--sent' : 'hs-inbox-message-row--received')}`;
    if (payload.id) {
        wrap.dataset.messageId = String(payload.id);
    }
    if (tempId) {
        wrap.dataset.tempId = String(tempId);
    }

    const bubble = document.createElement('div');
    if (isInternalNote) {
        bubble.className = 'hs-inbox-message-bubble max-w-[88%] sm:max-w-[75%] rounded-2xl px-3.5 py-2 mx-auto border border-amber-300/80 bg-amber-50/90 text-amber-950 shadow-2xs';
    } else if (outbound) {
        bubble.className = 'hs-inbox-message-bubble max-w-[88%] sm:max-w-[75%] rounded-2xl px-3.5 py-2 rounded-bl-xs bg-[#E8FAF6] text-slate-900 border border-[#C9F0E8] shadow-2xs';
    } else {
        bubble.className = 'hs-inbox-message-bubble max-w-[88%] sm:max-w-[75%] rounded-2xl px-3.5 py-2 rounded-br-xs border border-slate-200/90 bg-white text-slate-800 shadow-2xs';
    }

    if (isInternalNote) {
        const noteBadge = document.createElement('div');
        noteBadge.className = 'mb-1 flex items-center gap-1 text-[11px] font-bold text-amber-800';
        noteBadge.innerHTML = `<svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg><span>ملاحظة داخلية</span>`;
        bubble.appendChild(noteBadge);
    }

    if (Array.isArray(payload.attachments) && payload.attachments.length > 0) {
        const attachmentsWrap = document.createElement('div');
        attachmentsWrap.className = 'mb-2';
        attachmentsWrap.innerHTML = renderAttachmentsGridHtml(payload.attachments, payload);
        bubble.appendChild(attachmentsWrap);
    }

    if (payload.content) {
        const text = document.createElement('div');
        text.className = 'text-[12.5px] sm:text-[13px] leading-[1.6] whitespace-pre-wrap font-normal text-slate-800';
        text.textContent = payload.content;
        bubble.appendChild(text);
    }

    // Message Metadata Footer
    const footer = document.createElement('div');
    footer.className = 'mt-1 flex items-center justify-end gap-1.5 text-[9.5px] text-slate-400 select-none';

    const timeSpan = document.createElement('span');
    timeSpan.textContent = payload.created_at_time || formatTimeNow();
    footer.appendChild(timeSpan);

    if (payload.ai_generated || payload.participant_type === 'ai_employee') {
        const aiBadge = document.createElement('span');
        aiBadge.className = 'inline-flex items-center gap-0.5 text-emerald-700 font-medium';
        aiBadge.innerHTML = `<svg class="h-2.5 w-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg><span>AI</span>`;
        footer.appendChild(aiBadge);
    } else if (payload.sender_name && outbound) {
        const senderSpan = document.createElement('span');
        senderSpan.className = 'text-slate-600 font-medium';
        senderSpan.textContent = payload.sender_name;
        footer.appendChild(senderSpan);
    }

    if (outbound && !isInternalNote) {
        const checkmark = document.createElement('span');
        checkmark.className = 'inline-flex items-center';
        checkmark.innerHTML = `<svg class="h-3 w-3 text-[#06C2A4]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m4.5 12.75 4 4 9.5-9.5m-5 9.5 4 4 9.5-9.5" /></svg>`;
        footer.appendChild(checkmark);
    }

    bubble.appendChild(footer);
    wrap.appendChild(bubble);

    // Insert message in sorted order by message ID
    if (payload.id && !optimistic) {
        const id = Number(payload.id);
        const existingNodes = [...list.querySelectorAll('.hs-inbox-message-row[data-message-id]')];
        const nextNode = existingNodes.find((el) => Number(el.dataset.messageId) > id);
        if (nextNode) {
            list.insertBefore(wrap, nextNode);
        } else {
            list.appendChild(wrap);
        }
    } else {
        list.appendChild(wrap);
    }

    scrollMessagesToBottom(!optimistic);

    return wrap;
};

const inArray = (val, arr) => arr.indexOf(val) !== -1;

const prependNewConversationRow = (conv) => {
    const container = document.querySelector('[data-inbox-conversation-list]');
    if (!container || !conv?.id) return null;

    const row = document.createElement('div');
    row.className = 'group relative border-b border-slate-100 last:border-b-0 transition-colors duration-150';
    const currentWorkspaceId = document.getElementById('inbox-shell')?.dataset?.workspaceId || '';
    const isWorker = window.location.pathname.includes('/workers/');
    const href = isWorker
        ? `/workers/${currentWorkspaceId}/inbox?conversation=${conv.id}`
        : `/workspace/conversations?conversation=${conv.id}`;

    const customerName = conv.customer?.name || 'عميل جديد';
    const preview = conv.last_message || conv.last_message_preview || 'محادثة جديدة';
    const time = conv.last_message_at_human || conv.last_message_at || 'الآن';
    const responderName = conv.responder?.name || 'فريق العمل';
    const badgeClass = conv.responder?.badge_class || 'border-slate-200 bg-slate-50 text-slate-700';
    const dotClass = conv.responder?.dot_class || 'bg-slate-400';

    row.innerHTML = `
        <a href="${href}"
           data-conversation-id="${conv.id}"
           class="flex flex-col gap-1.5 p-3 sm:px-4 sm:py-3.5 hover:bg-slate-50/80 transition-colors bg-[#E8FAF6]">
            <div class="flex items-center justify-between gap-2">
                <span class="font-bold text-xs sm:text-sm text-slate-800 truncate">${customerName}</span>
                <span class="shrink-0 text-[11px] text-slate-400 font-medium">${time}</span>
            </div>
            <p class="truncate text-xs text-slate-600 font-medium">${preview}</p>
            <div class="mt-2 flex items-center justify-between">
                <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-bold ${badgeClass}">
                    <span class="h-1.5 w-1.5 rounded-full ${dotClass}"></span>
                    <span class="truncate">${responderName}</span>
                </span>
                <span class="inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-[#06C2A4] px-1.5 text-[10px] font-bold text-white shadow-2xs">1</span>
            </div>
        </a>
    `;

    container.insertBefore(row, container.firstElementChild);
    bindConversationLinks(currentWorkspaceId);
    return row.querySelector('a');
};

let typingTimeout = null;

const showTypingIndicator = (senderName = '', senderType = '', typingSummary = '', typingUsers = []) => {
    const list = document.getElementById('inbox-message-list');
    if (!list) return;

    let el = document.getElementById('inbox-typing-indicator');
    if (!el) {
        el = document.createElement('div');
        el.id = 'inbox-typing-indicator';
        el.className = 'hs-inbox-message-row hs-inbox-message-row--received py-1';
        el.innerHTML = `
            <div class="hs-inbox-message-bubble inline-flex items-center gap-2 rounded-2xl rounded-br-xs border border-slate-200/90 bg-white px-3.5 py-1.5 shadow-2xs text-[11px] font-medium text-slate-500">
                <span class="flex items-center gap-1">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-bounce"></span>
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-bounce [animation-delay:0.2s]"></span>
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-bounce [animation-delay:0.4s]"></span>
                </span>
                <span data-typing-text></span>
            </div>
        `;
        list.appendChild(el);
    }

    const textEl = el.querySelector('[data-typing-text]');
    if (textEl) {
        if (typingSummary && typingSummary.trim() !== '') {
            textEl.textContent = typingSummary;
        } else if (senderType === 'ai' || (senderName && (senderName.includes('مشعل') || senderName.includes('AI')))) {
            textEl.textContent = `${senderName || 'مشعل AI'} يجهز الرد...`;
        } else {
            textEl.textContent = `${senderName || 'الطرف الآخر'} يكتب الآن...`;
        }
    }

    el.classList.remove('hidden');
    el.classList.add('flex');

    if (isNearBottom(list)) {
        list.scrollTop = list.scrollHeight;
    }

    if (typingTimeout) clearTimeout(typingTimeout);
    typingTimeout = setTimeout(() => {
        hideTypingIndicator();
    }, 5500);
};

const updatePresenceUI = (presenceData) => {
    if (!presenceData) return;

    const countEl = document.getElementById('inbox-presence-count');
    const dropdownCountEl = document.getElementById('inbox-presence-dropdown-count');
    const listEl = document.getElementById('inbox-presence-list');

    const employees = Array.isArray(presenceData.present_employees)
        ? presenceData.present_employees
        : (Array.isArray(presenceData) ? presenceData : []);

    const count = typeof presenceData.present_count === 'number'
        ? presenceData.present_count
        : employees.length;

    if (countEl) countEl.textContent = String(count);
    if (dropdownCountEl) dropdownCountEl.textContent = `${count} متصل`;

    if (listEl) {
        if (employees.length === 0) {
            listEl.innerHTML = '<p class="text-xs text-slate-400 text-center py-2">لا يوجد موظفون متصلون حالياً</p>';
            return;
        }

        listEl.innerHTML = employees.map((emp) => {
            const state = emp.state || 'viewing';
            let stateLabel = 'يشاهد';
            let stateBadgeClass = 'bg-emerald-100 text-emerald-800';
            let stateDotClass = 'bg-emerald-500';

            if (state === 'typing') {
                stateLabel = 'يكتب الآن';
                stateBadgeClass = 'bg-amber-100 text-amber-900';
                stateDotClass = 'bg-amber-500 animate-pulse';
            } else if (state === 'sending') {
                stateLabel = 'يرسل...';
                stateBadgeClass = 'bg-blue-100 text-blue-900';
                stateDotClass = 'bg-blue-500 animate-pulse';
            } else if (state === 'connected') {
                stateLabel = 'متصل';
                stateBadgeClass = 'bg-emerald-100 text-emerald-800';
                stateDotClass = 'bg-emerald-500';
            } else if (state === 'disconnected') {
                stateLabel = 'انقطع';
                stateBadgeClass = 'bg-slate-100 text-slate-600';
                stateDotClass = 'bg-slate-400';
            }

            const isAssigned = Boolean(emp.is_assigned);
            const initials = emp.initials || (emp.name ? emp.name.substring(0, 1) : 'م');

            return `
                <div class="flex items-center justify-between gap-2 rounded-xl bg-slate-50 p-2 text-xs">
                    <div class="flex items-center gap-2 min-w-0">
                        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-teal-600 text-white font-bold text-[11px]">
                            ${escapeHtml(initials)}
                        </div>
                        <div class="min-w-0">
                            <p class="font-bold text-slate-900 truncate text-[11.5px]">${escapeHtml(emp.name || 'موظف')}</p>
                            ${isAssigned ? '<span class="text-[9.5px] font-semibold text-blue-700">المسؤول المعين</span>' : ''}
                        </div>
                    </div>
                    <span class="shrink-0 inline-flex items-center gap-1 text-[10px] font-semibold rounded-full px-2 py-0.5 ${stateBadgeClass}">
                        <span class="h-1.5 w-1.5 rounded-full ${stateDotClass}"></span>
                        <span>${stateLabel}</span>
                    </span>
                </div>
            `;
        }).join('');
    }
};

const hideTypingIndicator = () => {
    if (typingTimeout) {
        clearTimeout(typingTimeout);
        typingTimeout = null;
    }
    const el = document.getElementById('inbox-typing-indicator');
    if (el) {
        el.classList.add('hidden');
        el.classList.remove('flex');
    }
};

const setConnectionStatus = (status) => {
    window.__WS_CONNECTED__ = (status === 'connected');
    const badge = document.getElementById('inbox-connection-badge');
    if (!badge) return;

    badge.className = 'inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-semibold transition-all duration-300';
    if (status === 'connected') {
        badge.dataset.status = 'connected';
        badge.classList.add('bg-emerald-50', 'text-emerald-700', 'border', 'border-emerald-200');
        badge.innerHTML = `<span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span><span>متصل</span>`;
        setTimeout(() => {
            if (badge.dataset.status === 'connected') {
                badge.classList.add('opacity-0', 'pointer-events-none');
            }
        }, 3000);
    } else if (status === 'reconnecting') {
        badge.dataset.status = 'reconnecting';
        badge.classList.remove('opacity-0', 'pointer-events-none');
        badge.classList.add('bg-amber-50', 'text-amber-700', 'border', 'border-amber-200');
        badge.innerHTML = `<span class="h-1.5 w-1.5 rounded-full bg-amber-500 animate-pulse"></span><span>جاري إعادة الاتصال...</span>`;
    } else {
        badge.dataset.status = 'offline';
        badge.classList.remove('opacity-0', 'pointer-events-none');
        badge.classList.add('bg-rose-50', 'text-rose-700', 'border', 'border-rose-200');
        badge.innerHTML = `<span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span><span>غير متصل</span>`;
    }
};

const updateConversationInSidebar = (conv, { moveToTop = false } = {}) => {
    if (!conv?.id) return;
    let link = document.querySelector(`[data-conversation-id="${conv.id}"]`);
    if (!link) {
        if (conv.id && (conv.customer || conv.last_message || conv.last_message_preview)) {
            link = prependNewConversationRow(conv);
            if (link) return;
        }
        return;
    }

    const row = link.closest('.group') || link;

    // Update snippet
    if (conv.last_message !== undefined) {
        const snippetEl = link.querySelector('p.truncate.text-xs');
        if (snippetEl) {
            snippetEl.textContent = conv.last_message || 'لا توجد رسائل بعد';
        }
    }

    // Update time
    if (conv.last_message_at) {
        const timeEl = link.querySelector('span.shrink-0.text-\\[11px\\]');
        if (timeEl) {
            timeEl.textContent = conv.last_message_at;
        }
    }

    // Update unread count
    const badgeWrap = link.querySelector('.mt-2.flex.items-center.justify-between') || link.querySelector('.flex.flex-wrap.items-center.justify-between');
    if (badgeWrap) {
        let badge = badgeWrap.querySelector('.bg-\\[\\#00BFAE\\].text-white') || badgeWrap.querySelector('.bg-\\[\\#06C2A4\\].text-white');
        const count = Number(conv.unread_count) || 0;
        if (count > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-[#06C2A4] px-1.5 text-[10px] font-bold text-white shadow-2xs';
                badgeWrap.appendChild(badge);
            }
            badge.textContent = String(count);
        } else if (badge) {
            badge.remove();
        }
    }

    // Update live responder badge
    if (conv.responder) {
        const badge = link.querySelector(`[data-inbox-responder-badge="${conv.id}"]`);
        if (badge) {
            badge.className = `inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-bold ${conv.responder.badge_class || ''}`;
            badge.setAttribute('title', `${conv.responder.label || ''}: ${conv.responder.name || ''}`);

            const dot = badge.querySelector('.h-1\\.5') || badge.querySelector('span:first-child');
            if (dot) {
                dot.className = `h-1.5 w-1.5 rounded-full ${conv.responder.dot_class || ''}`;
            }

            const nameEl = badge.querySelector('.truncate');
            if (nameEl) {
                if (conv.responder.is_typing) {
                    nameEl.textContent = `${conv.responder.name} يكتب...`;
                } else if (conv.responder.status === 'ai_active') {
                    nameEl.textContent = conv.responder.name || 'مشعل AI';
                } else if (conv.responder.status === 'human_needed') {
                    nameEl.textContent = 'تحتاج تدخل بشري';
                } else {
                    const assignedName = conv.assigned_user_name || (conv.assigned_user && conv.assigned_user.name) || conv.responder.name;
                    nameEl.textContent = assignedName || 'الموظف';
                }
            }

            let genEl = badge.querySelector('.animate-pulse');
            if (conv.responder.status === 'ai_active' && conv.responder.is_ai_generating) {
                if (!genEl) {
                    genEl = document.createElement('span');
                    genEl.className = 'text-[9px] animate-pulse';
                    genEl.textContent = 'يكتب...';
                    badge.appendChild(genEl);
                }
            } else if (genEl) {
                genEl.remove();
            }
        }
    }

    // Move to top of conversations list ONLY if explicitly requested (e.g. new message arrived)
    if (moveToTop) {
        const container = document.querySelector('[data-inbox-conversation-list]');
        if (container && container.firstElementChild && container.firstElementChild !== row) {
            container.insertBefore(row, container.firstElementChild);
        }
    }
};

const updateTopResponderBar = (responder, conv) => {
    if (!responder) return;
    const bar = document.getElementById('inbox-live-responder-bar');
    if (!bar) return;

    let bgClasses = 'bg-slate-100/80 border-slate-200/70 text-slate-800';
    if (responder.status === 'ai_active') {
        bgClasses = 'bg-emerald-50/80 border-emerald-200/70 text-emerald-950';
    } else if (responder.status === 'human_active') {
        bgClasses = 'bg-blue-50/80 border-blue-200/70 text-blue-950';
    } else if (responder.status === 'human_needed') {
        bgClasses = 'bg-amber-50/80 border-amber-200/70 text-amber-950';
    }

    bar.className = `border-b px-3.5 py-2 sm:px-5 sm:py-2.5 text-xs transition-colors duration-200 ${bgClasses}`;

    const displayNameEl = bar.querySelector('[data-responder-display-name]');
    if (displayNameEl) {
        if (responder.status === 'ai_active') {
            displayNameEl.textContent = `${responder.name} يتولى الرد على هذه المحادثة`;
        } else if (responder.is_typing) {
            displayNameEl.textContent = `${responder.name} يكتب رداً الآن...`;
        } else if (responder.status === 'human_active') {
            const assignedName = (conv && (conv.assigned_user_name || (conv.assigned_user && conv.assigned_user.name))) || '';
            if (assignedName && responder.name && assignedName !== responder.name) {
                displayNameEl.textContent = `${responder.name} يتولى الرد (المسؤول: ${assignedName})`;
            } else {
                displayNameEl.textContent = `المسؤول: ${assignedName || responder.name || 'موظف'}`;
            }
        } else if (responder.status === 'human_needed') {
            displayNameEl.textContent = 'المحادثة بانتظار استلام من أحد موظفي الفريق';
        } else if (responder.status === 'waiting') {
            const assignedName = (conv && (conv.assigned_user_name || (conv.assigned_user && conv.assigned_user.name))) || (responder.name !== '—' ? responder.name : '');
            displayNameEl.textContent = assignedName ? `المسؤول: ${assignedName}` : 'المحادثة متوقفة مؤقتاً بانتظار الرد';
        } else {
            displayNameEl.textContent = 'المحادثة غير متصلة أو مغلقة';
        }
    }

    const pillEl = bar.querySelector('[data-responder-pill]');
    if (pillEl) {
        pillEl.className = `inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold shadow-2xs ${responder.badge_class || ''}`;
        const dot = pillEl.querySelector('.h-1\\.5') || pillEl.querySelector('span:first-child');
        if (dot) {
            dot.className = `h-1.5 w-1.5 rounded-full ${responder.dot_class || ''}`;
        }
        const labelEl = pillEl.querySelector('[data-responder-label]');
        if (labelEl) {
            labelEl.textContent = responder.label || '';
        }
    }

    const assignedEl = bar.querySelector('[data-responder-assigned]');
    if (assignedEl) {
        if (responder.assigned_at_human) {
            assignedEl.textContent = `بدء المسؤولية: ${responder.assigned_at_human}`;
            assignedEl.classList.remove('hidden');
        } else {
            assignedEl.classList.add('hidden');
        }
    }
};

const updateActiveConversationStatus = (newStatus) => {
    if (!newStatus) return;
    const btn = document.querySelector('[data-hs-region] button[title="تغيير حالة المحادثة"]');
    if (!btn) return;
    const dot = btn.querySelector('span.rounded-full');
    const label = btn.querySelector('span:not(.rounded-full)');
    if (dot) {
        dot.className = `h-2 w-2 rounded-full ${newStatus === 'open' ? 'bg-emerald-500' : (newStatus === 'archived' ? 'bg-amber-500' : 'bg-slate-400')}`;
    }
    if (label) {
        const labels = { open: 'مفتوحة', closed: 'مغلقة', archived: 'مؤرشفة' };
        label.textContent = labels[newStatus] || newStatus;
    }
};

const bindComposeForm = () => {
    const form = document.getElementById('inbox-compose-form');
    if (!form || form.dataset.hsBound === 'compose') {
        return;
    }

    form.dataset.hsBound = 'compose';

    const textarea = form.querySelector('textarea[name="content"]');
    if (textarea) {
        const resizeTextarea = () => {
            textarea.style.height = 'auto';
            const nextHeight = Math.min(Math.max(textarea.scrollHeight, 40), 160);
            textarea.style.height = `${nextHeight}px`;
        };

        let lastTypingSent = 0;
        const sendTypingNotification = (isTyping = true) => {
            const now = Date.now();
            if (isTyping && now - lastTypingSent < 2500) return;
            lastTypingSent = now;

            const root = document.getElementById('inbox-shell');
            const conversationId = root?.dataset?.activeConversationId || new URLSearchParams(window.location.search).get('conversation');
            if (!conversationId) return;

            const isWorker = window.location.pathname.includes('/workers/');
            const workspaceId = root?.dataset?.workspaceId;
            const url = isWorker
                ? `/workers/${workspaceId}/inbox/${conversationId}/typing`
                : `/workspace/conversations/${conversationId}/typing`;

            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ is_typing: isTyping }),
            }).catch(() => {});
        };

        textarea.addEventListener('input', () => {
            resizeTextarea();
            if (textarea.value.trim().length > 0) {
                sendTypingNotification(true);
            }
        });

        // Enter submits form, Shift+Enter creates a new line
        textarea.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                if (textarea.value.trim() !== '') {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.dispatchEvent(new Event('submit', { cancelable: true }));
                    }
                }
            }
        });
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const submitButton = form.querySelector('[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
        }

        const tempId = `temp-${Date.now()}`;
        const formData = new FormData(form);
        const direction = formData.get('direction') || 'outbound';
        const content = String(formData.get('content') || '').trim();

        formData.append('client_id', tempId);
        formData.append('metadata_json', JSON.stringify({ client_id: tempId, idempotency_key: tempId }));

        if (content !== '') {
            appendInboxMessage(
                {
                    temp_id: tempId,
                    client_id: tempId,
                    idempotency_key: tempId,
                    direction,
                    content,
                    attachments: [],
                },
                { optimistic: true },
            );
        }

        sendTypingNotification(false);

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: formData,
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`send_failed_${response.status}`);
            }

            const data = await response.json();

            if (data?.message) {
                appendInboxMessage(data.message);
                if (data?.conversation) {
                    updateConversationInSidebar(data.conversation, { moveToTop: true });
                    if (data.conversation.responder) {
                        updateTopResponderBar(data.conversation.responder, data.conversation);
                    }
                }
            } else {
                const node = document.querySelector(`[data-temp-id="${tempId}"]`);
                if (node) {
                    node.remove();
                }
            }

            if (textarea) {
                textarea.value = '';
                textarea.style.height = '';
            }
        } catch (_) {
            const node = document.querySelector(`[data-temp-id="${tempId}"]`);
            if (node) {
                node.classList.add('opacity-80');
                const bubble = node.querySelector('.hs-inbox-message-bubble');
                if (bubble && !bubble.querySelector('.hs-send-error')) {
                    const errEl = document.createElement('div');
                    errEl.className = 'hs-send-error mt-1 text-[10px] text-rose-600 font-semibold flex items-center gap-1 cursor-pointer';
                    errEl.innerHTML = `<svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span>تعذر الإرسال. انقر للمحاولة مرة أخرى.</span>`;
                    errEl.onclick = () => {
                        errEl.remove();
                        node.remove();
                        if (textarea) textarea.value = content;
                        form.requestSubmit();
                    };
                    bubble.appendChild(errEl);
                }
            }
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
            }
        }
    });
};

/* ==========================================================================
   HASIM AI — WHATSAPP-STYLE MEDIA SYSTEM (LIGHTBOX, PREVIEW, PROGRESS & UPLOAD)
   ========================================================================== */

let pendingMediaFiles = [];
let activeMediaIndex = 0;

const InboxMediaPreview = {
    open(files) {
        if (!files || files.length === 0) return;
        const modal = document.getElementById('inbox-media-preview-modal');
        if (!modal) return;

        // Convert FileList or Array to array of File objects
        const newFiles = Array.from(files);
        pendingMediaFiles = [...pendingMediaFiles, ...newFiles];
        activeMediaIndex = pendingMediaFiles.length - newFiles.length; // Focus on first newly added file

        modal.classList.remove('hidden');
        modal.classList.add('flex');

        this.render();

        const captionInput = document.getElementById('preview-caption-input');
        if (captionInput) {
            captionInput.focus();
        }
    },

    close() {
        const modal = document.getElementById('inbox-media-preview-modal');
        if (!modal) return;

        modal.classList.add('hidden');
        modal.classList.remove('flex');
        pendingMediaFiles = [];
        activeMediaIndex = 0;

        const captionInput = document.getElementById('preview-caption-input');
        if (captionInput) {
            captionInput.value = '';
        }

        const activeContainer = document.getElementById('preview-active-container');
        if (activeContainer) {
            activeContainer.innerHTML = '';
        }
        const tray = document.getElementById('preview-thumbnails-tray');
        if (tray) {
            tray.innerHTML = '';
        }
    },

    setActive(index) {
        if (index < 0 || index >= pendingMediaFiles.length) return;
        activeMediaIndex = index;
        this.render();
    },

    removeFile(index) {
        pendingMediaFiles.splice(index, 1);
        if (pendingMediaFiles.length === 0) {
            this.close();
            return;
        }
        if (activeMediaIndex >= pendingMediaFiles.length) {
            activeMediaIndex = pendingMediaFiles.length - 1;
        }
        this.render();
    },

    render() {
        const badge = document.getElementById('preview-file-count-badge');
        if (badge) {
            badge.textContent = String(pendingMediaFiles.length);
        }

        const activeContainer = document.getElementById('preview-active-container');
        const file = pendingMediaFiles[activeMediaIndex];
        if (!file || !activeContainer) return;

        const isImg = file.type.startsWith('image/');
        const isVideo = file.type.startsWith('video/');

        if (isImg) {
            const blobUrl = URL.createObjectURL(file);
            activeContainer.innerHTML = `
                <img src="${blobUrl}" alt="${file.name}" class="max-h-[50vh] sm:max-h-[58vh] max-w-full rounded-2xl object-contain shadow-2xl transition duration-200" />
            `;
        } else if (isVideo) {
            const blobUrl = URL.createObjectURL(file);
            activeContainer.innerHTML = `
                <video src="${blobUrl}" controls class="max-h-[50vh] max-w-full rounded-2xl shadow-2xl"></video>
            `;
        } else {
            const ext = file.name.split('.').pop()?.toUpperCase() || 'FILE';
            activeContainer.innerHTML = `
                <div class="flex flex-col items-center justify-center p-8 text-center">
                    <div class="flex h-20 w-20 items-center justify-center rounded-2xl bg-teal-500/20 text-[#06C2A4] font-bold text-lg mb-3">
                        ${ext.substring(0, 4)}
                    </div>
                    <div class="text-sm font-bold text-white max-w-md truncate">${file.name}</div>
                    <div class="text-xs text-slate-400 mt-1">${formatFileSize(file.size)}</div>
                </div>
            `;
        }

        // Render Carousel Thumbnails
        const tray = document.getElementById('preview-thumbnails-tray');
        if (tray) {
            tray.innerHTML = '';
            pendingMediaFiles.forEach((f, idx) => {
                const isSelected = idx === activeMediaIndex;
                const thumb = document.createElement('div');
                thumb.className = `relative shrink-0 w-14 h-14 rounded-xl overflow-hidden cursor-pointer border-2 transition ${isSelected ? 'border-[#06C2A4] ring-2 ring-[#06C2A4]/40 scale-105' : 'border-white/20 opacity-70 hover:opacity-100'}`;

                if (f.type.startsWith('image/')) {
                    const thumbUrl = URL.createObjectURL(f);
                    thumb.innerHTML = `<img src="${thumbUrl}" alt="" class="h-full w-full object-cover" />`;
                } else {
                    const ext = f.name.split('.').pop()?.toUpperCase() || 'FILE';
                    thumb.innerHTML = `<div class="h-full w-full bg-slate-800 flex items-center justify-center text-[10px] font-bold text-teal-400">${ext.substring(0, 4)}</div>`;
                }

                // Delete button on thumbnail
                const delBtn = document.createElement('button');
                delBtn.type = 'button';
                delBtn.className = 'absolute top-0.5 end-0.5 h-4 w-4 rounded-full bg-black/70 text-white flex items-center justify-center hover:bg-rose-500 transition text-[10px]';
                delBtn.innerHTML = '×';
                delBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    InboxMediaPreview.removeFile(idx);
                });
                thumb.appendChild(delBtn);

                thumb.addEventListener('click', () => {
                    InboxMediaPreview.setActive(idx);
                });

                tray.appendChild(thumb);
            });

            // Add More Files Button
            const addMoreBtn = document.createElement('button');
            addMoreBtn.type = 'button';
            addMoreBtn.className = 'shrink-0 w-14 h-14 rounded-xl border-2 border-dashed border-white/30 text-white/60 hover:text-white hover:border-white/60 flex flex-col items-center justify-center transition text-xs';
            addMoreBtn.innerHTML = `
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span class="text-[9px] mt-0.5">إضافة</span>
            `;
            addMoreBtn.addEventListener('click', () => {
                const input = document.getElementById('inbox-file-input-images');
                if (input) input.click();
            });
            tray.appendChild(addMoreBtn);
        }
    },

    send() {
        if (pendingMediaFiles.length === 0) return;
        const captionInput = document.getElementById('preview-caption-input');
        const caption = captionInput ? captionInput.value.trim() : '';

        const filesToSend = [...pendingMediaFiles];
        this.close();

        uploadMediaBatch(filesToSend, caption);
    },
};

const uploadMediaBatch = (files, caption = '') => {
    const form = document.getElementById('inbox-compose-form');
    if (!form || files.length === 0) return;

    const actionUrl = form.action;
    const tempId = `temp-upload-${Date.now()}`;
    const firstFile = files[0];
    const isImage = firstFile.type.startsWith('image/');

    // 1. Optimistic Bubble with real progress bar
    const list = document.getElementById('inbox-message-list');
    if (!list) return;

    // Remove empty-state if present
    const emptyState = list.querySelector('.flex.h-full.items-center.justify-center');
    if (emptyState) emptyState.remove();

    const wrap = document.createElement('div');
    wrap.className = 'hs-inbox-message-row hs-inbox-message-row--sent';
    wrap.dataset.tempId = tempId;

    const bubble = document.createElement('div');
    bubble.className = 'hs-inbox-message-bubble max-w-[88%] sm:max-w-[75%] rounded-2xl px-3.5 py-2.5 rounded-bl-xs bg-[#E8FAF6] text-slate-900 border border-[#C9F0E8] shadow-2xs space-y-2';

    // Preview thumbnail
    if (isImage) {
        const thumbUrl = URL.createObjectURL(firstFile);
        bubble.innerHTML = `
            <div class="relative overflow-hidden rounded-xl border border-slate-200/80 bg-slate-900/5 max-w-xs">
                <img src="${thumbUrl}" alt="" class="max-h-52 w-full object-cover rounded-xl opacity-80" />
                <div class="absolute inset-0 bg-black/20 flex items-center justify-center">
                    <span class="rounded-full bg-black/60 px-2.5 py-1 text-[11px] font-bold text-white backdrop-blur-xs upload-status-label">
                        جاري الرفع 0%
                    </span>
                </div>
            </div>
        `;
    } else {
        const ext = firstFile.name.split('.').pop()?.toUpperCase() || 'FILE';
        bubble.innerHTML = `
            <div class="flex items-center gap-3 rounded-xl border border-slate-200/90 bg-white/90 p-2.5 shadow-2xs max-w-xs">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-teal-50 text-teal-700 font-bold text-[10px] uppercase border border-teal-200">
                    ${ext.substring(0, 4)}
                </div>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-xs font-bold text-slate-800">${firstFile.name}</div>
                    <div class="text-[10px] text-slate-400 mt-0.5 upload-status-label">جاري الرفع 0%</div>
                </div>
            </div>
        `;
    }

    // Progress bar
    const progressTrack = document.createElement('div');
    progressTrack.className = 'h-1.5 w-full bg-slate-200/90 rounded-full overflow-hidden';
    const progressBar = document.createElement('div');
    progressBar.className = 'h-full bg-[#06C2A4] rounded-full transition-all duration-150 upload-progress-fill';
    progressBar.style.width = '0%';
    progressTrack.appendChild(progressBar);
    bubble.appendChild(progressTrack);

    if (caption) {
        const capEl = document.createElement('div');
        capEl.className = 'text-[12.5px] sm:text-[13px] leading-relaxed font-normal text-slate-800 pt-1';
        capEl.textContent = caption;
        bubble.appendChild(capEl);
    }

    wrap.appendChild(bubble);
    list.appendChild(wrap);
    scrollMessagesToBottom(true);

    // 2. Perform upload via XMLHttpRequest for real upload progress
    const formData = new FormData(form);
    formData.delete('content');
    formData.append('content', caption);
    formData.set('message_type', files.some((f) => f.type.startsWith('image/')) ? 'image' : 'file');

    files.forEach((f) => {
        formData.append('attachments[]', f);
    });

    const xhr = new XMLHttpRequest();
    xhr.open('POST', actionUrl, true);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    if (csrfToken) {
        xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken);
    }

    xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            progressBar.style.width = `${pct}%`;
            const statusLabel = bubble.querySelector('.upload-status-label');
            if (statusLabel) {
                statusLabel.textContent = `جاري الرفع ${pct}%`;
            }
        }
    };

    xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
            let data = null;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (_) {}

            wrap.remove();

            if (data?.message) {
                appendInboxMessage(data.message);
                if (data?.conversation) {
                    updateConversationInSidebar(data.conversation, { moveToTop: true });
                    if (data.conversation.responder) {
                        updateTopResponderBar(data.conversation.responder);
                    }
                }
            }
        } else {
            handleUploadFailure(wrap, files, caption);
        }
    };

    xhr.onerror = () => {
        handleUploadFailure(wrap, files, caption);
    };

    xhr.send(formData);
};

const handleUploadFailure = (wrapNode, files, caption) => {
    if (!wrapNode) return;
    const bubble = wrapNode.querySelector('.hs-inbox-message-bubble');
    if (!bubble) return;

    bubble.classList.add('border-rose-300', 'bg-rose-50/70');
    const statusLabel = bubble.querySelector('.upload-status-label');
    if (statusLabel) {
        statusLabel.textContent = 'فشل الرفع';
    }

    const retryBtn = document.createElement('button');
    retryBtn.type = 'button';
    retryBtn.className = 'mt-2 inline-flex items-center gap-1.5 rounded-xl bg-rose-600 px-3 py-1.5 text-xs font-bold text-white shadow-2xs hover:bg-rose-700 transition';
    retryBtn.innerHTML = `
        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        <span>إعادة المحاولة</span>
    `;
    retryBtn.addEventListener('click', () => {
        wrapNode.remove();
        uploadMediaBatch(files, caption);
    });

    bubble.appendChild(retryBtn);
};

/* ==========================================================================
   HASIM AI — LIGHTBOX CONTROLLER (ZOOM, PAN, PREV/NEXT & KEYBOARD SHORTCUTS)
   ========================================================================== */

const InboxLightbox = {
    modal: null,
    imgEl: null,
    images: [],
    currentIndex: 0,
    zoomScale: 1.0,
    panX: 0,
    panY: 0,
    isPanning: false,
    startX: 0,
    startY: 0,

    init() {
        this.modal = document.getElementById('inbox-media-lightbox');
        this.imgEl = document.getElementById('lightbox-img');
        if (!this.modal || !this.imgEl) return;

        // Close button
        const closeBtn = document.getElementById('lightbox-close-btn');
        if (closeBtn) closeBtn.addEventListener('click', () => this.close());

        // Prev / Next buttons
        const prevBtn = document.getElementById('lightbox-prev-btn');
        if (prevBtn) prevBtn.addEventListener('click', () => this.prev());
        const nextBtn = document.getElementById('lightbox-next-btn');
        if (nextBtn) nextBtn.addEventListener('click', () => this.next());

        // Zoom controls
        const zoomInBtn = document.getElementById('lightbox-zoom-in');
        if (zoomInBtn) zoomInBtn.addEventListener('click', () => this.zoom(0.25));
        const zoomOutBtn = document.getElementById('lightbox-zoom-out');
        if (zoomOutBtn) zoomOutBtn.addEventListener('click', () => this.zoom(-0.25));
        const zoomResetBtn = document.getElementById('lightbox-zoom-reset');
        if (zoomResetBtn) zoomResetBtn.addEventListener('click', () => this.resetZoom());

        // Pan and drag on stage
        const stage = document.getElementById('lightbox-stage');
        if (stage) {
            stage.addEventListener('mousedown', (e) => {
                if (this.zoomScale <= 1) return;
                this.isPanning = true;
                this.startX = e.clientX - this.panX;
                this.startY = e.clientY - this.panY;
            });
            window.addEventListener('mousemove', (e) => {
                if (!this.isPanning) return;
                this.panX = e.clientX - this.startX;
                this.panY = e.clientY - this.startY;
                this.applyTransform();
            });
            window.addEventListener('mouseup', () => {
                this.isPanning = false;
            });

            // Mouse wheel zoom
            stage.addEventListener('wheel', (e) => {
                e.preventDefault();
                const delta = e.deltaY < 0 ? 0.2 : -0.2;
                this.zoom(delta);
            }, { passive: false });
        }

        // Global keydown
        window.addEventListener('keydown', (e) => {
            if (!this.modal || this.modal.classList.contains('hidden')) return;
            if (e.key === 'Escape') {
                this.close();
            } else if (e.key === 'ArrowRight') {
                this.prev();
            } else if (e.key === 'ArrowLeft') {
                this.next();
            } else if (e.key === '+' || e.key === '=') {
                this.zoom(0.25);
            } else if (e.key === '-') {
                this.zoom(-0.25);
            }
        });

        // Delegate clicks on [data-media-viewer="true"]
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-media-viewer="true"]');
            if (trigger) {
                e.preventDefault();
                e.stopPropagation();
                this.openFromTrigger(trigger);
            }
        });
    },

    openFromTrigger(target) {
        // Collect all media viewer elements on page
        const nodes = Array.from(document.querySelectorAll('[data-media-viewer="true"]'));
        this.images = nodes.map((node) => ({
            url: node.getAttribute('data-media-url') || node.src,
            name: node.getAttribute('data-media-name') || '',
            sender: node.getAttribute('data-media-sender') || '',
            time: node.getAttribute('data-media-time') || '',
        })).filter((item) => Boolean(item.url));

        const targetUrl = target.getAttribute('data-media-url') || target.src;
        let foundIdx = this.images.findIndex((img) => img.url === targetUrl);
        if (foundIdx === -1) foundIdx = 0;
        this.currentIndex = foundIdx;

        this.open();
    },

    open() {
        if (!this.modal) return;
        this.modal.classList.remove('hidden');
        this.modal.classList.add('flex');
        this.resetZoom();
        this.render();
    },

    close() {
        if (!this.modal) return;
        this.modal.classList.add('hidden');
        this.modal.classList.remove('flex');
        this.resetZoom();
    },

    next() {
        if (this.images.length <= 1) return;
        this.currentIndex = (this.currentIndex + 1) % this.images.length;
        this.resetZoom();
        this.render();
    },

    prev() {
        if (this.images.length <= 1) return;
        this.currentIndex = (this.currentIndex - 1 + this.images.length) % this.images.length;
        this.resetZoom();
        this.render();
    },

    zoom(delta) {
        this.zoomScale = Math.min(Math.max(this.zoomScale + delta, 0.5), 3.5);
        if (this.zoomScale === 1.0) {
            this.panX = 0;
            this.panY = 0;
        }
        const pctEl = document.getElementById('lightbox-zoom-pct');
        if (pctEl) {
            pctEl.textContent = `${Math.round(this.zoomScale * 100)}%`;
        }
        this.applyTransform();
    },

    resetZoom() {
        this.zoomScale = 1.0;
        this.panX = 0;
        this.panY = 0;
        const pctEl = document.getElementById('lightbox-zoom-pct');
        if (pctEl) {
            pctEl.textContent = '100%';
        }
        this.applyTransform();
    },

    applyTransform() {
        if (!this.imgEl) return;
        this.imgEl.style.transform = `translate(${this.panX}px, ${this.panY}px) scale(${this.zoomScale})`;
    },

    render() {
        if (this.images.length === 0 || !this.imgEl) return;
        const current = this.images[this.currentIndex];

        this.imgEl.src = current.url;

        const counter = document.getElementById('lightbox-counter');
        if (counter) counter.textContent = `${this.currentIndex + 1} / ${this.images.length}`;

        const senderEl = document.getElementById('lightbox-sender');
        if (senderEl) senderEl.textContent = current.sender || 'المرسل';

        const timeEl = document.getElementById('lightbox-time');
        if (timeEl) timeEl.textContent = current.time ? `· ${current.time}` : '';

        const dlLink = document.getElementById('lightbox-download-link');
        if (dlLink) {
            dlLink.href = current.url;
            dlLink.download = current.name || 'image';
        }

        const prevBtn = document.getElementById('lightbox-prev-btn');
        const nextBtn = document.getElementById('lightbox-next-btn');
        if (this.images.length <= 1) {
            if (prevBtn) prevBtn.classList.add('hidden');
            if (nextBtn) nextBtn.classList.add('hidden');
        } else {
            if (prevBtn) prevBtn.classList.remove('hidden');
            if (nextBtn) nextBtn.classList.remove('hidden');
        }
    },
};

/* ==========================================================================
   DRAG & DROP, CLIPBOARD PASTE, FILE INPUTS & JUMP-TO-MESSAGE BINDINGS
   ========================================================================== */

const bindMediaInteractions = () => {
    // 1. Initialize Lightbox
    InboxLightbox.init();

    // 2. Pre-send preview modal buttons
    const previewCloseBtn = document.getElementById('preview-modal-close-btn');
    if (previewCloseBtn) previewCloseBtn.addEventListener('click', () => InboxMediaPreview.close());

    const previewCancelBtn = document.getElementById('preview-modal-cancel-btn');
    if (previewCancelBtn) previewCancelBtn.addEventListener('click', () => InboxMediaPreview.close());

    const previewSendBtn = document.getElementById('preview-modal-send-btn');
    if (previewSendBtn) previewSendBtn.addEventListener('click', () => InboxMediaPreview.send());

    const captionInput = document.getElementById('preview-caption-input');
    if (captionInput) {
        captionInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                InboxMediaPreview.send();
            }
        });
    }

    // 3. Hidden File Inputs change events
    ['inbox-file-input-images', 'inbox-file-input-camera', 'inbox-file-input-docs', 'inbox-file-input-video'].forEach((id) => {
        const input = document.getElementById(id);
        if (input && input.dataset.hsBound !== 'mediaInput') {
            input.dataset.hsBound = 'mediaInput';
            input.addEventListener('change', () => {
                if (input.files && input.files.length > 0) {
                    InboxMediaPreview.open(input.files);
                    input.value = ''; // Reset so the same file can be selected again
                }
            });
        }
    });

    // 4. Drag & Drop on Chat List
    const dropOverlay = document.getElementById('inbox-drag-drop-overlay');
    const shell = document.getElementById('inbox-shell');

    if (shell && shell.dataset.hsBound !== 'dragdrop') {
        shell.dataset.hsBound = 'dragdrop';

        let dragCounter = 0;

        window.addEventListener('dragenter', (e) => {
            if (e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')) {
                dragCounter++;
                if (dropOverlay) dropOverlay.classList.remove('hidden');
                if (dropOverlay) dropOverlay.classList.add('flex');
            }
        });

        window.addEventListener('dragleave', () => {
            dragCounter--;
            if (dragCounter <= 0) {
                dragCounter = 0;
                if (dropOverlay) dropOverlay.classList.add('hidden');
                if (dropOverlay) dropOverlay.classList.remove('flex');
            }
        });

        window.addEventListener('dragover', (e) => {
            e.preventDefault();
        });

        window.addEventListener('drop', (e) => {
            e.preventDefault();
            dragCounter = 0;
            if (dropOverlay) dropOverlay.classList.add('hidden');
            if (dropOverlay) dropOverlay.classList.remove('flex');

            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                InboxMediaPreview.open(e.dataTransfer.files);
            }
        });
    }

    // 5. Clipboard Paste (Ctrl+V) for images
    if (!window.__HASIM_PASTE_BOUND__) {
        window.__HASIM_PASTE_BOUND__ = true;
        window.addEventListener('paste', (e) => {
            const clipboardData = e.clipboardData;
            if (!clipboardData || !clipboardData.files || clipboardData.files.length === 0) return;

            const files = Array.from(clipboardData.files);
            const mediaFiles = files.filter((f) => f.type.startsWith('image/') || f.type.startsWith('video/') || f.size > 0);
            if (mediaFiles.length > 0) {
                e.preventDefault();
                InboxMediaPreview.open(mediaFiles);
            }
        });
    }

    // 6. Jump to Message scroll handler
    document.addEventListener('click', (e) => {
        const jumpBtn = e.target.closest('[data-jump-to-msg]');
        if (jumpBtn) {
            e.preventDefault();
            const msgId = jumpBtn.getAttribute('data-jump-to-msg');
            const targetRow = document.querySelector(`[data-message-id="${msgId}"]`);
            if (targetRow) {
                targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                const bubble = targetRow.querySelector('.hs-inbox-message-bubble');
                if (bubble) {
                    bubble.classList.add('ring-4', 'ring-[#06C2A4]', 'transition-all', 'duration-500');
                    setTimeout(() => {
                        bubble.classList.remove('ring-4', 'ring-[#06C2A4]');
                    }, 2000);
                }
            }
        }
    });
};

let pollTimer = null;
let sidebarPollTimer = null;

const bindLivePolling = () => {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
    if (sidebarPollTimer) {
        clearInterval(sidebarPollTimer);
        sidebarPollTimer = null;
    }

    const root = document.getElementById('inbox-shell');
    if (!root) {
        return;
    }

    const conversationId = root.dataset.activeConversationId || new URLSearchParams(window.location.search).get('conversation');

    // 1. Adaptive active chat polling (light safety net when WebSocket is connected, fallback when offline)
    if (conversationId) {
        let isPolling = false;
        pollTimer = setInterval(async () => {
            // When real-time WebSocket is connected, incoming messages arrive immediately via WebSocket.
            // In that case, we skip aggressive HTTP polling.
            if (window.__INBOX_REALTIME__?.enabled && window.__WS_CONNECTED__) {
                return;
            }

            if (isPolling) return;
            const currentList = document.getElementById('inbox-message-list');
            if (!currentList) {
                clearInterval(pollTimer);
                pollTimer = null;
                return;
            }

            const ids = [...currentList.querySelectorAll('[data-message-id]')]
                .map((el) => Number(el.dataset.messageId))
                .filter((id) => !isNaN(id) && id > 0);

            const afterId = ids.length > 0 ? Math.max(...ids) : 0;

            try {
                isPolling = true;
                const res = await fetch(`/workspace/conversations/${conversationId}/poll-messages?after_id=${afterId}`, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                if (!res.ok) return;
                const data = await res.json();
                if (Array.isArray(data?.messages) && data.messages.length > 0) {
                    let anyAppended = false;
                    for (const msg of data.messages) {
                        if (appendInboxMessage(msg)) {
                            anyAppended = true;
                        }
                    }
                    if (anyAppended) {
                        scrollMessagesToBottom(false);
                    }
                }
                if (data?.conversation) {
                    updateConversationInSidebar(data.conversation, { moveToTop: anyAppended });
                    if (data.conversation.responder) {
                        updateTopResponderBar(data.conversation.responder, data.conversation);
                    }
                }
            } catch (_) {
                // silent ignore
            } finally {
                isPolling = false;
            }
        }, 1500);
    }

    // 2. Adaptive sidebar conversations live update polling
    let isSidebarPolling = false;
    sidebarPollTimer = setInterval(async () => {
        // Skip frequent polling when WebSocket delivers conversation updates live
        if (window.__INBOX_REALTIME__?.enabled && window.__WS_CONNECTED__) {
            return;
        }

        if (isSidebarPolling) return;
        try {
            isSidebarPolling = true;
            const res = await fetch('/workspace/conversations/poll-updates', {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            const data = await res.json();
            if (Array.isArray(data?.conversations)) {
                for (const conv of data.conversations) {
                    updateConversationInSidebar(conv, { moveToTop: false });
                }
            }
        } catch (_) {
            // silent ignore
        } finally {
            isSidebarPolling = false;
        }
    }, 3000);
};

document.addEventListener('hs:before-navigate', () => {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
    if (sidebarPollTimer) {
        clearInterval(sidebarPollTimer);
        sidebarPollTimer = null;
    }
});

let inboxBooted = false;

const bootInbox = (event) => {
    const root = document.getElementById('inbox-shell');
    if (!root) {
        return;
    }
    if (inboxBooted && event?.type !== 'hs:navigated') {
        return;
    }
    inboxBooted = true;

    const workspaceId = root.dataset.workspaceId;
    if (event?.type !== 'hs:navigated') {
        restoreConversationFromStorage(workspaceId);
    }
    bindConversationListScroll(workspaceId);
    bindConversationLinks(workspaceId);
    bindComposeForm();
    bindMediaInteractions();
    bindLivePolling();
    scrollMessagesToBottom(true);

    window.__INBOX_UI__ = {
        appendInboxMessage,
        scrollMessagesToBottom,
        isNearBottom,
        updateConversationInSidebar,
        updateTopResponderBar,
        updateActiveConversationStatus,
        showTypingIndicator,
        hideTypingIndicator,
        setConnectionStatus,
        updatePresenceUI,
    };
};

document.addEventListener('DOMContentLoaded', () => bootInbox());
document.addEventListener('hs:navigated', bootInbox);
if (document.readyState !== 'loading') {
    bootInbox();
}
