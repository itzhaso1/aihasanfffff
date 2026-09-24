@php
    $company = is_array($companySnapshot ?? null) ? $companySnapshot : [];
    $recipient = is_array($recipientSnapshot ?? null) ? $recipientSnapshot : [];
    $theme = is_array($pdfSnapshot ?? null) ? $pdfSnapshot : [];
    $useLiveFallbacks = ! ($snapshotsAuthoritative ?? false);
    $primaryColor = $theme['primary_color'] ?? ($useLiveFallbacks ? ($setting?->invoice_primary_color ?: '#06C2A4') : '#06C2A4');

    $invoiceStatus = $invoice->invoice_status
        ?? (in_array($invoice->status, ['draft', 'cancelled'], true) ? $invoice->status : 'issued');
    $paymentStatus = $invoice->payment_status
        ?? (in_array($invoice->status, ['unpaid', 'partial', 'paid', 'overdue'], true) ? $invoice->status : 'unpaid');

    $invoiceStatusLabels = [
        'draft' => 'مسودة',
        'issued' => 'معتمدة',
        'cancelled' => 'ملغاة',
    ];
    $paymentStatusLabels = [
        'unpaid' => 'غير مدفوعة',
        'partial' => 'مدفوعة جزئيًا',
        'paid' => 'مدفوعة بالكامل',
        'overdue' => 'متأخرة',
    ];
    $taxDocumentLabels = [
        'standard' => 'قياسية',
        'simplified' => 'مبسطة',
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
    $taxBreakdown = is_array($invoice->tax_breakdown ?? null) ? $invoice->tax_breakdown : [];

    // Reliable Arabic font for PDF rendering (Dompdf / server-side PDF engines).
    // Prefer fonts bundled in public/fonts, then fall back to common Linux paths.
    $arabicFontRegularPath = public_path('fonts/NotoSansArabic-Regular.ttf');
    $arabicFontBoldPath = public_path('fonts/NotoSansArabic-Bold.ttf');
    if (!is_file($arabicFontRegularPath)) {
        $arabicFontRegularPath = '/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf';
    }
    if (!is_file($arabicFontBoldPath)) {
        $arabicFontBoldPath = '/usr/share/fonts/truetype/noto/NotoSansArabic-Bold.ttf';
    }
    $arabicFontRegularUri = 'file://' . str_replace('\\', '/', $arabicFontRegularPath);
    $arabicFontBoldUri = 'file://' . str_replace('\\', '/', $arabicFontBoldPath);

    $companyName = $company['company_name_ar'] ?? $company['company_name'] ?? ($useLiveFallbacks ? ($setting?->company_name_ar ?? $setting?->company_name) : null) ?? 'فاتورة';
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
        ?? ($useLiveFallbacks
            ? ($invoice->type === 'sales' ? ($invoice->customer?->name ?? $invoice->customer_name) : ($invoice->supplier?->name ?? null))
            : ($invoice->customer_name ?: null))
        ?? '-';
    $recipientAddress = $recipient['address']
        ?? ($useLiveFallbacks
            ? ($invoice->type === 'sales' ? ($invoice->customer?->address ?? null) : ($invoice->supplier?->address ?? null))
            : null);
    $recipientVat = $recipient['vat_number']
        ?? ($useLiveFallbacks
            ? ($invoice->type === 'sales' ? ($invoice->customer?->vat_number ?? null) : ($invoice->supplier?->vat_number ?? null))
            : null);
    $recipientPhone = $recipient['phone']
        ?? ($useLiveFallbacks
            ? ($invoice->type === 'sales' ? ($invoice->customer?->phone ?? null) : ($invoice->supplier?->phone ?? null))
            : null);
    $recipientEmail = $recipient['email']
        ?? ($useLiveFallbacks
            ? ($invoice->type === 'sales' ? ($invoice->customer?->email ?? null) : ($invoice->supplier?->email ?? null))
            : null);
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>فاتورة {{ $invoice->invoice_number }}</title>
    <style>
        @font-face {
            font-family: 'InvoiceArabic';
            font-style: normal;
            font-weight: 400;
            src: url('{{ $arabicFontRegularUri }}') format('truetype');
        }

        @font-face {
            font-family: 'InvoiceArabic';
            font-style: normal;
            font-weight: 700;
            src: url('{{ $arabicFontBoldUri }}') format('truetype');
        }

        @page {
            size: A4;
            margin: 9mm 8mm 14mm 8mm;
        }

        body {
            margin: 0;
            padding: 0;
            background: #ffffff;
            color: #19344f;
            font-family: 'InvoiceArabic', 'Noto Sans Arabic', 'DejaVu Sans', Arial, sans-serif;
            font-size: 12px;
            line-height: 1.55;
            direction: rtl;
        }

        * { box-sizing: border-box; }

        .document { width: 100%; }

        .teal { color: #087c73; }
        .navy { color: #19344f; }
        .muted { color: #75849a; }

        /* ===== Header ===== */
        .header {
            width: 100%;
            padding: 3px 4px 12px;
            border-bottom: 1px solid #dbe3ea;
            margin-bottom: 14px;
        }

        .header-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            direction: ltr;
        }

        .header-table td {
            border: 0;
            padding: 0;
            vertical-align: top;
        }

        .brand-cell {
            width: 55%;
            text-align: left;
            direction: rtl;
            padding-right: 20px !important;
        }

        .invoice-cell {
            width: 45%;
            text-align: right;
            direction: rtl;
            padding-left: 10px !important;
        }

        .logo {
            display: block;
            max-width: 175px;
            max-height: 72px;
            margin: 0 0 5px;
        }

        .brand-name {
            margin: 0;
            color: #087c73;
            font-size: 31px;
            line-height: 1.05;
            font-weight: 800;
        }

        .brand-tagline {
            margin: 4px 0 0;
            color: #78869a;
            font-size: 12px;
        }

        .brand-tagline-ar {
            margin: 2px 0 0;
            color: #536a82;
            font-size: 13px;
            font-weight: 600;
        }

        .invoice-title {
            margin: 0;
            color: #19344f;
            font-size: 28px;
            line-height: 1.15;
            font-weight: 800;
        }

        .invoice-subtitle {
            margin: 2px 0 9px;
            color: #7a8797;
            font-size: 12px;
        }

        .invoice-number {
            width: 154px;
            margin: 0 0 10px auto;
            padding: 5px 12px;
            border: 1px solid #d4dde6;
            background: #f9fbfd;
            border-radius: 6px;
            color: #2e4156;
            text-align: center;
            font-size: 12px;
            font-weight: 800;
        }

        .meta-row {
            width: 100%;
            margin-bottom: 4px;
            color: #60758c;
            font-size: 11px;
            white-space: nowrap;
        }

        .meta-row .meta-label {
            color: #2c4763;
            font-weight: 700;
        }

        .meta-icon {
            display: inline-block;
            width: 18px;
            height: 18px;
            margin-left: 6px;
            border: 1px solid #cbd6e0;
            border-radius: 50%;
            color: #687d91;
            line-height: 16px;
            text-align: center;
            font-size: 9px;
            vertical-align: middle;
        }

        /* ===== Information cards ===== */
        .info-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 10px 0;
            margin: 0 -10px 14px;
            table-layout: fixed;
            direction: ltr;
        }

        .info-table td {
            width: 50%;
            min-height: 138px;
            padding: 14px 16px;
            vertical-align: top;
            border: 1px solid #d7e2e8;
            border-radius: 9px;
            direction: rtl;
        }

        .customer-card {
            background: #f3fbfa;
            border-color: #c9e9e5 !important;
        }

        .invoice-card {
            background: #ffffff;
        }

        .card-head {
            margin: 0 0 10px;
            color: #18324d;
            font-size: 16px;
            font-weight: 800;
        }

        .card-head .card-icon {
            display: inline-block;
            float: left;
            width: 43px;
            height: 43px;
            margin: -1px 0 0 7px;
            border-radius: 50%;
            background: #d7f6f1;
            color: #0b907f;
            text-align: center;
            line-height: 43px;
            font-size: 20px;
            font-weight: 700;
        }

        .card-name {
            margin: 0 0 3px;
            color: #173450;
            font-size: 17px;
            font-weight: 800;
        }

        .card-line {
            margin: 5px 0;
            color: #536c84;
            font-size: 11px;
        }

        /* ===== Items ===== */
        .items {
            width: 100%;
            direction: ltr;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 2px;
            border: 1px solid #d8e3e8;
            border-radius: 8px;
            overflow: hidden;
            table-layout: fixed;
        }

        .items thead th {
            padding: 9px 6px;
            background: #0a7b73;
            color: #ffffff;
            border-right: 1px solid rgba(255,255,255,0.22);
            font-size: 11px;
            font-weight: 800;
            text-align: center;
        }

        .items thead th:first-child { border-right: 0; }

        .items tbody td {
            padding: 9px 6px;
            background: #ffffff;
            color: #243a52;
            border-top: 1px solid #d8e3e8;
            border-right: 1px solid #d8e3e8;
            font-size: 10.5px;
            text-align: center;
            vertical-align: middle;
            word-break: break-word;
        }

        .items tbody td:first-child { border-right: 0; }
        .items tbody tr { page-break-inside: avoid; }

        .items th:nth-child(1), .items td:nth-child(1) { width: 12%; }
        .items th:nth-child(2), .items td:nth-child(2) { width: 12%; }
        .items th:nth-child(3), .items td:nth-child(3) { width: 10%; }
        .items th:nth-child(4), .items td:nth-child(4) { width: 13%; }
        .items th:nth-child(5), .items td:nth-child(5) { width: 10%; }
        .items th:nth-child(6), .items td:nth-child(6) { width: 22%; }
        .items th:nth-child(7), .items td:nth-child(7) { width: 15%; }
        .items th:nth-child(8), .items td:nth-child(8) { width: 6%; }

        .item-name { font-weight: 700; }
        .item-desc { margin-top: 2px; color: #8190a1; font-size: 9.5px; }

        /* ===== Totals + thank you ===== */
        .summary-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 10px 0;
            margin: 14px -10px 0;
            table-layout: fixed;
            direction: ltr;
        }

        .summary-table > tbody > tr > td {
            vertical-align: top;
            border: 0;
            padding: 0;
            direction: rtl;
        }

        .totals-cell { width: 55%; }
        .thanks-cell { width: 45%; }

        .totals {
            width: 100%;
            direction: rtl;
            border-collapse: separate;
            border-spacing: 0;
            border: 1px solid #d8e3e8;
            border-radius: 7px;
            overflow: hidden;
        }

        .totals td {
            padding: 6px 9px;
            border-top: 1px solid #dce5eb;
            font-size: 11px;
        }

        .totals tr:first-child td { border-top: 0; }
        .totals .label {
            width: 66%;
            background: #fbfcfd;
            color: #314b66;
            font-weight: 700;
        }

        .totals .value {
            color: #243d55;
            text-align: left;
            font-weight: 700;
            white-space: nowrap;
        }

        .totals .grand .label,
        .totals .grand .value {
            background: #e8faf7;
            color: #079781;
            font-weight: 800;
            font-size: 15px;
        }

        .thanks-box {
            min-height: 227px;
            border: 1px solid #c8ebe6;
            border-radius: 9px;
            background: #f1fbf9;
            text-align: center;
            padding: 18px 12px 14px;
        }

        .thanks-icon {
            width: 54px;
            height: 54px;
            margin: 5px auto 10px;
            border-radius: 50%;
            background: #d4f7f1;
            color: #0a9a84;
            line-height: 54px;
            font-size: 27px;
            font-weight: 800;
        }

        .thanks-title {
            margin: 0;
            color: #087c73;
            font-size: 24px;
            font-weight: 800;
        }

        .thanks-text {
            margin: 6px 0 0;
            color: #4f687f;
            font-size: 12px;
        }

        /* ===== Bottom validation / notes ===== */
        .bottom-table {
            width: 100%;
            margin-top: 22px;
            border-collapse: collapse;
            direction: ltr;
            table-layout: fixed;
        }

        .bottom-table td {
            border: 0;
            padding: 0;
            vertical-align: top;
            direction: rtl;
        }

        .qr-cell { width: 50%; padding-right: 10px !important; }
        .notes-cell { width: 50%; padding-left: 8px !important; }

        .qr-wrap {
            text-align: left;
            min-height: 105px;
        }

        .qr-image {
            width: 92px;
            height: 92px;
            object-fit: contain;
            border: 1px solid #d7e0e7;
            padding: 3px;
            background: #fff;
        }

        .qr-placeholder {
            display: inline-block;
            width: 92px;
            height: 92px;
            border: 2px dashed #cad5df;
            color: #8492a2;
            text-align: center;
            line-height: 90px;
            font-size: 10px;
        }

        .qr-copy {
            display: inline-block;
            margin-left: 12px;
            vertical-align: top;
            text-align: left;
            direction: rtl;
        }

        .qr-title {
            margin: 4px 0 4px;
            color: #163450;
            font-size: 13px;
            font-weight: 800;
        }

        .qr-text {
            margin: 0;
            color: #75859a;
            font-size: 10px;
            line-height: 1.5;
        }

        .notes-box {
            min-height: 100px;
            padding: 4px 0 0 0;
            text-align: right;
        }

        .notes-title {
            margin: 0 0 7px;
            color: #19344f;
            font-size: 15px;
            font-weight: 800;
        }

        .notes-content {
            margin: 0;
            color: #728398;
            font-size: 11px;
        }

        /* ===== Footer ===== */
        .footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            border-top: 1px solid #dce4ea;
            padding: 6px 0 0;
            color: #687c91;
            font-size: 9.5px;
        }

        .footer-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            direction: ltr;
        }

        .footer-table td {
            border: 0;
            padding: 0;
            vertical-align: middle;
            direction: rtl;
        }

        .footer-left { width: 62%; text-align: center; }
        .footer-right { width: 38%; text-align: right; }

        .footer-logo {
            max-width: 125px;
            max-height: 40px;
            margin-top: -2px;
        }

        .contact {
            color: #426279;
            white-space: nowrap;
        }

        .contact span { margin: 0 9px; }

        .tiny-note {
            margin-top: 3px;
            color: #8a97a7;
            font-size: 8.5px;
        }
    </style>
