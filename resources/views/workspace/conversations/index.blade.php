<x-communication-layout flush page-title="التواصل — حاسم">
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
        class="hs-inbox-shell flex flex-col"
    >
        <div class="border-b border-slate-100 bg-white px-4 py-3 sm:px-5">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-[11px] font-semibold text-[#067e6b]">Omnichannel Inbox</p>
                    <h1 class="text-base font-bold text-slate-900">التواصل</h1>
                    <p class="mt-0.5 text-xs text-slate-500">إدارة جميع محادثات العملاء عبر جميع القنوات من مكان واحد</p>
                </div>
            </div>
        </div>

        @include('partials.flash')
        @include('workspace.communication.inbox.toolbar')

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
        @if($inboxRealtime['enabled'] ?? false)
            <script>
                window.__INBOX_REALTIME__ = @json($inboxRealtime);
            </script>
            @vite('resources/js/inbox-realtime.js')
        @endif
    @endpush
</x-communication-layout>
