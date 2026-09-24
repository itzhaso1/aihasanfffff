@extends('layouts.email', ['pageTitle' => $category['name']])

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('workspace.emails.templates.index') }}" class="text-sm font-semibold text-[#2563EB] hover:underline">كل التصنيفات</a>
                <h1 class="mt-2 text-2xl font-extrabold text-slate-900">{{ $category['name'] }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $category['name_en'] }} — {{ $category['description'] }}</p>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach($templates as $template)
                <article class="flex flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex h-40 items-center justify-center bg-slate-100">
                        @if($template['preview_url'])
                            <img src="{{ $template['preview_url'] }}" alt="" class="h-full w-full object-cover">
                        @else
                            <div class="px-6 text-center text-sm font-semibold text-slate-400">لا توجد صورة معاينة</div>
                        @endif
                    </div>
                    <div class="flex flex-1 flex-col p-5">
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="text-base font-bold text-slate-900">{{ $template['name'] }}</h2>
                            <span class="rounded-full px-2.5 py-1 text-[11px] font-bold {{ ($template['status'] ?? '') === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">
                                {{ ($template['status'] ?? '') === 'active' ? 'نشط' : 'مسودة' }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs font-semibold text-slate-400">{{ $template['name_en'] }}</p>
                        <p class="mt-3 flex-1 text-sm leading-6 text-slate-500">{{ $template['description'] }}</p>
                        <div class="mt-5 flex flex-wrap gap-2">
                            <a href="{{ route('workspace.emails.templates.preview', [$template['category'], $template['key']]) }}"
                               class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                معاينة
                            </a>
                            <form method="POST" action="{{ route('workspace.emails.templates.use', $template['key']) }}">
                                @csrf
                                <button type="submit" class="rounded-xl bg-black px-4 py-2 text-sm font-bold text-white hover:bg-slate-800">
                                    استخدام القالب
                                </button>
                            </form>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
@endsection
