@php
    $hsUser = auth()->user();
    $isPurchaseInvoices = request()->routeIs('workspace.finance.invoices.*') && request('type') === 'purchase';

    $leaves = [
        [
            'label' => 'لوحة المالية',
            'route' => 'workspace.finance.dashboard',
            'active' => request()->routeIs('workspace.finance.dashboard'),
            'icon' => 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
        ],
        [
            'label' => 'لوحة الفوترة',
            'route' => 'workspace.finance.billing.dashboard',
            'active' => request()->routeIs('workspace.finance.billing.*'),
            'icon' => 'M7 3h10a2 2 0 012 2v14l-3.5-1.6L12 19l-3.5-1.6L5 19V5a2 2 0 012-2z',
        ],
    ];

    $groups = [
        'sales' => [
            'label' => 'المبيعات',
            'icon' => 'M4 17l5-6 4 3 7-8M14 6h6v6',
            'links' => [
                ['label' => 'المبيعات', 'route' => 'workspace.finance.sales.index', 'active' => 'workspace.finance.sales.*', 'icon' => 'M4 17l5-6 4 3 7-8'],
                ['label' => 'عروض الأسعار', 'route' => 'workspace.finance.quotes.index', 'active' => 'workspace.finance.quotes.*', 'icon' => 'M8 3h8a2 2 0 012 2v14H6V5a2 2 0 012-2zm2 6h4M9 13h6'],
                ['label' => 'الفواتير', 'route' => 'workspace.finance.invoices.index', 'active' => 'workspace.finance.invoices.*', 'icon' => 'M7 3h10v18H7zM10 8h4M10 12h4'],
                ['label' => 'العقود', 'route' => 'workspace.finance.contracts.index', 'active' => 'workspace.finance.contracts.*', 'icon' => 'M8 12h8M8 8h8M7 4h10a2 2 0 012 2v14H5V6a2 2 0 012-2z'],
                ['label' => 'قوائم الأسعار', 'route' => 'workspace.finance.price-lists.index', 'active' => 'workspace.finance.price-lists.*', 'icon' => 'M4 7h16M7 7v10a2 2 0 002 2h6a2 2 0 002-2V7'],
                ['label' => 'العملاء المحتملون', 'route' => 'workspace.finance.leads.index', 'active' => 'workspace.finance.leads.*', 'icon' => 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0'],
            ],
        ],
        'payments' => [
            'label' => 'المدفوعات',
            'icon' => 'M4 10h16M6 7h12a2 2 0 012 2v8a2 2 0 01-2 2H6a2 2 0 01-2-2V9a2 2 0 012-2z',
            'links' => [
                ['label' => 'المدفوعات', 'route' => 'workspace.finance.payments.index', 'active' => 'workspace.finance.payments.*', 'icon' => 'M4 10h16M6 7h12a2 2 0 012 2v8a2 2 0 01-2 2H6a2 2 0 01-2-2V9a2 2 0 012-2z'],
                ['label' => 'الإيصالات', 'route' => 'workspace.finance.receipts.index', 'active' => 'workspace.finance.receipts.*', 'icon' => 'M8 4h8l2 3v13H6V7zM9 12l2 2 4-4'],
                ['label' => 'كشوف الحساب', 'route' => 'workspace.finance.statements.index', 'active' => 'workspace.finance.statements.*', 'icon' => 'M4 7h16M4 12h16M4 17h10'],
            ],
        ],
        'parties' => [
            'label' => 'العملاء والموردون',
            'icon' => 'M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M10 11a4 4 0 100-8 4 4 0 000 8zM20 8v6m3-3h-6',
            'links' => [
                ['label' => 'العملاء', 'route' => 'workspace.finance.customers.index', 'active' => 'workspace.finance.customers.*', 'icon' => 'M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M10 11a4 4 0 100-8 4 4 0 000 8z'],
                ['label' => 'الموردون', 'route' => 'workspace.finance.suppliers.index', 'active' => 'workspace.finance.suppliers.*', 'icon' => 'M4 10l8-6 8 6v10H4zM10 20v-6h4v6'],
            ],
        ],
        'purchases' => [
            'label' => 'المشتريات',
            'icon' => 'M6 7h15l-1.4 8H7.2L6 7zm0 0L5 4H2m5 16a1.2 1.2 0 100-2.4A1.2 1.2 0 007 20zm10 0a1.2 1.2 0 100-2.4A1.2 1.2 0 0017 20z',
            'links' => [
                ['label' => 'المشتريات', 'route' => 'workspace.finance.purchases.index', 'active' => 'workspace.finance.purchases.*', 'icon' => 'M6 7h15l-1.4 8H7.2L6 7zm0 0L5 4H2'],
                ['label' => 'أوامر الشراء', 'route' => 'workspace.finance.purchase-orders.index', 'active' => 'workspace.finance.purchase-orders.*', 'icon' => 'M4 7h16v12H4zM8 7V5h8v2'],
            ],
        ],
        'ops' => [
            'label' => 'المخزون',
            'icon' => 'M4 8l8-4 8 4v8l-8 4-8-4zM4 8l8 4 8-4',
            'links' => [
                ['label' => 'المصروفات', 'route' => 'workspace.finance.expenses.index', 'active' => 'workspace.finance.expenses.*', 'icon' => 'M12 8c-2.5 0-4 1.2-4 2.8S9.5 13.6 12 13.6s4 1.2 4 2.8S14.5 19.2 12 19.2 8 18 8 16.4M12 6v2m0 12v-2'],
                ['label' => 'المنتجات', 'route' => 'workspace.finance.products.index', 'active' => 'workspace.finance.products.*', 'icon' => 'M4 8l8-4 8 4v8l-8 4-8-4z'],
                ['label' => 'المخزون', 'route' => 'workspace.finance.inventory.index', 'active' => 'workspace.finance.inventory.*', 'icon' => 'M3 8h18M5 8v11h14V8M9 12h6'],
                ['label' => 'المشاريع', 'route' => 'workspace.finance.projects.index', 'active' => 'workspace.finance.projects.*', 'icon' => 'M4 6h7l2 2h7v10H4z'],
            ],
        ],
        'reports' => [
            'label' => 'التقارير',
            'icon' => 'M4 19V5m0 14h16M8 15V9m4 6V7m4 8v-4',
            'links' => [
                ['label' => 'التقارير', 'route' => 'workspace.finance.reports.index', 'active' => 'workspace.finance.reports.*', 'icon' => 'M4 19V5m0 14h16M8 15V9m4 6V7m4 8v-4'],
                ['label' => 'تصدير CSV', 'route' => 'workspace.finance.exports.index', 'active' => 'workspace.finance.exports.*', 'icon' => 'M12 4v10m0 0l-3-3m3 3l3-3M5 18h14'],
                ['label' => 'التنبيهات', 'route' => 'workspace.finance.alerts.index', 'active' => 'workspace.finance.alerts.*', 'icon' => 'M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0a3 3 0 11-6 0'],
                ['label' => 'المساعد المالي', 'route' => 'workspace.finance.assistant.index', 'active' => ['workspace.finance.assistant.*', 'workspace.finance.copilot.*'], 'icon' => 'M12 3a7 7 0 00-7 7c0 3 2 5 3 6h8c1-1 3-3 3-6a7 7 0 00-7-7zM10 20h4'],
            ],
        ],
        'accounting' => [
            'label' => 'المحاسبة',
            'icon' => 'M4 7h16M4 12h16M4 17h10',
            'links' => [
                ['label' => 'لوحة المحاسبة', 'route' => 'workspace.finance.accounting.dashboard', 'active' => 'workspace.finance.accounting.*', 'icon' => 'M4 7h16M4 12h16M4 17h10'],
                ['label' => 'السنوات والفترات', 'route' => 'workspace.finance.fiscal-years.index', 'active' => 'workspace.finance.fiscal-years.*', 'icon' => 'M8 3v3M16 3v3M4 9h16M6 5h12a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V7a2 2 0 012-2z'],
                ['label' => 'VAT', 'route' => 'workspace.finance.vat.index', 'active' => 'workspace.finance.vat.*', 'icon' => 'M18 6L6 18M8 8a3 3 0 116 0 3 3 0 01-6 0zM10 16a3 3 0 106 0 3 3 0 00-6 0z'],
            ],
        ],
        'banks' => [
            'label' => 'البنوك والخزينة',
            'icon' => 'M4 10l8-6 8 6M6 10v8h12v-8M4 18h16',
            'links' => [
                ['label' => 'الحسابات البنكية', 'route' => 'workspace.finance.banks.index', 'active' => 'workspace.finance.banks.*', 'icon' => 'M4 10l8-6 8 6M6 10v8h12v-8'],
                ['label' => 'الخزينة والتحويلات', 'route' => 'workspace.finance.treasury.index', 'active' => 'workspace.finance.treasury.*', 'icon' => 'M7 12h10M7 12l3-3M17 12l-3 3'],
            ],
        ],
        'people' => [
            'label' => 'الموظفون والمستحقات',
            'icon' => 'M8 7V4h8v3M6 7h12v13H6zM10 12h4',
            'links' => [
                ['label' => 'الموظفون والمستحقات', 'route' => 'workspace.finance.employees.index', 'active' => 'workspace.finance.employees.*', 'icon' => 'M8 7V4h8v3M6 7h12v13H6z'],
                ['label' => 'الرواتب والمستحقات', 'route' => 'workspace.finance.payroll.index', 'active' => 'workspace.finance.payroll.*', 'icon' => 'M4 10h16M6 7h12a2 2 0 012 2v8a2 2 0 01-2 2H6a2 2 0 01-2-2V9a2 2 0 012-2z'],
                ['label' => 'السلف', 'route' => 'workspace.finance.salary-advances.index', 'active' => 'workspace.finance.salary-advances.*', 'icon' => 'M12 5v14M5 12h14'],
                ['label' => 'البدلات', 'route' => 'workspace.finance.allowances.index', 'active' => 'workspace.finance.allowances.*', 'icon' => 'M4 8h16v10H4zM8 8V6h8v2'],
                ['label' => 'المكافآت', 'route' => 'workspace.finance.bonuses.index', 'active' => 'workspace.finance.bonuses.*', 'icon' => 'M8 10l4-4 4 4M6 14h12'],
                ['label' => 'الخصومات', 'route' => 'workspace.finance.deductions.index', 'active' => 'workspace.finance.deductions.*', 'icon' => 'M8 12h8M12 8v8'],
            ],
        ],
    ];

    $settings = [
        'label' => 'الإعدادات',
        'route' => 'workspace.finance.settings.index',
        'active' => request()->routeIs('workspace.finance.settings.*'),
        'icon' => 'M12 15.5a3.5 3.5 0 100-7 3.5 3.5 0 000 7zM19.4 13a7.8 7.8 0 00.1-1l2-1.6-2-3.4-2.4 1a8 8 0 00-1.7-1L15 4h-6l-.4 2.6a8 8 0 00-1.7 1l-2.4-1-2 3.4 2 1.6a7.8 7.8 0 000 2l-2 1.6 2 3.4 2.4-1a8 8 0 001.7 1L9 20h6l.4-2.6a8 8 0 001.7-1l2.4 1 2-3.4-2-1.6c0-.3.1-.7.1-1z',
    ];

    $isLinkActive = function (array $link) use ($isPurchaseInvoices): bool {
        $active = $link['active'] ?? $link['route'];
        $isActive = is_array($active)
            ? collect($active)->contains(fn (string $pattern): bool => request()->routeIs($pattern))
            : request()->routeIs($active);

        if (($link['route'] ?? '') === 'workspace.finance.invoices.index') {
            return request()->routeIs('workspace.finance.invoices.*') && ! $isPurchaseInvoices;
        }

        return $isActive;
    };

    $sectionStates = [];
    foreach ($groups as $sectionKey => $section) {
        $sectionStates[$sectionKey] = collect($section['links'])->contains(fn ($link) => $isLinkActive($link));
    }
@endphp

<div class="hs-fin-nav" x-data='{ openSections: @json($sectionStates) }'>
    <div class="hs-fin-brand">
        <span class="hs-fin-brand-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 10h16M6 7h12a2 2 0 012 2v8a2 2 0 01-2 2H6a2 2 0 01-2-2V9a2 2 0 012-2z" />
            </svg>
        </span>
        <div>
            <p class="hs-fin-brand-name">حاسم المالية</p>
            <p class="hs-fin-brand-sub">{{ $hsUser?->name }}</p>
        </div>
    </div>

    <nav class="hs-fin-scroll" aria-label="تنقل الفوترة">
        @foreach($leaves as $item)
            <a href="{{ route($item['route']) }}" class="hs-fin-leaf {{ $item['active'] ? 'is-active' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="{{ $item['icon'] }}" />
                </svg>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach

        <div class="hs-fin-divider" role="separator"></div>

        @foreach($groups as $sectionKey => $section)
            @php $parentActive = $sectionStates[$sectionKey] ?? false; @endphp
            <div class="hs-fin-group">
                <button
                    type="button"
                    class="hs-fin-parent"
                    @click="openSections['{{ $sectionKey }}'] = !openSections['{{ $sectionKey }}']"
                    :class="{
                        'is-open': openSections['{{ $sectionKey }}'],
                        'has-active': !openSections['{{ $sectionKey }}'] && {{ $parentActive ? 'true' : 'false' }}
                    }"
                    :aria-expanded="openSections['{{ $sectionKey }}'] ? 'true' : 'false'"
                >
                    <svg class="hs-fin-parent-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="{{ $section['icon'] }}" />
                    </svg>
                    <span>{{ $section['label'] }}</span>
                    <svg class="hs-fin-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                <div class="hs-fin-children" x-cloak x-show="openSections['{{ $sectionKey }}']" x-transition>
                    @foreach($section['links'] as $link)
                        @php $isActive = $isLinkActive($link); @endphp
                        <a href="{{ route($link['route'], $link['params'] ?? []) }}" class="hs-fin-child {{ $isActive ? 'is-active' : '' }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="{{ $link['icon'] }}" />
                            </svg>
                            <span>{{ $link['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="hs-fin-divider" role="separator"></div>

        <a href="{{ route($settings['route']) }}" class="hs-fin-leaf {{ $settings['active'] ? 'is-active' : '' }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="{{ $settings['icon'] }}" />
            </svg>
            <span>{{ $settings['label'] }}</span>
        </a>
    </nav>
</div>
