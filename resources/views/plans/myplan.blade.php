@extends('layouts.app')

@section('title', 'My Plan Details')

@section('content')
<style>
    /* Ensure modal close buttons always show cross icon clearly */
    .modal .btn-close-custom {
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        width: 34px !important;
        height: 34px !important;
        min-width: 34px !important;
        border-radius: 50% !important;
        border: none !important;
        outline: none !important;
        font-size: 16px !important;
        cursor: pointer !important;
        transition: all 0.2s ease-in-out !important;
        padding: 0 !important;
        line-height: 1 !important;
        text-decoration: none !important;
    }

    /* White close button variant (for colored/dark headers) */
    .modal .btn-close-custom.btn-close-white-style {
        background-color: rgba(255, 255, 255, 0.25) !important;
        color: #ffffff !important;
    }
    .modal .btn-close-custom.btn-close-white-style:hover {
        background-color: rgba(255, 255, 255, 0.4) !important;
        color: #ffffff !important;
        transform: scale(1.08);
    }

    /* Dark/Light close button variant */
    .modal .btn-close-custom.btn-close-dark-style {
        background-color: #f1f3f5 !important;
        color: #495057 !important;
    }
    .modal .btn-close-custom.btn-close-dark-style:hover {
        background-color: #e9ecef !important;
        color: #212529 !important;
        transform: scale(1.08);
    }

    /* Force pure white text on dark modal headers across all themes */
    #renewPlanModal .modal-header,
    #upgradePlanModal .modal-header,
    #invoicesListModal .modal-header {
        border-bottom: none !important;
    }

    #renewPlanModal .modal-title,
    #renewPlanModal .modal-header h5,
    #renewPlanModal .modal-header .modal-title,
    #upgradePlanModal .modal-title,
    #upgradePlanModal .modal-header h5,
    #upgradePlanModal .modal-header .modal-title,
    #invoicesListModal .modal-title,
    #invoicesListModal .modal-header h5,
    #invoicesListModal .modal-header .modal-title {
        color: #ffffff !important;
        font-weight: 700 !important;
        font-size: 1.2rem !important;
        letter-spacing: 0.3px !important;
        margin: 0 !important;
        text-shadow: 0 1px 2px rgba(0,0,0,0.2) !important;
    }

    #renewPlanModal .modal-header i,
    #upgradePlanModal .modal-header i {
        color: #f59e0b !important;
    }
</style>

