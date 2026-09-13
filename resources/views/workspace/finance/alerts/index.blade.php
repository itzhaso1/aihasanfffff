@extends('layouts.financial', ['pageTitle' => 'مركز التنبيهات'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold">تنبيهات الأعمال</h2>
        <div class="space-y-3">
            @forelse($alerts as $alert)
                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="font-bold">{{ $alert['title'] }}</h3>
                        <span class="rounded-full bg-slate-100 px-2 py-1 text-xs">{{ $alert['severity'] }}</span>
                    </div>
                    <p class="mt-2 text-sm text-slate-700">{{ $alert['reason'] }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $alert['action'] }}</p>
                </article>
            @empty
                <p class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-slate-500">لا توجد تنبيهات الآن.</p>
            @endforelse
        </div>
    </div>
@endsection
