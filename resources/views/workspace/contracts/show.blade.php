@extends('layouts.financial', ['pageTitle' => 'تفاصيل العقد'])

@section('content')
    @php
        $routePrefix = $routePrefix ?? 'workspace.finance.contracts';
        $billingSummary = $billingSummary ?? ['contract_value' => 0, 'invoiced' => 0, 'paid' => 0, 'outstanding' => 0, 'unbilled' => 0, 'overdue' => 0, 'cancelled_invoices' => 0, 'invoices' => collect(), 'payments' => collect()];
        $schedules = $schedules ?? $contract->billingSchedules;
        $termFields = is_array($contract->metadata) ? ($contract->metadata['terms'] ?? []) : [];
        $frequencyLabels = [
            'weekly' => 'أسبوعي',
            'monthly' => 'شهري',
            'quarterly' => 'ربع سنوي',
            'semi_annual' => 'نصف سنوي',
            'yearly' => 'سنوي',
            'custom' => 'مخصص',
            'installment' => 'أقساط',
        ];
        $scheduleStatusLabels = [
            'pending' => 'قيد الانتظار',
            'invoiced' => 'مفوتر',
            'partially_paid' => 'مدفوع جزئياً',
            'paid' => 'مدفوع',
            'overdue' => 'متأخر',
            'cancelled' => 'ملغي',
        ];
        $itemKindLabels = [
            'service' => 'خدمة',
            'product' => 'منتج',
            'subscription' => 'اشتراك',
            'one_time' => 'مرة واحدة',
            'monthly' => 'شهري',
            'recurring' => 'دوري',
        ];
        $expiry = \App\Support\Finance\ContractPresentation::expiry($contract);
    @endphp

    <div class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-slate-900">العقد {{ $contract->contract_number }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ $contract->title }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('workspace.finance.invoices.create', ['contract_id' => $contract->id, 'customer_id' => $contract->customer_id, 'project_id' => $contract->project_id, 'type' => 'sales']) }}" class="rounded-lg bg-[#06C2A4] px-3 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">فاتورة من العقد</a>
                @if($contract->isEditable())
                    <a href="{{ route($routePrefix.'.edit', $contract) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">تعديل</a>
                @endif
                <a href="{{ route($routePrefix.'.pdf', $contract) }}" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">تحميل PDF</a>
            </div>
        </div>

        <div class="flex flex-wrap gap-2 text-xs font-semibold">
            @foreach([
                ['overview', 'نظرة عامة'],
                ['items', 'البنود'],
                ['schedule', 'جدول الدفعات'],
                ['invoices', 'الفواتير'],
                ['payments', 'المدفوعات'],
                ['project', 'المشروع'],
                ['attachments', 'المرفقات'],
                ['terms', 'الشروط'],
                ['renewals', 'التجديد'],
                ['audit', 'سجل التدقيق'],
            ] as [$id, $label])
                <a href="#{{ $id }}" class="rounded-full border border-slate-200 bg-white px-3 py-1 text-slate-600 hover:border-[#06C2A4] hover:text-[#0f7668]">{{ $label }}</a>
            @endforeach
        </div>

        <section id="overview" class="grid gap-4 lg:grid-cols-3">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:col-span-2">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-bold text-slate-900">بيانات العقد</h3>
                    @include('workspace.finance.partials.status-badge', ['label' => \App\Support\Finance\ContractPresentation::statusLabel($contract->status), 'class' => \App\Support\Finance\ContractPresentation::statusBadgeClass($contract->status)])
                    @include('workspace.finance.partials.status-badge', ['label' => $expiry['label'], 'class' => \App\Support\Finance\ContractPresentation::expiryBadgeClass($expiry['severity'])])
                </div>
                <dl class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-slate-500">العميل</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $contract->customer?->name ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">المشروع</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">
                            @if($contract->project)
                                <a class="text-[#0f7668] hover:underline" href="{{ route('workspace.finance.projects.index') }}">{{ $contract->project->name }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">العملة / القيمة</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">{{ number_format((float) $contract->value, 2) }} {{ $contract->currency }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">شروط الدفع</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $contract->payment_terms ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">تاريخ البداية</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">{{ optional($contract->start_date)->format('Y-m-d') ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">تاريخ النهاية</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">{{ optional($contract->end_date)->format('Y-m-d') ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">حالة التوقيع التشغيلي</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $contract->signature_status ?: 'unsigned' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500">العقد الأصلي</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900">
                            @if($contract->parentContract)
                                <a class="text-[#0f7668] hover:underline" href="{{ route($routePrefix.'.show', $contract->parentContract) }}">{{ $contract->parentContract->contract_number }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                </dl>
                @if($contract->description)
                    <p class="mt-4 whitespace-pre-line rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">{{ $contract->description }}</p>
                @endif
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" x-data="{ sendEmail: false }">
                <h3 class="mb-3 text-sm font-bold text-slate-900">إجراءات العقد</h3>

                @if($contract->status === 'draft')
                    <form method="POST" action="{{ route($routePrefix.'.activate', $contract) }}" class="space-y-3">
                        @csrf
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input type="checkbox" name="send_email" value="1" x-model="sendEmail" class="rounded border-slate-300 text-slate-900">
                            إرسال العقد بالبريد عند التفعيل
                        </label>
                        <div x-cloak x-show="sendEmail" class="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <select name="email_account_id" class="w-full rounded-lg border-slate-300 text-sm">
                                <option value="">اختر الحساب</option>
                                @foreach($emailAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }} ({{ $account->email }})</option>
                                @endforeach
                            </select>
                            <input name="recipient" value="{{ old('recipient', $contract->customer?->email) }}" class="w-full rounded-lg border-slate-300 text-sm" placeholder="client@example.com">
                            <input name="subject" value="{{ old('subject', 'تفعيل العقد '.$contract->contract_number) }}" class="w-full rounded-lg border-slate-300 text-sm">
                        </div>
                        <button class="w-full rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">تفعيل العقد</button>
                    </form>
                @elseif(in_array($contract->status, ['open', 'expired'], true))
                    <div class="space-y-2">
                        @if($contract->status === 'open')
                            <form method="POST" action="{{ route($routePrefix.'.close', $contract) }}" onsubmit="return confirm('إغلاق العقد؟')">
                                @csrf
                                <button class="w-full rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">إغلاق العقد</button>
                            </form>
                            <form method="POST" action="{{ route($routePrefix.'.cancel', $contract) }}" onsubmit="return confirm('إلغاء العقد؟')">
                                @csrf
                                <button class="w-full rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">إلغاء العقد</button>
                            </form>
                        @endif
                    </div>
                @else
                    <p class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">لا توجد إجراءات حالة إضافية.</p>
                @endif

                <div class="mt-4 space-y-2 border-t border-slate-100 pt-3">
                    <p class="text-xs font-semibold text-slate-600">توقيع تشغيلي (ليس توقيعاً قانونياً معتمداً)</p>
                    @if($contract->signature_status !== 'signed')
                        <form method="POST" action="{{ route($routePrefix.'.sign', $contract) }}" class="grid gap-2">
                            @csrf
                            <select name="party" class="rounded-lg border-slate-300 text-sm" required>
                                <option value="customer">توقيع العميل</option>
                                <option value="company">توقيع الشركة</option>
                            </select>
                            <input name="signer_name" class="rounded-lg border-slate-300 text-sm" placeholder="اسم الموقع إن لزم">
                            <button class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700">تسجيل التوقيع</button>
                        </form>
                    @endif
                    <p class="text-[11px] text-slate-500">العميل: {{ optional($contract->customer_signed_at)->format('Y-m-d H:i') ?: 'غير موقع' }} · الشركة: {{ optional($contract->company_signed_at)->format('Y-m-d H:i') ?: 'غير موقع' }}</p>
                </div>
            </article>
        </section>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">قيمة العقد</p>
                <p class="mt-1 text-xl font-extrabold">{{ number_format((float) $billingSummary['contract_value'], 2) }}</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">المفوتر</p>
                <p class="mt-1 text-xl font-extrabold">{{ number_format((float) $billingSummary['invoiced'], 2) }}</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">المدفوع</p>
                <p class="mt-1 text-xl font-extrabold">{{ number_format((float) $billingSummary['paid'], 2) }}</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">المتبقي على الفواتير</p>
                <p class="mt-1 text-xl font-extrabold">{{ number_format((float) $billingSummary['outstanding'], 2) }}</p>
            </article>
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-slate-500">غير المفوتر</p>
                <p class="mt-1 text-xl font-extrabold">{{ number_format((float) $billingSummary['unbilled'], 2) }}</p>
            </article>
        </div>

        <article id="items" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold text-slate-900">بنود العقد</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-2 py-2 text-right">البند</th>
                            <th class="px-2 py-2 text-right">النوع</th>
                            <th class="px-2 py-2 text-right">الكمية</th>
                            <th class="px-2 py-2 text-right">سعر الوحدة</th>
                            <th class="px-2 py-2 text-right">الخصم</th>
                            <th class="px-2 py-2 text-right">الضريبة</th>
                            <th class="px-2 py-2 text-right">الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($contract->items as $item)
                            <tr>
                                <td class="px-2 py-2 font-semibold">{{ $item->title }}<p class="text-xs font-normal text-slate-500">{{ $item->description }}</p></td>
                                <td class="px-2 py-2">{{ $itemKindLabels[$item->item_kind] ?? ($item->item_kind ?: 'خدمة') }}</td>
                                <td class="px-2 py-2">{{ number_format((float) $item->quantity, 3) }} {{ $item->unit }}</td>
                                <td class="px-2 py-2">{{ number_format((float) $item->unit_price, 2) }}</td>
                                <td class="px-2 py-2">{{ number_format((float) ($item->discount ?? 0), 2) }}</td>
                                <td class="px-2 py-2">{{ number_format((float) ($item->tax_amount ?? 0), 2) }}</td>
                                <td class="px-2 py-2">{{ number_format((float) $item->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-2 py-6 text-center text-slate-500">لا توجد بنود بعد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article id="schedule" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold text-slate-900">جدول الدفعات / الفوترة</h3>
            <p class="mb-4 text-xs text-slate-500">يستخدم جدول الفوترة المالي الحالي. لن تُنشأ فواتير تلقائياً إلا بعد تفعيل الجدول مع خيار الإصدار التلقائي.</p>
            <div class="mb-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-2 py-2 text-right">العنوان</th>
                            <th class="px-2 py-2 text-right">الحالة</th>
                            <th class="px-2 py-2 text-right">الدورية</th>
                            <th class="px-2 py-2 text-right">القيمة</th>
                            <th class="px-2 py-2 text-right">النسبة</th>
                            <th class="px-2 py-2 text-right">الاستحقاق</th>
                            <th class="px-2 py-2 text-right">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($schedules as $schedule)
                            <tr>
                                <td class="px-2 py-2 font-semibold">{{ $schedule->title }}<p class="text-xs font-normal text-slate-500">{{ $schedule->description }}</p></td>
                                <td class="px-2 py-2">{{ $scheduleStatusLabels[$schedule->payment_status ?? 'pending'] ?? ($schedule->payment_status ?? $schedule->status) }}</td>
                                <td class="px-2 py-2">{{ $frequencyLabels[$schedule->frequency] ?? $schedule->frequency }}</td>
                                <td class="px-2 py-2">{{ number_format((float) $schedule->amount, 2) }} {{ $schedule->currency }}</td>
                                <td class="px-2 py-2">{{ number_format((float) ($schedule->percentage ?? 0), 1) }}%</td>
                                <td class="px-2 py-2">{{ optional($schedule->next_run_on)->format('Y-m-d') ?: '—' }}</td>
                                <td class="px-2 py-2">
                                    <div class="flex flex-wrap gap-2 text-xs font-semibold">
                                        @if($schedule->status === 'draft' || $schedule->status === 'paused')
                                            <form method="POST" action="{{ route('workspace.finance.contracts.billing-schedules.activate', [$contract, $schedule]) }}">@csrf<button class="text-[#06C2A4]">تفعيل</button></form>
                                        @endif
                                        @if($schedule->status === 'active')
                                            <form method="POST" action="{{ route('workspace.finance.contracts.billing-schedules.pause', [$contract, $schedule]) }}">@csrf<button class="text-slate-700">إيقاف</button></form>
                                        @endif
                                        @if(! in_array($schedule->status, ['cancelled', 'completed'], true) && $contract->status === 'open')
                                            <form method="POST" action="{{ route('workspace.finance.contracts.billing-schedules.generate', [$contract, $schedule]) }}">@csrf<button class="text-slate-700">إنشاء فاتورة</button></form>
                                        @endif
                                        @if(! in_array($schedule->status, ['cancelled', 'completed'], true))
                                            <form method="POST" action="{{ route('workspace.finance.contracts.billing-schedules.cancel', [$contract, $schedule]) }}" onsubmit="return confirm('إلغاء جدول الفوترة؟')">@csrf<button class="text-rose-600">إلغاء</button></form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-2 py-6 text-center text-slate-500">لا يوجد جدول دفعات بعد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if(in_array($contract->status, ['draft', 'open'], true))
                <form method="POST" action="{{ route('workspace.finance.contracts.billing-schedules.store', $contract) }}" class="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2 lg:grid-cols-4">
                    @csrf
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-xs font-semibold text-slate-600">عنوان الدفعة</label>
                        <input type="text" name="title" value="{{ old('title', 'دفعة العقد '.$contract->contract_number) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">الدورية</label>
                        <select name="frequency" class="w-full rounded-lg border-slate-300 text-sm" required>
                            @foreach($frequencyLabels as $value => $label)
                                <option value="{{ $value }}" @selected(old('frequency', 'installment') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">عدد الدورات</label>
                        <input type="number" min="1" max="120" name="total_occurrences" value="{{ old('total_occurrences', 1) }}" class="w-full rounded-lg border-slate-300 text-sm" required>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">مبلغ الدفعة</label>
                        <input type="number" min="0" step="0.01" name="amount" value="{{ old('amount') }}" class="w-full rounded-lg border-slate-300 text-sm" placeholder="افتراضي: تقسيم قيمة العقد">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">تاريخ الاستحقاق</label>
                        <input type="date" name="start_date" value="{{ old('start_date', optional($contract->start_date)->format('Y-m-d') ?? now()->toDateString()) }}" class="w-full rounded-lg border-slate-300 text-sm" required>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">تاريخ النهاية</label>
                        <input type="date" name="end_date" value="{{ old('end_date', optional($contract->end_date)->format('Y-m-d')) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                        <input type="checkbox" name="auto_issue" value="1" class="rounded border-slate-300">
                        إصدار الفاتورة تلقائياً عند حلول الاستحقاق
                    </label>
                    <div class="lg:col-span-4">
                        <input type="text" name="description" value="{{ old('description') }}" class="w-full rounded-lg border-slate-300 text-sm" placeholder="وصف الدفعة">
                    </div>
                    <div class="lg:col-span-4">
                        <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">إضافة دفعة / جدول فوترة</button>
                    </div>
                </form>
            @endif
        </article>

        <article id="invoices" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold text-slate-900">الفواتير المرتبطة</h3>
            <p class="mb-3 text-xs text-slate-500">متأخر: {{ number_format((float) $billingSummary['overdue'], 2) }} · ملغى: {{ number_format((float) $billingSummary['cancelled_invoices'], 2) }}</p>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-2 py-2 text-right">الرقم</th>
                            <th class="px-2 py-2 text-right">الحالة</th>
                            <th class="px-2 py-2 text-right">الإجمالي</th>
                            <th class="px-2 py-2 text-right">المدفوع</th>
                            <th class="px-2 py-2 text-right">المتبقي</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($billingSummary['invoices'] as $relatedInvoice)
                            @php $life = \App\Support\Finance\InvoicePresentation::lifecycle($relatedInvoice); @endphp
                            <tr>
                                <td class="px-2 py-2"><a class="font-semibold text-[#06C2A4] hover:underline" href="{{ route('workspace.finance.invoices.show', $relatedInvoice) }}">{{ $relatedInvoice->invoice_number }}</a></td>
                                <td class="px-2 py-2">@include('workspace.finance.partials.status-badge', ['label' => \App\Support\Finance\InvoicePresentation::label($life), 'class' => \App\Support\Finance\InvoicePresentation::badgeClass($life)])</td>
                                <td class="px-2 py-2">{{ number_format((float) $relatedInvoice->total, 2) }}</td>
                                <td class="px-2 py-2">{{ number_format((float) $relatedInvoice->amount_paid, 2) }}</td>
                                <td class="px-2 py-2 font-semibold {{ (float) $relatedInvoice->amount_due > 0 ? 'text-amber-700' : 'text-emerald-700' }}">{{ number_format((float) $relatedInvoice->amount_due, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-2 py-6 text-center text-slate-500">لا توجد فواتير مرتبطة بهذا العقد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article id="payments" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold text-slate-900">المدفوعات</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-2 py-2 text-right">التاريخ</th>
                            <th class="px-2 py-2 text-right">المبلغ</th>
                            <th class="px-2 py-2 text-right">المرجع</th>
                            <th class="px-2 py-2 text-right">الحالة</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($billingSummary['payments'] as $payment)
                            <tr>
                                <td class="px-2 py-2">{{ optional($payment->paid_at ?? $payment->created_at)->format('Y-m-d') }}</td>
                                <td class="px-2 py-2 font-semibold">{{ number_format((float) $payment->amount, 2) }}</td>
                                <td class="px-2 py-2">{{ $payment->reference ?: '—' }}</td>
                                <td class="px-2 py-2">{{ $payment->status ?: 'posted' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-2 py-6 text-center text-slate-500">لا توجد مدفوعات بعد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article id="project" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold text-slate-900">المشروع المرتبط</h3>
            @if($contract->project)
                <p class="text-sm font-semibold">{{ $contract->project->name }}</p>
                <p class="mt-1 text-xs text-slate-500">العقد ← المشروع ← الفواتير ← المدفوعات. الفواتير أعلاه هي المصدر المالي الحقيقي.</p>
            @else
                <p class="text-sm text-slate-500">لا يوجد مشروع مرتبط.</p>
            @endif
        </article>

        <div id="attachments">
            @include('workspace.contracts.partials.attachments-panel', [
                'contract' => $contract,
                'routePrefix' => $routePrefix,
                'allowDelete' => true,
            ])
            <form method="POST" action="{{ route($routePrefix.'.attachments.store', $contract) }}" enctype="multipart/form-data" class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white p-4">
                @csrf
                <input type="file" name="attachments[]" multiple class="text-sm">
                <button class="mt-2 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700">رفع مرفقات</button>
            </form>
        </div>

        <article id="terms" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold text-slate-900">شروط العقد</h3>
            <div class="grid gap-3 md:grid-cols-2">
                @foreach([
                    'payment' => 'شروط الدفع',
                    'delivery' => 'شروط التسليم',
                    'cancellation' => 'شروط الإلغاء',
                    'renewal' => 'شروط التجديد',
                    'warranty' => 'الضمان',
                    'termination' => 'إنهاء العقد',
                    'additional' => 'شروط إضافية',
                ] as $key => $label)
                    <div>
                        <h4 class="mb-1 text-xs font-semibold text-slate-600">{{ $label }}</h4>
                        <div class="min-h-16 whitespace-pre-line rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">{{ $termFields[$key] ?? '—' }}</div>
                    </div>
                @endforeach
            </div>
            <div class="mt-3">
                <h4 class="mb-1 text-xs font-semibold text-slate-600">النص الشامل</h4>
                <div class="whitespace-pre-line rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">{{ $contract->terms ?: '—' }}</div>
            </div>
            <div class="mt-3">
                <h4 class="mb-1 text-xs font-semibold text-slate-600">ملاحظات</h4>
                <div class="whitespace-pre-line rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">{{ $contract->notes ?: '—' }}</div>
            </div>
        </article>

        <article id="renewals" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold text-slate-900">سجل التجديد</h3>
            <ul class="mb-4 space-y-2 text-sm">
                @forelse($contract->renewals as $renewal)
                    <li><a class="font-semibold text-[#0f7668] hover:underline" href="{{ route($routePrefix.'.show', $renewal) }}">{{ $renewal->contract_number }}</a> · {{ $renewal->title }} · {{ optional($renewal->start_date)->format('Y-m-d') }}</li>
                @empty
                    <li class="text-slate-500">لا توجد تجديدات.</li>
                @endforelse
            </ul>
            @if(in_array($contract->status, ['open', 'expired', 'closed'], true))
                <form method="POST" action="{{ route($routePrefix.'.renew', $contract) }}" class="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2 lg:grid-cols-4">
                    @csrf
                    <div class="lg:col-span-2">
                        <label class="mb-1 block text-xs font-semibold text-slate-600">عنوان التجديد</label>
                        <input name="title" value="{{ old('title', $contract->title) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">تاريخ البداية</label>
                        <input type="date" name="start_date" value="{{ old('start_date', $contract->end_date ? $contract->end_date->copy()->addDay()->format('Y-m-d') : now()->toDateString()) }}" class="w-full rounded-lg border-slate-300 text-sm" required>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">تاريخ النهاية</label>
                        <input type="date" name="end_date" value="{{ old('end_date') }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">القيمة الجديدة</label>
                        <input type="number" min="0" step="0.01" name="value" value="{{ old('value', $contract->value) }}" class="w-full rounded-lg border-slate-300 text-sm">
                    </div>
                    <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                        <input type="checkbox" name="copy_schedules" value="1" class="rounded border-slate-300" checked>
                        نسخ جداول الدفعات كمسودات
                    </label>
                    <div class="lg:col-span-4">
                        <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">تجديد العقد</button>
                    </div>
                </form>
            @endif
        </article>

        <article id="audit" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold text-slate-900">سجل التدقيق</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-2 py-2 text-right">الإجراء</th>
                            <th class="px-2 py-2 text-right">المستخدم</th>
                            <th class="px-2 py-2 text-right">الوقت</th>
                            <th class="px-2 py-2 text-right">IP</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($auditLogs ?? [] as $log)
                            <tr>
                                <td class="px-2 py-2 font-semibold">{{ $log->action }}</td>
                                <td class="px-2 py-2">{{ $log->user?->name ?: '—' }}</td>
                                <td class="px-2 py-2">{{ optional($log->occurred_at)->format('Y-m-d H:i') }}</td>
                                <td class="px-2 py-2">{{ $log->ip_address ?: '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-2 py-6 text-center text-slate-500">لا توجد أحداث مسجلة.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
    </div>
@endsection
