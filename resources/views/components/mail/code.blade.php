@props([
    'lang' => null,
])
<table cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 24px;">
    <tr>
        <td style="background-color:#0a0a0a; border:1px solid #1f2937; border-left:3px solid #22c55e; border-radius:8px; padding:14px 16px;">
            @if ($lang)
                <p style="margin:0 0 6px; font-size:10px; color:#52525b; text-transform:uppercase; letter-spacing:0.18em; font-weight:700;">
                    {{ $lang }}
                </p>
            @endif
            <pre style="margin:0; font-family:'SF Mono', Consolas, Monaco, monospace; font-size:13px; color:#e4e4e7; line-height:1.5; white-space:pre-wrap; word-break:break-all;">{{ trim($slot) }}</pre>
        </td>
    </tr>
</table>
