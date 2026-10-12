@php
    $homeUrl = url('/');
    $onDownloads = request()->routeIs('downloads', 'downloads.app');
@endphp
<header x-data="{ open: false }" class="sticky top-0 z-40 border-b border-slate-200/70 bg-white/95 backdrop-blur">
    <div class="mx-auto grid h-[4.25rem] max-w-7xl grid-cols-[auto_1fr_auto] items-center gap-3 px-4 sm:px-6 lg:grid-cols-[1fr_auto_1fr] lg:px-8">
        <a href="{{ $homeUrl }}" class="flex items-center gap-2.5 justify-self-start">
            <x-hasim-logo variant="mark" size="md" />
            @include('partials.site-brand-lockup')
        </a>

        <nav class="hidden items-center justify-center gap-5 text-sm font-bold text-slate-600 xl:gap-7 lg:flex">
            <a href="{{ $homeUrl }}#features" class="transition hover:text-[#06C2A4]">المميزات</a>
            <a href="{{ $homeUrl }}#pricing" class="transition hover:text-[#06C2A4]">الأسعار</a>
            <a href="{{ $homeUrl }}#journey" class="transition hover:text-[#06C2A4]">قصص نجاح</a>
            <a href="{{ $homeUrl }}#blog" class="transition hover:text-[#06C2A4]">المدونة</a>
            <a href="{{ $homeUrl }}#support" class="transition hover:text-[#06C2A4]">الدعم</a>
            <a href="{{ route('downloads') }}" class="transition {{ $onDownloads ? 'text-[#06C2A4]' : 'hover:text-[#06C2A4]' }}">تحميل التطبيقات</a>
        </nav>

        <div class="flex items-center justify-end gap-2 justify-self-end">
            <div class="flex items-center gap-2">
                @auth
                    <a href="{{ route('workspace.subscriptions.index') }}" class="hs-btn-primary rounded-full px-3 py-2 text-xs sm:px-4 sm:text-sm">اشتراكي</a>
                @else
                    <a href="{{ route('login') }}" class="hidden rounded-full border border-slate-200 px-4 py-2 text-sm font-bold text-slate-700 transition hover:border-[#06C2A4] hover:text-[#067e6b] sm:inline-flex">تسجيل الدخول</a>
                    <a href="{{ route('register') }}" class="hs-btn-primary rounded-full px-4 py-2 text-sm">ابدأ مجانًا</a>
                @endauth
            </div>
            <button @click="open = !open" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-700 lg:hidden" aria-label="القائمة">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16M4 12h16M4 18h16" /></svg>
            </button>
        </div>
    </div>
    <div x-cloak x-show="open" class="border-t border-slate-100 bg-white px-4 py-3 lg:hidden">
        <div class="flex flex-col gap-3 text-sm font-bold text-slate-700">
            <a href="{{ $homeUrl }}#features" @click="open = false">المميزات</a>
            <a href="{{ $homeUrl }}#pricing" @click="open = false">الأسعار</a>
            <a href="{{ $homeUrl }}#journey" @click="open = false">قصص نجاح</a>
            <a href="{{ $homeUrl }}#blog" @click="open = false">المدونة</a>
            <a href="{{ $homeUrl }}#support" @click="open = false">الدعم</a>
            <a href="{{ route('downloads') }}" @click="open = false" class="{{ $onDownloads ? 'text-[#06C2A4]' : '' }}">تحميل التطبيقات</a>
            <div class="flex gap-2 pt-1">
                @auth
                    <a href="{{ route('workspace.subscriptions.index') }}" class="hs-btn-primary flex-1 py-2 text-sm">اشتراكي</a>
                @else
                    <a href="{{ route('login') }}" class="hs-btn-secondary flex-1 py-2 text-sm">تسجيل الدخول</a>
                    <a href="{{ route('register') }}" class="hs-btn-primary flex-1 py-2 text-sm">ابدأ مجانًا</a>
                @endauth
            </div>
        </div>
    </div>
</header>
