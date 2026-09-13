@props([
    'value' => '',
    'tones' => [],
])

@php
    $defaultTones = [
        'completed' => 'bg-emerald-50 text-emerald-700',
        'paid' => 'bg-emerald-50 text-emerald-700',
        'activated' => 'bg-emerald-50 text-emerald-700',
        'active' => 'bg-emerald-50 text-emerald-700',
        'pending' => 'bg-amber-50 text-amber-700',
        'awaiting_payment' => 'bg-amber-50 text-amber-700',
        'pending_activation' => 'bg-amber-50 text-amber-700',
        'processing' => 'bg-amber-50 text-amber-700',
        'failed' => 'bg-red-50 text-red-700',
        'expired' => 'bg-gray-100 text-gray-600',
        'cancelled' => 'bg-gray-100 text-gray-600',
    ];
    $map = $tones !== [] ? $tones : $defaultTones;
    $tone = $map[$value] ?? 'bg-gray-100 text-gray-600';
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex rounded-md px-2 py-0.5 text-xs font-medium '.$tone]) }}>
    {{ $value !== '' && $value !== null ? $value : '—' }}
</span>
