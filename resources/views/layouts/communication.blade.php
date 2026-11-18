<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $pageTitle ?? 'Hasim AI — منصة الذكاء الاصطناعي للأعمال' }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700,800&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    @php
        $hsWorkspace = request()->attributes->get('workspace') ?? app(\App\Support\Tenancy\WorkspaceContext::class)->workspace();
        $user = Auth::user();
        $userInitials = $user ? mb_substr($user->name, 0, 1, 'utf-8') : 'ح';
        $userWorkspaces = $user ? $user->workspaces()->take(6)->get() : collect();
    @endphp
    <body class="bg-[#F8FAFC] font-sans text-slate-900 antialiased selection:bg-[#E8FAF6] selection:text-[#067E6B] {{ ($flush ?? false) ? 'hs-flush-layout h-screen h-dvh max-h-screen max-h-dvh overflow-hidden' : 'min-h-screen' }}" data-hs-shell="communication">
        <div 
            x-data="{ 
                sidebarOpen: false, 
                sidebarCollapsed: localStorage.getItem('hs_sidebar_collapsed') === 'true',
                searchOpen: false,
                toggleSidebar() {
                    this.sidebarCollapsed = !this.sidebarCollapsed;
                    localStorage.setItem('hs_sidebar_collapsed', this.sidebarCollapsed);
                }
            }" 
            @keydown.window.ctrl.k.prevent="searchOpen = true"
            @keydown.window.meta.k.prevent="searchOpen = true"
            class="hs-workspace-shell {{ ($flush ?? false) ? 'h-screen h-dvh max-h-screen max-h-dvh overflow-hidden' : 'min-h-screen' }} w-full bg-[#F8FAFC] lg:flex"
        >
            <!-- Desktop Sidebar -->
            <aside 
                :class="sidebarCollapsed ? 'w-20' : 'w-64'"
                class="hs-workspace-sidebar hidden shrink-0 h-screen max-h-screen border-e border-slate-200/80 bg-white shadow-[1px_0_3px_0_rgba(15,23,42,0.03)] lg:flex lg:flex-col transition-all duration-200" 
                data-hs-chrome="sidebar"
            >
                <!-- Brand Header -->
                <div class="flex h-16 items-center justify-between border-b border-slate-100 px-4">
                    <a href="{{ route('workspace.dashboard') }}" class="flex items-center gap-2.5 overflow-hidden">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-[#06C2A4] to-[#049E86] text-white shadow-sm">
                            <span class="text-base font-extrabold tracking-tight">H</span>
                        </div>
                        <div x-show="!sidebarCollapsed" class="min-w-0 transition-opacity">
                            <span class="flex items-center gap-1.5 text-base font-extrabold tracking-tight text-slate-900">
                                <span>حاسم</span>
                                <span class="rounded bg-[#E8FAF6] px-1.5 py-0.5 text-[10px] font-bold text-[#067E6B]">AI</span>
                            </span>
                            <span class="block truncate text-[11px] font-medium text-slate-400">{{ $hsWorkspace?->name ?: 'لوحة التحكم' }}</span>
                        </div>
                    </a>
                    <button 
                        @click="toggleSidebar()" 
                        type="button" 
                        class="hidden lg:flex h-7 w-7 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition"
                        :title="sidebarCollapsed ? 'توسيع القائمة' : 'طي القائمة'"
                    >
                        <svg class="h-4 w-4 transition-transform duration-200" :class="sidebarCollapsed ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </button>
                </div>

                <!-- Navigation Links -->
                <div class="flex-1 overflow-y-auto px-3 py-4 hs-scrollbar">
                    @include('workspace.partials.sidebar-links')
                </div>

                <!-- Workspace Info Footer -->
                <div class="border-t border-slate-100 p-3">
                    <a 
                        href="{{ route('workspace.choose') }}" 
                        class="flex items-center gap-2.5 rounded-xl border border-slate-100 bg-slate-50/80 p-2 text-xs transition hover:bg-slate-100 hover:border-slate-200"
                        :title="sidebarCollapsed ? 'تبديل مساحة العمل' : ''"
                    >
                        <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white text-[#06C2A4] shadow-sm font-bold">
                            {{ mb_substr($hsWorkspace?->name ?: 'W', 0, 1, 'utf-8') }}
                        </div>
                        <div x-show="!sidebarCollapsed" class="min-w-0 flex-1">
                            <div class="truncate font-semibold text-slate-800">{{ $hsWorkspace?->name ?: 'المساحة الحالية' }}</div>
                            <div class="text-[10px] text-slate-400">انقر للتبديل</div>
                        </div>
                        <svg x-show="!sidebarCollapsed" class="h-3.5 w-3.5 text-slate-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4" />
                        </svg>
                    </a>
                </div>
            </aside>

            <!-- Main Workspace Area -->
            <div class="hs-workspace-main flex min-h-0 w-full min-w-0 flex-1 flex-col {{ ($flush ?? false) ? 'h-screen h-dvh max-h-screen max-h-dvh overflow-hidden' : '' }}">
                <!-- Top Navigation Bar -->
                <header class="sticky top-0 z-30 shrink-0 border-b border-slate-200/80 bg-white/95 backdrop-blur shadow-[0_1px_2px_0_rgba(15,23,42,0.02)]" data-hs-chrome="topbar">
                    <div class="flex h-16 min-w-0 items-center justify-between gap-3 px-4 sm:px-6 lg:px-8">
                        <!-- Left: Mobile Menu & Current Breadcrumb -->
                        <div class="flex items-center gap-3 min-w-0">
                            <button 
                                @click="sidebarOpen = true" 
                                type="button" 
                                class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200/90 text-slate-600 transition hover:bg-slate-50 hover:text-slate-900 lg:hidden" 
                                aria-label="فتح القائمة"
                            >
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                                </svg>
                            </button>

                            <div class="hidden sm:flex items-center gap-2 text-xs text-slate-500">
                                <span class="font-medium">{{ $hsWorkspace?->name ?: 'حاسم' }}</span>
                                <svg class="h-3 w-3 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7"/></svg>
                                <span class="font-semibold text-slate-800">
                                    @if(request()->routeIs('workspace.conversations.*')) المحادثات والوارد
                                    @elseif(request()->routeIs('workspace.customers.*')) إدارة العملاء CRM
                                    @elseif(request()->routeIs('workspace.commerce.orders.*')) الطلبات والمبيعات
                                    @elseif(request()->routeIs('workspace.chat-catalog.*')) المنتجات والمخزون
                                    @else التواصل
                                    @endif
                                </span>
                            </div>
                        </div>

                        <!-- Center: Search Trigger -->
                        <div class="flex-1 max-w-md mx-2 hidden md:block">
                            <button 
                                @click="searchOpen = true"
                                type="button" 
                                class="w-full flex items-center justify-between rounded-xl border border-slate-200/90 bg-slate-50/70 px-3.5 py-2 text-xs text-slate-400 hover:border-slate-300 hover:bg-white hover:text-slate-600 transition shadow-sm"
                            >
                                <span class="flex items-center gap-2">
                                    <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                                    </svg>
                                    <span>البحث السريع في النظام...</span>
                                </span>
                                <kbd class="hidden sm:inline-flex items-center gap-0.5 rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] font-mono text-slate-500 shadow-sm">
                                    Ctrl+K
                                </kbd>
                            </button>
                        </div>

                        <!-- Right: Actions & User -->
                        <div class="flex min-w-0 items-center gap-2 sm:gap-3">
                            <a 
                                href="{{ route('notifications.index') }}" 
                                class="hs-btn-icon relative" 
                                aria-label="الإشعارات"
                                title="الإشعارات"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                                </svg>
                                @if(Auth::user()?->unreadNotifications()->exists())
                                    <span class="absolute top-1.5 end-1.5 flex h-2 w-2">
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                                    </span>
                                @endif
                            </a>

                            <!-- Workspace Selector -->
                            <x-dropdown align="right" width="60">
                                <x-slot name="trigger">
                                    <button class="inline-flex items-center gap-2 rounded-xl border border-slate-200/90 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 focus:outline-none">
                                        <span class="h-2 w-2 rounded-full bg-[#06C2A4]"></span>
                                        <span class="max-w-[100px] truncate hidden sm:inline">{{ $hsWorkspace?->name ?: 'المساحة' }}</span>
                                        <svg class="h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <div class="px-3 py-2 border-b border-slate-100 text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                        مساحات العمل
                                    </div>
                                    @foreach($userWorkspaces as $ws)
                                        <form method="POST" action="{{ route('workspace.switch', $ws) }}">
                                            @csrf
                                            <button type="submit" class="w-full flex items-center justify-between px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 transition {{ $ws->id === $hsWorkspace?->id ? 'bg-[#E8FAF6] text-[#067E6B] font-bold' : '' }}">
                                                <span class="truncate">{{ $ws->name }}</span>
                                                @if($ws->id === $hsWorkspace?->id)
                                                    <svg class="h-3.5 w-3.5 text-[#06C2A4]" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z" clip-rule="evenodd"/></svg>
                                                @endif
                                            </button>
                                        </form>
                                    @endforeach
                                    <div class="border-t border-slate-100 mt-1">
                                        <x-dropdown-link :href="route('workspace.choose')" class="text-xs text-[#067E6B] font-semibold">
                                            + إدارة كافة المساحات
                                        </x-dropdown-link>
                                    </div>
                                </x-slot>
                            </x-dropdown>

                            <!-- User Profile Menu -->
                            <x-dropdown align="right" width="56">
                                <x-slot name="trigger">
                                    <button class="inline-flex items-center gap-2 rounded-xl border border-slate-200/90 bg-white p-1 pe-2.5 text-xs font-semibold text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 focus:outline-none">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-slate-800 to-slate-950 text-white text-xs font-bold shadow-sm">
                                            {{ $userInitials }}
                                        </span>
                                        <span class="hidden md:inline font-semibold text-slate-800">{{ $user->name }}</span>
                                        <svg class="h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                                    </button>
                                </x-slot>
                                <x-slot name="content">
                                    <div class="px-4 py-2.5 border-b border-slate-100">
                                        <p class="text-xs font-bold text-slate-900">{{ $user->name }}</p>
                                        <p class="text-[11px] text-slate-400 truncate mt-0.5">{{ $user->email }}</p>
                                    </div>
                                    <x-dropdown-link :href="route('profile.edit')" class="text-xs">
                                        الملف الشخصي والحساب
                                    </x-dropdown-link>
                                    <x-dropdown-link :href="route('workspace.dashboard')" class="text-xs">
                                        لوحة التحكم
                                    </x-dropdown-link>
                                    <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100">
                                        @csrf
                                        <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();" class="text-xs text-rose-600 hover:text-rose-700">
                                            تسجيل الخروج
                                        </x-dropdown-link>
                                    </form>
                                </x-slot>
                            </x-dropdown>
                        </div>
                    </div>
                </header>

                <!-- Mobile Drawer Sidebar -->
                <div x-cloak x-show="sidebarOpen" class="fixed inset-0 z-50 lg:hidden">
                    <button @click="sidebarOpen = false" type="button" class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity"></button>
                    <aside x-transition:enter="transition ease-in-out duration-300" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0" class="absolute inset-y-0 start-0 w-72 overflow-y-auto border-e border-slate-200 bg-white shadow-xl" data-hs-chrome="sidebar-mobile">
                        <div class="flex h-16 items-center justify-between border-b border-slate-100 px-5">
                            <a href="{{ route('workspace.dashboard') }}" class="flex items-center gap-2.5">
                                <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-[#06C2A4] to-[#049E86] text-white font-extrabold shadow-sm">
                                    H
                                </div>
                                <span class="text-base font-extrabold text-slate-900">حاسم AI</span>
                            </a>
                            <button @click="sidebarOpen = false" type="button" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                        <div class="px-3 py-4">
                            @include('workspace.partials.sidebar-links')
                        </div>
                    </aside>
                </div>

                <!-- Global Quick Search Modal (Cmd+K) -->
                <div x-cloak x-show="searchOpen" class="fixed inset-0 z-50 overflow-y-auto p-4 sm:p-6 md:p-20" role="dialog" aria-modal="true">
                    <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity" @click="searchOpen = false"></div>
                    <div class="mx-auto max-w-xl transform rounded-2xl bg-white shadow-2xl ring-1 ring-black/5 transition-all overflow-hidden" @keydown.escape="searchOpen = false">
                        <div class="relative border-b border-slate-100">
                            <svg class="pointer-events-none absolute start-4 top-3.5 h-5 w-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                            </svg>
                            <input type="text" placeholder="اكتب للبحث أو الانتقال السريع..." class="h-12 w-full border-0 bg-transparent ps-11 pe-4 text-sm text-slate-900 placeholder:text-slate-400 focus:ring-0">
                        </div>
                        <div class="p-3 text-xs text-slate-500">
                            <div class="px-2 py-1 font-bold text-slate-400 uppercase tracking-wider text-[10px]">روابط سريعة</div>
                            <div class="mt-1 space-y-1">
                                <a href="{{ route('workspace.dashboard') }}" class="flex items-center justify-between rounded-xl px-3 py-2 text-slate-700 hover:bg-[#E8FAF6] hover:text-[#067E6B] transition">
                                    <span>لوحة التحكم الرئيسية</span>
                                    <span class="text-[10px] text-slate-400">Dashboard</span>
                                </a>
                                <a href="{{ route('workspace.conversations.index') }}" class="flex items-center justify-between rounded-xl px-3 py-2 text-slate-700 hover:bg-[#E8FAF6] hover:text-[#067E6B] transition">
                                    <span>المحادثات والوارد</span>
                                    <span class="text-[10px] text-slate-400">Conversations</span>
                                </a>
                                <a href="{{ route('workspace.customers.index') }}" class="flex items-center justify-between rounded-xl px-3 py-2 text-slate-700 hover:bg-[#E8FAF6] hover:text-[#067E6B] transition">
                                    <span>إدارة العملاء والطلبات المعلقة</span>
                                    <span class="text-[10px] text-slate-400">CRM</span>
                                </a>
                                <a href="{{ route('workspace.commerce.orders.index') }}" class="flex items-center justify-between rounded-xl px-3 py-2 text-slate-700 hover:bg-[#E8FAF6] hover:text-[#067E6B] transition">
                                    <span>طلبات التجارة والمبيعات</span>
                                    <span class="text-[10px] text-slate-400">Orders</span>
                                </a>
                                <a href="{{ route('workspace.chat-catalog.index') }}" class="flex items-center justify-between rounded-xl px-3 py-2 text-slate-700 hover:bg-[#E8FAF6] hover:text-[#067E6B] transition">
                                    <span>المنتجات والكتالوج</span>
                                    <span class="text-[10px] text-slate-400">Catalog</span>
                                </a>
                                <a href="{{ route('workspace.ai-settings.edit') }}" class="flex items-center justify-between rounded-xl px-3 py-2 text-slate-700 hover:bg-[#E8FAF6] hover:text-[#067E6B] transition">
                                    <span>موظفو الذكاء الاصطناعي (مشعل)</span>
                                    <span class="text-[10px] text-slate-400">AI Employees</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                @isset($header)
                    <section class="border-b border-slate-200/80 bg-white">
                        <div class="px-4 py-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </section>
                @endisset

                <!-- Main Content -->
                <main data-hs-region @class([
                    'min-w-0 flex-1',
                    'px-4 py-6 sm:px-6 lg:px-8' => ! ($flush ?? false),
                    'p-0 min-h-0 h-[calc(100%-4rem)] max-h-[calc(100%-4rem)] overflow-hidden flex flex-row' => ($flush ?? false) && ($secondarySidebar ?? false),
                    'p-0 min-h-0 h-[calc(100%-4rem)] max-h-[calc(100%-4rem)] overflow-hidden' => ($flush ?? false) && ! ($secondarySidebar ?? false),
                ])>
                    @if($secondarySidebar ?? false)
                        @include('workspace.communication.partials.secondary-nav')
                    @endif
                    <div class="flex-1 min-w-0 min-h-0 h-full max-h-full overflow-hidden flex flex-col">
                        {{ $slot }}
                    </div>
                </main>

                @unless($flush ?? false)
                    <footer class="mt-auto border-t border-slate-200/80 bg-white">
                        @include('partials.company-footer-content')
                    </footer>
                @endunless
            </div>
        </div>
        @if($showAssistant ?? true)
            @include('partials.ai-assistant-widget')
        @endif
        @stack('scripts')
    </body>
</html>
