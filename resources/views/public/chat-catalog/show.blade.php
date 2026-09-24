@extends('layouts.public')

@php
    $seo = $catalog->seo ?? [];
    $display = $catalog->display_settings ?? [];
    $discovery = $catalog->discovery_settings ?? [];
    $language = $catalog->default_language === 'bilingual' ? $catalog->fallback_language : $catalog->default_language;
    $categoryLocalization = $selectedCategory?->localization ?? [];
    $categorySeo = $selectedCategory?->seo ?? [];
    $activeSeo = array_replace($seo, $categorySeo);
    $categoryName = data_get($categoryLocalization, $language.'.name') ?: $selectedCategory?->name;
    $categoryDescription = data_get($categoryLocalization, $language.'.description') ?: $selectedCategory?->description;
    $title = $categorySeo['title'] ?? $seo['title'] ?? $catalog->public_title ?? $catalog->public_name ?? $catalog->name;
    $description = $categorySeo['description'] ?? $seo['description'] ?? $categoryDescription ?? $catalog->public_description ?? $catalog->short_description ?? '';
    $closedMessage = data_get($catalog->customer_templates, 'business_closed.'.$language)
        ?: data_get($catalog->customer_templates, 'business_closed.'.$catalog->fallback_language)
        ?: data_get($catalog->availability_schedule, 'custom_closed_message')
        ?: data_get($catalog->customer_service_policy, 'unavailable_catalog_message')
        ?: 'الكتالوج غير متاح حالياً.';
@endphp

@section('html_lang', str_replace('_', '-', $catalog->default_locale ?: 'ar_SA'))
@section('html_dir', $catalog->rtl_mode ? 'rtl' : 'ltr')
@section('title', $title)

@push('head')
    @if($catalog->favicon_path)<link rel="icon" href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($catalog->favicon_path) }}">@endif
    @if($description)<meta name="description" content="{{ $description }}">@endif
    @if(!empty($activeSeo['robots']))<meta name="robots" content="{{ $activeSeo['robots'] }}">@endif
    <link rel="canonical" href="{{ url('/catalog/'.$workspace->slug.'/'.($seo['canonical_slug'] ?? $catalog->slug).($selectedCategory ? '?category='.urlencode($categorySeo['canonical_slug'] ?? $selectedCategory->slug) : '')) }}">
    <meta property="og:title" content="{{ $activeSeo['share_title'] ?? $title }}">
    @if(!empty($activeSeo['share_description'] ?? $description))<meta property="og:description" content="{{ $activeSeo['share_description'] ?? $description }}">@endif
    @if(!empty($activeSeo['share_image']))<meta property="og:image" content="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($activeSeo['share_image']) }}">@endif
@endpush

