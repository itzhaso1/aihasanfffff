<img
    src="{{ \App\Support\Ui\BrandLogo::defaultUrl() }}"
    alt="{{ ($publicSite ?? \App\Support\Site\PublicSite::current())->nameAr() }} {{ ($publicSite ?? \App\Support\Site\PublicSite::current())->nameEn() }}"
    {{ $attributes->merge(['class' => 'object-contain']) }}
>
