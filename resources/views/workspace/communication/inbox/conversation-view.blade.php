@php
    $activeDisplayChannel = $activeConversation->display_channel ?? $activeConversation->channel;
    $activeChannelChip = $channelUi[$activeDisplayChannel] ?? $channelUi['manual'];
    $customer = $activeConversation->customer;
    $customerName = $customer?->name ?? 'عميل بدون اسم';
    $initial = mb_substr($customerName, 0, 1);
    $metadata = is_array($activeConversation->metadata) ? $activeConversation->metadata : [];
    $handoffStatus = $activeConversation->handoff_status instanceof \App\Enums\ConversationHandoffStatus
        ? $activeConversation->handoff_status->value
        : ($activeConversation->handoff_status ?? 'none');
    $handoffReason = $activeConversation->handoff_reason instanceof \App\Enums\ConversationHandoffReason
        ? $activeConversation->handoff_reason->value
        : $activeConversation->handoff_reason;
    $handoffSummary = data_get($metadata, 'handoff.structured', []);
    $catalogCtx = data_get($metadata, 'catalog');
    $assignedType = \App\Services\Workforce\WorkforceInboxAttribution::typeForConversation($activeConversation);
    $backQuery = array_filter([
        'search' => request('search'),
        'channel' => request('channel'),
        'status' => request('status'),
        'unread' => request('unread'),
        'handoff' => request('handoff'),
    ], fn ($value) => $value !== null && $value !== '');
    $inboxIndexRoute = $inboxIndexRoute ?? 'workspace.conversations.index';
    $inboxRouteParameters = $inboxRouteParameters ?? [];
    $inboxAllowsCompanyActions = $inboxAllowsCompanyActions ?? true;
    $inboxCanSend = $inboxCanSend ?? true;
    $inboxMessageAction = $inboxMessageAction ?? route('workspace.conversations.messages.store', $activeConversation);
    $conversationIsOpen = $activeConversation->isOpen();
    $handoffEnum = $activeConversation->handoff_status instanceof \App\Enums\ConversationHandoffStatus
        ? $activeConversation->handoff_status
        : (\App\Enums\ConversationHandoffStatus::tryFrom((string) $handoffStatus) ?? \App\Enums\ConversationHandoffStatus::None);
    $handoffBlocksAi = $handoffEnum->blocksAi();
    $canConvertToAi = $conversationIsOpen && ! $activeConversation->isAiReplying() && ! $handoffBlocksAi;
    $canEndActiveHandoff = $conversationIsOpen && $handoffBlocksAi;
    $canTakeControlFromAi = $conversationIsOpen && $activeConversation->isAiReplying();
@endphp

