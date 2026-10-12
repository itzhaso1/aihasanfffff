@php
    $communicationNav = \App\Support\Ui\CommunicationNav::data();
    $communicationSecondaryLinks = $communicationNav['secondaryLinks'];
    $communicationIcons = $communicationNav['icons'];
    $asSidebar = $secondarySidebar ?? false;
@endphp

@if($asSidebar)
    <style>
        @media (min-width: 768px) {
            [data-communication-nav="sidebar"] {
                height: 100%;
                width: 4.5rem;
            }
        }
    </style>
    <nav class="sticky top-0 z-30 hidden h-full shrink-0 flex-col overflow-hidden border-e border-slate-200/80 bg-white px-2 py-3 md:flex" data-communication-nav="sidebar" data-hs-chrome="sections" aria-label="أقسام التواصل">
        <div class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto">
            @foreach($communicationSecondaryLinks as $link)
                @php $isActive = \App\Support\Ui\CommunicationNav::secondaryLinkIsActive($link); @endphp
                <a
                    href="{{ \App\Support\Ui\CommunicationNav::secondaryHref($link) }}"
                    title="{{ $link['label'] }}"
                    @isset($link['nav']) data-communication-nav="{{ $link['nav'] }}" @endisset
                    class="{{ $isActive ? 'bg-[#E8FAF6] text-[#067e6b]' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800' }} flex items-center justify-center rounded-xl px-2 py-2.5 transition"
                >
                    <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $communicationIcons[$link['icon']] ?? '' !!}</svg>
                </a>
            @endforeach
        </div>
        @php
            $settingsLink = $communicationNav['settingsLink'] ?? null;
            $settingsActive = is_array($settingsLink) && request()->routeIs($settingsLink['active']);
        @endphp
        @if(is_array($settingsLink))
            <a
                href="{{ route($settingsLink['route']) }}"
                title="{{ $settingsLink['label'] }}"
                aria-label="{{ $settingsLink['label'] }}"
                data-communication-nav="settings"
                class="{{ $settingsActive ? 'bg-[#E8FAF6] text-[#067e6b]' : 'bg-slate-100 text-slate-500 hover:bg-slate-200 hover:text-slate-800' }} mx-auto mt-auto flex h-10 w-10 shrink-0 items-center justify-center rounded-full transition"
            >
                <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $communicationIcons[$settingsLink['icon']] ?? '' !!}</svg>
            </a>
        @endif
    </nav>
    <nav
        class="fixed inset-x-0 bottom-0 z-40 flex items-stretch justify-around gap-0.5 border-t border-slate-200/80 bg-white/95 px-1 py-1 backdrop-blur md:hidden"
        style="padding-bottom: max(0.25rem, env(safe-area-inset-bottom));"
        data-communication-nav="mobile-bar"
        aria-label="أقسام التواصل"
    >
        @foreach($communicationSecondaryLinks as $link)
            @php $isActive = \App\Support\Ui\CommunicationNav::secondaryLinkIsActive($link); @endphp
            <a
                href="{{ \App\Support\Ui\CommunicationNav::secondaryHref($link) }}"
                @isset($link['nav']) data-communication-nav="{{ $link['nav'] }}" @endisset
                class="{{ $isActive ? 'text-[#067e6b]' : 'text-slate-500' }} flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 rounded-lg px-1 py-1.5 text-[10px] font-semibold transition"
            >
                <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $communicationIcons[$link['icon']] ?? '' !!}</svg>
                <span class="max-w-full truncate">{{ $link['label'] }}</span>
            </a>
        @endforeach
        @if(is_array($settingsLink))
            <a
                href="{{ route($settingsLink['route']) }}"
                data-communication-nav="settings"
                class="{{ $settingsActive ? 'text-[#067e6b]' : 'text-slate-500' }} flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 rounded-lg px-1 py-1.5 text-[10px] font-semibold transition"
            >
                <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $communicationIcons[$settingsLink['icon']] ?? '' !!}</svg>
                <span class="max-w-full truncate">{{ $settingsLink['label'] }}</span>
            </a>
        @endif
    </nav>
@else
    <nav @class([
        'sticky z-30 border-b border-slate-200/80 bg-white',
        'top-14' => $showNavbar ?? true,
        'top-0' => ! ($showNavbar ?? true),
    ]) data-hs-chrome="sections" aria-label="أقسام التواصل">
        <div class="flex gap-1 overflow-x-auto px-3 py-2 sm:px-5">
            @foreach($communicationSecondaryLinks as $link)
                @php $isActive = \App\Support\Ui\CommunicationNav::secondaryLinkIsActive($link); @endphp
                <a
                    href="{{ \App\Support\Ui\CommunicationNav::secondaryHref($link) }}"
                    class="{{ $isActive ? 'bg-[#E8FAF6] text-[#067e6b]' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-800' }} inline-flex shrink-0 items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold transition"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $communicationIcons[$link['icon']] ?? '' !!}</svg>
                    {{ $link['label'] }}
                </a>
            @endforeach
        </div>
    </nav>
@endif
