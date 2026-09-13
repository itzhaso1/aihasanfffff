@php
    $data = array_merge($data, [
        'brand_color' => $data['brand_color'] ?? '#06C2A4',
        'brand_name' => $data['account_name'] ?? ($data['company_name'] ?? config('app.name', 'HASEM')),
    ]);
@endphp

@include('emails.templates._base', ['subject' => $subject, 'data' => $data])
