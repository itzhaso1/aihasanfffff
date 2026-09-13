@php
    $customer = $activeConversation->customer;
    $whatsappNumber = preg_replace('/\D+/', '', (string) ($customer?->whatsapp ?: $customer?->phone));
@endphp

<aside class="hidden h-full min-h-0 w-[20rem] shrink-0 flex-col border-s border-slate-200 bg-white xl:flex">
    @include('workspace.communication.inbox.customer-details-content')
</aside>

<div x-cloak x-show="detailsOpen" class="fixed inset-0 z-50 xl:hidden">
    <button @click="detailsOpen = false" type="button" class="absolute inset-0 bg-slate-900/40"></button>
    <aside x-transition class="absolute inset-y-0 left-0 flex w-80 max-w-[90vw] flex-col border-r border-slate-200 bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <h3 class="text-sm font-bold text-slate-900">تفاصيل العميل</h3>
            <button @click="detailsOpen = false" type="button" class="rounded-md p-2 text-slate-500 hover:bg-slate-100">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        @include('workspace.communication.inbox.customer-details-content')
    </aside>
</div>
