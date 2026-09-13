<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    @php
        $headline = $data['headline'] ?? $subject;
        $intro = $data['intro'] ?? null;
        $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
        $actionText = $data['action_text'] ?? null;
        $actionUrl = $data['action_url'] ?? null;
        $footer = $data['footer'] ?? null;
        $brandColor = $data['brand_color'] ?? '#0f172a';
        $brandName = $data['brand_name'] ?? config('app.name', 'HASEM');
        $logoUrl = $data['logo_url'] ?? null;
    @endphp
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7fb;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="620" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb;">
                    <tr>
                        <td style="background:{{ $brandColor }};padding:18px 24px;">
                            <table role="presentation" width="100%">
                                <tr>
                                    <td>
                                        @if($logoUrl)
                                            <img src="{{ $logoUrl }}" alt="logo" style="height:40px;max-width:150px;object-fit:contain;">
                                        @else
                                            <span style="font-size:20px;font-weight:700;color:#ffffff;">{{ $brandName }}</span>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            <h2 style="margin:0 0 12px;font-size:20px;line-height:1.5;color:#111827;">{{ $headline }}</h2>
                            @if($intro)
                                <p style="margin:0 0 12px;font-size:15px;line-height:1.9;color:#374151;">{{ $intro }}</p>
                            @endif
                            @foreach($lines as $line)
                                <p style="margin:0 0 10px;font-size:15px;line-height:1.9;color:#374151;">{{ $line }}</p>
                            @endforeach
                            @if(!empty($data['body']))
                                <div style="font-size:15px;line-height:1.9;color:#374151;">{!! nl2br(e((string) $data['body'])) !!}</div>
                            @endif
                            @if($actionText && $actionUrl)
                                <div style="margin-top:18px;">
                                    <a href="{{ $actionUrl }}" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:8px;font-size:14px;font-weight:700;">{{ $actionText }}</a>
                                </div>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:14px 24px;border-top:1px solid #f1f5f9;color:#6b7280;font-size:12px;">
                            {{ $footer ?: 'هذه رسالة آلية من النظام.' }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
