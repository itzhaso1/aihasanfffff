@extends('layouts.pos', ['pageTitle' => 'Menu'])

@section('content')
    <section class="grid gap-4 xl:grid-cols-3">
        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm xl:col-span-2">
            <h2 class="text-base font-bold text-slate-900">روابط الـ Menu</h2>
            <p class="mt-1 text-xs text-slate-500">المنيو العام والطاولات يعملان على أصناف الكاشير فقط.</p>

            <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
                <p class="text-xs text-slate-600">General Menu</p>
                <a href="{{ route('menu.general', ['workspace' => $workspace->slug]) }}" target="_blank" class="mt-1 block text-sm font-semibold text-slate-900">
                    {{ route('menu.general', ['workspace' => $workspace->slug]) }}
                </a>
            </div>

            <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-3 py-2 text-right">الطاولة</th>
                            <th class="px-3 py-2 text-right">الرابط</th>
                            <th class="px-3 py-2 text-right">QR</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tables as $table)
                            @php($menuUrl = route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token]))
                            <tr class="border-t border-slate-200">
                                <td class="px-3 py-2 font-semibold">{{ $table->name }}</td>
                                <td class="px-3 py-2">
                                    <a href="{{ $menuUrl }}" target="_blank" class="text-xs text-slate-700">{{ $menuUrl }}</a>
                                </td>
                                <td class="px-3 py-2">
                                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data={{ urlencode($menuUrl) }}" alt="QR" class="h-14 w-14 rounded">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="text-sm font-bold text-slate-900">نصيحة تشغيل</h3>
            <ul class="mt-3 list-disc space-y-2 pr-5 text-xs text-slate-600">
                <li>استخدم صفحة إدارة الأصناف لتحديث المنيو فورًا.</li>
                <li>تجديد QR للطاولة متاح من صفحة الطاولات.</li>
                <li>الطلبات القادمة من QR للطاولات تظهر مباشرة في صفحة المطبخ.</li>
            </ul>
        </article>
    </section>
@endsection
