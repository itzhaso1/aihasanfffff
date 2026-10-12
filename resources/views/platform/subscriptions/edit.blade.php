@extends('platform.layout')

@section('content')
    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4">
            @include('platform.partials.nav')
            @include('partials.flash')
            <form method="POST" action="{{ route('platform.subscriptions.update', $subscription) }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf @method('PUT')
                <div>
                    <label class="mb-1 block text-sm">الخطة</label>
                    <select name="plan_id" class="w-full rounded-lg border-gray-300">
                        @foreach($plans as $plan)
                            <option value="{{ $plan->id }}" @selected(old('plan_id', $subscription->plan_id) == $plan->id)>{{ $plan->name }} ({{ $plan->workspace_type }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm">الحالة</label>
                    <select name="status" class="w-full rounded-lg border-gray-300">
                        @foreach(['trialing','active','past_due','cancelled','expired'] as $status)
                            <option value="{{ $status }}" @selected(old('status', $subscription->status) === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm">نهاية الفترة</label>
                    <input type="datetime-local" name="current_period_end" value="{{ old('current_period_end', optional($subscription->current_period_end)->format('Y-m-d\\TH:i')) }}" class="w-full rounded-lg border-gray-300" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">نهاية التجربة</label>
                    <input type="datetime-local" name="trial_ends_at" value="{{ old('trial_ends_at', optional($subscription->trial_ends_at)->format('Y-m-d\\TH:i')) }}" class="w-full rounded-lg border-gray-300" />
                </div>
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-white">حفظ</button>
            </form>
        </div>
    </div>
@endsection
