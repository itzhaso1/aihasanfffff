@php
    $activeDisplayChannel = $activeConversation->display_channel ?? $activeConversation->channel;
    $activeChannelChip = $channelUi[$activeDisplayChannel] ?? $channelUi['manual'];
    $customer = $activeConversation->customer;
    $customerName = $customer?->name ?? 'عميل بدون اسم';
    $initial = mb_substr($customerName, 0, 1);
    $backQuery = array_filter([
        'search' => request('search'),
        'channel' => request('channel'),
        'status' => request('status'),
        'unread' => request('unread'),
    ], fn ($value) => $value !== null && $value !== '');
@endphp

<section class="flex h-full min-h-0 min-w-0 flex-1 flex-col bg-[#FCFFFE]">
    <div class="flex items-center gap-3 border-b border-slate-200 bg-white px-3 py-3 sm:px-4">
        <a
            href="{{ route('workspace.conversations.index', $backQuery) }}"
            class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-500 md:hidden"
            aria-label="العودة إلى قائمة المحادثات"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M15 19l-7-7 7-7" />
            </svg>
        </a>
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#E8FAF6] text-sm font-bold text-[#067e6b]">
            {{ $initial }}
        </span>
        <div class="min-w-0 flex-1">
            <h3 class="truncate text-sm font-bold text-slate-900">{{ $customerName }}</h3>
            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-slate-500">
                @if($customer?->phone)
                    <span>{{ $customer->phone }}</span>
                @endif
                @if($activeConversation->external_id)
                    <span>External ID: #{{ $activeConversation->external_id }}</span>
                @endif
                <span class="{{ $activeChannelChip['badge'] }} rounded-full px-2 py-0.5 font-semibold">{{ $activeChannelChip['label'] }}</span>
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
            <button
                type="button"
                @click="detailsOpen = true"
                class="inline-flex rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 xl:hidden"
            >
                عرض تفاصيل العميل
            </button>
            <form method="POST" action="{{ route('workspace.conversations.update', $activeConversation) }}" class="flex items-center gap-2">
                @csrf
                @method('PUT')
                <select name="status" class="rounded-lg border-slate-300 text-xs focus:border-[#06C2A4] focus:ring-[#06C2A4]">
                    @foreach(['open' => 'مفتوحة', 'closed' => 'مغلقة', 'archived' => 'مؤرشفة'] as $statusKey => $statusLabel)
                        <option value="{{ $statusKey }}" @selected($activeConversation->status === $statusKey)>{{ $statusLabel }}</option>
                    @endforeach
                </select>
                <label class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-xs">
                    <input type="checkbox" name="ai_enabled" value="1" @checked($activeConversation->ai_enabled)>
                    <span>AI</span>
                </label>
                <input type="hidden" name="metadata_json" value="{{ json_encode($activeConversation->metadata ?? [], JSON_UNESCAPED_UNICODE) }}">
                <button class="rounded-lg bg-[#06C2A4] px-3 py-2 text-xs font-semibold text-white">تحديث</button>
            </form>
            @if($customer && \Illuminate\Support\Facades\Route::has('workspace.customers.edit'))
                <a href="{{ route('workspace.customers.edit', $customer) }}" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-500 transition hover:bg-slate-50" aria-label="المزيد">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z" />
                    </svg>
                </a>
            @endif
        </div>
    </div>

    <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-3 py-4 sm:px-5">
        @forelse($activeConversation->messages as $message)
            @php
                $outbound = in_array($message->direction, ['outbound', 'internal_note'], true);
                $statusLabel = $message->direction === 'internal_note'
                    ? 'ملاحظة داخلية'
                    : ($outbound ? 'صادرة' : 'واردة');
            @endphp
            <div class="flex {{ $outbound ? 'justify-end' : 'justify-start' }}">
                <div @class([
                    'max-w-[82%] rounded-2xl px-4 py-2 shadow-sm',
                    'rounded-es-md bg-[#DDF6F1] text-slate-900' => $outbound && $message->direction !== 'internal_note',
                    'rounded-es-md border border-amber-200 bg-amber-50 text-slate-900' => $message->direction === 'internal_note',
                    'rounded-ee-md border border-slate-200 bg-white text-slate-800' => ! $outbound,
                ])>
                    <p class="text-sm leading-6">{{ $message->content ?: '—' }}</p>
                    <div class="mt-1 flex items-center gap-2 text-[11px] text-slate-500">
                        <span>{{ $statusLabel }}</span>
                        <span>•</span>
                        <span>{{ $message->created_at?->format('h:i a') }}</span>
                        @if($message->ai_generated)
                            <span>•</span>
                            <span>AI</span>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-slate-200 bg-white p-6 text-center text-sm text-slate-500">
                لا توجد رسائل في هذه المحادثة حتى الآن.
            </div>
        @endforelse
    </div>

    <div class="border-t border-slate-200 bg-white px-3 py-3 sm:px-4">
        <form method="POST" action="{{ route('workspace.conversations.messages.store', $activeConversation) }}" class="space-y-2">
            @csrf
            <input type="hidden" name="conversation_id" value="{{ $activeConversation->id }}">
            <input type="hidden" name="customer_id" value="{{ $activeConversation->customer_id }}">
            <input type="hidden" name="metadata_json" value="{}">
            <div class="mb-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                <select name="direction" class="rounded-xl border-slate-300 text-xs focus:border-[#06C2A4] focus:ring-[#06C2A4]">
                    @foreach(['outbound' => 'صادرة', 'inbound' => 'واردة', 'internal_note' => 'ملاحظة'] as $direction => $directionLabel)
                        <option value="{{ $direction }}">{{ $directionLabel }}</option>
                    @endforeach
                </select>
                <select name="message_type" class="rounded-xl border-slate-300 text-xs focus:border-[#06C2A4] focus:ring-[#06C2A4]">
                    @foreach(['text' => 'نص', 'image' => 'صورة', 'file' => 'ملف', 'system' => 'نظام'] as $type => $typeLabel)
                        <option value="{{ $type }}">{{ $typeLabel }}</option>
                    @endforeach
                </select>
                <input name="external_message_id" class="hidden rounded-xl border-slate-300 text-xs focus:border-[#06C2A4] focus:ring-[#06C2A4] sm:block" placeholder="external_message_id (اختياري)">
            </div>
            <div class="flex items-end gap-2">
                <textarea name="content" rows="1" class="hs-input resize-none" placeholder="اكتب رسالتك هنا..."></textarea>
                <button class="hs-btn-primary h-11 w-11 shrink-0 rounded-xl p-0" aria-label="إرسال الرسالة">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 12 3 21l18-9L3 3l3 9Zm0 0h12" />
                    </svg>
                </button>
            </div>
            <p class="text-[11px] text-slate-400">المصدر الحالي: {{ $activeChannelChip['label'] }} · الإرسال من نفس المحادثة مباشرة.</p>
        </form>
    </div>
</section>
