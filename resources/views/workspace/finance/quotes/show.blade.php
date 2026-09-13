@extends('layouts.financial', ['pageTitle' => 'عرض سعر '.$quote->quote_number])

@php
    $status = $quote->status;
    $isDraft = $quote->isDraft();
    $isCancelled = $quote->isCancelled();
    $outcome = $quote->quoteOutcome();
    $statusClass = match ($status) {
        'cancelled' => 'bg-slate-100 text-slate-600',
        'draft' => 'bg-slate-50 text-slate-500',
        'issued' => 'bg-indigo-50 text-indigo-700',
        default => 'bg-amber-50 text-amber-700',
    };
    $outcomeClass = match ($outcome->value) {
        'accepted' => 'bg-emerald-50 text-emerald-700',
        'rejected' => 'bg-rose-50 text-rose-700',
        'converted' => 'bg-sky-50 text-sky-700',
        'expired' => 'bg-amber-50 text-amber-700',
        default => 'bg-slate-50 text-slate-600',
    };
    $statusLabels = ['draft' => 'مسودة', 'issued' => 'صادر', 'cancelled' => 'ملغى'];
    $taxProfileLabels = [
        'standard' => 'قياسية',
        'zero_rated' => 'صفرية',
        'exempt' => 'معفاة',
        'out_of_scope' => 'خارج النطاق',
    ];
    $snapshotsAuthoritative = $quote->snapshotsAreAuthoritative();
    $companyName = data_get($quote->company_snapshot, 'company_name_ar') ?: data_get($quote->company_snapshot, 'company_name');
    $companyVat = data_get($quote->company_snapshot, 'vat_number');
    $companyCr = data_get($quote->company_snapshot, 'commercial_registration');
    $recipientName = $snapshotsAuthoritative
        ? (data_get($quote->recipient_snapshot, 'name') ?: $quote->customer?->name)
        : $quote->customer?->name;
    $recipientVat = $snapshotsAuthoritative
        ? data_get($quote->recipient_snapshot, 'vat_number')
        : $quote->customer?->vat_number;
    $recipientCr = $snapshotsAuthoritative
        ? data_get($quote->recipient_snapshot, 'commercial_registration')
        : $quote->customer?->commercial_registration;
