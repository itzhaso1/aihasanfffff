<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? 'حاسم المالية' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('workspace.finance.partials.finance-shell-styles')
</head>
@php
    $hsWorkspace = request()->attributes->get('workspace');
    $hsUser = auth()->user();
    $hsIsOwner = $hsWorkspace && $hsUser && (int) $hsWorkspace->owner_user_id === (int) $hsUser->id;
    $hsRoleLabel = $hsIsOwner ? 'صاحب الحساب' : ($hsWorkspace?->name ?: 'حساب الفوترة');
    $hsInitial = \Illuminate\Support\Str::substr($hsUser?->name ?? 'ح', 0, 1);
@endphp
<body class="hs-fin-app font-sans antialiased">
    <div x-data="{ mobileSidebar: false }" class="min-h-screen">
        <aside class="hs-fin-sidebar">
            @include('workspace.finance.partials.sidebar')
        </aside>

        <div class="hs-fin-shell">
            <header class="hs-fin-topbar">
                <button @click="mobileSidebar = true" type="button" class="hs-fin-icon-btn hs-fin-menu-btn" aria-label="فتح القائمة">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>

                <form method="GET" action="{{ route('workspace.finance.search') }}" class="hs-fin-search">
                    <svg class="hs-fin-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 21l-4.3-4.3M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z" />
                    </svg>
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="ابحث في الفواتير، العملاء، المصروفات...">
                </form>

                <a href="{{ route('workspace.finance.alerts.index') }}" class="hs-fin-icon-btn" title="التنبيهات" aria-label="التنبيهات">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0a3 3 0 11-6 0" />
                    </svg>
                </a>

                <div class="hs-fin-user">
                    <div class="hs-fin-user-meta">
                        <p class="hs-fin-user-name">{{ $hsUser?->name }}</p>
                        <p class="hs-fin-user-role">{{ $hsRoleLabel }}</p>
                    </div>
                    <span class="hs-fin-avatar" aria-hidden="true">{{ $hsInitial }}</span>
                </div>

                <a href="{{ route('workspace.dashboard') }}" class="hs-fin-back">
                    ← العودة إلى حاسم
                </a>
            </header>

            <form method="GET" action="{{ route('workspace.finance.search') }}" class="hs-fin-search-mobile px-4 pt-3">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="ابحث في الفواتير، العملاء، المصروفات..." class="w-full rounded-full border border-slate-200 bg-white px-4 py-2 text-sm">
            </form>

            <main class="hs-fin-main">
                @include('partials.flash')
                @yield('content')
            </main>
        </div>

        <div x-cloak x-show="mobileSidebar" class="hs-fin-drawer-overlay fixed inset-0 z-50">
            <button @click="mobileSidebar = false" type="button" class="absolute inset-0 bg-slate-900/40"></button>
            <aside x-transition class="hs-fin-drawer absolute inset-y-0 right-0 flex flex-col">
                <div class="flex items-center justify-between px-4 py-4">
                    <h2 class="text-base font-black text-slate-900">حاسم المالية</h2>
                    <button @click="mobileSidebar = false" type="button" class="hs-fin-icon-btn">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto">
                    @include('workspace.finance.partials.sidebar')
                </div>
            </aside>
        </div>
    </div>
</body>
</html>
