@php
    $label = $label ?? $status ?? '';
    $class = $class ?? 'bg-slate-100 text-slate-700';
@endphp
<span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-bold {{ $class }}">{{ $label }}</span>
