@extends('platform.layout')

@section('content')
<div dir="rtl" class="space-y-4">
    @include('platform.partials.nav')
    @include('partials.flash')

    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold text-gray-900">طابور توثيق التجار</h1>
        <form method="GET" class="flex items-center gap-2">
            <select name="status" class="rounded-lg border-gray-300 text-sm" onchange="this.form.submit()">
                <option value="">الكل النشط</option>
                @foreach(\App\Support\MerchantStatusLabels::verification() as $option => $label)
                    @if($option !== 'not_requested')
                        <option value="{{ $option }}" @selected($status === $option)>{{ $label }}</option>
                    @endif
                @endforeach
            </select>
        </form>
    </div>

    <div class="overflow-x-auto rounded-xl border bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-right">مساحة العمل</th>
                    <th class="px-4 py-3 text-right">المالك</th>
                    <th class="px-4 py-3 text-right">التوثيق</th>
                    <th class="px-4 py-3 text-right">البوابة</th>
                    <th class="px-4 py-3 text-right">أُرسل</th>
                    <th class="px-4 py-3 text-right"></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($profiles as $profile)
                    <tr>
                        <td class="px-4 py-3">{{ $profile->workspace?->name ?? ('#'.$profile->workspace_id) }}</td>
                        <td class="px-4 py-3">{{ $profile->workspace?->owner?->email ?? '-' }}</td>
                        <td class="px-4 py-3">{{ \App\Support\MerchantStatusLabels::verificationLabel($profile->verification_status) }}</td>
                        <td class="px-4 py-3">{{ \App\Support\MerchantStatusLabels::providerLabel($profile->provider_onboarding_status) }}</td>
                        <td class="px-4 py-3">{{ $profile->submitted_at?->format('Y-m-d H:i') ?? '-' }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('platform.merchant-verifications.show', $profile->id) }}" class="text-blue-600">عرض</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">لا توجد طلبات.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $profiles->links() }}
</div>
@endsection
