<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?: 'رسالة' }}</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7fb;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="620" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb;">
                    <tr>
                        <td style="background:{{ $brandColor }};padding:18px 24px;">
                            <table role="presentation" width="100%">
                                <tr>
                                    <td style="vertical-align:middle;">
                                        @if($logoUrl)
                                            <img src="{{ $logoUrl }}" alt="logo" style="height:42px;max-width:160px;object-fit:contain;">
                                        @else
                                            <span style="display:inline-block;font-size:20px;font-weight:700;color:#ffffff;">{{ $accountName }}</span>
                                        @endif
                                    </td>
                                    <td style="text-align:left;color:#ffffff;font-size:13px;">{{ $accountName }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            @if($subject)
                                <h2 style="margin:0 0 16px;font-size:20px;line-height:1.5;color:#111827;">{{ $subject }}</h2>
                            @endif
                            <div style="font-size:15px;line-height:1.9;color:#374151;">
                                {!! nl2br(e((string) $body)) !!}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:14px 24px;border-top:1px solid #f1f5f9;color:#6b7280;font-size:12px;">
                            مع التحية، {{ $companyName ?: $accountName }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
