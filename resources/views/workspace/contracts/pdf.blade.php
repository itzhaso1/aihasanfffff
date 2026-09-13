@php
    $company = is_array($companySnapshot ?? null) ? $companySnapshot : [];
    $customer = is_array($customerSnapshot ?? null) ? $customerSnapshot : [];
    $theme = is_array($pdfSnapshot ?? null) ? $pdfSnapshot : [];

    $primaryColor = $theme['primary_color'] ?? ($setting?->invoice_primary_color ?: '#06C2A4');
    $currency = $contract->currency ?: ($theme['currency'] ?? 'SAR');
    $footerText = $theme['footer_text'] ?? ($setting?->invoice_footer_text ?: 'تم إنشاء هذا العقد عبر نظام HASem.');

    $companyName = $company['company_name_ar'] ?: ($company['company_name'] ?? (config('app.name', 'HASem')));
    $customerName = $customer['name'] ?? ($contract->customer?->name ?: '—');
    $statusLabel = [
        'draft' => 'مسودة',
        'open' => 'مفتوح',
        'closed' => 'مغلق',
        'cancelled' => 'ملغي',
    ][$contract->status] ?? $contract->status;
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 22mm 14mm 25mm 14mm;
        }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            color: #0f172a;
            font-size: 12px;
            line-height: 1.5;
            direction: rtl;
            unicode-bidi: embed;
        }
        .header {
            border-bottom: 2px solid {{ $primaryColor }};
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .header-table, .details-table, .items, .totals-table, .footer-table {
            width: 100%;
            border-collapse: collapse;
        }
        .logo {
            max-height: 58px;
            max-width: 120px;
            margin-bottom: 6px;
        }
        .title {
            margin: 0;
            font-size: 20px;
            color: {{ $primaryColor }};
        }
        .muted {
            color: #64748b;
            font-size: 11px;
        }
        .badge {
            display: inline-block;
            border: 1px solid {{ $primaryColor }};
            color: {{ $primaryColor }};
            border-radius: 999px;
            padding: 3px 8px;
            font-size: 11px;
            font-weight: 700;
        }
        .details-card {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 8px;
        }
        .items {
            margin-top: 12px;
            border: 1px solid #e2e8f0;
        }
        .items thead th {
            background: #f8fafc;
            color: #334155;
            font-size: 11px;
            border-bottom: 1px solid #e2e8f0;
            padding: 8px;
            text-align: right;
        }
        .items tbody td {
            border-bottom: 1px solid #f1f5f9;
            padding: 8px;
            text-align: right;
            vertical-align: top;
            direction: rtl;
            unicode-bidi: embed;
            word-break: break-word;
        }
        .items tbody tr:last-child td {
            border-bottom: none;
        }
        .totals-wrap {
            margin-top: 10px;
            page-break-inside: avoid;
        }
        .totals-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #f1f5f9;
        }
        .totals-table tr:last-child td {
            border-bottom: none;
            font-weight: 700;
            font-size: 13px;
            color: {{ $primaryColor }};
        }
        .section {
            margin-top: 12px;
            page-break-inside: avoid;
        }
        .section-title {
            margin: 0 0 6px 0;
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
        }
        .content-block {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 8px;
            min-height: 58px;
            white-space: pre-line;
        }
        .footer {
            position: fixed;
            bottom: -10mm;
            left: 0;
            right: 0;
            border-top: 1px solid #e2e8f0;
            padding-top: 5px;
            font-size: 10px;
            color: #64748b;
        }
        .footer td {
            vertical-align: top;
        }
    </style>
