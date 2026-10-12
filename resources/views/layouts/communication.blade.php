<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $pageTitle ?? 'التواصل — حاسم' }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700,800&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-[#F7FCFB] font-sans text-slate-900 antialiased" data-hs-shell="communication">
        <div @class([
            'min-h-0 overflow-hidden',
            ($secondarySidebar ?? false) ? 'flex h-dvh max-h-dvh' : 'flex min-h-screen flex-col',
        ]) data-hs-shell-root x-data="{ mobileNav: false }">
            <div data-hs-chrome="frame" @class([
                'shrink-0 min-h-0' => $secondarySidebar ?? false,
            ])>
            @if($showNavbar ?? true)
                @include('workspace.communication.partials.top-navbar')
            @endif
            @if($showSecondary ?? true)
                @include('workspace.communication.partials.secondary-nav')
            @endif
            </div>

            <div class="flex min-h-0 min-w-0 flex-1 flex-col" data-hs-region>
            @isset($header)
                <section class="border-b border-slate-100 bg-white">
                    <div class="px-4 py-3 sm:px-6">
                        {{ $header }}
                    </div>
                </section>
            @endisset

            <main @class([
                'flex-1 min-w-0' => true,
                'flex min-h-0 flex-col overflow-hidden' => ($flush ?? false) && ($secondarySidebar ?? false),
                'min-h-0 overflow-hidden' => ($flush ?? false) && ! ($secondarySidebar ?? false),
                'px-4 py-5 sm:px-6 lg:px-8' => ! ($flush ?? false),
                'pb-[calc(3.75rem+env(safe-area-inset-bottom,0px))] md:pb-0' => ($flush ?? false) && ($secondarySidebar ?? false),
            ])>
                {{ $slot }}
            </main>
            </div>
        </div>
        @if($showAssistant ?? true)
            @include('partials.ai-assistant-widget')
        @endif
        <div data-hs-boot hidden>
            @stack('scripts')
        </div>
    </body>
</html>
