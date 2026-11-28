<x-communication-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('workspace.customers.index') }}" class="rounded-xl border border-gray-200 bg-white p-2 text-gray-500 hover:text-gray-900 shadow-sm dark:bg-gray-800 dark:border-gray-700 dark:text-gray-400">
                    <svg class="w-5 h-5 rtl:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $customer->name }}</h2>
                        <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium {{ $customer->statusEnum()->badgeClass() }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $customer->statusEnum()->dotClass() }}"></span>
                            <span>{{ $customer->statusEnum()->label() }}</span>
                        </span>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        {{ $customer->partyType() === 'company' ? 'حساب شركة' : 'حساب فردي' }} • أنشئ بتاريخ {{ $customer->created_at->format('Y-m-d') }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('workspace.customers.edit', $customer) }}" class="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 shadow-sm dark:bg-gray-800 dark:border-gray-700 dark:text-gray-200">
                    تعديل البيانات
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 space-y-6">
            @include('workspace.partials.nav')
            @include('partials.flash')

            <!-- Top Highlights Grid -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <!-- Status & Fast Changer -->
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">حالة العميل الحالية</div>
                    <form method="POST" action="{{ route('workspace.customers.status.update', $customer) }}" class="mt-2 space-y-2">
                        @csrf @method('PATCH')
                        <select name="status" onchange="this.form.submit()" class="w-full rounded-xl border-gray-300 py-2 text-xs font-semibold focus:border-emerald-500 focus:ring-emerald-500 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100">
                            @foreach(\App\Enums\CustomerStatus::cases() as $st)
                                <option value="{{ $st->value }}" {{ $customer->statusEnum() === $st ? 'selected' : '' }}>
                                    {{ $st->label() }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-[10px] text-gray-400">اختر الحالة لتحديثها وتوثيقها تلقائياً.</p>
                    </form>
                </div>

                <!-- Orders Count -->
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">إجمالي الطلبات</div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-2">{{ $customer->orders_count }}</div>
                    <div class="text-[11px] text-gray-400 mt-1">آخر طلب: {{ $customer->last_order_at?->diffForHumans() ?? 'لا يوجد' }}</div>
                </div>

                <!-- Total Purchases -->
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">إجمالي المشتريات</div>
                    <div class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-2">
                        {{ number_format((float)$customer->total_purchases, 2) }} <span class="text-xs font-normal">ر.س</span>
                    </div>
                    <div class="text-[11px] text-gray-400 mt-1">الرصيد الدفتري: {{ number_format((float)$customer->storedBalance(), 2) }} ر.س</div>
                </div>

                <!-- Contact Shortcut -->
                <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700 flex flex-col justify-between">
                    <div>
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">معلومات الاتصال السريع</div>
                        <div class="font-mono text-sm font-semibold text-gray-800 dark:text-gray-200 mt-2 dir-ltr text-right">{{ $customer->phone }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $customer->email ?? 'بدون بريد إلكتروني' }}</div>
                    </div>
                    <a href="https://wa.me/{{ preg_replace('/[^\d]/', '', $customer->phone) }}" target="_blank" rel="noopener" 
                       class="mt-3 inline-flex items-center justify-center gap-1.5 rounded-xl bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 py-1.5 px-3 text-xs font-bold text-emerald-800 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-300 transition">
                        <span>محادثة واتساب مباشرة</span>
                    </a>
                </div>
            </div>

            <!-- Tabbed Main Content -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left 2 Cols: Bookings, Orders & Conversations -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Bookings Section -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:bg-gray-800 dark:border-gray-700 space-y-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-base font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                                <svg class="w-5 h-5 text-[#06C2A4]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <span>سجل الحجوزات والمواعيد ({{ $customer->bookings->count() }})</span>
                            </h3>
                            @if(\Illuminate\Support\Facades\Route::has('workspace.bookings.create'))
                                <a href="{{ route('workspace.bookings.create', ['customer_id' => $customer->id]) }}" class="rounded-xl border border-[#06C2A4]/40 bg-[#E8FAF6] px-3 py-1.5 text-xs font-bold text-[#067E6B] hover:bg-[#D5F5EE]">
                                    + حجز جديد
                                </a>
                            @endif
                        </div>

                        <div class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($customer->bookings ?? [] as $b)
                                <div class="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 first:pt-0 last:pb-0">
                                    <div>
                                        <a href="{{ route('workspace.bookings.show', $b) }}" class="font-mono font-bold text-sm text-slate-900 hover:text-[#067E6B]">{{ $b->booking_reference }}</a>
                                        <span class="ms-2 text-xs font-semibold text-slate-700">{{ $b->service_name }}</span>
                                        <div class="mt-0.5 text-xs text-slate-500">
                                            {{ $b->booking_date?->format('Y-m-d') }} · {{ $b->start_time }} - {{ $b->end_time }}
                                            @if($b->branch?->name) · {{ $b->branch->name }} @endif
                                            @if($b->provider?->name) · {{ $b->provider->name }} @endif
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        @include('workspace.bookings.partials.status-badge', ['status' => $b->status])
                                        <a href="{{ route('workspace.bookings.show', $b) }}" class="text-xs font-bold text-[#067E6B] hover:underline">التفاصيل</a>
                                    </div>
                                </div>
                            @empty
                                <p class="text-xs text-gray-400 py-2">لا توجد حجوزات مسجلة لهذا العميل.</p>
                            @endforelse
                        </div>
                    </div>

                    <!-- Orders Section -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:bg-gray-800 dark:border-gray-700 space-y-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-base font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                                <span>سجل الطلبات والمنتجات ({{ $customer->orders->count() }})</span>
                            </h3>
                        </div>

                        <div class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($customer->orders ?? [] as $order)
                                <div class="py-4 space-y-3 first:pt-0 last:pb-0">
                                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                        <div>
                                            <span class="font-mono font-bold text-sm text-gray-900 dark:text-gray-100">{{ $order->order_number }}</span>
                                            <span class="text-xs text-gray-400 mr-2">{{ $order->placed_at?->format('Y-m-d H:i') ?? $order->created_at->format('Y-m-d H:i') }}</span>
                                            @if($order->review_reason)
                                                <span class="inline-flex items-center gap-1 rounded bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 mr-2 dark:bg-amber-900/40 dark:text-amber-300">
                                                    {{ $order->review_reason }}
                                                </span>
                                            @endif
                                            @if(! empty($order->metadata['shipping_address']) || ! empty($order->metadata['recipient_phone']))
                                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 flex flex-wrap gap-x-3 gap-y-0.5">
                                                    @if(! empty($order->metadata['recipient_name']))
                                                        <span>المستلم: {{ $order->metadata['recipient_name'] }}</span>
                                                    @endif
                                                    @if(! empty($order->metadata['recipient_phone']))
                                                        <span class="font-mono dir-ltr">{{ $order->metadata['recipient_phone'] }}</span>
                                                    @endif
                                                    @if(! empty($order->metadata['shipping_address']))
                                                        <span>العنوان: {{ $order->metadata['shipping_address'] }}</span>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <!-- Status badges -->
                                            <span class="rounded-lg bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                                حالة الطلب: {{ $order->status }}
                                            </span>
                                            <span class="rounded-lg px-2.5 py-0.5 text-xs font-semibold {{ $order->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                                                {{ $order->payment_status === 'paid' ? 'مدفوع' : 'غير مدفوع' }}
                                            </span>
                                            <span class="font-bold text-sm text-emerald-600">
                                                {{ number_format((float)$order->total_amount, 2) }} {{ $order->currency }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Order Items -->
                                    <div class="rounded-xl bg-gray-50/80 p-3 text-xs dark:bg-gray-900/40">
                                        <div class="font-semibold text-gray-600 dark:text-gray-400 mb-1.5">المنتجات المطلوبة:</div>
                                        <div class="space-y-1">
                                            @foreach($order->items ?? [] as $item)
                                                <div class="flex items-center justify-between text-gray-800 dark:text-gray-200">
                                                    <span>
                                                        {{ $item->product_name }}
                                                        @if($item->variant_name) <span class="text-gray-400">({{ $item->variant_name }})</span> @endif
                                                        <span class="text-gray-500 font-bold">× {{ $item->quantity }}</span>
                                                    </span>
                                                    <span class="font-mono">
                                                        {{ number_format((float)$item->total_amount, 2) }} {{ $item->currency }}
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="py-8 text-center text-sm text-gray-400">
                                    لا توجد طلبات مسجلة لهذا العميل حتى الآن.
                                </div>
                            @endforelse
                        </div>
                    </div>

                    <!-- AI Conversations Section -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:bg-gray-800 dark:border-gray-700 space-y-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-base font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                <span>المحادثات المرتبطة مع موظف الذكاء الاصطناعي ({{ $customer->conversations->count() }})</span>
                            </h3>
                        </div>

                        <div class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse($customer->conversations ?? [] as $conv)
                                @php
                                    $lastMsg = $conv->messages->first();
                                @endphp
                                <div class="py-3.5 flex items-center justify-between gap-4 first:pt-0 last:pb-0">
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2">
                                            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                                قناة: {{ $conv->channel }}
                                            </span>
                                            <span class="text-xs text-gray-400">
                                                {{ $conv->last_message_at?->diffForHumans() ?? $conv->created_at->diffForHumans() }}
                                            </span>
                                        </div>
                                        @if($lastMsg)
                                            <p class="text-xs text-gray-600 dark:text-gray-300 line-clamp-1" title="{{ $lastMsg->content }}">
                                                {{ $lastMsg->direction === 'outbound' ? 'الرد: ' : 'العميل: ' }}{{ $lastMsg->content }}
                                            </p>
                                        @endif
                                    </div>
                                    <a href="{{ route('workspace.conversations.index', ['conversation_id' => $conv->id]) }}" 
                                       class="rounded-xl border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-100 dark:bg-blue-950/40 dark:border-blue-800 dark:text-blue-300 transition shrink-0">
                                        فتح في صندوق المحادثات ↗
                                    </a>
                                </div>
                            @empty
                                <div class="py-6 text-center text-sm text-gray-400">
                                    لا توجد محادثات مرتبطة مسجلة.
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <!-- Right Col: Personal Info & Internal Notes & Timeline -->
                <div class="space-y-6">
                    <!-- Personal Info Card -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:bg-gray-800 dark:border-gray-700 space-y-3">
                        <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100 border-b pb-2">بيانات العميل الشخصية</h4>
                        <dl class="space-y-2 text-xs">
                            <div class="flex justify-between">
                                <dt class="text-gray-500">نوع الكيان:</dt>
                                <dd class="font-semibold text-gray-800 dark:text-gray-200">{{ $customer->partyType() === 'company' ? 'شركة' : 'فرد' }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">المدينة:</dt>
                                <dd class="font-semibold text-gray-800 dark:text-gray-200">{{ $customer->city ?: '-' }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">الحي والشارع:</dt>
                                <dd class="font-semibold text-gray-800 dark:text-gray-200">{{ $customer->district ?: '' }} {{ $customer->street ?: '' }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-gray-500">العنوان الكامل:</dt>
                                <dd class="font-semibold text-gray-800 dark:text-gray-200">{{ $customer->address ?: '-' }}</dd>
                            </div>
                            @if($customer->vat_number)
                                <div class="flex justify-between">
                                    <dt class="text-gray-500">الرقم الضريبي:</dt>
                                    <dd class="font-mono text-gray-800 dark:text-gray-200">{{ $customer->vat_number }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>

                    <!-- Delivery Location Card -->
                    @php
                        $customerLocation = $customer->primaryLocation?->toSummaryArray()
                            ?? (is_array(data_get($customer->metadata, 'delivery_location')) ? data_get($customer->metadata, 'delivery_location') : null);
                    @endphp
                    @if($customerLocation)
                        @include('partials.location-card', [
                            'location' => $customerLocation,
                            'compact' => false,
                            'allowVerify' => true,
                        ])
                    @endif

                    <!-- Internal Notes Form & List -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:bg-gray-800 dark:border-gray-700 space-y-4">
                        <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                            <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            <span>الملاحظات الداخلية</span>
                        </h4>

                        <!-- Add Note Form -->
                        <form method="POST" action="{{ route('workspace.customers.notes.store', $customer) }}" class="space-y-2">
                            @csrf
                            <textarea name="body" rows="2" required 
                                      placeholder="أضف ملاحظة داخلية خاصة بالعميل أو طلبه..."
                                      class="w-full rounded-xl border-gray-300 p-2 text-xs focus:border-emerald-500 focus:ring-emerald-500 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100"></textarea>
                            <button type="submit" class="rounded-xl bg-gray-900 hover:bg-gray-800 px-3 py-1.5 text-xs font-semibold text-white transition dark:bg-gray-100 dark:text-gray-900">
                                إضافة الملاحظة
                            </button>
                        </form>

                        <!-- Notes List -->
                        <div class="space-y-2 divide-y divide-gray-100 dark:divide-gray-700 pt-2">
                            @forelse($customer->notes ?? [] as $note)
                                <div class="pt-2 text-xs text-gray-700 dark:text-gray-300">
                                    <p>{{ $note->body }}</p>
                                    <div class="mt-1 flex items-center gap-2 text-[10px] text-gray-400">
                                        <span>{{ $note->user?->name ?? 'النظام' }}</span>
                                        <span>•</span>
                                        <span>{{ $note->created_at->diffForHumans() }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="text-xs text-gray-400">لا توجد ملاحظات داخلية بعد.</div>
                            @endforelse
                        </div>
                    </div>

                    <!-- CRM Timeline Log -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:bg-gray-800 dark:border-gray-700 space-y-4">
                        <h4 class="text-sm font-bold text-gray-900 dark:text-gray-100">سجل نشاط وتواصل العميل</h4>
                        <div class="space-y-3 text-xs">
                            @forelse($timeline ?? [] as $event)
                                <div class="flex items-start gap-2.5">
                                    <div class="h-2 w-2 rounded-full bg-emerald-500 mt-1.5 shrink-0"></div>
                                    <div>
                                        <div class="font-semibold text-gray-800 dark:text-gray-200">{{ $event['summary'] ?? ($event['title'] ?? 'نشاط') }}</div>
                                        @if(!empty($event['at']))
                                            <div class="text-[10px] text-gray-400 mt-0.5">{{ \Carbon\Carbon::parse($event['at'])->diffForHumans() }}</div>
                                        @elseif(!empty($event['date']))
                                            <div class="text-[10px] text-gray-400 mt-0.5">{{ \Carbon\Carbon::parse($event['date'])->diffForHumans() }}</div>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <div class="text-gray-400 text-xs">لا يوجد نشاط مسجل.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-communication-layout>
