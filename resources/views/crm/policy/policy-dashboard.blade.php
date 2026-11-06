@extends('layouts.app')
@section('page_title', 'Policy Dashboard')
@push('styles')
<link rel="stylesheet" href="{{ (env('PUBLIC_PATH') ? '/'.trim(env('PUBLIC_PATH'), '/') : '').'/css/customer-dashboard.css' }}?v={{ @file_exists(public_path('css/customer-dashboard.css')) ? filemtime(public_path('css/customer-dashboard.css')) : time() }}">
<style>
    .policy-dashboard-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .policy-dashboard .policy-action { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 37px; padding: 8px 12px; border: 1px solid #d1ddea; border-radius: 9px; background: #f8fafd; color: #466480; font-size: .75rem; font-weight: 600; line-height: 1.3; text-decoration: none; box-shadow: 0 2px 4px rgba(30,55,85,.035); transition: background .15s, border-color .15s, box-shadow .15s; }
    .policy-dashboard .policy-action i { font-size: .95rem; line-height: 1; }
    .policy-dashboard .policy-action:hover { background: #eaf1f9; border-color: #a8bfd9; color: #234c77; box-shadow: 0 3px 8px rgba(30,55,85,.08); }
    .policy-dashboard .policy-action-primary { background: #173b60; color: #fff; border-color: #173b60; }
    .policy-dashboard .policy-action-primary:hover { background: #224d78; color: #fff; border-color: #224d78; }
    .policy-dashboard .policy-action-document { background: #edf3fc; color: #38679b; border-color: #c7d9ef; }
    .policy-dashboard .policy-action-renew { background: #eaf4ef; color: #397b64; border-color: #cadfd4; }
    .policy-dashboard .policy-action-back { background: transparent; box-shadow: none; }
    .policy-dashboard .policy-action:focus-visible { outline: 3px solid #8eb8ec; outline-offset: 3px; }
    [data-theme="dark"] .policy-dashboard .policy-action { background: #293d55; border-color: #425975; color: #d3e2f4; }
    [data-theme="dark"] .policy-dashboard .policy-action-primary { background: #335f8c; }
    @media(max-width:575.98px) { .policy-dashboard-actions { width: 100%; gap: 6px; } .policy-dashboard .policy-action { padding: 8px 10px; font-size: .72rem; } }
.policy-dashboard .policy-panel{border:1px solid #dde5ef;border-radius:16px;background:var(--crm-bg-card,#fff);overflow:hidden;margin-bottom:18px}.policy-dashboard .panel-heading{padding:17px 20px;border-bottom:1px solid #e5eaf1;font-size:.95rem;font-weight:700;margin:0;color:var(--crm-text-dark,#243e58)}.policy-dashboard .policy-field{padding:13px 20px;border-bottom:1px solid #eef1f6;overflow-wrap:anywhere}.policy-dashboard .policy-field span{display:block;color:#788aa0;font-size:.72rem;margin-bottom:5px}.policy-dashboard .policy-field strong{font-size:.84rem;font-weight:550}.policy-dashboard .policy-nav{display:flex;gap:8px;overflow:auto;padding:12px 0;margin-bottom:8px;scrollbar-width:thin}.policy-dashboard .policy-nav a{white-space:nowrap;padding:7px 13px;border:1px solid #dce5ef;border-radius:8px;color:#46678b;text-decoration:none;font-size:.78rem;background:#f5f8fc}.policy-dashboard section{scroll-margin-top:100px}.policy-dashboard .document-row{padding:14px 20px;display:flex;justify-content:space-between;align-items:center;gap:14px;border-bottom:1px solid #edf1f5}.policy-dashboard .document-row small{display:block;color:#7c8b9d;margin-top:5px}.policy-dashboard .customer-summary{min-height:115px}[data-theme="dark"] .policy-dashboard .policy-panel{border-color:#3a475b}[data-theme="dark"] .policy-dashboard .policy-field{border-color:#3a475b}
</style>
<style>
    .policy-dashboard .policy-panel { border-color: #dfe6ef; border-radius: 18px; box-shadow: 0 3px 12px rgba(24,49,80,.035); margin-bottom: 20px; }
    .policy-dashboard .panel-heading { display: flex; align-items: center; gap: 10px; padding: 16px 20px; background: linear-gradient(100deg,#eef3f9,#fbfcfe); border-bottom: 1px solid #e4ebf4; color: #284766; font-size: .9rem; }
    .policy-dashboard .panel-heading::before { content: ''; display: block; width: 4px; height: 21px; background: #7596bb; border-radius: 5px; flex-shrink: 0; }
    .policy-dashboard .policy-panel > .row { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 10px; padding: 16px; margin: 0; }
    .policy-dashboard .policy-panel > .row > .policy-field { width: auto; padding: 13px 15px; border: 1px solid #e9eef5; border-radius: 10px; background: linear-gradient(125deg,#f7f9fc,#fff); }
    .policy-dashboard .policy-field span { color: #7b8da2; font-size: .8rem; margin-bottom: 7px; }
    .policy-dashboard .policy-field strong { color: #2c4763; font-size: .94rem; font-weight: 600; line-height: 1.5; }
    .policy-dashboard .panel-heading { font-size: 1rem; }
    .policy-dashboard .customer-record-table td { font-size: .92rem; }
    .policy-dashboard .customer-record-table th { font-size: .8rem; }
    .policy-dashboard .document-row strong { font-size: .92rem; }
    .policy-dashboard .document-row small { font-size: .8rem; }
    .policy-dashboard .policy-nav { gap: 7px; margin-bottom: 13px; }
    .policy-dashboard .policy-nav a { border-color: #e0e7f0; background: #fff; box-shadow: 0 2px 4px #18315005; transition: background .15s; }
    .policy-dashboard .policy-nav a:hover { background: #eaf1fa; color: #244f7e; }
    .policy-dashboard .document-row { margin: 10px 16px; border: 1px solid #e6ecf4; border-radius: 11px; background: #f9fbfd; padding: 13px 15px; }
    .policy-dashboard .customer-record-table th { background: #f6f8fc; padding: 13px 20px; }
    .policy-dashboard .customer-record-table td { padding: 14px 20px; }
    .policy-dashboard .customer-record-table td[colspan] { background: #fbfcfe; color: #8a98aa !important; font-size: .8rem; }
    [data-theme="dark"] .policy-dashboard .panel-heading { background: linear-gradient(100deg,#273c54,#223046); color: #dce7f5; border-color: #3a475b; }
    [data-theme="dark"] .policy-dashboard .policy-panel > .row > .policy-field,
    [data-theme="dark"] .policy-dashboard .document-row { background: #233247; border-color: #35465c; }
    [data-theme="dark"] .policy-dashboard .policy-field strong { color: #dce7f5; }
    [data-theme="dark"] .policy-dashboard .policy-nav a { background: #25374e; border-color: #40516b; color: #c0d3e9; }
    [data-theme="dark"] .policy-dashboard .customer-record-table td[colspan] { background: #233247; }
    @media(max-width:991.98px) { .policy-dashboard .policy-panel > .row { grid-template-columns: repeat(2,minmax(0,1fr)); } }
    @media(max-width:575.98px) { .policy-dashboard .policy-panel > .row { grid-template-columns: 1fr; padding: 12px; gap: 8px; } .policy-dashboard .panel-heading { padding: 14px; } }
</style>
@endpush
@section('content')
@php
    $formatDate = fn ($value) => $value ? \Carbon\Carbon::parse($value)->format('d M Y') : '--';
    $money = fn ($value) => $value !== null && $value !== '' ? '₹'.number_format((float)$value, 2) : '--';
    $end = $policy->end_date ? \Carbon\Carbon::parse($policy->end_date)->startOfDay() : null;
    $start = $policy->start_date ? \Carbon\Carbon::parse($policy->start_date)->startOfDay() : null;
    $status = !$end ? 'Date unavailable' : ($end->lt(today()) ? 'Expired' : ($start?->gt(today()) ? 'Upcoming' : 'Active'));
    $canEdit = auth()->user()->isAdmin() || auth()->user()->hasMatrixPermission('edit_policies');
    $canDelete = auth()->user()->isAdmin() || auth()->user()->hasMatrixPermission('delete_policies');
    $fieldGroups = [
        'Policy & coverage' => ['Policy number' => $policy->policy_number_no ?: $policy->policy_number, 'Internal reference' => $policy->policy_number, 'Category' => $policy->policyType?->name, 'Subcategory' => $policy->policySubType?->name, 'Company' => $policy->company?->name, 'Branch' => $policy->branch?->name, 'Segment' => $policy->segment?->name, 'Start date' => $formatDate($policy->start_date), 'Expiry date' => $formatDate($policy->end_date), 'Sum assured' => $money($policy->sum_assured), 'Mode' => $policy->policy_mode, 'Assigned staff / agent' => $assignedStaff?->name, 'Created by' => $policy->creator?->name, 'Created on' => $formatDate($policy->created_at)],
        'Premium & payment' => ['Premium' => $money($policy->premium_amount), 'Tax' => $money($policy->tax_amount), 'Payment amount recorded' => $money($policy->payment_amount), 'Payment mode' => $policy->payment_mode ?: $policy->payment_type, 'Payment date' => $formatDate($policy->payment_date), 'Own damage premium' => $money($policy->premium_own_damage), 'Liability premium' => $money($policy->premium_liability), 'Add-ons premium' => $money($policy->addons_premium), 'Net premium' => $money($policy->net_premium)],
        'Nominee & servicing' => ['Nominee' => $policy->nominee_name, 'Nominee contact' => $policy->nominee_contact, 'Agent' => $policy->agent_name, 'Agent code' => $policy->agent_code, 'Broker contact' => $policy->broker_contact, 'Insurer claim email' => $policy->insurer_claim_email, 'Waiting period' => $policy->waiting_period, 'Deductible' => $policy->deductible, 'Maturity date' => $formatDate($policy->maturity_date), 'Remarks' => $policy->remarks],
    ];
@endphp
<div class="policy-dashboard customer-dashboard">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <div><div class="customer-profile-eyebrow">Policy dashboard</div><h1 class="h4 fw-bold mb-1">{{ $policy->policy_number_no ?: $policy->policy_number }}</h1><p class="small text-muted mb-0">{{ $policy->policyType?->name ?? 'Insurance policy' }} · {{ $policy->company?->name ?? '--' }}</p></div>
        <div class="policy-dashboard-actions">
            @if($policy->policy_pdf)<a class="policy-action policy-action-document" href="{{ route('policies.legacy.documents.view', $policy->id) }}" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>Policy PDF</a>@endif
            @if($canEdit)<a class="policy-action policy-action-primary" href="{{ route('policies.legacy.edit', $policy->id) }}"><i class="bi bi-pencil-square" aria-hidden="true"></i>Edit policy</a><a class="policy-action policy-action-renew" href="{{ route('fablead.ai.renewal', $policy->id) }}"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Renew</a>@endif
            <a class="policy-action" href="{{ route('policies.legacy.invoice', $policy->id) }}" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text" aria-hidden="true"></i>Policy summary PDF</a>
            <a class="policy-action policy-action-back" href="{{ route('policies.legacy.index') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back</a>
        </div>
    </div>
    <div class="row g-3 mb-3">
        @foreach([['Status', $status, 'green'], ['Premium', $money($policy->premium_amount), 'blue'], ['Sum assured', $money($policy->sum_assured), 'purple'], ['Covered members', $membersAvailable ? $policy->members->count() : 'Unavailable', 'blue'], ['Expiry', $formatDate($policy->end_date), 'amber']] as [$label,$value,$tone])
        <div class="col-6 col-xl"><div class="customer-summary {{ $tone }}"><span>{{ $label }}</span><div class="my-2"><strong>{{ $value }}</strong></div>@if($label === 'Expiry' && $end)<small>{{ $end->lt(today()) ? 'Expired '.today()->diffInDays($end).' days ago' : 'Due in '.today()->diffInDays($end).' days' }}</small>@endif</div></div>
        @endforeach
    </div>
    <nav class="policy-nav" aria-label="Policy sections"><a href="#policy-holder">Holder</a><a href="#policy-coverage">Coverage & payment</a><a href="#policy-members">Covered members</a><a href="#policy-documents">Documents & renewals</a><a href="#policy-claims">Claims</a><a href="#policy-additional">Additional details</a></nav>
    <section class="policy-panel" id="policy-holder"><h2 class="panel-heading"><i class="bi bi-person me-2"></i>Policy holder</h2><div class="row g-0">
        @foreach(['Name' => $policy->holder?->name, 'Phone' => $policy->holder?->phone, 'Email' => $policy->holder?->email, 'WhatsApp' => $policy->holder?->whatsapp, 'Date of birth' => $formatDate($policy->holder?->dob), 'Family group' => $policy->familyGroup?->group_name, 'Address' => $policy->holder?->address] as $label=>$value)<div class="col-md-4 policy-field"><span>{{ $label }}</span><strong>{{ $value ?: '--' }}</strong></div>@endforeach
    </div>@if($policy->holder && \Illuminate\Support\Facades\Gate::allows('view', $policy->holder))<div class="p-3"><a class="customer-pdf-button" href="{{ route('masters.customers.show', $policy->holder) }}">Open customer dashboard <i class="bi bi-arrow-up-right"></i></a></div>@endif</section>
    <section id="policy-coverage">@foreach($fieldGroups as $title=>$fields)<div class="policy-panel"><h2 class="panel-heading"><i class="bi {{ $title === 'Policy & coverage' ? 'bi-shield-check' : ($title === 'Premium & payment' ? 'bi-wallet2' : 'bi-person-check') }}" aria-hidden="true"></i>{{ $title }}</h2><div class="row g-0">@foreach($fields as $label=>$value)<div class="col-md-4 policy-field"><span>{{ $label }}</span><strong>{{ $value !== null && $value !== '' ? $value : '--' }}</strong></div>@endforeach</div></div>@endforeach</section>
    <section class="policy-panel" id="policy-members"><h2 class="panel-heading">Covered members <span class="text-muted small">· This policy only</span></h2>
        <div class="table-responsive customer-record-table"><table class="table mb-0"><thead><tr><th>Name</th><th>Relationship</th><th>Date of birth</th><th>Gender</th></tr></thead><tbody>@forelse($policy->members as $member)<tr><td>{{ $member->name }}</td><td>{{ $member->relation ?: '--' }}</td><td>{{ $formatDate($member->dob) }}</td><td>{{ $member->gender ?: '--' }}</td></tr>@empty<tr><td colspan="4" class="text-muted text-center py-4">{{ $membersAvailable ? 'No insured members recorded for this policy.' : 'Covered member details are temporarily unavailable.' }}</td></tr>@endforelse</tbody></table></div>
        @if($policy->addons->isNotEmpty())<h3 class="panel-heading">Additional covers</h3><div class="row g-0">@foreach($policy->addons as $addon)<div class="col-md-4 policy-field"><span>{{ $addon->cover_name }}</span><strong>{{ $money($addon->premium_amount) }}</strong></div>@endforeach</div>@endif
    </section>
    <section class="policy-panel" id="policy-documents"><div class="panel-heading d-flex justify-content-between align-items-center gap-2"><span>Documents & renewal history</span>@if($canEdit)<button class="customer-pdf-button" type="button" id="uploadPolicyPdfBtn"><i class="bi bi-upload"></i>Upload PDF</button><input type="file" id="uploadPolicyPdfInput" class="d-none" accept="application/pdf,.pdf">@endif</div>
        @include('crm.policy.renewal-term-history')
        @forelse($policyDocuments as $document)<div class="document-row flex-wrap"><div><strong class="small">{{ $document->label ?: ucfirst($document->document_type ?? 'Policy').' PDF' }}</strong><small>{{ $formatDate($document->document_date ?: $document->created_at) }} · {{ $document->uploader?->name ?? '--' }} · {{ $document->policy?->policy_number_no ?: $document->policy?->policy_number }}</small>@if($document->comment)<small>{{ $document->comment }}</small>@endif</div><div class="d-flex gap-2"><a class="customer-pdf-button" target="_blank" rel="noopener" href="{{ route('policies.legacy.documents.history.view', $document) }}"><i class="bi bi-file-earmark-pdf"></i>View PDF</a>@if($canDelete)<button class="customer-pdf-button delete-policy-pdf" data-document-id="{{ $document->id }}" data-file-name="{{ $document->original_file_name ?: $document->file_name }}" type="button">Delete</button>@endif</div></div>@empty<p class="p-3 text-muted small mb-0">No document history recorded.</p>@endforelse
    </section>
    <section class="policy-panel" id="policy-claims"><h2 class="panel-heading">Claim tracker</h2><div class="table-responsive customer-record-table"><table class="table mb-0"><thead><tr><th>Claim</th><th>Insured member</th><th>Amount</th><th>Received</th><th>Submitted</th><th>Status</th></tr></thead><tbody>@forelse($claims as $claim)<tr><td><a href="{{ route('claim-tracker.show', $claim) }}">#{{ $claim->id }}</a></td><td>{{ $claim->insuredMember?->member_name ?? '--' }}</td><td>{{ $money($claim->claim_amount) }}</td><td>{{ $formatDate($claim->claim_file_received_date) }}</td><td>{{ $formatDate($claim->claim_file_submit_date) }}</td><td>{{ $claim->claim_status }}</td></tr>@empty<tr><td colspan="6" class="text-center text-muted py-4">No claims recorded for this policy.</td></tr>@endforelse</tbody></table></div></section>
    <section id="policy-additional">
        @php
            $extraFields = collect(['product_name','business_type','coverage_type','reference','agent_code','vehicle_make','variant','vehicle_class','seating_capacity','vehicle_color','vehicle_idv','cng_lpg_unit','tp_end_date','financier_name','financier_branch','agreement_number'])
                ->filter(fn ($field) => $policy->$field !== null && $policy->$field !== '');
        @endphp
        @if($extraFields->isNotEmpty())<div class="policy-panel"><h2 class="panel-heading">Product & additional coverage details</h2><div class="row g-0">@foreach($extraFields as $field)<div class="col-md-4 policy-field"><span>{{ ucwords(str_replace('_',' ',$field)) }}</span><strong>{{ $policy->$field }}</strong></div>@endforeach</div></div>@endif
        @if($policy->isVehiclePolicy())<div class="policy-panel"><h2 class="panel-heading">Vehicle details</h2><div class="row g-0">@foreach($vehicleDetails as $label=>$value)<div class="col-md-4 policy-field"><span>{{ ucwords(str_replace('_',' ',$label)) }}</span><strong>{{ $value ?: '--' }}</strong></div>@endforeach</div></div>@endif
        <div class="policy-panel"><h2 class="panel-heading"><i class="bi bi-person-vcard" aria-hidden="true"></i>KYC record</h2><div class="row g-0"><div class="col-md-4 policy-field"><span>Status</span><strong>{{ $kyc?->kyc_status ?? 'Not recorded' }}</strong></div>@if($kyc)@foreach(['aadhaar_card','pan_card','driving_licence','photo','signature'] as $field)<div class="col-md-4 policy-field"><span>{{ ucwords(str_replace('_',' ',$field)) }}</span><strong>{{ $kyc->$field ? 'On file' : 'Not recorded' }}</strong></div>@endforeach @endif</div></div>
        @if(auth()->user()->isAdmin() || auth()->user()->hasMatrixPermission('view_commission_rules'))<div class="policy-panel"><h2 class="panel-heading"><i class="bi bi-cash-stack" aria-hidden="true"></i>Commission records</h2><div class="table-responsive customer-record-table"><table class="table mb-0"><thead><tr><th>Date</th><th>Commission</th><th>Pay in</th><th>Pay out</th><th>Status</th></tr></thead><tbody>@forelse($commissions as $record)<tr><td>{{ $formatDate($record->entry_date) }}</td><td>{{ $money($record->commission_amount) }}</td><td>{{ $money($record->pay_in_amount) }}</td><td>{{ $money($record->pay_out_amount) }}</td><td>{{ $record->payment_status }}</td></tr>@empty<tr><td colspan="5" class="text-center text-muted py-4">No commission entries recorded.</td></tr>@endforelse</tbody></table></div></div>@endif
    </section>
</div>
@endsection
@push('scripts')
@include('crm.policy.policy-dashboard-actions')
@endpush
