@extends('platform.layout')

@section('content')
    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4">
            @include('platform.partials.nav')
            @include('partials.flash')
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <form method="GET" class="flex flex-wrap gap-2">
                    <input name="search" value="{{ $search }}" class="rounded-lg border-gray-300 text-sm" placeholder="بحث بالمساحة أو المالك أو الإيميل" />
                    <select name="status" class="rounded-lg border-gray-300 text-sm">
                        <option value="">كل الحالات</option>
                        @foreach(['trialing','active','past_due','cancelled','expired'] as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                    <button class="rounded-lg bg-gray-800 px-3 py-2 text-sm text-white">تصفية</button>
                </form>
                <a href="{{ route('platform.subscriptions.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">منح اشتراك</a>
            </div>
            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">Workspace</th>
                            <th class="px-4 py-3 text-right">المالك</th>
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
                                <td class="px-4 py-3">{{ $subscription->workspace?->owner?->email ?? '-' }}</td>
                                <td class="px-4 py-3">{{ $subscription->plan?->display_name_ar ?: ($subscription->plan?->name ?? '-') }}</td>
                                <td class="px-4 py-3">{{ $subscription->status }}</td>
                                <td class="px-4 py-3">{{ $subscription->current_period_end }}</td>
                                <td class="px-4 py-3 text-left"><a href="{{ route('platform.subscriptions.edit', $subscription) }}" class="text-blue-600">تعديل</a></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                    <p>لا توجد اشتراكات.</p>
                                    <a href="{{ route('platform.subscriptions.create') }}" class="mt-3 inline-flex rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">منح اشتراك لمساحة عمل</a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $subscriptions->links() }}</div>
        </div>
    </div>
@endsection
