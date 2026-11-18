@php
    $sections = [
        [
            'title' => 'الرئيسية',
            'links' => [
                ['label' => 'لوحة التحكم', 'route' => 'workspace.dashboard', 'active' => ['workspace.dashboard'], 'icon' => 'home'],
                ['label' => 'التحليلات والتقارير', 'route' => 'workspace.analytics.index', 'active' => ['workspace.analytics.*'], 'icon' => 'chart'],
            ],
        ],
        [
            'title' => 'التواصل والمبيعات',
            'links' => [
                ['label' => 'المحادثات', 'route' => 'workspace.conversations.index', 'active' => ['workspace.conversations.*'], 'icon' => 'chat'],
                ['label' => 'العملاء (CRM)', 'route' => 'workspace.customers.index', 'active' => ['workspace.customers.*'], 'icon' => 'users'],
                ['label' => 'الطلبات', 'route' => 'workspace.commerce.orders.index', 'active' => ['workspace.commerce.orders.*'], 'icon' => 'order'],
                ['label' => 'سلات الشراء', 'route' => 'workspace.commerce.carts.index', 'active' => ['workspace.commerce.carts.*'], 'icon' => 'cart'],
            ],
        ],
        [
            'title' => 'الكتالوج والتجارة',
            'links' => [
                ['label' => 'المنتجات والمخزون', 'route' => 'workspace.chat-catalog.index', 'active' => ['workspace.chat-catalog.*'], 'icon' => 'cube'],
            ],
        ],
        [
            'title' => 'موظفو الذكاء الاصطناعي',
            'links' => [
                ['label' => 'الموظف الذكي (مشعل)', 'route' => 'workspace.ai-settings.edit', 'active' => ['workspace.ai-settings.*'], 'icon' => 'spark'],
            ],
        ],
        [
            'title' => 'القنوات والربط',
            'links' => [
                ['label' => 'القنوات المتصلة', 'route' => 'workspace.channels.index', 'active' => ['workspace.channels.*'], 'icon' => 'channels'],
                ['label' => 'حسابات واتساب', 'route' => 'workspace.whatsapp-accounts.index', 'active' => ['workspace.whatsapp-accounts.*'], 'icon' => 'whatsapp'],
            ],
        ],
        [
            'title' => 'الإدارة والنظام',
            'links' => [
                ['label' => 'فريق العمل', 'route' => 'workspace.employees.index', 'active' => ['workspace.employees.*'], 'icon' => 'team'],
                ['label' => 'الاشتراكات والفوترة', 'route' => 'workspace.subscriptions.index', 'active' => ['workspace.subscriptions.*', 'workspace.payments.*'], 'icon' => 'wallet'],
                ['label' => 'مفاتيح API', 'route' => 'workspace.api-keys.index', 'active' => ['workspace.api-keys.*'], 'icon' => 'key'],
            ],
        ],
    ];

    $icons = [
        'home' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />',
        'chart' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />',
        'chat' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.502 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.27Z" />',
        'users' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />',
        'order' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />',
        'cart' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />',
        'cube' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />',
        'spark' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" />',
        'channels' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />',
        'whatsapp' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8.25 9.75c.75 2.25 2.75 4.25 5 5l1.5-1.5a1.5 1.5 0 0 1 1.5-.37l3.12.94A1.5 1.5 0 0 1 20.5 15.3v2.45A1.75 1.75 0 0 1 18.6 19.5 16.1 16.1 0 0 1 4.5 5.4 1.75 1.75 0 0 1 6.25 3.5H8.7a1.5 1.5 0 0 1 1.43 1.13l.94 3.12a1.5 1.5 0 0 1-.37 1.5L8.25 9.75Z" />',
        'team' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.999-3.199a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />',
        'wallet' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 12a2.25 2.25 0 0 0-2.25-2.25H15a3 3 0 1 1-6 0H5.25A2.25 2.25 0 0 0 3 12m18 0v6a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 9m18 0V6a2.25 2.25 0 0 0-2.25-2.25H5.25A2.25 2.25 0 0 0 3 6v3" />',
        'key' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" />',
    ];
@endphp

<nav class="flex flex-col gap-5" aria-label="أقسام المنصة">
    @foreach($sections as $section)
        @php
            $visibleLinks = collect($section['links'])->filter(fn($l) => \Illuminate\Support\Facades\Route::has($l['route']))->values();
        @endphp
        @continue($visibleLinks->isEmpty())
        
        <div class="flex flex-col gap-1">
            <h3 
                x-show="!sidebarCollapsed"
                class="px-3 text-[11px] font-bold uppercase tracking-wider text-slate-400 select-none"
            >
                {{ $section['title'] }}
            </h3>
            <div class="flex flex-col gap-0.5">
                @foreach($visibleLinks as $link)
                    @php
                        $isActive = request()->routeIs(...$link['active']);
                    @endphp
                    <a
                        href="{{ route($link['route']) }}"
                        :title="sidebarCollapsed ? '{{ $link['label'] }}' : ''"
                        @class([
                            'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-xs font-semibold transition-all duration-150',
                            'bg-[#E8FAF6] text-[#067E6B] shadow-sm' => $isActive,
                            'text-slate-600 hover:bg-slate-100/70 hover:text-slate-900' => ! $isActive,
                        ])
                    >
                        @if($isActive)
                            <span class="absolute inset-y-1.5 start-0 w-1 rounded-full bg-[#06C2A4]"></span>
                        @endif

                        <span @class([
                            'flex h-7 w-7 shrink-0 items-center justify-center rounded-lg transition-colors',
                            'text-[#06C2A4]' => $isActive,
                            'text-slate-400 group-hover:text-slate-600' => ! $isActive,
                        ])>
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                {!! $icons[$link['icon']] ?? '' !!}
                            </svg>
                        </span>

                        <span 
                            x-show="!sidebarCollapsed" 
                            class="truncate transition-opacity"
                        >
                            {{ $link['label'] }}
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
</nav>
