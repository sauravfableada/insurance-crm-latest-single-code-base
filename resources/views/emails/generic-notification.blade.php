<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $emailSubject }}</title>
    <style type="text/css">
        body, table, td, a {
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        table, td {
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
            border-collapse: collapse;
        }
        img {
            -ms-interpolation-mode: bicubic;
            border: 0;
            height: auto;
            line-height: 100%;
            outline: none;
            text-decoration: none;
        }
        body {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            background-color: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: #1e293b;
        }
        .wrapper {
            width: 100%;
            table-layout: fixed;
            background-color: #f1f5f9;
            padding: 30px 10px;
        }
        .main-card {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.08);
            border: 1px solid #e2e8f0;
        }
        .brand-header {
            background-color: #0d1b2a;
            background: linear-gradient(135deg, #0d1b2a 0%, #1b263b 100%);
            padding: 24px 30px;
            text-align: center;
        }
        .brand-logo {
            max-height: 44px;
            max-width: 180px;
            vertical-align: middle;
        }
        .brand-title {
            color: #ffffff;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin: 0;
            text-transform: uppercase;
        }
        .subject-strip {
            background-color: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 14px 30px;
            font-size: 13px;
            font-weight: 600;
            color: #0284c7;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }
        .body-content {
            padding: 32px 30px;
            font-size: 15px;
            line-height: 1.7;
            color: #334155;
            white-space: pre-line;
            word-break: break-word;
        }
        .footer-strip {
            background-color: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 20px 30px;
            text-align: center;
            font-size: 12px;
            color: #64748b;
            line-height: 1.5;
        }
        .footer-strip a {
            color: #0284c7;
            text-decoration: none;
        }
        @media only screen and (max-width: 600px) {
            .wrapper {
                padding: 10px 5px !important;
            }
            .body-content, .brand-header, .subject-strip, .footer-strip {
                padding-left: 20px !important;
                padding-right: 20px !important;
            }
        }
    </style>
</head>
<body>
    <table class="wrapper" role="presentation" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td align="center">
                <table class="main-card" role="presentation" cellpadding="0" cellspacing="0" width="100%">
                    <!-- Header -->
                    <tr>
                        <td class="brand-header">
                            @if(!empty($companyLogoUrl))
                                <img src="{{ $companyLogoUrl }}" alt="{{ $firmName ?: 'CRM' }}" class="brand-logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                <h1 class="brand-title" style="display: none;">{{ $firmName ?: config('app.name', 'Insurance CRM') }}</h1>
                            @else
                                <h1 class="brand-title">{{ $firmName ?: config('app.name', 'Insurance CRM') }}</h1>
                            @endif
                        </td>
                    </tr>

                    <!-- Subject / Context Strip -->
                    @if(!empty($emailSubject))
                    <tr>
                        <td class="subject-strip">
                            <span>{{ $emailSubject }}</span>
                        </td>
                    </tr>
                    @endif

                    <!-- Main Email Message Body -->
                    <tr>
                        <td class="body-content">
{{ $bodyContent }}
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td class="footer-strip">
                            <p style="margin: 0 0 4px 0;">This is an automated notification from <strong>{{ $firmName ?: config('app.name', 'Insurance CRM') }}</strong>.</p>
                            <p style="margin: 0;">&copy; {{ date('Y') }} {{ $firmName ?: config('app.name', 'Insurance CRM') }}. All rights reserved.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
