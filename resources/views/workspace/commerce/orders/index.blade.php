<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-slate-900">إدارة الطلبات والمبيعات</h1>
                <p class="mt-0.5 text-xs text-slate-500">متابعة طلبات العملاء المنشأة عبر الذكاء الاصطناعي والمحادثات والمتجر.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('workspace.chat-catalog.index') }}" class="hs-btn-secondary text-xs">
                    كتالوج المنتجات
                </a>
                <a href="{{ route('workspace.conversations.index') }}" class="hs-btn-primary text-xs">
                    فتح المحادثات
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6" dir="rtl">
        @include('partials.flash')

        <!-- KPI Stats Cards -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <!-- Total Orders -->
            <a href="{{ route('workspace.commerce.orders.index') }}" @class([
                'hs-stat-card p-4 transition',
                'border-[#06C2A4] bg-[#E8FAF6]/40' => empty($status),
            ])>
                <span class="text-xs font-medium text-slate-500">إجمالي الطلبات</span>
                <p class="mt-1 text-2xl font-extrabold text-slate-900">{{ number_format($stats['total']) }}</p>
                <span class="text-[10px] text-slate-400 mt-1 block">كافة الطلبات</span>
            </a>

            <!-- Pending Payment -->
            <a href="{{ route('workspace.commerce.orders.index', ['status' => 'pending_payment']) }}" @class([
                'hs-stat-card p-4 transition',
                'border-blue-400 bg-blue-50/40' => $status === 'pending_payment',
            ])>
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-500">بانتظار الدفع</span>
                    <span class="h-2 w-2 rounded-full bg-blue-500"></span>
                </div>
                <p class="mt-1 text-2xl font-extrabold text-blue-600">{{ number_format($stats['pending_payment']) }}</p>
                <span class="text-[10px] text-slate-400 mt-1 block">رابط دفع معلق</span>
            </a>

            <!-- Completed -->
            <a href="{{ route('workspace.commerce.orders.index', ['status' => 'completed']) }}" @class([
                'hs-stat-card p-4 transition',
                'border-emerald-400 bg-emerald-50/40' => $status === 'completed',
            ])>
                <div class="flex items-center justify-between">
                    <span class="text-xs font-medium text-slate-500">مكتمل</span>
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                </div>
                <p class="mt-1 text-2xl font-extrabold text-emerald-600">{{ number_format($stats['completed']) }}</p>
                <span class="text-[10px] text-slate-400 mt-1 block">تم السداد والتأكيد</span>
            </a>

            <!-- Needs Review -->
            <a href="{{ route('workspace.commerce.orders.index', ['status' => 'needs_review']) }}" @class([
                'hs-stat-card p-4 transition',
                'border-amber-400 bg-amber-50/40' => $status === 'needs_review',
            ])>
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-amber-800">يحتاج مراجعة</span>
                    @if($stats['needs_review'] > 0)
                        <span class="h-2 w-2 rounded-full bg-amber-500 animate-pulse"></span>
                    @endif
                </div>
                <p class="mt-1 text-2xl font-extrabold text-amber-700">{{ number_format($stats['needs_review']) }}</p>
                <span class="text-[10px] text-amber-600 mt-1 block">تتطلب تدخلاً</span>
            </a>

            <!-- Total Sales -->
            <div class="hs-stat-card p-4">
                <span class="text-xs font-medium text-slate-500">إجمالي المبيعات</span>
                <p class="mt-1 text-2xl font-extrabold text-slate-900">{{ number_format($stats['total_amount'], 2) }}</p>
                <span class="text-[10px] text-slate-400 mt-1 block">ريال سعودي</span>
            </div>
        </div>

        <!-- Search & Filter Controls -->
        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm">
            <form method="GET" action="{{ route('workspace.commerce.orders.index') }}" class="flex flex-col sm:flex-row items-center gap-3">
                <div class="relative flex-1 w-full">
                    <svg class="pointer-events-none absolute start-3.5 top-3 h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input 
                        type="text" 
                        name="search" 
                        value="{{ $search }}" 
                        placeholder="ابحث برقم الطلب، اسم العميل، أو الهاتف..." 
                        class="hs-input ps-10 text-xs"
                    >
                </div>

                <div class="w-full sm:w-48">
                    <select name="status" class="hs-select text-xs">
                        <option value="">كافة الحالات</option>
                        <option value="needs_review" @selected($status === 'needs_review')>يحتاج مراجعة</option>
                        <option value="pending_payment" @selected($status === 'pending_payment')>بانتظار الدفع</option>
                        <option value="paid" @selected($status === 'paid')>تم الدفع</option>
                        <option value="completed" @selected($status === 'completed')>مكتمل التسليم</option>
                        <option value="cancelled" @selected($status === 'cancelled')>ملغي</option>
                    </select>
                </div>

                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <button type="submit" class="hs-btn-primary text-xs py-2 px-4 w-full sm:w-auto">
                        تصفية
                    </button>
                    @if($status || $search)
                        <a href="{{ route('workspace.commerce.orders.index') }}" class="hs-btn-secondary text-xs py-2 px-3">
                            إلغاء
                        </a>
                    @endif
                </div>
            </form>
        </div>

        <!-- Orders Table -->
        <div class="hs-table-wrap">
            <table class="hs-table">
                <thead>
                    <tr>
                        <th>رقم الطلب</th>
                        <th>العميل</th>
                        <th>المنتجات والكمية</th>
                        <th>المبلغ الإجمالي</th>
                        <th>طريقة الدفع</th>
                        <th>تاريخ الطلب</th>
                        <th>حالة الطلب</th>
                        <th class="text-end">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($orders as $order)
                        <tr>
                            <!-- Order Number -->
                            <td class="font-bold text-slate-900">
                                <a href="{{ route('workspace.commerce.orders.show', $order) }}" class="hover:text-[#067E6B] font-mono">
                                    #{{ $order->order_number }}
                                </a>
                            </td>

                            <!-- Customer -->
                            <td>
                                @if($order->customer)
                                    <a href="{{ route('workspace.customers.show', $order->customer) }}" class="font-semibold text-slate-800 hover:text-[#067E6B] block">
                                        {{ $order->customer->name }}
                                    </a>
                                    <span class="text-[11px] text-slate-400 font-mono">{{ $order->customer->phone }}</span>
                                @else
                                    <span class="text-slate-400 text-xs">عميل ضيف</span>
                                @endif
                            </td>

                            <!-- Items -->
                            <td>
                                <div class="max-w-[200px] truncate text-xs text-slate-800 font-medium">
                                    @php
                                        $itemNames = $order->items->pluck('product_name')->filter()->join(', ');
                                    @endphp
                                    {{ $itemNames ?: ($order->items_count . ' منتجات') }}
                                </div>
                                <span class="text-[11px] text-slate-400">{{ $order->items_count }} عنصر</span>
                            </td>

                            <!-- Total Amount -->
                            <td class="font-bold text-slate-900">
                                {{ number_format((float) $order->total_amount, 2) }}
                                <span class="text-[10px] text-slate-400 font-normal">{{ $order->currency }}</span>
                            </td>

                            <!-- Payment Method / Status -->
                            <td>
                                @if($order->payment_status === 'paid')
                                    <span class="hs-badge-success text-[11px]">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                        تم الدفع
                                    </span>
                                @elseif($order->payment_status === 'pending')
                                    <span class="hs-badge-warning text-[11px]">
                                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                        بانتظار الدفع
                                    </span>
                                @else
                                    <span class="hs-badge-neutral text-[11px]">
                                        {{ $order->payment_status ?: 'غير محدد' }}
                                    </span>
                                @endif
                            </td>

                            <!-- Date -->
                            <td class="text-xs text-slate-500">
                                {{ $order->created_at?->format('Y-m-d') }}
                                <span class="block text-[10px] text-slate-400">{{ $order->created_at?->format('h:i A') }}</span>
                            </td>

                            <!-- Order Status -->
                            <td>
                                @if($order->status === 'draft' || $order->review_reason)
                                    <span class="hs-badge-warning text-[11px]">
                                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                        يحتاج مراجعة
                                    </span>
                                @elseif($order->status === 'completed')
                                    <span class="hs-badge-success text-[11px]">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                        مكتمل التسليم
                                    </span>
                                @elseif($order->status === 'cancelled' || $order->commerce_status?->value === 'cancelled')
                                    <span class="hs-badge-danger text-[11px]">
                                        <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                                        ملغي
                                    </span>
                                @else
                                    <span class="hs-badge-info text-[11px]">
                                        <span class="h-1.5 w-1.5 rounded-full bg-blue-500"></span>
                                        {{ $order->status ?: 'قيد المعالجة' }}
                                    </span>
                                @endif
                            </td>

                            <!-- Actions -->
                            <td class="text-end">
                                <a href="{{ route('workspace.commerce.orders.show', $order) }}" class="hs-btn-secondary text-xs py-1.5 px-3">
                                    تفاصيل الطلب
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center">
                                <div class="hs-empty">
                                    <div class="hs-empty-icon">
                                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 11V7a4 4 0 0 0-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                                    </div>
                                    <h4 class="hs-empty-title">لا توجد طلبات تطابق بحثك</h4>
                                    <p class="hs-empty-text">الطلبات المنشأة بواسطة المحادثات أو الكتالوج ستظهر هنا فور تسجيلها.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $orders->links() }}
        </div>
    </div>
</x-app-layout>
