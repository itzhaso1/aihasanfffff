{{-- Construction Welcome Email Template --}}
{{-- Key: construction_welcome --}}
{{-- View: emails.library.construction.welcome --}}
{{-- Branding vars: primaryColor, secondaryColor, backgroundColor, textColor, logoUrl --}}

@php
    $primary = $primaryColor ?? '#0f766e';
    $secondary = $secondaryColor ?? '#14b8a6';
    $background = $backgroundColor ?? '#f4f7f9';
    $text = $textColor ?? '#1f3146';
    $company = $companyName ?? 'شركة المقاولات';
    $customer = $customerName ?? null;
    $headline = $subject ?? 'مرحباً بك معنا 👋';
    $buttonLabel = $ctaLabel ?? 'ابدأ الآن';
    $buttonUrl = $ctaUrl ?? '#';
@endphp

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $headline }}</title>
</head>
<body style="margin:0;padding:0;background:{{ $background }};font-family:Tahoma,Arial,Helvetica,sans-serif;color:{{ $text }};">

<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:{{ $background }};margin:0;padding:24px 0;">
    <tr>
        <td align="center" style="padding:0 12px;">

            <table role="presentation" width="680" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:680px;background:#ffffff;border-radius:18px;overflow:hidden;">

                {{-- Browser link --}}
                <tr>
                    <td align="center" style="padding:4px 0 18px;font-size:13px;line-height:1.8;color:#6b7a8f;background:{{ $background }};">
                        هل تواجه مشكلة في عرض هذه الرسالة؟
                        <a href="#" style="color:{{ $secondary }};font-weight:bold;text-decoration:underline;">اعرضها في المتصفح</a>
                    </td>
                </tr>

                {{-- Header --}}
                <tr>
                    <td style="background:{{ $primary }};padding:0;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td style="padding:28px 34px 24px;">
                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" dir="rtl">
                                        <tr>
                                            {{-- Logo / company --}}
                                            <td align="right" valign="middle" style="padding:0;">
                                                <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                                    <tr>
                                                        @if(!empty($logoUrl))
                                                            <td valign="middle" bgcolor="{{ $primary }}" style="padding-left:14px;background:{{ $primary }};">
                                                                <img src="{{ $logoUrl }}" alt="{{ $company }}" width="56" height="56" style="display:block;width:56px;height:56px;border:0;background:{{ $primary }};">
                                                            </td>
                                                        @endif
                                                        <td valign="middle" style="color:#ffffff;">
                                                            <div style="font-size:24px;line-height:1.25;font-weight:700;color:#ffffff;">
                                                                {{ $company }}
                                                            </div>
                                                            <div style="margin-top:4px;font-size:13px;line-height:1.7;color:#e7fffb;">
                                                                نبني بثقة، وننفذ بجودة
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>

                                            {{-- Slogan --}}
                                            <td align="left" valign="middle" style="padding-right:18px;">
                                                <div style="display:inline-block;border-right:1px solid rgba(255,255,255,.55);padding-right:18px;font-size:18px;line-height:1.7;color:#ffffff;">
                                                    نخطط معك ... وننجز معك
                                                </div>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>

                            {{-- Header curve --}}
                            <tr>
                                <td style="height:24px;font-size:0;line-height:0;background:{{ $primary }};">
                                    <div style="height:24px;background:#ffffff;border-radius:50% 50% 0 0 / 100% 100% 0 0;"></div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Main content --}}
                <tr>
                    <td style="padding:26px 40px 12px;background:#ffffff;text-align:right;" dir="rtl">

                        <div style="font-size:32px;line-height:1.5;font-weight:700;color:{{ $text }};">
                            {{ $headline }}
                        </div>

                        @if(!empty($customer))
                            <div style="margin-top:8px;font-size:19px;line-height:1.8;color:#53657b;">
                                أهلاً {{ $customer }}، يسعدنا انضمامك إلينا.
                            </div>
                        @else
                            <div style="margin-top:8px;font-size:19px;line-height:1.8;color:#53657b;">
                                يسعدنا انضمامك إلينا ونتطلع للعمل معك.
                            </div>
                        @endif

                        <div style="margin-top:20px;font-size:16px;line-height:2.1;color:{{ $text }};">
                            {!! nl2br(e((string) ($body ?? 'نلتزم بتقديم حلول إنشائية متكاملة، بجودة عالية، وتنفيذ احترافي يواكب أهداف مشاريعك من البداية وحتى التسليم.'))) !!}
                        </div>

                        {{-- CTA --}}
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:28px 0 8px;">
                            <tr>
                                <td align="center">
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                        <tr>
                                            <td align="center" style="background:{{ $secondary }};border-radius:14px;">
                                                <a href="{{ $buttonUrl }}" style="display:inline-block;min-width:220px;padding:16px 28px;color:#ffffff;text-decoration:none;font-size:18px;font-weight:700;">
                                                    {{ $buttonLabel }} <span style="font-size:20px;">←</span>
                                                </a>
                                            </td>
                                        </tr>
                                    </table>
                                    <div style="padding-top:10px;font-size:13px;line-height:1.8;color:#7b8999;">
                                        اضغط على الزر للوصول إلى حسابك أو متابعة مشروعك.
                                    </div>
                                </td>
                            </tr>
                        </table>

                    </td>
                </tr>

                {{-- Feature cards --}}
                <tr>
                    <td style="padding:12px 40px 18px;background:#ffffff;" dir="rtl">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f8fbfc;border-radius:16px;">
                            <tr>
                                <td align="center" width="33%" style="padding:22px 10px;">
                                    <div style="width:58px;height:58px;line-height:58px;border-radius:50%;background:{{ $secondary }};color:#ffffff;font-size:24px;margin:0 auto 10px;">✓</div>
                                    <div style="font-size:17px;line-height:1.6;font-weight:700;color:{{ $text }};">جودة في التنفيذ</div>
                                    <div style="font-size:12px;line-height:1.8;color:#718096;">معايير واضحة في كل مرحلة</div>
                                </td>

                                <td align="center" width="33%" style="padding:22px 10px;">
                                    <div style="width:58px;height:58px;line-height:58px;border-radius:50%;background:{{ $secondary }};color:#ffffff;font-size:24px;margin:0 auto 10px;">⏱</div>
                                    <div style="font-size:17px;line-height:1.6;font-weight:700;color:{{ $text }};">التزام بالمواعيد</div>
                                    <div style="font-size:12px;line-height:1.8;color:#718096;">تخطيط ومتابعة مستمرة</div>
                                </td>

                                <td align="center" width="33%" style="padding:22px 10px;">
                                    <div style="width:58px;height:58px;line-height:58px;border-radius:50%;background:{{ $secondary }};color:#ffffff;font-size:24px;margin:0 auto 10px;">⚒</div>
                                    <div style="font-size:17px;line-height:1.6;font-weight:700;color:{{ $text }};">خبرة ومهنية</div>
                                    <div style="font-size:12px;line-height:1.8;color:#718096;">حلول تناسب احتياجات مشروعك</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Closing --}}
                <tr>
                    <td align="center" style="padding:18px 40px 28px;background:#ffffff;">
                        <div style="font-size:18px;line-height:1.7;font-weight:700;color:{{ $text }};">
                            معًا نبني مشروعًا أفضل
                        </div>
                        <div style="margin:9px auto 0;width:54px;height:3px;background:{{ $secondary }};font-size:0;line-height:0;">&nbsp;</div>
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="background:{{ $text }};padding:16px 26px;border-radius:0 0 18px 18px;" dir="rtl">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td align="right" style="font-size:11px;line-height:1.8;color:#dbe4ec;">
                                    {{ $company }} — جميع الحقوق محفوظة
                                </td>
                                <td align="left" style="font-size:11px;line-height:1.8;">
                                    <a href="#" style="color:#ffffff;text-decoration:none;">الخصوصية</a>
                                    <span style="padding:0 7px;color:#90a0b2;">|</span>
                                    <a href="#" style="color:#ffffff;text-decoration:none;">الشروط</a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
