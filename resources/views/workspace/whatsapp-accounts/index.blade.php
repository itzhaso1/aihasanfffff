<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">حسابات واتساب</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-7xl px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')
            <div class="mb-5 rounded-2xl border border-[#BDEFE5] bg-[#F3FCFA] p-4">
                <h3 class="text-sm font-bold text-[#067e6b]">إعداد Webhook لربط Meta WhatsApp Business API</h3>
                <div class="mt-3 space-y-2 text-xs text-gray-700">
                    <p>
                        <span class="font-semibold">Webhook URL (GET/POST):</span>
                        <code class="rounded bg-white px-2 py-1 text-[11px]">{{ route('webhooks.whatsapp.verify') }}</code>
                    </p>
                    <p>
                        <span class="font-semibold">Verify Token:</span>
                        <code class="rounded bg-white px-2 py-1 text-[11px]">WHATSAPP_VERIFY_TOKEN</code>
                        <span class="text-gray-500">(القيمة الفعلية تقرأ من ملف .env)</span>
                    </p>
                    <p>
                        <span class="font-semibold">Signature Header:</span>
                        <code class="rounded bg-white px-2 py-1 text-[11px]">X-Hub-Signature-256</code>
                        ويتم التحقق منها باستخدام <code class="rounded bg-white px-2 py-1 text-[11px]">WHATSAPP_APP_SECRET</code>.
                    </p>
                </div>
            </div>
            <div class="mb-4 text-left">
                <a href="{{ route('workspace.whatsapp-accounts.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm text-white">إضافة حساب</a>
            </div>
            <div class="overflow-x-auto rounded-xl border bg-white">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-right">الاسم</th>
                            <th class="px-4 py-3 text-right">Business ID</th>
                            <th class="px-4 py-3 text-right">الحالة</th>
                            <th class="px-4 py-3 text-right">الأرقام</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($accounts as $account)
                            <tr>
                                <td class="px-4 py-3">{{ $account->display_name }}</td>
                                <td class="px-4 py-3">{{ $account->business_account_id }}</td>
                                <td class="px-4 py-3">{{ $account->status }}</td>
                                <td class="px-4 py-3">{{ $account->phoneNumbers->count() }}</td>
                                <td class="px-4 py-3 text-left">
                                    <a href="{{ route('workspace.whatsapp-accounts.edit', $account) }}" class="text-blue-600">تعديل</a>
                                    <form method="POST" action="{{ route('workspace.whatsapp-accounts.destroy', $account) }}" class="inline">
                                        @csrf @method('DELETE')
                                        <button class="mr-3 text-red-600" onclick="return confirm('تأكيد الحذف؟')">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">لا توجد حسابات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $accounts->links() }}</div>
        </div>
    </div>
</x-communication-layout>
