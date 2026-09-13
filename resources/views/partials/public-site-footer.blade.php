@php $homeUrl = url('/'); @endphp
<footer id="support" class="border-t border-slate-200 bg-white">
    <div class="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:px-6 lg:grid-cols-4 lg:px-8">
        <div>
            <a href="{{ $homeUrl }}" class="flex items-center gap-2.5">
                <x-hasim-logo variant="mark" size="md" />
                <span>
                    <span class="block text-sm font-extrabold text-[#0f172a]">حاسم الذكي</span>
                    <span class="text-[10px] font-bold tracking-[0.18em] text-slate-500">HASIM SMART</span>
                </span>
            </a>
            <p class="mt-4 max-w-md text-sm font-medium leading-7 text-slate-600">
                منصة عربية لإدارة المحادثات والطلبات والعملاء والفريق من مكان واحد.
            </p>
        </div>
        <div>
            <p class="text-sm font-extrabold text-[#0f172a]">المنتج</p>
            <div class="mt-3 flex flex-col gap-2 text-sm font-bold text-slate-600">
                <a href="{{ $homeUrl }}#features" class="hover:text-[#06C2A4]">المميزات</a>
                <a href="{{ $homeUrl }}#pricing" class="hover:text-[#06C2A4]">الأسعار</a>
                <a href="{{ $homeUrl }}#journey" class="hover:text-[#06C2A4]">قصص نجاح</a>
                <a href="{{ $homeUrl }}#blog" class="hover:text-[#06C2A4]">المدونة</a>
                <a href="{{ route('downloads') }}" class="hover:text-[#06C2A4]">تحميل التطبيقات</a>
            </div>
        </div>
        <div>
            <p class="text-sm font-extrabold text-[#0f172a]">الدعم</p>
            <div class="mt-3 flex flex-col gap-2 text-sm font-bold text-slate-600">
                <a href="{{ route('login') }}" class="hover:text-[#06C2A4]">تسجيل الدخول</a>
                <a href="{{ route('register') }}" class="hover:text-[#06C2A4]">ابدأ مجانًا</a>
                <a href="{{ route('platform.login') }}" class="hover:text-[#06C2A4]">منصة الإدارة</a>
            </div>
        </div>
        <div>
            <p class="text-sm font-extrabold text-[#0f172a]">قانوني</p>
            <div class="mt-3 flex flex-col gap-2 text-sm font-bold text-slate-600">
                <a href="{{ route('legal.privacy') }}" class="hover:text-[#06C2A4]" @if(request()->routeIs('legal.privacy')) aria-current="page" @endif>سياسة الخصوصية</a>
                <a href="{{ route('legal.terms') }}" class="hover:text-[#06C2A4]" @if(request()->routeIs('legal.terms')) aria-current="page" @endif>الشروط والأحكام</a>
                <a href="{{ route('legal.cookies') }}" class="hover:text-[#06C2A4]" @if(request()->routeIs('legal.cookies')) aria-current="page" @endif>سياسة ملفات تعريف الارتباط</a>
                <a href="{{ route('legal.acceptable-use') }}" class="hover:text-[#06C2A4]" @if(request()->routeIs('legal.acceptable-use')) aria-current="page" @endif>سياسة الاستخدام المقبول</a>
            </div>
        </div>
    </div>
    @include('partials.company-footer-content', ['showLinks' => true])
</footer>
