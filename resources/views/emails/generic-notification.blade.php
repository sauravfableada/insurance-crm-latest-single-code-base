<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>{{ $emailSubject }}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f4f6f9; padding: 30px 15px;">
        <tr>
            <td align="center">
                <!-- Main Container -->
                <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">
                    
                    <!-- Header with Company Name -->
                    <tr>
                        <td align="center" style="background-color: #0b192c; padding: 22px 30px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 19px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;">
                                {{ $firmName }}
                            </h1>
                        </td>
                    </tr>

                    <!-- Subject Strip -->
                    @if(!empty($emailSubject))
                    <tr>
                        <td style="background-color: #f8fafc; padding: 12px 30px; border-bottom: 1px solid #e2e8f0; font-size: 13px; font-weight: 600; color: #0284c7; text-transform: uppercase; letter-spacing: 0.5px;">
                            {{ $emailSubject }}
                        </td>
                    </tr>
                    @endif

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 28px 30px; font-size: 14px; line-height: 1.7; color: #334155; white-space: pre-line;">
{{ $bodyContent }}
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td align="center" style="background-color: #f8fafc; padding: 16px 30px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #64748b; text-align: center;">
                            &copy; {{ date('Y') }} {{ $firmName }}. All rights reserved.
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
