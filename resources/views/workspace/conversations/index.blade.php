<x-communication-layout flush :show-navbar="false" :show-assistant="false" secondary-sidebar page-title="المحادثات — حاسم">
    @php
        $channelUi = [
            'whatsapp' => ['label' => 'WhatsApp', 'badge' => 'bg-emerald-50 text-emerald-700 border border-emerald-200/80', 'color' => '#25D366', 'dot' => 'bg-emerald-500'],
            'instagram' => ['label' => 'Instagram', 'badge' => 'bg-pink-50 text-pink-700 border border-pink-200/80', 'color' => '#E4405F', 'dot' => 'bg-pink-500'],
            'facebook_messenger' => ['label' => 'Messenger', 'badge' => 'bg-blue-50 text-blue-700 border border-blue-200/80', 'color' => '#0084FF', 'dot' => 'bg-blue-500'],
            'email' => ['label' => 'Email', 'badge' => 'bg-amber-50 text-amber-700 border border-amber-200/80', 'color' => '#F59E0B', 'dot' => 'bg-amber-500'],
            'web' => ['label' => 'Web', 'badge' => 'bg-teal-50 text-teal-700 border border-teal-200/80', 'color' => '#00BFAE', 'dot' => 'bg-teal-500'],
            'manual' => ['label' => 'Manual', 'badge' => 'bg-slate-100 text-slate-700 border border-slate-200/80', 'color' => '#64748B', 'dot' => 'bg-slate-400'],
        ];
    @endphp

    <div
        id="inbox-shell"
        data-workspace-id="{{ $workspaceId ?? app(\App\Support\Tenancy\WorkspaceContext::class)->workspaceId() }}"
        data-active-conversation-id="{{ $activeConversation?->id }}"
        x-data="{ detailsOpen: false }"
        @keydown.escape.window="detailsOpen = false"
        class="hs-inbox-shell hs-inbox-shell--embedded flex min-h-0 flex-1 h-full max-h-full flex-col overflow-hidden"
    >
        @include('partials.flash')

        <div class="flex min-h-0 flex-1 h-full max-h-full overflow-hidden">
            @include('workspace.communication.inbox.conversation-list')

            @if($activeConversation)
                @include('workspace.communication.inbox.conversation-view')
                @include('workspace.communication.inbox.customer-details')
            @else
                @include('workspace.communication.inbox.empty-state')
            @endif
        </div>
    </div>

    @php
        $workspaceId = app(\App\Support\Tenancy\WorkspaceContext::class)->workspaceId();
        $inboxRealtime = array_merge(
            \App\Support\Inbox\InboxBroadcastConfig::clientPayload('/broadcasting/auth'),
            [
                'workspaceId' => $workspaceId,
                'activeConversationId' => $activeConversation?->id,
                'userId' => auth()->id(),
            ],
        );
    @endphp

    @push('scripts')
        @vite('resources/js/inbox-ui.js')
        @if(($inboxRealtimeEnabled ?? true) && ($inboxRealtime['enabled'] ?? false))
            <script>
                window.__INBOX_REALTIME__ = @json($inboxRealtime);
            </script>
            @vite('resources/js/inbox-realtime.js')
        @endif
    @endpush
</x-communication-layout>
