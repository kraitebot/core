<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $alertTitle }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f5f5f5;
        }
        .email-container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .hostname {
            margin-top: 8px;
            font-size: 14px;
            opacity: 0.9;
            font-family: 'Courier New', monospace;
        }
        .content {
            padding: 40px 30px;
        }
        .message-box {
            background-color: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 20px;
            border-radius: 4px;
            margin: 20px 0;
        }
        .message-box p {
            margin: 0;
            line-height: 1.6;
            color: #333333;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .button {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 30px;
            background-color: #667eea;
            color: #ffffff;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
            transition: background-color 0.3s ease;
        }
        .button:hover {
            background-color: #5568d3;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 20px 30px;
            text-align: center;
            color: #6c757d;
            font-size: 14px;
            border-top: 1px solid #e9ecef;
        }
        .footer p {
            margin: 5px 0;
        }
        @media only screen and (max-width: 600px) {
            .email-container {
                margin: 0;
                border-radius: 0;
            }
            .content {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <h1>{{ $alertTitle }}</h1>
            @if($hostname)
                <div class="hostname">{{ $hostname }}</div>
            @endif
        </div>

        <div class="content">
            <div class="message-box">
                <p>{{ $alertMessage }}</p>
            </div>

            @if($url)
                <a href="{{ $url }}" class="button">
                    {{ $url_title ?? 'View Details' }}
                </a>
            @endif
        </div>

        <div class="footer">
            <p>This is an automated notification from the Nidavellir Trading System.</p>
            <p>&copy; {{ date('Y') }} Kraite. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
