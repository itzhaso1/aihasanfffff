@php
    /** @var \App\Services\Chat\Storefront\PublicStorefrontContext $ctx */
    $catalog = $ctx->catalog;
    $theme = $ctx->theme;
    $display = $catalog->display_settings ?? [];
    $title = $catalog->public_title ?: $catalog->public_name ?: $catalog->name;
    $description = $catalog->public_description ?: $catalog->short_description ?: $catalog->description;
    $slides = [];
    $howTitle = '';
    $howBody = '';
    foreach ($ctx->enabledBlocks as $block) {
        if (($block['type'] ?? '') === 'hero') {
            $slides = is_array($block['settings']['slides'] ?? null) ? $block['settings']['slides'] : [];
        }
        if (($block['type'] ?? '') === 'rich_text' && $howTitle === '') {
            $howTitle = (string) ($block['settings']['title'] ?? '');
            $howBody = (string) ($block['settings']['body'] ?? '');
        }
    }
    if ($slides === [] && is_string($catalog->banner_path) && $catalog->banner_path !== '') {
        $slides = [[
            'image' => $catalog->banner_path,
            'title' => $title,
            'subtitle' => $description,
            'button_text' => '',
            'button_url' => '',
        ]];
    }
    $selectedSlug = $ctx->selectedCategory?->slug;
    $catalogUrl = url('/catalog/'.$ctx->workspace->slug.'/'.$catalog->slug);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $catalog->default_locale ?: 'ar') }}" dir="{{ $catalog->rtl_mode ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        :root {
            --storefront-primary: {{ $theme['primary_color'] ?? '#1e3a8a' }};
            --storefront-secondary: {{ $theme['secondary_color'] ?? '#38bdf8' }};
            --storefront-background: {{ $theme['background_color'] ?? '#f0f9ff' }};
            --storefront-text: {{ $theme['text_color'] ?? '#0f172a' }};
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--storefront-background); color: var(--storefront-text); font-family: system-ui, sans-serif; }
        a { color: inherit; }
        .wrap { width: min(1120px, calc(100% - 2rem)); margin-inline: auto; }
        .nav { position: sticky; top: 0; z-index: 20; background: #fff; border-bottom: 1px solid color-mix(in srgb, var(--storefront-text) 12%, transparent); }
        .nav .wrap { display: flex; align-items: center; gap: 1rem; min-height: 4.25rem; }
        .brand { display: flex; align-items: center; gap: .65rem; text-decoration: none; font-weight: 800; }
        .brand img { height: 40px; width: 40px; object-fit: cover; border-radius: .6rem; }
        .nav-links { display: flex; align-items: center; gap: .35rem; margin-inline-start: auto; }
        .nav-links a { text-decoration: none; padding: .45rem .75rem; border-radius: 999px; }
        .nav-links a.chat { background: var(--storefront-primary); color: #fff; }
        .nav-toggle { display: none; margin-inline-start: auto; border: 0; background: transparent; font-size: 1.4rem; color: var(--storefront-text); }
        @media (max-width: 720px) {
            .nav-toggle { display: block; }
            .nav-links { display: none; position: absolute; inset: 4.25rem 1rem auto; flex-direction: column; align-items: stretch; padding: .75rem; background: #fff; border-radius: 1rem; box-shadow: 0 12px 30px rgba(0,0,0,.08); }
            .nav-links.open { display: flex; }
        }
        footer.site { width: 100%; margin: 2.5rem 0 0; background: var(--storefront-text); color: #fff; }
        footer.site a { color: #fff; }
        footer.site .wrap { width: 100%; max-width: none; margin: 0; display: grid; gap: 1.5rem; padding: 2rem 1.25rem 1.25rem; }
        @media (min-width: 800px) { footer.site .wrap { grid-template-columns: 1.4fr 1fr 1fr; } }
        footer.site h2 { margin: 0 0 .6rem; font-size: 1rem; }
        footer.site ul { list-style: none; margin: 0; padding: 0; display: grid; gap: .4rem; }
        footer.site .legal { width: 100%; max-width: none; margin: 0; border-top: 1px solid rgba(255,255,255,.15); padding: .8rem 1.25rem 1.2rem; font-size: .85rem; opacity: .8; }
        .slider-shell { width: calc(100% - 1rem); margin: 1.25rem auto 0; }
        .slider { position: relative; overflow: hidden; border-radius: 1.5rem; background: #0f172a; min-height: 280px; width: 100%; }
        .slide { display: none; position: relative; min-height: 280px; }
        .slide.active { display: block; }
        .slide img { width: 100%; height: min(52vw, 420px); object-fit: cover; display: block; }
        @media (max-width: 720px) {
            .slider-shell { width: calc(100% - .5rem); }
            .slider, .slide { min-height: 168px; }
            .slide img { height: 168px; }
            .slide .copy { padding: .9rem; }
            .slide h1 { font-size: 1.15rem; }
        }
        .slide .copy { position: absolute; inset: auto 0 0 0; padding: 1.5rem; background: linear-gradient(transparent, rgba(0,0,0,.55)); color: #fff; }
        .slide h1 { margin: 0 0 .35rem; font-size: clamp(1.5rem, 3vw, 2.4rem); }
        .slide p { margin: 0; max-width: 40rem; }
        .btn { display: inline-flex; margin-top: .8rem; padding: .7rem 1.1rem; border-radius: 999px; background: var(--storefront-primary); color: #fff; text-decoration: none; font-weight: 700; }
        .controls { position: absolute; inset: 0; display: flex; align-items: center; justify-content: space-between; padding: .5rem; pointer-events: none; }
        .controls button, .dots button { pointer-events: auto; border: 0; background: rgba(255,255,255,.9); color: #111; border-radius: 999px; }
        .controls button { width: 2.2rem; height: 2.2rem; font-size: 1.2rem; }
        .dots { position: absolute; bottom: .6rem; left: 0; right: 0; display: flex; justify-content: center; gap: .4rem; }
        .dots button { width: .7rem; height: .7rem; padding: 0; background: rgba(255,255,255,.55); }
        .dots button[aria-current="true"] { background: var(--storefront-secondary); }
        .cats { display: flex; gap: .6rem; overflow-x: auto; padding: 1rem 0 1.25rem; }
        .cats a { flex: 0 0 auto; display: flex; align-items: center; gap: .45rem; padding: .55rem .85rem; border-radius: 999px; background: #fff; text-decoration: none; border: 1px solid transparent; }
        .cats a.active { background: var(--storefront-primary); color: #fff; }
        .cats img, .cats span.mark { width: 1.6rem; height: 1.6rem; border-radius: 999px; object-fit: cover; display: inline-grid; place-items: center; background: var(--storefront-secondary); color: #fff; font-size: .75rem; }
        .grid { display: grid; grid-template-columns: 1fr; gap: 1rem; }
        @media (min-width: 640px) { .grid { grid-template-columns: 1fr 1fr; } }
        @media (min-width: 960px) { .grid { grid-template-columns: 1fr 1fr 1fr; } }
        article.card { background: #fff; border-radius: 1rem; overflow: hidden; padding: 1rem; }
        article.card img { width: 100%; height: 180px; object-fit: cover; border-radius: .75rem; }
        .price { color: var(--storefront-primary); font-weight: 800; }
        .cta, footer { margin: 2rem 0; padding: 1.25rem; border-radius: 1rem; background: #fff; }
        .cta a, footer a { color: var(--storefront-primary); }
    </style>
</head>
<body data-storefront-template="digital">
    <header class="nav">
        <div class="wrap">
            <a class="brand" href="{{ $catalogUrl }}">
                @if($catalog->logo_path)
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($catalog->logo_path) }}" alt="">
                @endif
                <span>{{ $title }}</span>
            </a>
            <button class="nav-toggle" type="button" data-nav-toggle aria-label="القائمة">☰</button>
            <nav class="nav-links" data-nav-links>
                <a href="{{ $catalogUrl }}">المنتجات</a>
                <a href="#categories">التصنيفات</a>
                <a class="chat" href="#chat">اطلب عبر الشات</a>
            </nav>
        </div>
    </header>
    <div class="slider-shell">
        @if($slides !== [])
            <section class="slider" data-digital-slider>
                @foreach($slides as $index => $slide)
                    @php
                        $image = is_string($slide['image'] ?? null) ? $slide['image'] : '';
                        $publicImage = $image !== '' && ! str_contains($image, '..')
                            ? \Illuminate\Support\Facades\Storage::disk('public')->url($image)
                            : '';
                    @endphp
                    <div class="slide {{ $index === 0 ? 'active' : '' }}">
                        @if($publicImage !== '')
                            <img src="{{ $publicImage }}" alt="{{ $slide['title'] ?? $title }}">
                        @endif
                        <div class="copy">
                            @if(! empty($slide['title']))<h1>{{ $slide['title'] }}</h1>@else<h1>{{ $title }}</h1>@endif
                            @if(! empty($slide['subtitle']))<p>{{ $slide['subtitle'] }}</p>@endif
                            @if(! empty($slide['button_text']) && ! empty($slide['button_url']))
                                <a class="btn" href="{{ $slide['button_url'] }}">{{ $slide['button_text'] }}</a>
                            @endif
                        </div>
                    </div>
                @endforeach
                @if(count($slides) > 1)
                    <div class="controls">
                        <button type="button" data-prev aria-label="السابق">‹</button>
                        <button type="button" data-next aria-label="التالي">›</button>
                    </div>
                    <div class="dots">
                        @foreach($slides as $index => $slide)
                            <button type="button" data-dot="{{ $index }}" @if($index === 0) aria-current="true" @endif aria-label="{{ $index + 1 }}"></button>
                        @endforeach
                    </div>
                @endif
            </section>
        @else
            <section class="slider">
                <div class="slide active"><div class="copy"><h1>{{ $title }}</h1>@if($description)<p>{{ $description }}</p>@endif</div></div>
            </section>
        @endif
    </div>
    <div class="wrap">
        <nav class="cats" id="categories" aria-label="التصنيفات">
            <a href="{{ $catalogUrl }}" @class(['active' => $selectedSlug === null])>الكل</a>
            @foreach($ctx->categories as $category)
                @php $localized = data_get($category->localization, $ctx->language.'.name') ?: $category->name; @endphp
                <a href="{{ $catalogUrl.'?category='.urlencode($category->slug) }}" @class(['active' => $selectedSlug === $category->slug])>
                    @if($category->image_path)
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($category->image_path) }}" alt="">
                    @else
                        <span class="mark">{{ mb_substr($localized, 0, 1) }}</span>
                    @endif
                    {{ $localized }}
                </a>
            @endforeach
        </nav>

        @if($ctx->featuredItems->isNotEmpty() && $selectedSlug === null)
            <section>
                <h2>منتجات مميزة</h2>
                <div class="grid">
                    @foreach($ctx->featuredItems as $item)
                        @include('storefront.templates.partials.digital-product', ['item' => $item, 'display' => $display, 'ctx' => $ctx])
                    @endforeach
                </div>
            </section>
        @endif

        <main>
            <h2>{{ $ctx->selectedCategory ? (data_get($ctx->selectedCategory->localization, $ctx->language.'.name') ?: $ctx->selectedCategory->name) : 'المنتجات' }}</h2>
            <div class="grid">
                @forelse($ctx->items as $item)
                    @include('storefront.templates.partials.digital-product', ['item' => $item, 'display' => $display, 'ctx' => $ctx])
                @empty
                    <p>لا توجد منتجات في هذا التصنيف.</p>
                @endforelse
            </div>
        </main>

        <section class="cta">
            <h2>{{ $howTitle !== '' ? $howTitle : 'كيف يعمل' }}</h2>
            <p>{{ $howBody !== '' ? $howBody : ($description ?: 'اختر المنتج ثم اطلبه عبر الشات.') }}</p>
        </section>

        <section class="cta" id="chat">
            <h2>اطلب عبر الشات</h2>
            <p>اختر منتجاً ثم اضغط «اطلب عبر الشات».</p>
        </section>
    </div>
    <footer class="site">
        <div class="wrap">
            <div>
                <h2>{{ $title }}</h2>
                @if($description)<p>{{ $description }}</p>@endif
            </div>
            <div>
                <h2>التصنيفات</h2>
                <ul>
                    <li><a href="{{ $catalogUrl }}">الكل</a></li>
                    @foreach($ctx->categories as $category)
                        <li><a href="{{ $catalogUrl.'?category='.urlencode($category->slug) }}">{{ data_get($category->localization, $ctx->language.'.name') ?: $category->name }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div>
                <h2>التواصل</h2>
                <ul>
                    @if(data_get($catalog->public_support_contact, 'email'))<li>{{ data_get($catalog->public_support_contact, 'email') }}</li>@endif
                    @if(data_get($catalog->public_support_contact, 'phone'))<li>{{ data_get($catalog->public_support_contact, 'phone') }}</li>@endif
                    @if(is_string($catalog->public_website) && preg_match('#^https://#i', $catalog->public_website))
                        <li><a href="{{ $catalog->public_website }}">{{ $catalog->public_website }}</a></li>
                    @endif
                    @foreach($catalog->social_links ?? [] as $url)
                        @if(is_string($url) && preg_match('#^https://#i', $url))
                            <li><a href="{{ $url }}">{{ $url }}</a></li>
                        @endif
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="wrap legal">{{ $catalog->legal_business_name ?: $title }}</div>
    </footer>
    <script>
        (function () {
            var root = document.querySelector('[data-digital-slider]');
            if (!root) return;
            var slides = Array.prototype.slice.call(root.querySelectorAll('.slide'));
            var dots = Array.prototype.slice.call(root.querySelectorAll('[data-dot]'));
            var index = 0;
            var timer = null;
            function show(next) {
                index = (next + slides.length) % slides.length;
                slides.forEach(function (slide, i) { slide.classList.toggle('active', i === index); });
                dots.forEach(function (dot, i) { dot.setAttribute('aria-current', i === index ? 'true' : 'false'); });
            }
            function start() {
                if (slides.length < 2) return;
                stop();
                timer = setInterval(function () { show(index + 1); }, 6000);
            }
            function stop() { if (timer) clearInterval(timer); }
            var prev = root.querySelector('[data-prev]');
            var next = root.querySelector('[data-next]');
            if (prev) prev.addEventListener('click', function () { show(index - 1); start(); });
            if (next) next.addEventListener('click', function () { show(index + 1); start(); });
            dots.forEach(function (dot) {
                dot.addEventListener('click', function () { show(Number(dot.getAttribute('data-dot'))); start(); });
            });
            root.addEventListener('mouseenter', stop);
            root.addEventListener('mouseleave', start);
            root.addEventListener('touchstart', stop, { passive: true });
            start();
        }());
        (function () {
            var toggle = document.querySelector('[data-nav-toggle]');
            var links = document.querySelector('[data-nav-links]');
            if (!toggle || !links) return;
            toggle.addEventListener('click', function () { links.classList.toggle('open'); });
        }());
    </script>
</body>
</html>
