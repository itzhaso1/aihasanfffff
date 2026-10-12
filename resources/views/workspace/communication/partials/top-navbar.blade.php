@php
    $communicationNav = \App\Support\Ui\CommunicationNav::data();
    $communicationTopLinks = $communicationNav['topLinks'];
    $communicationIcons = $communicationNav['icons'];
    $isCommunicationModule = $communicationNav['isCommunicationModule'];
@endphp

<header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur" data-hs-chrome="topbar">
    <div class="flex h-14 items-center gap-3 px-3 sm:px-5">
        <button
            @click="mobileNav = true"
            type="button"
            class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 text-slate-600 transition hover:bg-slate-50 lg:hidden"
            aria-label="فتح القائمة"
        >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>

        <a href="{{ route('workspace.dashboard') }}" class="flex shrink-0 items-center gap-2">
            <x-hasim-logo variant="mark" size="sm" />
            <span class="hidden text-lg font-extrabold tracking-tight text-[#06C2A4] sm:inline">حاسم</span>
        </a>

        <nav class="hidden min-w-0 flex-1 items-center justify-center gap-1 lg:flex" aria-label="أقسام المنصة">
            @foreach($communicationTopLinks as $link)
                @php $isActive = \App\Support\Ui\CommunicationNav::linkIsActive($link, $isCommunicationModule); @endphp
                <a
                    href="{{ route($link['route']) }}"
                    class="{{ $isActive ? 'bg-[#E8FAF6] text-[#067e6b]' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800' }} inline-flex min-w-[4.5rem] flex-col items-center gap-0.5 rounded-xl px-2.5 py-1.5 transition"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $communicationIcons[$link['icon']] ?? '' !!}</svg>
                    <span class="text-[11px] font-semibold">{{ $link['label'] }}</span>
                </a>
            @endforeach
        </nav>

        <div class="ms-auto flex items-center gap-1.5 sm:gap-2">
            <a href="{{ route('profile.edit') }}" class="hidden rounded-full border border-slate-200 px-3 py-1.5 text-xs text-slate-600 transition hover:border-[#06C2A4] hover:text-[#06C2A4] sm:inline-flex">الإعدادات</a>
            <a href="{{ route('workspace.choose') }}" class="hidden rounded-full border border-slate-200 px-3 py-1.5 text-xs text-slate-600 transition hover:border-[#06C2A4] hover:text-[#06C2A4] md:inline-flex">المساحات</a>
            <a href="{{ route('notifications.index') }}" class="relative inline-flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:border-[#06C2A4] hover:text-[#06C2A4]">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0a3 3 0 1 1-6 0m6 0H9" />
                </svg>
                @if(Auth::user()?->unreadNotifications()->exists())
                    <span class="absolute top-1.5 end-1.5 h-2 w-2 rounded-full bg-red-500"></span>
                @endif
            </a>
            <x-dropdown align="right" width="56">
                <x-slot name="trigger">
                    <button class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-2.5 py-1.5 text-sm font-medium text-slate-700 transition hover:border-[#06C2A4] hover:text-[#06C2A4]">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-slate-500">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </span>
                        <span class="hidden max-w-[8rem] truncate lg:inline">{{ Auth::user()?->name }}</span>
                    </button>
                </x-slot>
                <x-slot name="content">
                    <x-dropdown-link :href="route('profile.edit')">الملف الشخصي</x-dropdown-link>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                            تسجيل الخروج
                        </x-dropdown-link>
                    </form>
                </x-slot>
            </x-dropdown>
        </div>
    </div>
</header>

<div x-cloak x-show="mobileNav" class="fixed inset-0 z-50 lg:hidden">
    <button @click="mobileNav = false" type="button" class="absolute inset-0 bg-slate-900/40"></button>
    <aside x-transition class="absolute inset-y-0 start-0 flex w-72 flex-col border-e border-slate-200 bg-white">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-4">
            <x-hasim-logo variant="mark" size="sm" />
            <button @click="mobileNav = false" type="button" class="rounded-md p-2 text-slate-500 hover:bg-slate-100">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <nav class="flex-1 space-y-1 overflow-y-auto p-3">
            @foreach($communicationTopLinks as $link)
                @php $isActive = \App\Support\Ui\CommunicationNav::linkIsActive($link, $isCommunicationModule); @endphp
                <a
                    href="{{ route($link['route']) }}"
                    class="{{ $isActive ? 'bg-[#E8FAF6] text-[#067e6b]' : 'text-slate-700 hover:bg-slate-50' }} flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $communicationIcons[$link['icon']] ?? '' !!}</svg>
                    {{ $link['label'] }}
                </a>
            @endforeach
        </nav>
    </aside>
</div>
