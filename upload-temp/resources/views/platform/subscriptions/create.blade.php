@extends('platform.layout')

@section('content')
    <div class="py-8">
        <div class="mx-auto max-w-3xl px-4">
            @include('platform.partials.nav')
            @include('partials.flash')
            <h2 class="mb-4 text-lg font-semibold">منح اشتراك لمساحة عمل</h2>
            <p class="mb-4 text-sm text-gray-600">اختر أي مستخدم/مساحة عمل وأعطه باقة من هنا. هذا التفعيل من الإدارة ولا يحتاج دفع.</p>
            <form method="POST" action="{{ route('platform.subscriptions.store') }}" class="rounded-xl border bg-white p-6 space-y-4">
                @csrf
                <div>
                    <label class="mb-1 block text-sm">مساحة العمل</label>
                    <select name="workspace_id" class="w-full rounded-lg border-gray-300" required>
                        <option value="">اختر مساحة العمل</option>
                        @foreach($workspaces as $workspace)
                            <option value="{{ $workspace->id }}" @selected((string) old('workspace_id', $selectedWorkspaceId) === (string) $workspace->id)>
                                {{ $workspace->name }} — {{ $workspace->owner?->email ?? 'بدون مالك' }} ({{ $workspace->type }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm">الباقة</label>
                    <select name="plan_id" class="w-full rounded-lg border-gray-300" required>
                        @foreach($plans as $plan)
                            <option value="{{ $plan->id }}" @selected(old('plan_id', $plans->firstWhere('code', 'enterprise')?->id) == $plan->id)>
                                {{ $plan->display_name_ar ?: $plan->name }} ({{ $plan->code }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm">الحالة</label>
                    <select name="status" class="w-full rounded-lg border-gray-300">
                        <option value="active" @selected(old('status', 'active') === 'active')>مفعّل (active)</option>
                        <option value="trialing" @selected(old('status') === 'trialing')">تجربة (trialing)</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm">نهاية الفترة</label>
                    <input type="datetime-local" name="current_period_end" value="{{ old('current_period_end', now()->addYear()->format('Y-m-d\\TH:i')) }}" class="w-full rounded-lg border-gray-300" />
                    <p class="mt-1 text-xs text-gray-500">اتركها سنة إذا تريد تجربة طويلة بدون تجديد.</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm">نهاية التجربة</label>
                    <input type="datetime-local" name="trial_ends_at" value="{{ old('trial_ends_at') }}" class="w-full rounded-lg border-gray-300" />
                    <p class="mt-1 text-xs text-gray-500">اختياري. يُستخدم إذا اخترت حالة التجربة.</p>
                </div>
                <div class="flex gap-2">
                    <button class="rounded-lg bg-blue-600 px-4 py-2 text-white">منح الاشتراك</button>
                    <a href="{{ route('platform.subscriptions.index') }}" class="rounded-lg bg-gray-100 px-4 py-2 text-gray-700">إلغاء</a>
                </div>
            </form>
        </div>
    </div>
@endsection
