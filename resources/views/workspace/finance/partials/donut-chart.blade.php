@php
    $labelKey = $labelKey ?? 'name';
    $valueKey = $valueKey ?? 'total';
    $slices = collect($slices ?? [])->map(function ($row) use ($labelKey, $valueKey) {
        $label = is_array($row) ? ($row[$labelKey] ?? '') : ($row->{$labelKey} ?? '');
        $value = (float) (is_array($row) ? ($row[$valueKey] ?? 0) : ($row->{$valueKey} ?? 0));

        return ['label' => $label, 'value' => $value];
    })->filter(fn ($row) => $row['value'] > 0)->values();
    $total = (float) $slices->sum('value');
    $colors = ['#06C2A4', '#5eead4', '#0f7668', '#99f6e4', '#14b8a6', '#334155', '#94a3b8', '#2dd4bf'];
    $radius = 56;
    $circumference = 2 * M_PI * $radius;
    $offset = 0;
@endphp

@if($slices->isEmpty() || $total <= 0)
    <p class="text-sm text-slate-500">لا توجد بيانات كافية لعرض هذا التوزيع.</p>
@else
    <div class="hs-fin-donut-wrap">
        <svg viewBox="0 0 160 160" class="max-w-[10rem]" role="img" aria-label="{{ $title ?? 'توزيع' }}">
            <circle cx="80" cy="80" r="{{ $radius }}" fill="none" stroke="#eef4f2" stroke-width="16"></circle>
            @foreach($slices as $index => $slice)
                @php
                    $length = ($slice['value'] / $total) * $circumference;
                    $color = $colors[$index % count($colors)];
                    $currentOffset = $offset;
                    $offset += $length;
                @endphp
                <circle
                    cx="80"
                    cy="80"
                    r="{{ $radius }}"
                    fill="none"
                    stroke="{{ $color }}"
                    stroke-width="16"
                    stroke-dasharray="{{ $length }} {{ $circumference - $length }}"
                    stroke-dashoffset="{{ -$currentOffset }}"
                    transform="rotate(-90 80 80)"
                    stroke-linecap="butt"
                >
                    <title>{{ $slice['label'] }} · {{ number_format($slice['value'], 2) }}</title>
                </circle>
            @endforeach
            <text x="80" y="76" text-anchor="middle" fill="#64748b" font-size="10">إجمالي الإيرادات</text>
            <text x="80" y="94" text-anchor="middle" fill="#0f172a" font-size="13" font-weight="800">{{ number_format($total, 2) }}</text>
        </svg>
        <div class="hs-fin-donut-list">
            @foreach($slices as $index => $slice)
                <div class="hs-fin-donut-row">
                    <span>
                        <i class="hs-fin-dot" style="background: {{ $colors[$index % count($colors)] }}"></i>
                        {{ $slice['label'] }}
                    </span>
                    <b>{{ number_format(($slice['value'] / $total) * 100, 0) }}%</b>
                </div>
            @endforeach
        </div>
    </div>
@endif
