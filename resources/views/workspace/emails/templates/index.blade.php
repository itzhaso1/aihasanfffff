@extends('layouts.email', ['pageTitle' => 'قوالب البريد'])

@section('content')
    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold tracking-wide text-slate-500">مكتبة القوالب</p>
            <h1 class="mt-1 text-2xl font-extrabold text-slate-900">قوالب البريد</h1>
            <p class="mt-2 max-w-2xl text-sm leading-7 text-slate-500">
                اختر تصنيفًا ثم اضغط «استخدام القالب» لأي قالب في المكتبة. بعد الاختيار يمكنك تخصيص ظهوره. علامة مسودة/نشط للعرض فقط ولا تمنع الاختيار.
            </p>
        </div>

        @if($preference)
            <div class="rounded-2xl border border-slate-200 bg-white px-5 py-4 text-sm text-slate-600">
                القالب المختار حاليًا:
                <span class="font-bold text-slate-900">{{ $preference->template_key }}</span>
                <a href="{{ route('workspace.emails.templates.edit') }}" class="ms-3 font-semibold text-[#2563EB] hover:underline">متابعة التخصيص</a>
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach($categories as $category)
                <a href="{{ route('workspace.emails.templates.category', $category['slug']) }}"
                   class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm transition hover:border-slate-300 hover:shadow">
                    <h2 class="text-lg font-bold text-slate-900">{{ $category['name'] }}</h2>
                    <p class="mt-1 text-xs font-semibold text-slate-400">{{ $category['name_en'] }}</p>
                    <p class="mt-3 text-sm leading-6 text-slate-500">{{ $category['description'] }}</p>
                    <p class="mt-4 text-xs font-semibold text-slate-500">{{ $category['template_count'] }} قالب</p>
                </a>
            @endforeach
        </div>
    </div>
@endsection
