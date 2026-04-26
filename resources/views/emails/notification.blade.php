<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $notificationTitle ?? 'Notification' }}</title>
    <style>
        /* Reset styles */
        body, table, td, a {
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        table, td {
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
        }
        img {
            -ms-interpolation-mode: bicubic;
            border: 0;
            height: auto;
            line-height: 100%;
            outline: none;
            text-decoration: none;
        }

        /* Base styles */
        body {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            background-color: #f4f4f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }

        /* Container */
        .email-container {
            max-width: 600px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            background-color: #ffffff;
            padding: 32px 24px;
            text-align: center;
            border-bottom: 1px solid #e5e7eb;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            color: #111827;
            letter-spacing: -0.5px;
        }

        /* Content */
        .content {
            background-color: #ffffff;
            padding: 32px 24px;
        }

        /* Severity badge */
        .severity-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
        }

        /* Title */
        .notification-title {
            font-size: 20px;
            font-weight: 600;
            color: #111827;
            margin: 0 0 24px 0;
        }

        /* Salutation */
        .salutation {
            font-size: 15px;
            line-height: 24px;
            color: #374151;
            margin: 0 0 16px 0;
        }

        /* Message */
        .message {
            font-size: 15px;
            line-height: 24px;
            color: #374151;
            margin: 0 0 24px 0;
        }

        .message p {
            margin: 0 0 16px 0;
        }

        .message p:last-child {
            margin-bottom: 0;
        }

        /* Signature */
        .signature {
            font-size: 15px;
            line-height: 24px;
            color: #374151;
            margin: 32px 0 0 0;
        }

        /* Details box */
        .details-box {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            margin: 24px 0;
        }
        .details-box h3 {
            margin: 0 0 12px 0;
            font-size: 14px;
            font-weight: 600;
            color: #111827;
        }
        .details-box p {
            margin: 0;
            font-size: 14px;
            line-height: 20px;
            color: #6b7280;
        }

        /* Action button */
        .action-button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #3b82f6;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            margin: 8px 0;
            transition: background-color 0.2s;
        }
        .action-button:hover {
            background-color: #2563eb;
        }

        /* Footer */
        .footer {
            background-color: #f9fafb;
            padding: 24px;
            text-align: center;
            border-top: 1px solid #e5e7eb;
        }
        .footer p {
            margin: 0;
            font-size: 12px;
            color: #6b7280;
            line-height: 18px;
        }
        .footer a {
            color: #3b82f6;
            text-decoration: none;
        }
        .footer a:hover {
            text-decoration: underline;
        }
        .timestamp {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
            font-size: 11px;
            color: #9ca3af;
        }

        /* Prominent IP address display */
        .ip-address {
            font-family: 'Courier New', Courier, monospace;
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            padding: 20px 0;
            margin: 16px 0;
            cursor: text;
            user-select: all;
            -webkit-user-select: all;
            -moz-user-select: all;
            -ms-user-select: all;
            line-height: 1.5;
        }

        /* Command block display */
        .command-block {
            font-family: 'Courier New', Courier, Consolas, Monaco, monospace;
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-left: 4px solid #3b82f6;
            padding: 12px 16px;
            margin: 8px 0;
            border-radius: 4px;
            overflow-x: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
            cursor: text;
            user-select: all;
            -webkit-user-select: all;
            -moz-user-select: all;
            -ms-user-select: all;
            line-height: 1.6;
        }

        /* Responsive */
        @media only screen and (max-width: 600px) {
            .email-container {
                width: 100% !important;
            }
            .content {
                padding: 24px 16px !important;
            }
            .header {
                padding: 24px 16px !important;
            }
            .ip-address {
                font-size: 18px;
                padding: 16px 0;
            }
            .command-block {
                font-size: 12px;
                padding: 10px 12px;
            }
        }
    </style>
