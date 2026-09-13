@php
    $label = $card['label'] ?? '';
    $value = $card['value'] ?? 0;
    $previous = $card['previous'] ?? null;
    $delta = $card['delta'] ?? null;
    $direction = (int) ($card['direction'] ?? 0);
    $hint = $card['hint'] ?? null;
    $href = $href ?? null;
    $key = $card['key'] ?? 'sales';
    $iconTone = match ($key) {
        'payables' => 'is-rose',
        'receivables' => 'is-amber',
        'profit' => 'is-navy',
        default => '',
    };
    $displayValue = is_numeric($value) ? number_format((float) $value, 2) : $value;
    $currentNum = is_numeric($value) ? (float) $value : null;
    $previousNum = is_numeric($previous) ? (float) $previous : null;
    $percent = ($currentNum !== null && $previousNum !== null && $previousNum != 0.0)
        ? round((($currentNum - $previousNum) / abs($previousNum)) * 100, 1)
        : null;
    $hasCompare = $delta !== null && $delta !== '';
    $deltaClass = $direction > 0 ? 'is-up' : ($direction < 0 ? 'is-down' : 'is-flat');
@endphp
@if($href)
    <a href="{{ $href }}" class="hs-fin-card hs-fin-kpi">
@else
    <article class="hs-fin-card hs-fin-kpi">
@endif
    <div class="hs-fin-kpi-top">
        <p class="hs-fin-kpi-label">{{ $label }}</p>
        <span class="hs-fin-kpi-icon {{ $iconTone }}" aria-hidden="true">
            @switch($key)
                @case('profit')
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 17l6-6 4 4 8-8M13 7h8v8"/></svg>
                    @break
                @case('receivables')
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 21v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2M11 11a4 4 0 100-8 4 4 0 000 8zM21 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                    @break
                @case('payables')
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8c-2.5 0-4 1.2-4 2.8S9.5 13.6 12 13.6s4 1.2 4 2.8S14.5 19.2 12 19.2 8 18 8 16.4M12 6v2m0 12v-2"/></svg>
                    @break
                @default
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 19V5m0 14h16M8 15V9m4 6V7m4 8v-4"/></svg>
            @endswitch
        </span>
    </div>
    <p class="hs-fin-kpi-value">{{ $displayValue }}</p>
    @if($hasCompare)
        <p class="hs-fin-delta {{ $deltaClass }}">
            @if($percent !== null)
                {{ $percent > 0 ? '+' : '' }}{{ $percent }}%
            @else
                {{ $direction > 0 ? '▲' : ($direction < 0 ? '▼' : '●') }}
                {{ number_format((float) $delta, 2) }}
            @endif
            مقارنة بالفترة السابقة
        </p>
    @endif
@if($href)
    </a>
@else
    </article>
@endif