</head>
<body>
<div class="document">
    <div class="header">
        <table class="header-table">
            <tr>
                <td class="brand-cell">
                    @if(!empty($logoDataUri))
                        <img src="{{ $logoDataUri }}" alt="Company Logo" class="logo">
                    @endif
                    <div class="brand-name">{{ $companyName }}</div>
                    @if($companyNameEn && $companyNameEn !== $companyName)
                        <div class="brand-tagline">{{ $companyNameEn }}</div>
                    @endif
                    <div class="brand-tagline-ar">أسهل لإدارة أعمالك</div>
                </td>
                <td class="invoice-cell">
                    <h1 class="invoice-title">{{ $invoice->type === 'sales' ? 'فاتورة مبيعات' : 'فاتورة شراء' }}</h1>
                    <div class="invoice-subtitle">Sales Invoice</div>
                    <div class="invoice-number">#{{ $invoice->invoice_number }}</div>

                    <div class="meta-row">
                        <span class="meta-icon">▣</span>
                        <span class="meta-label">تاريخ الإصدار:</span>
                        {{ $invoice->issue_date?->format('Y-m-d') ?? '-' }}
                    </div>
                    <div class="meta-row">
                        <span class="meta-icon">◷</span>
                        <span class="meta-label">وقت الإنشاء:</span>
                        {{ optional($invoice->created_at ?? null)->timezone(config('app.timezone'))->format('Y-m-d h:i A') ?? ($invoice->issued_at ? $invoice->issued_at->timezone(config('app.timezone'))->format('Y-m-d h:i A') : '-') }}
                    </div>
                    <div class="meta-row">
                        <span class="meta-icon">▣</span>
                        <span class="meta-label">تاريخ الاستحقاق:</span>
                        {{ $invoice->due_date?->format('Y-m-d') ?? '-' }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table class="info-table">
        <tr>
            <td class="customer-card">
                <p class="card-head"><span class="card-icon">◯</span>{{ $invoice->type === 'sales' ? 'بيانات العميل' : 'بيانات المورد' }}</p>
                <p class="card-name">{{ $recipientName }}</p>
                <div class="card-line">{{ $recipientAddress ?: '—' }}</div>
                <div class="card-line">الرقم الضريبي: {{ $recipientVat ?: '—' }}</div>
                <div class="card-line">رقم الجوال: {{ $recipientPhone ?: '—' }}</div>
            </td>
            <td class="invoice-card">
                <p class="card-head"><span class="card-icon">□</span>معلومات الفاتورة</p>
                <div class="card-line"><strong>العملة:</strong> {{ $invoice->currency }}</div>
                <div class="card-line"><strong>تصنيف المستند:</strong> {{ $taxDocumentLabels[$invoice->tax_document_subtype ?? 'standard'] ?? ($invoice->tax_document_subtype ?: 'قياسية') }}</div>
                <div class="card-line"><strong>تسعير الضريبة:</strong> {{ $taxPriceModeLabels[$invoice->tax_price_mode ?? 'exclusive'] ?? 'غير شامل الضريبة' }}</div>
                <div class="card-line"><strong>شروط السداد:</strong> {{ $invoice->payment_terms ?: ($company['default_payment_terms'] ?? 'عند الاستلام') }}</div>
                <div class="card-line"><strong>الفوترة الإلكترونية ZATCA:</strong> {{ !empty($qrCodeDataUri) ? 'مهيأة' : 'غير مهيأة' }}</div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>الإجمالي</th>
                <th>الضريبة</th>
                <th>الخصم</th>
                <th>سعر الوحدة</th>
                <th>الكمية</th>
                <th>الوصف</th>
                <th>الصنف</th>
                <th>#</th>
            </tr>
        </thead>
        <tbody>
        @foreach($invoice->items as $index => $item)
            @php
                $lineTitle = $item->lineTitle();
                $lineDescription = trim((string) $item->description);
            @endphp
            <tr>
                <td>{{ number_format((float) $item->total, 2) }} {{ $invoice->currency }}</td>
                <td>{{ number_format((float) $item->tax_amount, 2) }}</td>
                <td>{{ number_format((float) $item->discount, 2) }}</td>
                <td>{{ number_format((float) $item->unit_price, 2) }} {{ $invoice->currency }}</td>
                <td>{{ number_format((float) $item->quantity, 3) }}</td>
                <td>{{ $lineDescription !== '' ? $lineDescription : '—' }}</td>
                <td class="item-name">{{ $lineTitle !== '' ? $lineTitle : '-' }}</td>
                <td>{{ $index + 1 }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="summary-table">
        <tr>
            <td class="totals-cell">
                <table class="totals">
                    <tr>
                        <td class="label">الإجمالي قبل الخصم</td>
                        <td class="value">{{ number_format((float) $invoice->subtotal, 2) }} {{ $invoice->currency }}</td>
                    </tr>
                    <tr>
                        <td class="label">إجمالي الخصم</td>
                        <td class="value">{{ number_format((float) $invoice->discount, 2) }} {{ $invoice->currency }}</td>
                    </tr>
                    <tr>
                        <td class="label">المبلغ الخاضع للضريبة</td>
                        <td class="value">{{ number_format((float) $invoice->taxable_amount, 2) }} {{ $invoice->currency }}</td>
                    </tr>
                    <tr>
                        <td class="label">مبلغ الضريبة ({{ number_format((float) (($invoice->tax_amount / max((float) ($invoice->taxable_amount ?: 1), 1)) * 100), 0) }}%)</td>
                        <td class="value">{{ number_format((float) $invoice->tax_amount, 2) }} {{ $invoice->currency }}</td>
                    </tr>
                    <tr class="grand">
                        <td class="label">الإجمالي الكلي</td>
                        <td class="value">{{ number_format((float) $invoice->total, 2) }} {{ $invoice->currency }}</td>
                    </tr>
                    @foreach($taxBreakdown as $bucket)
                    <tr>
                        <td class="label">{{ $taxProfileLabels[$bucket['tax_profile_type'] ?? ''] ?? ($bucket['tax_profile_type'] ?? 'ضريبة') }} @ {{ number_format((float) ($bucket['tax_rate'] ?? 0), 2) }}%</td>
                        <td class="value">{{ number_format((float) ($bucket['tax_amount'] ?? 0), 2) }} {{ $invoice->currency }}</td>
                    </tr>
                    @endforeach
                    @if((float) ($invoice->amount_credited ?? 0) > 0)
                    <tr>
                        <td class="label">إشعارات دائن</td>
                        <td class="value">{{ number_format((float) $invoice->amount_credited, 2) }} {{ $invoice->currency }}</td>
                    </tr>
                    @endif
                    @if((float) ($invoice->amount_debited ?? 0) > 0)
                    <tr>
                        <td class="label">إشعارات مدين</td>
                        <td class="value">{{ number_format((float) $invoice->amount_debited, 2) }} {{ $invoice->currency }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td class="label">المدفوع</td>
                        <td class="value">{{ number_format((float) $invoice->amount_paid, 2) }} {{ $invoice->currency }}</td>
                    </tr>
                    <tr>
                        <td class="label">المتبقي</td>
                        <td class="value">{{ number_format((float) $invoice->amount_due, 2) }} {{ $invoice->currency }}</td>
                    </tr>
                </table>
            </td>
            <td class="thanks-cell">
                <div class="thanks-box">
                    <div class="thanks-icon">✓</div>
                    <div class="thanks-title">شكرًا لكم</div>
                    <div class="thanks-text">نقدر ثقتكم بنا ونتطلع لخدمتكم دائمًا</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="bottom-table">
        <tr>
            <td class="qr-cell">
                <div class="qr-wrap">
                    @if(!empty($qrCodeDataUri))
                        <img src="{{ $qrCodeDataUri }}" alt="QR Code" class="qr-image">
                    @else
                        <span class="qr-placeholder">QR</span>
                    @endif
                    <div class="qr-copy">
                        <div class="qr-title">تحقق من الفاتورة</div>
                        <p class="qr-text">يمكنك التحقق من صحة هذه الفاتورة<br>من خلال مسح رمز QR</p>
                    </div>
                </div>
            </td>
            <td class="notes-cell">
                <div class="notes-box">
                    <div class="notes-title">ملاحظات ⓘ</div>
                    <p class="notes-content">{{ $invoice->notes ?: 'لا توجد ملاحظات إضافية.' }}</p>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        <table class="footer-table">
            <tr>
                <td class="footer-left">
                    <div class="contact">
                        <span>☎ {{ $companyPhone ?: '9200 12345' }}</span>
                        <span>✉ {{ $companyEmail ?: 'info@fatora.sa' }}</span>
                        <span>◉ {{ $companyWebsite ?: 'www.fatora.sa' }}</span>
                    </div>
                    <div class="tiny-note">رقم المستند يعتبر دليلاً صادرًا من النظام الإلكتروني.</div>
                </td>
                <td class="footer-right">
                    @if(!empty($logoDataUri))
                        <img src="{{ $logoDataUri }}" alt="Company Logo" class="footer-logo">
                    @else
                        <div class="brand-name" style="font-size:24px;">{{ $companyName }}</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>
</div>

<script type="text/php">
    if (isset($pdf)) {
        $font = $fontMetrics->get_font('DejaVu Sans', 'normal');
        $pdf->page_text(500, 815, "{PAGE_NUM}/{PAGE_COUNT}", $font, 8.5, [0.39, 0.45, 0.55]);
    }
</script>
</body>
</html>
