<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subjectLine ?? 'Notification' }}</title>
</head>
<body style="margin:0; padding:24px; background-color:#f1f5f9; font-family: Arial, Helvetica, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px; margin:0 auto; background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 4px 14px rgba(15,23,42,0.08);">
        <!-- Top Header -->
        <tr>
            <td style="padding:18px 24px; border-bottom:3px solid #f59e0b; background:#ffffff;">
                <strong style="font-size:18px; color:#0f172a;">{{ $firmName ?? config('app.name', 'Insurance CRM') }}</strong>
            </td>
        </tr>

        <!-- Hero Banner -->
        <tr>
            <td style="padding:20px 24px 10px 24px;">
                <div style="background:#eef4ff; border-radius:10px; padding:18px 20px;">
                    <div style="font-size:20px; font-weight:bold; color:#0f172a;">{{ $subjectLine ?? 'CRM Notification' }}</div>
                    <div style="font-size:13px; color:#475569; margin-top:4px;">Automated notification from {{ $firmName ?? config('app.name', 'Insurance CRM') }}</div>
                </div>
            </td>
        </tr>

        <!-- Body -->
        <tr>
            <td style="padding:16px 24px 24px 24px;">
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px 18px; font-size:14px; line-height:1.65; color:#1e293b;">
                    {!! nl2br(e($bodyText ?? '')) !!}
                </div>
            </td>
        </tr>

        <!-- Footer -->
        <tr>
            <td style="background:#0f2137; color:#cbd5e1; padding:14px 24px; font-size:12px; border-top:3px solid #f59e0b;">
                <div>&copy; {{ date('Y') }} Copyright - <strong style="color:#f59e0b;">{{ $firmName ?? config('app.name', 'Insurance CRM') }}</strong></div>
            </td>
        </tr>
    </table>
</body>
</html>
