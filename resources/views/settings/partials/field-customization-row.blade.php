@php
    $fname = $field['field_name'];
    $isSystem = !empty($field['system_required']);
    $type = $field['type'] ?? 'text';
@endphp

<tr data-field-name="{{ $fname }}" id="field-row-{{ $fname }}">
    <!-- Default Label -->
    <td>
        <span class="text-dark fw-semibold small default-label-text">{{ $field['default_label'] }}</span>
    </td>

    <!-- Custom Label with Restore Button -->
    <td>
        <div class="input-group input-group-sm">
            <input type="text" 
                name="fields[{{ $fname }}][custom_label]" 
                class="form-control field-custom-label-input" 
                value="{{ $field['custom_label'] }}" 
                placeholder="{{ $field['default_label'] }}"
                data-default="{{ $field['default_label'] }}"
                title="Change display label">
            <button type="button" 
                class="btn btn-outline-secondary btn-restore-default-label" 
                title="Restore default label: {{ $field['default_label'] }}">
                <i class="fa-solid fa-rotate-left"></i>
            </button>
        </div>
    </td>

    <!-- Hidden Visible Flag -->
    <input type="hidden" name="fields[{{ $fname }}][is_visible]" value="{{ !empty($field['is_visible']) ? '1' : '0' }}">

    <!-- Required Toggle -->
    <td class="text-center">
        <div class="position-relative d-inline-flex align-items-center justify-content-center">
            @if($isSystem)
                <input type="hidden" name="fields[{{ $fname }}][is_required]" value="1">
                <div class="form-check form-switch d-inline-block m-0" title="System field must remain required">
                    <input class="form-check-input" type="checkbox" role="switch" checked disabled>
                </div>
                <i class="fa-solid fa-lock text-secondary position-absolute" 
                    style="left: calc(100% + 6px); top: 50%; transform: translateY(-50%); font-size: 0.8rem;" 
                    title="System required"></i>
            @else
                <input type="hidden" name="fields[{{ $fname }}][is_required]" value="0">
                <div class="form-check form-switch d-inline-block m-0">
                    <input class="form-check-input field-required-switch" 
                        type="checkbox" 
                        role="switch" 
                        name="fields[{{ $fname }}][is_required]" 
                        value="1" 
                        {{ !empty($field['is_required']) ? 'checked' : '' }}
                        title="Toggle required status">
                </div>
            @endif
        </div>
        <input type="hidden" name="fields[{{ $fname }}][display_order]" value="{{ $field['display_order'] ?? 0 }}">
    </td>

    <!-- Validation Error Message -->
    <td>
        <input type="text" 
            name="fields[{{ $fname }}][validation_message]" 
            class="form-control form-control-sm field-validation-message-input" 
            value="{{ $field['validation_message'] }}" 
            placeholder="e.g. {{ $field['label'] }} is required.">
    </td>
</tr>