</head>
<body>
    <header class="header">
        <table class="header-table">
            <tr>
                <td style="width: 60%; text-align: right;">
                    @if(!empty($logoDataUri))
                        <img src="{{ $logoDataUri }}" alt="Logo" class="logo">
                    @endif
                    <h1 class="title">{{ $companyName }}</h1>
                    <div class="muted">
                        @if(!empty($company['vat_number'])) رقم ضريبي: {{ $company['vat_number'] }}<br>@endif
                        @if(!empty($company['address_line'])) {{ $company['address_line'] }} @endif
                        @if(!empty($company['city'])) {{ $company['city'] }} @endif
                        @if(!empty($company['country_code'])) - {{ $company['country_code'] }} @endif
                        <br>
                        @if(!empty($company['phone'])) {{ $company['phone'] }} @endif
                        @if(!empty($company['email'])) | {{ $company['email'] }} @endif
                    </div>
                </td>
                <td style="width: 40%; text-align: left;">
                    <h2 style="margin: 0; font-size: 18px; color: #0f172a;">عقد</h2>
                    <p class="muted" style="margin: 5px 0 8px 0;">{{ $contract->contract_number }}</p>
                    <span class="badge">{{ $statusLabel }}</span>
                </td>
            </tr>
        </table>
    </header>

    <table class="details-table">
        <tr>
            <td style="width: 50%; padding-inline-end: 6px;">
                <div class="details-card">
                    <p style="margin: 0 0 4px 0; font-weight: 700;">بيانات العميل</p>
                    <p style="margin: 0;">{{ $customerName }}</p>
                    <p class="muted" style="margin: 4px 0 0 0;">
                        @if(!empty($customer['vat_number'])) رقم ضريبي: {{ $customer['vat_number'] }}<br>@endif
                        @if(!empty($customer['address'])) {{ $customer['address'] }}<br>@endif
                        @if(!empty($customer['phone'])) {{ $customer['phone'] }} @endif
                        @if(!empty($customer['email'])) | {{ $customer['email'] }} @endif
                    </p>
                </div>
            </td>
            <td style="width: 50%; padding-inline-start: 6px;">
                <div class="details-card">
                    <p style="margin: 0 0 4px 0; font-weight: 700;">تفاصيل العقد</p>
                    <p style="margin: 0;">العنوان: {{ $contract->title }}</p>
                    <p class="muted" style="margin: 4px 0 0 0;">
                        البداية: {{ optional($contract->start_date)->format('Y-m-d') ?: '—' }}<br>
                        النهاية: {{ optional($contract->end_date)->format('Y-m-d') ?: '—' }}<br>
                        القيمة: {{ number_format((float) $contract->value, 2) }} {{ $currency }}
                    </p>
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 6%;">#</th>
                <th style="width: 26%;">البند</th>
                <th style="width: 34%;">الوصف</th>
                <th style="width: 12%;">الكمية</th>
                <th style="width: 12%;">سعر الوحدة</th>
                <th style="width: 10%;">الإجمالي</th>
            </tr>
        </thead>
        <tbody>
            @forelse($contract->items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item->title }}</td>
                    <td>{{ $item->description ?: '—' }}</td>
                    <td>{{ number_format((float) $item->quantity, 3) }}</td>
                    <td>{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td>{{ number_format((float) $item->total, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align: center;">لا توجد بنود.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="totals-wrap">
        <table class="totals-table">
            <tr>
                <td style="text-align: right;">إجمالي قيمة العقد</td>
                <td style="text-align: left;">{{ number_format((float) $contract->value, 2) }} {{ $currency }}</td>
            </tr>
            <tr>
                <td style="text-align: right;">القيمة النهائية</td>
                <td style="text-align: left;">{{ number_format((float) $contract->value, 2) }} {{ $currency }}</td>
            </tr>
        </table>
    </div>

    <section class="section">
        <h3 class="section-title">الشروط</h3>
        <div class="content-block">{{ $contract->terms ?: '—' }}</div>
    </section>

    <section class="section">
        <h3 class="section-title">ملاحظات</h3>
        <div class="content-block">{{ $contract->notes ?: '—' }}</div>
    </section>

    <footer class="footer">
        <table class="footer-table">
            <tr>
                <td style="text-align: right;">{!! nl2br(e($footerText)) !!}</td>
                <td style="width: 100px; text-align: left;">صفحة <span class="page-number"></span></td>
            </tr>
        </table>
    </footer>

    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->get_font('DejaVu Sans', 'normal');
            $pdf->page_text(520, 815, "{PAGE_NUM}/{PAGE_COUNT}", $font, 9, [0.39, 0.45, 0.55]);
        }
    </script>
</body>
</html>
