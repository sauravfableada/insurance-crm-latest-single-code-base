<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pay-In / Pay-Out Statement - {{ $record['ref_no'] ?? '' }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111827; margin: 18px; }
        .outer-box { border: 1.5px solid #111827; width: 100%; border-collapse: collapse; }
        .outer-box td, .outer-box th { border: 1px solid #111827; padding: 6px 8px; vertical-align: top; }
        .company-name { font-size: 15px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.4px; }
        .muted { color: #4b5563; font-size: 10px; }
        .text-end { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: bold; }
        .section-head { font-weight: bold; text-decoration: underline; margin-bottom: 4px; display: block; }
        .items-table { width: 100%; border-collapse: collapse; }
        .items-table th, .items-table td { border: 1px solid #111827; padding: 6px 8px; }
        .items-table th { background: #f8fafc; font-weight: bold; text-align: center; }
        .summary-row td { font-weight: bold; }
        .highlight-row td { background: #f1f5f9; font-weight: bold; font-size: 12px; }
    </style>
</head>
<body>
@php
    $adminUser = \App\Models\User::where('id', 1)->first()
        ?? \App\Models\User::whereIn('type', ['admin', 'super_admin'])->first()
        ?? auth()->user();

    $firmName = $companySettings['company_name'] ?? config('app.name', 'FABLEAD DEVELOPERS TECHNOLAB');
    $firmAddress = $companySettings['company_address'] ?? '';
    $firmGstin = !empty($companySettings['company_tax_id'])
        ? $companySettings['company_tax_id']
        : (!empty($companySettings['company_gstin']) ? $companySettings['company_gstin'] : '--');
    $firmPhone = !empty($companySettings['company_phone'])
        ? $companySettings['company_phone']
        : ($adminUser?->contact_no ?: '--');
    $firmEmail = !empty($companySettings['company_email'])
        ? $companySettings['company_email']
        : ($adminUser?->email ?: '--');

    $gross = (float) ($record['gross_premium'] ?? 0);
    $payIn = (float) ($record['pay_in_amount'] ?? 0);
    $payOut = (float) ($record['pay_out_amount'] ?? 0);
    $netProfit = (float) ($record['net_profit'] ?? 0);
@endphp

<table class="outer-box">
    <!-- Header -->
    <tr>
        <td style="width: 35%; vertical-align: middle; text-align: center;">
            @if(!empty($logoBase64))
                <img src="{{ $logoBase64 }}" alt="Logo" style="max-height: 55px; max-width: 160px;">
            @else
                <div class="company-name">{{ $firmName }}</div>
            @endif
        </td>
        <td style="width: 65%; text-align: right;">
            <div class="company-name">{{ $firmName }}</div>
            @if($firmAddress)
                <div class="muted" style="margin-top: 3px;">{{ $firmAddress }}</div>
            @endif
            <div class="muted" style="margin-top: 4px;">
                <strong>GSTIN/Tax No:</strong> {{ $firmGstin }} &nbsp;|&nbsp;
                <strong>Mob:</strong> {{ $firmPhone }} &nbsp;|&nbsp;
                <strong>Email:</strong> {{ $firmEmail }}
            </div>
        </td>
    </tr>

    <!-- Reference & Date -->
    <tr>
        <td><strong>Statement No.:</strong> {{ $record['ref_no'] ?? '--' }}</td>
        <td><strong>Date:</strong> {{ $record['date'] ?? '--' }} &nbsp;|&nbsp; <strong>Status:</strong> {{ ucfirst($record['status'] ?? 'pending') }}</td>
    </tr>

    <!-- Party & Agent Details -->
    <tr>
        <td style="width: 50%;">
            <span class="section-head">Insurer / Policy Details</span>
            <table style="width:100%; border:none; border-collapse:collapse;">
                <tr><td style="border:none; padding:2px 0; width:35%;"><strong>Company</strong></td><td style="border:none; padding:2px 0;">{{ $record['company_name'] ?? '--' }}</td></tr>
                <tr><td style="border:none; padding:2px 0;"><strong>Policy No.</strong></td><td style="border:none; padding:2px 0;">{{ $record['policy_number'] ?? '--' }}</td></tr>
                <tr><td style="border:none; padding:2px 0;"><strong>Holder</strong></td><td style="border:none; padding:2px 0;">{{ $record['holder_name'] ?? '--' }}</td></tr>
            </table>
        </td>
        <td style="width: 50%;">
            <span class="section-head">Sub-Agent / Staff Details</span>
            <table style="width:100%; border:none; border-collapse:collapse;">
                <tr><td style="border:none; padding:2px 0; width:35%;"><strong>Name</strong></td><td style="border:none; padding:2px 0;">{{ $record['user_name'] ?? '--' }}</td></tr>
                <tr><td style="border:none; padding:2px 0;"><strong>Email</strong></td><td style="border:none; padding:2px 0;">{{ $record['user_email'] ?? '--' }}</td></tr>
                <tr><td style="border:none; padding:2px 0;"><strong>Payment Mode</strong></td><td style="border:none; padding:2px 0;">{{ !empty($record['payment_method']) ? strtoupper(str_replace('_', ' ', $record['payment_method'])) : '--' }}@if(!empty($record['payout_details'])) ({{ $record['payout_details'] }})@endif</td></tr>
            </table>
        </td>
    </tr>

    <!-- Line Items -->
    <tr>
        <td colspan="2" style="padding: 0;">
            <table class="items-table" style="border: none;">
                <thead>
                    <tr>
                        <th style="width: 7%; border-top:none; border-left:none;">S.No</th>
                        <th style="width: 48%; border-top:none; text-align:left;">Description of Commission / Payout</th>
                        <th style="width: 15%; border-top:none;">HSN/SAC</th>
                        <th style="width: 10%; border-top:none;">Qty/Unit</th>
                        <th style="width: 20%; border-top:none; border-right:none; text-align:right;">Total Value (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="text-center" style="border-left:none;">1</td>
                        <td>
                            <strong>Pay-In (Admin Commission)</strong><br>
                            <span class="muted">Policy: {{ $record['policy_number'] ?? '--' }} | Gross Premium: ₹{{ number_format($gross, 2) }}</span>
                        </td>
                        <td class="text-center">997161</td>
                        <td class="text-center">1</td>
                        <td class="text-end fw-bold" style="border-right:none;">{{ number_format($payIn, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="text-center" style="border-left:none;">2</td>
                        <td>
                            <strong>Pay-Out (Sub-Agent / Staff Commission)</strong><br>
                            <span class="muted">Payable to: {{ $record['user_name'] ?? '--' }}</span>
                        </td>
                        <td class="text-center">997161</td>
                        <td class="text-center">1</td>
                        <td class="text-end fw-bold" style="border-right:none;">{{ number_format($payOut, 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </td>
    </tr>

    <!-- Totals -->
    <tr class="summary-row">
        <td>Gross Premium</td>
        <td class="text-end">₹ {{ number_format($gross, 2) }}</td>
    </tr>
    <tr class="summary-row">
        <td>Total Pay-In (Admin)</td>
        <td class="text-end">₹ {{ number_format($payIn, 2) }}</td>
    </tr>
    <tr class="summary-row">
        <td>Total Pay-Out (Agent / Staff)</td>
        <td class="text-end">₹ {{ number_format($payOut, 2) }}</td>
    </tr>
    <tr class="highlight-row">
        <td>Net Admin Profit</td>
        <td class="text-end">₹ {{ number_format($netProfit, 2) }}</td>
    </tr>

    <!-- Terms & Signatory -->
    <tr>
        <td style="width: 55%;">
            <span class="section-head">Terms &amp; Bank Details</span>
            <div class="muted">
                1. E. &amp; O.E. Subject to jurisdiction.<br>
                @if(!empty($companySettings['bank_name']))
                    2. Bank: {{ $companySettings['bank_name'] }} | A/C: {{ $companySettings['bank_account_no'] ?? '--' }} | IFSC: {{ $companySettings['bank_ifsc'] ?? '--' }}
                @endif
                @if(!empty($record['notes']) || !empty($record['remarks']))
                    <br>3. Remarks: {{ $record['notes'] ?? $record['remarks'] }}
                @endif
            </div>
        </td>
        <td style="width: 45%; text-align: right; vertical-align: bottom;">
            <div class="fw-bold" style="margin-bottom: 36px;">For {{ $firmName }}</div>
            <div style="border-top: 1px solid #111827; display: inline-block; padding-top: 4px; min-width: 150px; text-align: center;">
                <strong>Authorized Signatory</strong>
            </div>
        </td>
    </tr>
    <tr>
        <td colspan="2"><strong>Receiver's Signature:</strong></td>
    </tr>
</table>
</body>
</html>
