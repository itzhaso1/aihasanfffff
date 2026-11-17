<div
    id="inbox-customer-details"
    x-cloak
    x-show="detailsOpen"
    class="fixed inset-0 z-50"
    role="dialog"
    aria-modal="true"
    aria-labelledby="inbox-customer-details-title"
>
    <button @click="detailsOpen = false" type="button" class="absolute inset-0 bg-slate-900/35 backdrop-blur-xs transition-opacity" aria-label="إغلاق تفاصيل العميل"></button>
    <aside
        x-show="detailsOpen"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-x-4"
        x-transition:enter-end="opacity-100 translate-x-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-x-0"
        x-transition:leave-end="opacity-0 translate-x-4"
        class="absolute inset-y-0 end-0 flex w-88 max-w-[90vw] flex-col border-s border-slate-200/90 bg-white shadow-2xl"
    >
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3.5">
            <div class="flex items-center gap-2">
                <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-[#E8FAF6] text-[#067e6b]">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </div>
                <h3 id="inbox-customer-details-title" class="text-sm font-bold text-slate-900">تفاصيل العميل</h3>
            </div>
            <button @click="detailsOpen = false" type="button" class="flex h-8 w-8 items-center justify-center rounded-xl text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" aria-label="إغلاق">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        @include('workspace.communication.inbox.customer-details-content')
    </aside>
</div>
