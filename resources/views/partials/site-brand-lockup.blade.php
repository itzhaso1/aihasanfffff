@php
    $publicSite ??= \App\Support\Site\PublicSite::current();
    $align = $align ?? 'start';
@endphp
<span class="{{ $align === 'center' ? 'text-center' : 'text-right' }}">
    <span class="block text-sm font-extrabold leading-none text-[#0f172a]">{{ $publicSite->nameAr() }}</span>
    <span class="mt-1 block text-[10px] font-bold tracking-[0.18em] text-slate-500">{{ $publicSite->tagline() }}</span>
</span>
