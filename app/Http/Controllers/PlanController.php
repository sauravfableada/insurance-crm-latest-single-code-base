<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PlanController extends Controller
{
    /**
     * Display current active plan details, limits, and billing history.
     */
    public function myplan()
    {
        $user = auth()->user();
        $tenant = function_exists('tenant') ? tenant() : null;
        $clientId = $tenant['client_id'] ?? null;

        $apiUrl = config('tenant.api_url');
        $superAdminBase = $apiUrl ? preg_replace('#/api/tenant/?$#i', '', $apiUrl) : '';

        // 1. Get or initialize local active Plan
        $plan = $user->getActivePlan();

        if (!$plan && $tenant) {
            $plan = Plan::create([
                'name'          => $tenant['plan_name'] ?? 'Starter Plan',
                'price'         => $tenant['amount'] ?? 1000.00,
                'renewal_price' => $tenant['renewal_amount'] ?? 0.00,
                'total_amount'  => $tenant['amount'] ?? 1000.00,
                'total_price'   => $tenant['amount'] ?? 1000.00,
                'duration'      => (str_contains(strtolower($tenant['billing_cycle'] ?? ''), 'month')) ? 'month' : 'year',
                'start_date'    => !empty($tenant['start_date']) ? Carbon::parse($tenant['start_date'])->format('Y-m-d') : now()->format('Y-m-d'),
                'end_date'      => !empty($tenant['expiry_date']) ? Carbon::parse($tenant['expiry_date'])->format('Y-m-d') : now()->addYear()->format('Y-m-d'),
                'subtitle'      => 'Purchased By ' . ($tenant['client_name'] ?? ($tenant['brand_name'] ?? ($user->name ?? 'Company'))),
                'user_limit'    => $tenant['max_user'] ?? 10,
                'branch_limit'  => $tenant['max_branch'] ?? 1,
                'is_active'     => true,
                'features'      => !empty($tenant['modules']) && is_array($tenant['modules']) ? array_values($tenant['modules']) : [
                    'Dashboard', 'Client Manage', 'Sales CRM', 'Policy Center', 'Commissions', 'Withdrawal Requests', 'Operations', 'Sub Agent'
                ],
            ]);

            $user->update(['plan_id' => $plan->id]);
        } elseif (!$plan) {
            // Local fallback plan
            $plan = Plan::firstOrCreate(
                ['is_active' => true],
                [
                    'name'          => 'Starter Plan',
                    'price'         => 1000.00,
                    'renewal_price' => 0.00,
                    'total_amount'  => 1000.00,
                    'total_price'   => 1000.00,
                    'duration'      => 'month',
                    'start_date'    => now()->format('Y-m-d'),
                    'end_date'      => now()->addYear()->format('Y-m-d'),
                    'subtitle'      => 'Purchased By ' . ($user->name ?? 'Company'),
                    'user_limit'    => 10,
                    'branch_limit'  => 1,
                    'is_active'     => true,
                    'features'      => [
                        'Dashboard', 'Client Manage', 'Sales CRM', 'Policy Center', 'Commissions', 'Withdrawal Requests', 'Operations', 'Sub Agent'
                    ],
                ]
            );
            $user->update(['plan_id' => $plan->id]);
        }

        // 2. Sync latest validated subscription dates from Super Admin tenant payload if available
        if ($tenant && !empty($tenant['expiry_date']) && $plan) {
            $tenantExpiry = Carbon::parse($tenant['expiry_date'])->format('Y-m-d');
            $tenantStart = !empty($tenant['start_date']) ? Carbon::parse($tenant['start_date'])->format('Y-m-d') : null;

            $needsUpdate = false;
            $updateData = [];

            if ($plan->end_date !== $tenantExpiry) {
                $updateData['end_date'] = $tenantExpiry;
                $needsUpdate = true;
            }
            if ($tenantStart && $plan->start_date !== $tenantStart) {
                $updateData['start_date'] = $tenantStart;
                $needsUpdate = true;
            }
            if (!empty($tenant['billing_cycle']) && $plan->duration !== (str_contains(strtolower($tenant['billing_cycle']), 'month') ? 'month' : 'year')) {
                $updateData['duration'] = str_contains(strtolower($tenant['billing_cycle']), 'month') ? 'month' : 'year';
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                $plan->update($updateData);
                $plan->refresh();
            }
        }

        $paymentHistory = [];

        // 3. Fetch official transactions/payments from Super Admin for this client
        if ($clientId && $superAdminBase) {
            try {
                $resp = Http::timeout(6)->withHeaders([
                    'X-Tenant-Secret' => config('tenant.secret'),
                ])->post("{$superAdminBase}/api/tenant/billing-history", [
                    'client_id' => $clientId,
                ]);

                if ($resp->successful() && !empty($resp->json('data'))) {
                    $paymentHistory = $resp->json('data');
                }
            } catch (\Throwable $e) {
                Log::warning('Failed fetching billing history from Super Admin: ' . $e->getMessage());
            }
        }

        // 4. Local fallback if Super Admin transactions could not be fetched
        if (empty($paymentHistory)) {
            $planHistory = Plan::latest('id')->get();
        } else {
            $planHistory = [];
        }

        return view('plans.myplan', compact('user', 'plan', 'planHistory', 'paymentHistory', 'tenant'));
    }

    public function myPlanDetails()
    {
        return $this->myplan();
    }

    /**
     * Get available upgrade plans from Super Admin (or local fallback).
     */
    public function getUpgradePlans(Request $request)
    {
        $tenant = function_exists('tenant') ? tenant() : null;
        $apiUrl = config('tenant.api_url');
        $superAdminBase = $apiUrl ? preg_replace('#/api/tenant/?$#i', '', $apiUrl) : '';
        $memberships = [];

        if ($superAdminBase) {
            try {
                $resp = Http::timeout(6)->get("{$superAdminBase}/api/memberships", [
                    'status' => 'Active',
                ]);

                if ($resp->successful() && !empty($resp->json('data'))) {
                    $memberships = $resp->json('data');
                }
            } catch (\Throwable $e) {
                Log::warning('Failed fetching memberships from Super Admin: ' . $e->getMessage());
            }
        }

        $currentPlan = auth()->user()->getActivePlan();

        return response()->json([
            'success'         => true,
            'current_plan'    => $currentPlan,
            'tenant'          => $tenant,
            'available_plans' => $memberships,
        ]);
    }

    /**
     * Create Razorpay order via Super Admin's Razorpay API (dynamically fetching Admin keys).
     */
    public function createRazorpayOrder(Request $request)
    {
        $validated = $request->validate([
            'payment_type'  => 'required|in:renewal,upgrade',
            'amount'        => 'required|numeric|min:1',
            'membership_id' => 'nullable|integer',
            'billing_cycle' => 'nullable|string',
        ]);

        $tenant = function_exists('tenant') ? tenant() : null;
        $clientId = $tenant['client_id'] ?? null;
        $apiUrl = config('tenant.api_url');
        $superAdminBase = $apiUrl ? preg_replace('#/api/tenant/?$#i', '', $apiUrl) : '';

        if (!$superAdminBase) {
            return response()->json([
                'success' => false,
                'message' => 'Super Admin API URL is not configured in TENANT_API_URL.',
            ], 400);
        }

        $user = auth()->user();
        $payload = [
            'amount'       => (float) $validated['amount'],
            'client_id'    => $clientId,
            'payment_type' => $validated['payment_type'],
            'name'         => $tenant['client_name'] ?? ($tenant['brand_name'] ?? $user->name),
            'email'        => $user->email,
            'contact'      => $user->phone ?? ($user->whatsapp ?? ''),
            'description'  => $validated['payment_type'] === 'renewal'
                ? 'Plan renewal payment for ' . ($tenant['client_name'] ?? $user->name)
                : 'Plan upgrade payment for ' . ($tenant['client_name'] ?? $user->name),
        ];

        if ($validated['payment_type'] === 'upgrade' && !empty($validated['membership_id'])) {
            $payload['membership_id'] = $validated['membership_id'];
            $payload['billing_cycle'] = $validated['billing_cycle'] ?? 'yearly';
        }

        try {
            $resp = Http::timeout(10)->post("{$superAdminBase}/api/razorpay/create-order", $payload);

            if ($resp->successful() && !empty($resp->json('data'))) {
                return response()->json([
                    'success' => true,
                    'order'   => $resp->json('data'),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $resp->json('message') ?? 'Failed to initialize payment gateway order.',
                'error'   => $resp->json(),
            ], 400);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to connect to Super Admin payment gateway: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify payment with Super Admin and synchronously sync client's local DB.
     */
    public function verifyRazorpayPayment(Request $request)
    {
        $validated = $request->validate([
            'razorpay_order_id'   => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature'  => 'required|string',
            'payment_type'        => 'required|in:renewal,upgrade',
            'amount'              => 'required|numeric',
            'membership_id'       => 'nullable|integer',
            'billing_cycle'       => 'nullable|string',
        ]);

        $tenant = function_exists('tenant') ? tenant() : null;
        $clientId = $tenant['client_id'] ?? null;
        $apiUrl = config('tenant.api_url');
        $superAdminBase = $apiUrl ? preg_replace('#/api/tenant/?$#i', '', $apiUrl) : '';

        try {
            // 1. Verify payment with Super Admin API
            $verifyResp = Http::timeout(12)->post("{$superAdminBase}/api/razorpay/verify-payment", [
                'razorpay_order_id'   => $validated['razorpay_order_id'],
                'razorpay_payment_id' => $validated['razorpay_payment_id'],
                'razorpay_signature'  => $validated['razorpay_signature'],
                'client_id'           => $clientId,
                'payment_type'        => $validated['payment_type'],
                'amount'              => $validated['amount'],
                'billing_cycle'       => $validated['billing_cycle'] ?? null,
            ]);

            if (!$verifyResp->successful() || !$verifyResp->json('success')) {
                Log::warning('Super Admin Razorpay verification rejected', [
                    'status'   => $verifyResp->status(),
                    'response' => $verifyResp->json() ?? $verifyResp->body(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $verifyResp->json('message') ?? 'Payment verification failed at Super Admin.',
                ], 400);
            }

            // 2. Clear tenant lookup cache so next request fetches fresh dates from Super Admin
            if ($tenant && !empty($tenant['domain'])) {
                app(\App\Services\Tenancy\TenantManager::class)->forgetCachedHost($tenant['domain']);
            }

            // 3. Update the local client tenant database immediately & accurately
            $currentPlan = auth()->user()->getActivePlan()
                ?? auth()->user()->getLatestPlan()
                ?? Plan::latest('id')->first();
            $now = Carbon::now();

            if ($validated['payment_type'] === 'renewal') {
                $cycle = $currentPlan ? strtolower(trim((string)$currentPlan->duration)) : 'year';
                if (!empty($validated['billing_cycle'])) {
                    $cycle = strtolower(trim((string)$validated['billing_cycle']));
                }

                $today = Carbon::today();
                $currentEndDate = ($currentPlan && !empty($currentPlan->end_date))
                    ? Carbon::parse($currentPlan->end_date)->startOfDay()
                    : null;

                $isActivePlan = ($currentEndDate && $currentEndDate->greaterThanOrEqualTo($today));
                $renewalStartDate = $isActivePlan
                    ? $currentEndDate->toDateString()
                    : $today->toDateString();

                $baseDateForExpiry = Carbon::parse($renewalStartDate);
                $newEndDate = (str_contains($cycle, 'month'))
                    ? $baseDateForExpiry->copy()->addMonth()->toDateString()
                    : $baseDateForExpiry->copy()->addYear()->toDateString();

                if ($currentPlan) {
                    $currentPlan->update([
                        'is_active'    => 1,
                        'duration'     => str_contains($cycle, 'month') ? 'month' : 'year',
                        'start_date'   => $renewalStartDate,
                        'end_date'     => $newEndDate,
                        'total_amount' => $validated['amount'],
                    ]);

                    User::where('id', auth()->id())->update([
                        'plan_id' => $currentPlan->id,
                    ]);
                }
            } elseif ($validated['payment_type'] === 'upgrade' && !empty($validated['membership_id'])) {
                // Fetch the upgraded membership info
                $membershipResp = Http::timeout(6)->get("{$superAdminBase}/api/memberships/{$validated['membership_id']}");
                $membershipData = $membershipResp->successful() ? $membershipResp->json('data') : null;

                $planName = $membershipData['plan_name'] ?? 'Upgraded Plan';
                $features = [];
                if (!empty($membershipData['project_modules_data'])) {
                    $features = array_column($membershipData['project_modules_data'], 'name');
                }

                $cycle = strtolower(trim((string) ($validated['billing_cycle'] ?? 'year')));
                $newEndDate = (str_contains($cycle, 'month'))
                    ? $now->copy()->addMonth()->format('Y-m-d')
                    : $now->copy()->addYear()->format('Y-m-d');

                Plan::where('is_active', 1)->update(['is_active' => 0]);

                $newPlan = Plan::create([
                    'name'          => $planName,
                    'price'         => $validated['amount'],
                    'final_price'   => $validated['amount'],
                    'total_amount'  => $validated['amount'],
                    'total_price'   => $validated['amount'],
                    'renewal_price' => !empty($membershipData['renewal_amount']) ? (is_array($membershipData['renewal_amount']) ? reset($membershipData['renewal_amount']) : $membershipData['renewal_amount']) : null,
                    'duration'      => (str_contains($cycle, 'month')) ? 'month' : 'year',
                    'start_date'    => $now->format('Y-m-d'),
                    'end_date'      => $newEndDate,
                    'subtitle'      => 'Upgraded via Razorpay Checkout',
                    'user_limit'    => $membershipData['max_user'] ?? null,
                    'branch_limit'  => $membershipData['max_branch'] ?? null,
                    'is_active'     => 1,
                    'features'      => $features,
                ]);

                User::where('id', auth()->id())->update([
                    'plan_id' => $newPlan->id,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment verified and plan updated successfully!',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error during payment processing: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate Tax Invoice / Receipt PDF for a subscription plan matching design.
     */
    public function downloadInvoicePdf($id = null)
    {
        $user = auth()->user();
        $plan = $user->getActivePlan() ?? Plan::latest('id')->first();

        if (!$plan) {
            return redirect()->back()->with('error', 'Plan not found.');
        }

        $apiUrl = config('tenant.api_url');
        $superAdminBase = $apiUrl ? preg_replace('#/api/tenant/?$#i', '', $apiUrl) : '';
        $tenant = function_exists('tenant') ? tenant() : [];
        $productId = $tenant['product_id'] ?? null;
        $projectId = $tenant['project_id'] ?? null;
        $clientId = $tenant['client_id'] ?? null;

        $companyDetails = [];
        $termsList = [];

        if ($superAdminBase) {
            try {
                $resp = Http::timeout(5)->get($superAdminBase . '/api/invoice-details', [
                    'product_id' => $productId,
                    'project_id' => $projectId,
                ]);
                if ($resp->successful() && !empty($resp->json('data'))) {
                    $data = $resp->json('data');
                    $companyDetails = $data['company_details'] ?? [];
                    $termsList = $data['terms'] ?? [];
                }
            } catch (\Throwable $e) {
                Log::warning('Invoice details fetch error: ' . $e->getMessage());
            }
        }

        $transactionId = request('transaction_id') ?: ($id && is_numeric($id) && $id > 10 ? $id : null);
        $transactionData = null;

        if ($clientId && $transactionId && $superAdminBase) {
            try {
                $txResp = Http::timeout(5)->withHeaders([
                    'X-Tenant-Secret' => config('tenant.secret'),
                ])->post("{$superAdminBase}/api/tenant/billing-history", [
                    'client_id'      => $clientId,
                    'transaction_id' => $transactionId,
                ]);

                if ($txResp->successful() && !empty($txResp->json('data'))) {
                    $transactionData = $txResp->json('data')[0] ?? null;
                }
            } catch (\Throwable $e) {
                Log::warning('Failed fetching specific transaction: ' . $e->getMessage());
            }
        }

        if (empty($companyDetails['company_name'])) {
            $companyDetails['company_name'] = 'FABLEAD DEVELOPERS TECHNOLAB';
        }
        if (empty($companyDetails['address'])) {
            $companyDetails['address'] = 'Ascon Plaza, A/5001, Behind Bulka Bhavan School, Adajan, Surat, Gujarat 395009';
        }
        if (empty($companyDetails['contact'])) {
            $companyDetails['contact'] = '9898989898';
        }
        if (empty($companyDetails['email'])) {
            $companyDetails['email'] = 'info@fableadtechnolabs.com';
        }

        if (empty($termsList)) {
            $termsList = [
                'This invoice is valid for the period mentioned in the order details.',
                'Payment is non-refundable once the service is activated.',
                'Renewal will be applicable as per the mentioned amount and validity.',
                'For any support or query, please contact us at the provided email or phone number.',
            ];
        }

        $customerName = $tenant['client_name'] ?? $user->name;
        $companyName = $tenant['brand_name'] ?? ($tenant['client_name'] ?? ($user->company_name ?? $customerName));
        $customerPhone = $user->phone ?? ($user->whatsapp ?? ($tenant['phone'] ?? ''));
        $customerEmail = $user->email;

        $invoiceYear = date('Y');
        $invoiceNo = $transactionData && !empty($transactionData['id'])
            ? 'INV-' . Carbon::parse($transactionData['created_at'])->format('Y') . '-' . str_pad((string)$transactionData['id'], 4, '0', STR_PAD_LEFT)
            : 'INV-' . $invoiceYear . '-' . str_pad((string)$plan->id, 4, '0', STR_PAD_LEFT);

        $orderId = $transactionData['razorpay_order_id'] ?? ($transactionData['id'] ?? (string)$plan->id);
        $orderDate = $transactionData && !empty($transactionData['created_at'])
            ? Carbon::parse($transactionData['created_at'])->format('d M Y')
            : ($plan->start_date ? Carbon::parse($plan->start_date)->format('d M Y') : date('d M Y'));

        $paymentMethod = $transactionData['payment_method'] ?? 'Razorpay';
        $paymentStatus = 'Completed (Paid)';

        if ($transactionData) {
            $plan = clone $plan;
            if (!empty($transactionData['amount'])) {
                $plan->total_amount = (float) $transactionData['amount'];
                $plan->price = (float) $transactionData['amount'];
            }
            if (!empty($transactionData['plan_name'])) {
                $plan->name = $transactionData['plan_name'];
            }
            if (!empty($transactionData['billing_cycle'])) {
                $plan->duration = $transactionData['billing_cycle'];
            }
            if (!empty($transactionData['period_start'])) {
                $plan->start_date = $transactionData['period_start'];
            }
            if (!empty($transactionData['period_end'])) {
                $plan->end_date = $transactionData['period_end'];
            }
            if (!empty($transactionData['payment_type'])) {
                $plan->subtitle = ucfirst($transactionData['payment_type']) . ' Payment';
            }
        }

        $validity = ($plan->start_date ? Carbon::parse($plan->start_date)->format('Y-m-d') : date('Y-m-d')) . ' to ' . ($plan->end_date ? Carbon::parse($plan->end_date)->format('Y-m-d') : date('Y-m-d'));

        $featuredModules = [];
        if (!empty($tenant['modules']) && is_array($tenant['modules'])) {
            $featuredModules = array_values($tenant['modules']);
        } elseif (!empty($plan->features) && is_array($plan->features)) {
            $featuredModules = array_values($plan->features);
        } else {
            $featuredModules = ['Client Manage', 'Sales CRM', 'Policy Center', 'Commissions', 'Withdrawal Requests', 'Operations', 'Sub Agent'];
        }

        $productTitle = $tenant['product_code'] ?? 'Insurance CRM';

        $pdfData = [
            'company'          => $companyDetails,
            'customer'         => [
                'name'    => $customerName,
                'company' => $companyName,
                'phone'   => $customerPhone,
                'email'   => $customerEmail,
            ],
            'order'            => [
                'status'         => $paymentStatus,
                'invoice_no'     => $invoiceNo,
                'order_id'       => $orderId,
                'date'           => $orderDate,
                'payment_method' => $paymentMethod,
                'validity'       => $validity,
            ],
            'plan'             => $plan,
            'product_title'    => $productTitle,
            'featured_modules' => $featuredModules,
            'terms'            => $termsList,
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('plans.invoice_pdf', $pdfData)
            ->setPaper('A4', 'portrait')
            ->setOptions([
                'defaultFont'          => 'DejaVu Sans',
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => true,
            ]);

        return $pdf->download("invoice-{$invoiceNo}.pdf");
    }
}

