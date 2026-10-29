<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Notice - CRM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0d1b2a 0%, #1b263b 50%, #415a77 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 1.5rem;
            color: #333;
        }
        .notice-card {
            background: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            max-width: 520px;
            width: 100%;
            padding: 2.5rem 2rem;
            text-align: center;
        }
        .icon-badge {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem auto;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
        }
        .badge-warning { background: #fff3cd; color: #b45309; }
        .badge-danger { background: #fee2e2; color: #dc2626; }
        .badge-info { background: #e0f2fe; color: #0284c7; }
        .badge-secondary { background: #f1f5f9; color: #475569; }
        h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            color: #1e293b;
        }
        p.message {
            font-size: 1rem;
            color: #64748b;
            line-height: 1.5;
            margin-bottom: 1.5rem;
        }
        .meta-pill {
            display: inline-block;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 9999px;
            padding: 0.35rem 1rem;
            font-size: 0.85rem;
            color: #475569;
            margin-bottom: 1.5rem;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            text-decoration: none;
            background: #3b82f6;
            color: #ffffff;
            transition: all 0.2s ease;
        }
        .btn-action:hover {
            background: #2563eb;
            color: #ffffff;
        }
    </style>
</head>
<body>
    <div class="notice-card">
        @php
            $r = strtoupper((string) ($reason ?? 'NOTICE'));
            $isExpired = in_array($r, ['EXPIRED', 'MEMBERSHIP_EXPIRED'], true);
            $isInactive = in_array($r, ['INACTIVE', 'PRODUCT_INACTIVE', 'SETUP_INACTIVE'], true);
            $isPending = in_array($r, ['PENDING', 'PAYMENT_PENDING'], true);
            $isUnreachable = in_array($r, ['UNREACHABLE', 'NETWORK_ERROR'], true);
            $isNotProvisioned = in_array($r, ['NOT_PROVISIONED'], true);
        @endphp

        @if($isExpired)
            <div class="icon-badge badge-warning">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <h1>Subscription Expired</h1>
        @elseif($isInactive)
            <div class="icon-badge badge-danger">
                <i class="fa-solid fa-ban"></i>
            </div>
            <h1>Account Suspended</h1>
        @elseif($isPending)
            <div class="icon-badge badge-info">
                <i class="fa-solid fa-hourglass-half"></i>
            </div>
            <h1>Payment Pending</h1>
        @elseif($isNotProvisioned)
            <div class="icon-badge badge-info">
                <i class="fa-solid fa-database"></i>
            </div>
            <h1>Setup In Progress</h1>
        @elseif($isUnreachable)
            <div class="icon-badge badge-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <h1>Server Temporarily Unavailable</h1>
        @else
            <div class="icon-badge badge-secondary">
                <i class="fa-solid fa-circle-exclamation"></i>
            </div>
            <h1>Access Restricted</h1>
        @endif

        <p class="message">
            {{ $message ?? 'Your account is currently unable to access this service.' }}
        </p>

        @if(!empty($expiryDate))
            <div class="meta-pill">
                <i class="fa-regular fa-calendar me-1"></i> Plan expired on {{ $expiryDate }}
            </div>
        @endif

        <div>
            <a href="{{ url('/') }}" class="btn-action">
                <i class="fa-solid fa-arrow-left"></i> Return to Login
            </a>
        </div>
    </div>
</body>
</html>

