<tr>
    <td style="padding:18px 24px;border-top:1px solid #eef2f7;background:#fbfcfe;color:#4b5563;font-size:12px;line-height:1.8;">
        @if($showFooterCompanyName)
            <div style="font-size:14px;font-weight:bold;color:{{ $primaryColor }};">{{ $companyName }}</div>
        @endif
        @if($companyDescription)
            <div style="padding-top:4px;">{{ $companyDescription }}</div>
        @endif
        @if($showFooterEmail && $footerEmail)
            <div>{{ $footerEmail }}</div>
        @endif
        @if($showFooterPhone && $footerPhone)
            <div>{{ $footerPhone }}</div>
        @endif
        @if($showFooterWebsite && $footerWebsite)
            <div><a href="{{ $footerWebsite }}" style="color:{{ $secondaryColor }};text-decoration:none;">{{ $footerWebsite }}</a></div>
        @endif
        @if($showFooterAddress && $footerAddress)
            <div>{{ $footerAddress }}</div>
        @endif
        @if($showFooterSocial && ! empty($socialLinks))
            <div style="padding-top:8px;">
                @foreach($socialLinks as $index => $social)
                    <a href="{{ $social['url'] }}" style="color:{{ $secondaryColor }};text-decoration:none;">{{ $social['label'] }}</a>@if($index < count($socialLinks) - 1) &nbsp;·&nbsp; @endif
                @endforeach
            </div>
        @endif
        @if($showFooterCopyright && $footerCopyright)
            <div style="padding-top:10px;color:#9ca3af;">{{ $footerCopyright }}</div>
        @endif
    </td>
</tr>
