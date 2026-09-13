@extends('layouts.financial', ['pageTitle' => 'الإعدادات المالية'])

@section('content')
    <div x-data="{ logoFileName: '' }" class="space-y-4">
        <h2 class="text-xl font-bold text-slate-900">الإعدادات المالية للمنشأة</h2>

        <div class="grid gap-4 xl:grid-cols-2">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">بيانات المنشأة</h3>
                <form method="POST" action="{{ route('workspace.finance.settings.company.update') }}" enctype="multipart/form-data" class="grid gap-2 sm:grid-cols-2">
                    @csrf
                    @method('PUT')
                    <input name="company_name" value="{{ $setting?->company_name }}" class="rounded-lg border-slate-300 text-sm" placeholder="اسم الشركة">
                    <input name="company_name_ar" value="{{ $setting?->company_name_ar }}" class="rounded-lg border-slate-300 text-sm" placeholder="الاسم العربي">
                    <input name="vat_number" value="{{ $setting?->vat_number }}" class="rounded-lg border-slate-300 text-sm" placeholder="الرقم الضريبي">
                    <input name="commercial_registration" value="{{ $setting?->commercial_registration }}" class="rounded-lg border-slate-300 text-sm" placeholder="السجل التجاري">
                    <input name="phone" value="{{ $setting?->phone }}" class="rounded-lg border-slate-300 text-sm" placeholder="الهاتف">
                    <input name="email" type="email" value="{{ $setting?->email }}" class="rounded-lg border-slate-300 text-sm" placeholder="البريد الإلكتروني">
                    <input name="website" value="{{ $setting?->website }}" class="rounded-lg border-slate-300 text-sm" placeholder="الموقع الإلكتروني">
                    <input name="address_line" value="{{ $setting?->address_line }}" class="rounded-lg border-slate-300 text-sm" placeholder="العنوان">
                    <input name="building_number" value="{{ $setting?->building_number }}" class="rounded-lg border-slate-300 text-sm" placeholder="رقم المبنى">
                    <input name="street" value="{{ $setting?->street }}" class="rounded-lg border-slate-300 text-sm" placeholder="الشارع">
                    <input name="district" value="{{ $setting?->district }}" class="rounded-lg border-slate-300 text-sm" placeholder="الحي">
                    <input name="city" value="{{ $setting?->city }}" class="rounded-lg border-slate-300 text-sm" placeholder="المدينة">
                    <input name="postal_code" value="{{ $setting?->postal_code }}" class="rounded-lg border-slate-300 text-sm" placeholder="الرمز البريدي">
                    <input name="country_code" value="{{ $setting?->country_code ?? 'SA' }}" class="rounded-lg border-slate-300 text-sm" placeholder="الدولة (SA)">
                    <input name="currency" value="{{ $setting?->currency ?? 'SAR' }}" class="rounded-lg border-slate-300 text-sm" placeholder="العملة">
                    <input name="invoice_prefix" value="{{ $setting?->invoice_prefix ?? 'INV' }}" class="rounded-lg border-slate-300 text-sm" placeholder="بادئة الفاتورة">
                    <input name="invoice_primary_color" value="{{ $setting?->invoice_primary_color ?? '#06C2A4' }}" class="rounded-lg border-slate-300 text-sm" placeholder="لون PDF الرئيسي (#06C2A4)">
                    <input name="default_vat_rate" value="{{ $setting?->default_vat_rate ?? 15 }}" type="number" step="0.01" class="rounded-lg border-slate-300 text-sm" placeholder="VAT الافتراضي">
                    <input name="default_payment_terms" value="{{ $setting?->default_payment_terms }}" class="rounded-lg border-slate-300 text-sm sm:col-span-2" placeholder="شروط الدفع الافتراضية">
                    <label class="flex items-center gap-2 text-xs text-slate-600 sm:col-span-2">
                        <input type="checkbox" name="allow_manual_invoice_numbers" value="1" class="rounded border-slate-300 text-[#06C2A4]" @checked($setting?->allowsManualInvoiceNumbers())>
                        السماح بإدخال رقم فاتورة يدوي (يبقى التفرد داخل المنشأة)
                    </label>
                    <textarea name="invoice_footer_text" rows="2" class="rounded-lg border-slate-300 text-sm sm:col-span-2" placeholder="نص تذييل PDF (اختياري)">{{ $setting?->invoice_footer_text }}</textarea>
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-semibold text-slate-600">شعار المنشأة</label>
                        <input
                            id="company_logo"
                            type="file"
                            name="logo"
                            class="sr-only"
                            @change="logoFileName = $event.target.files.length ? $event.target.files[0].name : ''"
                        >
                        <div class="flex items-center gap-2 rounded-xl border border-slate-300 bg-slate-50 p-2">
                            <label for="company_logo" class="cursor-pointer rounded-lg bg-[#06C2A4] px-3 py-2 text-xs font-bold text-white hover:bg-[#05ab91]">
                                اختيار ملف
                            </label>
                            <span class="truncate text-xs text-slate-600" x-text="logoFileName || 'لم يتم اختيار ملف بعد'"></span>
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-xs text-slate-600 sm:col-span-2">
                        <input type="checkbox" name="remove_logo" value="1" class="rounded border-slate-300 text-red-600">
                        حذف الشعار الحالي
                    </label>
                    <button class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white sm:col-span-2">حفظ بيانات المنشأة</button>
                </form>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">إعدادات الضرائب</h3>
                <form method="POST" action="{{ route('workspace.finance.settings.tax-rates.store') }}" class="grid gap-2 sm:grid-cols-2">
                    @csrf
                    <input name="name" class="rounded-lg border-slate-300 text-sm" placeholder="اسم الضريبة" required>
                    <input name="code" class="rounded-lg border-slate-300 text-sm" placeholder="رمز الضريبة (VAT_STD_15)" required>
                    <select name="type" class="rounded-lg border-slate-300 text-sm" required>
                        <option value="standard">ضريبة قياسية</option>
                        <option value="zero_rated">صفرية النسبة</option>
                        <option value="exempt">معفاة</option>
                        <option value="out_of_scope">خارج النطاق</option>
                    </select>
                    <input name="rate" type="number" step="0.01" min="0" max="100" class="rounded-lg border-slate-300 text-sm" placeholder="النسبة %" required>
                    <label class="flex items-center gap-2 text-xs text-slate-600">
                        <input type="checkbox" name="is_default" value="1" class="rounded border-slate-300 text-[#06C2A4]">
                        افتراضية
                    </label>
                    <label class="flex items-center gap-2 text-xs text-slate-600">
                        <input type="checkbox" name="is_active" value="1" checked class="rounded border-slate-300 text-[#06C2A4]">
                        فعّالة
                    </label>
                    <button class="rounded-lg border border-[#06C2A4] px-4 py-2 text-sm font-semibold text-[#06C2A4] sm:col-span-2">حفظ ضريبة</button>
                </form>

                <div class="mt-4 space-y-2">
                    @foreach($taxRates as $rate)
                        <div class="rounded-lg border border-slate-200 p-2 text-xs">
                            <span class="font-semibold">{{ $rate->name }}</span>
                            — {{ $rate->type }} — {{ number_format((float) $rate->rate, 2) }}%
                            @if($rate->is_default)
                                <span class="rounded-full bg-[#E8FAF6] px-2 py-0.5 text-[#0f7668]">افتراضية</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm xl:col-span-2">
                <h3 class="mb-3 text-sm font-bold">النقد والبنوك</h3>
                <form method="POST" action="{{ route('workspace.finance.settings.treasury-accounts.store') }}" class="grid gap-2 sm:grid-cols-3">
                    @csrf
                    <input name="name" class="rounded-lg border-slate-300 text-sm" placeholder="اسم الحساب" required>
                    <select name="type" class="rounded-lg border-slate-300 text-sm" required>
                        <option value="cash">نقدي</option>
                        <option value="bank">بنكي</option>
                    </select>
                    <input name="currency" value="{{ $setting?->currency ?? 'SAR' }}" class="rounded-lg border-slate-300 text-sm" maxlength="3" required>
                    <input name="opening_balance" type="number" step="0.01" class="rounded-lg border-slate-300 text-sm" placeholder="الرصيد الافتتاحي">
                    <input name="current_balance" type="number" step="0.01" class="rounded-lg border-slate-300 text-sm" placeholder="الرصيد الحالي">
                    <select name="linked_finance_account_id" class="rounded-lg border-slate-300 text-sm">
                        <option value="">ربط بحساب محاسبي</option>
                        @foreach($financeAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                        @endforeach
                    </select>
                    <input name="bank_name" class="rounded-lg border-slate-300 text-sm" placeholder="اسم البنك">
                    <input name="account_number" class="rounded-lg border-slate-300 text-sm" placeholder="رقم الحساب">
                    <input name="iban" class="rounded-lg border-slate-300 text-sm" placeholder="IBAN">
                    <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white sm:col-span-3">حفظ الحساب</button>
                </form>

                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-2 py-2 text-right">الاسم</th>
                                <th class="px-2 py-2 text-right">النوع</th>
                                <th class="px-2 py-2 text-right">الرصيد</th>
                                <th class="px-2 py-2 text-right">الحساب المحاسبي</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($treasuryAccounts as $account)
                                <tr>
                                    <td class="px-2 py-2">{{ $account->name }}</td>
                                    <td class="px-2 py-2">{{ $account->type }}</td>
                                    <td class="px-2 py-2">{{ number_format((float) $account->current_balance, 2) }} {{ $account->currency }}</td>
                                    <td class="px-2 py-2">{{ $account->linkedAccount?->code }} {{ $account->linkedAccount?->name }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </article>
        </div>
    </div>
@endsection