<div class="content">
    <div class="page-header d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div class="page-title">
            <h4 style="font-weight: 700; color: #333; margin-bottom: 5px;">My Plan Details</h4>
            <h6 style="color: #777; font-weight: 400;">Overview of your active subscription plan and limits</h6>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <button type="button" class="btn text-white fw-semibold" style="background-color: #eb5e28; border-radius: 6px;" data-bs-toggle="modal" data-bs-target="#invoicesListModal">
                <i class="fas fa-file-pdf me-1"></i> Download Invoice
            </button>
            <button type="button" class="btn btn-outline-success fw-semibold" style="border-radius: 6px;" data-bs-toggle="modal" data-bs-target="#renewPlanModal">
                <i class="fas fa-sync-alt me-1"></i> Renew Plan
            </button>
            <button type="button" class="btn" data-bs-toggle="modal" data-bs-target="#planHistoryModal" style="color: #24355c; border: 1px solid #24355c; background-color: #fff; font-weight: 600; border-radius: 6px;">
                <i class="fas fa-history me-1"></i> Plan History
            </button>
            <a href="javascript:history.back()" class="btn" style="background-color: #d27936; color: #fff; font-weight: 600; border-radius: 6px;">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    @php
        $planDaysRemaining = 0;
        $isFutureOrToday = false;
        if ($plan && $plan->end_date) {
            $endDate = \Carbon\Carbon::parse($plan->end_date)->startOfDay();
            $now = \Carbon\Carbon::now()->startOfDay();
            $planDaysRemaining = $now->diffInDays($endDate, false); 
            $isFutureOrToday = $endDate->greaterThanOrEqualTo($now);
        }

        // 1. Dynamic Plan Name from Tenant or Plan record
        $tenantPlanName = function_exists('tenant') ? tenant('plan_name') : ($tenant['plan_name'] ?? null);
        $displayPlanName = $tenantPlanName ?: (($plan && $plan->name && $plan->name !== 'Starter Plan') ? $plan->name : 'insurance-crm');

        // 2. Dynamic Purchase Price / Amount from Tenant or Plan record
        $tenantRawAmount = function_exists('tenant') ? tenant('amount') : ($tenant['amount'] ?? null);
        $tenantAmount = null;
        if ($tenantRawAmount !== null && $tenantRawAmount !== '') {
            $tenantAmount = (float) (is_array($tenantRawAmount) ? ($tenantRawAmount[0] ?? 0) : $tenantRawAmount);
        }

        // 3. Dynamic Renewal Amount from Tenant or Plan record
        $tenantRawRenewal = function_exists('tenant') ? tenant('renewal_amount') : ($tenant['renewal_amount'] ?? null);
        $tenantRenewalAmount = null;
        if ($tenantRawRenewal !== null && $tenantRawRenewal !== '') {
            $tenantRenewalAmount = (float) (is_array($tenantRawRenewal) ? ($tenantRawRenewal[0] ?? 0) : $tenantRawRenewal);
        }

        // Resolve Total Amount
        if ($tenantAmount !== null && $tenantAmount > 0) {
            $displayTotalAmount = $tenantAmount;
        } elseif ($plan && (float)($plan->total_amount ?? $plan->price ?? 0) > 0 && (float)($plan->total_amount ?? $plan->price ?? 0) !== 1000.00) {
            $displayTotalAmount = (float)($plan->total_amount ?? $plan->price);
        } else {
            $displayTotalAmount = 50.00;
        }

        // Resolve Renewal Price (must NOT be 0 if renewal amount is configured)
        if ($tenantRenewalAmount !== null && $tenantRenewalAmount > 0) {
            $renewalPrice = $tenantRenewalAmount;
        } elseif ($plan && (float)($plan->renewal_price ?? 0) > 0 && (float)($plan->renewal_price ?? 0) !== 1000.00) {
            $renewalPrice = (float) $plan->renewal_price;
        } elseif ($displayTotalAmount > 0 && (float)$displayTotalAmount !== 1000.00) {
            $renewalPrice = $displayTotalAmount;
        } else {
            $renewalPrice = 49.00;
        }

        $currentCycle = strtolower(trim((string)($plan->duration ?? (function_exists('tenant') ? tenant('billing_cycle') : 'month'))));
        if (empty($currentCycle)) {
            $currentCycle = 'month';
        }

        // Renewal dates
        $renewalStartDateCarbon = ($plan && $plan->end_date && $isFutureOrToday)
            ? \Carbon\Carbon::parse($plan->end_date)
            : \Carbon\Carbon::today();

        $renewalEndDateCarbon = (str_contains($currentCycle, 'month'))
            ? $renewalStartDateCarbon->copy()->addMonth()
            : $renewalStartDateCarbon->copy()->addYear();

        $extendedRenewalStartDate = $renewalStartDateCarbon->format('d-m-Y');
        $extendedRenewalDate = $renewalEndDateCarbon->format('d-m-Y');

        // Subtitle / Purchased By
        $purchasedBy = function_exists('tenant') 
            ? (tenant('client_name') ?? tenant('brand_name')) 
            : ($tenant['client_name'] ?? ($tenant['brand_name'] ?? null));
        $displaySubtitle = $purchasedBy ? 'Purchased By ' . $purchasedBy : ($plan->subtitle ?? 'Purchased By Insurance-CRM');
    @endphp

    <!-- Active Plan Gradient Banner -->
    <div class="card mb-4 border-0" style="background: linear-gradient(to right, #24355c, #d27936); color: white; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
        <div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap">
            <div class="mb-3 mb-md-0">
                <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
                    <h2 class="mb-0 text-white me-3 text-capitalize" style="font-weight: 700;">{{ $displayPlanName }}</h2>
                    @if($planDaysRemaining <= 30)
                    <span class="badge rounded-pill me-2" style="background-color: rgba(243, 156, 18, 0.2); color: #ffd166; border: 1px solid #ffd166; padding: 6px 12px; font-weight: 600;">
                        <i class="fas fa-exclamation-triangle me-1"></i> Expiring Soon ({{ $planDaysRemaining > 0 ? $planDaysRemaining . ' days left' : 'Expired' }})
                    </span>
                    @endif
                    <span class="badge rounded-pill" style="background-color: rgba(255, 255, 255, 0.18); color: #fff; border: 1px solid rgba(255, 255, 255, 0.35); padding: 6px 14px; font-weight: 600; font-size: 12.5px;">
                        Renewal Price: ₹{{ number_format($renewalPrice, 2) }}
                    </span>
                </div>
                <p class="mb-4 text-capitalize" style="opacity: 0.85; font-size: 14px;">{{ $displaySubtitle }}</p>
                <div class="d-flex gap-4 flex-wrap" style="font-size: 13px; font-weight: 500;">
                    <div><i class="far fa-calendar-alt" style="color: #ffd166;"></i> Start Date: {{ $plan && $plan->start_date ? \Carbon\Carbon::parse($plan->start_date)->format('d-m-Y') : date('d-m-Y') }}</div>
                    <div><i class="far fa-calendar-alt" style="color: #ffd166;"></i> End Date: {{ $plan && $plan->end_date ? \Carbon\Carbon::parse($plan->end_date)->format('d-m-Y') : 'N/A' }}</div>
                    <div><i class="fas fa-users" style="color: #ffd166;"></i> User Limit: {{ $plan->user_limit ?? (function_exists('tenant') ? (tenant('max_user') ?? 50) : 50) }}</div>
                    <div><i class="fas fa-building" style="color: #ffd166;"></i> Branch Limit: {{ $plan->branch_limit ?? (function_exists('tenant') ? (tenant('max_branch') ?? 1) : 1) }}</div>
                </div>
            </div>
            
            <div style="background-color: rgba(255,255,255,0.15); padding: 15px 30px; border-radius: 8px; text-align: center; border: 1px solid rgba(255,255,255,0.1); min-width: 170px;">
                <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.85; margin-bottom: 5px; font-weight: 600;">Total Amount</div>
                <h2 class="mb-1 text-white" style="font-weight: 700;">₹{{ number_format($displayTotalAmount, 2) }}</h2>
                <div style="font-size: 13px; color: #ffd166; font-weight: 500; margin-bottom: 5px;">
                    @if(str_contains($currentCycle, 'month'))
                        1 Month
                    @elseif(str_contains($currentCycle, 'year'))
                        1 Year
                    @else
                        {{ ucfirst($currentCycle) }}
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Included Features Grid -->
    @php
        $modulesList = null;
        if ($plan && isset($plan->features) && is_array($plan->features)) {
            $modulesList = $plan->features;
        } elseif (isset($tenant['modules']) && is_array($tenant['modules'])) {
            $modulesList = $tenant['modules'];
        } elseif (function_exists('tenant') && is_array(tenant('modules'))) {
            $modulesList = tenant('modules');
        } else {
            $modulesList = ['Dashboard', 'Client Manage', 'Sales CRM', 'Policy Center', 'Commissions', 'Withdrawal Requests', 'Operations', 'Sub Agent'];
        }
    @endphp
    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; border: 1px solid #f1f1f1 !important;">
        <div class="card-header bg-white border-bottom-0 pt-4 pb-2 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 style="font-weight: 700; color: #333; font-size: 16px;" class="mb-0">
                <i class="fas fa-check-circle text-success me-2" style="font-size: 18px;"></i> Included Features & Modules
            </h5>
            <div class="d-flex align-items-center gap-2">
                @if(is_array($modulesList) && count($modulesList) > 0)
                <span class="badge px-3 py-2 fw-bold text-success" style="background-color: #e6f9ed; border-radius: 20px; font-size: 12px; border: 1px solid #c2f0d4;">
                    <i class="fas fa-cube me-1"></i> {{ count($modulesList) }} Modules / Features Configured
                </span>
                @else
                <span class="badge px-3 py-2 fw-bold text-secondary" style="background-color: #f1f5f9; border-radius: 20px; font-size: 12px; border: 1px solid #cbd5e1;">
                    <i class="fas fa-cube me-1"></i> 0 Modules / Features Configured
                </span>
                @endif
                <button type="button" class="btn btn-sm btn-outline-primary shadow-sm" id="btnSyncAdminModules" onclick="syncSuperAdminModules(this)" style="border-radius: 20px; font-size: 12px; font-weight: 600; padding: 5px 14px; transition: all 0.2s ease;">
                    <i class="fas fa-arrows-rotate me-1"></i> Sync from Admin
                </button>
            </div>
        </div>
        <div class="card-body p-4">
            <div class="row">
            @if(is_array($modulesList) && count($modulesList) > 0)
                @foreach($modulesList as $feature)
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="p-3 h-100 d-flex align-items-center" style="background-color: #fafbfc; border-radius: 8px; border: 1px solid #eef2f6; color: #334155; font-weight: 500; font-size: 13.5px; transition: all 0.2s ease;">
                            <i class="fas fa-check text-success me-2 flex-shrink-0" style="background-color: #e6f9ed; padding: 6px; border-radius: 50%; font-size: 10px;"></i>
                            <span class="text-truncate" title="{{ $feature }}">{{ $feature }}</span>
                        </div>
                    </div>
                @endforeach
            @else
                <div class="col-md-12 mb-3">
                    <div class="p-4 text-center" style="background-color: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1; color: #64748b; font-weight: 500;">
                        <i class="fas fa-info-circle text-muted me-2" style="font-size: 18px;"></i>
                        No active modules or features are currently assigned to this account by the administrator.
                    </div>
                </div>
            @endif
            </div>
        </div>
    </div>

    <!-- Renew Plan Modal -->
    <div class="modal fade" id="renewPlanModal" tabindex="-1" aria-labelledby="renewPlanModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
                <!-- Header with High Contrast Pure White Text -->
                <div class="modal-header" style="background: linear-gradient(135deg, #11223b, #1e3a5f) !important; border-bottom: none; padding: 20px 24px;">
                    <h5 class="modal-title fw-bold text-white d-flex align-items-center mb-0" id="renewPlanModalLabel" style="color: #ffffff !important; font-size: 1.18rem;">
                        <i class="fas fa-sync-alt me-2" style="color: #f59e0b !important;"></i> Renew Current Subscription
                    </h5>
                    <button type="button" class="btn-close-custom btn-close-white-style ms-auto" data-bs-dismiss="modal" aria-label="Close">
                        <i class="fas fa-times" style="color: #ffffff !important;"></i>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <div class="text-center mb-4">
                        <div class="d-inline-flex align-items-center justify-content-center bg-light text-primary rounded-circle mb-2" style="width: 58px; height: 58px;">
                            <i class="fas fa-file-invoice-dollar fs-3" style="color: #11223b;"></i>
                        </div>
                        <h4 class="fw-bold mb-1 text-capitalize" style="color: #11223b;">{{ $displayPlanName }}</h4>
                        <span class="badge bg-light text-secondary border px-3 py-1">Active Plan</span>
                    </div>

                    <div class="bg-light rounded p-3 mb-3 border">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Billing Cycle:</span>
                            <span class="fw-semibold text-capitalize">{{ ucfirst($currentCycle) }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Current Expiry:</span>
                            <span class="fw-semibold">{{ $plan && $plan->end_date ? \Carbon\Carbon::parse($plan->end_date)->format('d-m-Y') : 'N/A' }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Renewal Start Date:</span>
                            <span class="fw-semibold text-dark">{{ $extendedRenewalStartDate }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Extended Expiry Date:</span>
                            <span class="fw-bold text-success">{{ $extendedRenewalDate }}</span>
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold" style="font-size: 16px;">Renewal Payable:</span>
                            <span class="fw-bold text-primary" style="font-size: 22px; color: #d27936 !important;">₹{{ number_format($renewalPrice, 2) }}</span>
                        </div>
                    </div>

                    <div class="alert alert-info py-2 px-3 small d-flex align-items-center mb-0" style="background-color: #e0f2fe; border-color: #bae6fd; color: #0369a1;">
                        <i class="fas fa-shield-alt me-2 fs-5" style="color: #0284c7;"></i>
                        <span>Secure checkout powered by Razorpay. Plan updates immediately upon verification.</span>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn text-white fw-bold px-4" id="btnConfirmRenewal" style="background-color: #11223b;" onclick="processRenewalPayment()">
                        <span class="spinner-border spinner-border-sm me-1 d-none" id="renewalSpinner"></span>
                        <i class="fas fa-lock me-1"></i> Pay ₹{{ number_format($renewalPrice, 2) }} & Renew
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Upgrade Plan Modal -->
    <div class="modal fade" id="upgradePlanModal" tabindex="-1" aria-labelledby="upgradePlanModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
                <div class="modal-header text-white" style="background: linear-gradient(135deg, #11223b, #1e3a5f) !important; border-bottom: none; padding: 20px 24px;">
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-1" id="upgradePlanModalLabel" style="color: #ffffff !important;">
                            <i class="fas fa-rocket me-2" style="color: #f59e0b !important;"></i> Upgrade Your Plan
                        </h5>
                        <p class="mb-0 small text-white-50" style="color: rgba(255, 255, 255, 0.75) !important;">Select a membership package to unlock higher user/branch limits and expanded modules.</p>
                    </div>
                    <button type="button" class="btn-close-custom btn-close-white-style ms-auto" data-bs-dismiss="modal" aria-label="Close">
                        <i class="fas fa-times" style="color: #ffffff !important;"></i>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <div id="upgradeLoadingState" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-3 text-muted">Fetching latest available plans from Super Admin...</p>
                    </div>

                    <div id="upgradeErrorState" class="alert alert-danger d-none" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i> <span id="upgradeErrorMessage">Failed to load plans.</span>
                    </div>

                    <div id="upgradePlansContainer" class="row g-3 d-none">
                        <!-- Dynamically populated membership cards -->
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 px-4 py-3">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Invoices List Modal (Triggered by "Download Invoice" button) -->
    <div class="modal fade" id="invoicesListModal" tabindex="-1" aria-labelledby="invoicesListModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 14px; overflow: hidden;">
                <div class="modal-header text-white" style="background-color: #eb5e28 !important; padding: 18px 24px;">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle p-2 bg-white bg-opacity-25 me-3 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                            <i class="fas fa-file-invoice text-white fs-5"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-white mb-0" id="invoicesListModalLabel" style="color: #ffffff !important;">Download Subscription Invoices</h5>
                            <p class="mb-0 small text-white-50" style="color: rgba(255, 255, 255, 0.8) !important;">Select and download tax invoices & receipts for all your plan purchases and renewals</p>
                        </div>
                    </div>
                    <button type="button" class="btn-close-custom btn-close-white-style ms-auto" data-bs-dismiss="modal" aria-label="Close">
                        <i class="fas fa-times" style="color: #ffffff !important;"></i>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 140px;">Invoice / Order #</th>
                                    <th>Payment Date</th>
                                    <th>Plan / Description</th>
                                    <th>Type</th>
                                    <th>Amount Paid</th>
                                    <th>Status</th>
                                    <th class="text-center" style="width: 160px;">Download</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if(!empty($paymentHistory) && count($paymentHistory) > 0)
                                    @foreach($paymentHistory as $tx)
                                        <tr>
                                            <td>
                                                <span class="badge bg-light text-dark border font-monospace">
                                                    {{ !empty($tx['id']) ? 'INV-' . \Carbon\Carbon::parse($tx['created_at'] ?? now())->format('Y') . '-' . str_pad((string)$tx['id'], 4, '0', STR_PAD_LEFT) : 'INV-CURRENT' }}
                                                </span>
                                            </td>
                                            <td>
                                                <strong>{{ !empty($tx['created_at']) ? \Carbon\Carbon::parse($tx['created_at'])->format('d M Y, h:i A') : 'N/A' }}</strong>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-dark">{{ $displayPlanName }}</div>
                                                <small class="text-muted">{{ $tx['description'] ?? ($tx['razorpay_order_id'] ?? 'Subscription') }}</small>
                                            </td>
                                            <td>
                                                <span class="badge {{ ($tx['payment_type'] ?? '') === 'renewal' ? 'bg-info text-white' : 'bg-primary text-white' }}" style="text-transform: uppercase;">
                                                    {{ $tx['payment_type'] ?? 'Purchase' }}
                                                </span>
                                            </td>
                                            <td class="fw-bold text-dark">
                                                ₹{{ number_format((float) ($tx['amount'] ?? 0), 2) }}
                                            </td>
                                            <td>
                                                @php
                                                    $st = strtolower(trim((string) ($tx['status'] ?? 'paid')));
                                                @endphp
                                                @if(in_array($st, ['paid', 'completed', 'success']))
                                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Paid</span>
                                                @elseif($st === 'pending')
                                                    <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i> Pending</span>
                                                @else
                                                    <span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i> {{ ucfirst($st) }}</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                <a href="{{ route('plans.invoice.pdf', ['transaction_id' => $tx['id'] ?? null]) }}" target="_blank" class="btn btn-sm text-white fw-semibold" style="background-color: #eb5e28; border-radius: 6px; padding: 6px 14px;">
                                                    <i class="fas fa-download me-1"></i> Download PDF
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                @else
                                    <tr>
                                        <td>
                                            <span class="badge bg-light text-dark border font-monospace">
                                                INV-{{ date('Y') }}-{{ str_pad((string)($plan->id ?? 1), 4, '0', STR_PAD_LEFT) }}
                                            </span>
                                        </td>
                                        <td><strong>{{ $plan && $plan->start_date ? \Carbon\Carbon::parse($plan->start_date)->format('d M Y') : date('d M Y') }}</strong></td>
                                        <td><div class="fw-bold text-dark text-capitalize">{{ $displayPlanName }}</div></td>
                                        <td><span class="badge bg-primary text-white">PURCHASE</span></td>
                                        <td class="fw-bold text-dark">₹{{ number_format($displayTotalAmount, 2) }}</td>
                                        <td><span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Active</span></td>
                                        <td class="text-center">
                                            <a href="{{ route('plans.invoice.pdf') }}" target="_blank" class="btn btn-sm text-white fw-semibold" style="background-color: #eb5e28; border-radius: 6px; padding: 6px 14px;">
                                                <i class="fas fa-download me-1"></i> Download PDF
                                            </a>
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 px-4 py-3">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Plan History Modal -->
    <div class="modal fade" id="planHistoryModal" tabindex="-1" aria-labelledby="planHistoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content border-0 shadow" style="border-radius: 12px;">
                <div class="modal-header border-bottom-0 pt-4 pb-2 px-4 d-flex justify-content-between align-items-center">
                    <h5 class="modal-title fw-bold" id="planHistoryModalLabel" style="font-weight: 700; color: #11223b !important;"><i class="fas fa-history text-secondary me-2"></i> Plan & Payment History</h5>
                    <button type="button" class="btn-close-custom btn-close-dark-style ms-auto" data-bs-dismiss="modal" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Plan / Description</th>
                                    <th>Type</th>
                                    <th>Payment Method</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th class="text-center" style="width: 100px;">Invoice / Receipt</th>
                                </tr>
                            </thead>
                            <tbody>
                                @if(!empty($paymentHistory) && count($paymentHistory) > 0)
                                    @foreach($paymentHistory as $tx)
                                        <tr>
                                            <td>
                                                <strong>{{ !empty($tx['created_at']) ? \Carbon\Carbon::parse($tx['created_at'])->format('d M Y, h:i A') : 'N/A' }}</strong>
                                            </td>
                                            <td>
                                                <div class="fw-bold text-dark text-capitalize">{{ $displayPlanName }}</div>
                                                <small class="text-muted">{{ $tx['description'] ?? ($tx['razorpay_order_id'] ?? '-') }}</small>
                                            </td>
                                            <td>
                                                <span class="badge {{ ($tx['payment_type'] ?? '') === 'renewal' ? 'bg-info text-white' : 'bg-primary text-white' }}" style="text-transform: uppercase;">
                                                    {{ $tx['payment_type'] ?? 'Purchase' }}
                                                </span>
                                            </td>
                                            <td>
                                                <i class="fas fa-credit-card me-1 text-muted"></i> {{ $tx['payment_method'] ?? 'Razorpay' }}
                                            </td>
                                            <td class="fw-bold text-dark">
                                                ₹{{ number_format((float) ($tx['amount'] ?? 0), 2) }}
                                            </td>
                                            <td>
                                                @php
                                                    $st = strtolower(trim((string) ($tx['status'] ?? 'pending')));
                                                @endphp
                                                @if(in_array($st, ['paid', 'completed', 'success']))
                                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Paid</span>
                                                @elseif($st === 'pending')
                                                    <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i> Pending</span>
                                                @else
                                                    <span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i> {{ ucfirst($st) }}</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                <a href="{{ route('plans.invoice.pdf', ['transaction_id' => $tx['id'] ?? null]) }}" target="_blank" class="btn btn-sm text-white" style="background-color: #eb5e28; border-radius: 4px; padding: 4px 10px;" title="Download PDF Invoice / Receipt">
                                                    <i class="fas fa-file-pdf me-1"></i> PDF
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                @elseif(!empty($planHistory) && count($planHistory) > 0)
                                    @foreach($planHistory as $historyItem)
                                        <tr>
                                            <td>{{ $historyItem->created_at ? \Carbon\Carbon::parse($historyItem->created_at)->format('d M Y') : 'N/A' }}</td>
                                            <td>
                                                <div class="fw-bold text-capitalize">{{ $historyItem->name }}</div>
                                                <small class="text-muted">{{ $historyItem->start_date ? \Carbon\Carbon::parse($historyItem->start_date)->format('d-m-Y') : '-' }} to {{ $historyItem->end_date ? \Carbon\Carbon::parse($historyItem->end_date)->format('d-m-Y') : '-' }}</small>
                                            </td>
                                            <td><span class="badge bg-primary text-white">SUBSCRIPTION</span></td>
                                            <td>Online</td>
                                            <td class="fw-bold">₹{{ number_format($historyItem->total_amount ?? $historyItem->price ?? 0, 2) }}</td>
                                            <td>
                                                @if($historyItem->is_active)
                                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Active</span>
                                                @else
                                                    <span class="badge bg-secondary">Archived</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                <a href="{{ route('plans.invoice.pdf', ['id' => $historyItem->id]) }}" target="_blank" class="btn btn-sm text-white" style="background-color: #eb5e28; border-radius: 4px; padding: 4px 10px;">
                                                    <i class="fas fa-file-pdf me-1"></i> PDF
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                @else
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-3">No previous transactions found.</td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 px-4 py-3">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
    const currentPlanId = {{ $plan->id ?? 'null' }};
    const currentRenewalPrice = {{ (float) $renewalPrice }};
    const currentBillingDuration = '{{ $currentCycle }}';
    const currentPlanDisplayName = "{{ addslashes($displayPlanName) }}";

    // Helper for SweetAlert
    function notifyAlert(icon, title, text, callback = null) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: icon,
                title: title,
                text: text,
                confirmButtonColor: '#11223b',
                confirmButtonText: 'OK'
            }).then(() => {
                if (callback) callback();
            });
        } else {
            alert(text);
            if (callback) callback();
        }
    }

    // 1. Process Plan Renewal with Razorpay
    function processRenewalPayment() {
        const btn = document.getElementById('btnConfirmRenewal');
        const spinner = document.getElementById('renewalSpinner');
        
        btn.disabled = true;
        spinner.classList.remove('d-none');

        fetch("{{ route('plans.razorpay.createOrder') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({
                amount: currentRenewalPrice,
                payment_type: 'renewal',
                description: 'Plan Renewal: ' + currentPlanDisplayName
            })
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            spinner.classList.add('d-none');

            if (!data.success || !data.order) {
                if (data.message && data.message.includes('credentials not configured')) {
                    notifyAlert('warning', 'Super Admin Notice', 'Razorpay credentials (Key ID and Secret) have not been entered into Super Admin Settings yet. Please configure your Razorpay Key ID and Key Secret in Super Admin Panel -> Settings -> Gateway / Razorpay Settings.');
                } else {
                    notifyAlert('error', 'Initialization Failed', data.message || 'Failed to initialize payment gateway.');
                }
                return;
            }

            const order = data.order;
            const options = {
                key: order.key || order.key_id,
                amount: order.amount,
                currency: order.currency || 'INR',
                name: order.name || 'Plan Renewal',
                description: order.description || 'Renewing active subscription',
                order_id: order.id || order.order_id,
                prefill: order.prefill || {},
                theme: { color: '#11223b' },
                modal: {
                    ondismiss: function() {
                        console.log('Payment modal dismissed by user.');
                    }
                },
                handler: function(response) {
                    verifyPayment({
                        razorpay_order_id: response.razorpay_order_id,
                        razorpay_payment_id: response.razorpay_payment_id,
                        razorpay_signature: response.razorpay_signature,
                        payment_type: 'renewal',
                        amount: currentRenewalPrice,
                        billing_cycle: currentBillingDuration
                    });
                }
            };

            const rzp = new Razorpay(options);
            rzp.on('payment.failed', function(response) {
                notifyAlert('error', 'Payment Failed', response.error.description || 'Transaction declined.');
            });
            rzp.open();
        })
        .catch(err => {
            btn.disabled = false;
            spinner.classList.add('d-none');
            notifyAlert('error', 'Payment Error', 'Error initializing payment: ' + err.message);
        });
    }

    // 2. Load Upgrade Plans dynamically from Super Admin
    function loadUpgradePlans() {
        const loadingState = document.getElementById('upgradeLoadingState');
        const errorState = document.getElementById('upgradeErrorState');
        const container = document.getElementById('upgradePlansContainer');

        loadingState.classList.remove('d-none');
        errorState.classList.add('d-none');
        container.classList.add('d-none');
        container.innerHTML = '';

        fetch("{{ route('plans.upgrade.options') }}", {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(async res => {
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                throw new Error('Server returned HTML instead of JSON: ' + (text.substring(0, 150) || 'Unknown response'));
            }
        })
        .then(res => {
            loadingState.classList.add('d-none');
            const plans = res.available_plans || res.data || [];
            if (!res.success || plans.length === 0) {
                errorState.classList.remove('d-none');
                document.getElementById('upgradeErrorMessage').textContent = res.message || 'No upgrade plans available at this time.';
                return;
            }

            container.classList.remove('d-none');
            plans.forEach(membership => {
                let amount = 0;
                let renewalAmount = 0;
                let cycleTitle = membership.billing_title || membership.billing_cycle || 'Yearly';

                if (typeof membership.amount === 'object' && membership.amount !== null) {
                    amount = membership.amount.yearly || membership.amount.monthly || Object.values(membership.amount)[0] || 0;
                } else if (Array.isArray(membership.amount)) {
                    amount = membership.amount[0] || 0;
                } else {
                    amount = parseFloat(membership.amount || 0);
                }

                if (typeof membership.renewal_amount === 'object' && membership.renewal_amount !== null) {
                    renewalAmount = membership.renewal_amount.yearly || membership.renewal_amount.monthly || Object.values(membership.renewal_amount)[0] || 0;
                } else if (Array.isArray(membership.renewal_amount)) {
                    renewalAmount = membership.renewal_amount[0] || 0;
                } else {
                    renewalAmount = parseFloat(membership.renewal_amount || 0);
                }

                let modules = membership.project_modules_data || [];

                let modulesHtml = '';
                if (modules.length > 0) {
                    modulesHtml = modules.slice(0, 5).map(m => `
                        <div class="small text-muted mb-1">
                            <i class="fas fa-check text-success me-1"></i> ${m.name || m}
                        </div>
                    `).join('');
                    if (modules.length > 5) {
                        modulesHtml += `<div class="small text-primary fw-semibold">+ ${modules.length - 5} more modules</div>`;
                    }
                } else {
                    modulesHtml = '<div class="small text-muted">All standard modules included</div>';
                }

                const cardHtml = `
                    <div class="col-md-4 col-sm-6">
                        <div class="card h-100 border shadow-sm" style="border-radius: 12px; transition: transform 0.2s, box-shadow 0.2s;">
                            <div class="card-body p-4 d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h5 class="fw-bold mb-0 text-dark">${membership.plan_name}</h5>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">Upgrade</span>
                                </div>
                                <div class="my-3">
                                    <span class="fs-2 fw-bold text-dark">₹${parseFloat(amount || 0).toLocaleString('en-IN', {minimumFractionDigits: 2})}</span>
                                    <span class="text-muted small">/ ${cycleTitle}</span>
                                    ${renewalAmount ? `<div class="text-muted small mt-1">Renewal at ₹${parseFloat(renewalAmount).toLocaleString('en-IN', {minimumFractionDigits: 2})}</div>` : ''}
                                </div>
                                <hr class="my-2">
                                <div class="mb-3">
                                    <div class="small text-dark fw-semibold mb-2"><i class="fas fa-users text-primary me-2"></i> Users: ${membership.max_user || 'Unlimited'}</div>
                                    <div class="small text-dark fw-semibold mb-2"><i class="fas fa-building text-primary me-2"></i> Branches: ${membership.max_branch || 'Unlimited'}</div>
                                </div>
                                <div class="mb-4 flex-grow-1">
                                    <div class="small fw-bold text-uppercase text-muted mb-2" style="letter-spacing: 0.5px;">Included Modules</div>
                                    ${modulesHtml}
                                </div>
                                <button type="button" class="btn text-white w-100 fw-bold mt-auto" style="background: linear-gradient(135deg, #11223b, #1e3a5f); border-radius: 8px;" onclick="processUpgradePayment(${membership.id}, ${parseFloat(amount || 0)}, '${membership.plan_name}', '${cycleTitle}')">
                                    <i class="fas fa-arrow-up me-1"></i> Choose & Pay
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                container.insertAdjacentHTML('beforeend', cardHtml);
            });
        })
        .catch(err => {
            loadingState.classList.add('d-none');
            errorState.classList.remove('d-none');
            document.getElementById('upgradeErrorMessage').textContent = 'Error contacting Super Admin: ' + err.message;
        });
    }

    // 3. Process Upgrade Payment with Razorpay
    function processUpgradePayment(membershipId, amount, planName, cycle) {
        fetch("{{ route('plans.razorpay.createOrder') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({
                amount: amount,
                payment_type: 'upgrade',
                membership_id: membershipId,
                billing_cycle: cycle,
                description: 'Upgrade Plan to ' + planName
            })
        })
        .then(res => res.json())
        .then(data => {
            if (!data.success || !data.order) {
                notifyAlert('error', 'Upgrade Error', data.message || 'Failed to initialize payment gateway.');
                return;
            }

            const order = data.order;
            const options = {
                key: order.key || order.key_id,
                amount: order.amount,
                currency: order.currency || 'INR',
                name: 'Upgrade to ' + planName,
                description: 'Subscription Plan Upgrade',
                order_id: order.id || order.order_id,
                prefill: order.prefill || {},
                theme: { color: '#11223b' },
                modal: {
                    ondismiss: function() {
                        console.log('Upgrade payment modal closed.');
                    }
                },
                handler: function(response) {
                    verifyPayment({
                        razorpay_order_id: response.razorpay_order_id,
                        razorpay_payment_id: response.razorpay_payment_id,
                        razorpay_signature: response.razorpay_signature,
                        payment_type: 'upgrade',
                        amount: amount,
                        membership_id: membershipId,
                        billing_cycle: cycle
                    });
                }
            };

            const rzp = new Razorpay(options);
            rzp.on('payment.failed', function(response) {
                notifyAlert('error', 'Payment Failed', response.error.description || 'Transaction declined.');
            });
            rzp.open();
        })
        .catch(err => {
            notifyAlert('error', 'Upgrade Error', 'Error initiating upgrade payment: ' + err.message);
        });
    }

    // 4. Verify payment with client backend + Super Admin API
    function verifyPayment(paymentDetails) {
        const loadingDiv = document.createElement('div');
        loadingDiv.id = 'paymentVerifyingOverlay';
        loadingDiv.style.position = 'fixed';
        loadingDiv.style.top = '0';
        loadingDiv.style.left = '0';
        loadingDiv.style.width = '100vw';
        loadingDiv.style.height = '100vh';
        loadingDiv.style.backgroundColor = 'rgba(0,0,0,0.7)';
        loadingDiv.style.display = 'flex';
        loadingDiv.style.flexDirection = 'column';
        loadingDiv.style.alignItems = 'center';
        loadingDiv.style.justifyContent = 'center';
        loadingDiv.style.zIndex = '99999';
        loadingDiv.innerHTML = `
            <div class="spinner-border text-light mb-3" style="width: 3rem; height: 3rem;" role="status"></div>
            <h4 class="text-white fw-bold">Verifying Payment & Syncing Subscription...</h4>
            <p class="text-white-50">Please do not refresh or close the page.</p>
        `;
        document.body.appendChild(loadingDiv);

        fetch("{{ route('plans.razorpay.verify') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify(paymentDetails)
        })
        .then(res => res.json())
        .then(data => {
            const overlay = document.getElementById('paymentVerifyingOverlay');
            if (overlay) overlay.remove();

            if (data.success) {
                notifyAlert('success', 'Payment Successful!', data.message || 'Payment successfully verified! Your subscription is now updated.', function() {
                    window.location.reload();
                });
            } else {
                notifyAlert('error', 'Verification Failed', data.message || 'Could not verify payment.');
            }
        })
        .catch(err => {
            const overlay = document.getElementById('paymentVerifyingOverlay');
            if (overlay) overlay.remove();
            notifyAlert('error', 'Verification Error', 'Payment verification error: ' + err.message);
        });
    }

    function syncSuperAdminModules(btn) {
        if (!btn) btn = document.getElementById('btnSyncAdminModules');
        const originalHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Syncing...';
        }

        fetch("{{ route('plans.syncModules') }}", {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            }
        })
        .then(res => res.json())
        .then(data => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
            if (data.success) {
                notifyAlert('success', 'Modules Synchronized!', data.message || `Successfully synced ${data.modules_count || ''} modules from Super Admin.`, function() {
                    window.location.reload();
                });
            } else {
                notifyAlert('error', 'Sync Failed', data.message || 'Could not synchronize modules.');
            }
        })
        .catch(err => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
            notifyAlert('error', 'Sync Error', 'Network error while syncing: ' + err.message);
        });
    }
</script>
@endpush
