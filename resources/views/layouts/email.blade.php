<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? 'مركز البريد الإلكتروني' }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700,800&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-[#F7FCFB] font-sans text-slate-900 antialiased" data-hs-shell="email">
    <div x-data="{ mobileNav: false, mobileSidebar: false }" class="min-h-screen">
        @include('workspace.communication.partials.top-navbar')
        @include('workspace.communication.partials.secondary-nav')

        <div class="flex min-h-[calc(100vh-6.75rem)]">
            <aside class="hidden w-72 shrink-0 border-e border-slate-200 bg-white xl:flex xl:flex-col">
                <div class="border-b border-slate-200 px-5 py-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Hasem Email Hub</p>
                    <h2 class="mt-1 text-lg font-bold text-slate-900">{{ $pageTitle ?? 'مركز البريد الإلكتروني' }}</h2>
                </div>
                @include('workspace.emails.partials.folder-nav')
            </aside>

            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3 xl:hidden">
                    <button @click="mobileSidebar = true" type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600">
                        مجلدات البريد
                    </button>
                    <h1 class="text-sm font-bold text-slate-900">{{ $pageTitle ?? 'مركز البريد الإلكتروني' }}</h1>
                </div>
                <main class="p-4 sm:p-6 lg:p-8" data-hs-region>
                    @include('partials.flash')
                    @yield('content')
                </main>
            </div>
        </div>

        <div x-cloak x-show="mobileSidebar" class="fixed inset-0 z-50 xl:hidden">
            <button @click="mobileSidebar = false" type="button" class="absolute inset-0 bg-slate-900/50"></button>
            <aside x-transition class="absolute inset-y-0 right-0 w-80 border-l border-slate-200 bg-white">
                <div class="flex items-center justify-between border-b border-slate-200 px-4 py-4">
                    <h2 class="text-base font-bold text-slate-900">البريد الإلكتروني</h2>
                    <button @click="mobileSidebar = false" type="button" class="rounded-md p-2 text-slate-600 hover:bg-slate-100">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="p-3">
                    @include('workspace.emails.partials.folder-nav')
                </div>
            </aside>
        </div>
    </div>
    @include('partials.ai-assistant-widget')
</body>
</html>
