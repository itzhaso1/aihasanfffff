@extends('layouts.financial', ['pageTitle' => 'بحث مالي'])

@section('content')
    <div class="space-y-4">
        <form method="GET" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <input type="search" name="q" value="{{ $term }}" placeholder="ابحث عن عميل، فاتورة، منتج، مورد..." class="w-full rounded-lg border-slate-300 text-sm">
        </form>
        @forelse($results as $group => $rows)
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">{{ $group }}</h3>
                <div class="space-y-2">
                    @forelse($rows as $row)
                        <a href="{{ $row['url'] }}" class="block rounded-lg border border-slate-100 px-3 py-2 hover:bg-slate-50">
                            <p class="font-semibold">{{ $row['title'] }}</p>
                            <p class="text-xs text-slate-500">{{ $row['subtitle'] }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-slate-500">لا نتائج.</p>
                    @endforelse
                </div>
            </article>
        @empty
            <p class="text-sm text-slate-500">أدخل كلمة بحث من حرفين على الأقل.</p>
        @endforelse
    </div>
@endsection
