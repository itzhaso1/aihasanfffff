<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? 'حاسم - POS' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-slate-100 font-sans text-slate-900 antialiased">
    <div class="min-h-screen">
        <header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex h-14 max-w-[1500px] items-center justify-between gap-3 px-3 sm:px-6">
                <div>
                    <p class="text-xs text-slate-500">{{ request()->attributes->get('workspace')?->name }}</p>
                    <h1 class="text-sm font-extrabold text-slate-900">{{ $pageTitle ?? 'POS / Cashier' }}</h1>
                </div>
                <div class="flex items-center gap-2">
                    @include('workspace.pos.partials.cart-nav-button')
                    <a href="{{ route('workspace.dashboard') }}" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-100">
                        العودة إلى الرئيسية
                    </a>
                </div>
            </div>
            @include('workspace.pos.partials.top-nav')
        </header>

        <main class="mx-auto max-w-[1500px] p-3 sm:p-6">
            @include('partials.flash')
            @yield('content')
        </main>
    </div>
</body>
</html>
