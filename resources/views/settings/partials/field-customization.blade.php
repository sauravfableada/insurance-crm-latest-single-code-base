<div class="settings-panel mb-4">
    <div class="settings-panel-head d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
        <div>
            <span class="d-flex align-items-center gap-2">
                <i class="fa-solid fa-sliders text-primary"></i> Module-Wise Field Customization
            </span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-dark border px-3 py-2">
                <i class="fa-solid fa-shield-halved text-success me-1"></i> Admin Controlled
            </span>
        </div>
    </div>

    <div class="settings-panel-body">
        <p class="text-muted small mb-3">
            Manage field labels, form visibility, mandatory validation rules, and display ordering separately for each CRM module.
            Changes persist across user sessions and dynamically apply to all relevant create, edit, update, detail, and listing pages without modifying database columns.
        </p>

        <!-- Module Selector Tabs (Horizontal Scroller styled like main settings tabs) -->
        <div class="field-custom-modules-wrap mb-4">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <label class="form-label fw-bold small text-muted text-uppercase mb-0">
                    <i class="fa-solid fa-layer-group text-primary me-1"></i> Select CRM Module:
                </label>
                <div class="d-flex align-items-center gap-1 field-custom-scroll-nav">
                    <button type="button" class="field-custom-scroll-btn" id="fieldModScrollLeft" title="Scroll left">
                        <i class="fa-solid fa-chevron-left"></i>
                    </button>
                    <button type="button" class="field-custom-scroll-btn" id="fieldModScrollRight" title="Scroll right">
                        <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>
            </div>
            <div class="field-custom-scroller-track">
                <div class="field-custom-modules-scroller" id="fieldCustomizationModulePills" role="tablist">
                    @forelse($fieldCustomizationModules as $modKey => $modInfo)
                        <button type="button" 
                            class="field-custom-mod-btn {{ $modKey === $initialFieldCustomizationModule ? 'active' : '' }}"
                            data-module="{{ $modKey }}"
                            id="mod-pill-{{ $modKey }}">
                            <i class="fa-solid {{ $modInfo['icon'] }} field-mod-icon"></i>
                            <span>{{ $modInfo['name'] }}</span>
                        </button>
                    @empty
                        <div class="text-muted small py-2 px-3">No active modules available for field customization in your current plan.</div>
                    @endforelse
                </div>
            </div>
        </div>

        @if(!empty($fieldCustomizationModules) && isset($fieldCustomizationModules[$initialFieldCustomizationModule]))
        <!-- Module Configuration Form -->
        <form id="fieldCustomizationForm">
            @csrf
            <input type="hidden" name="current_module" id="fieldCustomCurrentModule" value="{{ $initialFieldCustomizationModule }}">

            <!-- Active Module Header -->
            <div class="p-3 bg-light rounded-3 border mb-3">
                <h6 class="fw-bold mb-1 d-flex align-items-center gap-2">
                    <span id="fieldCustomActiveModuleName">{{ $fieldCustomizationModules[$initialFieldCustomizationModule]['name'] ?? 'Module' }}</span>
                    <span class="badge bg-secondary font-monospace" id="fieldCustomActiveModuleKey">{{ $initialFieldCustomizationModule }}</span>
                </h6>
                <small class="text-muted" id="fieldCustomActiveModuleDesc">
                    {{ $fieldCustomizationModules[$initialFieldCustomizationModule]['description'] ?? '' }}
                </small>
            </div>

            <!-- Fields Table -->
            <div class="table-responsive border rounded-3 bg-white">
                <table class="table table-hover align-middle mb-0" id="fieldCustomizationTable">
                    <thead class="table-light text-muted small text-uppercase">
                        <tr>
                            <th style="width: 220px;">Default Label</th>
                            <th style="min-width: 260px;">Custom Label</th>
                            <th style="width: 130px;" class="text-center">Required</th>
                            <th style="min-width: 280px;">Validation Error Message</th>
                        </tr>
                    </thead>
                    <tbody id="fieldCustomizationTableBody">
                        @foreach($initialFieldCustomizationFields as $field)
                            @include('settings.partials.field-customization-row', ['field' => $field, 'module' => $initialFieldCustomizationModule])
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Bottom Action Bar -->
            <div class="d-flex justify-content-between align-items-center mt-3">
                <small class="text-muted">
                    <i class="fa-solid fa-circle-info me-1"></i> System-required fields are marked with a lock icon and cannot be hidden or disabled to ensure data integrity.
                </small>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btnResetFieldCustomizationBottom">
                        <i class="fa-solid fa-rotate-left me-1"></i> Reset to Default
                    </button>
                    <button type="submit" class="btn btn-sm btn-dark-blue px-3" id="btnSaveFieldCustomizationBottom">
                        <i class="fa-solid fa-check me-1"></i> <span id="btnSaveFieldCustomLabel">Save Changes</span>
                    </button>
                </div>
            </div>
        </form>
        @else
        <div class="alert alert-info py-3 px-4 rounded-3 border-0 shadow-sm mt-3">
            <i class="fa-solid fa-circle-info me-2"></i> No configurable modules are available in your current subscription plan.
        </div>
        @endif
    </div>
</div>

