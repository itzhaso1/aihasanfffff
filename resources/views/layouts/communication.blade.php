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
    <body class="bg-[#F7FCFB] font-sans text-slate-900 antialiased">
        <div x-data="{ mobileNav: false }" class="flex min-h-screen flex-col">
            @include('workspace.communication.partials.top-navbar')
            @if($showSecondary ?? true)
                @include('workspace.communication.partials.secondary-nav')
            @endif

            @isset($header)
                <section class="border-b border-slate-100 bg-white">
                    <div class="px-4 py-3 sm:px-6">
                        {{ $header }}
                    </div>
                </section>
            @endisset

            <main @class([
                'flex-1' => true,
                'min-h-0 overflow-hidden' => $flush ?? false,
                'px-4 py-5 sm:px-6 lg:px-8' => ! ($flush ?? false),
            ])>
                {{ $slot }}
            </main>
        </div>
        @include('partials.ai-assistant-widget')
    </body>
</html>
