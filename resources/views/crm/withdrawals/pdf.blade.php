<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pay-In / Pay-Out Statement - {{ $record['ref_no'] ?? '' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1e293b; margin: 24px; }
        .header { border-bottom: 2px solid #0f172a; padding-bottom: 12px; margin-bottom: 20px; }
        .title { font-size: 18px; font-weight: bold; color: #0f172a; margin: 0 0 4px 0; }
        .subtitle { font-size: 11px; color: #64748b; margin: 0; }
        .badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 10px; font-weight: bold; text-transform: uppercase; background: #e2e8f0; color: #0f172a; }
        .section-title { font-size: 13px; font-weight: bold; color: #0f172a; margin: 18px 0 8px 0; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        th, td { padding: 8px 10px; border: 1px solid #cbd5e1; text-align: left; }
        th { background: #f8fafc; font-weight: bold; color: #334155; width: 28%; }
        .text-end { text-align: right; }
        .amount-in { color: #1d4ed8; font-weight: bold; }
        .amount-out { color: #0f172a; font-weight: bold; }
        .amount-profit { color: #15803d; font-weight: bold; }
        .footer { margin-top: 30px; font-size: 10px; color: #64748b; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Pay-In / Pay-Out Statement</div>
        <p class="subtitle">
            Reference: <strong>{{ $record['ref_no'] ?? '--' }}</strong> &nbsp;|&nbsp;
            Date: <strong>{{ $record['date'] ?? '--' }}</strong> &nbsp;|&nbsp;
            Status: <span class="badge">{{ ucfirst($record['status'] ?? 'pending') }}</span>
        </p>
    </div>

    <div class="section-title">Policy &amp; Agent Details</div>
    <table>
        <tr>
            <th>Insurer / Company</th>
            <td>{{ $record['company_name'] ?? '--' }}</td>
            <th>Policy Number</th>
            <td>{{ $record['policy_number'] ?? '--' }}</td>
        </tr>
        <tr>
            <th>Sub-Agent / Staff</th>
            <td>{{ $record['user_name'] ?? '--' }}</td>
            <th>Agent Email</th>
            <td>{{ $record['user_email'] ?? '--' }}</td>
        </tr>
        <tr>
            <th>Payment Mode</th>
            <td>{{ $record['payment_method'] ?? '--' }}</td>
            <th>Policy Holder</th>
            <td>{{ $record['holder_name'] ?? '--' }}</td>
        </tr>
    </table>

    <div class="section-title">Pay-In &amp; Pay-Out Breakdown</div>
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="text-end">Amount (INR)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Gross Premium</td>
                <td class="text-end">₹ {{ number_format((float) ($record['gross_premium'] ?? 0), 2) }}</td>
            </tr>
            <tr>
                <td>Pay-In (Admin Commission)</td>
                <td class="text-end amount-in">₹ {{ number_format((float) ($record['pay_in_amount'] ?? 0), 2) }}</td>
            </tr>
            <tr>
                <td>Pay-Out (Agent / Staff Commission)</td>
                <td class="text-end amount-out">₹ {{ number_format((float) ($record['pay_out_amount'] ?? 0), 2) }}</td>
            </tr>
            <tr>
                <td>Net Admin Profit</td>
                <td class="text-end amount-profit">₹ {{ number_format((float) ($record['net_profit'] ?? 0), 2) }}</td>
            </tr>
        </tbody>
    </table>

    @if(!empty($record['notes']) || !empty($record['remarks']))
        <div class="section-title">Remarks / Notes</div>
        <p>{{ $record['notes'] ?? $record['remarks'] }}</p>
    @endif

    <div class="footer">
        Generated on {{ now()->format('d M Y, h:i A') }}
    </div>
</body>
</html>
