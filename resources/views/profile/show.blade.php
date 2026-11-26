@extends('layouts.app')

@section('page_title', 'Profile')

@push('styles')
<link rel="stylesheet" href="{{ (env('PUBLIC_PATH') ? '/' . trim(env('PUBLIC_PATH'), '/') . '/css/profile.css' : '/css/profile.css') }}?v={{ filemtime(public_path('css/profile.css')) }}">
@endpush

@section('content')
@php
    $roleName = auth()->user()->isAdmin()
        ? 'Administrator'
        : (auth()->user()->roles->first()?->name
            ? \Illuminate\Support\Str::headline(auth()->user()->roles->first()->name)
            : (auth()->user()->job_title ?: 'Staff'));

    $defaultAvatar = 'https://ui-avatars.com/api/?name=' . urlencode($user->name) . '&background=random&color=fff';
    $defaultLogo = (env('PUBLIC_PATH') ? '/' . trim(env('PUBLIC_PATH'), '/') . '/images/template/crmfavicon.png' : '/images/template/crmfavicon.png');
    
    $avatarUrl = !empty($user->avatar_path)
        ? route('users.image', $user) . '?v=' . optional($user->updated_at)->timestamp
        : $defaultAvatar;

    $companyLogoPath = $settings['company_logo_path'] ?? null;
    $companyLogoUrl = $companyLogoPath && \Illuminate\Support\Facades\Storage::disk('public')->exists($companyLogoPath)
        ? route('profile.company_logo.image') . '?v=' . \Illuminate\Support\Facades\Storage::disk('public')->lastModified($companyLogoPath)
        : $defaultLogo;

    $shortCompanyLogoPath = $settings['short_company_logo_path'] ?? null;
    $shortCompanyLogoUrl = $shortCompanyLogoPath && \Illuminate\Support\Facades\Storage::disk('public')->exists($shortCompanyLogoPath)
        ? route('profile.short_company_logo.image') . '?v=' . \Illuminate\Support\Facades\Storage::disk('public')->lastModified($shortCompanyLogoPath)
        : $defaultLogo;
@endphp

