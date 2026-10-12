@php
    $title = $title ?? 'الأقسام';
    $items = $items ?? [];
@endphp
<aside class="hs-legal-toc min-w-0" aria-label="جدول المحتويات">
    <div class="hs-legal-mobile-toc mb-4" x-data="{ open: false }">
        <button type="button" class="hs-legal-toc-toggle" @click="open = !open" :aria-expanded="open.toString()">
            <span>محتويات الصفحة</span>
            <svg class="h-4 w-4 transition" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 9l-7 7-7-7" />
            </svg>
        </button>
        <nav x-cloak x-show="open" x-transition class="hs-legal-card mt-2 p-2">
            @foreach($items as $item)
                <a href="#{{ $item['id'] }}" @click="open = false">{{ $item['label'] }}</a>
            @endforeach
        </nav>
    </div>

    <nav class="hs-legal-desktop-toc hs-legal-card p-3">
        <p class="px-2 pb-2 text-sm font-black text-[#0f172a]">{{ $title }}</p>
        @foreach($items as $item)
            <a href="#{{ $item['id'] }}">{{ $item['label'] }}</a>
        @endforeach
    </nav>
</aside>