</head>
<body>
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f4f4f5;">
        <tr>
            <td style="padding: 40px 0;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" class="email-container" style="margin: 0 auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td class="header">
                            <h1>KRAITE</h1>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td class="content">
                            <!-- Severity Badge -->
                            @if(isset($severity))
                            <div class="severity-badge" style="background-color: {{ $severity->backgroundColor() }}; color: {{ $severity->color() }};">
                                {{ $severity->icon() }} {{ $severity->label() }}
                            </div>
                            @endif

                            <!-- Notification Title -->
                            <h2 class="notification-title">{{ $notificationTitle }}</h2>

                            <!-- Salutation -->
                            <div class="salutation">
                                Hello {{ $userName ?? 'there' }},
                            </div>

                            <!-- Message with proper paragraph formatting -->
                            <div class="message">
                                @php
                                    // Parse [COPY]text[/COPY] and [CMD]text[/CMD] markers
                                    // Use unique placeholders to avoid escaping issues
                                    $specialItems = [];
                                    $placeholder = '___SPECIAL_ITEM_%d___';
                                    $index = 0;

                                    // Process [COPY] markers for IP addresses
                                    $processedMessage = preg_replace_callback(
                                        '/\[COPY\](.*?)\[\/COPY\]/s',
                                        function($matches) use (&$specialItems, &$index, $placeholder) {
                                            $text = trim($matches[1]);
                                            $html = '<div class="ip-address">' . e($text) . '</div>';
                                            $specialItems[$index] = $html;
                                            return sprintf($placeholder, $index++);
                                        },
                                        $notificationMessage
                                    );

                                    // Process [CMD] markers for commands
                                    $processedMessage = preg_replace_callback(
                                        '/\[CMD\](.*?)\[\/CMD\]/s',
                                        function($matches) use (&$specialItems, &$index, $placeholder) {
                                            $text = trim($matches[1]);

                                            // Format SQL with line breaks for readability
                                            $sqlKeywords = ['SELECT', 'FROM', 'INNER JOIN', 'LEFT JOIN', 'RIGHT JOIN', 'JOIN', 'WHERE', 'AND', 'OR', 'GROUP BY', 'ORDER BY', 'HAVING', 'LIMIT', 'OFFSET', 'UNION', 'INSERT INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE FROM'];
                                            foreach ($sqlKeywords as $keyword) {
                                                $text = preg_replace('/\b(' . preg_quote($keyword, '/') . ')\b/i', "\n$1", $text);
                                            }
                                            $text = trim($text);

                                            // Escape HTML but convert newlines to <br> for email client compatibility
                                            $html = '<div class="command-block">' . nl2br(e($text)) . '</div>';
                                            $specialItems[$index] = $html;
                                            return sprintf($placeholder, $index++);
                                        },
                                        $processedMessage
                                    );

                                    // Escape and add line breaks to the remaining text
                                    $processedMessage = nl2br(e($processedMessage));

                                    // Replace placeholders with actual HTML
                                    foreach ($specialItems as $idx => $html) {
                                        $processedMessage = str_replace(
                                            e(sprintf($placeholder, $idx)),
                                            $html,
                                            $processedMessage
                                        );
                                    }
                                @endphp
                                {!! $processedMessage !!}
                            </div>

                            <!-- Action Button -->
                            @if(isset($actionUrl) && $actionUrl)
                            <div style="margin: 32px 0;">
                                <a href="{{ $actionUrl }}" class="action-button">
                                    {{ $actionLabel ?? 'Take Action' }} →
                                </a>
                            </div>
                            @endif

                            <!-- Additional Details (optional) -->
                            @if(isset($details) && $details)
                            <div class="details-box">
                                <h3>Additional Details</h3>
                                <p>{{ $details }}</p>
                            </div>
                            @endif

                            <!-- Signature -->
                            <div class="signature">
                                Best regards,<br/>
                                Kraite Team
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td class="footer">
                            <p>
                                Need help? Contact us at <a href="mailto:info@kraite.com">info@kraite.com</a>
                            </p>
                            <p style="margin-top: 8px; color: #9ca3af; font-size: 11px;">
                                © {{ date('Y') }} Kraite. All rights reserved.
                            </p>
                            <p class="timestamp">
                                Time sent: {{ now()->format('H:i') }} ({{ config('app.timezone') }})
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