<div class="profile-shell">
    <div class="profile-hero">
        <div class="profile-hero-card d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <img src="{{ $avatarUrl }}" onerror="this.onerror=null;this.src='{{ $defaultAvatar }}';" alt="{{ $user->name }}" class="profile-avatar-mini">
                <div>
                    <h4 class="profile-hero-name">{{ $user->name }}</h4>
                    <div class="text-muted">({{ ucfirst($roleName) }})</div>
                </div>
            </div>
            <button type="button" class="profile-dark-btn profile-password-btn" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                <i class="bi bi-key-fill"></i>
                <span>Change Password</span>
            </button>
        </div>

        @if($googleConnectionEnabled ?? false)
        <div class="profile-hero-card d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="profile-google-icon">G</span>
                <div>
                    <h3 class="profile-hero-subtitle">{{ $googleCalendarConnected ? 'Google Connected' : 'Connect To Google' }}</h3>
                    <div class="text-muted">{{ $user->email }}</div>
                </div>
            </div>
            <div id="profileGoogleAction">
                @if($googleCalendarConnected)
                    <form method="POST" action="{{ route('profile.google.disconnect') }}" class="m-0 google-disconnect-form">
                        @csrf
                        <button type="submit" class="profile-dark-btn profile-google-btn" style="min-width: 180px;">
                            <i class="bi bi-google"></i>
                            <span>Disconnect Google</span>
                        </button>
                    </form>
                @else
                    <a href="{{ route('google.auth') }}" class="profile-dark-btn profile-google-btn" style="min-width: 180px;">
                        <i class="bi bi-link-45deg"></i>
                        <span>Connect to Google</span>
                    </a>
                @endif
            </div>
        </div>
        @endif
    </div>

    <form id="profileForm" method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data">
        @csrf
        <div class="card profile-card">
            <div class="profile-card-head">UPDATE PROFILE</div>
            <div class="card-body p-3 p-md-4">
                <div class="profile-section">
                    <div class="profile-section-label">USER INFORMATION</div>
                    <div class="row g-4 align-items-start">
                        <div class="col-lg-3 profile-image-col" style="display: flex;flex-direction: column;justify-content: center;align-items: center;">
                            <h5 class="profile-image-title">Profile Image</h5>
                            
                                <img src="{{ $avatarUrl }}" onerror="this.onerror=null;this.src='{{ $defaultAvatar }}';" alt="Profile" class="profile-circle-image" id="avatar-preview">
                            
                        </div>
                        <div class="col-lg-9">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Name</label>
                                    <input type="text" name="name" value="{{ old('name', $user->name) }}" class="form-control @error('name') is-invalid @enderror">
                                    @error('name')<div class="profile-field-error">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">User Name</label>
                                    <input type="text" name="username" value="{{ old('username', $user->username) }}" data-original-value="{{ $user->username }}" data-ignore-user-id="{{ $user->id }}" data-context="profile" class="form-control @error('username') is-invalid @enderror" placeholder="Enter username" autocomplete="off">
                                    <div class="username-availability-feedback mt-1"></div>
                                    @error('username')<div class="profile-field-error">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Email address</label>
                                    <input type="email" name="email" value="{{ old('email', $user->email) }}" class="form-control @error('email') is-invalid @enderror">
                                    @error('email')<div class="profile-field-error">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Contact No.</label>
                                    <input type="text" name="phone" value="{{ old('phone', $user->phone) }}" class="form-control @error('phone') is-invalid @enderror">
                                    @error('phone')<div class="profile-field-error">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label fw-semibold">Profile Image (Upload)</label>
                                    <input type="file" name="avatar" id="avatar-input" accept="image/*" class="form-control @error('avatar') is-invalid @enderror">
                                    @error('avatar')<div class="profile-field-error">{{ $message }}</div>@enderror
                                    <small class="text-muted">Any image file - max 2 MB.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                @if(auth()->user()->isAdmin())
                <div class="profile-section">
                    <div class="profile-section-label">COMPANY INFORMATION</div>
                    <div class="row g-4 align-items-start">
                        <div class="col-lg-3 profile-image-col"style="display: flex;flex-direction: column;justify-content: center;align-items: center;">
                            <h5 class="profile-image-title">Company Logo</h5>
                            <div class="profile-circle-image">
                                <img src="{{ $companyLogoUrl }}" onerror="this.onerror=null;this.src='{{ $defaultLogo }}';" alt="Company Logo" id="company-logo-preview" style=" overflow: hidden; ">
                            </div>
                        </div>
                        <div class="col-lg-9">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Company Name</label>
                                    <input type="text" name="company_name" value="{{ old('company_name', $settings['company_name'] ?? '') }}" class="form-control @error('company_name') is-invalid @enderror">
                                    @error('company_name')<div class="profile-field-error">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Company Tax No.</label>
                                    <input type="text" name="company_tax_id" value="{{ old('company_tax_id', $settings['company_tax_id'] ?? '') }}" class="form-control @error('company_tax_id') is-invalid @enderror">
                                    @error('company_tax_id')<div class="profile-field-error">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label fw-semibold">Company Address</label>
                                    <input type="text" name="company_address" value="{{ old('company_address', $settings['company_address'] ?? '') }}" class="form-control @error('company_address') is-invalid @enderror">
                                    @error('company_address')<div class="profile-field-error">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Company Logo (Upload)</label>
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="file" name="company_logo_path" id="company-logo-input" accept="image/*" class="form-control @error('company_logo_path') is-invalid @enderror">
                                        <img src="{{ $companyLogoUrl }}" onerror="this.onerror=null;this.src='{{ $defaultLogo }}';" id="company-logo-preview-small" style="height: 38px; width: 38px; object-fit: contain; border: 1px solid #ddd; border-radius: 4px;">
                                    </div>
                                    @error('company_logo_path')<div class="profile-field-error">{{ $message }}</div>@enderror
                                    <small class="text-muted">Any image file - max 2 MB.</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Short Company Logo (Upload)</label>
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="file" name="short_company_logo_path" id="short-company-logo-input" accept="image/*" class="form-control @error('short_company_logo_path') is-invalid @enderror">
                                        <img src="{{ $shortCompanyLogoUrl ?? $defaultLogo }}" onerror="this.onerror=null;this.src='{{ $defaultLogo }}';" id="short-company-logo-preview" style="height: 38px; width: 38px; object-fit: contain; border: 1px solid #ddd; border-radius: 4px;">
                                    </div>
                                    @error('short_company_logo_path')<div class="profile-field-error">{{ $message }}</div>@enderror
                                    <small class="text-muted">Square image recommended - max 2 MB.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                <div class="profile-submit-wrap">
                    <button type="submit" class="btn btn-dark-blue profile-submit-btn">Update</button>
                </div>
            </div>
        </div>
    </form>

    @if(!$user->isAdmin())
    <form id="kycForm" method="POST" action="{{ route('profile.kyc.update') }}" enctype="multipart/form-data">
        @csrf
        <div class="card profile-card mt-4">
            <div class="profile-card-head d-flex justify-content-between align-items-center">
                <span>KYC DETAILS</span>
                @if(!empty($kyc?->kyc_status))
                    <span class="badge bg-success-subtle text-success fs-6 fw-normal px-3 py-1">
                        {{ ucfirst($kyc->kyc_status) }}
                    </span>
                @endif
            </div>
            <div class="card-body p-3 p-md-4">
                <div class="profile-section">
                    <div class="profile-section-label">IDENTITY &amp; BANKING DOCUMENTS</div>
                    <p class="text-muted small mb-4">Upload clear scanned photos or PDF documents of your Aadhaar Card, PAN Card, Bank Passbook, and Bank Cheque.</p>
                    
                    <div class="row g-4">
                        @php
                            $kycFields = [
                                'aadhaar_card' => ['title' => 'Aadhaar Card', 'icon' => 'fa-id-card', 'hint' => 'Upload Aadhaar Card (Front/Back)'],
                                'pan_card'     => ['title' => 'PAN Card',     'icon' => 'fa-address-card', 'hint' => 'Upload PAN Card'],
                                'passbook'     => ['title' => 'Bank Passbook','icon' => 'fa-book', 'hint' => 'Upload Bank Passbook (first page with account details)'],
                                'cheque'       => ['title' => 'Bank Cheque',  'icon' => 'fa-money-check', 'hint' => 'Upload Cancelled Cheque / Cheque Leaf'],
                            ];
                        @endphp

                        @foreach($kycFields as $fieldKey => $info)
                            @php
                                $val = $kyc?->{$fieldKey};
                                $files = [];
                                if (!empty($val)) {
                                    $decoded = is_string($val) ? json_decode($val, true) : (array) $val;
                                    $files = is_array($decoded) ? $decoded : [$val];
                                }
                            @endphp
                            <div class="col-md-6">
                                <div class="p-3 border rounded-3 bg-light h-100 d-flex flex-column justify-content-between">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <i class="fa-solid {{ $info['icon'] }} text-primary fs-5"></i>
                                            <label class="form-label fw-bold mb-0 text-dark">{{ $info['title'] }}</label>
                                        </div>
                                        <p class="text-muted small mb-3">{{ $info['hint'] }}</p>

                                        @if(!empty($files))
                                            <div class="d-flex flex-wrap gap-2 mb-3">
                                                @foreach($files as $fileName)
                                                    @php
                                                        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                                                        $filePath = 'uploads/kyc/' . $fileName;
                                                        $fileUrl = asset($filePath);
                                                    @endphp
                                                    <div class="position-relative border rounded p-1 bg-white text-center shadow-sm" style="width: 85px;">
                                                        @if(in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'jfif']))
                                                            <a href="{{ $fileUrl }}" target="_blank" title="View Full Image">
                                                                <img src="{{ $fileUrl }}" alt="{{ $info['title'] }}" class="rounded" style="width: 100%; height: 55px; object-fit: cover;">
                                                            </a>
                                                        @elseif($ext === 'pdf')
                                                            <a href="{{ $fileUrl }}" target="_blank" class="d-flex flex-column align-items-center justify-content-center text-danger text-decoration-none py-1" style="height: 55px;">
                                                                <i class="fa-solid fa-file-pdf fs-3"></i>
                                                                <span style="font-size: 10px;">PDF</span>
                                                            </a>
                                                        @else
                                                            <a href="{{ $fileUrl }}" target="_blank" class="d-flex flex-column align-items-center justify-content-center text-secondary text-decoration-none py-1" style="height: 55px;">
                                                                <i class="fa-solid fa-file fs-3"></i>
                                                                <span style="font-size: 10px;">File</span>
                                                            </a>
                                                        @endif
                                                        <a href="{{ $fileUrl }}" target="_blank" class="btn btn-xs btn-outline-dark w-100 mt-1 py-0 px-1" style="font-size: 11px;">View</a>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>

                                    <div>
                                        <input type="file" name="{{ $fieldKey }}[]" multiple accept="image/*,application/pdf" class="form-control form-control-sm @error($fieldKey) is-invalid @enderror">
                                        <small class="text-muted d-block mt-1" style="font-size: 0.78rem;">Images (JPG, PNG) or PDF - Max 5 MB.</small>
                                        @error($fieldKey)<div class="profile-field-error">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="profile-submit-wrap mt-4">
                    <button type="submit" class="btn btn-dark-blue profile-submit-btn">
                        <i class="fa-solid fa-cloud-arrow-up me-1"></i> Upload KYC Details
                    </button>
                </div>
            </div>
        </div>
    </form>
    @endif
