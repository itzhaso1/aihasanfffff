@php
    $hasContact = (! empty($showFooterEmail) && ! empty($footerEmail))
        || (! empty($showFooterPhone) && ! empty($footerPhone))
        || (! empty($showFooterWebsite) && ! empty($footerWebsite))
        || (! empty($showFooterAddress) && ! empty($footerAddress));

    $hasSocial = ! empty($showFooterSocial) && ! empty($socialLinks);

    $headerNav = [];
    if (! empty($headerLinks) && is_array($headerLinks)) {
        $headerNav = $headerLinks;
    }
@endphp

@php
    // Theme colors come from the existing settings panel.
    // Fallbacks keep the template working if a variable is missing.
    $themePrimary = !empty($primaryColor) ? $primaryColor : '#ffffff';
    $themeSecondary = !empty($secondaryColor) ? $secondaryColor : '#079a96';
    $themeBackground = !empty($backgroundColor) ? $backgroundColor : '#f3f7fa';
    $themeText = !empty($textColor) ? $textColor : '#21344c';
    $themeMuted = '#6e7d90';
    $themeBorder = '#e8edf1';
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?: $companyName }}</title>
</head>
<body style="margin:0;padding:0;background:{{ $themeBackground }};font-family:Tahoma,Arial,Helvetica,sans-serif;color:{{ $themeText }};">

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="{{ $themeBackground }}" style="width:100%;background:{{ $themeBackground }};">
        <tr>
            <td align="center" style="padding:28px 10px;">

                <!-- Browser preview -->
                <table role="presentation" width="960" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:960px;">
                    <tr>
                        <td align="center" dir="rtl" style="padding:0 0 18px 0;font-size:14px;line-height:1.8;color:{{ $themeText }};">
                            هل تواجه مشكلة في عرض هذه الرسالة؟
                            @if(!empty($headerNav))
                                @foreach($headerNav as $link)
                                    @if(! empty($link['url']) && ! empty($link['label']))
                                        <a href="{{ $link['url'] }}" style="color:{{ $themeSecondary }};text-decoration:underline;font-weight:bold;margin-right:7px;">{{ $link['label'] }}</a>
                                        @break
                                    @endif
                                @endforeach
                            @else
                                <a href="#" style="color:{{ $themeSecondary }};text-decoration:underline;font-weight:bold;margin-right:7px;">اعرضها في المتصفح</a>
                            @endif
                        </td>
                    </tr>
                </table>

                <!-- Main card -->
                <table role="presentation" width="960" cellspacing="0" cellpadding="0" border="0" bgcolor="{{ $themePrimary }}" style="width:100%;max-width:960px;background:{{ $themePrimary }};border-radius:18px;overflow:hidden;">

                    <!-- Header -->
                    <tr>
                        <td bgcolor="{{ $themeSecondary }}" style="padding:0;background:{{ $themeSecondary }};">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td dir="rtl" style="padding:34px 48px 34px 48px;background:{{ $themeSecondary }};">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                            <tr>
                                                <!-- Logo / company -->
                                                <td align="right" valign="middle" dir="rtl" style="padding:0;">
                                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                                        <tr>
                                                            @if(! empty($showHeaderLogo) && ! empty($logoUrl))
                                                                <td valign="middle" bgcolor="{{ $themeSecondary }}" style="padding-left:16px;background:{{ $themeSecondary }};">
                                                                    <img src="{{ $logoUrl }}" alt="" width="66" height="66" style="display:block;border:0;width:66px;height:66px;background:{{ $themeSecondary }};">
                                                                </td>
                                                            @endif
                                                            <td valign="middle" style="color:#ffffff;">
                                                                @if(! empty($showHeaderCompanyName) && ! empty($companyName))
                                                                    <div style="font-size:30px;line-height:1.15;font-weight:bold;color:#ffffff;">{{ $companyName }}</div>
                                                                @endif
                                                                @if(! empty($companyDescription))
                                                                    <div style="padding-top:5px;font-size:15px;line-height:1.7;color:#ffffff;">{{ $companyDescription }}</div>
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>

                                                <!-- Slogan / nav -->
                                                <td align="left" valign="middle" dir="rtl" style="padding:0 0 0 18px;color:#ffffff;">
                                                    @if(!empty($headerNav))
                                                        @foreach($headerNav as $link)
                                                            @if(! empty($link['url']) && ! empty($link['label']))
                                                                <div style="font-size:14px;line-height:1.8;color:#ffffff;">
                                                                    <a href="{{ $link['url'] }}" style="color:#ffffff;text-decoration:none;">{{ $link['label'] }}</a>
                                                                </div>
                                                            @endif
                                                        @endforeach
                                                    @else
                                                        <div style="font-size:23px;line-height:1.5;color:#ffffff;border-right:1px solid rgba(255,255,255,.6);padding-right:22px;">نفكر معك ... لنصل لأبعد</div>
                                                    @endif
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <!-- Curved lower edge -->
                                <tr>
                                    <td height="28" style="height:28px;background:{{ $themeSecondary }};font-size:0;line-height:0;">
                                        <div style="height:28px;background:{{ $themePrimary }};border-radius:50% 50% 0 0 / 100% 100% 0 0;"></div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Main content -->
                    <tr>
                        <td dir="rtl" align="right" style="padding:18px 62px 20px 62px;background:{{ $themePrimary }};">

                            @if(! empty($subject))
                                <h1 style="margin:0 0 12px;font-size:34px;line-height:1.5;color:{{ $themeText }};font-weight:bold;">
                                    {{ $subject }} <span style="font-size:28px;vertical-align:middle;">👋</span>
                                </h1>
                            @else
                                <h1 style="margin:0 0 12px;font-size:34px;line-height:1.5;color:{{ $themeText }};font-weight:bold;">
                                    مرحباً بك <span style="font-size:28px;vertical-align:middle;">👋</span>
                                </h1>
                            @endif

                            @if(!empty($companyName))
                                <div style="font-size:22px;line-height:1.8;color:{{ $themeText }};margin-bottom:25px;">يسعدنا تواصلك معنا</div>
                            @endif

                            <div style="font-size:18px;line-height:2.1;color:{{ $themeText }};">
                                {!! nl2br(e((string) $body)) !!}
                            </div>

                            @if(! empty($showCta) && ! empty($ctaUrl) && ! empty($ctaLabel))
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:28px 0 8px 0;">
                                    <tr>
                                        <td align="center">
                                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                                <tr>
                                                    <td align="center" bgcolor="{{ $themeSecondary }}" style="background:{{ $themeSecondary }};border-radius:15px;">
                                                        <a href="{{ $ctaUrl }}" style="display:inline-block;min-width:285px;padding:17px 30px;color:#ffffff;text-decoration:none;font-size:20px;font-weight:bold;">
                                                            {{ $ctaLabel }} <span style="font-size:22px;">←</span>
                                                        </a>
                                                    </td>
                                                </tr>
                                            </table>
                                            <div style="padding-top:10px;font-size:14px;line-height:1.7;color:{{ $themeMuted }};">بالضغط على الزر أعلاه، ستنتقل إلى حسابك مباشرة.</div>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                        </td>
                    </tr>

                    <!-- Features matching the reference design -->
                    <tr>
                        <td dir="rtl" style="padding:18px 62px 16px;background:{{ $themePrimary }};">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="{{ $themeBackground }}" style="width:100%;background:{{ $themeBackground }};border-radius:16px;">
                                <tr>
                                    <td align="center" valign="top" width="33%" style="padding:24px 12px;">
                                        <div style="width:66px;height:66px;margin:0 auto 12px auto;border-radius:50%;background:{{ $themeSecondary }};line-height:66px;font-size:30px;color:#ffffff;">🛡</div>
                                        <div style="font-size:19px;line-height:1.6;font-weight:bold;color:{{ $themeText }};">أمان وموثوقية</div>
                                        <div style="font-size:14px;line-height:1.8;color:{{ $themeMuted }};">بياناتك في أمان</div>
                                    </td>
                                    <td align="center" valign="top" width="33%" style="padding:24px 12px;">
                                        <div style="width:66px;height:66px;margin:0 auto 12px auto;border-radius:50%;background:{{ $themeSecondary }};line-height:66px;font-size:30px;color:#ffffff;">⚡</div>
                                        <div style="font-size:19px;line-height:1.6;font-weight:bold;color:{{ $themeText }};">أداء أعلى</div>
                                        <div style="font-size:14px;line-height:1.8;color:{{ $themeMuted }};">حلول مبتكرة وفعالة</div>
                                    </td>
                                    <td align="center" valign="top" width="33%" style="padding:24px 12px;">
                                        <div style="width:66px;height:66px;margin:0 auto 12px auto;border-radius:50%;background:{{ $themeSecondary }};line-height:66px;font-size:30px;color:#ffffff;">🎧</div>
                                        <div style="font-size:19px;line-height:1.6;font-weight:bold;color:{{ $themeText }};">دعم دائم</div>
                                        <div style="font-size:14px;line-height:1.8;color:{{ $themeMuted }};">نحن هنا لمساعدتك</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Company name / social -->
                    @if(! empty($showFooterCompanyName) && ! empty($companyName))
                        <tr>
                            <td align="center" style="padding:24px 32px 8px;background:{{ $themePrimary }};">
                                <div style="font-size:20px;font-weight:bold;color:{{ $themeText }};">معاً نصنع مستقبلاً أفضل</div>
                                <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:10px auto 0 auto;">
                                    <tr>
                                        <td width="58" height="3" bgcolor="{{ $themeSecondary }}" style="background:{{ $themeSecondary }};font-size:0;line-height:0;">&nbsp;</td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    @if($hasSocial)
                        <tr>
                            <td align="center" style="padding:10px 32px 28px;background:{{ $themePrimary }};">
                                <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                    <tr>
                                        @foreach($socialLinks as $social)
                                            @if(! empty($social['url']) && ! empty($social['iconUrl']))
                                                <td align="center" valign="middle" style="padding:0 7px;">
                                                    <a href="{{ $social['url'] }}" style="text-decoration:none;">
                                                        <img src="{{ $social['iconUrl'] }}" alt="" width="42" height="42" style="display:block;border:0;width:42px;height:42px;">
                                                    </a>
                                                </td>
                                            @endif
                                        @endforeach
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    <!-- Contact -->
                    @if($hasContact)
                        <tr>
                            <td dir="rtl" align="center" style="padding:18px 28px 22px;background:{{ $themePrimary }};border-top:1px solid {{ $themeBorder }};">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                    <tr>
                                        @if(! empty($showFooterEmail) && ! empty($footerEmail))
                                            <td align="center" valign="middle" style="padding:7px;font-size:13px;line-height:1.8;color:{{ $themeText }};">✉ {{ $footerEmail }}</td>
                                        @endif
                                        @if(! empty($showFooterPhone) && ! empty($footerPhone))
                                            <td align="center" valign="middle" style="padding:7px;font-size:13px;line-height:1.8;color:{{ $themeText }};">☎ {{ $footerPhone }}</td>
                                        @endif
                                        @if(! empty($showFooterWebsite) && ! empty($footerWebsite))
                                            <td align="center" valign="middle" style="padding:7px;font-size:13px;line-height:1.8;color:{{ $themeText }};">
                                                🌐 <a href="{{ $footerWebsite }}" style="color:{{ $themeText }};text-decoration:none;">{{ $footerWebsite }}</a>
                                            </td>
                                        @endif
                                        @if(! empty($showFooterAddress) && ! empty($footerAddress))
                                            <td align="center" valign="middle" style="padding:7px;font-size:13px;line-height:1.8;color:{{ $themeText }};">📍 {{ $footerAddress }}</td>
                                        @endif
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    <!-- Footer -->
                    @if(! empty($showFooterCopyright) && ! empty($footerCopyright))
                        <tr>
                            <td bgcolor="{{ $themeText }}" style="background:{{ $themeText }};padding:17px 24px;border-radius:0 0 18px 18px;">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                    <tr>
                                        <td align="right" dir="rtl" style="font-size:12px;line-height:1.8;color:#d5dde6;">
                                            {{ $footerCopyright }}
                                        </td>
                                        <td align="left" dir="rtl" style="font-size:12px;line-height:1.8;color:#d5dde6;">
                                            <a href="#" style="color:#ffffff;text-decoration:none;">الخصوصية</a>
                                            <span style="padding:0 8px;color:{{ $themeMuted }};">|</span>
                                            <a href="#" style="color:#ffffff;text-decoration:none;">الشروط</a>
                                            <span style="padding:0 8px;color:{{ $themeMuted }};">|</span>
                                            <a href="#" style="color:#ffffff;text-decoration:none;">إلغاء الاشتراك</a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
