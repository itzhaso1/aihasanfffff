@php
    $company = is_array($companySnapshot ?? null) ? $companySnapshot : [];
    $recipient = is_array($recipientSnapshot ?? null) ? $recipientSnapshot : [];
    $theme = is_array($pdfSnapshot ?? null) ? $pdfSnapshot : [];
    $useLiveFallbacks = ! ($snapshotsAuthoritative ?? false);
    $primaryColor = $theme['primary_color'] ?? ($useLiveFallbacks ? ($setting?->invoice_primary_color ?: '#06C2A4') : '#06C2A4');

    $quoteStatus = $quote->status ?: 'draft';
    $quoteStatusLabels = [
        'draft' => 'مسودة',
        'issued' => 'صادر',
        'cancelled' => 'ملغى',
    ];
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
    $taxBreakdown = is_array($quote->tax_breakdown ?? null) ? $quote->tax_breakdown : [];

    $companyName = $company['company_name_ar'] ?? $company['company_name'] ?? ($useLiveFallbacks ? ($setting?->company_name_ar ?? $setting?->company_name) : null) ?? 'عرض سعر';
    $companyNameEn = $company['company_name'] ?? ($useLiveFallbacks ? $setting?->company_name : null);
    $companyAddress = trim(implode(' - ', array_filter([
        $company['address_line'] ?? null,
        $company['street'] ?? null,
        $company['district'] ?? null,
        $company['city'] ?? null,
        $company['postal_code'] ?? null,
        $company['country_code'] ?? null,
    ])));
    $companyVat = $company['vat_number'] ?? ($useLiveFallbacks ? $setting?->vat_number : null);
    $companyCr = $company['commercial_registration'] ?? ($useLiveFallbacks ? $setting?->commercial_registration : null);
    $companyPhone = $company['phone'] ?? ($useLiveFallbacks ? $setting?->phone : null);
    $companyEmail = $company['email'] ?? ($useLiveFallbacks ? $setting?->email : null);
    $companyWebsite = $company['website'] ?? ($useLiveFallbacks ? $setting?->website : null);
    $invoiceFooter = $theme['footer_text'] ?? ($useLiveFallbacks ? $setting?->invoice_footer_text : null);

    $recipientName = $recipient['name']
        ?? ($useLiveFallbacks ? ($quote->customer?->name ?? null) : null)
        ?? '-';
    $recipientAddress = $recipient['address']
        ?? ($useLiveFallbacks ? ($quote->customer?->address ?? null) : null);
    $recipientVat = $recipient['vat_number']
        ?? ($useLiveFallbacks ? ($quote->customer?->vat_number ?? null) : null);
    $recipientCr = $recipient['commercial_registration']
        ?? ($useLiveFallbacks ? ($quote->customer?->commercial_registration ?? null) : null);
    $recipientPhone = $recipient['phone']
        ?? ($useLiveFallbacks ? ($quote->customer?->phone ?? null) : null);
    $recipientEmail = $recipient['email']
        ?? ($useLiveFallbacks ? ($quote->customer?->email ?? null) : null);
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
            <title>عرض سعر {{ $quote->quote_number }}</title>
    <style>
        @page {
            size: A4;
            margin: 20mm 12mm 24mm 12mm;
        }

        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            color: #0f172a;
            font-size: 12px;
            line-height: 1.5;
            direction: rtl;
            unicode-bidi: embed;
        }

        .document {
            width: 100%;
        }

        .header {
            width: 100%;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
        }

        .header-table td {
            vertical-align: top;
            border: none;
            padding: 0;
        }

        .logo {
            max-width: 120px;
            max-height: 56px;
            margin-bottom: 6px;
        }

        .title {
            margin: 0;
            color: {{ $primaryColor }};
            font-size: 24px;
            font-weight: 700;
        }

        .subtitle {
            margin: 2px 0 0;
            color: #64748b;
            font-size: 11px;
        }

        .invoice-badge {
            display: inline-block;
            border: 1px solid #d1d5db;
            border-radius: 9999px;
            padding: 3px 8px;
            margin: 2px 2px 0 0;
            font-size: 11px;
            color: #334155;
            background: #f8fafc;
        }

        .blocks {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }

        .blocks td {
            width: 50%;
            vertical-align: top;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 10px;
        }

        .block-title {
            margin: 0 0 4px;
            color: #0f172a;
            font-weight: 700;
            font-size: 12px;
        }

        .muted {
            color: #64748b;
            font-size: 11px;
        }

        .items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }

        .items thead th {
            background: #f8fafc;
            color: #0f172a;
            font-weight: 700;
            border: 1px solid #e2e8f0;
            padding: 7px;
            font-size: 11px;
            text-align: right;
        }

        .items tbody td {
            border: 1px solid #e2e8f0;
            padding: 7px;
            font-size: 11px;
            vertical-align: top;
            word-break: break-word;
            direction: rtl;
            unicode-bidi: embed;
        }

        .items tbody tr {
            page-break-inside: avoid;
        }

        .totals {
            width: 48%;
            margin-right: auto;
            margin-top: 12px;
            border-collapse: collapse;
        }

        .totals td {
            border: 1px solid #e2e8f0;
            padding: 6px 8px;
            font-size: 11px;
        }

        .totals .label {
            background: #f8fafc;
            width: 65%;
            font-weight: 600;
        }

        .totals .grand {
            font-weight: 700;
            color: {{ $primaryColor }};
        }

        .notes-box {
            margin-top: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 10px;
        }

        .footer {
            position: fixed;
            bottom: -10mm;
            right: 0;
            left: 0;
            border-top: 1px solid #e2e8f0;
            padding-top: 6px;
            color: #64748b;
            font-size: 10px;
        }

        .footer-table {
            width: 100%;
            border-collapse: collapse;
        }

        .footer-table td {
            border: none;
            padding: 0;
        }
    </style>
