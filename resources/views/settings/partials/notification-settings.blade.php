<div class="settings-panel">
    <div class="settings-panel-head d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <span><i class="fa-solid fa-bell me-2 text-primary"></i> Module Notifications (Email & WhatsApp)</span>
            <div class="text-muted small mt-1">Enable or disable automated notification alerts to assigned staff / sub-agents per CRM module.</div>
        </div>
    </div>

    <div class="settings-panel-body p-3">
        <!-- Sub-Tabs for Email vs WhatsApp Notification -->
        <ul class="nav nav-pills mb-4 gap-2" id="notificationSubTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active px-4 py-2 rounded-pill fw-semibold" id="email-notif-subtab" data-bs-toggle="pill"
                    data-bs-target="#email-notif-content" type="button" role="tab">
                    <i class="fa-solid fa-envelope me-2 text-primary"></i> Email Notification
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link px-4 py-2 rounded-pill fw-semibold" id="whatsapp-notif-subtab" data-bs-toggle="pill"
                    data-bs-target="#whatsapp-notif-content" type="button" role="tab">
                    <i class="fa-brands fa-whatsapp me-2 text-success"></i> WhatsApp Notification
                </button>
            </li>
        </ul>

        <div class="tab-content" id="notificationSubTabContent">
            <!-- ================= EMAIL NOTIFICATIONS SUB-TAB ================= -->
            <div class="tab-pane fade show active" id="email-notif-content" role="tabpanel">
                <form action="{{ route('settings.update') }}" method="POST">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_tab" value="notifications">

                    <div class="row g-3">
                        @foreach($notificationModules as $modKey => $modInfo)
                            @php
                                $emailEnabled = ($settings["notif_email_{$modKey}_enabled"]->value ?? '1') === '1';
                                $emailSubject = $settings["notif_email_{$modKey}_subject"]->value ?? $modInfo['email_subject'];
                                $emailBody = $settings["notif_email_{$modKey}_body"]->value ?? $modInfo['email_body'];
                            @endphp
                            <div class="col-12 col-xl-6">
                                <div class="card border rounded-3 shadow-sm h-100 p-3 bg-white">
                                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="rounded-circle d-flex align-items-center justify-content-center text-white"
                                                style="width: 32px; height: 32px; background-color: {{ $modInfo['color'] ?? '#3b82f6' }};">
                                                <i class="fa-solid {{ $modInfo['icon'] }} small"></i>
                                            </span>
                                            <div>
                                                <h6 class="mb-0 fw-bold">{{ $modInfo['name'] }}</h6>
                                                <small class="text-muted">Staff / Sub-Agent Alert on Action</small>
                                            </div>
                                        </div>
                                        <div class="form-check form-switch m-0">
                                            <input type="hidden" name="notif_email_{{ $modKey }}_enabled" value="0">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                name="notif_email_{{ $modKey }}_enabled" value="1"
                                                id="notif_email_{{ $modKey }}_switch" {{ $emailEnabled ? 'checked' : '' }}>
                                            <label class="form-check-label small fw-semibold" for="notif_email_{{ $modKey }}_switch">
                                                {{ $emailEnabled ? 'Enabled' : 'Disabled' }}
                                            </label>
                                        </div>
                                    </div>

                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold text-secondary mb-1">Email Subject</label>
                                        <input type="text" name="notif_email_{{ $modKey }}_subject" class="form-control form-control-sm"
                                            value="{{ $emailSubject }}">
                                    </div>

                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold text-secondary mb-1">Email Template Body</label>
                                        <textarea name="notif_email_{{ $modKey }}_body" rows="4" class="form-control form-control-sm"
                                            style="font-family: monospace; font-size: 12px;">{{ $emailBody }}</textarea>
                                    </div>

                                    <div class="small text-muted" style="font-size: 11px;">
                                        Placeholders: <code>{Staff_Name}</code>, <code>{Firm_Name}</code>, <code>{Client_Name}</code>, <code>{Policy_No}</code>, <code>{Lead_Name}</code>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="text-end mt-4">
                        <button type="submit" class="btn btn-primary px-4 rounded-pill">
                            <i class="fa-solid fa-save me-1"></i> Save Email Notification Settings
                        </button>
                    </div>
                </form>
            </div>

            <!-- ================= WHATSAPP NOTIFICATIONS SUB-TAB ================= -->
            <div class="tab-pane fade" id="whatsapp-notif-content" role="tabpanel">
                <form action="{{ route('settings.update') }}" method="POST">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_tab" value="notifications">

                    <div class="alert alert-info py-2 px-3 small d-flex align-items-center mb-3">
                        <i class="fa-solid fa-info-circle me-2 fs-5"></i>
                        <span>WhatsApp notifications use your configured WhatsApp mode (Personal WhatsApp bridge or WhatsApp Cloud API) to send automated updates directly to assigned Staff / Sub-Agents when records are created or assigned.</span>
                    </div>

                    <div class="row g-3">
                        @foreach($notificationModules as $modKey => $modInfo)
                            @php
                                $waEnabled = ($settings["notif_wa_{$modKey}_enabled"]->value ?? '1') === '1';
                                $waTemplate = $settings["notif_wa_{$modKey}_template"]->value 
                                    ?? ($modKey === 'leads' ? ($settings['whatsapp_personal_lead_message']->value ?? $modInfo['wa_message']) : $modInfo['wa_message']);
                            @endphp
                            <div class="col-12 col-xl-6">
                                <div class="card border rounded-3 shadow-sm h-100 p-3 bg-white">
                                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="rounded-circle d-flex align-items-center justify-content-center text-white"
                                                style="width: 32px; height: 32px; background-color: {{ $modInfo['color'] ?? '#10b981' }};">
                                                <i class="fa-solid {{ $modInfo['icon'] }} small"></i>
                                            </span>
                                            <div>
                                                <h6 class="mb-0 fw-bold">{{ $modInfo['name'] }}</h6>
                                                <small class="text-muted">Staff / Sub-Agent Alert on Action</small>
                                            </div>
                                        </div>
                                        <div class="form-check form-switch m-0">
                                            <input type="hidden" name="notif_wa_{{ $modKey }}_enabled" value="0">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                name="notif_wa_{{ $modKey }}_enabled" value="1"
                                                id="notif_wa_{{ $modKey }}_switch" {{ $waEnabled ? 'checked' : '' }}>
                                            <label class="form-check-label small fw-semibold" for="notif_wa_{{ $modKey }}_switch">
                                                {{ $waEnabled ? 'Enabled' : 'Disabled' }}
                                            </label>
                                        </div>
                                    </div>

                                    <div class="mb-2">
                                        <label class="form-label small fw-semibold text-secondary mb-1">WhatsApp Message Template</label>
                                        <textarea name="notif_wa_{{ $modKey }}_template" rows="5" class="form-control form-control-sm"
                                            style="font-family: monospace; font-size: 12px;">{{ $waTemplate }}</textarea>
                                    </div>

                                    <div class="small text-muted" style="font-size: 11px;">
                                        Placeholders: <code>{Staff_Name}</code>, <code>{Firm_Name}</code>, <code>{Client_Name}</code>, <code>{Policy_No}</code>, <code>{Lead_Name}</code>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="text-end mt-4">
                        <button type="submit" class="btn btn-primary px-4 rounded-pill">
                            <i class="fa-solid fa-save me-1"></i> Save WhatsApp Notification Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
