<x-communication-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">إدارة العملاء ومتابعة الطلبات</h2>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">متابعة بيانات العملاء، الطلبات التلقائية من موظف الذكاء الاصطناعي، وطابور المراجعة.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('workspace.customers.create') }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>إضافة عميل جديد</span>
                </a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 space-y-6">
            @include('workspace.partials.nav')
            @include('partials.flash')

            <!-- 1. Quick Stats Cards (Clickable to Filter) -->
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
                <!-- Total Customers -->
                <a href="{{ route('workspace.customers.index', ['tab' => 'all']) }}" 
                   class="group relative overflow-hidden rounded-2xl border p-3.5 transition hover:shadow-md {{ $tab === 'all' && !request('status') ? 'bg-emerald-50/70 border-emerald-300 dark:bg-emerald-950/30 dark:border-emerald-700' : 'bg-white border-gray-200 dark:bg-gray-800 dark:border-gray-700' }}">
                    <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400">إجمالي العملاء</div>
                    <div class="text-xl font-bold text-gray-900 dark:text-gray-100 mt-1">{{ number_format($stats['total']) }}</div>
                    <div class="text-[10px] text-gray-400 mt-0.5">الكل</div>
                </a>

                <!-- New Customers -->
                <div class="rounded-2xl border border-gray-200 bg-white p-3.5 dark:bg-gray-800 dark:border-gray-700">
                    <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400">العملاء الجدد</div>
                    <div class="text-xl font-bold text-emerald-600 dark:text-emerald-400 mt-1">{{ number_format($stats['new']) }}</div>
                    <div class="text-[10px] text-gray-400 mt-0.5">خلال 30 يوم</div>
                </div>

                <!-- Needs Review (Highlight) -->
                <a href="{{ route('workspace.customers.index', ['tab' => 'needs_review']) }}" 
                   class="group relative overflow-hidden rounded-2xl border p-3.5 transition hover:shadow-md {{ $tab === 'needs_review' || request('status') === 'needs_review' ? 'bg-amber-50 border-amber-400 dark:bg-amber-950/40 dark:border-amber-600' : 'bg-amber-50/50 border-amber-200 dark:bg-amber-950/20 dark:border-amber-900/50' }}">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold text-amber-800 dark:text-amber-300">يحتاج مراجعة</span>
                        @if($stats['needs_review'] > 0 || $stats['review_orders_count'] > 0)
                            <span class="inline-flex h-2 w-2 rounded-full bg-amber-500 animate-pulse"></span>
                        @endif
                    </div>
                    <div class="text-xl font-bold text-amber-700 dark:text-amber-400 mt-1">{{ number_format($stats['needs_review']) }}</div>
                    <div class="text-[10px] text-amber-600/80 dark:text-amber-400 mt-0.5">{{ $stats['review_orders_count'] }} طلبات معلقة</div>
                </a>

                <!-- Completed -->
                <a href="{{ route('workspace.customers.index', ['status' => 'completed', 'tab' => 'all']) }}" 
                   class="group rounded-2xl border p-3.5 transition hover:shadow-md {{ request('status') === 'completed' ? 'bg-emerald-50 border-emerald-400 dark:bg-emerald-950/30' : 'bg-white border-gray-200 dark:bg-gray-800 dark:border-gray-700' }}">
                    <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400">مكتمل</div>
                    <div class="text-xl font-bold text-emerald-700 dark:text-emerald-400 mt-1">{{ number_format($stats['completed']) }}</div>
                    <div class="text-[10px] text-gray-400 mt-0.5">جاهز</div>
                </a>

                <!-- Awaiting Payment -->
                <a href="{{ route('workspace.customers.index', ['status' => 'awaiting_payment', 'tab' => 'all']) }}" 
                   class="group rounded-2xl border p-3.5 transition hover:shadow-md {{ request('status') === 'awaiting_payment' ? 'bg-blue-50 border-blue-400 dark:bg-blue-950/30' : 'bg-white border-gray-200 dark:bg-gray-800 dark:border-gray-700' }}">
                    <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400">بانتظار الدفع</div>
                    <div class="text-xl font-bold text-blue-700 dark:text-blue-400 mt-1">{{ number_format($stats['awaiting_payment']) }}</div>
                    <div class="text-[10px] text-gray-400 mt-0.5">غير مسدد</div>
                </a>

                <!-- In Progress -->
                <a href="{{ route('workspace.customers.index', ['status' => 'in_progress', 'tab' => 'all']) }}" 
                   class="group rounded-2xl border p-3.5 transition hover:shadow-md {{ request('status') === 'in_progress' ? 'bg-indigo-50 border-indigo-400 dark:bg-indigo-950/30' : 'bg-white border-gray-200 dark:bg-gray-800 dark:border-gray-700' }}">
                    <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400">قيد التنفيذ</div>
                    <div class="text-xl font-bold text-indigo-700 dark:text-indigo-400 mt-1">{{ number_format($stats['in_progress']) }}</div>
                    <div class="text-[10px] text-gray-400 mt-0.5">جاري المعالجة</div>
                </a>

                <!-- Delivered -->
                <a href="{{ route('workspace.customers.index', ['status' => 'delivered', 'tab' => 'all']) }}" 
                   class="group rounded-2xl border p-3.5 transition hover:shadow-md {{ request('status') === 'delivered' ? 'bg-teal-50 border-teal-400 dark:bg-teal-950/30' : 'bg-white border-gray-200 dark:bg-gray-800 dark:border-gray-700' }}">
                    <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400">تم التسليم</div>
                    <div class="text-xl font-bold text-teal-700 dark:text-teal-400 mt-1">{{ number_format($stats['delivered']) }}</div>
                    <div class="text-[10px] text-gray-400 mt-0.5">مكتمل النهائي</div>
                </a>

                <!-- Cancelled -->
                <a href="{{ route('workspace.customers.index', ['status' => 'cancelled', 'tab' => 'all']) }}" 
                   class="group rounded-2xl border p-3.5 transition hover:shadow-md {{ request('status') === 'cancelled' ? 'bg-rose-50 border-rose-400 dark:bg-rose-950/30' : 'bg-white border-gray-200 dark:bg-gray-800 dark:border-gray-700' }}">
                    <div class="text-[11px] font-medium text-gray-500 dark:text-gray-400">ملغي</div>
                    <div class="text-xl font-bold text-rose-700 dark:text-rose-400 mt-1">{{ number_format($stats['cancelled']) }}</div>
                    <div class="text-[10px] text-gray-400 mt-0.5">ملغي/مرفوض</div>
                </a>
            </div>

            <!-- 2. Primary Tabs (All Customers vs Needs Review) -->
            <div class="border-b border-gray-200 dark:border-gray-700">
                <nav class="-mb-px flex space-x-reverse space-x-6">
                    <a href="{{ route('workspace.customers.index', array_merge(request()->query(), ['tab' => 'all'])) }}"
                       class="whitespace-nowrap pb-3 px-1 border-b-2 font-semibold text-sm transition {{ $tab === 'all' ? 'border-emerald-600 text-emerald-600 dark:text-emerald-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400' }}">
                        <span class="inline-flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            <span>جميع العملاء</span>
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ number_format($stats['total']) }}</span>
                        </span>
                    </a>

                    <a href="{{ route('workspace.customers.index', ['tab' => 'needs_review']) }}"
                       class="whitespace-nowrap pb-3 px-1 border-b-2 font-semibold text-sm transition {{ $tab === 'needs_review' ? 'border-amber-600 text-amber-600 dark:text-amber-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400' }}">
                        <span class="inline-flex items-center gap-2">
                            <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            <span>يحتاج إلى مراجعة</span>
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">
                                {{ $stats['needs_review'] + $stats['review_orders_count'] }}
                            </span>
                        </span>
                    </a>
                </nav>
            </div>

            @if($tab === 'needs_review')
                <!-- 3. NEEDS REVIEW QUEUE SECTION -->
                <div class="space-y-4">
                    <div class="bg-amber-50/70 border border-amber-200 rounded-2xl p-4 text-sm text-amber-900 dark:bg-amber-950/20 dark:border-amber-800 dark:text-amber-300 flex items-start gap-3">
                        <svg class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <div>
                            <span class="font-semibold">طابور التدخل والمراجعة البشرية:</span>
                            الطلبات والعملاء المسجلون تلقائياً بواسطة موظف الذكاء الاصطناعي مع معلومات ناقصة، أو الذين طلبوا إجراءات تتطلب موافقة الإدارة. يمكنك مراجعة المحادثة واعتماد الطلب أو رفضه فوراً.
                        </div>
                    </div>

                    <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm dark:bg-gray-800 dark:border-gray-700">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50/75 dark:bg-gray-900/50 text-gray-700 dark:text-gray-300 font-semibold text-xs">
                                <tr>
                                    <th class="px-4 py-3.5 text-right">العميل ورقم الجوال</th>
                                    <th class="px-4 py-3.5 text-right">رقم الطلب والملخص</th>
                                    <th class="px-4 py-3.5 text-right">سبب المراجعة</th>
                                    <th class="px-4 py-3.5 text-right">آخر رسالة من العميل</th>
                                    <th class="px-4 py-3.5 text-right">تاريخ الطلب</th>
                                    <th class="px-4 py-3.5 text-center">الإجراءات السريعة</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                                @forelse($reviewOrders as $order)
                                    @php
                                        $orderCustomer = $order->customer;
                                        $latestMsg = $order->conversation?->messages?->first();
                                    @endphp
                                    <tr class="hover:bg-amber-50/30 dark:hover:bg-amber-950/10 transition">
                                        <!-- Customer -->
                                        <td class="px-4 py-3.5 whitespace-nowrap">
                                            @if($orderCustomer)
                                                <div class="font-semibold text-gray-900 dark:text-gray-100">
                                                    <a href="{{ route('workspace.customers.show', $orderCustomer) }}" class="hover:underline text-emerald-700 dark:text-emerald-400">
                                                        {{ $orderCustomer->name }}
                                                    </a>
                                                </div>
                                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 dir-ltr text-right">
                                                    {{ $orderCustomer->phone }}
                                                </div>
                                            @else
                                                <span class="text-gray-400">بدون ملف عميل</span>
                                            @endif
                                        </td>

                                        <!-- Order Summary -->
                                        <td class="px-4 py-3.5">
                                            <div class="font-mono text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $order->order_number }}</div>
                                            <div class="text-xs text-emerald-600 font-bold mt-0.5">{{ number_format((float)$order->total_amount, 2) }} {{ $order->currency }}</div>
                                            <div class="text-[11px] text-gray-500 mt-0.5">
                                                @foreach($order->items->take(2) as $item)
                                                    <span class="inline-block bg-gray-100 dark:bg-gray-700 rounded px-1.5 py-0.2 mr-1">{{ $item->product_name }} ({{ $item->quantity }})</span>
                                                @endforeach
                                                @if($order->items->count() > 2)
                                                    <span class="text-gray-400">+{{ $order->items->count() - 2 }}</span>
                                                @endif
                                            </div>
                                        </td>

                                        <!-- Review Reason -->
                                        <td class="px-4 py-3.5 max-w-xs">
                                            <div class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-100/70 px-2.5 py-1 text-xs font-medium text-amber-900 dark:bg-amber-950/50 dark:border-amber-700 dark:text-amber-200">
                                                <svg class="w-3.5 h-3.5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                                <span>{{ $order->review_reason ?: 'يحتاج مراجعة بيانات' }}</span>
                                            </div>
                                            @if($order->review_required_action)
                                                <div class="text-[11px] text-gray-600 dark:text-gray-400 mt-1 font-medium">
                                                    الإجراء: {{ $order->review_required_action }}
                                                </div>
                                            @endif
                                        </td>

                                        <!-- Last Message -->
                                        <td class="px-4 py-3.5 max-w-xs text-xs text-gray-600 dark:text-gray-300">
                                            @if($latestMsg)
                                                <p class="truncate" title="{{ $latestMsg->content }}">{{ $latestMsg->content }}</p>
                                                <span class="text-[10px] text-gray-400">{{ $latestMsg->created_at?->diffForHumans() }}</span>
                                            @else
                                                <span class="text-gray-400">-</span>
                                            @endif
                                        </td>

                                        <!-- Placed At -->
                                        <td class="px-4 py-3.5 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                            {{ $order->placed_at?->format('Y-m-d H:i') ?? $order->created_at->format('Y-m-d H:i') }}
                                        </td>

                                        <!-- Quick Actions -->
                                        <td class="px-4 py-3.5 whitespace-nowrap text-center">
                                            <div class="flex items-center justify-center gap-1.5">
                                                <!-- Review Chat -->
                                                @if($order->conversation_id)
                                                    <a href="{{ route('workspace.conversations.show', $order->conversation_id) }}" 
                                                       title="مراجعة المحادثة" 
                                                       class="rounded-lg border border-gray-300 p-1.5 text-gray-700 hover:bg-gray-100 hover:text-emerald-700 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700 transition">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                                    </a>
                                                @endif

                                                <!-- Open Profile -->
                                                @if($orderCustomer)
                                                    <a href="{{ route('workspace.customers.show', $orderCustomer) }}" 
                                                       title="فتح ملف العميل"
                                                       class="rounded-lg border border-gray-300 p-1.5 text-gray-700 hover:bg-gray-100 hover:text-emerald-700 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700 transition">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                                    </a>
                                                @endif

                                                <!-- Approve -->
                                                <form method="POST" action="{{ route('workspace.customers.orders.review-action', $order) }}" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="action" value="approve">
                                                    <button type="submit" 
                                                            title="اعتماد الطلب"
                                                            onclick="return confirm('هل تريد بالتأكيد اعتماد هذا الطلب؟')"
                                                            class="rounded-lg bg-emerald-600 text-white p-1.5 hover:bg-emerald-700 transition">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                    </button>
                                                </form>

                                                <!-- Move to in_progress -->
                                                <form method="POST" action="{{ route('workspace.customers.orders.review-action', $order) }}" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="action" value="in_progress">
                                                    <button type="submit" 
                                                            title="تحويل إلى قيد التنفيذ"
                                                            class="rounded-lg bg-indigo-600 text-white p-1.5 hover:bg-indigo-700 transition">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                                    </button>
                                                </form>

                                                <!-- Reject -->
                                                <form method="POST" action="{{ route('workspace.customers.orders.review-action', $order) }}" class="inline">
                                                    @csrf
                                                    <input type="hidden" name="action" value="reject">
                                                    <button type="submit" 
                                                            title="رفض الطلب"
                                                            onclick="return confirm('هل تريد بالتأكيد رفض هذا الطلب؟')"
                                                            class="rounded-lg bg-rose-600 text-white p-1.5 hover:bg-rose-700 transition">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-12 text-center text-gray-500 dark:text-gray-400">
                                            <div class="flex flex-col items-center justify-center">
                                                <svg class="w-10 h-10 text-emerald-500 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                <p class="font-semibold text-gray-800 dark:text-gray-200">لا توجد طلبات معلقة للمراجعة حالياً!</p>
                                                <p class="text-xs text-gray-400 mt-1">جميع طلبات العملاء مكتملة وتمت معالجتها بنجاح.</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($reviewOrders->hasPages())
                        <div class="mt-4">{{ $reviewOrders->links() }}</div>
                    @endif
                </div>

            @else
                <!-- 4. ALL CUSTOMERS TAB SECTION -->
                <div class="space-y-4">
                    <!-- Search & Filter Bar -->
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:bg-gray-800 dark:border-gray-700">
                        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-6 gap-3 items-end">
                            <input type="hidden" name="tab" value="all" />

                            <!-- Search -->
                            <div class="md:col-span-2">
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">بحث سريع</label>
                                <div class="relative">
                                    <input name="search" value="{{ request('search') }}" 
                                           class="w-full rounded-xl border-gray-300 pl-3 pr-9 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100" 
                                           placeholder="اسم العميل، الجوال، رقم الطلب..." />
                                    <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none text-gray-400">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                    </div>
                                </div>
                            </div>

                            <!-- Customer Status Filter -->
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">حالة العميل</label>
                                <select name="status" class="w-full rounded-xl border-gray-300 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100">
                                    <option value="">جميع الحالات</option>
                                    @foreach(\App\Enums\CustomerStatus::cases() as $st)
                                        <option value="{{ $st->value }}" {{ request('status') === $st->value ? 'selected' : '' }}>
                                            {{ $st->label() }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <!-- Date Range (From) -->
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">من تاريخ</label>
                                <input type="date" name="date_from" value="{{ request('date_from') }}" 
                                       class="w-full rounded-xl border-gray-300 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100" />
                            </div>

                            <!-- Date Range (To) -->
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">إلى تاريخ</label>
                                <input type="date" name="date_to" value="{{ request('date_to') }}" 
                                       class="w-full rounded-xl border-gray-300 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100" />
                            </div>

                            <!-- Sort & Action Buttons -->
                            <div class="flex items-center gap-2">
                                <select name="sort" class="w-full rounded-xl border-gray-300 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-100">
                                    <option value="newest" {{ request('sort') === 'newest' ? 'selected' : '' }}>الأحدث</option>
                                    <option value="most_orders" {{ request('sort') === 'most_orders' ? 'selected' : '' }}>الأكثر طلباً</option>
                                    <option value="highest_spend" {{ request('sort') === 'highest_spend' ? 'selected' : '' }}>الأعلى إنفاقاً</option>
                                </select>
                                <button type="submit" class="rounded-xl bg-gray-900 hover:bg-gray-800 px-3.5 py-2 text-sm font-medium text-white transition dark:bg-gray-100 dark:text-gray-900">
                                    تطبيق
                                </button>
                                @if(request()->anyFilled(['search', 'status', 'order_status', 'date_from', 'date_to', 'sort']))
                                    <a href="{{ route('workspace.customers.index', ['tab' => 'all']) }}" class="text-xs text-gray-500 hover:text-gray-700 p-2" title="إلغاء الفلاتر">✕</a>
                                @endif
                            </div>
                        </form>
                    </div>

                    <!-- Customers Table -->
                    <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm dark:bg-gray-800 dark:border-gray-700">
                        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-gray-50/75 dark:bg-gray-900/50 text-gray-700 dark:text-gray-300 font-semibold text-xs">
                                <tr>
                                    <th class="px-4 py-3.5 text-right">اسم العميل</th>
                                    <th class="px-4 py-3.5 text-right">رقم الجوال</th>
                                    <th class="px-4 py-3.5 text-right">آخر طلب</th>
                                    <th class="px-4 py-3.5 text-center">عدد الطلبات</th>
                                    <th class="px-4 py-3.5 text-right">إجمالي المشتريات</th>
                                    <th class="px-4 py-3.5 text-right">تاريخ آخر تواصل</th>
                                    <th class="px-4 py-3.5 text-center">حالة العميل</th>
                                    <th class="px-4 py-3.5 text-left">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                                @forelse($customers as $customer)
                                    @php
                                        $statusEnum = $customer->statusEnum();
                                    @endphp
                                    <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/30 transition">
                                        <!-- Customer Name -->
                                        <td class="px-4 py-3.5 whitespace-nowrap">
                                            <div class="flex items-center gap-2">
                                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-100 text-emerald-800 font-bold text-xs dark:bg-emerald-950/60 dark:text-emerald-300">
                                                    {{ mb_substr($customer->name, 0, 1) }}
                                                </div>
                                                <div>
                                                    <a href="{{ route('workspace.customers.show', $customer) }}" class="font-semibold text-gray-900 dark:text-gray-100 hover:text-emerald-600 hover:underline">
                                                        {{ $customer->name }}
                                                    </a>
                                                    <div class="text-[11px] text-gray-400">
                                                        {{ $customer->partyType() === 'company' ? 'شركة' : 'فرد' }}
                                                        @if($customer->city) • {{ $customer->city }} @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <!-- Phone & WhatsApp -->
                                        <td class="px-4 py-3.5 whitespace-nowrap text-xs">
                                            <div class="flex items-center gap-1.5 dir-ltr justify-end">
                                                <span class="font-mono text-gray-800 dark:text-gray-200">{{ $customer->phone }}</span>
                                                <a href="https://wa.me/{{ preg_replace('/[^\d]/', '', $customer->phone) }}" target="_blank" rel="noopener" class="text-emerald-600 hover:text-emerald-700 p-1" title="محادثة واتساب">
                                                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981z"/></svg>
                                                </a>
                                            </div>
                                        </td>

                                        <!-- Last Order -->
                                        <td class="px-4 py-3.5 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                            @if($customer->last_order_at)
                                                <div>{{ $customer->last_order_at->format('Y-m-d') }}</div>
                                                <div class="text-[10px] text-gray-400">{{ $customer->last_order_at->diffForHumans() }}</div>
                                            @else
                                                <span class="text-gray-400">لا يوجد</span>
                                            @endif
                                        </td>

                                        <!-- Orders Count -->
                                        <td class="px-4 py-3.5 whitespace-nowrap text-center">
                                            <span class="inline-flex items-center justify-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                                {{ $customer->orders_count }}
                                            </span>
                                        </td>

                                        <!-- Total Purchases -->
                                        <td class="px-4 py-3.5 whitespace-nowrap text-xs font-bold text-gray-900 dark:text-gray-100">
                                            {{ number_format((float)$customer->total_purchases, 2) }} <span class="text-[10px] text-gray-400 font-normal">ر.س</span>
                                        </td>

                                        <!-- Last Conversation -->
                                        <td class="px-4 py-3.5 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                            {{ $customer->last_conversation_at?->diffForHumans() ?? '-' }}
                                        </td>

                                        <!-- Customer Status Badge -->
                                        <td class="px-4 py-3.5 whitespace-nowrap text-center">
                                            <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium {{ $statusEnum->badgeClass() }}">
                                                <span class="h-1.5 w-1.5 rounded-full {{ $statusEnum->dotClass() }}"></span>
                                                <span>{{ $statusEnum->label() }}</span>
                                            </span>
                                        </td>

                                        <!-- Actions -->
                                        <td class="px-4 py-3.5 whitespace-nowrap text-left text-xs">
                                            <div class="flex items-center justify-end gap-2">
                                                <a href="{{ route('workspace.customers.show', $customer) }}" 
                                                   class="font-medium text-emerald-600 hover:text-emerald-700">عرض</a>
                                                <a href="{{ route('workspace.customers.edit', $customer) }}" 
                                                   class="font-medium text-blue-600 hover:text-blue-700">تعديل</a>
                                                <form method="POST" action="{{ route('workspace.customers.destroy', $customer) }}" class="inline">
                                                    @csrf @method('DELETE')
                                                    <button class="font-medium text-rose-600 hover:text-rose-700" onclick="return confirm('هل تريد بالتأكيد حذف هذا العميل؟')">حذف</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                            لا يوجد عملاء يطابقون شروط البحث الحالية.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if($customers->hasPages())
                        <div class="mt-4">{{ $customers->links() }}</div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-communication-layout>
