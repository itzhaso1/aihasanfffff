@php
    $rows = collect($points ?? []);
    $sales = $rows->map(fn ($row) => (float) (is_array($row) ? ($row['sales'] ?? 0) : ($row->sales ?? 0)));
    $expenses = $rows->map(fn ($row) => (float) (is_array($row) ? ($row['expenses'] ?? 0) : ($row->expenses ?? 0)));
    $max = max(1, (float) $sales->merge($expenses)->max());
    $width = 680;
    $height = 268;
    $padX = 28;
    $padTop = 18;
    $padBottom = 28;
    $innerW = $width - ($padX * 2);
    $innerH = $height - $padTop - $padBottom;
    $count = max(1, $rows->count());
    $step = $count > 1 ? $innerW / ($count - 1) : 0;

    $coords = function ($values) use ($rows, $padX, $padTop, $innerH, $max, $step) {
        return $rows->values()->map(function ($row, $index) use ($values, $padX, $padTop, $innerH, $max, $step) {
            $value = (float) $values[$index];
            return [
                'x' => $padX + ($index * $step),
                'y' => $padTop + ((1 - ($value / $max)) * $innerH),
                'value' => $value,
                'label' => is_array($row) ? ($row['month'] ?? '') : ($row->month ?? ''),
            ];
        });
    };

    $salesPoints = $coords($sales->values());
    $expensePoints = $coords($expenses->values());
    $toPair = fn ($items) => $items->map(fn ($p) => number_format($p['x'], 1, '.', '').','.number_format($p['y'], 1, '.', ''))->implode(' ');
    $area = $salesPoints->isNotEmpty()
        ? $toPair($salesPoints)
            .' '.($salesPoints->last()['x'] ?? $padX).','.($padTop + $innerH)
            .' '.($salesPoints->first()['x'] ?? $padX).','.($padTop + $innerH)
        : '';
@endphp

<div class="hs-fin-chart">
    @if($rows->isEmpty())
        <p class="text-sm text-slate-500">لا توجد بيانات لهذه الفترة.</p>
    @else
        <div class="hs-fin-legend mb-3">
            <span><i class="hs-fin-dot" style="background:#06C2A4"></i>الإيرادات</span>
            <span><i class="hs-fin-dot" style="background:#cbd5e1"></i>المصروفات</span>
        </div>
        <svg viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="الإيرادات والمصروفات">
            @for($i = 0; $i < 4; $i++)
                @php $y = $padTop + (($innerH / 3) * $i); @endphp
                <line x1="{{ $padX }}" y1="{{ $y }}" x2="{{ $width - $padX }}" y2="{{ $y }}" stroke="#eef4f2" stroke-width="1" />
            @endfor
            @if($area !== '')
                <polygon points="{{ $area }}" fill="rgba(6,194,164,0.16)"></polygon>
            @endif
            <polyline points="{{ $toPair($salesPoints) }}" fill="none" stroke="#06C2A4" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"></polyline>
            <polyline points="{{ $toPair($expensePoints) }}" fill="none" stroke="#cbd5e1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></polyline>
            @foreach($salesPoints as $point)
                <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="3.5" fill="#06C2A4">
                    <title>{{ $point['label'] }} · إيراد {{ number_format($point['value'], 2) }}</title>
                </circle>
            @endforeach
            @foreach($expensePoints as $point)
                <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="3.2" fill="#cbd5e1">
                    <title>{{ $point['label'] }} · مصروف {{ number_format($point['value'], 2) }}</title>
                </circle>
            @endforeach
            @foreach($salesPoints as $point)
                <text x="{{ $point['x'] }}" y="{{ $height - 8 }}" text-anchor="middle" fill="#94a3b8" font-size="10">
                    {{ preg_match('/^\d{4}-\d{2}$/', (string) $point['label']) ? \Illuminate\Support\Carbon::createFromFormat('Y-m', $point['label'])->translatedFormat('M') : $point['label'] }}
                </text>
            @endforeach
        </svg>
    @endif
</div>
