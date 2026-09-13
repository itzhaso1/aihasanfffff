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
    <body class="bg-white font-sans text-gray-900 antialiased">
        @php
            $isWorkspaceShell = request()->is('workspace*');
        @endphp

        @if($isWorkspaceShell)
            <div x-data="{ sidebarOpen: false }" class="min-h-screen bg-[#F7FCFB]">
                <aside class="fixed inset-y-0 right-0 z-40 hidden w-72 border-l border-gray-200 bg-white lg:flex lg:flex-col">
                    <div class="border-b border-gray-100 px-6 py-6">
                        <a href="{{ route('workspace.dashboard') }}" class="flex items-center gap-2">
                            <x-hasim-logo variant="mark" size="lg" />
                            <span class="text-2xl font-extrabold tracking-tight text-[#06C2A4]">حاسم</span>
                        </a>
                        <p class="mt-2 text-xs text-gray-500">لوحة تحكم الأعمال الذكية</p>
                    </div>
                    <nav class="flex-1 overflow-y-auto px-4 py-4">
                        @include('workspace.partials.sidebar-links')
                    </nav>
                </aside>

                <div class="min-h-screen lg:pr-72">
                    <header class="sticky top-0 z-30 border-b border-gray-200 bg-white/95 backdrop-blur">
                        <div class="flex h-16 items-center justify-between px-4 sm:px-6 lg:px-8">
                            <button @click="sidebarOpen = true" type="button" class="inline-flex items-center justify-center rounded-lg border border-gray-200 p-2 text-gray-600 transition hover:bg-gray-50 lg:hidden">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M4 6h16M4 12h16M4 18h16" />
                                </svg>
                            </button>
                            <a href="{{ route('workspace.dashboard') }}" class="hidden items-center gap-2 lg:flex">
                                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-[#E8F8F4] text-[#06C2A4]">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 10.75 12 3l9 7.75V21a1 1 0 0 1-1 1h-5.5v-7h-5v7H4a1 1 0 0 1-1-1V10.75Z" />
                                    </svg>
                                </span>
                                <span class="text-sm text-gray-500">منصة SaaS عربية احترافية</span>
                            </a>
                            <div class="flex items-center gap-2 sm:gap-3">
                                <a href="{{ route('profile.edit') }}" class="rounded-full border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 transition hover:border-[#06C2A4] hover:text-[#06C2A4]">اللغة</a>
                                <a href="{{ route('workspace.choose') }}" class="rounded-full border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 transition hover:border-[#06C2A4] hover:text-[#06C2A4]">المساحات</a>
                                <a href="{{ route('notifications.index') }}" class="relative inline-flex items-center gap-2 rounded-full border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 transition hover:border-[#06C2A4] hover:text-[#06C2A4]">
                                    الإشعارات
                                    <span class="relative text-gray-500">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0a3 3 0 1 1-6 0m6 0H9" />
                                        </svg>
                                        @if(Auth::user()?->unreadNotifications()->exists())
                                            <span class="absolute -top-1 -left-0.5 h-2 w-2 rounded-full bg-red-500"></span>
                                        @endif
                                    </span>
                                </a>
                                <x-dropdown align="left" width="56">
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
                        <aside x-transition class="absolute inset-y-0 right-0 w-72 overflow-y-auto border-l border-gray-200 bg-white">
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
                            <nav class="px-4 py-4">
                                @include('workspace.partials.sidebar-links')
                            </nav>
                        </aside>
                    </div>

                    @isset($header)
                        <section class="border-b border-gray-100 bg-white">
                            <div class="px-4 py-5 sm:px-6 lg:px-8">
                                {{ $header }}
                            </div>
                        </section>
                    @endisset

                    <main class="px-4 py-6 sm:px-6 lg:px-8">
                        {{ $slot }}
                    </main>

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

                <main>
                    {{ $slot }}
                </main>
            </div>
        @endif
        @include('partials.ai-assistant-widget')
    </body>
</html>
