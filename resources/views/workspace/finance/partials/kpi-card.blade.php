@php
    $tone = $tone ?? 'slate';
    $value = $value ?? 0;
    $hint = $hint ?? null;
    $delta = $delta ?? null;
    $direction = $direction ?? 0;
    $href = $href ?? null;
    $toneClass = match ($tone) {
        'emerald' => 'border-emerald-100 bg-gradient-to-br from-white to-emerald-50/60',
        'amber' => 'border-amber-100 bg-gradient-to-br from-white to-amber-50/60',
        'rose' => 'border-rose-100 bg-gradient-to-br from-white to-rose-50/50',
        'indigo' => 'border-indigo-100 bg-gradient-to-br from-white to-indigo-50/50',
        default => 'border-slate-200 bg-white',
    };
    $deltaClass = $direction > 0 ? 'text-emerald-700' : ($direction < 0 ? 'text-rose-600' : 'text-slate-500');
@endphp
@if($href)
    <a href="{{ $href }}" class="block rounded-2xl border {{ $toneClass }} p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
@else
    <article class="rounded-2xl border {{ $toneClass }} p-4 shadow-sm">
@endif
    <p class="text-xs font-semibold text-slate-500">{{ $label }}</p>
    <p class="mt-2 text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">
        {{ is_numeric($value) ? number_format((float) $value, 2) : $value }}
        @if(!empty($currency))
            <span class="text-sm font-semibold text-slate-500">{{ $currency }}</span>
        @endif
    </p>
    @if($delta !== null)
        <p class="mt-1 text-xs font-semibold {{ $deltaClass }}">
            {{ $direction > 0 ? '▲' : ($direction < 0 ? '▼' : '●') }}
            {{ number_format((float) $delta, 2) }} مقابل الفترة السابقة
        </p>
    @endif
    @if($hint)
        <p class="mt-1 text-[11px] leading-5 text-slate-500">{{ $hint }}</p>
    @endif
@if($href)
    </a>
@else
    </article>
@endif