@endphp

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-slate-900">عرض سعر {{ $quote->quote_number }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ $recipientName }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-3 py-1 text-xs font-bold {{ $statusClass }}">{{ $statusLabels[$status] ?? $status }}</span>
                    <span class="rounded-full px-3 py-1 text-xs font-bold {{ $outcomeClass }}">النتيجة: {{ $outcome->labelAr() }}</span>
                    <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-bold text-slate-600">{{ $quote->currency }}</span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('workspace.finance.quotes.index') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">رجوع</a>
                <a href="{{ route('workspace.finance.quotes.pdf', $quote) }}" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">PDF</a>
                @if($isDraft)
                    <a href="{{ route('workspace.finance.quotes.edit', $quote) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">تعديل</a>
                    <form method="POST" action="{{ route('workspace.finance.quotes.issue', $quote) }}" onsubmit="return confirm('إصدار عرض السعر؟ سيتم قفل البنود والمبالغ.')">
                        @csrf
                        <button class="rounded-lg bg-[#06C2A4] px-3 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">إصدار</button>
                    </form>
                    <form method="POST" action="{{ route('workspace.finance.quotes.destroy', $quote) }}" onsubmit="return confirm('حذف مسودة عرض السعر؟')">
                        @csrf
                        @method('DELETE')
                        <button class="rounded-lg border border-rose-300 px-3 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-50">حذف المسودة</button>
                    </form>
                @endif
                @if($quote->isIssued())
                    <a href="#quote-send" class="rounded-lg bg-[#06C2A4] px-3 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">إرسال عرض السعر</a>
                    @if($quote->isAcceptable())
                        <form method="POST" action="{{ route('workspace.finance.quotes.accept', $quote) }}" onsubmit="return confirm('قبول عرض السعر؟ لن تُنشأ فاتورة تلقائياً.')">
                            @csrf
                            <button class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">قبول العرض</button>
                        </form>
                    @endif
                    @if($quote->isConvertible())
                        <form method="POST" action="{{ route('workspace.finance.quotes.convert', $quote) }}" onsubmit="return confirm('تحويل عرض السعر إلى فاتورة مسودة؟ لن تُصدر الفاتورة تلقائياً.')">
                            @csrf
                            <button class="rounded-lg bg-sky-600 px-3 py-2 text-sm font-semibold text-white hover:bg-sky-700">تحويل إلى فاتورة</button>
                        </form>
                    @endif
                    @if($quote->convertedInvoice)
                        <a href="{{ route('workspace.finance.invoices.show', $quote->convertedInvoice) }}" class="rounded-lg border border-sky-300 px-3 py-2 text-sm font-semibold text-sky-700 hover:bg-sky-50">فاتورة {{ $quote->convertedInvoice->invoice_number }}</a>
                    @endif
                    @if(! $quote->isConverted())
                        <form method="POST" action="{{ route('workspace.finance.quotes.cancel', $quote) }}" onsubmit="return confirm('إلغاء عرض السعر الصادر؟')">
                            @csrf
                            <button class="rounded-lg border border-rose-300 px-3 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-50">إلغاء</button>
                        </form>
                    @endif
                @endif
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">الشركة</h3>
                <p class="mt-2 text-sm">{{ $companyName ?: '—' }}</p>
                <p class="text-xs text-slate-500">الرقم الضريبي: {{ $companyVat ?: '—' }}</p>
                <p class="text-xs text-slate-500">السجل التجاري: {{ $companyCr ?: '—' }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">العميل</h3>
                <p class="mt-2 text-sm">{{ $recipientName ?: '—' }}</p>
                <p class="text-xs text-slate-500">الرقم الضريبي: {{ $recipientVat ?: '—' }}</p>
                <p class="text-xs text-slate-500">السجل التجاري: {{ $recipientCr ?: '—' }}</p>
                <p class="mt-2 text-xs text-slate-500">تاريخ الإصدار: {{ $quote->issue_date?->format('Y-m-d') }} · الصلاحية: {{ $quote->expiry_date?->format('Y-m-d') ?: '—' }}</p>
            </div>
        </div>

        <p class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">عرض السعر ليس فاتورة ضريبية ولا ينشئ قيدًا محاسبيًا ولا دفعة ولا يدخل سلسلة ZATCA.</p>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-bold text-slate-900">النتيجة التجارية</h3>
            <p class="mt-1 text-xs text-slate-500">منفصلة عن حالة المستند ({{ $statusLabels[$status] ?? $status }}) وعن حالة الإرسال.</p>
            <p class="mt-2 text-sm font-semibold {{ $outcomeClass }} inline-flex rounded-full px-3 py-1">{{ $outcome->labelAr() }}</p>
            @if($quote->isPastExpiry() && $quote->isPendingOutcome())
                <p class="mt-2 text-xs text-amber-700">انتهت صلاحية العرض. لا يمكن القبول أو التحويل. يمكن رفضه داخلياً.</p>
            @endif
            @if($quote->isAccepted())
                <p class="mt-2 text-sm text-emerald-800">قُبل داخلياً{{ $quote->accepted_at ? ' في '.$quote->accepted_at->timezone(config('app.timezone'))->format('Y-m-d H:i') : '' }}{{ $quote->acceptedByUser?->name ? ' بواسطة '.$quote->acceptedByUser->name : '' }}. القبول لا ينشئ فاتورة.</p>
            @endif
            @if($quote->isRejected())
                <p class="mt-2 text-sm text-rose-800">رُفض داخلياً{{ $quote->rejected_at ? ' في '.$quote->rejected_at->timezone(config('app.timezone'))->format('Y-m-d H:i') : '' }}{{ $quote->rejectedByUser?->name ? ' بواسطة '.$quote->rejectedByUser->name : '' }}.</p>
                @if($quote->rejection_reason)
                    <p class="mt-1 text-sm text-slate-600">سبب الرفض: {{ $quote->rejection_reason }}</p>
                @endif
            @endif
            @if($quote->isConverted() && $quote->convertedInvoice)
                <p class="mt-2 text-sm text-sky-800">حُوّل إلى فاتورة مسودة
                    <a href="{{ route('workspace.finance.invoices.show', $quote->convertedInvoice) }}" class="font-semibold underline">{{ $quote->convertedInvoice->invoice_number }}</a>
                    {{ $quote->converted_at ? ' في '.$quote->converted_at->timezone(config('app.timezone'))->format('Y-m-d H:i') : '' }}.
                    إصدار الفاتورة يتم من شاشة الفاتورة.</p>
            @endif
            @if($quote->isRejectable())
                <form method="POST" action="{{ route('workspace.finance.quotes.reject', $quote) }}" class="mt-4 space-y-2" onsubmit="return confirm('رفض عرض السعر؟ لا يمكن قبوله أو تحويله بعد الرفض.')">
                    @csrf
                    <label class="mb-1 block text-xs font-semibold text-slate-600">سبب الرفض (اختياري)</label>
                    <textarea name="rejection_reason" rows="2" maxlength="2000" class="w-full rounded-lg border-slate-300 text-sm" placeholder="سبب الرفض الداخلي">{{ old('rejection_reason') }}</textarea>
                    @error('rejection_reason')<p class="text-xs font-semibold text-red-600">{{ $message }}</p>@enderror
                    <button class="rounded-lg border border-rose-300 px-3 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-50">رفض العرض</button>
                </form>
            @endif
        </div>

        @php
            $sendEmail = old('email', $quote->customer?->email);
            $sendPhone = old('phone', $quote->customer?->phone);
            $companyName = $companyName ?: (string) config('app.name', 'HASEM');
            $defaultSubject = 'عرض سعر رقم '.$quote->quote_number;
            $defaultMessage = "السلام عليكم،\nنرفق لكم عرض السعر رقم {$quote->quote_number}.\nالإجمالي: ".number_format((float) $quote->total, 2).' '.($quote->currency ?: 'SAR')."\nصلاحية العرض حتى: ".($quote->expiry_date?->format('Y-m-d') ?: '—')."\nمع التحية،\n".$companyName;
            $deliveryStatusLabels = ['sending' => 'جارٍ الإرسال', 'sent' => 'تم الإرسال', 'failed' => 'فشل الإرسال'];
            $channelLabels = ['email' => 'بريد إلكتروني'];
        @endphp

        <div id="quote-send" class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900">إرسال عرض السعر</h3>
                @if($quote->isDraft())
                    <p class="mt-2 text-sm text-slate-500">يجب إصدار عرض السعر قبل إرساله بالبريد.</p>
                @elseif($quote->isCancelled())
                    <p class="mt-2 text-sm text-slate-500">لا يمكن إرسال عرض سعر ملغى.</p>
                @else
                    @if(! $quote->customer?->email)
                        <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">لا يوجد بريد إلكتروني للعميل. أدخل بريداً صالحاً قبل الإرسال.</p>
                    @endif
                    <form method="POST" action="{{ route('workspace.finance.quotes.send', $quote) }}" class="mt-3 space-y-3">
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
                <p class="mt-1 text-xs text-slate-500">حالة الإرسال منفصلة عن حالة المستند ({{ $statusLabels[$status] ?? $status }}).</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-3 py-2 text-right">التاريخ</th>
                                <th class="px-3 py-2 text-right">القناة</th>
                                <th class="px-3 py-2 text-right">المستلم</th>
                                <th class="px-3 py-2 text-right">الحالة</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($quote->deliveries as $delivery)
                                <tr class="border-t border-slate-100">
                                    <td class="px-3 py-2">{{ ($delivery->sent_at ?? $delivery->created_at)?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                                    <td class="px-3 py-2">{{ $channelLabels[$delivery->channel] ?? $delivery->channel }}</td>
                                    <td class="px-3 py-2">{{ $delivery->recipient }}</td>
                                    <td class="px-3 py-2">{{ $deliveryStatusLabels[$delivery->status] ?? $delivery->status }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-3 py-6 text-center text-slate-500">لم يُرسل هذا العرض بعد.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
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
                        @forelse($quote->items as $item)
                            <tr class="border-t border-slate-100">
                                <td class="px-4 py-2">{{ $item->lineTitle() }}</td>
                                <td class="px-4 py-2">{{ $item->displayUnit() !== '' ? $item->displayUnit() : '-' }}</td>
                                <td class="px-4 py-2">{{ number_format((float) $item->quantity, 2) }}</td>
                                <td class="px-4 py-2">{{ number_format((float) $item->unit_price, 2) }}</td>
                                <td class="px-4 py-2">{{ number_format((float) $item->discount, 2) }}</td>
                                <td class="px-4 py-2">{{ $taxProfileLabels[$item->tax_profile_type] ?? $item->tax_profile_type }}</td>
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

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm text-sm">
            <div class="flex justify-between"><span>الإجمالي قبل الضريبة</span><span>{{ number_format((float) $quote->subtotal, 2) }} {{ $quote->currency }}</span></div>
            <div class="flex justify-between"><span>الخصم</span><span>{{ number_format((float) $quote->discount, 2) }} {{ $quote->currency }}</span></div>
            <div class="flex justify-between"><span>ضريبة القيمة المضافة</span><span>{{ number_format((float) $quote->tax_amount, 2) }} {{ $quote->currency }}</span></div>
            <div class="mt-2 flex justify-between border-t pt-2 font-bold"><span>الإجمالي</span><span>{{ number_format((float) $quote->total, 2) }} {{ $quote->currency }}</span></div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-bold">المرفقات</h3>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse($quote->attachments as $attachment)
                    <li class="flex items-center justify-between gap-2">
                        <a class="text-slate-700 hover:underline" href="{{ route('workspace.finance.quotes.attachments.download', [$quote, $attachment]) }}">{{ $attachment->file_name ?: ('مرفق #'.$attachment->id) }}</a>
                        @if(! $isCancelled)
                            <form method="POST" action="{{ route('workspace.finance.quotes.attachments.destroy', [$quote, $attachment]) }}" onsubmit="return confirm('حذف المرفق؟')">
                                @csrf
                                @method('DELETE')
                                <button class="text-xs text-rose-600">حذف</button>
                            </form>
                        @endif
                    </li>
                @empty
                    <li class="text-slate-500">لا توجد مرفقات.</li>
                @endforelse
            </ul>
            @if(! $isCancelled)
                <form method="POST" action="{{ route('workspace.finance.quotes.attachments.store', $quote) }}" enctype="multipart/form-data" class="mt-4 space-y-2">
                    @csrf
                    <input type="file" name="attachments[]" multiple class="text-sm">
                    <button class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white">رفع</button>
                </form>
            @endif
        </div>

        @if($quote->terms)
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold">الشروط</h3>
                <p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $quote->terms }}</p>
            </div>
        @endif
        @if($quote->notes)
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold">ملاحظات</h3>
                <p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $quote->notes }}</p>
            </div>
        @endif
    </div>
@endsection
