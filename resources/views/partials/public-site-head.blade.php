@php $publicSite ??= \App\Support\Site\PublicSite::current(); @endphp
<link rel="icon" href="{{ $publicSite->faviconUrl() }}">
<meta name="description" content="{{ $publicSite->description() }}">
