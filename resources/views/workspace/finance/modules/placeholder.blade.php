@extends('layouts.financial', ['pageTitle' => $title])

@section('content')
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-xl font-bold text-slate-900">{{ $title }}</h2>
        <p class="mt-2 text-sm text-slate-600">
            هذه الوحدة ضمن HASem Financial وتم تجهيز مسارها وقسمها داخل التطبيق المالي المستقل.
            سيتم توسيع إجراءاتها التفصيلية فوق نفس البنية المحاسبية الحالية بدون كسر أي وحدة موجودة.
        </p>
        <p class="mt-2 text-xs text-slate-500">Module Key: {{ $moduleKey }}</p>
    </div>
@endsection
