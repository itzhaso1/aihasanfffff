@extends('layouts.public')

@section('title', $app['name'].' | تحميل التطبيقات | '.($publicSite->appName() ?? config('app.name', 'HASEM')))

@push('head')
    <style>
        .hs-hero-shot {
            display: block;
            width: 100%;
            height: 18rem;
            object-fit: cover;
            object-position: top;
            background: #F3FCFA;
        }
        @media (min-width: 1024px) {
            .hs-hero-shot { height: 28rem; }
        }
    </style>
@endpush

@section('content')
    <main>
        <section class="mx-auto max-w-7xl px-4 pb-10 pt-8 sm:px-6 lg:px-8 lg:pb-14 lg:pt-12">
            <nav class="text-sm font-bold text-[#6F7B7B]">
                <a href="{{ route('downloads') }}" class="hover:text-[#00BFA6]">تحميل التطبيقات</a>
                <span class="mx-2">/</span>
                <span class="text-[#172024]">{{ $app['name'] }}</span>
            </nav>

            <div class="mt-8 grid items-center gap-10 lg:grid-cols-[1.05fr_0.95fr] lg:gap-14">
                <div>
                    <span class="inline-flex rounded-full border border-[#D5EBE7] bg-[#E6F7F2] px-3 py-1 text-xs font-bold text-[#067e6b]">{{ $app['eyebrow'] }}</span>
                    <h1 class="mt-5 text-4xl font-extrabold leading-[1.3] text-[#172024] sm:text-5xl">{{ $app['name'] }}</h1>
                    <p class="mt-5 max-w-xl text-lg font-bold leading-8 text-[#172024]">{{ $app['tagline'] }}</p>
                    <p class="mt-4 max-w-xl text-base font-medium leading-8 text-[#6F7B7B]">{{ $app['description'] }}</p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        @forelse($app['downloads'] as $download)
                            <a href="{{ $download['url'] }}" class="hs-btn-primary rounded-full px-5 py-3 text-sm" rel="noopener noreferrer" target="_blank">
                                @include('partials.downloads-platform-icon', ['platform' => $download['key']])
                                {{ $download['label'] }}
                            </a>
                        @empty
                            <p class="text-sm font-semibold text-[#6F7B7B]">روابط التحميل ستظهر هنا عند نشر هذا التطبيق.</p>
                        @endforelse
                    </div>
                </div>
                <div class="overflow-hidden rounded-[1.8rem] border border-[#D5EBE7] bg-white shadow-[0_24px_50px_-32px_rgba(23,32,36,0.35)]">
                    <img src="{{ asset($app['image']) }}" alt="{{ $app['alt'] }}" class="hs-hero-shot">
                </div>
            </div>
        </section>

        <section class="bg-white py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <h2 class="text-3xl font-extrabold text-[#172024]">صور من التطبيق</h2>
                <div class="mt-8 grid gap-5 md:grid-cols-2">
                    @foreach($app['gallery'] as $shot)
                        <figure class="overflow-hidden rounded-[1.4rem] border border-[#D5EBE7] bg-[#F3FCFA]">
                            <img src="{{ asset($shot['src']) }}" alt="{{ $shot['alt'] }}" class="h-72 w-full object-cover object-top sm:h-80">
                            <figcaption class="px-5 py-3 text-sm font-bold text-[#6F7B7B]">{{ $shot['alt'] }}</figcaption>
                        </figure>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="py-16">
            <div class="mx-auto grid max-w-7xl gap-6 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
                <article class="rounded-[1.4rem] border border-[#D5EBE7] bg-white p-6 shadow-[0_16px_40px_-28px_rgba(23,32,36,0.28)]">
                    <h2 class="text-2xl font-extrabold text-[#172024]">أهم المميزات</h2>
                    <ul class="mt-5 space-y-3 text-sm font-semibold leading-7 text-[#172024]">
                        @foreach($app['features'] as $feature)
                            <li class="flex items-start gap-3">
                                <span class="mt-2 inline-block h-2 w-2 shrink-0 rounded-full bg-[#00BFA6]"></span>
                                <span>{{ $feature }}</span>
                            </li>
                        @endforeach
                    </ul>
                </article>
                <article class="rounded-[1.4rem] border border-[#D5EBE7] bg-white p-6 shadow-[0_16px_40px_-28px_rgba(23,32,36,0.28)]">
                    <h2 class="text-2xl font-extrabold text-[#172024]">لمن هذا التطبيق؟</h2>
                    <p class="mt-5 text-sm font-medium leading-8 text-[#6F7B7B]">{{ $app['audience'] }}</p>
                </article>
            </div>
        </section>

        <section id="download" class="bg-white py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="rounded-[1.8rem] border border-[#D5EBE7] bg-[#F3FCFA] px-6 py-10 sm:px-10">
                    <h2 class="text-3xl font-extrabold text-[#172024]">خيارات التحميل</h2>
                    <p class="mt-3 max-w-2xl text-sm font-medium leading-7 text-[#6F7B7B]">نعرض فقط المنصات التي يتوفر لها رابط نشر حقيقي لهذا التطبيق.</p>
                    <div class="mt-8 grid gap-4 md:grid-cols-3">
                        @forelse($app['downloads'] as $download)
                            <a href="{{ $download['url'] }}" class="flex items-center gap-4 rounded-[1.2rem] border border-[#D5EBE7] bg-white px-5 py-4 shadow-sm transition hover:border-[#00BFA6]" rel="noopener noreferrer" target="_blank">
                                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-[#E6F7F2] text-[#067e6b]">
                                    @include('partials.downloads-platform-icon', ['platform' => $download['key']])
                                </span>
                                <span>
                                    <span class="block text-xs font-bold text-[#6F7B7B]">{{ $download['hint'] }}</span>
                                    <span class="mt-1 block text-sm font-extrabold text-[#172024]">{{ $download['label'] }}</span>
                                </span>
                            </a>
                        @empty
                            <p class="text-sm font-semibold text-[#6F7B7B]">لا توجد روابط تحميل عامة لهذا التطبيق حاليًا.</p>
                        @endforelse
                    </div>
                    <a href="{{ route('downloads') }}" class="mt-8 inline-flex text-sm font-extrabold text-[#00BFA6]">العودة إلى كل التطبيقات</a>
                </div>
            </div>
        </section>
    </main>
@endsection
