<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?: ($publicSite ?? \App\Support\Site\PublicSite::current())->appName() }}</title>
        @include('partials.public-site-head')

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        @if ($variant === 'split')
            <link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700,800&display=swap" rel="stylesheet" />
        @else
            <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
        @endif

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    @if ($variant === 'split')
    <body class="overflow-x-hidden bg-[#F7FCFB] text-[#0f172a] antialiased" style="font-family: var(--hs-font)">
        {{ $slot }}
        @include('partials.ai-assistant-widget')
    </body>
    @else
    <body class="bg-slate-100 font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col items-center justify-center bg-gradient-to-b from-[#E8FAF6] via-slate-50 to-white px-4 py-8">
            <div class="text-center">
                <a href="/" class="inline-flex flex-col items-center">
                    <x-hasim-logo size="xl" class="h-24 max-w-[16rem]" />
                </a>
            </div>

            <div class="mt-6 w-full max-w-lg overflow-hidden rounded-2xl bg-white/90 p-6 shadow-xl ring-1 ring-slate-200">
                {{ $slot }}
            </div>
        </div>
        @include('partials.ai-assistant-widget')
    </body>
    @endif
</html>
