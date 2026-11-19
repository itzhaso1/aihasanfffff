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
    $activeResponder = $activeConversation->currentResponder();
    $currentUserId = auth()->id();
    $initialPresence = auth()->check()
        ? app(\App\Services\Conversation\ConversationPresenceService::class)->join($activeConversation, auth()->user())
        : app(\App\Services\Conversation\ConversationPresenceService::class)->getConversationState($activeConversation);
    $isAlreadyAssignedToMe = (int) $activeConversation->assigned_user_id === (int) $currentUserId && ! (bool) $activeConversation->ai_enabled;
    $isHumanNeeded = in_array($handoffEnum, [\App\Enums\ConversationHandoffStatus::Requested, \App\Enums\ConversationHandoffStatus::Queued], true);
    $canClaimConversation = $conversationIsOpen
        && ! $isAlreadyAssignedToMe
        && ($isHumanNeeded || $canTakeControlFromAi || ($handoffEnum === \App\Enums\ConversationHandoffStatus::Active && (int) $activeConversation->assigned_user_id !== (int) $currentUserId));
@endphp

<section 
    x-data="{
        auditLogOpen: false,
        auditLogs: [],
        auditLoading: false,
        fetchAuditLogs() {
            this.auditLoading = true;
            fetch('{{ route('workspace.conversations.responder-logs', $activeConversation) }}')
                .then(r => r.json())
                .then(data => {
                    this.auditLogs = data.logs || [];
                    this.auditLoading = false;
                })
                .catch(() => { this.auditLoading = false; });
        }
    }"
    class="flex h-full min-h-0 min-w-0 w-full flex-1 flex-col overflow-hidden bg-[#F8FBFA]"
