<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle }} | حاسم الذكي</title>
    <meta name="description" content="{{ $metaDescription }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700,800&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('legal.partials.styles')
</head>
<body class="hs-legal-app antialiased" style="font-family: var(--hs-font)">
    <a href="#hs-legal-content" class="sr-only focus:not-sr-only focus:absolute focus:right-4 focus:top-4 focus:z-50 focus:rounded-xl focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:font-bold focus:text-[#0f7668]">تخطٍ إلى المحتوى</a>
    @include('partials.public-topbar')

    <header class="hs-legal-hero">
        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <p class="hs-legal-kicker">HASIM SMART</p>
            <h1 class="mt-2 text-3xl font-black leading-tight text-[#0f172a] sm:text-4xl">{{ $pageHeading }}</h1>
            <p class="mt-3 max-w-3xl text-sm font-medium leading-7 text-slate-600 sm:text-[15px]">{{ $pageIntro }}</p>
            @if (filled($legal['effective_date'] ?? null))
                <p class="mt-4 text-xs font-bold text-slate-500">آخر تحديث: {{ $legal['effective_date'] }}</p>
            @endif
        </div>
    </header>

    <main id="hs-legal-content" class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 lg:py-12">
        <div class="hs-legal-layout">
            @include('legal.partials.toc', ['title' => $pageHeading, 'items' => $toc])
            <article class="hs-legal-card hs-legal-prose min-w-0 p-5 sm:p-8">
                @yield('content')
            </article>
        </div>
    </main>

    @include('partials.public-site-footer')
    @include('partials.ai-assistant-widget')
</body>
</html>
