<div class="settings-panel">
    <div class="settings-panel-head d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <span><i class="fa-solid fa-bell me-2 text-primary"></i> Module Notifications (Email & WhatsApp)</span>
            <div class="text-muted small mt-1">Enable or disable automated notification alerts per CRM module.</div>
        </div>
    </div>

    <div class="settings-panel-body p-3">
        <!-- Sub-Tabs for Email vs WhatsApp Notification (styled like WhatsApp Configuration subtabs) -->
        <ul class="nav settings-subtabs mb-4 border-bottom" id="notificationSubTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="email-notif-subtab" data-bs-toggle="pill"
                    data-bs-target="#email-notif-content" type="button" role="tab">
                    Email Notification
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="whatsapp-notif-subtab" data-bs-toggle="pill"
                    data-bs-target="#whatsapp-notif-content" type="button" role="tab">
                    WhatsApp Notification
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
                            @endphp
                            <div class="col-12 col-md-6 col-xl-4">
                                <div class="card border rounded-3 shadow-sm p-3 bg-white h-100">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="rounded-circle d-flex align-items-center justify-content-center text-white flex-shrink-0"
                                                style="width: 36px; height: 36px; background-color: {{ $modInfo['color'] ?? '#3b82f6' }};">
                                                <i class="fa-solid {{ $modInfo['icon'] }} small"></i>
                                            </span>
                                            <div>
                                                <h6 class="mb-0 fw-bold">{{ $modInfo['name'] }}</h6>
                                                <small class="text-muted" style="font-size: 11px;">
                                                    {{ $modKey === 'customers' ? 'Send to Client' : 'Send to Staff / Sub-Agent' }}
                                                </small>
                                            </div>
                                        </div>
                                        <div class="form-check form-switch m-0">
                                            <input type="hidden" name="notif_email_{{ $modKey }}_enabled" value="0">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                name="notif_email_{{ $modKey }}_enabled" value="1"
                                                id="notif_email_{{ $modKey }}_switch" {{ $emailEnabled ? 'checked' : '' }}
                                                onchange="this.nextElementSibling.innerText = this.checked ? 'Enabled' : 'Disabled'">
                                            <label class="form-check-label small fw-semibold" for="notif_email_{{ $modKey }}_switch">
                                                {{ $emailEnabled ? 'Enabled' : 'Disabled' }}
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="text-end mt-4">
                        <button type="submit" class="btn btn-dark-blue px-4 rounded-pill">
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
                        <span>WhatsApp notifications automatically send updates when records are created or assigned.</span>
                    </div>

                    <div class="row g-3">
                        @foreach($notificationModules as $modKey => $modInfo)
                            @php
                                $waEnabled = ($settings["notif_wa_{$modKey}_enabled"]->value ?? '1') === '1';
                            @endphp
                            <div class="col-12 col-md-6 col-xl-4">
                                <div class="card border rounded-3 shadow-sm p-3 bg-white h-100">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="rounded-circle d-flex align-items-center justify-content-center text-white flex-shrink-0"
                                                style="width: 36px; height: 36px; background-color: {{ $modInfo['color'] ?? '#10b981' }};">
                                                <i class="fa-solid {{ $modInfo['icon'] }} small"></i>
                                            </span>
                                            <div>
                                                <h6 class="mb-0 fw-bold">{{ $modInfo['name'] }}</h6>
                                                <small class="text-muted" style="font-size: 11px;">
                                                    {{ $modKey === 'customers' ? 'Send to Client' : 'Send to Staff / Sub-Agent' }}
                                                </small>
                                            </div>
                                        </div>
                                        <div class="form-check form-switch m-0">
                                            <input type="hidden" name="notif_wa_{{ $modKey }}_enabled" value="0">
                                            <input class="form-check-input" type="checkbox" role="switch"
                                                name="notif_wa_{{ $modKey }}_enabled" value="1"
                                                id="notif_wa_{{ $modKey }}_switch" {{ $waEnabled ? 'checked' : '' }}
                                                onchange="this.nextElementSibling.innerText = this.checked ? 'Enabled' : 'Disabled'">
                                            <label class="form-check-label small fw-semibold" for="notif_wa_{{ $modKey }}_switch">
                                                {{ $waEnabled ? 'Enabled' : 'Disabled' }}
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="text-end mt-4">
                        <button type="submit" class="btn btn-dark-blue px-4 rounded-pill">
                            <i class="fa-solid fa-save me-1"></i> Save WhatsApp Notification Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
