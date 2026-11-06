<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Policy summary</title><style>
@page { margin: 34px; } body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #30455e; } h1 { font-size: 21px; color: #173653; margin: 0 0 6px; } h2 { font-size: 12px; background: #edf2f8; color: #214769; padding: 9px 10px; margin: 20px 0 8px; } .muted { color: #7b8ba0; } .header { border-bottom: 3px solid #254d77; padding-bottom: 14px; } table { width: 100%; border-collapse: collapse; table-layout: fixed; } td,th { text-align: left; vertical-align: top; padding: 9px; border-bottom: 1px solid #e4eaf2; overflow-wrap: break-word; } th { background: #f6f8fb; font-size: 9px; color: #637a94; } .label { color: #7c8fa5; font-size: 9px; margin-bottom: 4px; } .value { font-weight: bold; } .footer { margin-top: 24px; font-size: 8px; color: #8795a8; } tr { page-break-inside: avoid; }
</style></head><body>
@php
    $date = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('d M Y') : '--';
    $money = fn ($v) => $v === null || $v === '' ? '--' : '₹ '.number_format((float)$v, 2);
@endphp
<div class="header"><h1>Policy summary</h1><strong>{{ $policy->policy_number_no ?: $policy->policy_number }}</strong><div class="muted">{{ $policy->policyType?->name ?? '--' }} · {{ $policy->company?->name ?? '--' }}</div></div>
<h2>Policy holder</h2>
<table><tr><td><div class="label">Name</div><div class="value">{{ $policy->holder?->name ?? '--' }}</div></td><td><div class="label">Phone</div>{{ $policy->holder?->phone ?: '--' }}</td></tr><tr><td><div class="label">Email</div>{{ $policy->holder?->email ?: '--' }}</td><td><div class="label">Date of birth</div>{{ $date($policy->holder?->dob) }}</td></tr><tr><td colspan="2"><div class="label">Address</div>{{ $policy->holder?->address ?: '--' }}</td></tr></table>
<h2>Coverage details</h2><table>
@foreach(array_chunk([
    ['Policy number', $policy->policy_number_no ?: $policy->policy_number], ['Internal reference', $policy->policy_number],
    ['Insurance company', $policy->company?->name], ['Category', $policy->policyType?->name],
    ['Start date', $date($policy->start_date)], ['Expiry date', $date($policy->end_date)],
    ['Sum assured', $money($policy->sum_assured)], ['Policy mode', $policy->policy_mode],
    ['Family group', $family_group?->group_name], ['Assigned staff / agent', $policy->user?->name],
], 2) as $row)<tr>@foreach($row as [$label,$value])<td><div class="label">{{ $label }}</div><div class="value">{{ $value ?: '--' }}</div></td>@endforeach</tr>@endforeach
</table>
<h2>Covered members</h2><table><thead><tr><th>Name</th><th>Relationship</th><th>Date of birth</th><th>Gender</th></tr></thead><tbody>@forelse($members as $member)<tr><td>{{ $member->name }}</td><td>{{ $member->relation ?: '--' }}</td><td>{{ $date($member->dob) }}</td><td>{{ $member->gender ?: '--' }}</td></tr>@empty<tr><td colspan="4" class="muted">No insured member details recorded for this policy.</td></tr>@endforelse</tbody></table>
<h2>Premium & payment</h2><table><tr><th>Premium</th><th>Tax</th><th>Recorded payment amount</th></tr><tr><td>{{ $money($policy->premium_amount) }}</td><td>{{ $money($policy->tax_amount) }}</td><td>{{ $money($policy->payment_amount) }}</td></tr><tr><td><div class="label">Payment date</div>{{ $date($policy->payment_date) }}</td><td colspan="2"><div class="label">Payment mode</div>{{ $policy->payment_mode ?: $policy->payment_type ?: '--' }}</td></tr></table>
<h2>Nominee & notes</h2><table><tr><td><div class="label">Nominee</div>{{ $policy->nominee_name ?: '--' }}</td><td><div class="label">Nominee contact</div>{{ $policy->nominee_contact ?: '--' }}</td></tr><tr><td colspan="2"><div class="label">Remarks</div>{{ $policy->remarks ?: '--' }}</td></tr></table>
@if($vehicle)<h2>Vehicle</h2><table><tr><th>Registration</th><th>Model</th><th>Engine / chassis</th></tr><tr><td>{{ $vehicle->vehicle_reg_no ?: '--' }}</td><td>{{ $vehicle->vehicle_model ?: '--' }}</td><td>{{ $vehicle->engine_no ?: '--' }} / {{ $vehicle->chassis_no ?: '--' }}</td></tr></table>@endif
<div class="footer">Generated {{ now()->format('d M Y H:i') }} · Summary of CRM records. Refer to the insurer-issued policy document for the full policy terms.</div>
</body></html>
