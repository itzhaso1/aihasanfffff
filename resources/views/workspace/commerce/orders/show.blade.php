<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="{{ route('workspace.commerce.orders.index') }}" class="hs-btn-icon" title="العودة إلى الطلبات">
                    <svg class="h-4 w-4 rtl:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-bold text-slate-900 font-mono">طلب #{{ $order->order_number }}</h1>
                        @if($order->status === 'completed')
                            <span class="hs-badge-success text-xs">مكتمل</span>
                        @elseif($order->status === 'draft' || $order->review_reason)
                            <span class="hs-badge-warning text-xs">يحتاج مراجعة</span>
                        @elseif($order->status === 'cancelled')
                            <span class="hs-badge-danger text-xs">ملغي</span>
                        @else
                            <span class="hs-badge-info text-xs">{{ $order->status }}</span>
                        @endif
                    </div>
                    <p class="text-xs text-slate-400 mt-0.5">
                        سُجل بتاريخ {{ $order->created_at?->format('Y-m-d h:i A') }} ({{ $order->created_at?->diffForHumans() }})
                    </p>
                </div>
            </div>

            <!-- Header Actions -->
            <div class="flex items-center gap-2">
                @if($order->conversation_id)
                    <a href="{{ route('workspace.conversations.index', ['conversation_id' => $order->conversation_id]) }}" class="hs-btn-primary text-xs">
                        فتح المحادثة المرتبطة
                    </a>
                @endif
                @if($order->customer)
                    <a href="{{ route('workspace.customers.show', $order->customer) }}" class="hs-btn-secondary text-xs">
                        الملف التعريفي للعميل
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="space-y-6" dir="rtl">
        @include('partials.flash')

        <!-- Review Alert Banner if needed -->
        @if($order->review_reason)
            <div class="rounded-2xl border border-amber-300 bg-amber-50 p-4">
                <div class="flex items-start gap-3">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-700">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </span>
                    <div>
                        <h4 class="text-xs font-bold text-amber-900">هذا الطلب يتطلب مراجعة بشرية</h4>
                        <p class="mt-0.5 text-xs text-amber-800">{{ $order->review_reason }}</p>
                        @if($order->review_required_action)
                            <p class="mt-1 text-[11px] font-semibold text-amber-700">الإجراء المطلوب: {{ $order->review_required_action }}</p>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        <!-- Summary KPI Cards -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="hs-stat-card p-4">
                <span class="text-xs font-medium text-slate-500">حالة الطلب</span>
                <p class="mt-1 text-base font-bold text-slate-900">{{ $order->status }}</p>
                <span class="text-[10px] text-slate-400 mt-0.5 block">التجارة: {{ $order->commerce_status?->value ?? $order->commerce_status }}</span>
            </div>
            <div class="hs-stat-card p-4">
                <span class="text-xs font-medium text-slate-500">حالة الدفع</span>
                <p class="mt-1 text-base font-bold {{ $order->payment_status === 'paid' ? 'text-emerald-600' : 'text-amber-600' }}">
                    {{ $order->payment_status === 'paid' ? 'تم الدفع بنجاح' : ($order->payment_status ?: 'معلق') }}
                </p>
                <span class="text-[10px] text-slate-400 mt-0.5 block">عبر الرابط الإلكتروني</span>
            </div>
            <div class="hs-stat-card p-4">
                <span class="text-xs font-medium text-slate-500">إجمالي المبلغ</span>
                <p class="mt-1 text-xl font-extrabold text-slate-900">
                    {{ number_format((float) $order->total_amount, 2) }}
                    <span class="text-xs font-normal text-slate-400">{{ $order->currency }}</span>
                </p>
                <span class="text-[10px] text-slate-400 mt-0.5 block">{{ $order->items->count() }} أصناف بالطلب</span>
            </div>
            <div class="hs-stat-card p-4">
                <span class="text-xs font-medium text-slate-500">العميل</span>
                <p class="mt-1 text-base font-bold text-slate-900 truncate">
                    {{ $order->customer?->name ?? 'عميل ضيف' }}
                </p>
                <span class="text-[10px] text-slate-400 mt-0.5 block font-mono">{{ $order->customer?->phone ?? '—' }}</span>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Left 2 Cols: Order Items, Allocations, Reservations -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Items Table -->
                <div class="hs-card p-0 overflow-hidden">
                    <div class="border-b border-slate-100 p-4 flex items-center justify-between">
                        <h3 class="text-sm font-bold text-slate-900">أصناف الطلب المحجوزة</h3>
                        <span class="text-xs text-slate-400">{{ $order->items->count() }} عنصر</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="hs-table">
                            <thead>
                                <tr>
                                    <th>الصنف</th>
                                    <th>البائع</th>
                                    <th>الكمية</th>
                                    <th>سعر الوحدة</th>
                                    <th class="text-end">المجموع</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse($order->items as $item)
                                    <tr>
                                        <td>
                                            <span class="font-bold text-slate-900 block">{{ $item->product_name }}</span>
                                            @if($item->variant_name)
                                                <span class="text-xs text-slate-400">{{ $item->variant_name }}</span>
                                            @endif
                                            @if($item->sku)
                                                <span class="text-[10px] font-mono text-slate-400 block">SKU: {{ $item->sku }}</span>
                                            @endif
                                        </td>
                                        <td class="text-xs text-slate-600">{{ $item->seller_display_name ?? 'الرئيسي' }}</td>
                                        <td class="font-bold text-slate-800">{{ $item->quantity }}</td>
                                        <td class="text-xs text-slate-600">{{ number_format((float) $item->unit_price, 2) }} {{ $item->currency }}</td>
                                        <td class="text-end font-bold text-slate-900">{{ number_format((float) $item->total_amount, 2) }} {{ $item->currency }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="py-6 text-center text-xs text-slate-400">لا توجد بنود مسجلة لهذا الطلب.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <!-- Totals Calculation -->
                    <div class="border-t border-slate-100 bg-slate-50/60 p-4 space-y-1.5 text-xs text-slate-600">
                        <div class="flex justify-between">
                            <span>المجموع الفرعي:</span>
                            <span class="font-semibold text-slate-800">{{ number_format((float) $order->subtotal, 2) }} {{ $order->currency }}</span>
                        </div>
                        @if((float) $order->discount_amount > 0)
                            <div class="flex justify-between text-emerald-600">
                                <span>الخصم:</span>
                                <span>-{{ number_format((float) $order->discount_amount, 2) }} {{ $order->currency }}</span>
                            </div>
                        @endif
                        @if((float) $order->tax_amount > 0)
                            <div class="flex justify-between">
                                <span>الضريبة:</span>
                                <span class="font-semibold text-slate-800">{{ number_format((float) $order->tax_amount, 2) }} {{ $order->currency }}</span>
                            </div>
                        @endif
                        @if((float) $order->shipping_amount > 0)
                            <div class="flex justify-between">
                                <span>رسوم التوصيل:</span>
                                <span class="font-semibold text-slate-800">{{ number_format((float) $order->shipping_amount, 2) }} {{ $order->currency }}</span>
                            </div>
                        @endif
                        <div class="flex justify-between border-t border-slate-200/80 pt-2 text-sm font-extrabold text-slate-900">
                            <span>الإجمالي النهائي:</span>
                            <span>{{ number_format((float) $order->total_amount, 2) }} {{ $order->currency }}</span>
                        </div>
                    </div>
                </div>

                <!-- Seller Allocations if present -->
                @if($order->sellerAllocations && $order->sellerAllocations->isNotEmpty())
                    <div class="hs-card p-0 overflow-hidden">
                        <div class="border-b border-slate-100 p-4">
                            <h3 class="text-sm font-bold text-slate-900">تخصيصات البائعين والتسوية المالية</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="hs-table text-xs">
                                <thead>
                                    <tr>
                                        <th>البائع</th>
                                        <th>المبلغ الإجمالي</th>
                                        <th>رسوم المنصة</th>
                                        <th>صافي البائع</th>
                                        <th>حالة التسوية</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($order->sellerAllocations as $alloc)
                                        <tr>
                                            <td class="font-semibold text-slate-800">{{ $alloc->seller?->display_name ?: $alloc->seller?->name }}</td>
                                            <td>{{ $alloc->gross_amount }} {{ $alloc->currency }}</td>
                                            <td class="text-slate-500">{{ $alloc->platform_fee_amount }} {{ $alloc->currency }}</td>
                                            <td class="font-bold text-slate-900">{{ $alloc->seller_net_amount }} {{ $alloc->currency }}</td>
                                            <td>
                                                <span class="hs-badge-neutral text-[10px]">{{ $alloc->settlement_status?->value ?? $alloc->settlement_status }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>

            <!-- Right 1 Col: Customer Card & Order Meta -->
            <div class="space-y-6">
                <!-- Customer Card -->
                <div class="hs-card space-y-4">
                    <h3 class="text-sm font-bold text-slate-900 border-b border-slate-100 pb-3">بيانات العميل</h3>
                    @if($order->customer)
                        <div class="flex items-center gap-3">
                            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-[#E8FAF6] to-[#C9F0E8] font-bold text-[#067E6B]">
                                {{ mb_substr($order->customer->name, 0, 1) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('workspace.customers.show', $order->customer) }}" class="font-bold text-slate-900 hover:text-[#067E6B] block truncate text-sm">
                                    {{ $order->customer->name }}
                                </a>
                                <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $order->customer->phone ?: 'بدون هاتف' }}</p>
                            </div>
                        </div>
                        <div class="space-y-2 text-xs pt-2">
                            <div class="flex justify-between text-slate-600">
                                <span class="text-slate-400">البريد الإلكتروني:</span>
                                <span class="font-medium text-slate-800">{{ $order->customer->email ?: '—' }}</span>
                            </div>
                            <div class="flex justify-between text-slate-600">
                                <span class="text-slate-400">إجمالي الطلبات:</span>
                                <span class="font-bold text-slate-800">{{ $order->customer->orders()->count() }}</span>
                            </div>
                        </div>
                        <div class="pt-2">
                            <a href="{{ route('workspace.customers.show', $order->customer) }}" class="hs-btn-secondary w-full text-xs py-2">
                                عرض السجل الكامل للعميل
                            </a>
                        </div>
                    @else
                        <p class="text-xs text-slate-500">تم تسجيل هذا الطلب كعميل ضيف دون حساب مرتبط.</p>
                    @endif
                </div>

                <!-- Delivery Location Card -->
                @php
                    $orderLocation = $order->deliveryLocation?->toSummaryArray()
                        ?? (is_array(data_get($order->metadata, 'delivery_location')) ? data_get($order->metadata, 'delivery_location') : null);
                @endphp
                @if($orderLocation)
                    @include('partials.location-card', [
                        'location' => $orderLocation,
                        'compact' => false,
                        'allowVerify' => true,
                    ])
                @endif

                <!-- Order Notes & Metadata -->
                <div class="hs-card space-y-3">
                    <h3 class="text-sm font-bold text-slate-900 border-b border-slate-100 pb-3">الملاحظات والقناة</h3>
                    <div class="text-xs space-y-2 text-slate-600">
                        <div class="flex justify-between">
                            <span class="text-slate-400">القناة:</span>
                            <span class="font-semibold text-slate-800">{{ $order->commerce_channel ?: 'المتجر / الشات' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">المصدر:</span>
                            <span class="font-semibold text-slate-800">{{ $order->source }}</span>
                        </div>
                        @if($order->notes)
                            <div class="pt-2">
                                <span class="text-slate-400 block mb-1">ملاحظات العميل:</span>
                                <div class="rounded-xl bg-slate-50 p-2.5 text-slate-700 leading-relaxed">
                                    {{ $order->notes }}
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