>
    {{-- Chat Header --}}
    <div class="relative z-20 flex flex-col gap-2 border-b border-slate-200/90 bg-white px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-5 overflow-visible">
        <div class="flex min-w-0 items-center justify-between sm:justify-start gap-2.5 sm:gap-3">
            <div class="flex min-w-0 items-center gap-2.5 sm:gap-3">
                <a
                    href="{{ route($inboxIndexRoute, array_merge($inboxRouteParameters, $backQuery, ['conversation' => 'none'])) }}"
                    data-inbox-back-button
                    class="inline-flex h-8 w-8 sm:h-9 sm:w-9 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-500 transition hover:bg-slate-50 md:hidden"
                    aria-label="العودة إلى قائمة المحادثات"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </a>
                <button
                    type="button"
                    class="relative flex h-9 w-9 sm:h-11 sm:w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-[#E8FAF6] to-[#C9F0E8] text-xs sm:text-sm font-bold text-[#067e6b] shadow-2xs transition hover:opacity-90"
                    @click="detailsOpen = true"
                    aria-controls="inbox-customer-details"
                    :aria-expanded="detailsOpen.toString()"
                    aria-label="عرض تفاصيل {{ $customerName }}"
                >
                    {{ $initial }}
                    <span class="absolute -bottom-0.5 -end-0.5 flex h-3.5 w-3.5 sm:h-4 sm:w-4 items-center justify-center rounded-full bg-white shadow-2xs ring-1 ring-white">
                        <span class="h-2 w-2 sm:h-2.5 sm:w-2.5 rounded-full {{ $activeChannelChip['dot'] ?? 'bg-slate-400' }}"></span>
                    </span>
                </button>
                <div class="min-w-0 flex-1">
                    <button
                        type="button"
                        class="block max-w-[150px] sm:max-w-none truncate text-start text-xs sm:text-sm font-bold text-slate-900 transition hover:text-[#00BFAE]"
                        @click="detailsOpen = true"
                        aria-controls="inbox-customer-details"
                        :aria-expanded="detailsOpen.toString()"
                    >
                        {{ $customerName }}
                    </button>
                    <div class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-[10px] sm:text-[11px] text-slate-500">
                        @if($customer?->phone)
                            <span class="font-medium text-slate-600">{{ $customer->phone }}</span>
                        @endif
                        @if($activeConversation->external_id)
                            <span class="hidden text-slate-400 sm:inline">#{{ $activeConversation->external_id }}</span>
                        @endif
                        <span class="{{ $activeChannelChip['badge'] }} rounded-full px-1.5 py-0.2 sm:px-2 sm:py-0.5 text-[9px] sm:text-[10px] font-semibold">{{ $activeChannelChip['label'] }}</span>
                        @if($activeConversation->isAiReplying())
                            <span class="inline-flex items-center gap-0.5 rounded-full bg-emerald-50 px-1.5 py-0.2 sm:px-2 sm:py-0.5 text-[9px] sm:text-[10px] font-semibold text-emerald-800 border border-emerald-200/60">
                                <svg class="h-2 w-2 sm:h-2.5 sm:w-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                <span>AI</span>
                            </span>
                        @elseif($assignedType === \App\Enums\WorkforceParticipantType::AiEmployee)
                            <span class="rounded-full bg-emerald-50 px-1.5 py-0.2 sm:px-2 sm:py-0.5 text-[9px] sm:text-[10px] font-semibold text-emerald-800 border border-emerald-200/60">
                                AI{{ $activeConversation->aiEmployee?->name ? ': '.$activeConversation->aiEmployee->name : '' }}
                            </span>
                        @endif
                        @if($handoffEnum->blocksAi())
                            <span class="inline-flex items-center gap-0.5 rounded-full border border-amber-300 bg-amber-50 px-1.5 py-0.2 sm:px-2 sm:py-0.5 text-[9px] sm:text-[10px] font-semibold text-amber-900">
                                <svg class="h-2 w-2 sm:h-2.5 sm:w-2.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                <span>مراجعة</span>
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Mobile Details Drawer Button --}}
            <button
                type="button"
                @click="detailsOpen = !detailsOpen"
                class="inline-flex sm:hidden h-8 w-8 shrink-0 items-center justify-center rounded-xl border border-slate-200/90 text-slate-500 transition hover:bg-slate-50 hover:text-slate-800 shadow-2xs"
                title="تفاصيل العميل والملاحظات"
                aria-label="تفاصيل العميل"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </button>
        </div>

        {{-- Actions in Header --}}
        <div class="flex items-center justify-end gap-1.5 sm:gap-2 shrink-0 overflow-visible pt-1.5 sm:pt-0 border-t border-slate-100 sm:border-t-0">
            <span id="inbox-connection-badge" class="hidden"></span>
            @if($inboxAllowsCompanyActions && $conversationIsOpen)
                @can('manageHandoff', $activeConversation)
                    @if($canEndActiveHandoff)
                        @if($canClaimConversation)
                            <form method="POST" action="{{ route('workspace.conversations.handoff.takeover', $activeConversation) }}">
                                @csrf
                                <button type="submit" class="inline-flex items-center gap-1 rounded-xl bg-blue-600 px-2.5 py-1 sm:px-3 sm:py-1.5 text-xs font-bold text-white shadow-2xs hover:bg-blue-700 transition" title="استلام المحادثة وتولي الرد عليها">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    <span>استلام المحادثة</span>
                                </button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('workspace.conversations.handoff.resolve', $activeConversation) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1 rounded-xl border border-slate-200/90 bg-white px-2.5 py-1 sm:px-3 sm:py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition hover:bg-slate-50">
                                <svg class="h-3.5 w-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span class="hidden sm:inline">إنهاء المراجعة</span>
                                <span class="sm:hidden">إنهاء</span>
                            </button>
                        </form>
                    @elseif($canTakeControlFromAi)
                        <form method="POST" action="{{ route('workspace.conversations.handoff.takeover', $activeConversation) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1 rounded-xl border border-slate-200/90 bg-white px-2.5 py-1 sm:px-3 sm:py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition hover:bg-slate-50">
                                <svg class="h-3.5 w-3.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                <span class="hidden sm:inline">تحويل إلى الفريق</span>
                                <span class="sm:hidden">تحويل</span>
                            </button>
                        </form>
                    @endif
                @endcan
                @can('resumeAi', $activeConversation)
                    @if($canConvertToAi)
                        <form method="POST" action="{{ route('workspace.conversations.handoff.resume-ai', $activeConversation) }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1 rounded-xl border border-emerald-200 bg-emerald-50 px-2.5 py-1 sm:px-3 sm:py-1.5 text-xs font-semibold text-emerald-800 shadow-2xs transition hover:bg-emerald-100">
                                <svg class="h-3.5 w-3.5 text-emerald-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                <span class="hidden sm:inline">تحويل إلى AI</span>
                                <span class="sm:hidden">إلى AI</span>
                            </button>
                        </form>
                    @endif
                @endcan
            @endif

            @if($inboxAllowsCompanyActions)
                {{-- Status Dropdown --}}
                <div class="relative" x-data="{ statusOpen: false }" @click.outside="statusOpen = false">
                    <button
                        type="button"
                        @click="statusOpen = !statusOpen"
                        class="inline-flex items-center gap-1 rounded-xl border border-slate-200/90 bg-white px-2.5 py-1 sm:px-2.5 sm:py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition hover:bg-slate-50"
                        title="تغيير حالة المحادثة"
                    >
                        <span class="h-2 w-2 rounded-full {{ $activeConversation->status === 'open' ? 'bg-emerald-500' : ($activeConversation->status === 'archived' ? 'bg-amber-500' : 'bg-slate-400') }}"></span>
                        <span>{{ ['open' => 'مفتوحة', 'closed' => 'مغلقة', 'archived' => 'مؤرشفة'][$activeConversation->status] ?? $activeConversation->status }}</span>
                        <svg class="h-3 w-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div
                        x-show="statusOpen"
                        x-cloak
                        x-transition.origin.top
                        class="absolute end-0 top-full z-50 mt-1.5 min-w-[9rem] max-w-[calc(100vw-2rem)] rounded-2xl border border-slate-200/90 bg-white p-1.5 shadow-xl"
                    >
                        @foreach(['open' => ['مفتوحة', 'bg-emerald-500'], 'closed' => ['مغلقة', 'bg-slate-400'], 'archived' => ['مؤرشفة', 'bg-amber-500']] as $sKey => [$sLabel, $sDot])
                            <form method="POST" action="{{ route('workspace.conversations.update', $activeConversation) }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="status" value="{{ $sKey }}">
                                <input type="hidden" name="ai_enabled" value="{{ $activeConversation->ai_enabled ? '1' : '0' }}">
                                <input type="hidden" name="metadata_json" value="{{ json_encode($activeConversation->metadata ?? [], JSON_UNESCAPED_UNICODE) }}">
                                <button
                                    type="submit"
                                    class="flex w-full items-center gap-2 rounded-xl px-2.5 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-[#E8FAF6] hover:text-[#067e6b] {{ $activeConversation->status === $sKey ? 'bg-slate-50' : '' }}"
                                >
                                    <span class="h-2 w-2 rounded-full {{ $sDot }}"></span>
                                    <span>{{ $sLabel }}</span>
                                    @if($activeConversation->status === $sKey)
                                        <svg class="ms-auto h-3 w-3 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                    @endif
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>

                {{-- AI Toggle Switch --}}
                <form method="POST" action="{{ route('workspace.conversations.update', $activeConversation) }}" class="inline-flex">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="status" value="{{ $activeConversation->status }}">
                    <input type="hidden" name="ai_enabled" value="{{ $activeConversation->ai_enabled ? '0' : '1' }}">
                    <input type="hidden" name="metadata_json" value="{{ json_encode($activeConversation->metadata ?? [], JSON_UNESCAPED_UNICODE) }}">
                    <button
                        type="submit"
                        title="{{ $activeConversation->ai_enabled ? 'تعطيل AI لهذه المحادثة' : 'تفعيل AI لهذه المحادثة' }}"
                        class="inline-flex items-center gap-1 rounded-xl border {{ $activeConversation->ai_enabled ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-slate-200/90 bg-white text-slate-500' }} px-2.5 py-1 sm:px-2.5 sm:py-1.5 text-xs font-semibold shadow-2xs transition hover:opacity-90"
                    >
                        <svg class="h-3.5 w-3.5 {{ $activeConversation->ai_enabled ? 'text-emerald-700' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        <span class="hidden sm:inline">{{ $activeConversation->ai_enabled ? 'AI نشط' : 'AI معطل' }}</span>
                        <span class="sm:hidden">{{ $activeConversation->ai_enabled ? 'AI' : 'معطل' }}</span>
                    </button>
                </form>
            @endif

            {{-- Multi-Employee Live Presence Section --}}
            <div x-data="{ presenceOpen: false }" class="relative inline-block text-start">
                <button
                    type="button"
                    @click="presenceOpen = !presenceOpen"
                    @click.away="presenceOpen = false"
                    id="inbox-presence-btn"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200/90 bg-white px-2.5 py-1 sm:px-3 sm:py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition hover:bg-slate-50"
                    title="الموظفون داخل المحادثة حالياً"
                >
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                    </span>
                    <span class="hidden md:inline">الموظفون داخل المحادثة:</span>
                    <span id="inbox-presence-count" class="rounded-full bg-emerald-100 px-1.5 py-0.2 text-[10px] font-extrabold text-emerald-800">{{ $initialPresence['present_count'] ?? 1 }}</span>
                    <svg class="h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 9-7 7-7-7"/></svg>
                </button>

                {{-- Presence Dropdown Details --}}
                <div
                    x-show="presenceOpen"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                    class="absolute left-0 mt-2 w-80 origin-top-left rounded-2xl border border-slate-200 bg-white p-3 shadow-xl z-50 hs-scrollbar"
                    style="display: none;"
                >
                    <div class="flex items-center justify-between border-b border-slate-100 pb-2 mb-2.5">
                        <span class="text-xs font-bold text-slate-800">الموظفون المتواجدون بالمحادثة</span>
                        <span id="inbox-presence-dropdown-count" class="text-[11px] font-semibold text-slate-400">{{ $initialPresence['present_count'] ?? 1 }} متصل</span>
                    </div>

                    <div id="inbox-presence-list" class="space-y-2 max-h-60 overflow-y-auto">
                        @forelse($initialPresence['present_employees'] ?? [] as $emp)
                            <div class="flex items-center justify-between gap-2 rounded-xl bg-slate-50 p-2 text-xs">
                                <div class="flex items-center gap-2 min-w-0">
                                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-teal-600 text-white font-bold text-[11px]">
                                        {{ $emp['initials'] ?? 'م' }}
                                    </div>
                                    <div class="min-w-0">
                                        <p class="font-bold text-slate-900 truncate text-[11.5px]">
                                            @if(! ($inboxAllowsCompanyActions ?? true) && $emp['id'] === auth()->id())
                                                أنت
                                            @else
                                                {{ $emp['name'] }}
                                            @endif
                                        </p>
                                        @if($emp['is_assigned'] ?? false)
                                            <span class="text-[9.5px] font-semibold text-blue-700">المسؤول المعين</span>
                                        @endif
                                    </div>
                                </div>
                                <span class="shrink-0 inline-flex items-center gap-1 text-[10px] font-semibold rounded-full px-2 py-0.5 {{ ($emp['state'] ?? 'viewing') === 'typing' ? 'bg-amber-100 text-amber-900' : (($emp['state'] ?? 'viewing') === 'sending' ? 'bg-blue-100 text-blue-900' : 'bg-emerald-100 text-emerald-800') }}">
                                    <span class="h-1.5 w-1.5 rounded-full {{ ($emp['state'] ?? 'viewing') === 'typing' ? 'bg-amber-500 animate-pulse' : (($emp['state'] ?? 'viewing') === 'sending' ? 'bg-blue-500 animate-pulse' : 'bg-emerald-500') }}"></span>
                                    <span>{{ ($emp['state'] ?? 'viewing') === 'typing' ? 'يكتب الآن' : (($emp['state'] ?? 'viewing') === 'sending' ? 'يرسل...' : 'يشاهد') }}</span>
                                </span>
                            </div>
                        @empty
                            <p class="text-xs text-slate-400 text-center py-2">لا يوجد موظفون متصلون حالياً</p>
                        @endforelse
                    </div>

                    <div class="mt-2.5 pt-2 border-t border-slate-100 text-[10px] text-slate-400 text-center">
                        تحديث حي وتلقائي لنشاط وتواجد الموظفين
                    </div>
                </div>
            </div>

            {{-- Desktop Details Drawer Button --}}
            <button
                type="button"
                @click="detailsOpen = !detailsOpen"
                class="hidden sm:inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-slate-200/90 bg-white text-slate-600 shadow-2xs transition hover:bg-slate-50 hover:text-slate-900"
                aria-controls="inbox-customer-details"
                :aria-expanded="detailsOpen.toString()"
                aria-label="عرض تفاصيل {{ $customerName }}"
                title="تفاصيل العميل والملاحظات"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </button>
        </div>
    </div>

    @if($inboxAllowsCompanyActions)
    {{-- Live Responder Status Banner --}}
    <div 
        id="inbox-live-responder-bar" 
        data-inbox-responder-bar="{{ $activeConversation->id }}"
        class="border-b px-3.5 py-2 sm:px-5 sm:py-2.5 text-xs transition-colors duration-200 {{ $activeResponder['status'] === 'ai_active' ? 'bg-emerald-50/80 border-emerald-200/70 text-emerald-950' : ($activeResponder['status'] === 'human_active' ? 'bg-blue-50/80 border-blue-200/70 text-blue-950' : ($activeResponder['status'] === 'human_needed' ? 'bg-amber-50/80 border-amber-200/70 text-amber-950' : 'bg-slate-100/80 border-slate-200/70 text-slate-800')) }}"
    >
        <div class="flex flex-wrap items-center justify-between gap-2.5">
            <div class="flex items-center gap-2.5 min-w-0 flex-1">
                {{-- Icon / Avatar --}}
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl shadow-2xs {{ $activeResponder['status'] === 'ai_active' ? 'bg-emerald-600 text-white' : ($activeResponder['status'] === 'human_active' ? 'bg-blue-600 text-white font-bold' : ($activeResponder['status'] === 'human_needed' ? 'bg-amber-500 text-white font-bold' : 'bg-slate-400 text-white')) }}">
                    @if($activeResponder['status'] === 'ai_active')
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    @elseif($activeResponder['status'] === 'human_active')
                        <span class="text-xs">{{ $activeResponder['user_initials'] ?? 'م' }}</span>
                    @elseif($activeResponder['status'] === 'human_needed')
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    @else
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    @endif
                </div>

                @php
                    $isAssignedWorker = $activeConversation->assigned_worker_engagement_id !== null;
                    $assignedEmployeeName = (! $isAssignedWorker && $activeConversation->assignedUser)
                        ? $activeConversation->assignedUser->name
                        : null;
                    $lastMessageSender = $activeConversation->lastMessageSender();
                @endphp
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
                        <span class="font-bold text-xs sm:text-sm text-slate-900 truncate" data-responder-display-name>
                            @if($activeResponder['status'] === 'ai_active')
                                {{ $activeResponder['name'] }} يتولى الرد على هذه المحادثة
                            @elseif($activeResponder['is_typing'] ?? false)
                                {{ $activeResponder['name'] }} يكتب رداً الآن...
                            @elseif($assignedEmployeeName)
                                المسؤول: {{ $assignedEmployeeName }}
                            @elseif($activeResponder['status'] === 'human_needed')
                                المحادثة بانتظار استلام من أحد موظفي الفريق
                            @elseif($activeResponder['status'] === 'waiting')
                                المحادثة متوقفة مؤقتاً بانتظار الرد
                            @else
                                المحادثة غير متصلة أو مغلقة
                            @endif
                        </span>
                        <span data-responder-pill class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold shadow-2xs {{ $activeResponder['badge_class'] }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $activeResponder['dot_class'] }}"></span>
                            <span data-responder-label>{{ ($activeResponder['is_typing'] ?? false) ? 'يكتب الآن...' : $activeResponder['label'] }}</span>
                        </span>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[10px] sm:text-[11px] opacity-75 mt-0.5">
                        @if($assignedEmployeeName && ($activeResponder['is_typing'] ?? false))
                            <span class="text-slate-600">المسؤول: <strong>{{ $assignedEmployeeName }}</strong></span>
                            <span>•</span>
                        @endif
                        @if($lastMessageSender)
                            <span class="text-slate-500">آخر رسالة من: <strong>{{ $lastMessageSender['name'] }}</strong></span>
                        @endif
                        @if($activeResponder['assigned_at_human'])
                            <span class="text-slate-400">• منذ {{ $activeResponder['assigned_at_human'] }}</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Actions & Audit Log in Responder Bar --}}
            <div class="flex items-center gap-1.5 shrink-0">
                @if($inboxAllowsCompanyActions && $canClaimConversation)
                    @can('manageHandoff', $activeConversation)
                        <form method="POST" action="{{ route('workspace.conversations.handoff.takeover', $activeConversation) }}">
                            @csrf
                            <button
                                type="submit"
                                class="inline-flex items-center gap-1 rounded-xl bg-blue-600 px-2.5 py-1 sm:px-3 sm:py-1.5 text-xs font-bold text-white shadow-2xs hover:bg-blue-700 transition"
                                title="استلام مسؤولية الرد على هذه المحادثة"
                            >
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                <span>استلام المحادثة</span>
                            </button>
                        </form>
                    @endcan
                @endif
                <button
                    type="button"
                    @click="auditLogOpen = true; fetchAuditLogs()"
                    class="inline-flex items-center gap-1 rounded-xl border border-slate-300 bg-white px-2.5 py-1 sm:px-3 sm:py-1.5 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition"
                    title="عرض سجل تاريخ المسؤولية والتحويلات"
                >
                    <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    <span class="hidden sm:inline">سجل المسؤولية</span>
                    <span class="sm:hidden">السجل</span>
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Context Banner (Handoff or Catalog) --}}
    @if($handoffStatus !== 'none' || is_array($catalogCtx))
        <div class="border-b border-amber-200/60 bg-amber-50/70 px-4 py-2.5 text-xs text-amber-950">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <svg class="h-4 w-4 shrink-0 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    @if($handoffBlocksAi)
                        <span class="font-bold">تحتاج مراجعة من الفريق</span>
                        @if($activeConversation->handoff_requested_at)
                            <span class="text-amber-700">· طلب التحويل {{ $activeConversation->handoff_requested_at->format('Y-m-d H:i') }}</span>
                        @endif
                    @elseif($handoffStatus !== 'none')
                        <span><span class="font-semibold">حالة التحويل:</span> {{ $handoffStatus }}</span>
                    @endif
                </div>
                @if(is_array($catalogCtx) && data_get($catalogCtx, 'attached_product.name'))
                    <div class="inline-flex items-center gap-1.5 rounded-lg bg-white/80 px-2.5 py-1 text-slate-700 border border-amber-200/50 shadow-2xs">
                        <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                        <span class="font-semibold">المنتج:</span>
                        <span>{{ data_get($catalogCtx, 'attached_product.name') }}</span>
                        @if(data_get($catalogCtx, 'attached_product.selected_variant.name'))
                            <span class="text-slate-500">({{ data_get($catalogCtx, 'attached_product.selected_variant.name') }})</span>
                        @endif
                    </div>
                @endif
            </div>
            @if(is_array($handoffSummary) && !empty($handoffSummary['latest_customer_message']))
                <p class="mt-1.5 truncate text-[11px] text-amber-800"><span class="font-semibold">آخر رسالة:</span> {{ $handoffSummary['latest_customer_message'] }}</p>
            @endif
        </div>
    @endif

    {{-- Messages Area --}}
    <div id="inbox-message-list" class="min-h-0 flex-1 space-y-2.5 overflow-y-auto overscroll-contain hs-scrollbar px-3 py-3.5 sm:px-6" style="overscroll-behavior: contain; -webkit-overflow-scrolling: touch;">
        @forelse($activeConversation->messages as $message)
            @php
                $isInternalNote = $message->direction === 'internal_note'
                    || $message->message_type === 'system'
                    || (bool) data_get($message->metadata, 'handoff_acknowledgement');
                $outbound = in_array($message->direction, ['outbound', 'internal_note'], true);
                $statusLabel = $isInternalNote
                    ? 'ملاحظة داخلية'
                    : ($outbound ? 'صادرة' : 'واردة');
                $messageType = \App\Services\Workforce\WorkforceInboxAttribution::typeForMessage($message);
            @endphp
            <div @class([
                    'hs-inbox-message-row',
                    $isInternalNote ? 'justify-center' : ($outbound ? 'hs-inbox-message-row--sent' : 'hs-inbox-message-row--received'),
                ]) data-message-id="{{ $message->id }}">
                <div @class([
                    'hs-inbox-message-bubble max-w-[85%] sm:max-w-[72%] rounded-2xl px-3.5 py-2 shadow-2xs transition',
                    'rounded-bl-xs bg-[#E8FAF6] text-slate-900 border border-[#C9F0E8]' => $outbound && ! $isInternalNote,
                    'mx-auto border border-amber-300/80 bg-amber-50/90 text-amber-950 shadow-2xs' => $isInternalNote,
                    'rounded-br-xs border border-slate-200/90 bg-white text-slate-800' => ! $outbound && ! $isInternalNote,
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
                                        class="max-h-60 rounded-xl border border-slate-200/90 object-cover shadow-2xs"
                                        loading="lazy"
                                    />
                                @elseif($attachmentReady && $attachment->kind === 'audio')
                                    <div class="rounded-xl border border-slate-200/80 bg-white/80 p-2 shadow-2xs">
                                        <audio controls preload="none" class="w-full max-w-xs">
                                            <source src="{{ route('workspace.message-attachments.download', $attachment) }}" type="{{ $attachment->mime_type ?: 'audio/mpeg' }}">
                                        </audio>
                                    </div>
                                @elseif($attachmentReady && $attachment->kind === 'video')
                                    <video controls preload="metadata" class="max-h-60 w-full rounded-xl border border-slate-200 shadow-2xs">
                                        <source src="{{ route('workspace.message-attachments.download', $attachment) }}" type="{{ $attachment->mime_type ?: 'video/mp4' }}">
                                    </video>
                                @elseif($attachmentReady)
                                    <a
                                        href="{{ route('workspace.message-attachments.download', $attachment) }}"
                                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200/90 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-2xs transition hover:bg-slate-50"
                                    >
                                        <svg class="h-4 w-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        <span>{{ $attachment->original_name ?: 'ملف مرفق' }}</span>
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

                    @if($isInternalNote)
                        <div class="mb-1 flex items-center gap-1 text-[11px] font-bold text-amber-800">
                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            <span>{{ $statusLabel }}</span>
                        </div>
                    @endif

                    <div class="text-[12.5px] sm:text-[13px] leading-[1.6] whitespace-pre-wrap font-normal text-slate-800">
                        {{ $message->content ?: '—' }}
                    </div>

                    {{-- Message Metadata Footer --}}
                    <div class="mt-1 flex items-center justify-end gap-1.5 text-[9.5px] text-slate-400 select-none">
                        <span>{{ $message->created_at?->format('h:i A') }}</span>
                        @if($messageType === \App\Enums\WorkforceParticipantType::AiEmployee)
                            <span class="inline-flex items-center gap-0.5 text-emerald-700 font-medium">
                                <svg class="h-2.5 w-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                <span>AI</span>
                            </span>
                        @elseif($messageType === \App\Enums\WorkforceParticipantType::IndependentWorker)
                            @php
                                $workerDisplayName = $message->workerEngagement?->workerProfile?->display_name
                                    ?: $message->workerEngagement?->workerProfile?->user?->name
                                    ?: $message->user?->name
                                    ?: 'موظف مستقل';
                            @endphp
                            <span class="text-slate-600 font-medium">{{ $workerDisplayName }} (موظف مستقل)</span>
                        @elseif($messageType === \App\Enums\WorkforceParticipantType::CompanyStaff && $message->user)
                            <span class="text-slate-600 font-medium">{{ $message->user->name }}</span>
                        @endif

                        @if($outbound && ! $isInternalNote)
                            {{-- Double Checkmark Icon (WhatsApp Style) --}}
                            <svg class="h-3 w-3 text-[#06C2A4]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m4.5 12.75 4 4 9.5-9.5m-5 9.5 4 4 9.5-9.5" />
                            </svg>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="flex h-full items-center justify-center p-8">
                <div class="rounded-2xl border border-dashed border-slate-200 bg-white p-8 text-center text-sm text-slate-500 shadow-2xs">
                    <svg class="mx-auto mb-2 h-8 w-8 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                    لا توجد رسائل في هذه المحادثة حتى الآن.
                </div>
            </div>
        @endforelse
    </div>

    {{-- Message Input Bar --}}
    <div class="shrink-0 border-t border-slate-200/90 bg-white px-3 py-2 sm:px-5 sm:py-2.5" style="padding-bottom: max(0.5rem, env(safe-area-inset-bottom));">
        @if($inboxCanSend)
        <form id="inbox-compose-form" method="POST" action="{{ $inboxMessageAction }}" class="space-y-1.5">
            @csrf
            <input type="hidden" name="conversation_id" value="{{ $activeConversation->id }}">
            <input type="hidden" name="customer_id" value="{{ $activeConversation->customer_id }}">
            <input type="hidden" name="direction" value="outbound">
            <input type="hidden" name="message_type" value="text">
            <input type="hidden" name="metadata_json" value="{}">
            <div class="flex items-end gap-2">
                <div class="relative min-w-0 flex-1 rounded-2xl border border-slate-200/90 bg-slate-50/70 transition focus-within:border-[#06C2A4] focus-within:bg-white focus-within:ring-2 focus-within:ring-[#06C2A4]/15">
                    <textarea
                        name="content"
                        rows="1"
                        class="w-full resize-none border-0 bg-transparent px-3 py-2 sm:px-3.5 sm:py-2.5 text-xs sm:text-[13px] text-slate-800 placeholder-slate-400 focus:outline-none focus:ring-0 leading-normal"
                        placeholder="اكتب رسالتك هنا... (اضغط Enter للإرسال)"
                    ></textarea>
                </div>
                <button
                    type="submit"
                    class="inline-flex h-9 w-9 sm:h-10 sm:w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-[#06C2A4] to-[#049E86] text-white shadow-xs transition hover:brightness-105 active:scale-95 focus:outline-none focus:ring-2 focus:ring-[#06C2A4]/40"
                    aria-label="إرسال الرسالة"
                    title="إرسال الرسالة"
                >
                    <svg class="h-4 w-4 transform rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                    </svg>
                </button>
            </div>
            <div class="flex items-center justify-between text-[10px] text-slate-400 px-1">
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-1.5 w-1.5 rounded-full {{ $activeChannelChip['dot'] ?? 'bg-slate-400' }}"></span>
                    <span>المصدر: {{ $activeChannelChip['label'] }}</span>
                </span>
                <span class="hidden sm:inline">اضغط Enter للإرسال · Shift+Enter لسطر جديد</span>
            </div>
        </form>
        @else
            <p class="rounded-xl bg-amber-50 px-4 py-3 text-center text-xs font-semibold text-amber-800 border border-amber-200/60">
                إرسال الرسائل غير مشمول في الصلاحيات الممنوحة لهذا الارتباط.
            </p>
        @endif
    </div>

    {{-- Responsibility Audit Log Modal --}}
    <div
        x-show="auditLogOpen"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs"
        @keydown.escape.window="auditLogOpen = false"
    >
        <div 
            class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl border border-slate-200 overflow-hidden text-right"
            dir="rtl"
            @click.outside="auditLogOpen = false"
        >
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 bg-slate-50/70">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-100 text-blue-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    </span>
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">سجل المسؤولية والتحويلات</h3>
                        <p class="text-[11px] text-slate-500">تتبع تاريخ انتقال المسؤولية بين الذكاء الاصطناعي والموظفين</p>
                    </div>
                </div>
                <button 
                    type="button" 
                    @click="auditLogOpen = false" 
                    class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-200/60 hover:text-slate-700 transition"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="p-5 max-h-[60vh] overflow-y-auto space-y-4 hs-scrollbar">
                <template x-if="auditLoading">
                    <div class="py-10 text-center text-xs text-slate-500">
                        <svg class="mx-auto h-6 w-6 animate-spin text-slate-400 mb-2" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                        </svg>
                        <span>جاري تحميل سجل المسؤولية...</span>
                    </div>
                </template>

                <template x-if="!auditLoading && auditLogs.length === 0">
                    <div class="py-10 text-center text-xs text-slate-500">
                        لا توجد تحويلات مسجلة لهذه المحادثة بعد.
                    </div>
                </template>

                <template x-if="!auditLoading && auditLogs.length > 0">
                    <div class="relative border-s-2 border-slate-200 ms-3 space-y-5">
                        <template x-for="log in auditLogs" :key="log.id">
                            <div class="relative ps-6">
                                <div class="absolute -start-[7px] top-1 h-3 w-3 rounded-full border-2 border-white bg-blue-600 shadow-2xs"></div>

                                <div class="rounded-xl border border-slate-100 bg-slate-50/70 p-3 shadow-2xs">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-bold text-xs text-slate-900" x-text="log.action_label"></span>
                                        <span class="text-[10px] text-slate-400" x-text="log.created_at_human || log.created_at"></span>
                                    </div>
                                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                                        <span class="text-slate-500">المسؤول:</span>
                                        <span class="font-bold text-blue-700" x-text="log.new_name"></span>
                                        <template x-if="log.previous_name && log.previous_name !== '—'">
                                            <span class="text-slate-400 text-[11px]">(سابقاً: <span x-text="log.previous_name"></span>)</span>
                                        </template>
                                    </div>
                                    <template x-if="log.reason">
                                        <p class="mt-1 text-[11px] text-slate-600 bg-white rounded-lg p-2 border border-slate-100" x-text="'السبب: ' + log.reason"></p>
                                    </template>
                                    <div class="mt-2 text-[10px] text-slate-400 flex items-center gap-1">
                                        <span>بواسطة:</span>
                                        <span class="font-medium text-slate-600" x-text="log.actor_name"></span>
                                        <span class="opacity-50">·</span>
                                        <span x-text="log.created_at"></span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <div class="border-t border-slate-100 bg-slate-50 px-5 py-3 text-left">
                <button 
                    type="button" 
                    @click="auditLogOpen = false" 
                    class="rounded-xl border border-slate-300 bg-white px-4 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition"
                >
                    إغلاق
                </button>
            </div>
        </div>
    </div>
</section>
