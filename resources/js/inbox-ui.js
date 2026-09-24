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
    if (!list || !workspaceId) {
        return;
    }

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
        return `<img src="${attachment.download_url}" alt="${attachment.original_name || 'image'}" class="max-h-56 rounded-lg border border-slate-200 object-cover" loading="lazy" />`;
    }

    if (attachment.kind === 'audio') {
        return `<audio controls preload="none" class="w-full max-w-xs"><source src="${attachment.download_url}" type="${attachment.mime_type || 'audio/mpeg'}"></audio>`;
    }

    if (attachment.kind === 'video') {
        return `<video controls preload="metadata" class="max-h-56 w-full rounded-lg"><source src="${attachment.download_url}" type="${attachment.mime_type || 'video/mp4'}"></video>`;
    }

    return `<a href="${attachment.download_url}" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">${attachment.original_name || 'ملف'}</a>`;
};

const appendInboxMessage = (payload, { optimistic = false } = {}) => {
    const list = document.getElementById('inbox-message-list');
    if (!list || !payload) {
        return null;
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

    const outbound = payload.direction === 'outbound' || payload.direction === 'internal_note';
    const wrap = document.createElement('div');
    wrap.className = `hs-inbox-message-row ${outbound ? 'hs-inbox-message-row--sent' : 'hs-inbox-message-row--received'}`;
    if (payload.id) {
        wrap.dataset.messageId = String(payload.id);
    }
    if (tempId) {
        wrap.dataset.tempId = String(tempId);
    }

    const bubble = document.createElement('div');
    bubble.className = outbound
        ? 'hs-inbox-message-bubble max-w-[78%] rounded-2xl rounded-es-md bg-[#DDF6F1] px-4 py-2 text-slate-900 shadow-sm'
        : 'hs-inbox-message-bubble max-w-[78%] rounded-2xl rounded-ee-md border border-slate-200 bg-white px-4 py-2 text-slate-800 shadow-sm';

    if (Array.isArray(payload.attachments) && payload.attachments.length > 0) {
        const attachmentsWrap = document.createElement('div');
        attachmentsWrap.className = 'mb-2 space-y-2';
        payload.attachments.forEach((attachment) => {
            attachmentsWrap.insertAdjacentHTML('beforeend', renderAttachmentHtml(attachment));
        });
        bubble.appendChild(attachmentsWrap);
    }

    const text = document.createElement('p');
    text.className = 'text-sm leading-6';
    text.textContent = payload.content || '—';
    bubble.appendChild(text);

    wrap.appendChild(bubble);
    list.appendChild(wrap);
    scrollMessagesToBottom(!optimistic);

    return wrap;
};

const bindComposeForm = () => {
    const form = document.getElementById('inbox-compose-form');
    if (!form) {
        return;
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
            }

            const textarea = form.querySelector('textarea[name="content"]');
            if (textarea) {
                textarea.value = '';
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

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('inbox-shell');
    const workspaceId = root?.dataset?.workspaceId;

    restoreConversationFromStorage(workspaceId);
    bindConversationListScroll(workspaceId);
    bindConversationLinks(workspaceId);
    bindComposeForm();
    scrollMessagesToBottom(true);

    window.__INBOX_UI__ = {
        appendInboxMessage,
        scrollMessagesToBottom,
        isNearBottom,
    };
});
