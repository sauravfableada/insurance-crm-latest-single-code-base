@php $renewalDays = $policy['renewal_days'] ?? null; @endphp
<div class="policy-number-reminder">
    <small class="d-block text-muted">{{ $policy['display_policy_number'] ?? $policy['policy_number'] ?? '--' }}</small>
    @if($renewalDays !== null && $renewalDays >= 0 && $renewalDays <= 90)
        <div class="d-flex align-items-center gap-2 mt-1">
            <span class="policy-renewal-pulse"><span aria-hidden="true"></span>{{ $renewalDays === 0 ? 'Due today' : 'Renewal in '.$renewalDays.' days' }}</span>
            <button type="button" class="policy-whatsapp-reminder" data-draft-url="{{ route('whatsapp.web.draft',$policy['id']) }}" title="Draft WhatsApp renewal reminder" aria-label="Draft WhatsApp renewal reminder"><i class="bi bi-whatsapp" aria-hidden="true"></i></button>
        </div>
    @endif
</div>
