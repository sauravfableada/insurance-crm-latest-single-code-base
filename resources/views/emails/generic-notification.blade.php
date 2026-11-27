<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $emailSubject }}</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f6f9;
            margin: 0;
            padding: 20px;
            color: #333333;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border: 1px solid #e9ecef;
        }
        .header {
            background-color: #0f172a;
            color: #ffffff;
            padding: 24px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .content {
            padding: 28px 24px;
            line-height: 1.6;
            font-size: 15px;
            white-space: pre-line;
            color: #2d3748;
        }
        .footer {
            background-color: #f8fafc;
            border-top: 1px solid #edf2f7;
            padding: 16px 24px;
            text-align: center;
            font-size: 13px;
            color: #718096;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $firmName ?: config('app.name', 'Insurance CRM') }}</h1>
        </div>
        <div class="content">
{{ $bodyContent }}
        </div>
        <div class="footer">
            <p style="margin: 0;">&copy; {{ date('Y') }} {{ $firmName ?: config('app.name', 'Insurance CRM') }}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
