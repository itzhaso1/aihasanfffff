@extends('layouts.email', ['pageTitle' => 'الشركات / حسابات البريد'])

@section('content')
    <div class="grid gap-4 xl:grid-cols-[380px_1fr]">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-bold text-slate-900">{{ $editingAccount ? 'تعديل حساب الشركة' : 'إضافة شركة جديدة' }}</h2>
            <p class="mt-1 text-xs text-slate-500">ضع بيانات البريد في هذا القسم فقط لضمان تنظيم الإعدادات.</p>

            <form method="POST"
                  action="{{ $editingAccount ? route('workspace.emails.accounts.update', $editingAccount) : route('workspace.emails.accounts.store') }}"
                  enctype="multipart/form-data"
                  class="mt-4 space-y-3">
                @csrf
                @if($editingAccount)
                    @method('PUT')
                @endif

                <div>
                    <label class="mb-1 block text-sm font-semibold text-slate-700">اسم الشركة</label>
                    <input type="text" name="name" value="{{ old('name', $editingAccount?->name) }}" required
                           placeholder="مثل: شركة ABC أو الدعم الفني"
                           class="w-full rounded-lg border-slate-300 text-sm">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-semibold text-slate-700">البريد الإلكتروني</label>
                    <input type="email" name="email" value="{{ old('email', $editingAccount?->email) }}" required
                           class="w-full rounded-lg border-slate-300 text-sm">
                </div>

                <div>
                    <label class="mb-1 block text-sm font-semibold text-slate-700">
                        كلمة المرور {{ $editingAccount ? '(اتركها فارغة إذا لا تريد تغييرها)' : '' }}
                    </label>
                    <input type="password" name="password" {{ $editingAccount ? '' : 'required' }} class="w-full rounded-lg border-slate-300 text-sm">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">IMAP Host</label>
                        <input type="text" name="imap_host" value="{{ old('imap_host', $editingAccount?->imap_host) }}" required class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">IMAP Port</label>
                        <input type="number" name="imap_port" value="{{ old('imap_port', $editingAccount?->imap_port ?? 993) }}" required class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">SMTP Host</label>
                        <input type="text" name="smtp_host" value="{{ old('smtp_host', $editingAccount?->smtp_host) }}" required class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">SMTP Port</label>
                        <input type="number" name="smtp_port" value="{{ old('smtp_port', $editingAccount?->smtp_port ?? 587) }}" required class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">لون الهوية</label>
                        <input type="color" name="brand_color" value="{{ old('brand_color', $editingAccount?->brand_color ?? '#06C2A4') }}" class="h-10 w-full rounded-lg border-slate-300">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">شعار (اختياري)</label>
                        <input type="file" name="logo" class="w-full rounded-lg border-slate-300 text-xs">
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-semibold text-slate-700">أسماء مرسل إضافية (اختياري)</label>
                    <textarea name="aliases" rows="2" class="w-full rounded-lg border-slate-300 text-sm" placeholder="الدعم الفني&#10;المبيعات">{{ old('aliases', $editingAccount ? implode("\n", $editingAccount->aliases ?? []) : '') }}</textarea>
                </div>

                @if($editingAccount)
                    <label class="flex items-center gap-2 text-xs text-slate-600">
                        <input type="checkbox" name="remove_logo" value="1" class="rounded border-slate-300 text-red-600">
                        حذف الشعار الحالي
                    </label>
                @endif

                <div class="flex items-center gap-2">
                    <button class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">
                        {{ $editingAccount ? 'حفظ التعديلات' : 'إضافة الشركة' }}
                    </button>
                    @if($editingAccount)
                        <a href="{{ route('workspace.emails.accounts.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                            إلغاء
                        </a>
                    @endif
                </div>
            </form>
        </section>

        <section class="space-y-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">الحسابات الحالية</h3>
                <p class="mt-1 text-xs text-slate-500">يمكنك التعديل أو الحذف أو المزامنة لكل شركة.</p>
            </div>

            @forelse($accounts as $account)
                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h4 class="text-sm font-bold text-slate-900">{{ $account->name }}</h4>
                            <p class="text-xs text-slate-500">{{ $account->email }}</p>
                            <p class="mt-1 text-xs text-slate-500">IMAP: {{ $account->imap_host }}:{{ $account->imap_port }} — SMTP: {{ $account->smtp_host }}:{{ $account->smtp_port }}</p>
                        </div>
                        <span class="h-3 w-3 rounded-full border border-white shadow" style="background-color: {{ $account->brand_color }}"></span>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <a href="{{ route('workspace.emails.accounts.index', ['account_id' => $account->id]) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                            تعديل
                        </a>
                        <a href="{{ route('workspace.emails.compose', ['account_id' => $account->id]) }}" class="rounded-lg border border-[#06C2A4] px-3 py-1.5 text-xs font-semibold text-[#0f7668] hover:bg-[#E8FAF6]">
                            استخدام في الإرسال
                        </a>
                        <form method="POST" action="{{ route('workspace.emails.accounts.sync', $account) }}">
                            @csrf
                            <button type="submit" class="rounded-lg border border-amber-300 px-3 py-1.5 text-xs font-semibold text-amber-700 hover:bg-amber-50">
                                مزامنة الوارد
                            </button>
                        </form>
                        <form method="POST" action="{{ route('workspace.emails.accounts.destroy', $account) }}" onsubmit="return confirm('سيتم حذف الحساب والرسائل والمرفقات المرتبطة به. هل أنت متأكد؟');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-lg border border-red-300 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">
                                حذف الشركة
                            </button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">
                    لا توجد شركات مضافة حتى الآن.
                </div>
            @endforelse
        </section>
    </div>
@endsection
