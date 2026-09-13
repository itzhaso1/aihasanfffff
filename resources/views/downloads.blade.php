<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>تحميل التطبيقات | {{ config('app.name', 'HASEM') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700,800&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        html { scroll-behavior: smooth; }
        .hs-device-phone {
            width: 8.4rem;
            border-radius: 1.35rem;
            border: 3px solid #0f172a;
            background: #0f172a;
            box-shadow: 0 18px 36px -24px rgba(15, 23, 42, 0.45);
            overflow: hidden;
        }
        .hs-device-phone img { display: block; width: 100%; height: 15.5rem; object-fit: cover; object-position: top; }
        .hs-device-laptop {
            width: min(100%, 22rem);
            border-radius: 0.9rem 0.9rem 0.35rem 0.35rem;
            border: 3px solid #0f172a;
            background: #0f172a;
            overflow: hidden;
            box-shadow: 0 18px 36px -24px rgba(15, 23, 42, 0.45);
        }
        .hs-device-laptop img { display: block; width: 100%; height: 13.5rem; object-fit: cover; object-position: top; }
        .hs-device-laptop-base {
            height: 0.7rem;
            margin: 0 auto;
            width: 72%;
            border-radius: 0 0 0.7rem 0.7rem;
            background: #cbd5e1;
        }
        .hs-system-photo {
            display: block;
            width: 100%;
            height: 13rem;
            object-fit: contain;
            background: #F7FCFB;
        }
        @media (min-width: 1024px) {
            .hs-device-phone { width: 9.2rem; }
            .hs-device-phone img { height: 17.2rem; }
            .hs-device-laptop { width: 26rem; }
            .hs-device-laptop img { height: 15rem; }
        }
    </style>
</head>
<body class="overflow-x-hidden bg-[#F7FCFB] text-[#0f172a] antialiased" style="font-family: var(--hs-font)">
    @include('partials.public-topbar')

    @php
        $appStoreUrl = config('downloads.app_store_url');
        $googlePlayUrl = config('downloads.google_play_url');
        $windowsUrl = config('downloads.windows_url');
        $apps = [
            [
                'key' => 'chat',
                'icon' => '💬',
                'title' => 'حاسم للمحادثات',
                'text' => 'أدر محادثات عملائك من WhatsApp وInstagram وMessenger وTelegram وTikTok والبريد من مكان واحد.',
                'image' => 'images/landing-inbox.jpg',
                'alt' => 'حاسم للمحادثات',
                'platforms' => ['apple', 'android'],
            ],
            [
                'key' => 'finance',
                'icon' => '🧾',
                'title' => 'حاسم للفوترة',
                'text' => 'أنشئ الفواتير، تابع المبيعات والمدفوعات والتقارير المالية من منصة واحدة.',
                'image' => 'images/landing-invoicing.jpg',
                'alt' => 'حاسم للفوترة',
                'platforms' => ['apple', 'android', 'windows'],
            ],
            [
                'key' => 'cashier',
                'icon' => '🛒',
                'title' => 'حاسم للكاشير',
                'text' => 'أدر المبيعات والطلبات والطاولات والمنتجات والمخزون من شاشة كاشير سهلة وسريعة.',
                'image' => 'images/landing-cashier.jpg',
                'alt' => 'حاسم للكاشير',
                'platforms' => ['apple', 'android', 'windows'],
            ],
        ];
    @endphp

    <main>
        <section class="mx-auto max-w-7xl px-4 pb-12 pt-10 sm:px-6 lg:px-8 lg:pb-16 lg:pt-16">
            <div class="grid items-center gap-10 lg:grid-cols-2 lg:gap-12">
                <div>
                    <span class="inline-flex rounded-full border border-[#BDEFE5] bg-[#E8FAF6] px-3 py-1 text-xs font-bold text-[#067e6b]">تطبيقات حاسم الذكي</span>
                    <h1 class="mt-5 text-4xl font-extrabold leading-[1.3] text-[#0f172a] sm:text-5xl">حمّل حاسم أينما كنت</h1>
                    <p class="mt-5 max-w-xl text-base font-medium leading-8 text-slate-600 sm:text-lg">
                        الوصول إلى أعمالك أسهل من أي وقت. حمّل تطبيقات حاسم على هاتفك أو جهاز الكمبيوتر وابقَ على اتصال بعملائك وفريقك.
                    </p>
                    <p class="mt-3 max-w-xl text-sm font-medium leading-7 text-slate-500">
                        استخدم حاسم من هاتفك أو جهاز الكمبيوتر، واختر التطبيق المناسب لجهازك.
                    </p>
                </div>
                <div class="flex min-w-0 flex-wrap items-end justify-center gap-4 lg:gap-5">
                    <div class="hs-device-phone" aria-hidden="true">
                        <img src="{{ asset('images/landing-inbox.jpg') }}" alt="">
                    </div>
                    <div class="order-first w-full sm:order-none sm:w-auto">
                        <div class="hs-device-laptop mx-auto">
                            <img src="{{ asset('images/landing-invoicing.jpg') }}" alt="">
                        </div>
                        <div class="hs-device-laptop-base"></div>
                    </div>
                    <div class="hs-device-phone" aria-hidden="true">
                        <img src="{{ asset('images/landing-cashier.jpg') }}" alt="">
                    </div>
                </div>
            </div>
        </section>

        <section id="devices" class="bg-white py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-extrabold text-[#0f172a]">اختر جهازك</h2>
                    <p class="mt-3 text-sm font-medium leading-7 text-slate-600">حمّل حاسم على المنصة التي تستخدمها.</p>
                </div>
                <div class="mt-10 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                    <article class="rounded-[1.4rem] border border-slate-100 bg-[#F7FCFB] p-6 text-right shadow-[0_16px_40px_-28px_rgba(15,23,42,0.35)]">
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-[#0f172a] shadow-sm">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.37 12.86c0-2.16 1.77-3.2 1.85-3.25-1.02-1.48-2.6-1.69-3.16-1.71-1.32-.14-2.61.79-3.29.79-.68 0-1.71-.77-2.82-.75-1.43.02-2.77.85-3.51 2.15-1.52 2.63-.39 6.52 1.07 8.65.73 1.04 1.58 2.2 2.7 2.16 1.09-.04 1.5-.7 2.81-.7 1.31 0 1.68.7 2.82.67 1.17-.02 1.91-1.05 2.62-2.1.83-1.2 1.17-2.37 1.19-2.43-.03-.01-2.26-.87-2.28-3.48Zm-2.14-6.3c.59-.72 1-1.72.89-2.72-.86.04-1.9.58-2.51 1.3-.55.64-1.03 1.67-.9 2.65.95.07 1.93-.48 2.52-1.23Z"/></svg>
                        </span>
                        <h3 class="mt-5 text-lg font-extrabold text-[#0f172a]">iPhone و iPad</h3>
                        <p class="mt-2 text-sm font-medium leading-7 text-slate-600">حمّل تطبيق حاسم لأجهزة Apple.</p>
                        @if(filled($appStoreUrl))
                            <a href="{{ $appStoreUrl }}" class="hs-btn-primary mt-5 rounded-full px-5 py-2.5 text-sm" rel="noopener noreferrer" target="_blank">تحميل من App Store</a>
                        @else
                            <span title="APP_STORE_URL" class="mt-5 inline-flex cursor-not-allowed items-center rounded-full bg-[#06C2A4]/40 px-5 py-2.5 text-sm font-bold text-white">تحميل من App Store</span>
                        @endif
                    </article>

                    <article class="rounded-[1.4rem] border border-slate-100 bg-[#F7FCFB] p-6 text-right shadow-[0_16px_40px_-28px_rgba(15,23,42,0.35)]">
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-[#067e6b] shadow-sm">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.2 2.6 13 12l-9.8 9.4c-.4-.3-.7-.8-.7-1.4V4c0-.6.3-1.1.7-1.4Zm11.2 10.8 2.3 2.3-8.3 4.8 6-7.1Zm2.3-6.9-2.3 2.3-6-7.1 8.3 4.8ZM14.6 12l3.7-3.7 2.1 1.2c.9.5.9 1.8 0 2.3l-2.1 1.2L14.6 12Z"/></svg>
                        </span>
                        <h3 class="mt-5 text-lg font-extrabold text-[#0f172a]">Android</h3>
                        <p class="mt-2 text-sm font-medium leading-7 text-slate-600">حمّل تطبيق حاسم لأجهزة Android.</p>
                        @if(filled($googlePlayUrl))
                            <a href="{{ $googlePlayUrl }}" class="hs-btn-primary mt-5 rounded-full px-5 py-2.5 text-sm" rel="noopener noreferrer" target="_blank">تحميل من Google Play</a>
                        @else
                            <span title="GOOGLE_PLAY_URL" class="mt-5 inline-flex cursor-not-allowed items-center rounded-full bg-[#06C2A4]/40 px-5 py-2.5 text-sm font-bold text-white">تحميل من Google Play</span>
                        @endif
                    </article>

                    <article class="rounded-[1.4rem] border border-slate-100 bg-[#F7FCFB] p-6 text-right shadow-[0_16px_40px_-28px_rgba(15,23,42,0.35)]">
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-[#0f172a] shadow-sm">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 3h8.4v8.4H3V3Zm9.6 0H21v8.4h-8.4V3ZM3 12.6h8.4V21H3v-8.4Zm9.6 0H21V21h-8.4v-8.4Z"/></svg>
                        </span>
                        <h3 class="mt-5 text-lg font-extrabold text-[#0f172a]">Windows</h3>
                        <p class="mt-2 text-sm font-medium leading-7 text-slate-600">حمّل تطبيق حاسم على جهاز الكمبيوتر.</p>
                        @if(filled($windowsUrl))
                            <a href="{{ $windowsUrl }}" class="hs-btn-primary mt-5 rounded-full px-5 py-2.5 text-sm" rel="noopener noreferrer" target="_blank">تحميل لـ Windows</a>
                        @else
                            <span title="WINDOWS_DOWNLOAD_URL" class="mt-5 inline-flex cursor-not-allowed items-center rounded-full bg-[#06C2A4]/40 px-5 py-2.5 text-sm font-bold text-white">تحميل لـ Windows</span>
                        @endif
                    </article>
                </div>
                @if(! filled($appStoreUrl) && ! filled($googlePlayUrl) && ! filled($windowsUrl))
                    <p class="mx-auto mt-6 max-w-2xl text-center text-xs font-semibold text-slate-500">روابط المتاجر ستظهر هنا عند نشر التطبيقات. لا توجد روابط تحميل عامة في النظام حاليًا.</p>
                @endif
            </div>
        </section>

        <section id="apps" class="px-4 py-16 sm:px-6 lg:px-8" x-data="{ platform: 'apple' }">
            <div class="mx-auto max-w-7xl">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-extrabold text-[#0f172a]">تطبيقات حاسم في مكان واحد</h2>
                    <p class="mt-3 text-sm font-medium leading-7 text-slate-600">اختر النظام الذي تحتاجه، واستخدمه من جهازك بسهولة.</p>
                </div>

                <div class="mt-8 flex flex-wrap items-center justify-center gap-2">
                    <button type="button" @click="platform = 'apple'" :class="platform === 'apple' ? 'bg-[#06C2A4] text-white' : 'bg-white text-slate-700'" class="rounded-full border border-slate-200 px-4 py-2 text-sm font-extrabold shadow-sm">Apple</button>
                    <button type="button" @click="platform = 'android'" :class="platform === 'android' ? 'bg-[#06C2A4] text-white' : 'bg-white text-slate-700'" class="rounded-full border border-slate-200 px-4 py-2 text-sm font-extrabold shadow-sm">Android</button>
                    <button type="button" @click="platform = 'windows'" :class="platform === 'windows' ? 'bg-[#06C2A4] text-white' : 'bg-white text-slate-700'" class="rounded-full border border-slate-200 px-4 py-2 text-sm font-extrabold shadow-sm">Windows</button>
                </div>

                <div class="mt-10 grid gap-5 lg:grid-cols-3">
                    @foreach($apps as $app)
                        <article
                            x-show="{{ collect($app['platforms'])->map(fn ($p) => "platform === '{$p}'")->implode(' || ') }}"
                            x-cloak
                            class="flex min-w-0 flex-col overflow-hidden rounded-[1.4rem] border border-slate-100 bg-white shadow-[0_16px_40px_-28px_rgba(15,23,42,0.35)]"
                        >
                            <div class="bg-[#F7FCFB]">
                                <img src="{{ asset($app['image']) }}" alt="{{ $app['alt'] }}" class="hs-system-photo">
                            </div>
                            <div class="flex flex-1 flex-col px-5 pb-5 pt-4 text-right">
                                <p class="text-xl" aria-hidden="true">{{ $app['icon'] }}</p>
                                <h3 class="mt-2 text-lg font-extrabold text-[#0f172a]">{{ $app['title'] }}</h3>
                                <p class="mt-2 text-sm font-medium leading-7 text-slate-600">{{ $app['text'] }}</p>
                            </div>
                        </article>
                    @endforeach
                </div>
                <p class="mt-6 text-center text-xs font-semibold text-slate-500">
                    نعرض فقط التطبيقات المتوفرة على المنصة المحددة. تطبيق المحادثات متاح حاليًا لـ Apple وAndroid.
                </p>
            </div>
        </section>
    </main>

    @include('partials.public-site-footer')
    @include('partials.ai-assistant-widget')
</body>
</html>
