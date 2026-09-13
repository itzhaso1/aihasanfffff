@extends('platform.layout')

@section('content')
    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4">
            @include('platform.partials.nav')
            @include('partials.flash')
            <form method="GET" class="mb-4 flex gap-2">
                <select name="status" class="rounded-lg border-gray-300 text-sm">
                    <option value="">كل الحالات</option>
                    @foreach(['trialing','active','past_due','cancelled','expired'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
                <button class="rounded-lg bg-gray-800 px-3 py-2 text-sm text-white">تصفية</button>
            </form>
            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">Workspace</th>
                            <th class="px-4 py-3 text-right">Plan</th>
                            <th class="px-4 py-3 text-right">Status</th>
                            <th class="px-4 py-3 text-right">Current Period End</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($subscriptions as $subscription)
                            <tr>
                                <td class="px-4 py-3">{{ $subscription->workspace?->name ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $subscription->plan?->name ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $subscription->status }}</td>
                                <td class="px-4 py-3">{{ $subscription->current_period_end }}</td>
                                <td class="px-4 py-3 text-left"><a href="{{ route('platform.subscriptions.edit', $subscription) }}" class="text-blue-600">تعديل</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">لا توجد اشتراكات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $subscriptions->links() }}</div>
        </div>
    </div>
@endsection
