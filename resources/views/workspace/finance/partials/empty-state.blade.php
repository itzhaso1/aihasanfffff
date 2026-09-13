<div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
    <p class="text-base font-bold text-slate-900">{{ $title }}</p>
    @if(!empty($body))
        <p class="mt-2 text-sm text-slate-500">{{ $body }}</p>
    @endif
    @if(!empty($actionHref) && !empty($actionLabel))
        <a href="{{ $actionHref }}" class="mt-4 inline-flex rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">{{ $actionLabel }}</a>
    @endif
</div>
