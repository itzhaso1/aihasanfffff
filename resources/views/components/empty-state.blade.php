@props([
    'title' => 'لا توجد بيانات',
    'text' => 'لم يتم العثور على عناصر لعرضها حالياً.',
    'actionLabel' => null,
    'actionUrl' => null,
])

<div {{ $attributes->merge(['class' => 'hs-empty']) }} role="status">
    <p class="hs-empty-title">{{ $title }}</p>
    <p class="hs-empty-text">{{ $text }}</p>
    @if($actionLabel && $actionUrl)
        <a href="{{ $actionUrl }}" class="hs-btn-primary mt-5">{{ $actionLabel }}</a>
    @endif
    {{ $slot }}
</div>
