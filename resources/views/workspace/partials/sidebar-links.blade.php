@php
    $sections = [
        [
            'title' => 'الرئيسية',
            'links' => [
                ['label' => 'لوحة التحكم', 'route' => 'workspace.dashboard', 'active' => 'workspace.dashboard', 'icon' => 'home'],
                ['label' => 'التحليلات', 'route' => 'workspace.analytics.index', 'active' => 'workspace.analytics.*', 'icon' => 'chart'],
            ],
        ],
        [
            'title' => 'الشات',
            'links' => [
                ['label' => 'المحادثات', 'route' => 'workspace.conversations.index', 'active' => 'workspace.conversations.*', 'icon' => 'chat'],
                ['label' => 'العملاء', 'route' => 'workspace.customers.index', 'active' => 'workspace.customers.*', 'icon' => 'users'],
                ['label' => 'القنوات', 'route' => 'workspace.channels.index', 'active' => 'workspace.channels.*', 'icon' => 'channels'],
                ['label' => 'واتساب', 'route' => 'workspace.whatsapp-accounts.index', 'active' => 'workspace.whatsapp-accounts.*', 'icon' => 'whatsapp'],
            ],
        ],
        [
            'title' => 'المنتجات',
            'links' => [
                ['label' => 'المنتجات والمخزون', 'route' => 'workspace.chat-catalog.index', 'active' => 'workspace.chat-catalog.*', 'icon' => 'cube'],
                ['label' => 'السلات', 'route' => 'workspace.commerce.carts.index', 'active' => 'workspace.commerce.carts.*', 'icon' => 'box'],
                ['label' => 'الطلبات', 'route' => 'workspace.commerce.orders.index', 'active' => 'workspace.commerce.orders.*', 'icon' => 'tag'],
            ],
        ],
        [
            'title' => 'البريد',
            'links' => [
                [
                    'label' => 'صندوق البريد',
                    'route' => 'workspace.emails.index',
                    'active' => [
                        'workspace.emails.index',
                        'workspace.emails.inbox',
                        'workspace.emails.sent',
                        'workspace.emails.compose',
                        'workspace.emails.messages.*',
                        'workspace.emails.logs.*',
                    ],
                    'icon' => 'mail',
                ],
                ['label' => 'الحسابات', 'route' => 'workspace.emails.accounts.index', 'active' => 'workspace.emails.accounts.*', 'icon' => 'bank'],
                ['label' => 'جهات الاتصال', 'route' => 'workspace.emails.contacts.index', 'active' => 'workspace.emails.contacts.*', 'icon' => 'users'],
                ['label' => 'قوالب البريد', 'route' => 'workspace.emails.templates.index', 'active' => 'workspace.emails.templates.*', 'icon' => 'template'],
                ['label' => 'هوية البريد', 'route' => 'workspace.emails.branding.edit', 'active' => 'workspace.emails.branding.*', 'icon' => 'id-card'],
            ],
        ],
        [
            'title' => 'الفريق',
            'links' => [
                ['label' => 'اكتشاف العمال', 'route' => 'workspace.worker-discovery.index', 'active' => 'workspace.worker-discovery.*', 'icon' => 'users'],
                ['label' => 'الارتباطات', 'route' => 'workspace.worker-engagements.index', 'active' => 'workspace.worker-engagements.*', 'icon' => 'link'],
                ['label' => 'النزاعات', 'route' => 'workspace.worker-disputes.index', 'active' => 'workspace.worker-disputes.*', 'icon' => 'alert'],
            ],
        ],
        [
            'title' => 'النظام',
            'links' => [
                ['label' => 'الذكاء الاصطناعي', 'route' => 'workspace.ai-settings.edit', 'active' => 'workspace.ai-settings.*', 'icon' => 'spark'],
                ['label' => 'الموارد البشرية', 'route' => 'workspace.employees.index', 'active' => 'workspace.employees.*', 'icon' => 'id-card'],
                ['label' => 'مفاتيح API', 'route' => 'workspace.api-keys.index', 'active' => 'workspace.api-keys.*', 'icon' => 'key'],
            ],
        ],
        [
            'title' => 'الفوترة',
            'links' => [
                ['label' => 'سجل المدفوعات', 'route' => 'workspace.payments.index', 'active' => 'workspace.payments.*', 'icon' => 'wallet'],
                ['label' => 'الاشتراكات', 'route' => 'workspace.subscriptions.index', 'active' => 'workspace.subscriptions.*', 'icon' => 'spark'],
            ],
        ],
        [
            'title' => 'الحساب',
            'links' => [
                ['label' => 'مساحة العمل', 'route' => 'workspace.choose', 'active' => 'workspace.choose', 'icon' => 'home'],
                ['label' => 'الملف الشخصي', 'route' => 'profile.edit', 'active' => 'profile.*', 'icon' => 'users'],
            ],
        ],
    ];

    foreach ($sections as &$section) {
        $section['links'] = collect($section['links'])
            ->filter(fn (array $link): bool => \Illuminate\Support\Facades\Route::has($link['route']))
            ->values()
            ->all();
        $section['is_active'] = collect($section['links'])->contains(function (array $link): bool {
            $patterns = (array) ($link['active'] ?? $link['route']);

            return request()->routeIs(...$patterns);
        });
    }
    unset($section);

    $icons = [
        'home' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 10.75 12 3l9 7.75V21a1 1 0 0 1-1 1h-5.5v-7h-5v7H4a1 1 0 0 1-1-1V10.75Z" />',
        'chart' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 19.5h18M6.75 16.5V10.5m5.25 6V6.75m5.25 9.75V8.25" />',
        'chat' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M7.5 17.25H4.875A1.875 1.875 0 0 1 3 15.375V5.625A1.875 1.875 0 0 1 4.875 3.75h14.25A1.875 1.875 0 0 1 21 5.625v9.75a1.875 1.875 0 0 1-1.875 1.875H12l-4.5 3v-3Z" />',
        'users' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M16 19.5v-1.125A3.375 3.375 0 0 0 12.625 15h-4.25A3.375 3.375 0 0 0 5 18.375V19.5m11-9.375a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Zm-8.75 0a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />',
        'channels' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 0 1 1.06 0Z" />',
        'whatsapp' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M8.25 9.75c.75 2.25 2.75 4.25 5 5l1.5-1.5a1.5 1.5 0 0 1 1.5-.37l3.12.94A1.5 1.5 0 0 1 20.5 15.3v2.45A1.75 1.75 0 0 1 18.6 19.5 16.1 16.1 0 0 1 4.5 5.4 1.75 1.75 0 0 1 6.25 3.5H8.7a1.5 1.5 0 0 1 1.43 1.13l.94 3.12a1.5 1.5 0 0 1-.37 1.5L8.25 9.75Z" />',
        'cube' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M21 7.5 12 3 3 7.5m18 0-9 4.5m9-4.5v9L12 21m0-9.5L3 7.5m9 4v9.5m0 0L3 16.5v-9" />',
        'box' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="m3.75 7.5 8.25-4.5 8.25 4.5m-16.5 0 8.25 4.5m8.25-4.5v9L12 21.75m0-9.75v9.75" />',
        'tag' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M6 6h.008v.008H6V6Z" />',
        'mail' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 6.75A1.75 1.75 0 0 1 4.75 5h14.5A1.75 1.75 0 0 1 21 6.75v10.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75Zm1.75.35L12 12l7.25-4.9" />',
        'template' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M7.5 3.75h9A2.25 2.25 0 0 1 18.75 6v12A2.25 2.25 0 0 1 16.5 20.25h-9A2.25 2.25 0 0 1 5.25 18V6A2.25 2.25 0 0 1 7.5 3.75ZM8.25 8.25h7.5M8.25 12h7.5M8.25 15.75h4.5" />',
        'id-card' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h12A2.25 2.25 0 0 1 20.25 6v12A2.25 2.25 0 0 1 18 20.25H6A2.25 2.25 0 0 1 3.75 18V6Zm4.5 3.75a2.25 2.25 0 1 0 4.5 0 2.25 2.25 0 0 0-4.5 0Zm-.75 6h6m1.5-6h2.25m-2.25 3h2.25m-2.25 3h2.25" />',
        'link' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />',
        'alert' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />',
        'spark' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 3.75 13.9 8.1 18.25 10l-4.35 1.9L12 16.25l-1.9-4.35L5.75 10l4.35-1.9L12 3.75Z" />',
        'key' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M15.75 5.25a3.75 3.75 0 1 1-7.13 2.287L3 13.157V17.25h4.093l1.06-1.06v-2.28h2.28L15.75 8.593A3.74 3.74 0 0 1 15.75 5.25Z" />',
        'wallet' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M2.25 7.5A2.25 2.25 0 0 1 4.5 5.25h15A2.25 2.25 0 0 1 21.75 7.5v9A2.25 2.25 0 0 1 19.5 18.75h-15A2.25 2.25 0 0 1 2.25 16.5v-9Zm15.75 4.5h3.75v3H18a1.5 1.5 0 0 1 0-3Z" />',
        'bank' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 9.75 12 4.5l9 5.25M4.5 10.5V18m5.25-7.5V18m5.25-7.5V18m5.25-7.5V18M3 21h18" />',
    ];
