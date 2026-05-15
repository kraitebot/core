@props([
    'title' => 'Kraite',
])
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
</head>
<body style="margin:0; padding:0; background-color:#000000; font-family:'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#000000" style="background-color:#000000;">
        <tr>
            <td align="center" valign="top" bgcolor="#000000" style="padding:48px 20px; background-color:#000000;">
                <table role="presentation" width="520" cellpadding="0" cellspacing="0" border="0" align="center" style="width:520px; max-width:520px; background-color:#1c1c1c; border:1px solid #2a2a2a; border-radius:16px; overflow:hidden; margin:0 auto;">
                    <tr>
                        <td style="padding:32px 32px 24px; border-bottom:1px solid #2a2a2a;">
                            <span style="font-size:20px; font-weight:800; color:#ffffff; letter-spacing:-0.5px;">kraite</span><span style="color:#22c55e; margin-left:4px;">&bull;</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            {{ $slot }}
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:24px 32px; border-top:1px solid #2a2a2a; text-align:center;">
                            <p style="margin:0 0 10px; font-size:12px; line-height:1.6; color:#52525b;">
                                Need help? <a href="mailto:support@kraite.com" style="color:#a1a1aa; text-decoration:underline;">support@kraite.com</a>
                            </p>
                            <p style="margin:0; font-size:12px; line-height:1.6; color:#3f3f46;">
                                &copy; {{ date('Y') }} Kraite
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
