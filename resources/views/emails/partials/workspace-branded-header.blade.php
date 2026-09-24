@php
    $headerStyle = $headerStyle ?? 'solid';
    $navHtml = '';
    if (! empty($headerLinks)) {
        $parts = [];
        foreach ($headerLinks as $link) {
            $parts[] = '<a href="'.e($link['url']).'" style="color:inherit;text-decoration:none;font-size:13px;padding:0 8px;">'.e($link['label']).'</a>';
        }
        $navHtml = implode('', $parts);
    }
@endphp
<tr>
    <td>
        @if($headerStyle === 'minimal')
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                <tr>
                    <td style="padding:18px 24px 12px 24px;background:#ffffff;border-bottom:3px solid {{ $primaryColor }};">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td style="vertical-align:middle;">
                                    @if($showHeaderLogo && $logoUrl)
                                        <img src="{{ $logoUrl }}" alt="{{ $companyName }}" width="140" height="42" style="display:block;border:0;height:42px;width:auto;">
                                    @endif
                                    @if($showHeaderCompanyName)
                                        <div style="font-size:18px;font-weight:bold;color:{{ $primaryColor }};padding-top:6px;">{{ $companyName }}</div>
                                    @endif
                                </td>
                                @if($navHtml !== '')
                                    <td align="left" style="vertical-align:middle;color:{{ $textColor }};">{!! $navHtml !!}</td>
                                @endif
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        @elseif($headerStyle === 'centered')
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:{{ $primaryColor }};">
                <tr>
                    <td align="center" style="padding:22px 24px;color:#ffffff;">
                        @if($showHeaderLogo && $logoUrl)
                            <img src="{{ $logoUrl }}" alt="{{ $companyName }}" width="140" height="42" style="display:block;border:0;height:42px;width:auto;margin:0 auto 8px auto;">
                        @endif
                        @if($showHeaderCompanyName)
                            <div style="font-size:20px;font-weight:bold;color:#ffffff;">{{ $companyName }}</div>
                        @endif
                        @if($navHtml !== '')
                            <div style="padding-top:10px;color:#ffffff;">{!! $navHtml !!}</div>
                        @endif
                    </td>
                </tr>
            </table>
        @elseif($headerStyle === 'split')
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:{{ $primaryColor }};">
                <tr>
                    <td style="padding:18px 24px;color:#ffffff;vertical-align:middle;">
                        @if($showHeaderLogo && $logoUrl)
                            <img src="{{ $logoUrl }}" alt="{{ $companyName }}" width="140" height="42" style="display:block;border:0;height:42px;width:auto;">
                        @endif
                        @if($showHeaderCompanyName)
                            <div style="font-size:18px;font-weight:bold;color:#ffffff;padding-top:6px;">{{ $companyName }}</div>
                        @endif
                    </td>
                    @if($navHtml !== '')
                        <td align="left" style="padding:18px 24px;color:#ffffff;vertical-align:middle;">{!! $navHtml !!}</td>
                    @endif
                </tr>
            </table>
        @else
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:{{ $primaryColor }};">
                <tr>
                    <td style="padding:18px 24px;color:#ffffff;">
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td style="vertical-align:middle;color:#ffffff;">
                                    @if($showHeaderLogo && $logoUrl)
                                        <img src="{{ $logoUrl }}" alt="{{ $companyName }}" width="140" height="42" style="display:block;border:0;height:42px;width:auto;">
                                    @endif
                                    @if($showHeaderCompanyName)
                                        <div style="font-size:18px;font-weight:bold;color:#ffffff;padding-top:6px;">{{ $companyName }}</div>
                                    @endif
                                </td>
                                @if($navHtml !== '')
                                    <td align="left" style="vertical-align:middle;color:#ffffff;">{!! $navHtml !!}</td>
                                @endif
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        @endif
    </td>
</tr>
