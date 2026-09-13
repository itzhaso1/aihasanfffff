@php
    $customer = $statement['customer'];
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>كشف حساب {{ $customer->name }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #0f172a; direction: rtl; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #e2e8f0; padding: 6px; text-align: right; }
        th { background: #f8fafc; }
        h1 { margin: 0; color: #06C2A4; }
    </style>
</head>
<body>
    <h1>كشف حساب العميل</h1>
    <p>{{ $customer->name }} | من {{ $statement['from'] }} إلى {{ $statement['to'] }}</p>
    <p>الرصيد الافتتاحي: {{ number_format((float) $statement['opening_balance'], 2) }} | الرصيد الختامي: {{ number_format((float) $statement['closing_balance'], 2) }}</p>
    <table>
        <thead>
            <tr>
                <th>التاريخ</th>
                <th>النوع</th>
                <th>المرجع</th>
                <th>الوصف</th>
                <th>مدين</th>
                <th>دائن</th>
                <th>الرصيد</th>
            </tr>
        </thead>
        <tbody>
            @foreach($statement['lines'] as $line)
                <tr>
                    <td>{{ $line['date'] }}</td>
                    <td>{{ $line['kind'] }}</td>
                    <td>{{ $line['reference'] }}</td>
                    <td>{{ $line['description'] }}</td>
                    <td>{{ number_format((float) $line['debit'], 2) }}</td>
                    <td>{{ number_format((float) $line['credit'], 2) }}</td>
                    <td>{{ number_format((float) $line['balance'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