<section class="flex h-full min-h-0 min-w-0 w-full flex-1 flex-col overflow-hidden bg-[#FCFFFE]">
    <div class="flex flex-col gap-2 border-b border-slate-200 bg-white px-3 py-3 sm:flex-row sm:items-center sm:gap-3 sm:px-4">
        <div class="flex min-w-0 items-center gap-3">
        <a
            href="{{ route($inboxIndexRoute, array_merge($inboxRouteParameters, $backQuery)) }}"
            class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-500 md:hidden"
            aria-label="العودة إلى قائمة المحادثات"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M15 19l-7-7 7-7" />
            </svg>
        </a>
        <button
            type="button"
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#E8FAF6] text-sm font-bold text-[#067e6b]"
            @click="detailsOpen = true"
            aria-controls="inbox-customer-details"
            :aria-expanded="detailsOpen.toString()"
            aria-label="عرض تفاصيل {{ $customerName }}"
        >
            {{ $initial }}
        </button>
        <div class="min-w-0 flex-1">
            <button
                type="button"
                class="block max-w-full truncate text-start text-sm font-bold text-slate-900 hover:text-[#067e6b]"
                @click="detailsOpen = true"
                aria-controls="inbox-customer-details"
                :aria-expanded="detailsOpen.toString()"
            >
                {{ $customerName }}
            </button>
            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-slate-500">
                @if($customer?->phone)
                    <span>{{ $customer->phone }}</span>
                @endif
                @if($activeConversation->external_id)
                    <span class="hidden sm:inline">External ID: #{{ $activeConversation->external_id }}</span>
                @endif
                <span class="{{ $activeChannelChip['badge'] }} rounded-full px-2 py-0.5 font-semibold">{{ $activeChannelChip['label'] }}</span>
                @if($activeConversation->isAiReplying())
                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 font-semibold text-emerald-800">رد AI</span>
                @elseif($assignedType === \App\Enums\WorkforceParticipantType::AiEmployee)
                    <span class="rounded-full bg-emerald-100 px-2 py-0.5 font-semibold text-emerald-800">
                        AI Employee{{ $activeConversation->aiEmployee?->name ? ': '.$activeConversation->aiEmployee->name : '' }}
                    </span>
                @endif
                @if($handoffEnum->blocksAi())
                    <span class="inline-flex items-center gap-1 rounded-full border border-amber-400 bg-amber-50 px-2 py-0.5 font-semibold text-amber-950">
                        <span aria-hidden="true">مراجعة</span>
                        تحتاج مراجعة من الفريق
                    </span>
                @endif
                @if($handoffReason)
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-700">{{ $handoffReason }}</span>
                @endif
            </div>
        </div>
        </div>
        <div class="hidden max-w-full flex-wrap items-center justify-end gap-2 overflow-x-auto sm:flex">
            @if($inboxAllowsCompanyActions && $conversationIsOpen)
                @can('manageHandoff', $activeConversation)
                    @if($canEndActiveHandoff)
                        <form method="POST" action="{{ route('workspace.conversations.handoff.resolve', $activeConversation) }}">
                            @csrf
                            <button type="submit" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">إنهاء المراجعة</button>
                        </form>
                    @elseif($canTakeControlFromAi)
                        <form method="POST" action="{{ route('workspace.conversations.handoff.takeover', $activeConversation) }}">
                            @csrf
                            <button type="submit" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">إنهاء التحويل</button>
                        </form>
                    @endif
                @endcan
                @can('resumeAi', $activeConversation)
                    @if($canConvertToAi)
                        <form method="POST" action="{{ route('workspace.conversations.handoff.resume-ai', $activeConversation) }}">
                            @csrf
                            <button type="submit" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 hover:bg-emerald-100">تحويل إلى AI</button>
                        </form>
                    @endif
                @endcan
            @endif
            @if($inboxAllowsCompanyActions)
                <form method="POST" action="{{ route('workspace.conversations.update', $activeConversation) }}" class="flex items-center gap-2">
                    @csrf
                    @method('PUT')
                    <select name="status" class="rounded-lg border-slate-300 text-xs focus:border-[#06C2A4] focus:ring-[#06C2A4]">
                        @foreach(['open' => 'مفتوحة', 'closed' => 'مغلقة', 'archived' => 'مؤرشفة'] as $statusKey => $statusLabel)
                            <option value="{{ $statusKey }}" @selected($activeConversation->status === $statusKey)>{{ $statusLabel }}</option>
                        @endforeach
                    </select>
                    <label class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-xs">
                        <input type="hidden" name="ai_enabled" value="0">
                        <input type="checkbox" name="ai_enabled" value="1" @checked($activeConversation->ai_enabled)>
                        <span>AI</span>
                    </label>
                    <input type="hidden" name="metadata_json" value="{{ json_encode($activeConversation->metadata ?? [], JSON_UNESCAPED_UNICODE) }}">
                    <button class="rounded-lg bg-[#06C2A4] px-3 py-2 text-xs font-semibold text-white">تحديث</button>
                </form>
            @endif
            @if($inboxAllowsCompanyActions && $customer && \Illuminate\Support\Facades\Route::has('workspace.customers.edit'))
                <a href="{{ route('workspace.customers.edit', $customer) }}" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-500 transition hover:bg-slate-50" aria-label="المزيد">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 6.75a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Zm0 6a.75.75 0 1 1 0-1.5.75.75 0 0 1 0 1.5Z" />
                    </svg>
                </a>
            @endif
        </div>
    </div>

    @if($handoffStatus !== 'none' || is_array($catalogCtx))
        <div class="border-b border-slate-100 bg-slate-50 px-4 py-3 text-xs text-slate-700">
            @if($handoffBlocksAi)
                <p class="font-semibold text-amber-950">تحتاج مراجعة من الفريق</p>
            @elseif($handoffStatus !== 'none')
                <p><span class="font-semibold">التحويل:</span> {{ $handoffStatus }}
                    @if($activeConversation->handoff_requested_at)
                        · {{ $activeConversation->handoff_requested_at->format('Y-m-d H:i') }}
                    @endif
                </p>
            @endif
            @if(is_array($catalogCtx) && data_get($catalogCtx, 'attached_product.name'))
                <p class="mt-1"><span class="font-semibold">المنتج:</span> {{ data_get($catalogCtx, 'attached_product.name') }}
                    @if(data_get($catalogCtx, 'attached_product.selected_variant.name'))
                        ({{ data_get($catalogCtx, 'attached_product.selected_variant.name') }})
                    @endif
                </p>
            @endif
            @if(is_array($handoffSummary) && !empty($handoffSummary['latest_customer_message']))
                <p class="mt-1 truncate"><span class="font-semibold">آخر رسالة:</span> {{ $handoffSummary['latest_customer_message'] }}</p>
            @endif
        </div>
    @endif

    <div id="inbox-message-list" class="min-h-0 flex-1 space-y-3 overflow-y-auto px-3 py-4 sm:px-5">
        @forelse($activeConversation->messages as $message)
            @php
                $outbound = in_array($message->direction, ['outbound', 'internal_note'], true);
                $statusLabel = $message->direction === 'internal_note'
                    ? 'ملاحظة داخلية'
                    : ($outbound ? 'صادرة' : 'واردة');
            @endphp
            <div @class([
                    'hs-inbox-message-row',
                    $outbound ? 'hs-inbox-message-row--sent' : 'hs-inbox-message-row--received',
                ]) data-message-id="{{ $message->id }}">
                <div @class([
                    'hs-inbox-message-bubble max-w-[78%] rounded-2xl px-3 py-1.5 shadow-sm',
                    'rounded-es-md bg-[#DDF6F1] text-slate-900' => $outbound && $message->direction !== 'internal_note',
                    'rounded-es-md border border-amber-200 bg-amber-50 text-slate-900' => $message->direction === 'internal_note',
                    'rounded-ee-md border border-slate-200 bg-white text-slate-800' => ! $outbound,
                ])>
                    @if($message->attachments && $message->attachments->isNotEmpty())
                        <div class="mb-2 space-y-2">
                            @foreach($message->attachments as $attachment)
                                @php
                                    $attachmentReady = ($attachment->status ?? 'stored') === \App\Models\MessageAttachment::STATUS_STORED;
                                @endphp
                                @if($attachmentReady && $attachment->kind === 'image')
                                    <img
                                        src="{{ route('workspace.message-attachments.download', $attachment) }}"
                                        alt="{{ $attachment->original_name }}"
                                        class="max-h-56 rounded-lg border border-slate-200 object-cover"
                                        loading="lazy"
                                    />
                                @elseif($attachmentReady && $attachment->kind === 'audio')
                                    <audio controls preload="none" class="w-full max-w-xs">
                                        <source src="{{ route('workspace.message-attachments.download', $attachment) }}" type="{{ $attachment->mime_type ?: 'audio/mpeg' }}">
                                    </audio>
                                @elseif($attachmentReady && $attachment->kind === 'video')
                                    <video controls preload="metadata" class="max-h-56 w-full rounded-lg">
                                        <source src="{{ route('workspace.message-attachments.download', $attachment) }}" type="{{ $attachment->mime_type ?: 'video/mp4' }}">
                                    </video>
                                @elseif($attachmentReady)
                                    <a
                                        href="{{ route('workspace.message-attachments.download', $attachment) }}"
                                        class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                    >
                                        <span>{{ $attachment->original_name ?: 'ملف' }}</span>
                                        @if($attachment->size_bytes)
                                            <span class="text-slate-400">({{ number_format($attachment->size_bytes / 1024, 1) }} KB)</span>
                                        @endif
                                    </a>
                                @else
                                    <span class="text-xs text-slate-500">جاري تجهيز المرفق…</span>
                                @endif
                            @endforeach
                        </div>
                    @endif
                    <p class="text-sm leading-snug">
                        {{ $message->content ?: '—' }}
                        <span class="hs-inbox-message-meta text-slate-500">
                            <span>{{ $message->created_at?->format('h:i a') }}</span>
                            @php
                                $messageType = \App\Services\Workforce\WorkforceInboxAttribution::typeForMessage($message);
                            @endphp
                            @if($messageType === \App\Enums\WorkforceParticipantType::AiEmployee)
                                <span>· AI Employee</span>
                            @elseif($messageType === \App\Enums\WorkforceParticipantType::IndependentWorker)
                                <span>· عامل مستقل{{ $message->workerEngagement?->workerProfile?->display_name ? ': '.$message->workerEngagement->workerProfile->display_name : '' }}</span>
                            @elseif($messageType === \App\Enums\WorkforceParticipantType::CompanyStaff && $message->user)
                                <span>· موظف الشركة: {{ $message->user->name }}</span>
                            @endif
                        </span>
                    </p>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-slate-200 bg-white p-6 text-center text-sm text-slate-500">
                لا توجد رسائل في هذه المحادثة حتى الآن.
            </div>
        @endforelse
    </div>

    <div class="border-t border-slate-200 bg-white px-3 py-3 sm:px-4">
        @if($inboxCanSend)
        <form id="inbox-compose-form" method="POST" action="{{ $inboxMessageAction }}" class="space-y-2">
            @csrf
            <input type="hidden" name="conversation_id" value="{{ $activeConversation->id }}">
            <input type="hidden" name="customer_id" value="{{ $activeConversation->customer_id }}">
            <input type="hidden" name="direction" value="outbound">
            <input type="hidden" name="message_type" value="text">
            <input type="hidden" name="metadata_json" value="{}">
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
        @else
            <p class="bg-amber-50 px-4 py-3 text-center text-sm text-amber-800">إرسال الرسائل غير مشمول في الصلاحيات الممنوحة لهذا الارتباط.</p>
        @endif
    </div>
</section>
