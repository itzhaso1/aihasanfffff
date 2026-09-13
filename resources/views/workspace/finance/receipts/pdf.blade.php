@php
    $company = is_array($companySnapshot ?? null) ? $companySnapshot : [];
    $recipient = is_array($recipientSnapshot ?? null) ? $recipientSnapshot : [];
    $theme = is_array($pdfSnapshot ?? null) ? $pdfSnapshot : [];
    $useLiveFallbacks = ! ($snapshotsAuthoritative ?? false);
    $primaryColor = $theme['primary_color'] ?? ($useLiveFallbacks ? ($setting?->invoice_primary_color ?: '#06C2A4') : '#06C2A4');
    $companyName = $company['company_name_ar'] ?? $company['company_name'] ?? ($useLiveFallbacks ? ($setting?->company_name_ar ?? $setting?->company_name) : null) ?? 'إيصال';
    $companyVat = $company['vat_number'] ?? ($useLiveFallbacks ? $setting?->vat_number : null);
    $companyCr = $company['commercial_registration'] ?? ($useLiveFallbacks ? $setting?->commercial_registration : null);
    $companyAddress = trim(implode(' - ', array_filter([
        $company['address_line'] ?? null,
        $company['street'] ?? null,
        $company['city'] ?? null,
        $company['country_code'] ?? null,
    ])));
    $recipientName = $recipient['name']
        ?? $receipt->customer?->name
        ?? $invoice?->customer_name
        ?? '—';
    $amount = number_format((float) ($payment?->amount ?? $receipt->amount), 2);
    $methodLabels = ['cash' => 'نقد', 'bank_transfer' => 'تحويل بنكي', 'card' => 'بطاقة', 'other' => 'أخرى'];
    $method = $payment?->method ?: $receipt->method;
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إيصال {{ $receipt->receipt_number }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #0f172a; font-size: 12px; direction: rtl; }
        .title { color: {{ $primaryColor }}; font-size: 22px; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; text-align: right; }
        .muted { color: #64748b; font-size: 11px; }
        .badge { display: inline-block; border: 1px solid #d1d5db; border-radius: 9999px; padding: 3px 8px; font-size: 11px; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td>
                @if(!empty($logoDataUri))
                    <img src="{{ $logoDataUri }}" style="max-height:56px;max-width:120px">
                @endif
                <h1 class="title">إيصال قبض</h1>
                <p class="muted">{{ $companyName }}</p>
                @if($companyVat)<p class="muted">الرقم الضريبي: {{ $companyVat }}</p>@endif
                @if($companyCr)<p class="muted">السجل التجاري: {{ $companyCr }}</p>@endif
                @if($companyAddress)<p class="muted">{{ $companyAddress }}</p>@endif
            </td>
            <td style="text-align:left">
                <p><strong>{{ $receipt->receipt_number }}</strong></p>
                <p class="badge">{{ $receipt->isVoided() ? 'ملغى' : 'معتمد' }}</p>
                <p class="muted">تاريخ الدفع: {{ optional($payment?->payment_date ?? $receipt->payment_date)->format('Y-m-d') }}</p>
            </td>
        </tr>
    </table>

    <h3>استلمنا من</h3>
    <p>{{ $recipientName }}</p>
    @if(!empty($recipient['vat_number']))<p class="muted">الرقم الضريبي: {{ $recipient['vat_number'] }}</p>@endif

    <table>
        <tr><th>الفاتورة</th><td>{{ $invoice?->invoice_number ?: '—' }}</td></tr>
        <tr><th>المبلغ</th><td>{{ $amount }} {{ $receipt->currency }}</td></tr>
        <tr><th>طريقة الدفع</th><td>{{ $methodLabels[$method] ?? $method }}</td></tr>
        <tr><th>المرجع</th><td>{{ $payment?->reference ?: ($receipt->reference ?: '—') }}</td></tr>
    </table>
    <p class="muted">هذا الإيصال مرتبط بالدفعة المالية المسجّلة ولا ينشئ دفعة جديدة.</p>
</body>
</html>
