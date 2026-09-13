@extends('layouts.email', ['pageTitle' => 'جهات الاتصال'])

@section('content')
    <div class="grid gap-4 xl:grid-cols-[380px_1fr]">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
                 x-data="contactLookup({
                    lookupUrl: '{{ route('workspace.emails.contacts.lookup') }}',
                    currentId: {{ $editingContact?->id ?? 'null' }}
                 })">
            <h2 class="text-base font-bold text-slate-900">{{ $editingContact ? 'تعديل جهة اتصال' : 'إضافة جهة اتصال' }}</h2>
            <p class="mt-1 text-xs text-slate-500">كل بريد إلكتروني يُسجل مرة واحدة فقط داخل نفس المساحة.</p>

            <form method="POST"
                  action="{{ $editingContact ? route('workspace.emails.contacts.update', $editingContact) : route('workspace.emails.contacts.store') }}"
                  class="mt-4 space-y-3">
                @csrf
                @if($editingContact)
                    @method('PUT')
                @endif

                <div>
                    <label class="mb-1 block text-sm font-semibold text-slate-700">اسم الشركة / جهة الاتصال</label>
                    <input type="text" name="name" value="{{ old('name', $editingContact?->name) }}" required
                           placeholder="مثل: شركة أحمد للهواتف"
                           class="w-full rounded-lg border-slate-300 text-sm">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-semibold text-slate-700">البريد الإلكتروني</label>
                    <input type="email" name="email" x-model="email" @input.debounce.350ms="checkEmail"
                           value="{{ old('email', $editingContact?->email) }}" required
                           placeholder="example@company.com"
                           class="w-full rounded-lg border-slate-300 text-sm">

                    <template x-if="checking">
                        <p class="mt-2 text-xs text-slate-500">جارٍ التحقق من البريد...</p>
                    </template>

                    <template x-if="existing && (!currentId || existing.id !== currentId)">
                        <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                            <p class="font-semibold">هذا البريد الإلكتروني مسجل مسبقًا.</p>
                            <p class="mt-1" x-text="`${existing.name} — ${existing.email}`"></p>
                            <a :href="`{{ route('workspace.emails.contacts.index') }}?contact_id=${existing.id}`"
                               class="mt-2 inline-flex rounded-md border border-amber-300 px-2 py-1 font-semibold text-amber-800 hover:bg-amber-100">
                                فتح جهة الاتصال الموجودة
                            </a>
                        </div>
                    </template>

                    <template x-if="!existing && email.length > 4">
                        <p class="mt-2 text-xs font-semibold text-emerald-700">البريد غير مسجل، يمكنك إضافته.</p>
                    </template>
                </div>

                <div class="flex items-center gap-2">
                    <button class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">
                        {{ $editingContact ? 'حفظ التعديلات' : 'إضافة جهة الاتصال' }}
                    </button>
                    @if($editingContact)
                        <a href="{{ route('workspace.emails.contacts.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                            إلغاء
                        </a>
                    @endif
                </div>
            </form>
        </section>

        <section class="space-y-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <form method="GET" action="{{ route('workspace.emails.contacts.index') }}" class="flex flex-wrap items-end gap-2">
                    <div class="flex-1 min-w-[240px]">
                        <label class="mb-1 block text-xs font-semibold text-slate-600">بحث بالاسم أو البريد</label>
                        <input type="text" name="search" value="{{ $search }}" placeholder="اكتب اسم الشركة أو البريد الإلكتروني"
                               class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <button class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-700">بحث</button>
                </form>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-4 py-3 text-right font-semibold text-slate-600">اسم الجهة</th>
                                <th class="px-4 py-3 text-right font-semibold text-slate-600">البريد الإلكتروني</th>
                                <th class="px-4 py-3 text-left font-semibold text-slate-600">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($contacts as $contact)
                                <tr class="hover:bg-slate-50">
                                    <td class="px-4 py-3 text-slate-800">{{ $contact->name }}</td>
                                    <td class="px-4 py-3 text-slate-700">{{ $contact->email }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-end gap-2">
                                            <a href="{{ route('workspace.emails.compose', ['recipient' => $contact->email, 'recipient_contact_id' => $contact->id]) }}"
                                               class="rounded-lg border border-[#06C2A4] px-3 py-1.5 text-xs font-semibold text-[#0f7668] hover:bg-[#E8FAF6]">
                                                إرسال رسالة
                                            </a>
                                            <a href="{{ route('workspace.emails.contacts.index', ['contact_id' => $contact->id]) }}"
                                               class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                                تعديل
                                            </a>
                                            <form method="POST" action="{{ route('workspace.emails.contacts.destroy', $contact) }}" onsubmit="return confirm('هل تريد حذف جهة الاتصال؟');">
                                                @csrf
                                                @method('DELETE')
                                                <button class="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">
                                                    حذف
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-4 py-8 text-center text-sm text-slate-500">لا توجد جهات اتصال حتى الآن.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-slate-100 px-4 py-3">
                    {{ $contacts->links() }}
                </div>
            </div>
        </section>
    </div>

    <script>
        function contactLookup({ lookupUrl, currentId }) {
            return {
                email: '',
                existing: null,
                checking: false,
                currentId,
                init() {
                    this.email = this.$root.querySelector('input[name="email"]')?.value ?? '';
                    if (this.email) {
                        this.checkEmail();
                    }
                },
                async checkEmail() {
                    const value = (this.email || '').trim();
                    this.existing = null;
                    if (!value || value.indexOf('@') === -1) {
                        return;
                    }

                    this.checking = true;
                    try {
                        const url = new URL(lookupUrl, window.location.origin);
                        url.searchParams.set('email', value);
                        const response = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                        const data = await response.json();
                        this.existing = data?.found ? data.contact : null;
                    } catch (error) {
                        this.existing = null;
                    } finally {
                        this.checking = false;
                    }
                }
            };
        }
    </script>
@endsection
