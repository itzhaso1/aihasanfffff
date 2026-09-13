@extends('layouts.financial', ['pageTitle' => 'VAT'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold text-slate-900">ضريبة القيمة المضافة VAT</h2>

        <div class="grid gap-3 sm:grid-cols-3">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">ضريبة المخرجات</p>
                <p class="mt-2 text-2xl font-bold">{{ number_format((float) $vat['output'], 2) }}</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">ضريبة المدخلات</p>
                <p class="mt-2 text-2xl font-bold">{{ number_format((float) $vat['input'], 2) }}</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">صافي الضريبة</p>
                <p class="mt-2 text-2xl font-bold">{{ number_format((float) $vat['net'], 2) }}</p>
            </article>
        </div>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold">ملفات محرك الضريبة</h3>
            <div class="grid gap-2 md:grid-cols-2">
                @foreach($rates as $rate)
                    <div class="rounded-lg border border-slate-200 p-3 text-sm">
                        <p class="font-semibold">{{ $rate->name }} @if($rate->is_default)<span class="text-xs text-[#06C2A4]">(افتراضية)</span>@endif</p>
                        <p class="text-xs text-slate-500">{{ $rate->code }} | {{ $rate->type }} | {{ number_format((float) $rate->rate, 2) }}%</p>
                    </div>
                @endforeach
            </div>
        </article>
    </div>
@endsection
