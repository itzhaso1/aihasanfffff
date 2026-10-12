@extends('platform.layout')

@section('content')
    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-4xl px-4">
            @include('platform.partials.nav')
            @include('partials.flash')
            <h1 class="mb-4 text-xl font-bold text-slate-900">إضافة باقة</h1>
            <form method="POST" action="{{ route('platform.plans.store') }}" class="space-y-4 rounded-xl border bg-white p-6">
                @csrf
                @include('platform.plans.form', [
                    'commercialFeatures' => $commercialFeatures,
                    'limitFields' => $limitFields,
                ])
                <button class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white">حفظ الباقة</button>
            </form>
        </div>
    </div>
@endsection
