<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'HASEM') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700,800&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    @php
        $isWorkspaceShell = request()->is('workspace*');
        $hsWorkspace = request()->attributes->get('workspace');
    @endphp
    <body class="bg-white font-sans text-gray-900 antialiased" data-hs-shell="{{ $isWorkspaceShell ? 'workspace' : 'account' }}">

        @if($isWorkspaceShell)
            <div x-data="{ sidebarOpen: false }" class="hs-workspace-shell min-h-screen w-full bg-[#F7FCFB] lg:flex">
                <aside class="hs-workspace-sidebar hidden w-72 shrink-0 border-e border-slate-200/80 bg-white lg:flex lg:flex-col" data-hs-chrome="sidebar">
                    <div class="border-b border-slate-100 px-5 py-5">
                        <a href="{{ route('workspace.dashboard') }}" class="flex items-center gap-2.5">
                            <x-hasim-logo variant="mark" size="lg" />
                            <span class="min-w-0">
                                <span class="block text-lg font-extrabold tracking-tight text-[#06C2A4]">حاسم</span>
                                <span class="mt-0.5 block truncate text-[11px] font-semibold text-slate-400">{{ $hsWorkspace?->name ?: 'لوحة التحكم' }}</span>
                            </span>
                        </a>
                    </div>
                    <div class="flex-1 overflow-y-auto px-3 py-5">
                        @include('workspace.partials.sidebar-links')
                    </div>
                </aside>

                <div class="hs-workspace-main flex min-h-0 w-full min-w-0 flex-1 flex-col">
                    <header class="sticky top-0 z-30 border-b border-gray-200 bg-white/95 backdrop-blur" data-hs-chrome="topbar">
                        <div class="flex h-16 min-w-0 items-center justify-between gap-3 px-4 sm:px-6 lg:px-8">
                            <button @click="sidebarOpen = true" type="button" class="inline-flex items-center justify-center rounded-lg border border-gray-200 p-2 text-gray-600 transition hover:bg-gray-50 lg:hidden" aria-label="فتح القائمة">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M4 6h16M4 12h16M4 18h16" />
                                </svg>
                            </button>
                            <a href="{{ route('workspace.dashboard') }}" class="hidden min-w-0 items-center gap-2 lg:flex">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#E8F8F4] text-[#06C2A4]">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 10.75 12 3l9 7.75V21a1 1 0 0 1-1 1h-5.5v-7h-5v7H4a1 1 0 0 1-1-1V10.75Z" />
                                    </svg>
                                </span>
                                <span class="truncate text-sm text-gray-500">{{ $hsWorkspace?->name ?: 'لوحة التحكم' }}</span>
                            </a>
                            <div class="flex min-w-0 items-center gap-2 sm:gap-3">
                                <a href="{{ route('profile.edit') }}" class="hidden rounded-full border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 transition hover:border-[#06C2A4] hover:text-[#06C2A4] sm:inline-flex">الإعدادات</a>
                                <a href="{{ route('workspace.choose') }}" class="hidden rounded-full border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 transition hover:border-[#06C2A4] hover:text-[#06C2A4] md:inline-flex">المساحات</a>
                                <a href="{{ route('notifications.index') }}" class="relative inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 transition hover:border-[#06C2A4] hover:text-[#06C2A4]" aria-label="الإشعارات">
                                    <span class="hidden sm:inline">الإشعارات</span>
                                    <span class="relative text-gray-500">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0a3 3 0 1 1-6 0m6 0H9" />
                                        </svg>
                                        @if(Auth::user()?->unreadNotifications()->exists())
                                            <span class="absolute -top-1 -end-0.5 h-2 w-2 rounded-full bg-red-500"></span>
                                        @endif
                                    </span>
                                </a>
                                <x-dropdown align="right" width="56">
                                    <x-slot name="trigger">
                                        <button class="inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:border-[#06C2A4] hover:text-[#06C2A4] focus:outline-none">
                                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-slate-500">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                                </svg>
                                            </span>
                                            <span class="hidden sm:inline">{{ Auth::user()->name }}</span>
                                            <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 011.08 1.04l-4.25 4.512a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                                            </svg>
                                        </button>
                                    </x-slot>
                                    <x-slot name="content">
                                        <x-dropdown-link :href="route('profile.edit')">الملف الشخصي</x-dropdown-link>
                                        <form method="POST" action="{{ route('logout') }}">
                                            @csrf
                                            <x-dropdown-link :href="route('logout')" onclick="event.preventDefault(); this.closest('form').submit();">
                                                تسجيل الخروج
                                            </x-dropdown-link>
                                        </form>
                                    </x-slot>
                                </x-dropdown>
                            </div>
                        </div>
                    </header>

                    <div x-cloak x-show="sidebarOpen" class="fixed inset-0 z-50 lg:hidden">
                        <button @click="sidebarOpen = false" type="button" class="absolute inset-0 bg-gray-900/40"></button>
                        <aside x-transition class="absolute inset-y-0 start-0 w-72 overflow-y-auto border-e border-gray-200 bg-white" data-hs-chrome="sidebar-mobile">
                            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-5">
                                <a href="{{ route('workspace.dashboard') }}" class="flex items-center gap-2">
                                    <x-hasim-logo variant="mark" size="sm" />
                                    <span class="text-lg font-extrabold text-[#06C2A4]">حاسم</span>
                                </a>
                                <button @click="sidebarOpen = false" type="button" class="rounded-md p-2 text-gray-600 hover:bg-gray-100">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                            <div class="px-3 py-5">
                                @include('workspace.partials.sidebar-links')
                            </div>
                        </aside>
                    </div>

                    <div data-hs-region>
                    @isset($header)
                        <section class="border-b border-gray-100 bg-white">
                            <div class="px-4 py-5 sm:px-6 lg:px-8">
                                {{ $header }}
                            </div>
                        </section>
                    @endisset

                    <main class="min-w-0 px-4 py-6 sm:px-6 lg:px-8">
                        {{ $slot }}
                    </main>
                    </div>

                    <footer class="border-t border-gray-200 bg-white">
                        @include('partials.company-footer-content')
                    </footer>
                </div>
            </div>
        @else
            <div class="min-h-screen bg-gray-50">
                @include('layouts.navigation')

                @isset($header)
                    <header class="bg-white shadow">
                        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                <main data-hs-region>
                    {{ $slot }}
                </main>
            </div>
        @endif
        @include('partials.ai-assistant-widget')
    </body>
</html>
