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
    'h-full min-h-0 w-full max-w-full flex-col border-slate-200/90 bg-white md:flex md:w-[22rem] md:max-w-[22rem] md:border-e lg:w-[24rem] lg:max-w-[24rem] xl:w-[26rem] xl:max-w-[26rem]',
    'flex' => ! $activeConversation,
    'hidden' => $activeConversation,
])>
    <div class="border-b border-slate-100 bg-white px-4 py-3.5">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <h3 class="text-base font-bold text-slate-900">المحادثات</h3>
                <span class="inline-flex items-center rounded-full bg-[#E8FAF6] px-2.5 py-0.5 text-xs font-bold text-[#067e6b]">
                    {{ $conversations->total() }}
                </span>
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
            <div class="flex items-center gap-1.5">
                @if($inboxAllowsCompanyActions)
                    <a
                        href="{{ route('workspace.conversations.create') }}"
                        class="inline-flex items-center gap-1 rounded-xl bg-[#00BFAE] px-2.5 py-1.5 text-xs font-semibold text-white shadow-xs transition hover:bg-[#06a89a]"
                        title="إنشاء محادثة جديدة"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 4v16m8-8H4"/></svg>
                        <span>جديدة</span>
                    </a>
                @endif
                @if(! $inboxAllowsCompanyActions)
                    <form method="POST" action="{{ route('logout') }}" class="ms-auto">
                        @csrf
                        <button type="submit" class="rounded-xl border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            تسجيل الخروج
                        </button>
                    </form>
                @endif
                <div class="relative" data-hs-menu x-data="{ open: false }" @keydown.escape.window="open = false">
                    <button
                        type="button"
                        @click="open = !open"
                        :aria-expanded="open.toString()"
                        aria-label="تصفية المحادثات"
                        class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-600 shadow-2xs transition hover:bg-slate-50 focus:outline-none"
                        :class="(open || {{ $handoffFilter ? 'true' : 'false' }}) ? 'border-[#C9F0E8] bg-[#E8FAF6] text-[#067e6b]' : ''"
                    >
                        <span data-hs-filter-label>{{ $handoffOptions[$handoffFilter] ?? 'كل الحالات' }}</span>
                        <svg class="h-3 w-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div
                        x-show="open"
                        x-cloak
                        x-transition.origin.top
                        @click.outside="open = false"
                        class="absolute z-30 mt-1.5 min-w-[12rem] overflow-hidden rounded-2xl border border-slate-200/90 bg-white p-1.5 shadow-xl"
                        style="inset-inline-end:0"
                    >
                        <a
                            href="{{ $inboxUrl(request()->integer('conversation') ?: null, ['handoff' => null]) }}"
                            class="flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold {{ ! $handoffFilter ? 'bg-[#E8FAF6] text-[#067e6b]' : 'text-slate-600 hover:bg-slate-50' }}"
                        >
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"/></svg>
                            <span>كل الحالات</span>
                        </a>
                        @foreach($handoffOptions as $handoffKey => $handoffLabel)
                            <a
                                href="{{ $inboxUrl(request()->integer('conversation') ?: null, ['handoff' => $handoffFilter === $handoffKey ? null : $handoffKey]) }}"
                                class="flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold {{ $handoffFilter === $handoffKey ? 'bg-[#E8FAF6] text-[#067e6b]' : 'text-slate-600 hover:bg-slate-50' }}"
                            >
                                <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $handoffIcons[$handoffKey] }}"/></svg>
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
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-slate-400">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m21 21-4.35-4.35M17 10.5A6.5 6.5 0 1 1 4 10.5a6.5 6.5 0 0 1 13 0Z" />
                    </svg>
                </span>
                <input
                    name="search"
                    value="{{ request('search') }}"
                    class="w-full rounded-xl border border-slate-200/90 bg-slate-50/80 py-2 pe-3 ps-9 text-xs text-slate-800 placeholder-slate-400 transition focus:border-[#00BFAE] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#00BFAE]/15"
                    placeholder="ابحث باسم العميل أو رقم الهاتف..."
                />
                @if(request('search'))
                    <a
                        href="{{ $inboxUrl(request()->integer('conversation') ?: null, ['search' => null]) }}"
                        class="absolute inset-y-0 end-2.5 flex items-center text-slate-400 hover:text-slate-600"
                        title="مسح البحث"
                    >
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </a>
                @endif
            </div>
        </form>
    </div>

    <div class="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50/50 px-3 py-2 overflow-x-auto">
        <span data-hs-filter-group class="inline-flex items-center gap-1.5">
            <a
                href="{{ $inboxUrl(request()->integer('conversation') ?: null, ['unread' => null]) }}"
                class="{{ ! request()->boolean('unread') ? 'bg-[#00BFAE] text-white shadow-2xs font-bold' : 'bg-white text-slate-600 border border-slate-200/80 hover:bg-slate-50 font-semibold' }} rounded-full px-3 py-1 text-[11px] transition"
            >
                الكل ({{ $conversations->total() }})
            </a>
            <a
                href="{{ $inboxUrl(request()->integer('conversation') ?: null, ['unread' => 1]) }}"
                class="{{ request()->boolean('unread') ? 'bg-[#00BFAE] text-white shadow-2xs font-bold' : 'bg-white text-slate-600 border border-slate-200/80 hover:bg-slate-50 font-semibold' }} inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[11px] transition"
            >
                <span class="h-1.5 w-1.5 rounded-full {{ request()->boolean('unread') ? 'bg-white' : 'bg-teal-500' }}"></span>
                <span>غير مقروءة</span>
            </a>
        </span>
        <div class="relative ms-auto" data-hs-menu x-data="{ open: false }" @keydown.escape.window="open = false">
            <button
                type="button"
                @click="open = !open"
                :aria-expanded="open.toString()"
                aria-label="تصفية حسب القنوات"
                class="{{ $channelFilter ? 'border-[#C9F0E8] bg-[#E8FAF6] text-[#067e6b]' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' }} inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-[11px] font-semibold transition"
            >
                <span>{{ $channelFilter ? ($availableChannels[$channelFilter] ?? $channelFilter) : 'القناة' }}</span>
                <svg class="h-3 w-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6" />
                </svg>
            </button>
            <div
                x-show="open"
                x-cloak
                x-transition.origin.top
                @click.outside="open = false"
                class="absolute end-0 top-full z-20 mt-1 min-w-[11rem] overflow-hidden rounded-2xl border border-slate-200 bg-white p-1 shadow-lg"
            >
                <a
                    href="{{ $inboxUrl(request()->integer('conversation') ?: null, ['channel' => null]) }}"
                    class="{{ ! $channelFilter ? 'bg-[#E8FAF6] text-[#067e6b]' : 'text-slate-600 hover:bg-slate-50' }} block rounded-xl px-3 py-1.5 text-xs font-semibold"
                >
                    كل القنوات
                </a>
                @foreach($availableChannels as $channelKey => $channelLabel)
                    <a
                        href="{{ $inboxUrl(request()->integer('conversation') ?: null, ['channel' => $channelFilter === $channelKey ? null : $channelKey]) }}"
                        class="{{ $channelFilter === $channelKey ? 'bg-[#E8FAF6] text-[#067e6b]' : 'text-slate-600 hover:bg-slate-50' }} block rounded-xl px-3 py-1.5 text-xs font-semibold"
                    >
                        {{ $channelLabel }}
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto divide-y divide-slate-100" data-inbox-conversation-list data-hs-result>
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
                class="{{ $isActive ? 'border-s-[3px] border-s-[#00BFAE] bg-[#F0FBF8]' : 'border-s-[3px] border-s-transparent hover:bg-slate-50/80' }} block px-4 py-3.5 transition"
            >
                <div class="flex items-start gap-3">
                    <div class="relative shrink-0">
                        <span class="flex h-11 w-11 items-center justify-center rounded-full bg-gradient-to-br from-[#E8FAF6] to-[#C9F0E8] text-sm font-bold text-[#067e6b] shadow-2xs">
                            {{ $initial }}
                        </span>
                        <span class="absolute -bottom-0.5 -end-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-white shadow-2xs ring-1 ring-white">
                            <span class="h-2.5 w-2.5 rounded-full {{ $channelChip['dot'] ?? 'bg-slate-400' }}"></span>
                        </span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <p class="truncate text-sm font-bold text-slate-900">{{ $customerName }}</p>
                            <span class="shrink-0 text-[11px] font-medium text-slate-400">
                                {{ $conversation->last_message_at?->format('h:i A') ?? '' }}
                            </span>
                        </div>
                        <p class="mt-0.5 truncate text-xs {{ $unreadCount > 0 ? 'font-semibold text-slate-800' : 'text-slate-500' }}">
                            {{ $preview?->content ?: 'لا توجد رسائل بعد' }}
                        </p>
                        <div class="mt-2 flex flex-wrap items-center justify-between gap-1.5">
                            <div class="flex flex-wrap items-center gap-1">
                                <span class="{{ $channelChip['badge'] }} rounded-full px-2 py-0.5 text-[10px] font-semibold">{{ $channelChip['label'] }}</span>
                                @if($conversation->isAiReplying())
                                    <span class="inline-flex items-center gap-0.5 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-800 border border-emerald-200/60">
                                        <svg class="h-2.5 w-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                        <span>رد AI</span>
                                    </span>
                                @elseif($assignedType === \App\Enums\WorkforceParticipantType::AiEmployee)
                                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-800 border border-emerald-200/60">AI Employee</span>
                                @endif
                                @if($conversation->handoffStatusEnum()->blocksAi())
                                    <span class="inline-flex items-center gap-1 rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-900">
                                        <svg class="h-2.5 w-2.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                        <span>تحتاج مراجعة</span>
                                    </span>
                                @endif
                            </div>
                            @if($unreadCount > 0)
                                <span class="inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-[#00BFAE] px-1.5 text-[10px] font-bold text-white shadow-2xs">{{ $unreadCount }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </a>
        @empty
            <div class="flex h-full items-center justify-center p-6">
                <div class="w-full rounded-2xl border border-dashed border-slate-200 bg-slate-50/60 px-4 py-10 text-center">
                    <span class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-[#E8FAF6] text-[#00BFAE] shadow-2xs">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                    </span>
                    <p class="text-sm font-bold text-slate-900">لا توجد محادثات</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ request()->filled('search') || request()->filled('channel') || request()->filled('status') || request()->boolean('unread') ? 'لا توجد نتائج مطابقة لعوامل التصفية الحالية.' : 'ابدأ بإنشاء محادثة جديدة للتواصل مع العملاء.' }}</p>
                    @if($inboxAllowsCompanyActions)
                        <a href="{{ route('workspace.conversations.create') }}" class="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-[#00BFAE] px-4 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-[#06a89a]">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            <span>محادثة جديدة</span>
                        </a>
                    @endif
                </div>
            </div>
        @endforelse
    </div>

    @if($conversations->hasPages())
        <div class="border-t border-slate-100 bg-white px-3 py-2.5">
            {{ $conversations->onEachSide(0)->links() }}
        </div>
    @endif
</aside>
