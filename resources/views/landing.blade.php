<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ ($publicSite ?? \App\Support\Site\PublicSite::current())->appName() }} | {{ ($publicSite ?? \App\Support\Site\PublicSite::current())->nameAr() }}</title>
    @include('partials.public-site-head')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=cairo:400,500,600,700,800&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        html { scroll-behavior: smooth; }
        .hs-landing-fade { animation: hsLandingFade .55s ease-out both; }
        .hs-landing-fade-delay { animation-delay: .08s; }
        @keyframes hsLandingFade {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: none; }
        }
        .hs-pain-banner {
            background: #EAF8F5;
            border-radius: 2rem;
            box-shadow: 0 20px 48px -30px rgba(15, 23, 42, 0.28);
            padding: 1.5rem 1.15rem;
        }
        .hs-pain-grid {
            display: flex;
            flex-direction: column;
            gap: 1.15rem;
            min-width: 0;
        }
        .hs-pain-visual {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            min-width: 0;
        }
        .hs-pain-photo {
            display: block;
            order: 1;
            width: 100%;
            height: 17rem;
            object-fit: cover;
            object-position: 46% 28%;
            -webkit-mask-image: linear-gradient(to bottom, transparent 0%, #000 10%, #000 82%, transparent 100%),
                                linear-gradient(to right, transparent 0%, #000 6%, #000 94%, transparent 100%);
            mask-image: linear-gradient(to bottom, transparent 0%, #000 10%, #000 82%, transparent 100%),
                        linear-gradient(to right, transparent 0%, #000 6%, #000 94%, transparent 100%);
            -webkit-mask-composite: destination-in;
            mask-composite: intersect;
        }
        .hs-pain-scribble {
            display: none;
        }
        .hs-pain-issues {
            display: flex;
            flex-direction: column;
            order: 2;
            gap: 0.55rem;
            min-width: 0;
        }
        .hs-pain-issue {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 0;
            border-radius: 999px;
            background: #fff;
            padding: 0.55rem 0.7rem;
            box-shadow: 0 10px 24px -16px rgba(15, 23, 42, 0.4);
        }
        .hs-pain-issue svg {
            width: 14px;
            height: 14px;
            flex: none;
        }
        .hs-pain-issue p {
            flex: 1;
            min-width: 0;
            margin: 0;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.5;
            color: #334155;
        }
        .hs-pain-x {
            display: inline-flex;
            height: 1.15rem;
            width: 1.15rem;
            flex: none;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: #FEECEC;
            color: #F43F5E;
            font-size: 11px;
            font-weight: 800;
            line-height: 1;
        }
        @media (min-width: 1024px) {
            .hs-pain-banner { padding: 1.1rem 1.6rem 0.85rem; }
            .hs-pain-grid {
                display: grid;
                grid-template-columns: minmax(18rem, 0.82fr) minmax(0, 1.28fr);
                align-items: center;
                gap: 0.5rem 0.5rem;
            }
            .hs-pain-visual {
                display: grid;
                grid-template-columns: minmax(15.25rem, 16.75rem) minmax(0, 1fr);
                align-items: center;
                gap: 0.35rem 0.4rem;
                direction: ltr;
            }
            .hs-pain-photo {
                order: 2;
                height: 21rem;
                object-position: 46% 30%;
            }
            .hs-pain-issues {
                order: 1;
                direction: rtl;
            }
            .hs-pain-scribble {
                display: block;
                position: absolute;
                top: 0.45rem;
                left: 58%;
                z-index: 2;
                width: 2.4rem;
                height: 2.1rem;
                color: #94A3B8;
                pointer-events: none;
            }
        }
        .hs-system-photo {
            display: block;
            width: 100%;
            height: 12.5rem;
            object-fit: contain;
            background: #F7FCFB;
        }
        @media (min-width: 1024px) {
            .hs-system-photo { height: 14rem; }
        }
        .hs-channels-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.85rem;
        }
        .hs-channel-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 0;
            padding: 1.15rem 0.75rem 1rem;
            background: #fff;
            border: 1px solid #e8eef3;
            border-radius: 1.35rem;
            box-shadow: 0 12px 28px -24px rgba(15, 23, 42, 0.35);
            text-align: center;
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
        }
        .hs-channel-icon {
            display: flex;
            height: 3.15rem;
            width: 3.15rem;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: #F7FCFB;
            box-shadow: 0 8px 18px -14px rgba(15, 23, 42, 0.35);
            transition: transform .2s ease;
        }
        .hs-channel-card h3 {
            margin: 0.85rem 0 0;
            font-size: 0.95rem;
            font-weight: 800;
            color: #0f172a;
        }
        .hs-channel-card p {
            margin: 0.3rem 0 0;
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1.6;
            color: #64748b;
        }
        .hs-channel-arrow {
            display: inline-flex;
            margin-top: 0.85rem;
            color: #94a3b8;
            transition: color .2s ease, transform .2s ease;
        }
        .hs-channel-card:hover {
            transform: translateY(-4px);
            border-color: #d7efe9;
            box-shadow: 0 18px 36px -22px rgba(15, 23, 42, 0.4);
        }
        .hs-channel-card:hover .hs-channel-icon {
            transform: translateY(-2px);
        }
        .hs-channel-card:hover .hs-channel-arrow {
            color: #06C2A4;
            transform: translateX(-2px);
        }
        .hs-channels-foot {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            margin-top: 1.75rem;
            padding: 0.5rem 0.9rem;
            border-radius: 999px;
            background: #fff;
            border: 1px solid #e8eef3;
            box-shadow: 0 10px 22px -20px rgba(15, 23, 42, 0.35);
            font-size: 0.78rem;
            font-weight: 800;
            color: #334155;
        }
        @media (min-width: 768px) {
            .hs-channels-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .hs-channel-card { padding: 1.25rem 0.9rem 1.05rem; }
        }
        @media (min-width: 1024px) {
            .hs-channels-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        @media (min-width: 1280px) {
            .hs-channels-grid { grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.75rem; }
            .hs-channel-card { padding: 1.2rem 0.55rem 1rem; }
            .hs-channel-card h3 { font-size: 0.86rem; }
            .hs-channel-card p { font-size: 0.68rem; }
        }
    </style>
</head>
<body class="overflow-x-hidden bg-[#F7FCFB] text-[#0f172a] antialiased" style="font-family: var(--hs-font)">
    @include('partials.public-topbar')

    <main>
        <section class="mx-auto max-w-7xl px-4 pb-14 pt-10 sm:px-6 lg:px-8 lg:pb-16 lg:pt-16">
            <div class="grid items-center gap-10 lg:grid-cols-2 lg:gap-12">
                <div class="hs-landing-fade">
                    <span class="inline-flex rounded-full border border-[#BDEFE5] bg-[#E8FAF6] px-3 py-1 text-xs font-bold text-[#067e6b]">
                        منصة {{ ($publicSite ?? \App\Support\Site\PublicSite::current())->nameAr() }} لإدارة الأعمال والمحادثات الذكية
                    </span>
                    <h1 class="mt-5 text-4xl font-extrabold leading-[1.3] text-[#0f172a] sm:text-5xl">
                        حوّل محادثاتك وعملاءك
                        <span class="block text-[#06C2A4]">إلى مبيعات حقيقية</span>
                    </h1>
                    <p class="mt-5 max-w-xl text-base font-medium leading-8 text-slate-600 sm:text-lg">
                        أدر واتساب، الطلبات، العملاء وفريقك من مكان واحد. منصة حاسم تجمع كل قنوات التواصل في لوحة موحدة، مع أدوات ذكية تساعدك على الرد بسرعة، متابعة الطلبات، وزيادة مبيعاتك.
                    </p>
                    <div class="mt-7 flex flex-wrap gap-3">
                        @auth
                            <a href="{{ route('workspace.subscriptions.index') }}" class="hs-btn-primary rounded-full px-6 py-3">إدارة الاشتراك</a>
                        @else
                            <a href="{{ route('register') }}" class="hs-btn-primary rounded-full px-6 py-3">
                                ابدأ مجانًا
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 19l-7-7 7-7" /></svg>
                            </a>
                            <a href="#journey" class="hs-btn-secondary rounded-full px-6 py-3">
                                <svg class="h-4 w-4 text-[#06C2A4]" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7L8 5z" /></svg>
                                شاهد كيف تعمل المنصة
                            </a>
                        @endauth
                    </div>
                    <ul class="mt-6 flex flex-wrap gap-x-5 gap-y-2 text-sm font-bold text-slate-600">
                        <li class="inline-flex items-center gap-2"><span class="text-[#06C2A4]">✓</span> بدون بطاقة ائتمان</li>
                        <li class="inline-flex items-center gap-2"><span class="text-[#06C2A4]">✓</span> إعداد سريع</li>
                        <li class="inline-flex items-center gap-2"><span class="text-[#06C2A4]">✓</span> دعم فني دائم</li>
                    </ul>
                </div>
                <div class="hs-landing-fade hs-landing-fade-delay min-w-0">
                    <img src="{{ asset('images/landing-inbox.jpg') }}" alt="واجهة المحادثات في حاسم" class="w-full max-w-full rounded-[1.6rem] object-contain">
                </div>
            </div>
        </section>

        <section id="channels" class="px-4 pb-16 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl text-center">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-[#BDEFE5] bg-[#E8FAF6] px-3 py-1 text-xs font-bold text-[#067e6b]">
                    <svg class="h-3.5 w-3.5 text-[#06C2A4]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 10V3L4 14h7v7l9-11h-7Z"/></svg>
                    جميع قنوات التواصل في مكان واحد
                </span>
                <h2 class="mt-4 text-3xl font-extrabold leading-[1.35] text-[#0f172a] sm:text-4xl">
                    كل قنوات عملائك
                    <span class="text-[#06C2A4]">في مكان واحد</span>
                </h2>
                <p class="mx-auto mt-3 max-w-xl text-sm font-medium leading-7 text-slate-600">اجمع محادثات عملائك من جميع القنوات وأدرها بسهولة.</p>

                <div class="hs-channels-grid mt-10">
                    @foreach([
                        ['label' => 'واتساب', 'text' => 'محادثات فورية', 'color' => '#25D366', 'icon' => 'whatsapp'],
                        ['label' => 'إنستغرام', 'text' => 'الرسائل والتعليقات', 'color' => '#E4405F', 'icon' => 'instagram'],
                        ['label' => 'ماسنجر', 'text' => 'محادثات العملاء', 'color' => '#0084FF', 'icon' => 'messenger'],
                        ['label' => 'تلغرام', 'text' => 'تواصل سريع', 'color' => '#2AABEE', 'icon' => 'telegram'],
                        ['label' => 'تيك توك', 'text' => 'الرسائل والتفاعل', 'color' => '#111827', 'icon' => 'tiktok'],
                        ['label' => 'البريد الإلكتروني', 'text' => 'إدارة الرسائل', 'color' => '#D97706', 'icon' => 'email'],
                        ['label' => 'المزيد', 'text' => 'قنوات أخرى', 'color' => '#94A3B8', 'icon' => 'more'],
                    ] as $channel)
                        <article class="hs-channel-card">
                            <span class="hs-channel-icon" style="color: {{ $channel['color'] }}">
                                @switch($channel['icon'])
                                    @case('whatsapp')
                                        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.5 6.5A7.42 7.42 0 0 0 12.2 4a7.7 7.7 0 0 0-6.66 11.52L4 20l4.63-1.5A7.7 7.7 0 0 0 12.2 20h.01a7.8 7.8 0 0 0 7.78-7.77A7.7 7.7 0 0 0 17.5 6.5Zm-5.29 12.2a6.42 6.42 0 0 1-3.27-.9l-.24-.14-2.74.89.9-2.67-.15-.27a6.42 6.42 0 1 1 5.5 3.09Z"/></svg>
                                        @break
                                    @case('instagram')
                                        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7.5 2h9A5.5 5.5 0 0 1 22 7.5v9a5.5 5.5 0 0 1-5.5 5.5h-9A5.5 5.5 0 0 1 2 16.5v-9A5.5 5.5 0 0 1 7.5 2Zm4.5 5a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 1.8a3.2 3.2 0 1 0 0 6.4 3.2 3.2 0 0 0 0-6.4Z"/></svg>
                                        @break
                                    @case('messenger')
                                        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2C6.47 2 2 6.15 2 11.3c0 2.94 1.46 5.56 3.74 7.26V22l3.18-1.75c.99.27 2.03.42 3.08.42 5.53 0 10-4.15 10-9.3S17.53 2 12 2Z"/></svg>
                                        @break
                                    @case('telegram')
                                        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21.5 4.5 3.7 11.3c-1.2.5-1.2 1.2-.2 1.5l4.6 1.4 10.6-6.7c.5-.3 1-.1.6.2l-8.6 7.8-.3 4.5c.5 0 .7-.2 1-.5l2.3-2.2 4.8 3.5c.9.5 1.5.2 1.7-.8l3.1-14.6c.3-1.3-.5-1.9-1.6-1.4Z"/></svg>
                                        @break
                                    @case('tiktok')
                                        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14 3c.4 2.6 2.1 4.4 4.6 4.7v3.1c-1.6 0-3.1-.5-4.4-1.4v6.3A6.7 6.7 0 1 1 8.8 9.2v3.3a3.5 3.5 0 1 0 2.4 3.3V3H14Z"/></svg>
                                        @break
                                    @case('email')
                                        <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 6.75A1.75 1.75 0 0 1 4.75 5h14.5A1.75 1.75 0 0 1 21 6.75v10.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75Zm1.75.35L12 12l7.25-4.9"/></svg>
                                        @break
                                    @default
                                        <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h.01M12 12h.01M19 12h.01"/></svg>
                                @endswitch
                            </span>
                            <h3>{{ $channel['label'] }}</h3>
                            <p>{{ $channel['text'] }}</p>
                            <span class="hs-channel-arrow" aria-hidden="true">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 19l-7-7 7-7" /></svg>
                            </span>
                        </article>
                    @endforeach
                </div>

                <div class="hs-channels-foot">
                    <svg class="h-4 w-4 text-[#06C2A4]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 7h4v4H4V7Zm6 0h4v4h-4V7Zm6 0h4v4h-4V7ZM4 13h4v4H4v-4Zm6 0h4v4h-4v-4Zm6 0h4v4h-4v-4Z"/></svg>
                    جميع القنوات في لوحة واحدة
                </div>
            </div>
        </section>

        <section id="features" class="bg-white py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-extrabold text-[#0f172a]">كل ما يحتاجه فريقك في مكان واحد</h2>
                    <p class="mt-3 text-sm font-medium leading-7 text-slate-600">من المحادثة إلى الطلب والمبيعات. أعمالك كلها من لوحة واحدة.</p>
                </div>
                <div class="mt-12 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach([
                        ['title' => 'المحادثات الموحدة', 'text' => 'اجمع محادثات عملائك من القنوات المختلفة في صندوق واحد.', 'icon' => 'chat'],
                        ['title' => 'إدارة الطلبات', 'text' => 'تابع الطلب من أول رسالة وحتى التأكيد والشحن.', 'icon' => 'box'],
                        ['title' => 'المبيعات والتقارير', 'text' => 'تابع مبيعاتك واطلع على تقارير دقيقة تساعدك على نمو عملك.', 'icon' => 'chart'],
                        ['title' => 'إدارة الفريق', 'text' => 'وزع المحادثات على أعضاء فريقك وتابع الأداء بسهولة.', 'icon' => 'team'],
                    ] as $card)
                        <article class="rounded-[1.4rem] border border-slate-100 bg-[#F7FCFB] px-6 py-8 text-center shadow-[0_10px_30px_-24px_rgba(15,23,42,0.35)]">
                            <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-[#06C2A4] shadow-sm">
                                @switch($card['icon'])
                                    @case('chat')
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7.5 17.25H4.875A1.875 1.875 0 0 1 3 15.375V5.625A1.875 1.875 0 0 1 4.875 3.75h14.25A1.875 1.875 0 0 1 21 5.625v9.75a1.875 1.875 0 0 1-1.875 1.875H12l-4.5 3v-3Z"/></svg>
                                        @break
                                    @case('box')
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>
                                        @break
                                    @case('chart')
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 19.5h18M6.75 16.5V10.5m5.25 6V6.75m5.25 9.75V8.25"/></svg>
                                        @break
                                    @default
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 19.5v-1.125A3.375 3.375 0 0 0 12.625 15h-4.25A3.375 3.375 0 0 0 5 18.375V19.5m11-9.375a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
                                @endswitch
                            </span>
                            <h3 class="mt-5 text-lg font-extrabold text-[#0f172a]">{{ $card['title'] }}</h3>
                            <p class="mt-2 text-sm font-medium leading-7 text-slate-600">{{ $card['text'] }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="px-4 py-10 sm:px-6 lg:px-8">
            <div class="hs-pain-banner mx-auto max-w-7xl">
                <div class="hs-pain-grid">
                    <div class="min-w-0 text-right">
                        <h2 class="text-3xl font-extrabold leading-snug text-[#0f172a] sm:text-[2.1rem]">
                            تعبت من التنقل بين <span style="color:#06C2A4">التطبيقات؟</span>
                        </h2>
                        <p class="mt-3 max-w-md text-sm font-medium leading-7 text-slate-600 sm:text-[15px]">
                            حاسم يجمع كل شيء في مكان واحد. لا مزيد من التنقل بين أدوات متعددة لإدارة عملائك وفريقك.
                        </p>
                        <ul class="mt-4 space-y-2 text-sm font-bold text-slate-700">
                            <li class="flex items-start gap-2"><span class="text-[#06C2A4]">✓</span><span>محادثات موحدة من جميع القنوات</span></li>
                            <li class="flex items-start gap-2"><span class="text-[#06C2A4]">✓</span><span>طلبات منظمة وسهلة المتابعة</span></li>
                            <li class="flex items-start gap-2"><span class="text-[#06C2A4]">✓</span><span>مراقبة أداء فريقك بكل سهولة</span></li>
                        </ul>
                        <div class="mt-5">
                            @auth
                                <a href="{{ route('workspace.dashboard') }}" class="hs-btn-primary rounded-full px-6 py-3">الانتقال إلى لوحة التحكم</a>
                            @else
                                <a href="{{ route('register') }}" class="hs-btn-primary rounded-full px-6 py-3">
                                    جرّب حاسم الآن
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 19l-7-7 7-7" /></svg>
                                </a>
                            @endauth
                        </div>
                    </div>

                    <div class="hs-pain-visual">
                        <svg class="hs-pain-scribble" viewBox="0 0 48 36" fill="none" aria-hidden="true">
                            <path d="M8 22c3-8 8-14 14-8 4 4 2 10-2 8 6 1 12-6 18-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                        </svg>
                        <img src="{{ asset('images/landing-person.jpg') }}" alt="صاحب عمل يدير عملياته من حاسم" class="hs-pain-photo">
                        <div class="hs-pain-issues">
                            <div class="hs-pain-issue">
                                <span class="inline-flex items-center gap-1" style="color:#25D366">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.5 6.5A7.42 7.42 0 0 0 12.2 4a7.7 7.7 0 0 0-6.66 11.52L4 20l4.63-1.5A7.7 7.7 0 0 0 12.2 20h.01a7.8 7.8 0 0 0 7.78-7.77A7.7 7.7 0 0 0 17.5 6.5Zm-5.29 12.2a6.42 6.42 0 0 1-3.27-.9l-.24-.14-2.74.89.9-2.67-.15-.27a6.42 6.42 0 1 1 5.5 3.09Z"/></svg>
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="#E4405F" aria-hidden="true"><path d="M7.5 2h9A5.5 5.5 0 0 1 22 7.5v9a5.5 5.5 0 0 1-5.5 5.5h-9A5.5 5.5 0 0 1 2 16.5v-9A5.5 5.5 0 0 1 7.5 2Zm4.5 5a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 1.8a3.2 3.2 0 1 0 0 6.4 3.2 3.2 0 0 0 0-6.4Z"/></svg>
                                </span>
                                <p>محادثات موزعة بين تطبيقات مختلفة</p>
                                <span class="hs-pain-x" aria-hidden="true">×</span>
                            </div>
                            <div class="hs-pain-issue">
                                <span class="inline-flex text-slate-500">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 6.75A1.75 1.75 0 0 1 4.75 5h14.5A1.75 1.75 0 0 1 21 6.75v10.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75Zm1.75.35L12 12l7.25-4.9"/></svg>
                                </span>
                                <p>طلبات تضيع وسط الرسائل</p>
                                <span class="hs-pain-x" aria-hidden="true">×</span>
                            </div>
                            <div class="hs-pain-issue">
                                <span class="inline-flex text-slate-500">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 19.5v-1.125A3.375 3.375 0 0 0 12.625 15h-4.25A3.375 3.375 0 0 0 5 18.375V19.5m11-9.375a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
                                </span>
                                <p>صعوبة متابعة أداء الفريق</p>
                                <span class="hs-pain-x" aria-hidden="true">×</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="systems" class="bg-white py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-extrabold text-[#0f172a]">ماذا يقدم حاسم؟</h2>
                    <p class="mt-3 text-sm font-medium leading-7 text-slate-600">منصة واحدة تجمع أدواتك الأساسية لإدارة عملك من مكان واحد.</p>
                </div>
                <div class="mt-10 grid gap-5 lg:grid-cols-3">
                    @foreach([
                        [
                            'title' => 'نظام المحادثات',
                            'text' => 'اجمع محادثات عملائك من واتساب وإنستغرام وماسنجر وتيليجرام وتيك توك والبريد في مكان واحد.',
                            'image' => 'images/landing-inbox.jpg',
                            'alt' => 'نظام المحادثات في حاسم',
                            'route' => 'workspace.conversations.index',
                        ],
                        [
                            'title' => 'المنتجات والمخزون',
                            'text' => 'اعرض منتجاتك وخدماتك الرقمية داخل المحادثة، مع المتغيرات والمخزون الرقمي.',
                            'image' => 'images/landing-inbox.jpg',
                            'alt' => 'المنتجات والمخزون في حاسم',
                            'route' => 'workspace.chat-catalog.index',
                        ],
                        [
                            'title' => 'العمال المستقلون',
                            'text' => 'اكتشف العمال، أرسل العروض، وتابع الارتباطات والأرباح من مساحة العمل.',
                            'image' => 'images/landing-person.jpg',
                            'alt' => 'العمال المستقلون في حاسم',
                            'route' => 'workspace.worker-discovery.index',
                        ],
                    ] as $system)
                        <article class="flex min-w-0 flex-col overflow-hidden rounded-[1.4rem] border border-slate-100 bg-white shadow-[0_16px_40px_-28px_rgba(15,23,42,0.35)]">
                            <div class="bg-[#F7FCFB]">
                                <img src="{{ asset($system['image']) }}" alt="{{ $system['alt'] }}" class="hs-system-photo">
                            </div>
                            <div class="flex flex-1 flex-col px-5 pb-5 pt-4 text-right">
                                <h3 class="text-lg font-extrabold text-[#0f172a]">{{ $system['title'] }}</h3>
                                <p class="mt-2 text-sm font-medium leading-7 text-slate-600">{{ $system['text'] }}</p>
                                <a href="{{ route($system['route']) }}" class="mt-4 inline-flex items-center gap-2 self-start text-sm font-extrabold text-[#06C2A4]">
                                    استكشف النظام
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 19l-7-7 7-7" /></svg>
                                </a>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        <section id="journey" class="px-4 py-16 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-6xl text-center">
                <h2 class="text-3xl font-extrabold text-[#0f172a]">من أول رسالة إلى آخر عملية بيع</h2>
                <p class="mx-auto mt-3 max-w-2xl text-sm font-medium leading-7 text-slate-600">رحلة العميل الرسمية داخل المنصة: من المحادثة حتى الطلب والشحن والبيع.</p>
                <div class="relative mt-12">
                    <div class="pointer-events-none absolute inset-x-8 top-7 hidden h-px bg-[#D8F3EE] lg:block"></div>
                    <div class="grid grid-cols-2 gap-x-3 gap-y-8 sm:grid-cols-4 lg:grid-cols-7">
                        @foreach([
                            ['label' => 'رسالة العميل', 'icon' => 'M7.5 17.25H4.875A1.875 1.875 0 0 1 3 15.375V5.625A1.875 1.875 0 0 1 4.875 3.75h14.25A1.875 1.875 0 0 1 21 5.625v9.75a1.875 1.875 0 0 1-1.875 1.875H12l-4.5 3v-3Z'],
                            ['label' => 'المحادثة', 'icon' => 'M8 10h8M8 14h5m5 7-3.7-2H8a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v14Z'],
                            ['label' => 'بيانات العميل', 'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
                            ['label' => 'الطلب', 'icon' => 'M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z'],
                            ['label' => 'التأكيد', 'icon' => 'm9 12 2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                            ['label' => 'الشحن', 'icon' => 'M3 7h11v10H3V7Zm11 3h4l3 3v4h-7V10ZM7 21a2 2 0 100-4 2 2 0 000 4Zm10 0a2 2 0 100-4 2 2 0 000 4Z'],
                            ['label' => 'تم البيع', 'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V7m0 10v1M5 12a7 7 0 1114 0 7 7 0 01-14 0Z'],
                        ] as $step)
                            <div class="relative flex flex-col items-center">
                                <span class="relative z-10 flex h-14 w-14 items-center justify-center rounded-full border border-[#D8F3EE] bg-white text-[#06C2A4] shadow-sm">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $step['icon'] }}" /></svg>
                                </span>
                                <p class="mt-3 text-sm font-extrabold text-[#0f172a]">{{ $step['label'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <section id="ai" class="bg-white py-16">
            <div class="mx-auto grid max-w-6xl items-center gap-10 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
                <div>
                    <h2 class="text-3xl font-extrabold text-[#0f172a]">ذكاء يساعد فريقك على البيع بشكل أسرع</h2>
                    <p class="mt-4 text-base font-medium leading-8 text-slate-600">
                        مساعد حاسم موجود داخل المنصة لدعم الدخول والتنقل والإجابة على أسئلة الفريق. نفس أداة الدعم الحالية، دون اختراع قدرات غير مفعّلة.
                    </p>
                    <a href="#support" class="mt-6 inline-flex items-center gap-2 text-sm font-extrabold text-[#06C2A4]">
                        اكتشف المزيد
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 19l-7-7 7-7" /></svg>
                    </a>
                </div>
                <div class="overflow-hidden rounded-[1.4rem] border border-slate-200 bg-white shadow-[0_18px_40px_-28px_rgba(15,23,42,0.4)]">
                    <div class="flex items-center justify-between bg-[#06C2A4] px-4 py-3 text-white">
                        <div class="flex items-center gap-2.5">
                            <img
                                src="{{ asset('images/hasim-assistant-icon.jpg') }}"
                                alt=""
                                class="h-9 w-9 rounded-full object-cover object-center ring-2 ring-white/30"
                            >
                            <div>
                                <p class="text-sm font-extrabold">مساعد حاسم</p>
                                <p class="text-[11px] font-medium text-white/90">دعم فوري للدخول والتنقل داخل المنصة</p>
                            </div>
                        </div>
                    </div>
                    <div class="space-y-3 bg-[#F7FCFB] p-5">
                        <div class="max-w-[88%] rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium leading-7 text-slate-800">
                            مرحبًا، أنا مساعد حاسم. كيف يمكنني مساعدتك اليوم؟
                        </div>
                        <div class="ms-auto max-w-[80%] rounded-2xl bg-[#06C2A4] px-3 py-2 text-sm font-medium leading-7 text-white">
                            كيف أبدأ من لوحة التحكم؟
                        </div>
                    </div>
                    <div class="border-t border-slate-100 p-3">
                        <div class="flex items-center gap-2">
                            <span class="flex-1 rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium text-slate-400">اكتب سؤالك...</span>
                            <span class="rounded-xl bg-[#06C2A4] px-3 py-2 text-xs font-bold text-white">إرسال</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="pricing" class="px-4 py-16 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-7xl">
                <div class="mx-auto max-w-3xl text-center">
                    <h2 class="text-3xl font-extrabold text-[#0f172a]">الأسعار</h2>
                    <p class="mt-3 text-sm font-medium text-slate-600">الباقات الرسمية المعروضة من النظام فقط.</p>
                </div>
                @php $officialPlans = $officialPlans ?? collect(); @endphp
                @if($officialPlans->count() > 0)
                    <div class="mt-10 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
                        @foreach($officialPlans as $plan)
                            <article class="rounded-2xl border {{ $plan->code === 'pro' ? 'border-2 border-[#06C2A4] bg-[#E8FAF6]' : 'border-slate-200 bg-white' }} p-6 shadow-sm">
                                <p class="text-sm font-bold {{ $plan->code === 'pro' ? 'text-[#069c83]' : 'text-slate-500' }}">{{ strtoupper($plan->code) }}</p>
                                <h3 class="mt-2 text-xl font-extrabold text-[#0f172a]">{{ $plan->display_name_ar ?: $plan->name }}</h3>
                                <p class="mt-2 text-2xl font-extrabold text-[#06C2A4]">
                                    {{ number_format((float) $plan->price, 2) }} <span class="text-sm font-bold text-slate-600">{{ $plan->currency }}</span>
                                </p>
                                <p class="mt-1 text-xs font-semibold text-slate-600">الفترة: {{ $plan->billing_period === 'yearly' ? 'سنوي' : ($plan->billing_period === 'monthly' ? 'شهري' : $plan->billing_period) }}</p>
                                <div class="mt-3 flex flex-wrap gap-1">
                                    @foreach(\App\Support\Plans\PlanCardHighlights::labels($plan) as $feature)
                                        <span class="rounded-md bg-white/80 px-2 py-1 text-[11px] font-semibold text-slate-700">{{ $feature }}</span>
                                    @endforeach
                                </div>
                                <div class="mt-5">
                                    @auth
                                        <a href="{{ route('workspace.subscriptions.index') }}" class="inline-flex w-full justify-center rounded-full bg-[#06C2A4] px-4 py-2.5 text-sm font-bold text-white hover:bg-[#04a98e]">اشترك الآن</a>
                                    @else
                                        <a href="{{ route('register') }}" class="inline-flex w-full justify-center rounded-full bg-[#06C2A4] px-4 py-2.5 text-sm font-bold text-white hover:bg-[#04a98e]">سجّل وابدأ الاشتراك</a>
                                    @endauth
                                </div>
                            </article>
                        @endforeach
                    </div>
                @else
                    <div class="mt-8 rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm font-medium text-slate-600">
                        لا توجد باقات مفعلة حاليًا. يمكن لمدير المنصة إعدادها من لوحة الأدمن.
                    </div>
                @endif
            </div>
        </section>

        <section id="blog" class="sr-only">
            <h2>المدونة</h2>
            <p>لا توجد مقالات منشورة من النظام حاليًا.</p>
        </section>

        <section class="bg-[#06C2A4] px-4 py-16 text-center sm:px-6 lg:px-8">
            <div class="mx-auto max-w-3xl">
                <h2 class="text-3xl font-extrabold text-white sm:text-4xl">جاهز تدير محادثاتك ومبيعاتك من مكان واحد؟</h2>
                <p class="mx-auto mt-4 max-w-xl text-sm font-medium leading-7 text-white/90">ابدأ باستخدام {{ ($publicSite ?? \App\Support\Site\PublicSite::current())->nameAr() }} واجعل فريقك أكثر تنظيمًا وسرعة.</p>
                <div class="mt-8">
                    @auth
                        <a href="{{ route('workspace.dashboard') }}" class="inline-flex items-center justify-center rounded-full bg-white px-8 py-3 text-sm font-extrabold text-[#067e6b] shadow-sm">الانتقال إلى لوحة التحكم</a>
                    @else
                        <a href="{{ route('register') }}" class="inline-flex items-center gap-2 rounded-full bg-white px-8 py-3 text-sm font-extrabold text-[#067e6b] shadow-sm">
                            ابدأ مجانًا
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 19l-7-7 7-7" /></svg>
                        </a>
                    @endauth
                </div>
            </div>
        </section>
    </main>

    @include('partials.public-site-footer')

    @include('partials.ai-assistant-widget')
</body>
</html>
