@php
    $modules = [
        [
            'key' => 'home',
            'title' => 'الرئيسية',
            'description' => 'نظرة عامة على أعمالك',
            'icon' => 'home',
            'links' => [
                ['label' => 'Dashboard', 'route' => 'workspace.dashboard', 'active' => 'workspace.dashboard'],
                ['label' => 'التحليلات', 'route' => 'workspace.analytics.index', 'active' => 'workspace.analytics.*'],
            ],
        ],
        [
            'key' => 'pos-cashier',
            'title' => 'POS / الإدارة',
            'description' => 'الطاولات، QR Menu، والفواتير السحابية.',
            'icon' => 'wallet',
            'links' => [
                ['label' => 'الطاولات / QR', 'route' => 'workspace.pos.tables.index', 'active' => 'workspace.pos.*'],
            ],
        ],
        [
            'key' => 'communication',
            'title' => 'التواصل',
            'description' => 'المحادثات والعملاء وكتالوج الشات.',
            'icon' => 'chat',
            'links' => [
                ['label' => 'المحادثات', 'route' => 'workspace.conversations.index', 'active' => 'workspace.conversations.*'],
                ['label' => 'العملاء', 'route' => 'workspace.customers.index', 'active' => 'workspace.customers.*'],
                ['label' => 'كتالوج الشات', 'route' => 'workspace.chat-catalog.index', 'active' => 'workspace.chat-catalog.*'],
                ['label' => 'Channels', 'route' => 'workspace.channels.index', 'active' => 'workspace.channels.*'],
                ['label' => 'البريد الإلكتروني', 'route' => 'workspace.emails.index', 'active' => 'workspace.emails.*'],
                ['label' => 'قوالب البريد', 'route' => 'workspace.emails.templates.index', 'active' => 'workspace.emails.templates.*'],
                ['label' => 'هوية البريد الإلكتروني', 'route' => 'workspace.emails.branding.edit', 'active' => 'workspace.emails.branding.*'],
                ['label' => 'واتساب', 'route' => 'workspace.whatsapp-accounts.index', 'active' => 'workspace.whatsapp-accounts.*'],
            ],
        ],
        [
            'key' => 'finance',
            'title' => 'الفوترة والحسابات',
            'description' => 'وحدة مالية مستقلة للفواتير والتقارير.',
            'icon' => 'bank',
            'links' => [
                ['label' => 'الفوترة والحسابات', 'route' => 'workspace.finance.dashboard', 'active' => 'workspace.finance.*'],
                ['label' => 'العقود', 'route' => 'workspace.finance.contracts.index', 'active' => 'workspace.finance.contracts.*'],
            ],
        ],
        [
            'key' => 'settings',
            'title' => 'الإعدادات',
            'description' => 'إعدادات النظام والفريق والفوترة.',
            'icon' => 'settings',
            'links' => [
                ['label' => 'الذكاء الاصطناعي', 'route' => 'workspace.ai-settings.edit', 'active' => 'workspace.ai-settings.*'],
                ['label' => 'المدفوعات', 'route' => 'workspace.payments.index', 'active' => 'workspace.payments.*'],
                ['label' => 'بوابات الدفع', 'route' => 'workspace.payment-gateways.index', 'active' => 'workspace.payment-gateways.*'],
                ['label' => 'الاشتراكات', 'route' => 'workspace.subscriptions.index', 'active' => 'workspace.subscriptions.*'],
                ['label' => 'الموارد البشرية', 'route' => 'workspace.employees.index', 'active' => 'workspace.employees.*'],
                ['label' => 'مفاتيح API', 'route' => 'workspace.api-keys.index', 'active' => 'workspace.api-keys.*'],
                ['label' => 'مساحة العمل', 'route' => 'workspace.choose', 'active' => 'workspace.choose'],
                ['label' => 'الملف الشخصي', 'route' => 'profile.edit', 'active' => 'profile.*'],
            ],
        ],
    ];

    foreach ($modules as &$module) {
        $module['links'] = collect($module['links'])
            ->filter(fn (array $link): bool => \Illuminate\Support\Facades\Route::has($link['route']))
            ->values()
            ->all();
        $module['is_active'] = collect($module['links'])->contains(
            fn (array $link): bool => request()->routeIs($link['active'] ?? $link['route'])
        );
    }
    unset($module);

    $icons = [
        'home' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 10.75 12 3l9 7.75V21a1 1 0 0 1-1 1h-5.5v-7h-5v7H4a1 1 0 0 1-1-1V10.75Z" />',
        'box' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="m3.75 7.5 8.25-4.5 8.25 4.5m-16.5 0 8.25 4.5m8.25-4.5v9L12 21.75m0-9.75v9.75" />',
        'users' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M16 19.5v-1.125A3.375 3.375 0 0 0 12.625 15h-4.25A3.375 3.375 0 0 0 5 18.375V19.5m11-9.375a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Zm-8.75 0a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />',
        'chat' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M7.5 17.25H4.875A1.875 1.875 0 0 1 3 15.375V5.625A1.875 1.875 0 0 1 4.875 3.75h14.25A1.875 1.875 0 0 1 21 5.625v9.75a1.875 1.875 0 0 1-1.875 1.875H12l-4.5 3v-3Z" />',
        'wallet' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M2.25 7.5A2.25 2.25 0 0 1 4.5 5.25h15A2.25 2.25 0 0 1 21.75 7.5v9A2.25 2.25 0 0 1 19.5 18.75h-15A2.25 2.25 0 0 1 2.25 16.5v-9Zm15.75 4.5h3.75v3H18a1.5 1.5 0 0 1 0-3Z" />',
        'id-card' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h12A2.25 2.25 0 0 1 20.25 6v12A2.25 2.25 0 0 1 18 20.25H6A2.25 2.25 0 0 1 3.75 18V6Zm4.5 3.75a2.25 2.25 0 1 0 4.5 0 2.25 2.25 0 0 0-4.5 0Zm-.75 6h6m1.5-6h2.25m-2.25 3h2.25m-2.25 3h2.25" />',
        'bank' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 9.75 12 4.5l9 5.25M4.5 10.5V18m5.25-7.5V18m5.25-7.5V18m5.25-7.5V18M3 21h18" />',
        'spark' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 3.75 13.9 8.1 18.25 10l-4.35 1.9L12 16.25l-1.9-4.35L5.75 10l4.35-1.9L12 3.75Zm6.75 12.75 1.125 2.625L22.5 20.25l-2.625 1.125L18.75 24l-1.125-2.625L15 20.25l2.625-1.125L18.75 16.5Z" />',
        'settings' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />',
    ];

    $initialState = collect($modules)->mapWithKeys(function (array $module): array {
        return [$module['key'] => (bool) $module['is_active']];
    })->all();
