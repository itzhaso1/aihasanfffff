<section class="hidden h-full min-h-0 flex-1 flex-col bg-gradient-to-b from-[#F7FCFB] via-[#FAFCFB] to-[#F1F9F7] md:flex">
    <div class="flex h-full items-center justify-center p-8">
        <div class="relative max-w-md rounded-3xl border border-slate-200/80 bg-white/90 p-8 text-center shadow-xs backdrop-blur-sm">
            <div class="relative mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-3xl bg-gradient-to-br from-[#E8FAF6] to-[#C9F0E8] text-[#067e6b] shadow-xs ring-8 ring-[#E8FAF6]/60">
                <svg class="h-9 w-9" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7.5 17.25H4.875A1.875 1.875 0 0 1 3 15.375V5.625A1.875 1.875 0 0 1 4.875 3.75h14.25A1.875 1.875 0 0 1 21 5.625v9.75a1.875 1.875 0 0 1-1.875 1.875H12l-4.5 3v-3Z" />
                </svg>
            </div>
            <h3 class="text-lg font-bold text-slate-900">ابدأ بإدارة محادثاتك</h3>
            <p class="mt-2 text-xs leading-6 text-slate-500">اختر محادثة من القائمة لعرض الرسائل والتفاصيل، أو أنشئ محادثة جديدة للبدء بالتواصل المباشر مع عملائك.</p>
            @if($inboxAllowsCompanyActions ?? true)
                <div class="mt-6">
                    <a href="{{ route('workspace.conversations.create') }}" class="hs-btn-primary inline-flex items-center gap-1.5 px-5 py-2.5 text-xs shadow-xs">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        <span>+ محادثة جديدة</span>
                    </a>
                </div>
            @endif
        </div>
    </div>
</section>