</head>
<body>
    <div class="document">
        <div class="header">
            <table class="header-table">
                <tr>
                    <td style="width: 60%;">
                        @if(!empty($logoDataUri))
                            <img src="{{ $logoDataUri }}" alt="Company Logo" class="logo">
                        @endif
                        <h1 class="title">{{ $companyName }}</h1>
                        @if($companyNameEn && $companyNameEn !== $companyName)
                            <p class="subtitle">{{ $companyNameEn }}</p>
                        @endif
                        <p class="subtitle">
                            {{ $companyAddress !== '' ? $companyAddress : '-' }}<br>
                            ضريبة القيمة المضافة: {{ $companyVat ?: '-' }} | السجل التجاري: {{ $companyCr ?: '-' }}<br>
                            {{ $companyPhone ?: '-' }} | {{ $companyEmail ?: '-' }}
                            @if($companyWebsite)
                                | {{ $companyWebsite }}
                            @endif
                        </p>
                    </td>
                    <td style="width: 40%; text-align: left;">
                        <p style="margin:0;font-size:18px;font-weight:700;">عرض سعر</p>
                        <p class="subtitle">#{{ $quote->quote_number }}</p>
                        <p class="subtitle">تاريخ الإصدار: {{ $quote->issue_date?->format('Y-m-d') ?? '-' }}</p>
                        @if($quote->issued_at)
                            <p class="subtitle">وقت الاعتماد: {{ $quote->issued_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</p>
                        @endif
                        <p class="subtitle">تاريخ الصلاحية: {{ $quote->expiry_date?->format('Y-m-d') ?? '-' }}</p>
                        <div>
                            <span class="invoice-badge">حالة المستند: {{ $quoteStatusLabels[$quoteStatus] ?? $quoteStatus }}</span>
                        </div>
                    </td>
                </tr>
            </table>
        </div>

        <table class="blocks">
            <tr>
                <td>
                    <p class="block-title">بيانات العميل</p>
                    <div>{{ $recipientName }}</div>
                    <div class="muted">{{ $recipientAddress ?: '—' }}</div>
                    <div class="muted">الرقم الضريبي: {{ $recipientVat ?: '-' }}</div>
                    <div class="muted">السجل التجاري: {{ $recipientCr ?: '-' }}</div>
                    <div class="muted">{{ $recipientPhone ?: '-' }} | {{ $recipientEmail ?: '-' }}</div>
                </td>
                <td>
                    <p class="block-title">معلومات عرض السعر</p>
                    <div class="muted">العملة: {{ $quote->currency }}</div>
                    <div class="muted">تسعير الضريبة: {{ $taxPriceModeLabels[$quote->tax_price_mode ?? 'exclusive'] ?? 'غير شامل الضريبة' }}</div>
                    <div class="muted">هذا المستند عرض سعر وليس فاتورة ضريبية.</div>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th>البند</th>
                    <th>الوحدة</th>
                    <th>الكمية</th>
                    <th>سعر الوحدة</th>
                    <th>التصنيف</th>
                    <th>الخصم</th>
                    <th>الضريبة</th>
                    <th>الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                @foreach($quote->items as $item)
                    @php
                        $lineTitle = $item->lineTitle();
                        $lineDescription = trim((string) $item->description);
                        $lineUnit = $item->displayUnit();
                    @endphp
                    <tr>
                        <td>
                            {{ $lineTitle !== '' ? $lineTitle : '-' }}
                            @if($lineDescription !== '' && $lineDescription !== $lineTitle)
                                <div class="muted">{{ $lineDescription }}</div>
                            @endif
                        </td>
                        <td>{{ $lineUnit !== '' ? $lineUnit : '-' }}</td>
                        <td>{{ number_format((float) $item->quantity, 3) }}</td>
                        <td>{{ number_format((float) $item->unit_price, 2) }}</td>
                        <td>{{ $taxProfileLabels[$item->tax_profile_type ?? ''] ?? ($item->tax_profile_type ?: '-') }}</td>
                        <td>{{ number_format((float) $item->discount, 2) }}</td>
                        <td>{{ number_format((float) $item->tax_amount, 2) }}</td>
                        <td>{{ number_format((float) $item->total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr>
                <td class="label">الإجمالي قبل الضريبة</td>
                <td>{{ number_format((float) $quote->subtotal, 2) }} {{ $quote->currency }}</td>
            </tr>
            <tr>
                <td class="label">الخصم</td>
                <td>{{ number_format((float) $quote->discount, 2) }} {{ $quote->currency }}</td>
            </tr>
            <tr>
                <td class="label">المبلغ الخاضع للضريبة</td>
                <td>{{ number_format((float) $quote->taxable_amount, 2) }} {{ $quote->currency }}</td>
            </tr>
            <tr>
                <td class="label">الضريبة</td>
                <td>{{ number_format((float) $quote->tax_amount, 2) }} {{ $quote->currency }}</td>
            </tr>
            <tr>
                <td class="label grand">الإجمالي</td>
                <td class="grand">{{ number_format((float) $quote->total, 2) }} {{ $quote->currency }}</td>
            </tr>
            @foreach($taxBreakdown as $bucket)
            <tr>
                <td class="label">{{ $taxProfileLabels[$bucket['tax_profile_type'] ?? ''] ?? ($bucket['tax_profile_type'] ?? 'ضريبة') }} @ {{ number_format((float) ($bucket['tax_rate'] ?? 0), 2) }}%</td>
                <td>{{ number_format((float) ($bucket['taxable_amount'] ?? 0), 2) }} / {{ number_format((float) ($bucket['tax_amount'] ?? 0), 2) }} {{ $quote->currency }}</td>
            </tr>
            @endforeach
        </table>

        @if($quote->terms)
            <div class="notes-box">
                <strong>الشروط:</strong>
                <div class="muted">{{ $quote->terms }}</div>
            </div>
        @endif

        @if($quote->notes)
            <div class="notes-box">
                <strong>ملاحظات:</strong>
                <div class="muted">{{ $quote->notes }}</div>
            </div>
        @endif

        <div class="footer">
            <table class="footer-table">
                <tr>
                    <td style="text-align: right;">
                        {!! nl2br(e($invoiceFooter ?: 'هذا عرض سعر وليس فاتورة ضريبية.')) !!}
                    </td>
                    <td style="text-align: left;">
                        رقم الصفحة يظهر تلقائيًا
                    </td>
                </tr>
            </table>
        </div>
    </div>
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->get_font('DejaVu Sans', 'normal');
            $pdf->page_text(500, 815, "{PAGE_NUM}/{PAGE_COUNT}", $font, 9, [0.39, 0.45, 0.55]);
        }
    </script>
</body>
</html>
