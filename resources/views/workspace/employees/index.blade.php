<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">الموظفون والدعوات</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4 space-y-6">
            @include('workspace.partials.nav')
            @include('partials.flash')

            <section class="rounded-xl border bg-white p-6">
                <div class="mb-4">
                    <h3 class="font-semibold">المشاركون في مساحة العمل</h3>
                    <p class="mt-1 text-sm text-gray-500">تظهر الأنواع الثلاثة منفصلة؛ نوع المشارك لا يعتمد على الاسم أو البريد.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-right">النوع</th>
                                <th class="px-4 py-3 text-right">الاسم</th>
                                <th class="px-4 py-3 text-right">الدور أو الارتباط</th>
                                <th class="px-4 py-3 text-right">الحالة</th>
                                <th class="px-4 py-3 text-right"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse($participants as $participant)
                                <tr>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                            {{ $participant->type->label() }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-medium">{{ $participant->displayName }}</td>
                                    <td class="px-4 py-3 text-gray-600">{{ $participant->role ?? '-' }}</td>
                                    <td class="px-4 py-3">{{ $participant->status }}</td>
                                    <td class="px-4 py-3 text-left">
                                        @if($participant->type === \App\Enums\WorkforceParticipantType::IndependentWorker && $participant->engagementId !== null)
                                            <a class="text-blue-700 underline" href="{{ route('workspace.worker-engagements.show', $participant->engagementId) }}">عرض الارتباط</a>
                                        @elseif($participant->type === \App\Enums\WorkforceParticipantType::AiEmployee && $canManageAiSettings)
                                            <a class="text-blue-700 underline" href="{{ route('workspace.ai-settings.edit', ['tab' => 'general', 'employee' => $participant->aiEmployeeId]) }}">إدارة موظف AI</a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">لا يوجد مشاركون.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

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
