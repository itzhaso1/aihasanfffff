<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">الموظفون والدعوات</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4 space-y-6">
            @include('workspace.partials.nav')
            @include('partials.flash')

            <div class="text-left">
                <a href="{{ route('workspace.employees.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white">دعوة موظف</a>
            </div>

            <div class="rounded-xl border bg-white p-6">
                <h3 class="mb-3 font-semibold">أعضاء مساحة العمل</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-right">الاسم</th>
                                <th class="px-4 py-3 text-right">البريد</th>
                                <th class="px-4 py-3 text-right">الدور</th>
                                <th class="px-4 py-3 text-right">الحالة</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse($memberships as $membership)
                                <tr>
                                    <td class="px-4 py-3">{{ $membership->user?->name ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ $membership->user?->email ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ $membership->membership_role }}</td>
                                    <td class="px-4 py-3">{{ $membership->status }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">لا يوجد أعضاء.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $memberships->links() }}</div>
            </div>

            <div class="rounded-xl border bg-white p-6">
                <h3 class="mb-3 font-semibold">الدعوات</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-right">البريد</th>
                                <th class="px-4 py-3 text-right">الدور</th>
                                <th class="px-4 py-3 text-right">الحالة</th>
                                <th class="px-4 py-3 text-right">الانتهاء</th>
                                <th class="px-4 py-3 text-right"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse($invitations as $invitation)
                                <tr>
                                    <td class="px-4 py-3">{{ $invitation->email }}</td>
                                    <td class="px-4 py-3">{{ $invitation->role }}</td>
                                    <td class="px-4 py-3">{{ $invitation->status }}</td>
                                    <td class="px-4 py-3">{{ $invitation->expires_at }}</td>
                                    <td class="px-4 py-3 text-left">
                                        @if($invitation->status === 'pending')
                                            <form method="POST" action="{{ route('workspace.employees.destroy', $invitation) }}">
                                                @csrf @method('DELETE')
                                                <button class="text-red-600" onclick="return confirm('إلغاء الدعوة؟')">إلغاء</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">لا توجد دعوات.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
