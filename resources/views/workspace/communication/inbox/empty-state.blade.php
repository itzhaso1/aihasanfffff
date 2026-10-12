<section class="hidden h-full min-h-0 flex-1 flex-col bg-[#F7FCFB] md:flex">
    <div class="flex h-full items-center justify-center px-6">
        <div class="max-w-md text-center">
            <div class="mx-auto mb-4 flex items-center justify-center rounded-full bg-[#E8FAF6] text-[#06C2A4]" style="height:3.5rem;width:3.5rem">
                <svg style="height:1.75rem;width:1.75rem" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M7.5 17.25H4.875A1.875 1.875 0 0 1 3 15.375V5.625A1.875 1.875 0 0 1 4.875 3.75h14.25A1.875 1.875 0 0 1 21 5.625v9.75a1.875 1.875 0 0 1-1.875 1.875H12l-4.5 3v-3Z" />
                </svg>
            </div>
            <h3 class="text-base font-semibold text-slate-900">ابدأ بإدارة محادثاتك</h3>
            <p class="mt-2 text-sm text-slate-500">اختر محادثة من القائمة لعرض الرسائل والتفاصيل، أو أنشئ محادثة جديدة للبدء.</p>
            @if($inboxAllowsCompanyActions ?? true)
                <a href="{{ route('workspace.conversations.create') }}" class="hs-btn-primary mt-5">
                    + محادثة جديدة
                </a>
            @endif
        </div>
    </div>
</section>
