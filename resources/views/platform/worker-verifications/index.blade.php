@extends('platform.layout')

@section('content')
<div dir="rtl" class="space-y-4">
    @include('platform.partials.nav')
    @include('partials.flash')

    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold text-gray-900">طابور تحقق العمال المستقلين</h1>
        <form method="GET" class="flex items-center gap-2">
            <select name="status" class="rounded-lg border-gray-300 text-sm" onchange="this.form.submit()">
                <option value="">طلبات المراجعة</option>
                @foreach([
                    'pending_review' => 'قيد المراجعة',
                    'documents_required' => 'مستندات مطلوبة',
                    'rejected' => 'مرفوض',
                    'approved' => 'معتمد',
                    'suspended' => 'معلّق',
                ] as $option => $label)
                    <option value="{{ $option }}" @selected($status === $option)>{{ $label }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="overflow-x-auto rounded-xl border bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-right">العامل</th>
                    <th class="px-4 py-3 text-right">البريد</th>
                    <th class="px-4 py-3 text-right">الدولة</th>
                    <th class="px-4 py-3 text-right">التحقق</th>
                    <th class="px-4 py-3 text-right">آخر تحديث</th>
                    <th class="px-4 py-3 text-right"></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($profiles as $profile)
                    <tr>
                        <td class="px-4 py-3">{{ $profile->display_name }}</td>
                        <td class="px-4 py-3">{{ $profile->user?->email }}</td>
                        <td class="px-4 py-3">{{ $profile->country_code }}</td>
                        <td class="px-4 py-3">{{ $profile->verificationStatusLabel() }}</td>
                        <td class="px-4 py-3">{{ $profile->updated_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('platform.worker-verifications.show', $profile->id) }}" class="text-blue-600">عرض</a>
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
