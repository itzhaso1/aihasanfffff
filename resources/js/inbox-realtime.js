import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

let echo = null;
let joined = [];
let presenceInterval = null;
let currentTrackingConversationId = null;

const leavePresence = (conversationId, csrfToken) => {
    const targetId = conversationId || currentTrackingConversationId;
    if (!targetId) return;

    const token = csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    if (navigator.sendBeacon) {
        const formData = new FormData();
        formData.append('_token', token);
        navigator.sendBeacon(`/workspace/conversations/${targetId}/presence/leave`, formData);
    } else {
        fetch(`/workspace/conversations/${targetId}/presence/leave`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token },
            keepalive: true,
        }).catch(() => {});
    }
};

const leaveAll = () => {
    if (presenceInterval) {
        clearInterval(presenceInterval);
        presenceInterval = null;
    }

    if (currentTrackingConversationId) {
        leavePresence(currentTrackingConversationId);
        currentTrackingConversationId = null;
    }

    if (!echo) {
        return;
    }

    joined.forEach((name) => {
        try {
            echo.leave(name);
        } catch (_) {
            // Channel may already be gone after a partial navigation.
        }
    });
    joined = [];
};

const bindRealtime = () => {
    leaveAll();

    if (!document.getElementById('inbox-shell')) {
        return;
    }

    const config = window.__INBOX_REALTIME__ ?? null;
    if (!config?.enabled || !config.key) {
        return;
    }

    const authEndpoint = config.authEndpoint || config.auth_endpoint || '/broadcasting/auth';

    if (!echo) {
        echo = new Echo({
            broadcaster: config.driver === 'reverb' ? 'reverb' : 'pusher',
            key: config.key,
            wsHost: config.host,
            wsPort: config.port ?? 80,
            wssPort: config.port ?? 443,
            forceTLS: (config.scheme ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
            authEndpoint: authEndpoint,
            auth: {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
            },
        });
    }

    const workspaceId = config.workspaceId;
    const activeConversationId = config.activeConversationId ?? null;
    const seenMessageIds = new Set(
        [...document.querySelectorAll('#inbox-message-list [data-message-id]')].map((el) => Number(el.dataset.messageId)),
    );

    const appendMessage = (payload) => {
        if (!payload?.id) {
            return;
        }
        const messageList = document.getElementById('inbox-message-list');
        if (!messageList) {
            return;
        }
        if (messageList.querySelector(`[data-message-id="${payload.id}"]`) || seenMessageIds.has(payload.id)) {
            return;
        }
        seenMessageIds.add(payload.id);

        if (window.__INBOX_UI__?.appendInboxMessage) {
            window.__INBOX_UI__.appendInboxMessage(payload);
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

    const syncMissedState = async () => {
        const activeId = window.__INBOX_REALTIME__?.activeConversationId ?? null;
        if (activeId) {
            const messageList = document.getElementById('inbox-message-list');
            const ids = [...(messageList?.querySelectorAll('[data-message-id]') || [])]
                .map((el) => Number(el.dataset.messageId))
                .filter((id) => !isNaN(id) && id > 0);
            const afterId = ids.length > 0 ? Math.max(...ids) : 0;
            try {
                const res = await fetch(`/workspace/conversations/${activeId}/poll-messages?after_id=${afterId}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (res.ok) {
                    const data = await res.json();
                    if (Array.isArray(data?.messages)) {
                        data.messages.forEach((msg) => appendMessage(msg));
                    }
                    if (data?.conversation) {
                        window.__INBOX_UI__?.updateConversationInSidebar?.(data.conversation, { moveToTop: true });
                        if (data.conversation.responder) {
                            window.__INBOX_UI__?.updateTopResponderBar?.(data.conversation.responder);
                        }
                    }
                }
            } catch (_) {}
        }

        // Catch up on sidebar unread counters and latest snippet
        try {
            const updatesRes = await fetch(`/workspace/conversations/poll-updates`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (updatesRes.ok) {
                const updatesData = await updatesRes.json();
                if (Array.isArray(updatesData?.conversations)) {
                    updatesData.conversations.forEach((conv) => {
                        window.__INBOX_UI__?.updateConversationInSidebar?.(conv, { moveToTop: false });
                    });
                }
            }
        } catch (_) {}
    };

    // Connection state listeners (guarded against duplicate bindings)
    if (echo?.connector?.pusher?.connection && !echo.__stateChangeBound) {
        echo.__stateChangeBound = true;
        echo.connector.pusher.connection.bind('state_change', (states) => {
            const current = states.current;
            if (current === 'connected') {
                window.__INBOX_UI__?.setConnectionStatus?.('connected');
                syncMissedState();
            } else if (current === 'connecting') {
                window.__INBOX_UI__?.setConnectionStatus?.('reconnecting');
            } else if (current === 'unavailable' || current === 'failed' || current === 'disconnected') {
                window.__INBOX_UI__?.setConnectionStatus?.('offline');
            }
        });
    }

    const handleIncomingMessage = (payload) => {
        if (!payload?.conversation_id) return;

        const currentId = window.__INBOX_REALTIME__?.activeConversationId ?? null;
        if (currentId && Number(payload.conversation_id) === Number(currentId)) {
            appendMessage(payload);
            window.__INBOX_UI__?.hideTypingIndicator?.();
        }

        const convUpdate = {
            id: payload.conversation_id,
            last_message: payload.content,
            last_message_at: payload.created_at_time || 'الآن',
            last_message_at_human: 'الآن',
            customer: payload.customer,
        };

        if (payload.direction === 'inbound' && Number(payload.conversation_id) !== Number(currentId)) {
            const link = document.querySelector(`[data-conversation-id="${payload.conversation_id}"]`);
            const badge = link?.querySelector('.bg-\\[\\#00BFAE\\].text-white') || link?.querySelector('.bg-\\[\\#06C2A4\\].text-white');
            const currentCount = badge ? (Number(badge.textContent) || 0) : 0;
            convUpdate.unread_count = currentCount + 1;
        }

        if (window.__INBOX_UI__?.updateConversationInSidebar) {
            window.__INBOX_UI__.updateConversationInSidebar(convUpdate, { moveToTop: true });
        }
    };

    const handleConversationUpdated = (payload) => {
        if (!payload?.id) return;
        if (window.__INBOX_UI__?.updateConversationInSidebar) {
            window.__INBOX_UI__.updateConversationInSidebar(payload, { moveToTop: false });
        }
        const currentId = window.__INBOX_REALTIME__?.activeConversationId ?? null;
        if (currentId && Number(payload.id) === Number(currentId)) {
            if (payload.responder) {
                window.__INBOX_UI__?.updateTopResponderBar?.(payload.responder, payload);
            }
            if (payload.status) {
                window.__INBOX_UI__?.updateActiveConversationStatus?.(payload.status);
            }
        }
    };

    const handleTypingActivity = (payload) => {
        if (!payload?.conversation_id) return;
        const currentId = window.__INBOX_REALTIME__?.activeConversationId ?? null;
        if (currentId && Number(payload.conversation_id) === Number(currentId)) {
            const currentUserId = window.__INBOX_REALTIME__?.userId;
            if (currentUserId && payload.user_id && Number(payload.user_id) === Number(currentUserId)) {
                return;
            }
            if (payload.is_typing) {
                window.__INBOX_UI__?.showTypingIndicator?.(
                    payload.sender_name,
                    payload.sender_type,
                    payload.typing_summary,
                    payload.typing_users
                );
            } else {
                window.__INBOX_UI__?.hideTypingIndicator?.();
            }
        }
    };

    const handlePresenceUpdated = (payload) => {
        if (!payload?.conversation_id) return;
        const currentId = window.__INBOX_REALTIME__?.activeConversationId ?? null;
        if (currentId && Number(payload.conversation_id) === Number(currentId)) {
            window.__INBOX_UI__?.updatePresenceUI?.(payload);
        }
    };

    // Subscriptions
    const workspaceChannel = `workspace.${workspaceId}.conversations`;
    echo.private(workspaceChannel)
        .listen('.message.created', handleIncomingMessage)
        .listen('.conversation.updated', handleConversationUpdated);
    joined.push(workspaceChannel);

    if (activeConversationId) {
        currentTrackingConversationId = activeConversationId;
        const conversationChannel = `workspace.${workspaceId}.conversation.${activeConversationId}`;
        echo.private(conversationChannel)
            .listen('.message.created', handleIncomingMessage)
            .listen('.conversation.updated', handleConversationUpdated)
            .listen('.typing.activity', handleTypingActivity)
            .listen('.employee.joined', handlePresenceUpdated)
            .listen('.employee.left', handlePresenceUpdated)
            .listen('.employee.presence_updated', handlePresenceUpdated);
        joined.push(conversationChannel);

        // Presence Heartbeat Loop (every 20 seconds)
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const pingPresence = (state = 'viewing') => {
            fetch(`/workspace/conversations/${activeConversationId}/presence/ping`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ state }),
            })
            .then(res => res.ok ? res.json() : null)
            .then(data => {
                if (data?.data) {
                    window.__INBOX_UI__?.updatePresenceUI?.(data.data);
                }
            })
            .catch(() => {});
        };

        pingPresence('viewing');
        presenceInterval = setInterval(() => pingPresence('viewing'), 20000);
    }
};

window.addEventListener('beforeunload', () => {
    leaveAll();
});

document.addEventListener('hs:before-navigate', () => {
    leaveAll();
});

document.addEventListener('hs:navigated', bindRealtime);
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindRealtime, { once: true });
} else {
    bindRealtime();
}
