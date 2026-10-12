@php
    $settingsLinks = [
        ['id' => 'profile', 'label' => 'الملف الشخصي', 'hint' => 'بياناتك الشخصية', 'icon' => 'user'],
        ['id' => 'password', 'label' => 'كلمة المرور', 'hint' => 'تحديث كلمة المرور', 'icon' => 'lock'],
        ['id' => 'security', 'label' => 'الأمان', 'hint' => 'إعدادات الأمان', 'icon' => 'shield'],
        ['id' => 'account', 'label' => 'الحساب', 'hint' => 'المنطقة المتقدمة', 'icon' => 'alert'],
    ];

    $settingsIcons = [
        'user' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />',
        'lock' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16.5 10.5V8.25a4.5 4.5 0 10-9 0v2.25m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />',
        'shield' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3 5 6v5c0 5 3.4 8.5 7 10 3.6-1.5 7-5 7-10V6l-7-3Z" />',
        'alert' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v4m0 4h.01M10.29 4.86 1.82 19a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 4.86a2 2 0 00-3.42 0Z" />',
    ];
@endphp

<nav class="hidden w-64 shrink-0 lg:block" aria-label="إعدادات الحساب">
    <div class="sticky top-24 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
        @foreach($settingsLinks as $link)
            <a
                href="#{{ $link['id'] }}"
                @click="section = '{{ $link['id'] }}'"
                class="flex items-center gap-3 rounded-xl px-3 py-2.5 transition"
                :class="section === '{{ $link['id'] }}' ? 'bg-[#E8FAF6] text-[#067e6b]' : 'text-slate-700 hover:bg-slate-50'"
            >
                <span
                    class="flex h-9 w-9 items-center justify-center rounded-xl"
                    :class="section === '{{ $link['id'] }}' ? 'bg-[#06C2A4] text-white' : 'bg-slate-100 text-slate-600'"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">{!! $settingsIcons[$link['icon']] !!}</svg>
                </span>
                <span>
                    <span class="block text-sm font-bold">{{ $link['label'] }}</span>
                    <span class="mt-0.5 block text-[11px] font-medium text-slate-500">{{ $link['hint'] }}</span>
                </span>
            </a>
        @endforeach
    </div>
</nav>

<nav class="mb-4 flex gap-2 overflow-x-auto lg:hidden" aria-label="إعدادات الحساب">
    @foreach($settingsLinks as $link)
        <a
            href="#{{ $link['id'] }}"
            @click="section = '{{ $link['id'] }}'"
            class="inline-flex shrink-0 items-center gap-2 rounded-full px-3 py-2 text-sm font-bold transition"
            :class="section === '{{ $link['id'] }}' ? 'bg-[#E8FAF6] text-[#067e6b]' : 'bg-white text-slate-600 border border-slate-200'"
        >
            {{ $link['label'] }}
        </a>
    @endforeach
</nav>
