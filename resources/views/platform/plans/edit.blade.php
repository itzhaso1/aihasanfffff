@extends('platform.layout')

@section('content')
    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-4xl px-4">
            @include('platform.partials.nav')
            @include('partials.flash')
            <h1 class="mb-4 text-xl font-bold text-slate-900">تعديل الباقة</h1>
            <form method="POST" action="{{ route('platform.plans.update', $plan) }}" class="space-y-4 rounded-xl border bg-white p-6">
                @csrf @method('PUT')
                @include('platform.plans.form', [
                    'plan' => $plan,
                    'commercialFeatures' => $commercialFeatures,
                    'limitFields' => $limitFields,
                    'featuresJson' => $featuresJson,
                    'permissionsJson' => $permissionsJson,
                    'limitsJson' => $limitsJson,
                    'overageJson' => $overageJson,
                ])
                <button class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white">تحديث الباقة</button>
            </form>
        </div>
    </div>
@endsection
