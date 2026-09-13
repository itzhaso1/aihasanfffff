@php
    $company = is_array($companySnapshot ?? null) ? $companySnapshot : [];
    $recipient = is_array($recipientSnapshot ?? null) ? $recipientSnapshot : [];
    $theme = is_array($pdfSnapshot ?? null) ? $pdfSnapshot : [];
    $useLiveFallbacks = ! ($snapshotsAuthoritative ?? false);
    $primaryColor = $theme['primary_color'] ?? ($useLiveFallbacks ? ($setting?->invoice_primary_color ?: '#06C2A4') : '#06C2A4');
    $isCredit = $note->isCredit();
    $title = $isCredit ? 'إشعار دائن' : 'إشعار مدين';
    $companyName = $company['company_name_ar'] ?? $company['company_name'] ?? ($useLiveFallbacks ? ($setting?->company_name_ar ?? $setting?->company_name) : null) ?? $title;
    $companyVat = $company['vat_number'] ?? ($useLiveFallbacks ? $setting?->vat_number : null);
    $companyCr = $company['commercial_registration'] ?? ($useLiveFallbacks ? $setting?->commercial_registration : null);
    $companyAddress = trim(implode(' - ', array_filter([
        $company['address_line'] ?? null,
        $company['street'] ?? null,
        $company['city'] ?? null,
        $company['country_code'] ?? null,
    ])));
    $recipientName = $recipient['name'] ?? $note->customer?->name ?? $invoice?->customer_name ?? '—';
    $statusLabels = ['draft' => 'مسودة', 'issued' => 'معتمد', 'cancelled' => 'ملغى'];
    $taxProfileLabels = [
        'standard' => 'قياسية',
        'zero_rated' => 'صفرية',
        'exempt' => 'معفاة',
        'out_of_scope' => 'خارج النطاق',
    ];
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }} {{ $note->note_number }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #0f172a; font-size: 12px; direction: rtl; }
        .title { color: {{ $primaryColor }}; font-size: 22px; margin: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; text-align: right; }
        .muted { color: #64748b; font-size: 11px; }
        .totals td { font-weight: 700; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td>
                @if(!empty($logoDataUri))
                    <img src="{{ $logoDataUri }}" style="max-height:56px;max-width:120px">
                @endif
                <h1 class="title">{{ $title }}</h1>
                <p class="muted">{{ $companyName }}</p>
                @if($companyVat)<p class="muted">الرقم الضريبي: {{ $companyVat }}</p>@endif
                @if($companyCr)<p class="muted">السجل التجاري: {{ $companyCr }}</p>@endif
                @if($companyAddress)<p class="muted">{{ $companyAddress }}</p>@endif
            </td>
            <td style="text-align:left">
                <p><strong>{{ $note->note_number }}</strong></p>
                <p>{{ $statusLabels[$note->status] ?? $note->status }}</p>
                <p class="muted">التاريخ: {{ optional($note->issue_date)->format('Y-m-d') }}</p>
                <p class="muted">مرجع الفاتورة: {{ $invoice?->invoice_number ?: '—' }}</p>
            </td>
        </tr>
    </table>

    <p>إلى: {{ $recipientName }}</p>
    @if($note->reason)<p class="muted">السبب: {{ $note->reason }}</p>@endif

    <table>
        <thead>
            <tr>
                <th>الوصف</th>
                <th>الكمية</th>
                <th>السعر</th>
                <th>الضريبة</th>
                <th>الإجمالي</th>
            </tr>
        </thead>
        <tbody>
            @foreach($note->items as $item)
                <tr>
                    <td>{{ $item->product_name ?: $item->description }}</td>
                    <td>{{ number_format((float) $item->quantity, 3) }}</td>
                    <td>{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td>{{ number_format((float) $item->tax_amount, 2) }} ({{ $taxProfileLabels[$item->tax_profile_type] ?? $item->tax_profile_type }})</td>
                    <td>{{ number_format((float) $item->total, 2) }}</td>
                </tr>
            @endforeach
            <tr class="totals"><td colspan="4">الخاضع للضريبة</td><td>{{ number_format((float) $note->taxable_amount, 2) }}</td></tr>
            <tr class="totals"><td colspan="4">الضريبة</td><td>{{ number_format((float) $note->tax_amount, 2) }}</td></tr>
            <tr class="totals"><td colspan="4">الإجمالي</td><td>{{ number_format((float) $note->total, 2) }} {{ $note->currency }}</td></tr>
        </tbody>
    </table>
    @if($note->notes)<p class="muted">{{ $note->notes }}</p>@endif
</body>
</html>
