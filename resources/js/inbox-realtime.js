import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const config = window.__INBOX_REALTIME__ ?? null;

if (config?.enabled && config.key) {
    const echo = new Echo({
        broadcaster: config.driver === 'reverb' ? 'reverb' : 'pusher',
        key: config.key,
        wsHost: config.host,
        wsPort: config.port ?? 80,
        wssPort: config.port ?? 443,
        forceTLS: (config.scheme ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: config.authEndpoint,
        auth: {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        },
    });

    const workspaceId = config.workspaceId;
    const activeConversationId = config.activeConversationId ?? null;

    const messageList = document.getElementById('inbox-message-list');
    const seenMessageIds = new Set(
        messageList
            ? [...messageList.querySelectorAll('[data-message-id]')].map((el) => Number(el.dataset.messageId))
            : [],
    );

    const appendMessage = (payload) => {
        if (!payload?.id || seenMessageIds.has(payload.id)) {
            return;
        }
        seenMessageIds.add(payload.id);

        if (window.__INBOX_UI__?.appendInboxMessage) {
            window.__INBOX_UI__.appendInboxMessage(payload);
            return;
        }

        if (!messageList) {
            return;
        }

        const outbound = payload.direction === 'outbound' || payload.direction === 'internal_note';
        const wrap = document.createElement('div');
        wrap.className = `hs-inbox-message-row ${outbound ? 'hs-inbox-message-row--sent' : 'hs-inbox-message-row--received'}`;
        wrap.dataset.messageId = String(payload.id);

        const bubble = document.createElement('div');
        bubble.className = outbound
            ? 'hs-inbox-message-bubble max-w-[78%] rounded-2xl rounded-es-md bg-[#DDF6F1] px-4 py-2 text-slate-900 shadow-sm'
            : 'hs-inbox-message-bubble max-w-[78%] rounded-2xl rounded-ee-md border border-slate-200 bg-white px-4 py-2 text-slate-800 shadow-sm';

        const text = document.createElement('p');
        text.className = 'text-sm leading-6';
        text.textContent = payload.content || '—';
        bubble.appendChild(text);

        wrap.appendChild(bubble);
        messageList.appendChild(wrap);

        const nearBottom = window.__INBOX_UI__?.isNearBottom?.(messageList) ?? true;
        if (nearBottom) {
            messageList.scrollTop = messageList.scrollHeight;
        }
    };

    const touchConversationList = (payload) => {
        if (!payload?.id) {
            return;
        }
        const row = document.querySelector(`[data-conversation-id="${payload.id}"]`);
        if (row) {
            row.classList.add('bg-[#E8FAF6]');
        }
    };

    echo.private(`workspace.${workspaceId}.conversations`)
        .listen('.message.created', (payload) => {
            if (activeConversationId && payload.conversation_id === activeConversationId) {
                appendMessage(payload);
            }
        })
        .listen('.conversation.updated', (payload) => {
            touchConversationList(payload);
        });

    if (activeConversationId) {
        echo.private(`workspace.${workspaceId}.conversation.${activeConversationId}`)
            .listen('.message.created', appendMessage);
    }
}
