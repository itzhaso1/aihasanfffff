@extends('layouts.financial', ['pageTitle' => 'فاتورة '.$invoice->invoice_number])

@php
    $invoiceStatus = $invoice->invoice_status ?: $invoice->status;
    $paymentStatus = $invoice->payment_status ?: $invoice->status;
    $isDraft = $invoiceStatus === 'draft';
    $isCancelled = $invoiceStatus === 'cancelled';
    $statusClass = match ($invoiceStatus) {
        'cancelled' => 'bg-slate-100 text-slate-600',
        'draft' => 'bg-slate-50 text-slate-500',
        'issued' => 'bg-indigo-50 text-indigo-700',
        default => 'bg-amber-50 text-amber-700',
    };
    $payClass = match ($paymentStatus) {
        'paid' => 'bg-emerald-50 text-emerald-700',
        'partial' => 'bg-sky-50 text-sky-700',
        'overdue' => 'bg-rose-50 text-rose-700',
        default => 'bg-amber-50 text-amber-700',
    };
    $lifecycle = \App\Support\Finance\InvoicePresentation::lifecycle($invoice);
    $invoiceStatusLabels = ['draft' => 'مسودة', 'issued' => 'معتمدة', 'cancelled' => 'ملغاة'];
    $paymentStatusLabels = ['unpaid' => 'غير مدفوعة', 'partial' => 'مدفوعة جزئيًا', 'paid' => 'مدفوعة', 'overdue' => 'متأخرة'];
    $taxDocumentLabels = ['standard' => 'قياسية', 'simplified' => 'مبسطة'];
    $zatcaRequirementLabels = ['not_required' => 'غير مطلوب', 'required' => 'مطلوب (داخلي)'];
    $dueToday = $invoice->due_date && $invoice->due_date->isToday() && in_array($paymentStatus, ['unpaid', 'partial'], true);
    $canPay = (float) $invoice->amount_due > 0.009 && ! $isDraft && ! $isCancelled;
    $paidRatio = (float) $invoice->total > 0 ? min(100, ((float) $invoice->amount_paid / (float) $invoice->total) * 100) : 0;
    $snapshotsAuthoritative = $invoice->snapshotsAreAuthoritative();
    $companyVat = data_get($invoice->company_snapshot, 'vat_number');
    $recipientVat = data_get($invoice->recipient_snapshot, 'vat_number');
    $recipientName = $snapshotsAuthoritative
        ? (data_get($invoice->recipient_snapshot, 'name') ?: ($invoice->customer_name ?: optional($invoice->customer)->name ?: optional($invoice->supplier)->name))
        : ($invoice->customer_name ?: optional($invoice->customer)->name ?: optional($invoice->supplier)->name);
    $recipientEmail = $snapshotsAuthoritative
        ? data_get($invoice->recipient_snapshot, 'email')
        : ($invoice->type === 'purchase' ? optional($invoice->supplier)->email : optional($invoice->customer)->email);
    $recipientPhone = $snapshotsAuthoritative
        ? data_get($invoice->recipient_snapshot, 'phone')
        : ($invoice->type === 'purchase' ? optional($invoice->supplier)->phone : optional($invoice->customer)->phone);
    $displayRecipientVat = $snapshotsAuthoritative
        ? $recipientVat
        : ($invoice->type === 'purchase' ? optional($invoice->supplier)->vat_number : optional($invoice->customer)->vat_number);
    $taxDocumentSubtype = $invoice->tax_document_subtype ?: 'standard';
    $zatcaRequirement = $invoice->type === 'purchase' ? 'not_required' : ($invoice->zatca_requirement ?: 'not_required');
    $taxProfileLabels = [
        'standard' => 'قياسية',
        'zero_rated' => 'صفرية',
        'exempt' => 'معفاة',
        'out_of_scope' => 'خارج النطاق',
    ];
    $taxPriceModeLabels = [
        'exclusive' => 'غير شامل الضريبة',
        'inclusive' => 'شامل الضريبة',
    ];
    $taxBreakdown = is_array($invoice->tax_breakdown) ? $invoice->tax_breakdown : [];
