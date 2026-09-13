@props([
    'workspace' => null,
    'size' => 'md',
    'variant' => 'full',
])

@php
    $url = \App\Support\Ui\BrandLogo::url($workspace);
    $markSizes = [
        'sm' => 'h-8 w-8',
        'md' => 'h-10 w-10',
        'lg' => 'h-12 w-12',
        'xl' => 'h-16 w-16',
    ];
    $fullSizes = [
        'sm' => 'h-8 max-w-[8rem]',
        'md' => 'h-11 max-w-[10rem]',
        'lg' => 'h-16 max-w-[13rem]',
        'xl' => 'h-24 max-w-[16rem]',
    ];
    $classes = $variant === 'mark'
        ? ($markSizes[$size] ?? 'h-10 w-10').' rounded-xl object-cover object-[center_30%]'
        : ($fullSizes[$size] ?? 'h-11 max-w-[10rem]').' w-auto object-contain object-right';
@endphp

<img
    src="{{ $url }}"
    alt="حاسم الذكي HASIM SMART"
    {{ $attributes->class($classes) }}
>
