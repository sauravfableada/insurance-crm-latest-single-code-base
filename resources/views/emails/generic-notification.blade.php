<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $emailSubject }}</title>
    <style type="text/css">
        body {
            margin: 0;
            padding: 0;
            background-color: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #1e293b;
            -webkit-font-smoothing: antialiased;
        }
        table {
            border-collapse: collapse;
        }
        .email-container {
            max-width: 580px;
            margin: 30px auto;
            background: #ffffff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
            border: 1px solid #e2e8f0;
        }
        .header {
            background-color: #0b192c;
            padding: 24px 30px;
            text-align: center;
        }
        .header img {
            max-height: 48px;
            max-width: 200px;
            object-fit: contain;
            display: inline-block;
        }
        .header-title {
            color: #ffffff;
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .content {
            padding: 30px;
            font-size: 15px;
            line-height: 1.65;
            color: #334155;
            white-space: pre-line;
        }
        .footer {
            background-color: #f8fafc;
            padding: 16px 30px;
            text-align: center;
            font-size: 12px;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
        }
        @media only screen and (max-width: 600px) {
            .email-container {
                margin: 10px;
                border-radius: 6px;
            }
            .content, .header, .footer {
                padding: 20px !important;
            }
        }
    </style>
</head>
<body>
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color: #f1f5f9; padding: 20px 0;">
        <tr>
            <td align="center">
                <div class="email-container">
                    <!-- Brand Header -->
                    <div class="header">
                        @if(!empty($logoDataUri))
                            <img src="{{ $logoDataUri }}" alt="{{ $firmName }}">
                        @else
                            <h2 class="header-title">{{ $firmName }}</h2>
                        @endif
                    </div>

                    <!-- Main Body -->
                    <div class="content">
{{ $bodyContent }}
                    </div>

                    <!-- Footer -->
                    <div class="footer">
                        &copy; {{ date('Y') }} {{ $firmName }}. All rights reserved.
                    </div>
                </div>
            </td>
        </tr>
    </table>
</body>
</html>
