@php
    $points = collect($points ?? []);
    $valueKey = $valueKey ?? 'value';
    $labelKey = $labelKey ?? 'month';
    $max = max(1, (float) $points->max(function ($point) use ($valueKey) {
        $amount = is_array($point) ? ($point[$valueKey] ?? 0) : ($point->{$valueKey} ?? 0);

        return abs((float) $amount);
    }));
@endphp
<div class="space-y-2">
    @forelse($points as $point)
        @php
            $amount = (float) (is_array($point) ? ($point[$valueKey] ?? 0) : ($point->{$valueKey} ?? 0));
            $label = is_array($point) ? ($point[$labelKey] ?? '') : ($point->{$labelKey} ?? '');
            $width = min(100, (abs($amount) / $max) * 100);
        @endphp
        <div>
            <div class="mb-1 flex items-center justify-between gap-3 text-xs text-slate-500">
                <span class="truncate font-medium text-slate-700">{{ $label }}</span>
                <span class="shrink-0 font-semibold {{ $amount < 0 ? 'text-rose-700' : 'text-slate-900' }}">{{ number_format($amount, 2) }}</span>
            </div>
            <div class="h-2.5 overflow-hidden rounded-full bg-slate-100">
                <div class="h-2.5 rounded-full {{ $amount < 0 ? 'bg-rose-400' : 'bg-[#06C2A4]' }}" style="width: {{ $width }}%"></div>
            </div>
        </div>
    @empty
        <p class="text-sm text-slate-500">لا توجد بيانات لهذه الفترة.</p>
    @endforelse
</div>
