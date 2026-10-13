@php
    $inboxIndexRoute = $inboxIndexRoute ?? 'workspace.conversations.index';
    $inboxRouteParameters = $inboxRouteParameters ?? [];
    $inboxAllowsCompanyActions = $inboxAllowsCompanyActions ?? true;
    $listQuery = fn (?int $conversationId = null, array $overrides = []) => array_filter(array_merge([
        'search' => request('search'),
        'channel' => request('channel'),
        'status' => request('status'),
        'unread' => request('unread'),
        'handoff' => request('handoff'),
        'conversation' => $conversationId,
    ], $overrides), fn ($value) => $value !== null && $value !== '');
    $inboxUrl = fn (?int $conversationId = null, array $overrides = []) => route(
        $inboxIndexRoute,
        array_merge($inboxRouteParameters, $listQuery($conversationId, $overrides)),
    );
@endphp

<aside @class([
    'h-full min-h-0 w-full max-w-full flex-col border-slate-200 bg-white md:flex md:w-[22rem] md:max-w-[22rem] md:border-e xl:w-[23rem] xl:max-w-[23rem]',
    'flex' => ! $activeConversation,
    'hidden' => $activeConversation,
])>
    <style>
        .inbox-filter-trigger {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            background: #fff;
            color: #475569;
            padding: 0.2rem 0.7rem;
            font-size: 11px;
            font-weight: 600;
            line-height: 1.25rem;
            cursor: pointer;
        }
        .inbox-filter-trigger.is-on {
            border-color: #C9F0E8;
            background: #E8FAF6;
            color: #067e6b;
        }
        .inbox-filter-menu {
            position: absolute;
            z-index: 30;
            top: 100%;
            margin-top: 0.3rem;
            min-width: 11.25rem;
            overflow: hidden;
            border-radius: 0.85rem;
            border: 1px solid #e8eef3;
            background: #fff;
            padding: 0.3rem;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.12);
        }
        .inbox-filter-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border-radius: 0.65rem;
            padding: 0.45rem 0.6rem;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
        }
        .inbox-filter-item svg {
            height: 0.95rem;
            width: 0.95rem;
            flex: none;
        }
        .inbox-filter-item:hover,
        .inbox-filter-item.is-active {
            background: #E8FAF6;
            color: #067e6b;
        }
    </style>
    <div class="border-b border-slate-100 px-4 py-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="shrink-0">
                <h3 class="text-sm font-bold text-slate-900">المحادثات</h3>
                <p class="text-[11px] text-slate-500">{{ $conversations->total() }} محادثة</p>
            </div>
            @php
                $handoffOptions = [
                    'ai' => 'AI',
                    'handoff' => 'تحويل بشري',
                    'queued' => 'بانتظار موظف',
                    'assigned_me' => 'معي',
                    'active' => 'بشري نشط',
                    'resolved' => 'تحويل منتهٍ',
                ];
                $handoffIcons = [
                    'ai' => 'M12 3.5v2.2M12 18.3V20.5M3.5 12h2.2M18.3 12H20.5M6 6l1.6 1.6M16.4 16.4 18 18M18 6l-1.6 1.6M7.6 16.4 6 18',
                    'handoff' => 'M16 19v-1.2a3 3 0 0 0-3-3H7a3 3 0 0 0-3 3V19M10 11.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM20 19v-1.1a2.6 2.6 0 0 0-2-2.5M16.2 5.6a2.5 2.5 0 0 1 0 4.8',
                    'queued' => 'M12 7v5l3 2M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z',
                    'assigned_me' => 'M12 12a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4ZM5.5 19.2a6.5 6.5 0 0 1 13 0',
                    'active' => 'M4 12h3l2-5 4 10 2-5h5',
                    'resolved' => 'M5 12.5 9 16.5 19 6.5',
                ];
                $handoffFilter = request('handoff');
            @endphp
            <div class="flex min-w-0 flex-wrap items-center gap-1">
                @if(! $inboxAllowsCompanyActions)
                    <form method="POST" action="{{ route('logout') }}" class="ms-auto">
                        @csrf
                        <button type="submit" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            تسجيل الخروج
                        </button>
                    </form>
                @endif
                <div class="relative" data-hs-menu x-data="{ open: false }" @keydown.escape.window="open = false">
                    <button
                        type="button"
                        @click="open = !open"
                        :aria-expanded="open.toString()"
                        aria-label="كل المحادثات"
                        class="inbox-filter-trigger"
                        :class="(open || {{ $handoffFilter ? 'true' : 'false' }}) ? 'is-on' : ''"
                    >
                        <span data-hs-filter-label>{{ $handoffOptions[$handoffFilter] ?? 'كل المحادثات' }}</span>
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div x-show="open" x-cloak @click.outside="open = false" class="inbox-filter-menu" style="inset-inline-end:0">
                        @foreach($handoffOptions as $handoffKey => $handoffLabel)
                            <a href="{{ $inboxUrl(request()->integer('conversation') ?: null, ['handoff' => $handoffFilter === $handoffKey ? null : $handoffKey]) }}" class="inbox-filter-item {{ $handoffFilter === $handoffKey ? 'is-active' : '' }}">
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $handoffIcons[$handoffKey] }}"/></svg>
                                <span>{{ $handoffLabel }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        <form method="GET" action="{{ route($inboxIndexRoute, $inboxRouteParameters) }}" class="mt-3">
            @foreach(['conversation', 'channel', 'status', 'handoff', 'unread'] as $queryKey)
                @if(request()->filled($queryKey))
                    <input type="hidden" name="{{ $queryKey }}" value="{{ request($queryKey) }}">
                @endif
            @endforeach
            <label class="relative block">
                <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-slate-400">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="m21 21-4.35-4.35M17 10.5A6.5 6.5 0 1 1 4 10.5a6.5 6.5 0 0 1 13 0Z" />
                    </svg>
                </span>
                <input
                    name="search"
                    value="{{ request('search') }}"
                    class="hs-input w-full pr-10"
                    placeholder="ابحث باسم العميل أو رقم الهاتف أو External ID..."
                />
            </label>
        </form>
    </div>

    <div class="flex flex-wrap items-center gap-1 border-b border-slate-100 px-3 py-2">
        <span data-hs-filter-group class="inline-flex items-center gap-1">
        <a
            href="{{ $inboxUrl(request()->integer('conversation') ?: null, ['unread' => null]) }}"
            class="{{ ! request()->boolean('unread') ? 'bg-[#E8FAF6] text-[#067e6b]' : 'text-slate-500 hover:bg-slate-50' }} rounded-full px-3 py-1 text-[11px] font-semibold transition"
        >
            الكل ({{ $conversations->total() }})
        </a>
        <a
            href="{{ $inboxUrl(request()->integer('conversation') ?: null, ['unread' => 1]) }}"
            class="{{ request()->boolean('unread') ? 'bg-[#E8FAF6] text-[#067e6b]' : 'text-slate-500 hover:bg-slate-50' }} rounded-full px-3 py-1 text-[11px] font-semibold transition"
        >
            غير مقروءة
        </a>
        </span>
        <div class="relative" data-hs-menu x-data="{ open: false }" @keydown.escape.window="open = false">
            <button
                type="button"
                @click="open = !open"
                :aria-expanded="open.toString()"
                aria-label="القنوات"
                class="{{ $channelFilter ? 'border-[#C9F0E8] bg-[#E8FAF6] text-[#067e6b]' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50' }} inline-flex h-6 w-6 items-center justify-center rounded-full border transition"
            >
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6" />
                </svg>
            </button>
            <div
                x-show="open"
                x-cloak
                @click.outside="open = false"
                class="absolute start-0 top-full z-20 mt-1 min-w-[11rem] overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-lg"
            >
                @foreach($availableChannels as $channelKey => $channelLabel)
                    <a
                        href="{{ $inboxUrl(request()->integer('conversation') ?: null, ['channel' => $channelFilter === $channelKey ? null : $channelKey]) }}"
                        class="{{ $channelFilter === $channelKey ? 'bg-[#E8FAF6] text-[#067e6b]' : 'text-slate-600 hover:bg-slate-50' }} block px-3 py-1.5 text-[11px] font-semibold"
                    >
                        {{ $channelLabel }}
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto" data-inbox-conversation-list data-hs-result>
        @forelse($conversations as $conversation)
            @php
                $isActive = $activeConversation && $activeConversation->id === $conversation->id;
                $preview = $conversation->messages->first();
                $displayChannel = $conversation->display_channel ?? $conversation->channel;
                $channelChip = $channelUi[$displayChannel] ?? $channelUi['manual'];
                $unreadCount = (int) ($conversation->unread_count ?? 0);
                $handoffStatus = $conversation->handoff_status instanceof \App\Enums\ConversationHandoffStatus
                    ? $conversation->handoff_status->value
                    : ($conversation->handoff_status ?? 'none');
                $customerName = $conversation->customer?->name ?? 'عميل غير معروف';
                $initial = mb_substr($customerName, 0, 1);
                $assignedType = \App\Services\Workforce\WorkforceInboxAttribution::typeForConversation($conversation);
            @endphp
            <a
                href="{{ $inboxUrl($conversation->id) }}"
                data-conversation-id="{{ $conversation->id }}"
                class="{{ $isActive ? 'border-[#C9F0E8] bg-[#E8FAF6]' : 'border-transparent hover:bg-slate-50' }} block border-b border-slate-100 px-4 py-3 transition"
            >
                <div class="flex items-start gap-3">
                    <span class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-bold text-slate-600">
                        {{ $initial }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <p class="truncate text-sm font-semibold text-slate-900">{{ $customerName }}</p>
                            <span class="shrink-0 text-[11px] text-slate-400">
                                {{ $conversation->last_message_at?->format('h:i A') ?? '' }}
                            </span>
                        </div>
                        <p class="mt-0.5 truncate text-xs text-slate-500">
                            {{ $preview?->content ?: 'لا توجد رسائل بعد' }}
                        </p>
                        <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap gap-1">
                            <span class="{{ $channelChip['badge'] }} rounded-full px-2 py-0.5 text-[10px] font-semibold">{{ $channelChip['label'] }}</span>
                            @if($conversation->isAiReplying())
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800">رد AI</span>
                            @elseif($assignedType === \App\Enums\WorkforceParticipantType::AiEmployee)
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800">AI Employee</span>
                            @endif
                            @if($handoffStatus !== 'none')
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">{{ $handoffStatus }}</span>
                            @endif
                            </div>
                            @if($unreadCount > 0)
                                <span class="rounded-full bg-[#06C2A4] px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $unreadCount }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </a>
        @empty
            <div class="flex h-full items-center justify-center p-4">
                <div class="w-full rounded-2xl border border-[#C9F0E8] bg-white px-4 py-8 text-center">
                    <span class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-[#E8FAF6] text-[#06C2A4]">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M7.5 17.25H4.875A1.875 1.875 0 0 1 3 15.375V5.625A1.875 1.875 0 0 1 4.875 3.75h14.25A1.875 1.875 0 0 1 21 5.625v9.75a1.875 1.875 0 0 1-1.875 1.875H12l-4.5 3v-3Z" />
                        </svg>
                    </span>
                    <p class="text-sm font-semibold text-slate-900">لا توجد محادثات</p>
                    <p class="mt-2 text-xs leading-5 text-slate-500">{{ request()->filled('search') || request()->filled('channel') || request()->filled('status') || request()->boolean('unread') ? 'لا توجد نتائج مطابقة لعوامل التصفية الحالية.' : 'ابدأ بإنشاء محادثة جديدة للتواصل مع العملاء.' }}</p>
                    @if($inboxAllowsCompanyActions)
                        <a href="{{ route('workspace.conversations.create') }}" class="hs-btn-primary mt-4 text-xs">+ محادثة جديدة</a>
                    @endif
                </div>
            </div>
        @endforelse
    </div>

    @if($conversations->hasPages())
        <div class="border-t border-slate-100 px-3 py-2">
            {{ $conversations->onEachSide(0)->links() }}
        </div>
    @endif
</aside>
