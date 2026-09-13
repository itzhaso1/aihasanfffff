@php
    $communicationNav = \App\Support\Ui\CommunicationNav::data();
    $communicationSecondaryLinks = $communicationNav['secondaryLinks'];
    $communicationIcons = $communicationNav['icons'];
@endphp

<nav class="sticky top-14 z-30 border-b border-slate-200/80 bg-white" aria-label="أقسام التواصل">
    <div class="flex gap-1 overflow-x-auto px-3 py-2 sm:px-5">
        @foreach($communicationSecondaryLinks as $link)
            @php $isActive = request()->routeIs($link['active'] ?? $link['route']); @endphp
            <a
                href="{{ route($link['route']) }}"
                class="{{ $isActive ? 'bg-[#E8FAF6] text-[#067e6b]' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800' }} inline-flex shrink-0 items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold transition"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $communicationIcons[$link['icon']] ?? '' !!}</svg>
                {{ $link['label'] }}
            </a>
        @endforeach
    </div>
</nav>