@endphp

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-slate-900">فاتورة {{ $invoice->invoice_number }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ $recipientName }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    @include('workspace.finance.partials.status-badge', ['label' => \App\Support\Finance\InvoicePresentation::label($lifecycle), 'class' => \App\Support\Finance\InvoicePresentation::badgeClass($lifecycle)])
                    <span class="rounded-full px-3 py-1 text-xs font-bold {{ $statusClass }}">مستند: {{ $invoiceStatusLabels[$invoiceStatus] ?? $invoiceStatus }}</span>
                    <span class="rounded-full px-3 py-1 text-xs font-bold {{ $payClass }}">دفع: {{ $paymentStatusLabels[$paymentStatus] ?? $paymentStatus }}</span>
                    <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-bold text-slate-600">{{ $taxDocumentLabels[$taxDocumentSubtype] ?? $taxDocumentSubtype }}</span>
                    @if($dueToday)
                        <span class="rounded-full bg-orange-50 px-3 py-1 text-xs font-bold text-orange-700">مستحقة اليوم</span>
                    @endif
                    <span class="text-sm text-slate-500">{{ $invoice->type === 'purchase' ? 'مشتريات' : 'مبيعات' }}</span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('workspace.finance.invoices.index') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">رجوع</a>
                <a href="{{ route('workspace.finance.invoices.pdf', $invoice) }}" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">PDF</a>
                @if($invoice->isSendable())
                    <a href="#invoice-send" class="rounded-lg bg-[#06C2A4] px-3 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">إرسال بالبريد الإلكتروني</a>
                @endif
                @if($invoice->isRemindable())
                    <a href="#invoice-remind" class="rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-50">تذكير بالبريد</a>
                @endif
                @if($isDraft)
                    <a href="{{ route('workspace.finance.invoices.edit', $invoice) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">تعديل</a>
                    <form method="POST" action="{{ route('workspace.finance.invoices.issue', $invoice) }}" onsubmit="return confirm('إصدار الفاتورة وترحيل القيد المحاسبي؟')">
                        @csrf
                        <button class="rounded-lg bg-[#06C2A4] px-3 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">إصدار</button>
                    </form>
                    <form method="POST" action="{{ route('workspace.finance.invoices.destroy', $invoice) }}" onsubmit="return confirm('حذف مسودة الفاتورة؟ لا يمكن التراجع عن هذا الإجراء.')">
                        @csrf
                        @method('DELETE')
                        <button class="rounded-lg border border-rose-300 px-3 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-50">حذف المسودة</button>
                    </form>
                @endif
                @if(! $isCancelled)
                    <form method="POST" action="{{ route('workspace.finance.invoices.cancel', $invoice) }}" onsubmit="return confirm('إلغاء الفاتورة؟ لن يُحذف القيد المحاسبي بل يُعكس بقيد جديد. يجب عكس الدفعات القائمة أولاً.')">
                        @csrf
                        <button class="rounded-lg border border-rose-300 px-3 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-50">إلغاء</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between text-xs font-semibold text-slate-500">
                <span>تقدم التحصيل</span>
                <span>{{ number_format($paidRatio, 0) }}%</span>
            </div>
            <div class="mt-2 h-3 overflow-hidden rounded-full bg-slate-100">
                <div class="h-3 rounded-full {{ $lifecycle === 'overdue' ? 'bg-rose-500' : 'bg-emerald-500' }}" style="width: {{ $paidRatio }}%"></div>
            </div>
            <p class="mt-2 text-xs text-slate-500">مدفوع {{ number_format((float) $invoice->amount_paid, 2) }} من {{ number_format((float) $invoice->total, 2) }} {{ $invoice->currency }} · خصم {{ number_format((float) $invoice->discount, 2) }} · ضريبة {{ number_format((float) $invoice->tax_amount, 2) }}</p>
        </div>

        @php
            $sendEmail = old('email', $invoice->customer?->email ?: $recipientEmail);
            $sendPhone = old('phone', $invoice->customer?->phone ?: $recipientPhone);
            $companyNameForEmail = data_get($invoice->company_snapshot, 'company_name_ar') ?: data_get($invoice->company_snapshot, 'company_name') ?: (string) config('app.name', 'HASEM');
            $defaultSubject = 'فاتورة رقم '.$invoice->invoice_number;
            $defaultMessage = "السلام عليكم،\nنرفق لكم الفاتورة رقم {$invoice->invoice_number}.\nالتاريخ: ".($invoice->issue_date?->format('Y-m-d') ?: '—')."\nالإجمالي: ".number_format((float) $invoice->total, 2).' '.($invoice->currency ?: 'SAR')."\nمع التحية،\n".$companyNameForEmail;
            $deliveryStatusLabels = ['sending' => 'جارٍ الإرسال', 'sent' => 'تم الإرسال', 'failed' => 'فشل الإرسال'];
            $channelLabels = ['email' => 'بريد إلكتروني'];
            $deliveryTypeLabels = ['invoice' => 'فاتورة', 'invoice_reminder' => 'تذكير'];
            $latestDelivery = $invoice->relationLoaded('deliveries') ? $invoice->deliveries->first() : null;
            $checkout = $checkout ?? null;
        @endphp

        <div id="invoice-send" class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">إرسال بالبريد الإلكتروني</h3>
                <p class="mt-1 text-xs text-slate-500">حالة الفاتورة المالية منفصلة عن حالة الإرسال. الإرسال لا يغيّر المبالغ ولا ينشئ دفعة.</p>
                @if($invoice->isDraft())
                    <p class="mt-2 text-sm text-slate-500">يجب إصدار الفاتورة قبل إرسالها بالبريد.</p>
                @elseif($invoice->isCancelled())
                    <p class="mt-2 text-sm text-slate-500">لا يمكن إرسال فاتورة ملغاة.</p>
                @elseif((string) $invoice->type !== 'sales')
                    <p class="mt-2 text-sm text-slate-500">إرسال البريد متاح لفواتير المبيعات الصادرة فقط.</p>
                @else
                    @if(! $invoice->customer?->email && ! $sendEmail)
                        <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">لا يوجد بريد إلكتروني للعميل. أدخل بريداً صالحاً قبل الإرسال.</p>
                    @endif
                    <form method="POST" action="{{ route('workspace.finance.invoices.send', $invoice) }}" class="mt-3 space-y-3">
                        @csrf
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">العميل</label>
                            <input type="text" value="{{ $recipientName }}" class="w-full rounded-lg border-slate-200 bg-slate-50 text-sm" disabled>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">البريد الإلكتروني</label>
                            <input type="email" name="email" value="{{ $sendEmail }}" required class="w-full rounded-lg border-slate-300 text-sm" placeholder="customer@example.com">
                            @error('email')<p class="mt-1 text-xs font-semibold text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">رقم الجوال (اختياري — لا يُستخدم للإرسال حالياً)</label>
                            <input type="text" name="phone" value="{{ $sendPhone }}" maxlength="32" class="w-full rounded-lg border-slate-300 text-sm" placeholder="05xxxxxxxx">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">الموضوع</label>
                            <input type="text" name="subject" value="{{ old('subject', $defaultSubject) }}" class="w-full rounded-lg border-slate-300 text-sm">
                            @error('subject')<p class="mt-1 text-xs font-semibold text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-600">الرسالة</label>
                            <textarea name="message" rows="5" class="w-full rounded-lg border-slate-300 text-sm">{{ old('message', $defaultMessage) }}</textarea>
                            @error('message')<p class="mt-1 text-xs font-semibold text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input type="hidden" name="attach_pdf" value="0">
                            <input type="checkbox" name="attach_pdf" value="1" class="rounded border-slate-300 text-[#06C2A4]" @checked(old('attach_pdf', '1') === '1' || old('attach_pdf') === 1 || old('attach_pdf') === true)>
                            إرفاق ملف PDF
                        </label>
                        <button class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">إرسال عبر البريد</button>
                    </form>
                @endif
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">سجل الإرسال</h3>
                <p class="mt-1 text-xs text-slate-500">حالة المستند: {{ $invoiceStatusLabels[$invoiceStatus] ?? $invoiceStatus }} · آخر إرسال: {{ $latestDelivery ? ($deliveryStatusLabels[$latestDelivery->status] ?? $latestDelivery->status) : 'لم يُرسل بعد' }}</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-3 py-2 text-right">النوع</th>
                                <th class="px-3 py-2 text-right">التاريخ</th>
                                <th class="px-3 py-2 text-right">القناة</th>
                                <th class="px-3 py-2 text-right">المستلم</th>
                                <th class="px-3 py-2 text-right">الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($invoice->deliveries as $delivery)
                                <tr class="border-t border-slate-100">
                                    <td class="px-3 py-2">{{ $deliveryTypeLabels[$delivery->document_type] ?? $delivery->document_type }}</td>
                                    <td class="px-3 py-2">{{ ($delivery->sent_at ?? $delivery->created_at)?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                                    <td class="px-3 py-2">{{ $channelLabels[$delivery->channel] ?? $delivery->channel }}</td>
                                    <td class="px-3 py-2">{{ $delivery->recipient }}</td>
                                    <td class="px-3 py-2">{{ $deliveryStatusLabels[$delivery->status] ?? $delivery->status }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-6 text-center text-slate-500">لم تُرسل هذه الفاتورة بعد.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="invoice-remind" class="rounded-2xl border border-amber-200 bg-amber-50/40 p-5 shadow-sm">
            <h3 class="text-sm font-bold text-slate-900">تذكير العميل بالبريد الإلكتروني</h3>
            <p class="mt-1 text-xs text-slate-500">للتذكير بفواتير المبيعات الصادرة غير المسددة. التذكير لا يغيّر حالة المستند ولا حالة الدفع ولا يستهلك مرحلة التذكير المجدولة.</p>
            @if($invoice->isRemindable())
                <form method="POST" action="{{ route('workspace.finance.invoices.remind', $invoice) }}" class="mt-3 grid gap-3 md:grid-cols-2">
                    @csrf
                    <input type="email" name="email" value="{{ $sendEmail }}" required class="rounded-lg border-slate-300 text-sm" placeholder="customer@example.com">
                    <input type="text" name="subject" value="{{ old('subject', 'تذكير بفاتورة رقم '.$invoice->invoice_number) }}" class="rounded-lg border-slate-300 text-sm">
                    <textarea name="message" rows="3" class="rounded-lg border-slate-300 text-sm md:col-span-2">{{ old('message', 'تذكير بلطف بأن الفاتورة رقم '.$invoice->invoice_number.' ما زالت مستحقة. المتبقي: '.number_format((float) $invoice->amount_due, 2).' '.($invoice->currency ?: 'SAR')) }}</textarea>
                    <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                        <input type="hidden" name="attach_pdf" value="0">
                        <input type="checkbox" name="attach_pdf" value="1" class="rounded border-slate-300 text-[#06C2A4]" checked>
                        إرفاق PDF الفاتورة
                    </label>
                    <button class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">إرسال التذكير</button>
                </form>
            @else
                <p class="mt-2 text-sm text-slate-500">التذكير متاح فقط لفواتير المبيعات الصادرة التي ما زال عليها مبلغ مستحق.</p>
            @endif
        </div>

        @if($checkout)
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">الدفع الإلكتروني</h3>
                @if($checkout->hasCheckoutUrl())
                    <p class="mt-2 text-sm text-slate-600">رابط التحصيل جاهز. إنشاء الرابط لا يعني أن الفاتورة دُفعت. التأكيد يتم عبر بوابة الدفع المشتركة.</p>
                    <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                        <input type="text" readonly value="{{ $checkout->checkoutUrl }}" class="w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-800" id="finance-checkout-url">
                        <div class="flex gap-2">
                            <a class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white hover:opacity-90" href="{{ $checkout->checkoutUrl }}" target="_blank" rel="noopener">فتح الرابط</a>
                            <button type="button" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100" onclick="navigator.clipboard.writeText(document.getElementById('finance-checkout-url').value)">نسخ</button>
                        </div>
                    </div>
                @elseif($checkout->supported)
                    <p class="mt-2 text-sm text-slate-600">{{ $checkout->message !== '' ? $checkout->message : 'يمكن إنشاء رابط دفع إلكتروني لهذه الفاتورة.' }}</p>
                    <form method="POST" action="{{ route('workspace.finance.invoices.checkout', $invoice) }}" class="mt-3">
                        @csrf
                        <button class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white hover:opacity-90">إنشاء رابط الدفع الإلكتروني</button>
                    </form>
                @else
                    <p class="mt-2 text-sm text-slate-600">{{ $checkout->message }}</p>
                    <p class="mt-1 text-xs text-slate-500">سجّل التحصيل يدوياً من نموذج الدفعة أدناه. لا يُنشأ طلب POS ولا بوابة دفع داخل المالية.</p>
                @endif
            </div>
        @endif

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold text-slate-500">الإجمالي</p>
                <p class="mt-1 text-2xl font-black text-slate-900">{{ number_format((float) $invoice->total, 2) }} {{ $invoice->currency }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold text-slate-500">المدفوع</p>
                <p class="mt-1 text-2xl font-black text-emerald-700">{{ number_format((float) $invoice->amount_paid, 2) }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold text-slate-500">المتبقي</p>
                <p class="mt-1 text-2xl font-black {{ (float) $invoice->amount_due > 0 ? 'text-amber-700' : 'text-slate-900' }}">{{ number_format((float) $invoice->amount_due, 2) }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold text-slate-500">الاستحقاق</p>
                <p class="mt-1 text-lg font-bold text-slate-900">{{ optional($invoice->due_date)->format('Y-m-d') ?: '—' }}</p>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">الملخص</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">رقم الفاتورة</dt><dd class="font-semibold">{{ $invoice->invoice_number }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">تاريخ الإصدار</dt><dd>{{ optional($invoice->issue_date)->format('Y-m-d') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">وقت الاعتماد</dt><dd>{{ $invoice->issued_at ? $invoice->issued_at->timezone(config('app.timezone'))->format('Y-m-d H:i') : '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">تصنيف المستند</dt><dd>{{ $taxDocumentLabels[$taxDocumentSubtype] ?? $taxDocumentSubtype }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">الشروط</dt><dd>{{ $invoice->payment_terms ?: '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">الإجمالي قبل الضريبة</dt><dd>{{ number_format((float) $invoice->subtotal, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">الخصم</dt><dd>{{ number_format((float) $invoice->discount, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">الخاضع للضريبة</dt><dd>{{ number_format((float) $invoice->taxable_amount, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">تسعير الضريبة</dt><dd>{{ $taxPriceModeLabels[$invoice->tax_price_mode ?? 'exclusive'] ?? ($invoice->tax_price_mode ?: 'غير شامل الضريبة') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">الضريبة</dt><dd>{{ number_format((float) $invoice->tax_amount, 2) }} ({{ number_format((float) $invoice->tax_rate, 2) }}%)</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">دائن / مدين</dt><dd>{{ number_format((float) ($invoice->amount_credited ?? 0), 2) }} / {{ number_format((float) ($invoice->amount_debited ?? 0), 2) }}</dd></div>
                    @if($invoice->contract)
                        <div class="flex justify-between"><dt class="text-slate-500">العقد</dt><dd><a class="font-semibold text-[#06C2A4] hover:underline" href="{{ route('workspace.finance.contracts.show', $invoice->contract) }}">{{ $invoice->contract->contract_number }}</a></dd></div>
                    @endif
                    @if($invoice->project)
                        <div class="flex justify-between"><dt class="text-slate-500">المشروع</dt><dd>{{ $invoice->project->name }}</dd></div>
                    @endif
                </dl>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">العميل / المورد</h3>
                <p class="mt-3 text-sm font-semibold">{{ $recipientName ?: '—' }}</p>
                <p class="text-xs text-slate-500">{{ $recipientEmail }}</p>
                <p class="text-xs text-slate-500">{{ $recipientPhone }}</p>
                <p class="text-xs text-slate-500">الرقم الضريبي: {{ $displayRecipientVat ?: '—' }}</p>
                @if($snapshotsAuthoritative)
                    <p class="mt-2 text-[11px] text-slate-500">البيانات أعلاه من لقطة الإصدار ولا تتغير إذا تغيّر ملف العميل لاحقاً.</p>
                @endif
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">الفوترة الإلكترونية</h3>
                @php
                    $zatca = $zatcaArtifacts ?? [];
                    $xmlAvailable = (bool) ($zatca['xml_available'] ?? false);
                    $qrAvailable = (bool) ($zatca['qr_available'] ?? false);
                    $zatcaReason = $zatca['reason'] ?? null;
                    $zatcaCopy = match (true) {
                        (bool) ($zatca['not_applicable'] ?? false) => 'فواتير الشراء ليست مستندات فوترة إلكترونية صادرة للعميل.',
                        $zatcaReason === 'not_issued' => 'بعد إصدار فاتورة المبيعات يولّد النظام مستند XML وأساس رمز QR داخلياً. لا يوجد ربط FATOORA أو اعتماد إنتاج.',
                        $xmlAvailable => 'تم توليد مستندات الأساس (XML'.($qrAvailable ? ' ورمز QR' : '').') من لقطة الإصدار. هذا أساس داخلي وليس اعتماد FATOORA أو إبلاغ الإنتاج.',
                        default => 'فاتورة صادرة بدون لقطة إلكترونية مكتملة بعد. لا يوجد ربط FATOORA أو اعتماد إنتاج.',
                    };
                @endphp
                <p class="mt-2 text-xs leading-5 text-slate-500">{{ $zatcaCopy }}</p>
                <ul class="mt-3 space-y-1.5 text-sm">
                    <li class="text-slate-700">التكامل الحالي: أساس داخلي — بلا تخليص FATOORA وبلا إبلاغ إنتاج</li>
                    <li class="text-amber-800">حالة FATOORA/الإنتاج: غير مهيأة</li>
                    <li class="text-slate-600">متطلب داخلي: {{ $zatcaRequirementLabels[$zatcaRequirement] ?? $zatcaRequirement }}</li>
                    <li class="{{ $xmlAvailable ? 'text-emerald-700' : 'text-slate-500' }}">XML: {{ $xmlAvailable ? 'متوفر للتحميل' : 'غير متوفر' }}</li>
                    <li class="{{ $qrAvailable ? 'text-emerald-700' : 'text-slate-500' }}">رمز QR: {{ $qrAvailable ? 'متوفر' : 'غير متوفر' }}</li>
                    <li class="{{ $companyVat ? 'text-emerald-700' : 'text-slate-500' }}">الرقم الضريبي للشركة (لقطة): {{ $companyVat ?: 'غير محفوظ في اللقطة' }}</li>
                    <li class="{{ $recipientVat ? 'text-emerald-700' : 'text-slate-500' }}">الرقم الضريبي للعميل/المورد (لقطة): {{ $recipientVat ?: '—' }}</li>
                </ul>
                @if($xmlAvailable || $qrAvailable)
                    <div class="mt-3 flex flex-wrap gap-2">
                        @if($xmlAvailable)
                            <a href="{{ route('workspace.finance.invoices.xml', $invoice) }}" class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800">تحميل XML</a>
                        @endif
                        @if($qrAvailable)
                            <a href="{{ route('workspace.finance.invoices.qr', $invoice) }}" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">عرض رمز QR</a>
                        @endif
                    </div>
                @endif
                <p class="mt-3 whitespace-pre-line text-sm text-slate-600">{{ $invoice->notes ?: '' }}</p>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-5 py-3 text-sm font-bold">البنود</div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-4 py-2 text-right">الوصف</th>
                            <th class="px-4 py-2 text-right">الوحدة</th>
                            <th class="px-4 py-2 text-right">الكمية</th>
                            <th class="px-4 py-2 text-right">السعر</th>
                            <th class="px-4 py-2 text-right">الخصم</th>
                            <th class="px-4 py-2 text-right">تصنيف الضريبة</th>
                            <th class="px-4 py-2 text-right">النسبة</th>
                            <th class="px-4 py-2 text-right">الضريبة</th>
                            <th class="px-4 py-2 text-right">الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($invoice->items as $item)
                            <tr class="border-t border-slate-100">
                                <td class="px-4 py-2">{{ $item->lineTitle() }}</td>
                                <td class="px-4 py-2">{{ $item->displayUnit() !== '' ? $item->displayUnit() : '-' }}</td>
                                <td class="px-4 py-2">{{ number_format((float) $item->quantity, 2) }}</td>
                                <td class="px-4 py-2">{{ number_format((float) $item->unit_price, 2) }}</td>
                                <td class="px-4 py-2">{{ number_format((float) $item->discount, 2) }}</td>
                                <td class="px-4 py-2">{{ $taxProfileLabels[$item->tax_profile_type ?: $invoice->tax_profile_type] ?? ($item->tax_profile_type ?: $invoice->tax_profile_type) }}@if($item->exemption_reason)<div class="text-[11px] text-slate-500">{{ $item->exemption_reason }}</div>@endif</td>
                                <td class="px-4 py-2">{{ number_format((float) $item->tax_rate, 2) }}%</td>
                                <td class="px-4 py-2">{{ number_format((float) $item->tax_amount, 2) }}</td>
                                <td class="px-4 py-2 font-semibold">{{ number_format((float) $item->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-8 text-center text-slate-500">لا توجد بنود.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if($taxBreakdown !== [])
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-3 text-sm font-bold">مجاميع الضريبة حسب التصنيف</div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-4 py-2 text-right">التصنيف</th>
                                <th class="px-4 py-2 text-right">النسبة</th>
                                <th class="px-4 py-2 text-right">الخاضع للضريبة</th>
                                <th class="px-4 py-2 text-right">الضريبة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($taxBreakdown as $bucket)
                                <tr class="border-t border-slate-100">
                                    <td class="px-4 py-2">{{ $taxProfileLabels[$bucket['tax_profile_type'] ?? ''] ?? ($bucket['tax_profile_type'] ?? '—') }}</td>
                                    <td class="px-4 py-2">{{ number_format((float) ($bucket['tax_rate'] ?? 0), 2) }}%</td>
                                    <td class="px-4 py-2">{{ number_format((float) ($bucket['taxable_amount'] ?? 0), 2) }}</td>
                                    <td class="px-4 py-2">{{ number_format((float) ($bucket['tax_amount'] ?? 0), 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div id="payments" class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold">الدفعات</h3>
                    @if($canPay)
                        <span class="text-xs text-slate-500">سجل دفعة أدناه</span>
                    @endif
                </div>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-slate-500">
                            <tr>
                                <th class="py-1 text-right">التاريخ</th>
                                <th class="py-1 text-right">المبلغ</th>
                                <th class="py-1 text-right">الطريقة</th>
                                <th class="py-1 text-right">المرجع</th>
                                <th class="py-1 text-right">الحالة</th>
                                <th class="py-1"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($invoice->payments as $payment)
                                <tr class="border-t border-slate-100">
                                    <td class="py-2">{{ optional($payment->payment_date)->format('Y-m-d') }}</td>
                                    <td class="py-2 font-semibold">{{ number_format((float) $payment->amount, 2) }}</td>
                                    <td class="py-2">{{ $payment->method }}</td>
                                    <td class="py-2 text-xs">{{ $payment->reference ?: '—' }}</td>
                                    <td class="py-2">{{ $payment->status ?: 'posted' }}</td>
                                    <td class="py-2">
                                        @if($payment->receipt)
                                            <a href="{{ route('workspace.finance.receipts.show', $payment->receipt) }}" class="block text-xs font-semibold text-[#06C2A4]">إيصال {{ $payment->receipt->receipt_number }}</a>
                                            <a href="{{ route('workspace.finance.receipts.pdf', $payment->receipt) }}" class="block text-[11px] text-slate-500">PDF</a>
                                        @endif
                                        @if(($payment->status ?: 'posted') === 'posted' && ! $isCancelled)
                                            <form method="POST" action="{{ route('workspace.finance.invoices.payments.reverse', [$invoice, $payment]) }}" onsubmit="return confirm('عكس هذه الدفعة بقيد محاسبي جديد؟')">
                                                @csrf
                                                <input type="hidden" name="reversal_reason" value="عكس دفعة من واجهة الفاتورة">
                                                <button class="text-xs font-semibold text-rose-600">عكس</button>
                                            </form>
                                        @endif
                                        <p class="text-[11px] text-slate-400">{{ optional($payment->creator)->name }}</p>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-6 text-center text-slate-500">لا توجد دفعات.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold">إشعارات دائن / مدين</h3>
                    @if(! $isDraft && ! $isCancelled)
                        <a href="{{ route('workspace.finance.invoices.credit-notes.create', $invoice) }}" class="text-xs font-semibold text-[#06C2A4] hover:underline">إنشاء</a>
                    @endif
                </div>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse($invoice->creditNotes as $note)
                        <li class="rounded-lg bg-slate-50 px-3 py-2">
                            <div class="flex items-center justify-between gap-2">
                                <span>{{ $note->note_number }} · {{ $note->type === 'debit' ? 'مدين' : 'دائن' }} · {{ $note->status }}</span>
                                <span class="font-semibold">{{ number_format((float) $note->total, 2) }}</span>
                            </div>
                            <p class="text-xs text-slate-500">{{ $note->reason }}</p>
                            <div class="mt-2 flex gap-2">
                                <a href="{{ route('workspace.finance.invoices.credit-notes.pdf', [$invoice, $note]) }}" class="text-xs font-semibold text-slate-700 hover:underline">PDF</a>
                                @if($note->status === 'draft')
                                    <form method="POST" action="{{ route('workspace.finance.invoices.credit-notes.issue', [$invoice, $note]) }}" onsubmit="return confirm('إصدار الإشعار وترحيله محاسبياً؟')">
                                        @csrf
                                        <button class="text-xs font-semibold text-[#06C2A4]">إصدار</button>
                                    </form>
                                @endif
                                @if($note->status !== 'cancelled')
                                    <form method="POST" action="{{ route('workspace.finance.invoices.credit-notes.cancel', [$invoice, $note]) }}" onsubmit="return confirm('إلغاء هذا الإشعار؟')">
                                        @csrf
                                        <button class="text-xs font-semibold text-rose-600">إلغاء</button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="text-slate-500">لا توجد إشعارات.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        @if($canPay)
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold">تسجيل دفعة</h3>
                <form method="POST" action="{{ route('workspace.finance.invoices.payments.store', $invoice) }}" class="mt-4 grid gap-3 md:grid-cols-3">
                    @csrf
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">المبلغ</label>
                        <input type="number" step="0.01" min="0.01" max="{{ $invoice->amount_due }}" name="amount" value="{{ old('amount', $invoice->amount_due) }}" class="w-full rounded-lg border-slate-300 text-sm" required>
                        @error('amount')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">طريقة الدفع</label>
                        <select name="method" class="w-full rounded-lg border-slate-300 text-sm">
                            @foreach(['cash' => 'نقد', 'bank_transfer' => 'تحويل بنكي', 'card' => 'بطاقة', 'other' => 'أخرى'] as $method => $label)
                                <option value="{{ $method }}" @selected(old('method') === $method)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">حساب الخزينة</label>
                        <select name="treasury_account_id" class="w-full rounded-lg border-slate-300 text-sm">
                            <option value="">بدون / الافتراضي</option>
                            @foreach($treasuryAccounts as $account)
                                <option value="{{ $account->id }}" @selected((string) old('treasury_account_id') === (string) $account->id)>{{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">المرجع</label>
                        <input type="text" name="reference" value="{{ old('reference') }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">تاريخ الدفع</label>
                        <input type="date" name="payment_date" value="{{ old('payment_date', now()->toDateString()) }}" class="w-full rounded-lg border-slate-300 text-sm" required>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">ملاحظات</label>
                        <input type="text" name="notes" value="{{ old('notes') }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div class="md:col-span-3">
                        <button class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">حفظ الدفعة</button>
                    </div>
                </form>
            </div>
        @endif

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold">المستندات</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    <li><a class="font-semibold text-[#06C2A4] hover:underline" href="{{ route('workspace.finance.invoices.pdf', $invoice) }}">تحميل PDF</a></li>
                    @if(($zatcaArtifacts['xml_available'] ?? false) && $invoice->type !== 'purchase')
                        <li><a class="font-semibold text-[#06C2A4] hover:underline" href="{{ route('workspace.finance.invoices.xml', $invoice) }}">تحميل XML الإلكتروني</a></li>
                    @endif
                    @if(($zatcaArtifacts['qr_available'] ?? false) && $invoice->type !== 'purchase')
                        <li><a class="font-semibold text-[#06C2A4] hover:underline" href="{{ route('workspace.finance.invoices.qr', $invoice) }}">عرض رمز QR</a></li>
                    @endif
                    @foreach($invoice->attachments as $attachment)
                        <li class="flex items-center justify-between gap-2">
                            <a class="text-slate-700 hover:underline" href="{{ route('workspace.finance.invoices.attachments.download', [$invoice, $attachment]) }}">{{ $attachment->file_name ?: ('مرفق #'.$attachment->id) }}</a>
                            @if($isDraft)
                                <form method="POST" action="{{ route('workspace.finance.invoices.attachments.destroy', [$invoice, $attachment]) }}" onsubmit="return confirm('حذف المرفق؟')">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-xs text-rose-600">حذف</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if($isDraft)
                    <form method="POST" action="{{ route('workspace.finance.invoices.attachments.store', $invoice) }}" enctype="multipart/form-data" class="mt-4 space-y-2">
                        @csrf
                        <input type="file" name="attachments[]" multiple class="text-sm">
                        <button class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white">رفع</button>
                    </form>
                @endif
            </div>

            @if($canViewAccounting)
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-sm font-bold">القيود المحاسبية</h3>
                    <ul class="mt-3 space-y-2 text-sm">
                        @forelse($journalEntries as $entry)
                            <li class="rounded-lg bg-slate-50 px-3 py-2">
                                <div class="flex justify-between">
                                    <span class="font-semibold">{{ $entry->entry_number }}</span>
                                    <span>{{ $entry->type }}</span>
                                </div>
                                <p class="text-xs text-slate-500">مدين {{ number_format((float) $entry->lines->sum('debit'), 2) }} / دائن {{ number_format((float) $entry->lines->sum('credit'), 2) }}</p>
                            </li>
                        @empty
                            <li class="text-slate-500">لا توجد قيود مرتبطة.</li>
                        @endforelse
                    </ul>
                </div>
            @endif
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-bold">التسلسل الزمني / التدقيق</h3>
            <ul class="mt-3 space-y-2 text-sm">
                <li class="text-slate-600">أنشأها {{ optional($invoice->creator)->name ?: '—' }} في {{ optional($invoice->created_at)->format('Y-m-d H:i') }}</li>
                @if($invoice->issued_at)
                    <li class="text-slate-600">أصدرها {{ optional($invoice->issuer)->name ?: '—' }} في {{ optional($invoice->issued_at)->format('Y-m-d H:i') }}</li>
                @endif
                @if($invoice->cancelled_at)
                    <li class="text-slate-600">أُلغيت في {{ optional($invoice->cancelled_at)->format('Y-m-d H:i') }}</li>
                @endif
                @forelse($auditLogs as $log)
                    <li class="rounded-lg bg-slate-50 px-3 py-2">
                        <span class="font-semibold">{{ $log->action }}</span>
                        <span class="text-xs text-slate-500">{{ optional($log->created_at)->format('Y-m-d H:i') }} · مستخدم #{{ $log->user_id }}</span>
                    </li>
                @empty
                    <li class="text-slate-500">لا توجد سجلات تدقيق إضافية.</li>
                @endforelse
            </ul>
        </div>
    </div>
@endsection
