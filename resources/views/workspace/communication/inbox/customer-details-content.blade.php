@php
    $customer = $activeConversation->customer;
    $whatsappNumber = preg_replace('/\D+/', '', (string) ($customer?->whatsapp ?: $customer?->phone));
    $initial = mb_substr($customer?->name ?? '؟', 0, 1);
    $customerNotes = $customer?->relationLoaded('notes')
        ? $customer->getRelation('notes')
        : ($customer?->notes()->latest()->limit(8)->get() ?? collect());
    $customerTags = $customer?->relationLoaded('tags')
        ? $customer->tags
        : ($customer?->tags ?? collect());
    $customerOrders = $customer?->relationLoaded('orders')
        ? $customer->orders
        : ($customer?->orders ?? collect());
@endphp

<div class="min-h-0 flex-1 overflow-y-auto divide-y divide-slate-100 overscroll-contain hs-scrollbar" style="overscroll-behavior: contain; -webkit-overflow-scrolling: touch;">
    @if($customer)
        {{-- Profile Header --}}
        <div class="px-5 py-6 text-center bg-gradient-to-b from-slate-50/50 to-white">
            <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-[#E8FAF6] to-[#C9F0E8] text-xl font-bold text-[#067e6b] shadow-2xs ring-4 ring-[#E8FAF6]/80">
                {{ $initial }}
            </span>
            <h3 class="mt-3.5 text-base font-bold text-slate-900">{{ $customer->name }}</h3>

            <div class="mt-4 space-y-2 text-xs text-slate-600">
                @if($customer->phone)
                    <div class="flex items-center justify-between rounded-xl bg-slate-50/80 px-3 py-2 border border-slate-100">
                        <span class="flex items-center gap-1.5 text-slate-400">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            <span>الهاتف</span>
                        </span>
                        <span class="font-bold text-slate-800 font-mono" dir="ltr">{{ $customer->phone }}</span>
                    </div>
                @endif
                @if($customer->email)
                    <div class="flex items-center justify-between rounded-xl bg-slate-50/80 px-3 py-2 border border-slate-100">
                        <span class="flex items-center gap-1.5 text-slate-400">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <span>البريد</span>
                        </span>
                        <span class="font-semibold text-slate-800 truncate max-w-[140px]">{{ $customer->email }}</span>
                    </div>
                @endif
                @if($customer->city)
                    <div class="flex items-center justify-between rounded-xl bg-slate-50/80 px-3 py-2 border border-slate-100">
                        <span class="flex items-center gap-1.5 text-slate-400">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/></svg>
                            <span>المدينة</span>
                        </span>
                        <span class="font-semibold text-slate-800">{{ $customer->city }}</span>
                    </div>
                @endif
                @if($activeConversation->external_id)
                    <div class="flex items-center justify-between rounded-xl bg-slate-50/80 px-3 py-2 border border-slate-100">
                        <span class="flex items-center gap-1.5 text-slate-400">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"/></svg>
                            <span>المعرّف</span>
                        </span>
                        <span class="font-mono font-semibold text-slate-700">#{{ $activeConversation->external_id }}</span>
                    </div>
                @endif
                @if($customer->created_at)
                    <div class="flex items-center justify-between rounded-xl bg-slate-50/80 px-3 py-2 border border-slate-100">
                        <span class="flex items-center gap-1.5 text-slate-400">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            <span>تاريخ الإنشاء</span>
                        </span>
                        <span class="font-semibold text-slate-700">{{ $customer->created_at->format('Y-m-d') }}</span>
                    </div>
                @endif
            </div>

            {{-- Quick action buttons --}}
            <div class="mt-4 flex flex-wrap gap-2 justify-center">
                @if($whatsappNumber)
                    <a
                        href="https://wa.me/{{ $whatsappNumber }}"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800 transition hover:bg-emerald-100"
                    >
                        <svg class="h-3.5 w-3.5 fill-current text-[#25D366]" viewBox="0 0 24 24"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.711 2.598 2.664-.699c.971.53 1.769.815 2.796.815 3.183 0 5.769-2.587 5.77-5.767 0-3.181-2.587-5.767-5.77-5.767zm3.376 8.167c-.14.394-.807.755-1.121.803-.314.048-.718.066-2.072-.472-1.631-.649-2.673-2.316-2.754-2.424-.081-.108-.66-878-.66-1.674 0-.796.417-1.189.565-1.349.148-.16.323-.201.431-.201.108 0 .216.002.31.007.098.005.23-.037.359.274.133.323.454 1.106.494 1.187.04.081.067.176.013.283-.054.108-.081.176-.162.27-.081.095-.171.212-.244.285-.081.081-.166.17-.071.333.095.162.42 693.902 1.122.619.551 1.14.721 1.302.802.162.081.256.068.35-.041.095-.108.404-.472.512-.634.108-.162.216-.135.364-.081.148.054.943.445 1.105.526.162.081.27.122.31.189.04.068.04.394-.1.788z"/></svg>
                        <span>واتساب</span>
                    </a>
                @endif
                @if($customer->phone)
                    <a
                        href="tel:{{ $customer->phone }}"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                    >
                        <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        <span>مكالمة</span>
                    </a>
                @endif
                @if(($inboxAllowsCompanyActions ?? true) && \Illuminate\Support\Facades\Route::has('workspace.customers.edit'))
                    <a
                        href="{{ route('workspace.customers.edit', $customer) }}"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                    >
                        <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        <span>الملف الكامل</span>
                    </a>
                @endif
            </div>
        </div>

        {{-- Notes --}}
        <details open class="group px-4 py-3.5">
            <summary class="flex cursor-pointer items-center justify-between text-xs font-bold text-slate-800">
                <span class="flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5 text-[#00BFAE]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span>ملاحظات ({{ $customerNotes->count() }})</span>
                </span>
                <svg class="h-3.5 w-3.5 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 9-7 7-7-7"/></svg>
            </summary>
            <div class="mt-3 space-y-2.5">
                @forelse($customerNotes as $note)
                    <div class="rounded-2xl border border-slate-100 bg-slate-50/70 p-3 text-xs leading-5 text-slate-700">
                        <p>{{ $note->body }}</p>
                        @if($note->created_at)
                            <span class="mt-1 block text-[10px] text-slate-400">{{ $note->created_at->diffForHumans() }}</span>
                        @endif
                    </div>
                @empty
                    <p class="text-xs text-slate-400 py-1">لا توجد ملاحظات مسجلة.</p>
                @endforelse
                @if(($inboxAllowsCompanyActions ?? true) && \Illuminate\Support\Facades\Route::has('workspace.customers.notes.store'))
                    <form method="POST" action="{{ route('workspace.customers.notes.store', $customer) }}" class="mt-2 space-y-2">
                        @csrf
                        <textarea
                            name="body"
                            rows="2"
                            required
                            class="w-full rounded-xl border border-slate-200 bg-white p-2.5 text-xs text-slate-800 placeholder-slate-400 transition focus:border-[#00BFAE] focus:outline-none focus:ring-2 focus:ring-[#00BFAE]/15"
                            placeholder="أضف ملاحظة خاصة بالعميل..."
                        ></textarea>
                        <button class="inline-flex w-full items-center justify-center rounded-xl bg-[#00BFAE] py-2 text-xs font-semibold text-white shadow-2xs transition hover:bg-[#06a89a]">
                            حفظ الملاحظة
                        </button>
                    </form>
                @endif
            </div>
        </details>

        {{-- Tags --}}
        <details class="group px-4 py-3.5">
            <summary class="flex cursor-pointer items-center justify-between text-xs font-bold text-slate-800">
                <span class="flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5 text-[#00BFAE]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    <span>الوسوم</span>
                </span>
                <svg class="h-3.5 w-3.5 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 9-7 7-7-7"/></svg>
            </summary>
            <div class="mt-3 flex flex-wrap gap-1.5">
                @forelse($customerTags as $tag)
                    <span class="rounded-full border border-slate-200/80 bg-slate-100/80 px-2.5 py-1 text-[11px] font-semibold text-slate-700">
                        {{ $tag->name }}
                    </span>
                @empty
                    <p class="text-xs text-slate-400 py-1">لا توجد وسوم مرتبطة بهذا العميل.</p>
                @endforelse
            </div>
        </details>

        {{-- Media & Documents Gallery (معرض الوسائط والمستندات) --}}
        @php
            $conversationAttachments = \App\Models\MessageAttachment::query()
                ->where('workspace_id', $activeConversation->workspace_id)
                ->whereHas('message', fn ($q) => $q->where('conversation_id', $activeConversation->id))
                ->where('status', \App\Models\MessageAttachment::STATUS_STORED)
                ->with('message')
                ->latest()
                ->limit(30)
                ->get();
            $imagesCount = $conversationAttachments->where('kind', 'image')->count();
            $docsCount = $conversationAttachments->whereIn('kind', ['document', 'file', 'video', 'audio'])->count();
        @endphp
        <details class="group px-4 py-3.5" open id="inbox-media-gallery-section" x-data="{ mediaFilter: 'all' }">
            <summary class="flex cursor-pointer items-center justify-between text-xs font-bold text-slate-800">
                <span class="flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5 text-[#06C2A4]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <span>الوسائط والمستندات</span>
                    <span class="rounded-full bg-slate-100 px-1.5 py-0.2 text-[10px] text-slate-500 font-mono">{{ $conversationAttachments->count() }}</span>
                </span>
                <svg class="h-3.5 w-3.5 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 9-7 7-7-7"/></svg>
            </summary>

            <div class="mt-3 space-y-2.5">
                {{-- Filter Chips --}}
                <div class="flex items-center gap-1 text-[11px]">
                    <button type="button" @click="mediaFilter = 'all'" :class="mediaFilter === 'all' ? 'bg-[#06C2A4] text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" class="rounded-full px-2.5 py-0.5 transition">الكل ({{ $conversationAttachments->count() }})</button>
                    <button type="button" @click="mediaFilter = 'image'" :class="mediaFilter === 'image' ? 'bg-[#06C2A4] text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" class="rounded-full px-2.5 py-0.5 transition">الصور ({{ $imagesCount }})</button>
                    <button type="button" @click="mediaFilter = 'doc'" :class="mediaFilter === 'doc' ? 'bg-[#06C2A4] text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'" class="rounded-full px-2.5 py-0.5 transition">المستندات ({{ $docsCount }})</button>
                </div>

                {{-- Grid of attachments --}}
                @if($conversationAttachments->isEmpty())
                    <p class="text-xs text-slate-400 py-2 text-center">لا توجد وسائط أو ملفات في هذه المحادثة حتى الآن.</p>
                @else
                    <div class="grid grid-cols-3 gap-1.5 pt-1">
                        @foreach($conversationAttachments as $att)
                            @php
                                $attUrl = route('workspace.message-attachments.download', $att);
                                $isImg = $att->kind === 'image';
                                $messageDate = $att->message?->created_at?->format('Y-m-d H:i') ?? '';
                                $sender = $att->message?->direction === 'outbound' ? 'أنت' : ($customer?->name ?? 'العميل');
                            @endphp
                            <div
                                x-show="mediaFilter === 'all' || (mediaFilter === 'image' && '{{ $att->kind }}' === 'image') || (mediaFilter === 'doc' && '{{ $att->kind }}' !== 'image')"
                                class="group relative aspect-square overflow-hidden rounded-xl border border-slate-200/80 bg-slate-100 cursor-pointer shadow-2xs hover:shadow-sm transition"
                            >
                                @if($isImg)
                                    <img
                                        src="{{ $attUrl }}"
                                        alt="{{ $att->original_name }}"
                                        class="h-full w-full object-cover transition duration-300 group-hover:scale-110"
                                        loading="lazy"
                                        data-media-viewer="true"
                                        data-media-url="{{ $attUrl }}"
                                        data-media-name="{{ $att->original_name }}"
                                        data-media-sender="{{ $sender }}"
                                        data-media-time="{{ $messageDate }}"
                                        data-media-msg-id="{{ $att->message_id }}"
                                    />
                                @else
                                    <a
                                        href="{{ $attUrl }}"
                                        target="_blank"
                                        download="{{ $att->original_name }}"
                                        class="flex h-full w-full flex-col items-center justify-center p-1.5 text-center transition hover:bg-slate-200"
                                        title="{{ $att->original_name }}"
                                    >
                                        <svg class="h-6 w-6 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                        <span class="mt-1 line-clamp-1 w-full text-[9px] font-medium text-slate-700">{{ $att->original_name }}</span>
                                    </a>
                                @endif

                                {{-- Jump to message action on hover --}}
                                @if($att->message_id)
                                    <button
                                        type="button"
                                        class="absolute bottom-1 end-1 z-10 hidden rounded-md bg-black/60 p-1 text-white opacity-0 transition group-hover:flex group-hover:opacity-100 hover:bg-[#06C2A4]"
                                        data-jump-to-msg="{{ $att->message_id }}"
                                        title="الانتقال إلى الرسالة الأصلية"
                                    >
                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </details>

        {{-- Orders --}}
        <details class="group px-4 py-3.5">
            <summary class="flex cursor-pointer items-center justify-between text-xs font-bold text-slate-800">
                <span class="flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5 text-[#00BFAE]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                    <span>الطلبات ({{ $customerOrders->count() }})</span>
                </span>
                <svg class="h-3.5 w-3.5 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 9-7 7-7-7"/></svg>
            </summary>
            <div class="mt-3 space-y-2">
                @forelse($customerOrders as $order)
                    <div class="rounded-2xl border border-slate-100 bg-slate-50/70 p-3 text-xs text-slate-700">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-slate-900">{{ $order->order_number ?: 'طلب #'.$order->id }}</span>
                            <span class="rounded-full bg-slate-200/80 px-2 py-0.5 text-[10px] font-semibold text-slate-700">{{ $order->status }}</span>
                        </div>
                        @if($order->placed_at)
                            <span class="mt-1 block text-[11px] text-slate-400">{{ $order->placed_at->format('Y-m-d') }}</span>
                        @endif
                    </div>
                @empty
                    <p class="text-xs text-slate-400 py-1">لا توجد طلبات سابقة لهذا العميل.</p>
                @endforelse
            </div>
        </details>

        {{-- Previous Activities --}}
        <details class="group px-4 py-3.5">
            <summary class="flex cursor-pointer items-center justify-between text-xs font-bold text-slate-800">
                <span class="flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5 text-[#00BFAE]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>النشاطات السابقة</span>
                </span>
                <svg class="h-3.5 w-3.5 text-slate-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19 9-7 7-7-7"/></svg>
            </summary>
            <div class="mt-3 space-y-2 text-xs text-slate-500">
                @if($customer->last_conversation_at)
                    <div class="flex items-center justify-between">
                        <span>آخر محادثة:</span>
                        <span class="font-semibold text-slate-700">{{ $customer->last_conversation_at->diffForHumans() }}</span>
                    </div>
                @endif
                @if($customer->last_order_at)
                    <div class="flex items-center justify-between">
                        <span>آخر طلب:</span>
                        <span class="font-semibold text-slate-700">{{ $customer->last_order_at->diffForHumans() }}</span>
                    </div>
                @endif
                @if(! $customer->last_conversation_at && ! $customer->last_order_at)
                    <p class="text-xs text-slate-400 py-1">لا يوجد نشاط مسجّل.</p>
                @endif
            </div>
        </details>
    @else
        <div class="p-6">
            <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50/60 p-6 text-center">
                <p class="text-xs font-bold text-slate-700">لا يوجد عميل مرتبط</p>
                <p class="mt-1 text-xs text-slate-400">هذه المحادثة غير مربوطة بعميل حتى الآن.</p>
            </div>
        </div>
    @endif
</div>
