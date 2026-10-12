@props([
    'label' => 'جاري التحميل...',
])

<div {{ $attributes->merge(['class' => 'flex items-center justify-center gap-3 py-10 text-sm text-slate-500']) }} role="status" aria-live="polite">
    <svg class="h-5 w-5 animate-spin text-[#06C2A4]" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
    </svg>
    <span>{{ $label }}</span>
</div>
