@props(['name' => 'rocket'])

@php
    $paths = [
        'rocket' => 'M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09ZM12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2Z',
        'crown' => 'm2 8 5 3 5-7 5 7 5-3v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2Z',
        'briefcase' => 'M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M4 7h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z',
        'building' => 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6',
        'arrow-left' => 'M19 12H5m0 0 7 7M5 12l7-7',
    ];
@endphp

<svg {{ $attributes->merge(['class' => 'h-5 w-5', 'fill' => 'none', 'stroke' => 'currentColor', 'viewBox' => '0 0 24 24']) }} aria-hidden="true">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $paths[$name] ?? $paths['rocket'] }}"/>
</svg>