</div>

<div class="modal fade profile-password-modal" id="changePasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="{{ route('profile.password.update') }}" id="changePasswordForm">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Change Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <div class="input-group">
                            <input type="password" name="current_password" class="form-control @error('current_password') is-invalid @enderror" required>
                            <button class="btn btn-outline-secondary toggle-password" type="button" tabindex="-1"><i class="fa-regular fa-eye"></i></button>
                        </div>
                        @error('current_password')<div class="profile-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <div class="input-group">
                            <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" required>
                            <button class="btn btn-outline-secondary toggle-password" type="button" tabindex="-1"><i class="fa-regular fa-eye"></i></button>
                        </div>
                        @error('password')<div class="profile-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Confirm Password</label>
                        <div class="input-group">
                            <input type="password" name="password_confirmation" class="form-control" required>
                            <button class="btn btn-outline-secondary toggle-password" type="button" tabindex="-1"><i class="fa-regular fa-eye"></i></button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-dark-blue profile-submit-btn">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
window.profilePageConfig = {
    updateUrl: @json(route('profile.update')),
    kycUrl: @json(route('profile.kyc.update')),
    passwordUrl: @json(route('profile.password.update')),
    disconnectGoogleUrl: @json(($googleConnectionEnabled ?? false) ? route('profile.google.disconnect') : null),
    googleStatusUrl: @json(($googleConnectionEnabled ?? false) ? url('/api/meetings/google/auth-status') : null),
    googleAuthUrl: @json(($googleConnectionEnabled ?? false) ? route('google.auth') : null),
    openPasswordModal: @json($errors->has('current_password') || $errors->has('password')),
};
</script>
<script src="{{ (env('PUBLIC_PATH') ? '/' . trim(env('PUBLIC_PATH'), '/') . '/js/profile.js' : '/js/profile.js') }}?v={{ filemtime(public_path('js/profile.js')) }}"></script>
@endpush