@endphp

<nav class="flex flex-col gap-6" aria-label="أقسام المنصة">
    @foreach($sections as $section)
        @continue(empty($section['links']))
        <section class="flex flex-col gap-1">
            <h3 @class([
                'px-3 text-[11px] font-extrabold tracking-wide',
                'text-[#067e6b]' => $section['is_active'],
                'text-slate-400' => ! $section['is_active'],
            ])>{{ $section['title'] }}</h3>
            <div class="flex flex-col gap-0.5">
                @foreach($section['links'] as $link)
                    @php
                        $isActive = request()->routeIs(...(array) ($link['active'] ?? $link['route']));
                    @endphp
                    <a
                        href="{{ route($link['route']) }}"
                        @class([
                            'group flex items-center gap-3 rounded-xl px-3 py-2 text-[13px] font-semibold transition',
                            'bg-[#E8F8F4] text-[#067e6b] shadow-[0_1px_0_rgba(6,194,164,0.12)]' => $isActive,
                            'text-slate-600 hover:bg-slate-50 hover:text-slate-900' => ! $isActive,
                        ])
                    >
                        <span @class([
                            'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                            'bg-white text-[#06C2A4] shadow-sm' => $isActive,
                            'bg-slate-100 text-slate-500 group-hover:bg-white group-hover:text-slate-700' => ! $isActive,
                        ])>
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $icons[$link['icon']] ?? '' !!}</svg>
                        </span>
                        <span class="min-w-0 flex-1 truncate">{{ $link['label'] }}</span>
                        @if($isActive)
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-[#06C2A4]"></span>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach
</nav>
