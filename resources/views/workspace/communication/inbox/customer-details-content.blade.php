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

<div class="min-h-0 flex-1 overflow-y-auto">
    @if($customer)
        <div class="border-b border-slate-100 px-4 py-4 text-center">
            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-[#E8FAF6] text-lg font-bold text-[#067e6b]">
                {{ $initial }}
            </span>
            <h3 class="mt-3 text-sm font-bold text-slate-900">{{ $customer->name }}</h3>
            <dl class="mt-3 space-y-1.5 text-xs text-slate-500">
                @if($customer->phone)
                    <div>الهاتف: <span class="font-semibold text-slate-700">{{ $customer->phone }}</span></div>
                @endif
                @if($customer->email)
                    <div>البريد: <span class="font-semibold text-slate-700">{{ $customer->email }}</span></div>
                @endif
                @if($customer->city)
                    <div>المدينة: <span class="font-semibold text-slate-700">{{ $customer->city }}</span></div>
                @endif
                @if($activeConversation->external_id)
                    <div>External ID: <span class="font-semibold text-slate-700">#{{ $activeConversation->external_id }}</span></div>
                @endif
                @if($customer->created_at)
                    <div>تاريخ الإنشاء: <span class="font-semibold text-slate-700">{{ $customer->created_at->format('Y-m-d') }}</span></div>
                @endif
            </dl>
        </div>

        <div class="flex flex-wrap gap-2 border-b border-slate-100 px-4 py-3">
            @if($whatsappNumber)
                <a href="https://wa.me/{{ $whatsappNumber }}" target="_blank" rel="noopener" class="hs-btn-secondary px-3 py-1.5 text-xs">واتساب</a>
            @endif
            @if($customer->phone)
                <a href="tel:{{ $customer->phone }}" class="hs-btn-secondary px-3 py-1.5 text-xs">مكالمة</a>
            @endif
            @if(($inboxAllowsCompanyActions ?? true) && \Illuminate\Support\Facades\Route::has('workspace.customers.edit'))
                <a href="{{ route('workspace.customers.edit', $customer) }}" class="hs-btn-secondary px-3 py-1.5 text-xs">المزيد</a>
            @endif
        </div>

        <details open class="border-b border-slate-100 px-4 py-3">
            <summary class="cursor-pointer text-xs font-bold text-slate-800">ملاحظات</summary>
            <div class="mt-3 space-y-2">
                @forelse($customerNotes as $note)
                    <p class="rounded-xl bg-slate-50 px-3 py-2 text-xs leading-5 text-slate-600">{{ $note->body }}</p>
                @empty
                    <p class="text-xs text-slate-400">لا توجد ملاحظات.</p>
                @endforelse
                @if(($inboxAllowsCompanyActions ?? true) && \Illuminate\Support\Facades\Route::has('workspace.customers.notes.store'))
                    <form method="POST" action="{{ route('workspace.customers.notes.store', $customer) }}" class="space-y-2">
                        @csrf
                        <textarea name="body" rows="2" required class="hs-input" placeholder="أضف ملاحظة..."></textarea>
                        <button class="hs-btn-secondary w-full py-1.5 text-xs">حفظ الملاحظة</button>
                    </form>
                @endif
            </div>
        </details>

        <details class="border-b border-slate-100 px-4 py-3">
            <summary class="cursor-pointer text-xs font-bold text-slate-800">الوسوم</summary>
            <div class="mt-3 flex flex-wrap gap-1.5">
                @forelse($customerTags as $tag)
                    <span class="rounded-full bg-slate-100 px-2 py-1 text-[11px] font-semibold text-slate-600">{{ $tag->name }}</span>
                @empty
                    <p class="text-xs text-slate-400">لا توجد وسوم.</p>
                @endforelse
            </div>
        </details>

        <details class="border-b border-slate-100 px-4 py-3">
            <summary class="cursor-pointer text-xs font-bold text-slate-800">الطلبات</summary>
            <div class="mt-3 space-y-2">
                @forelse($customerOrders as $order)
                    <div class="rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        <p class="font-semibold text-slate-800">{{ $order->order_number ?: 'طلب #'.$order->id }}</p>
                        <p>{{ $order->status }} @if($order->placed_at) · {{ $order->placed_at->format('Y-m-d') }} @endif</p>
                    </div>
                @empty
                    <p class="text-xs text-slate-400">لا توجد طلبات.</p>
                @endforelse
            </div>
        </details>

        <details class="px-4 py-3">
            <summary class="cursor-pointer text-xs font-bold text-slate-800">النشاطات السابقة</summary>
            <div class="mt-3 space-y-1.5 text-xs text-slate-500">
                @if($customer->last_conversation_at)
                    <p>آخر محادثة: <span class="font-semibold text-slate-700">{{ $customer->last_conversation_at->diffForHumans() }}</span></p>
                @endif
                @if($customer->last_order_at)
                    <p>آخر طلب: <span class="font-semibold text-slate-700">{{ $customer->last_order_at->diffForHumans() }}</span></p>
                @endif
                @if(! $customer->last_conversation_at && ! $customer->last_order_at)
                    <p class="text-slate-400">لا يوجد نشاط مسجّل.</p>
                @endif
            </div>
        </details>
    @else
        <div class="p-6">
            <x-empty-state title="لا يوجد عميل مرتبط" text="هذه المحادثة غير مربوطة بعميل حتى الآن." />
        </div>
    @endif
</div>
