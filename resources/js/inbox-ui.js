const SCROLL_STORAGE_PREFIX = 'hasem.inbox.listScroll.';
const CONVERSATION_STORAGE_PREFIX = 'hasem.inbox.conversation.';

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
};

const renderAttachmentHtml = (attachment) => {
    if (!attachment?.download_url) {
        return '<span class="text-xs text-slate-500">جاري تجهيز المرفق…</span>';
    }

    if (attachment.kind === 'image') {
        return `<img src="${attachment.download_url}" alt="${attachment.original_name || 'image'}" class="max-h-60 rounded-xl border border-slate-200/90 object-cover shadow-2xs" loading="lazy" />`;
    }

    if (attachment.kind === 'audio') {
        return `<div class="rounded-xl border border-slate-200/80 bg-white/80 p-2 shadow-2xs"><audio controls preload="none" class="w-full max-w-xs"><source src="${attachment.download_url}" type="${attachment.mime_type || 'audio/mpeg'}"></audio></div>`;
    }

    if (attachment.kind === 'video') {
        return `<video controls preload="metadata" class="max-h-60 w-full rounded-xl border border-slate-200 shadow-2xs"><source src="${attachment.download_url}" type="${attachment.mime_type || 'video/mp4'}"></video>`;
    }

    return `<a href="${attachment.download_url}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200/90 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-2xs transition hover:bg-slate-50"><svg class="h-4 w-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg><span>${attachment.original_name || 'ملف مرفق'}</span></a>`;
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

    const tempId = payload.temp_id;
    if (tempId) {
        const tempNode = list.querySelector(`[data-temp-id="${tempId}"]`);
        if (tempNode) {
            return tempNode;
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
        bubble.className = 'hs-inbox-message-bubble max-w-[80%] rounded-2xl px-4 py-2.5 mx-auto border border-amber-300 bg-amber-50/90 text-amber-950 shadow-xs';
    } else if (outbound) {
        bubble.className = 'hs-inbox-message-bubble max-w-[80%] rounded-2xl px-4 py-2.5 rounded-bl-xs bg-[#D8F6F0] text-slate-900 border border-[#BDEFE5]/70 shadow-[0_1px_2px_rgba(0,0,0,0.04)]';
    } else {
        bubble.className = 'hs-inbox-message-bubble max-w-[80%] rounded-2xl px-4 py-2.5 rounded-br-xs border border-slate-200/80 bg-white text-slate-800 shadow-[0_1px_2px_rgba(0,0,0,0.04)]';
    }

    if (isInternalNote) {
        const noteBadge = document.createElement('div');
        noteBadge.className = 'mb-1.5 flex items-center gap-1 text-[11px] font-bold text-amber-800';
        noteBadge.innerHTML = `<svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg><span>ملاحظة داخلية</span>`;
        bubble.appendChild(noteBadge);
    }

    if (Array.isArray(payload.attachments) && payload.attachments.length > 0) {
        const attachmentsWrap = document.createElement('div');
        attachmentsWrap.className = 'mb-2 space-y-2';
        payload.attachments.forEach((attachment) => {
            attachmentsWrap.insertAdjacentHTML('beforeend', renderAttachmentHtml(attachment));
        });
        bubble.appendChild(attachmentsWrap);
    }

    const text = document.createElement('div');
    text.className = 'text-[13.5px] leading-relaxed whitespace-pre-wrap font-normal';
    text.textContent = payload.content || '—';
    bubble.appendChild(text);

    // Message Metadata Footer
    const footer = document.createElement('div');
    footer.className = 'mt-1 flex items-center justify-end gap-1.5 text-[10px] text-slate-400 select-none';

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
        checkmark.innerHTML = `<svg class="h-3.5 w-3.5 text-[#00BFAE]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m4.5 12.75 4 4 9.5-9.5m-5 9.5 4 4 9.5-9.5" /></svg>`;
        footer.appendChild(checkmark);
    }

    bubble.appendChild(footer);
    wrap.appendChild(bubble);
    list.appendChild(wrap);
    scrollMessagesToBottom(!optimistic);

    return wrap;
};

const inArray = (val, arr) => arr.indexOf(val) !== -1;

const updateConversationInSidebar = (conv) => {
    if (!conv?.id) return;
    const link = document.querySelector(`[data-conversation-id="${conv.id}"]`);
    if (!link) return;

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
        let badge = badgeWrap.querySelector('.bg-\\[\\#00BFAE\\].text-white');
        const count = Number(conv.unread_count) || 0;
        if (count > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-[#00BFAE] px-1.5 text-[10px] font-bold text-white shadow-2xs';
                badgeWrap.appendChild(badge);
            }
            badge.textContent = String(count);
        } else if (badge) {
            badge.remove();
        }
    }

    // Move to top of conversations list
    const container = document.querySelector('[data-inbox-conversation-list]');
    if (container && container.firstElementChild && container.firstElementChild !== row) {
        container.insertBefore(row, container.firstElementChild);
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

        textarea.addEventListener('input', resizeTextarea);

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

        if (content !== '') {
            appendInboxMessage(
                {
                    temp_id: tempId,
                    direction,
                    content,
                    attachments: [],
                },
                { optimistic: true },
            );
        }

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
            const node = document.querySelector(`[data-temp-id="${tempId}"]`);
            if (node) {
                node.remove();
            }

            if (data?.message) {
                appendInboxMessage(data.message);
                if (data?.conversation) {
                    updateConversationInSidebar(data.conversation);
                }
            }

            if (textarea) {
                textarea.value = '';
                textarea.style.height = '';
            }
        } catch (_) {
            const node = document.querySelector(`[data-temp-id="${tempId}"]`);
            if (node) {
                node.remove();
            }
            window.alert('تعذر إرسال الرسالة. حاول مرة أخرى.');
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
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

    // 1. High-frequency active chat polling (every 1.2s)
    if (conversationId) {
        let isPolling = false;
        pollTimer = setInterval(async () => {
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
                    updateConversationInSidebar(data.conversation);
                }
            } catch (_) {
                // silent ignore
            } finally {
                isPolling = false;
            }
        }, 1200);
    }

    // 2. Sidebar conversations live update polling (every 2.5s)
    let isSidebarPolling = false;
    sidebarPollTimer = setInterval(async () => {
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
                    updateConversationInSidebar(conv);
                }
            }
        } catch (_) {
            // silent ignore
        } finally {
            isSidebarPolling = false;
        }
    }, 2500);
};

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
    bindLivePolling();
    scrollMessagesToBottom(true);

    window.__INBOX_UI__ = {
        appendInboxMessage,
        scrollMessagesToBottom,
        isNearBottom,
    };
};

document.addEventListener('DOMContentLoaded', () => bootInbox());
document.addEventListener('hs:navigated', bootInbox);
if (document.readyState !== 'loading') {
    bootInbox();
}