@endphp

<div x-data='{ openModules: @json($initialState) }' class="space-y-3">
    @foreach($modules as $module)
        @continue(empty($module['links']))
        <section class="rounded-2xl border {{ $module['is_active'] ? 'border-[#C9F0E8] bg-[#E8F8F4]' : 'border-slate-200/80 bg-white/90' }} shadow-sm">
            <button
                type="button"
                @click="openModules['{{ $module['key'] }}'] = !openModules['{{ $module['key'] }}']"
                class="flex w-full items-start gap-3 rounded-2xl px-3 py-3 text-right transition {{ $module['is_active'] ? 'hover:bg-[#DDF6F0]' : 'hover:bg-slate-50' }}"
            >
                <span class="mt-0.5 flex h-9 w-9 items-center justify-center rounded-xl {{ $module['is_active'] ? 'bg-[#06C2A4] text-white' : 'bg-slate-100 text-slate-700' }}">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons[$module['icon']] ?? '' !!}</svg>
                </span>
                <span class="flex-1">
                    <span class="block text-sm font-bold {{ $module['is_active'] ? 'text-[#067e6b]' : 'text-slate-900' }}">{{ $module['title'] }}</span>
                    <span class="mt-0.5 block text-[11px] {{ $module['is_active'] ? 'text-[#4E8F84]' : 'text-slate-500' }}">{{ $module['description'] }}</span>
                </span>
                <svg class="mt-1 h-4 w-4 text-slate-400 transition-transform" :class="openModules['{{ $module['key'] }}'] ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>
            <div x-cloak x-show="openModules['{{ $module['key'] }}']" x-transition class="space-y-1 px-3 pb-3">
                @foreach($module['links'] as $link)
                    @php
                        $isActive = request()->routeIs($link['active'] ?? $link['route']);
                    @endphp
                    <a
                        href="{{ route($link['route']) }}"
                        class="{{ $isActive ? 'bg-white text-[#067e6b] shadow-sm' : 'text-slate-700 hover:bg-white/70' }} flex items-center justify-between rounded-xl px-3 py-2 text-sm font-medium transition"
                    >
                        <span>{{ $link['label'] }}</span>
                        @if($isActive)
                            <span class="h-1.5 w-1.5 rounded-full bg-[#06C2A4]"></span>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
