@php
    $rows = collect($points ?? []);
    $leftKey = $leftKey ?? 'sales';
    $rightKey = $rightKey ?? 'expenses';
    $values = $rows->flatMap(function ($row) use ($leftKey, $rightKey) {
        $left = (float) (is_array($row) ? ($row[$leftKey] ?? 0) : ($row->{$leftKey} ?? 0));
        $right = (float) (is_array($row) ? ($row[$rightKey] ?? 0) : ($row->{$rightKey} ?? 0));

        return [$left, $right];
    });
    $max = max(1, (float) $values->max());
    $width = 560;
    $height = 220;
    $padX = 18;
    $padTop = 12;
    $padBottom = 28;
    $innerW = $width - ($padX * 2);
    $innerH = $height - $padTop - $padBottom;
    $groupW = $rows->count() > 0 ? $innerW / $rows->count() : $innerW;
    $barW = max(6, min(16, $groupW * 0.22));
@endphp

<div class="hs-fin-chart">
    @if($rows->isEmpty())
        <p class="text-sm text-slate-500">لا توجد بيانات لهذه الفترة.</p>
    @else
        <div class="hs-fin-legend mb-3">
            <span><i class="hs-fin-dot" style="background:#06C2A4"></i>{{ $leftLabel ?? 'إيرادات' }}</span>
            <span><i class="hs-fin-dot" style="background:#cbd5e1"></i>{{ $rightLabel ?? 'مصروفات' }}</span>
        </div>
        <svg viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="{{ $title ?? 'حركة النقدية' }}">
            @for($i = 0; $i < 4; $i++)
                @php $y = $padTop + (($innerH / 3) * $i); @endphp
                <line x1="{{ $padX }}" y1="{{ $y }}" x2="{{ $width - $padX }}" y2="{{ $y }}" stroke="#eef4f2" stroke-width="1" />
            @endfor
            @foreach($rows->values() as $index => $row)
                @php
                    $left = (float) (is_array($row) ? ($row[$leftKey] ?? 0) : ($row->{$leftKey} ?? 0));
                    $right = (float) (is_array($row) ? ($row[$rightKey] ?? 0) : ($row->{$rightKey} ?? 0));
                    $label = is_array($row) ? ($row['month'] ?? '') : ($row->month ?? '');
                    $center = $padX + ($groupW * $index) + ($groupW / 2);
                    $leftH = ($left / $max) * $innerH;
                    $rightH = ($right / $max) * $innerH;
                    $leftX = $center - $barW - 2;
                    $rightX = $center + 2;
                @endphp
                <rect x="{{ $leftX }}" y="{{ $padTop + $innerH - $leftH }}" width="{{ $barW }}" height="{{ $leftH }}" rx="4" fill="#06C2A4">
                    <title>{{ $label }} · {{ $leftLabel ?? 'إيرادات' }} {{ number_format($left, 2) }}</title>
                </rect>
                <rect x="{{ $rightX }}" y="{{ $padTop + $innerH - $rightH }}" width="{{ $barW }}" height="{{ $rightH }}" rx="4" fill="#cbd5e1">
                    <title>{{ $label }} · {{ $rightLabel ?? 'مصروفات' }} {{ number_format($right, 2) }}</title>
                </rect>
                <text x="{{ $center }}" y="{{ $height - 8 }}" text-anchor="middle" fill="#94a3b8" font-size="10">
                    {{ preg_match('/^\d{4}-\d{2}$/', (string) $label) ? \Illuminate\Support\Carbon::createFromFormat('Y-m', $label)->translatedFormat('M') : $label }}
                </text>
            @endforeach
        </svg>
    @endif
</div>
