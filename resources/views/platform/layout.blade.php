<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'منصة الإدارة' }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700,800&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50 text-gray-900 antialiased" data-hs-shell="platform">
    <header class="border-b bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4">
            <h1 class="text-lg font-semibold">منصة الإدارة</h1>
            @if(auth('platform_admin')->check())
                <div class="flex items-center gap-3 text-sm">
                    <span>{{ auth('platform_admin')->user()->name }}</span>
                    <form method="POST" action="{{ route('platform.logout') }}">
                        @csrf
                        <button class="rounded-md bg-red-600 px-3 py-1.5 text-white">خروج</button>
                    </form>
                </div>
            @endif
        </div>
    </header>
    <main class="min-h-screen" data-hs-region>
        @yield('content')
    </main>
    <footer class="border-t border-gray-200 bg-white">
        @include('partials.company-footer-content')
    </footer>
</body>
</html>
