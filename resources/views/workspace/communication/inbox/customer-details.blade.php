<div
    id="inbox-customer-details"
    x-cloak
    x-show="detailsOpen"
    class="fixed inset-0 z-50"
    role="dialog"
    aria-modal="true"
    aria-labelledby="inbox-customer-details-title"
>
    <button @click="detailsOpen = false" type="button" class="absolute inset-0 bg-slate-900/40" aria-label="إغلاق تفاصيل العميل"></button>
    <aside x-transition class="absolute inset-y-0 end-0 flex w-80 max-w-[90vw] flex-col border-s border-slate-200 bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
            <h3 id="inbox-customer-details-title" class="text-sm font-bold text-slate-900">تفاصيل العميل</h3>
            <button @click="detailsOpen = false" type="button" class="rounded-md p-2 text-slate-500 hover:bg-slate-100" aria-label="إغلاق">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        @include('workspace.communication.inbox.customer-details-content')
    </aside>
</div>
