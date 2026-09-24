@php
    $listQuery = fn (?int $conversationId = null, array $overrides = []) => array_filter(array_merge([
        'search' => request('search'),
        'channel' => request('channel'),
        'status' => request('status'),
        'unread' => request('unread'),
        'conversation' => $conversationId,
    ], $overrides), fn ($value) => $value !== null && $value !== '');
@endphp

<aside @class([
    'h-full min-h-0 w-full flex-col border-slate-200 bg-white md:flex md:w-[22rem] md:border-e xl:w-[23rem]',
    'flex' => ! $activeConversation,
    'hidden' => $activeConversation,
])>
    <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
        <div>
            <h3 class="text-sm font-bold text-slate-900">المحادثات</h3>
            <p class="text-[11px] text-slate-500">{{ $conversations->total() }} محادثة</p>
        </div>
    </div>

    <div class="flex gap-1 border-b border-slate-100 px-3 py-2">
        <a
            href="{{ route('workspace.conversations.index', $listQuery(request()->integer('conversation') ?: null, ['unread' => null])) }}"
            class="{{ ! request()->boolean('unread') ? 'bg-[#E8FAF6] text-[#067e6b]' : 'text-slate-500 hover:bg-slate-50' }} rounded-full px-3 py-1 text-[11px] font-semibold transition"
        >
            الكل ({{ $conversations->total() }})
        </a>
        <a
            href="{{ route('workspace.conversations.index', $listQuery(request()->integer('conversation') ?: null, ['unread' => 1])) }}"
            class="{{ request()->boolean('unread') ? 'bg-[#E8FAF6] text-[#067e6b]' : 'text-slate-500 hover:bg-slate-50' }} rounded-full px-3 py-1 text-[11px] font-semibold transition"
        >
            غير مقروءة
        </a>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto" data-inbox-conversation-list>
        @forelse($conversations as $conversation)
            @php
                $isActive = $activeConversation && $activeConversation->id === $conversation->id;
                $preview = $conversation->messages->first();
                $displayChannel = $conversation->display_channel ?? $conversation->channel;
                $channelChip = $channelUi[$displayChannel] ?? $channelUi['manual'];
                $unreadCount = (int) ($conversation->unread_count ?? 0);
                $customerName = $conversation->customer?->name ?? 'عميل غير معروف';
                $initial = mb_substr($customerName, 0, 1);
            @endphp
            <a
                href="{{ route('workspace.conversations.index', $listQuery($conversation->id)) }}"
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
                        <div class="mt-2 flex items-center justify-between gap-2">
                            <span class="{{ $channelChip['badge'] }} rounded-full px-2 py-0.5 text-[10px] font-semibold">{{ $channelChip['label'] }}</span>
                            @if($unreadCount > 0)
                                <span class="rounded-full bg-[#06C2A4] px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $unreadCount }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </a>
        @empty
            <div class="p-6">
                <x-empty-state
                    title="لا توجد محادثات"
                    text="{{ request()->filled('search') || request()->filled('channel') || request()->filled('status') || request()->boolean('unread') ? 'لا توجد نتائج مطابقة لعوامل التصفية الحالية.' : 'ابدأ بإنشاء محادثة جديدة للتواصل مع العملاء.' }}"
                    action-label="+ محادثة جديدة"
                    action-url="{{ route('workspace.conversations.create') }}"
                />
            </div>
        @endforelse
    </div>

    @if($conversations->hasPages())
        <div class="border-t border-slate-100 px-3 py-2">
            {{ $conversations->onEachSide(0)->links() }}
        </div>
    @endif
</aside>
