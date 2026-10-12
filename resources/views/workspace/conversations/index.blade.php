<x-communication-layout flush :show-navbar="false" :show-assistant="false" secondary-sidebar page-title="التواصل — حاسم">
    @php
        $channelUi = [
            'whatsapp' => ['label' => 'WhatsApp', 'badge' => 'bg-emerald-100 text-emerald-700'],
            'instagram' => ['label' => 'Instagram', 'badge' => 'bg-pink-100 text-pink-700'],
            'facebook_messenger' => ['label' => 'Facebook Messenger', 'badge' => 'bg-blue-100 text-blue-700'],
            'email' => ['label' => 'Email', 'badge' => 'bg-amber-100 text-amber-700'],
            'web' => ['label' => 'Web', 'badge' => 'bg-slate-100 text-slate-700'],
            'manual' => ['label' => 'Manual', 'badge' => 'bg-slate-100 text-slate-700'],
        ];
    @endphp

    <div
        id="inbox-shell"
        data-workspace-id="{{ $workspaceId ?? app(\App\Support\Tenancy\WorkspaceContext::class)->workspaceId() }}"
        x-data="{ detailsOpen: false }"
        @keydown.escape.window="detailsOpen = false"
        class="hs-inbox-shell hs-inbox-shell--embedded flex min-h-0 flex-1 flex-col"
    >
        @include('partials.flash')

        <div class="flex min-h-0 flex-1">
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
