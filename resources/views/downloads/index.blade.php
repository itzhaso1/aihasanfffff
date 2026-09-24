@extends('layouts.public')

@section('title', 'تحميل التطبيقات | '.($publicSite->appName() ?? config('app.name', 'HASEM')))

@push('head')
    <style>
        html { scroll-behavior: smooth; }
        .hs-device-phone {
            width: 8.4rem;
            border-radius: 1.35rem;
            border: 3px solid #172024;
            background: #172024;
            box-shadow: 0 18px 36px -24px rgba(23, 32, 36, 0.45);
            overflow: hidden;
        }
        .hs-device-phone img { display: block; width: 100%; height: 15.5rem; object-fit: cover; object-position: top; }
        .hs-device-laptop {
            width: min(100%, 22rem);
            border-radius: 0.9rem 0.9rem 0.35rem 0.35rem;
            border: 3px solid #172024;
            background: #172024;
            overflow: hidden;
            box-shadow: 0 18px 36px -24px rgba(23, 32, 36, 0.45);
        }
        .hs-device-laptop img { display: block; width: 100%; height: 13.5rem; object-fit: cover; object-position: top; }
        .hs-device-laptop-base {
            height: 0.7rem;
            margin: 0 auto;
            width: 72%;
            border-radius: 0 0 0.7rem 0.7rem;
            background: #D5EBE7;
        }
        .hs-app-shot {
            display: block;
            width: 100%;
            height: 14rem;
            object-fit: cover;
            object-position: top;
            background: #F3FCFA;
        }
        @media (min-width: 1024px) {
            .hs-device-phone { width: 9.2rem; }
            .hs-device-phone img { height: 17.2rem; }
            .hs-device-laptop { width: 26rem; }
            .hs-device-laptop img { height: 15rem; }
            .hs-app-shot { height: 16rem; }
        }
    </style>
@endpush

@section('content')
    <main>
        <section class="mx-auto max-w-7xl px-4 pb-12 pt-10 sm:px-6 lg:px-8 lg:pb-16 lg:pt-16">
            <div class="grid items-center gap-10 lg:grid-cols-2 lg:gap-12">
                <div>
                    <span class="inline-flex rounded-full border border-[#D5EBE7] bg-[#E6F7F2] px-3 py-1 text-xs font-bold text-[#067e6b]">بوابة تطبيقات حاسم</span>
                    <h1 class="mt-5 text-4xl font-extrabold leading-[1.3] text-[#172024] sm:text-5xl">حمّل التطبيق المناسب لعملك</h1>
                    <p class="mt-5 max-w-xl text-base font-medium leading-8 text-[#6F7B7B] sm:text-lg">
                        ثلاثة تطبيقات مستقلة تحت منصة حاسم: شات للمحادثات، المالية للفواتير، والكاشير لنقطة البيع. اختر التطبيق ثم حمّله من المنصات المتوفرة فعلًا.
                    </p>
                </div>
                <div class="flex min-w-0 flex-wrap items-end justify-center gap-4 lg:gap-5">
                    <div class="hs-device-phone">
                        <img src="{{ asset('images/landing-inbox.jpg') }}" alt="لقطة من حاسم شات">
                    </div>
                    <div class="order-first w-full sm:order-none sm:w-auto">
                        <div class="hs-device-laptop mx-auto">
                            <img src="{{ asset('images/landing-invoicing.jpg') }}" alt="لقطة من حاسم المالية">
                        </div>
                        <div class="hs-device-laptop-base"></div>
                    </div>
                    <div class="hs-device-phone">
                        <img src="{{ asset('images/landing-cashier.jpg') }}" alt="لقطة من حاسم الكاشير">
                    </div>
                </div>
            </div>
        </section>

        <section id="apps" class="bg-white py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-extrabold text-[#172024]">تطبيقات حاسم</h2>
                    <p class="mt-3 text-sm font-medium leading-7 text-[#6F7B7B]">كل تطبيق له صفحة مستقلة وروابط تحميل خاصة به.</p>
                </div>

                <div class="mt-10 grid gap-6 lg:grid-cols-3">
                    @foreach($apps as $app)
                        <article class="flex min-w-0 flex-col overflow-hidden rounded-[1.4rem] border border-[#D5EBE7] bg-white shadow-[0_16px_40px_-28px_rgba(23,32,36,0.28)]">
                            <div class="bg-[#F3FCFA]">
                                <img src="{{ asset($app['image']) }}" alt="{{ $app['alt'] }}" class="hs-app-shot">
                            </div>
                            <div class="flex flex-1 flex-col px-5 pb-6 pt-5 text-right">
                                <p class="text-xs font-extrabold text-[#00BFA6]">{{ $app['eyebrow'] }}</p>
                                <h3 class="mt-2 text-xl font-extrabold text-[#172024]">{{ $app['name'] }}</h3>
                                <p class="mt-2 text-sm font-medium leading-7 text-[#6F7B7B]">{{ $app['tagline'] }}</p>
                                <ul class="mt-4 space-y-2 text-sm font-semibold text-[#172024]">
                                    @foreach(array_slice($app['features'], 0, 3) as $feature)
                                        <li class="flex items-start gap-2">
                                            <span class="mt-1 inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-[#00BFA6]"></span>
                                            <span>{{ $feature }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                <a href="{{ route('downloads.app', $app['key']) }}" class="hs-btn-primary mt-6 rounded-full px-5 py-2.5 text-sm">عرض التطبيق</a>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    </main>
@endsection