@section('content')
    <main class="mx-auto max-w-7xl px-4 py-10">
        <section class="mb-8 overflow-hidden rounded-3xl bg-white shadow-sm">
            @if($catalog->banner_path)
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($catalog->banner_path) }}" alt="" class="h-48 w-full object-cover">
            @endif
            <div class="p-6">
                <div class="flex items-center gap-4">
                    @if($catalog->logo_path)
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($catalog->logo_path) }}" alt="{{ $catalog->public_name ?: $catalog->name }}" class="h-14 w-14 rounded-xl object-cover">
                    @endif
                    <h1 class="text-3xl font-bold">{{ $catalog->public_name ?: $catalog->name }}</h1>
                </div>
                @if($catalog->public_description ?: $catalog->description)
                    <p class="mt-3 max-w-3xl text-slate-600">{{ $catalog->public_description ?: $catalog->description }}</p>
                @endif
                @if($catalog->brand_image_path)
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($catalog->brand_image_path) }}" alt="{{ $catalog->brand_description ?: $catalog->public_name }}" class="mt-4 max-h-48 rounded-xl object-cover">
                @endif
                @if($catalog->brand_description)
                    <p class="mt-3 max-w-3xl text-sm text-slate-600">{{ $catalog->brand_description }}</p>
                @endif
                @if($catalog->seller)
                    <p class="mt-3 text-sm text-slate-600">
                        البائع: {{ $catalog->seller_public_display_name ?: $catalog->seller->display_name ?: $catalog->seller->name }}
                        @if($catalog->seller_status_visibility) — {{ $catalog->seller->status->value }} @endif
                    </p>
                @endif
                @if($isPaused || ! $isOpen)
                    <p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
                        {{ $closedMessage }}
                    </p>
                @endif
                @if(data_get($catalog->customer_service_policy, 'inquiry_mode') === 'inquiry_only')
                    <p class="mt-4 rounded-lg bg-sky-50 p-3 text-sm text-sky-800">
                        {{ data_get($catalog->customer_templates, 'inquiry_response.'.$language) ?: data_get($catalog->customer_templates, 'inquiry_response.'.$catalog->fallback_language) ?: 'هذا الكتالوج متاح للاستفسارات فقط.' }}
                    </p>
                @endif
            </div>
        </section>

        @if(data_get($catalog->discovery_settings, 'searchable', true))
            <form class="mb-6 flex gap-2" method="GET">
                <input name="q" value="{{ request('q') }}" class="w-full rounded-xl border-slate-300" placeholder="ابحث في الكتالوج">
                <button class="rounded-xl bg-slate-900 px-5 py-2 text-white">بحث</button>
            </form>
        @endif

        @if($categories->isNotEmpty())
            <section class="mb-6 flex gap-3 overflow-x-auto">
                <a href="{{ url('/catalog/'.$workspace->slug.'/'.$catalog->slug) }}" class="shrink-0 rounded-xl bg-white px-4 py-3 shadow-sm">{{ $language === 'en' ? 'All' : 'الكل' }}</a>
                @foreach($categories as $category)
                    @php($localizedName = data_get($category->localization, $language.'.name') ?: $category->name)
                    <a href="{{ url('/catalog/'.$workspace->slug.'/'.$catalog->slug.'?category='.urlencode($category->slug)) }}" class="shrink-0 rounded-xl bg-white px-4 py-3 shadow-sm @if($selectedCategory?->id === $category->id) ring-2 ring-slate-900 @endif">
                        @if($category->image_path)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($category->image_path) }}" alt="" class="mr-2 inline h-6 w-6 rounded object-cover">@endif
                        {{ $localizedName }}
                    </a>
                @endforeach
            </section>
        @endif

        @if($selectedCategory)
            <section class="mb-6 rounded-2xl bg-white p-5 shadow-sm">
                @if($selectedCategory->image_path)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($selectedCategory->image_path) }}" alt="" class="mb-3 h-24 w-full rounded-xl object-cover">@endif
                <h2 class="font-semibold">{{ $categoryName }}</h2>
                @if($categoryDescription)<p class="mt-2 text-sm text-slate-600">{{ $categoryDescription }}</p>@endif
            </section>
        @endif

        <section class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @forelse($items as $item)
                @php($availability = $item->customer_availability)
                @php($price = $item->customer_price)
                @php($primaryMedia = $item->media->firstWhere('kind', 'primary') ?: $item->media->firstWhere('kind', 'thumbnail') ?: $item->media->first())
                @php($imagePath = $primaryMedia?->path ?: $item->image_path)
                @php($focalX = min(100, max(0, (int) data_get($primaryMedia?->focal_metadata, 'x', 50))))
                @php($focalY = min(100, max(0, (int) data_get($primaryMedia?->focal_metadata, 'y', 50))))
                <article class="rounded-2xl bg-white p-5 shadow-sm">
                    @if($imagePath)
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($imagePath) }}" alt="{{ $primaryMedia?->alt ?: $item->name }}" class="mb-4 h-44 w-full rounded-xl object-cover" style="object-position: {{ $focalX }}% {{ $focalY }}%">
                    @endif
                    <h2 class="font-semibold">{{ $item->name }}</h2>
                    @if((($discovery['featured_items_enabled'] ?? true) && $item->is_featured) || $item->is_bestseller || $item->is_new || $item->is_recommended)
                        <p class="mt-1 text-xs font-medium text-emerald-700">
                            {{ collect([
                                ($discovery['featured_items_enabled'] ?? true) && $item->is_featured ? ($language === 'en' ? 'Featured' : 'مميز') : null,
                                $item->is_bestseller ? ($language === 'en' ? 'Bestseller' : 'الأكثر مبيعاً') : null,
                                $item->is_new ? ($language === 'en' ? 'New' : 'جديد') : null,
                                $item->is_recommended ? ($language === 'en' ? 'Recommended' : 'موصى به') : null,
                            ])->filter()->implode(' · ') }}
                        </p>
                    @endif
                    @if(($display['show_brand'] ?? true) && $item->brand)<p class="mt-1 text-xs text-slate-500">{{ $item->brand }}</p>@endif
                    @if($item->customer_description ?: $item->short_description ?: $item->description)
                        <p class="mt-2 text-sm text-slate-600">{{ $item->customer_description ?: $item->short_description ?: $item->description }}</p>
                    @endif
                    <p class="mt-4 font-semibold">
                        @if($price['is_from_price']){{ $language === 'en' ? 'From' : 'يبدأ من' }} @endif
                        {{ $price['formatted'] }} {{ $price['currency'] }}
                    </p>
                    @if($price['compare_at_amount'])
                        <p class="text-sm text-slate-500 line-through">{{ $price['compare_at_amount'] }} {{ $price['currency'] }}</p>
                    @endif
                    @if($price['tax_presentation'])
                        <p class="mt-1 text-xs text-slate-500">{{ str_replace('_', ' ', $price['tax_presentation']) }}</p>
                    @endif
                    @if($display['show_sku'] ?? false)<p class="mt-1 text-xs text-slate-500">SKU: {{ $item->sku }}</p>@endif
                    @if($display['show_barcode'] ?? false)<p class="mt-1 text-xs text-slate-500">Barcode: {{ $item->barcode }}</p>@endif
                    @if(($display['show_stock'] ?? true) && $availability['quantity'] !== null)<p class="mt-1 text-xs text-slate-500">{{ $availability['quantity'] }}</p>@endif
                    @if(($display['show_variants'] ?? true) && $item->variants->isNotEmpty())
                        <p class="mt-1 text-xs text-slate-500">{{ $item->variants->pluck('name')->implode(' · ') }}</p>
                    @endif
                    @if(!empty($item->highlights))
                        <ul class="mt-3 list-inside list-disc text-sm text-slate-600">@foreach($item->highlights as $highlight)<li>{{ $highlight }}</li>@endforeach</ul>
                    @endif
                    @if($item->warnings)<p class="mt-3 text-sm text-amber-700">{{ $item->warnings }}</p>@endif
                    @if($item->terms)<p class="mt-3 text-xs text-slate-500">{{ $item->terms }}</p>@endif
                    @if($item->media->where('kind', 'gallery')->isNotEmpty())
                        <div class="mt-3 flex gap-2 overflow-x-auto">
                            @foreach($item->media->where('kind', 'gallery') as $media)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($media->path) }}" alt="{{ $media->alt ?: $item->name }}" class="h-12 w-12 rounded object-cover">
                            @endforeach
                        </div>
                    @endif
                    @if($item->media->where('kind', 'video_reference')->isNotEmpty())
                        @php($video = $item->media->where('kind', 'video_reference')->first())
                        <a href="{{ $video->path }}" rel="noopener noreferrer" class="mt-3 inline-block text-sm underline">{{ $video->alt ?: 'Video' }}</a>
                    @endif
                    @if(! $isOpen)
                        <p class="mt-2 text-sm text-amber-700">{{ $closedMessage }}</p>
                    @elseif(! $availability['available'])
                        <p class="mt-2 text-sm text-amber-700">{{ data_get($catalog->customer_templates, 'out_of_stock.'.$language) ?: data_get($catalog->customer_templates, 'unavailable.'.$language) ?: data_get($catalog->customer_templates, 'out_of_stock.'.$catalog->fallback_language) ?: data_get($catalog->customer_service_policy, 'unavailable_product_message') ?: 'غير متاح حالياً.' }}</p>
                    @elseif($availability['label'] === 'backorder')
                        <p class="mt-2 text-sm text-amber-700">{{ data_get($catalog->customer_service_policy, 'backorder_message') ?: 'متاح بالطلب المسبق.' }}</p>
                    @elseif($availability['label'] === 'low_stock')
                        <p class="mt-2 text-sm text-amber-700">{{ data_get($catalog->customer_templates, 'low_stock.'.$language) ?: data_get($catalog->customer_templates, 'low_stock.'.$catalog->fallback_language) ?: data_get($catalog->customer_service_policy, 'low_stock_message') ?: 'الكمية محدودة.' }}</p>
                    @endif
                </article>
            @empty
                <p class="col-span-full rounded-xl bg-white p-8 text-center text-slate-500">لا توجد منتجات متاحة للعرض.</p>
            @endforelse
        </section>
        @if($catalog->legal_business_name || $catalog->public_website || data_get($catalog->public_support_contact, 'email') || data_get($catalog->public_support_contact, 'phone') || data_get($catalog->seller_support_contact, 'email') || data_get($catalog->seller_support_contact, 'phone') || data_get($catalog->customer_service_policy, 'support_contact_instructions') || !empty($catalog->social_links))
            <section class="mt-8 rounded-2xl bg-white p-5 text-sm text-slate-600 shadow-sm">
                @if($catalog->legal_business_name)<p>{{ $catalog->legal_business_name }}</p>@endif
                @if($catalog->public_website)<p><a class="underline" href="{{ $catalog->public_website }}" rel="noopener noreferrer">{{ $catalog->public_website }}</a></p>@endif
                @if(data_get($catalog->public_support_contact, 'email'))<p>{{ data_get($catalog->public_support_contact, 'email') }}</p>@endif
                @if(data_get($catalog->public_support_contact, 'phone'))<p>{{ data_get($catalog->public_support_contact, 'phone') }}</p>@endif
                @if(data_get($catalog->seller_support_contact, 'email'))<p>{{ data_get($catalog->seller_support_contact, 'email') }}</p>@endif
                @if(data_get($catalog->seller_support_contact, 'phone'))<p>{{ data_get($catalog->seller_support_contact, 'phone') }}</p>@endif
                @if(data_get($catalog->customer_service_policy, 'support_contact_instructions'))<p class="mt-2">{{ data_get($catalog->customer_service_policy, 'support_contact_instructions') }}</p>@endif
                @if(data_get($catalog->customer_service_policy, 'response_expectation_hours'))<p class="mt-2">{{ $language === 'en' ? 'Expected response within' : 'وقت الرد المتوقع خلال' }} {{ data_get($catalog->customer_service_policy, 'response_expectation_hours') }} {{ $language === 'en' ? 'hours' : 'ساعة' }}</p>@endif
                @foreach($catalog->social_links ?? [] as $url)
                    @if(is_string($url) && $url !== '')<a class="mr-3 underline" href="{{ $url }}" rel="noopener noreferrer">{{ $url }}</a>@endif
                @endforeach
            </section>
        @endif
        <div class="mt-8">{{ $items->links() }}</div>
    </main>
@endsection
