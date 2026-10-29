@php
    $isCustomerUser = $isCustomerUser ?? (auth()->guard('customer')->check());
    $companyName = $companyName ?? null;
    $companyLogoUrl = $companyLogoUrl ?? null;
    $shortCompanyLogoUrl = $shortCompanyLogoUrl ?? null;
    $authUser = $authUser ?? auth()->user();
@endphp
@if(!$isCustomerUser)
<aside class="crm-sidebar shadow-sm" id="sidenav-main" style="min-width: 260px">
    <div class="sidenav-header sidebar-logo-header">
        <a class="navbar-brand sidebar-logo-card" href="{{ route('dashboard') }}" aria-label="Go to dashboard">
            <img src="{{ $companyLogoUrl ?? asset('logo/fableadcrmLogo.png') }}"
                class="sidebar-company-logo"
                alt="{{ $companyName ? $companyName . ' logo' : 'Company logo' }}">
            <img src="{{ (env('PUBLIC_PATH') ? '/' . trim(env('PUBLIC_PATH'), '/') . '/images/template/policysarthi-favicon.jpg' : '/images/template/policysarthi-favicon.jpg') }}"
                class="sidebar-compact-logo"
                alt="AKP Insurance">
        </a>
    </div>

    @if(!$isCustomerUser)
    <div class="sidenav-header" style="padding: 0px 8px;">
        <div class="profile-card brand-card">
            <img src="{{ $shortCompanyLogoUrl ?? (env('PUBLIC_PATH') ? '/' . trim(env('PUBLIC_PATH'), '/') . '/images/template/akpfavicon.png' : '/images/template/akpfavicon.png') }}"
                class="brand-logo-icon" alt="CRM Logo">
            <span class="brand-logo-text capitalize" title="{{ strtoupper($authUser?->name ?? 'FableadCRM') }}">{{ strtoupper($authUser?->name ?? 'FableadCRM') }}</span>
        </div>
    </div>
    @endif

    <nav class="navbar-nav overflow-x-hidden" id="sidebar-nav" style="padding: 0px 8px; padding-bottom:25px;"
         data-user-id="{{ auth()->id() }}" data-is-admin="{{ (!$isCustomerUser && auth()->user()?->isAdmin()) ? '1' : '0' }}">
        @if($isCustomerUser)
        <!-- Customer Portal Menu -->
        <li class="nav-item mt-2 sidebar-category-dashboard">
            <a class="nav-link ccc ddd @if(request()->routeIs('customer.portal.dashboard') && !request()->has('view')) active @endif" href="{{ route('customer.portal.dashboard') }}">
                <i class="fa-solid fa-table-columns me-2 text-primary"></i>
                <span>Dashboard</span>
            </a>
        </li>
        <li class="nav-item mt-2 sidebar-module">
            <a class="nav-link ccc ddd @if(request()->routeIs('customer.portal.policy.detail') || (request()->routeIs('customer.portal.dashboard') && request()->get('tab') === 'policies')) active @endif" href="{{ route('customer.portal.dashboard') }}?tab=policies">
                <i class="fa-regular fa-file-lines me-2 text-primary"></i>
                <span>My Policies</span>
            </a>
        </li>
        <li class="nav-item mt-2 sidebar-module">
            <a class="nav-link ccc ddd @if(request()->get('tab') === 'documents') active @endif" href="{{ route('customer.portal.dashboard') }}?tab=documents">
                <i class="fa-regular fa-folder-open me-2 text-primary"></i>
                <span>Documents</span>
            </a>
        </li>
        <li class="nav-item mt-2 sidebar-module">
            <a class="nav-link ccc ddd @if(request()->routeIs('customer.portal.profile')) active @endif" href="{{ route('customer.portal.profile') }}">
                <i class="fa-regular fa-circle-user me-2 text-primary"></i>
                <span>My Profile</span>
            </a>
        </li>
        <li class="nav-item mt-2 sidebar-module">
            <a class="nav-link ccc ddd @if(request()->get('tab') === 'support') active @endif" href="{{ route('customer.portal.dashboard') }}?tab=support">
                <i class="fa-solid fa-headset me-2 text-primary"></i>
                <span>Support</span>
            </a>
        </li>
        @else
        <!-- Dashboard -->
        @if(tenant_has_module('Dashboard'))
        <li class="nav-item mt-2 sidebar-category-dashboard" data-permission="view_dashboard" data-requires-perm="false">
            <a class="nav-link ccc ddd @if(request()->routeIs('dashboard')) active @endif" href="{{ route('dashboard') }}">
                <i class="fa-solid fa-tv me-2 text-primary"></i>
                <span>Dashboard</span>
            </a>
        </li>
        @endif
        @endif

        @php
            $canClientManage = tenant_has_module(['Customers', 'Clients', 'Family Groups', 'Client Manage']) && (
                $authUser?->isAdmin()
                || $authUser?->hasMatrixPermission('view_customers')
                || $authUser?->hasMatrixPermission('view_family_groups')
            );
        @endphp
        <!-- Client Management -->
        @if($canClientManage)
        <li class="nav-item mt-2 customer-menu sidebar-category" data-requires-perm="false" data-group="customer-management">
            <a class="nav-link ccc ddd d-flex align-items-center customer-parent-link @if(request()->routeIs('masters.customers.*', 'masters.family_groups.*')) active @endif"
               data-bs-toggle="collapse"
               href="#customerManagementCollapse"
               role="button"
               aria-expanded="{{ request()->routeIs('masters.customers.*', 'masters.family_groups.*') ? 'true' : 'false' }}"
               aria-controls="customerManagementCollapse">
                <i class="fa-solid fa-user-group me-2 text-primary"></i>
                <span>Client Manage</span>
                <i class="fa-solid fa-chevron-down ms-auto" style="font-size: 0.8rem;"></i>
            </a>
            <div class="collapse @if(request()->routeIs('masters.customers.*', 'masters.family_groups.*')) show @endif"
                 id="customerManagementCollapse">
                <ul class="nav flex-column customer-submenu">
                    @if(tenant_has_module(['Customers', 'Clients', 'All Clients']) && ($authUser?->isAdmin() || $authUser?->hasMatrixPermission('view_customers')))
                    <li class="nav-item mt-2 sidebar-module" data-permission="view_customers" data-requires-perm="true">
                        <a class="nav-link ccc ddd py-2 @if(request()->routeIs('masters.customers.*')) active @endif"
                           href="{{ route('masters.customers.index') }}">
                            <i class="bi bi-list-task me-2 text-primary"></i>
                            <span>All Clients</span>
                        </a>
                    </li>
                    @endif
                    @if(tenant_has_module('Family Groups') && ($authUser?->isAdmin() || $authUser?->hasMatrixPermission('view_family_groups')))
                    <li class="nav-item mt-2 sidebar-module" data-permission="view_family_groups" data-requires-perm="true">
                        <a class="nav-link ccc ddd py-2 @if(request()->routeIs('masters.family_groups.*')) active @endif"
                           href="{{ route('masters.family_groups.index') }}">
                            <i class="bi bi-people-fill me-2 text-primary"></i>
                            <span>Family Groups</span>
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
        </li>
        @endif

        @php
            $salesCrmActive = request()->routeIs('prospects.*', 'leads.*', 'followups.*', 'meetings.*');
            $canSalesCrm = tenant_has_module(['Sales CRM', 'Prospects', 'Leads', 'Followups', 'Meetings']) && (
                $authUser?->isAdmin()
                || $authUser?->hasMatrixPermission('view_prospects')
                || $authUser?->hasMatrixPermission('view_leads')
                || $authUser?->hasMatrixPermission('view_followups')
                || $authUser?->hasMatrixPermission('view_meetings')
            );
        @endphp
        <!-- Sales CRM -->
        @if($canSalesCrm)
        <li class="nav-item mt-2 sidebar-category" data-requires-perm="false">
            <a class="nav-link ccc ddd d-flex align-items-center {{ $salesCrmActive ? 'active' : '' }}"
                data-bs-toggle="collapse" href="#salesCrmCollapse" role="button"
                aria-expanded="{{ $salesCrmActive ? 'true' : 'false' }}" aria-controls="salesCrmCollapse">
                <i class="fa-solid fa-chart-line me-2 text-primary"></i>
                <span>Sales CRM</span>
                <i class="fa-solid fa-chevron-down ms-auto" style="font-size: 0.8rem;"></i>
            </a>
            <div class="collapse {{ $salesCrmActive ? 'show' : '' }}" id="salesCrmCollapse">
                <ul class="nav flex-column customer-submenu">
                    @if(tenant_has_module(['Prospects', 'Manage Prospects']) && ($authUser?->isAdmin() || $authUser?->hasMatrixPermission('view_prospects')))
                    <li class="nav-item mt-2 sidebar-module" data-permission="view_prospects" data-requires-perm="true">
                        <a class="nav-link ccc ddd ps-3 py-2 {{ request()->routeIs('prospects.*') ? 'active' : '' }}"
                            href="{{ route('prospects.index') }}">
                            <i class="fa-solid fa-users-viewfinder me-2 text-primary"></i>
                            <span>Manage Prospects</span>
                        </a>
                    </li>
                    @endif
                    @if(tenant_has_module(['Leads', 'Manage Leads']) && ($authUser?->isAdmin() || $authUser?->hasMatrixPermission('view_leads')))
                    <li class="nav-item mt-2 sidebar-module" data-permission="view_leads" data-requires-perm="true">
                        <a class="nav-link ccc ddd ps-3 py-2 {{ request()->routeIs('leads.*') ? 'active' : '' }}"
                            href="{{ route('leads.index') }}">
                            <i class="fa fa-bullhorn me-2 text-warning"></i>
                            <span>Manage Leads</span>
                        </a>
                    </li>
                    @endif
                    @if(tenant_has_module(['Followups', 'Follow ups', 'Follow-ups', 'Manage Follow up']) && ($authUser?->isAdmin() || $authUser?->hasMatrixPermission('view_followups')))
                    <li class="nav-item mt-2 sidebar-module" data-permission="view_followups" data-requires-perm="true">
                        <a class="nav-link ccc ddd ps-3 py-2 {{ request()->routeIs('followups.*') ? 'active' : '' }}"
                            href="{{ route('followups.index') }}">
                            <i class="fa fa-user-tie me-2 text-secondary"></i>
                            <span>Manage Follow up</span>
                        </a>
                    </li>
                    @endif
                    @if(tenant_has_module(['Meetings', 'Manage Meetings']) && ($authUser?->isAdmin() || $authUser?->hasMatrixPermission('view_meetings')))
                    <li class="nav-item mt-2 sidebar-module" data-permission="view_meetings" data-requires-perm="true">
                        <a class="nav-link ccc ddd ps-3 py-2 {{ request()->routeIs('meetings.*') ? 'active' : '' }}"
                            href="{{ route('meetings.index') }}">
                            <i class="fa-solid fa-handshake me-2 text-success"></i>
                            <span>Manage Meetings</span>
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
        </li>
        @endif

        @php
            $policyCenterActive = request()->routeIs('policies.*')
                || request()->is('add-life-insurance', 'manage-life-insurance', 'renew-life-insurance')
                || request()->is('add-investment', 'investment-management');
            $canPolicyCenter = tenant_has_module(['Policy Center', 'Policies', 'Insurance', 'Investments']) && (
                $authUser?->isAdmin()
                || $authUser?->hasMatrixPermission('view_insurance')
                || $authUser?->hasMatrixPermission('view_investments')
                || $authUser?->hasMatrixPermission('view_policies')
            );
        @endphp
        <!-- Policy Center -->
        @if($canPolicyCenter)
        <li class="nav-item mt-2 sidebar-category" data-requires-perm="false">
            <a class="nav-link ccc ddd d-flex align-items-center {{ $policyCenterActive ? 'active' : '' }}"
                data-bs-toggle="collapse" href="#policyCenterCollapse" role="button"
                aria-expanded="{{ $policyCenterActive ? 'true' : 'false' }}" aria-controls="policyCenterCollapse">
                <i class="fa-solid fa-shield-halved me-2 text-primary"></i>
                <span>Policy Center</span>
                <i class="fa-solid fa-chevron-down ms-auto" style="font-size: 0.8rem;"></i>
            </a>
            <div class="collapse {{ $policyCenterActive ? 'show' : '' }}" id="policyCenterCollapse">
                <ul class="nav flex-column customer-submenu">
                    @if(tenant_has_module(['Policies', 'Manage Policies']) && ($authUser?->isAdmin() || $authUser?->hasMatrixPermission('view_policies')))
                    <li class="nav-item mt-2 sidebar-module" data-permission="view_policies" data-requires-perm="true">
                        <a class="nav-link ccc ddd ps-3 py-2 @if(request()->routeIs('policies.*')) active @endif" href="{{ route('policies.legacy.index') }}">
                            <i class="fa-solid fa-file-signature me-2 text-warning"></i>
                            <span>Manage Policies</span>
                        </a>
                    </li>
                    @endif

                    <!-- Investment Menu -->
                    @if(tenant_has_module(['Investments', 'Investment']) && ($authUser?->isAdmin() || $authUser?->hasMatrixPermission('view_investments')))
                    <li class="nav-item mt-2 sidebar-module" data-permission="view_investments" data-requires-perm="true">
                        <a class="nav-link ccc ddd d-flex align-items-center @if(request()->is('add-investment') || request()->is('investment-management')) active @endif" data-bs-toggle="collapse" href="#investmentCollapse" role="button" aria-expanded="{{ (request()->is('add-investment') || request()->is('investment-management')) ? 'true' : 'false' }}" aria-controls="investmentCollapse">
                            <i class="fa-solid fa-chart-line me-2 text-primary"></i>
                            <span>Investment</span>
                            <i class="fa-solid fa-chevron-down ms-auto" style="font-size: 0.8rem; transition: transform 0.3s ease;"></i>
                        </a>
                        <div class="collapse @if(request()->is('add-investment') || request()->is('investment-management')) show @endif" id="investmentCollapse">
                            <ul class="nav flex-column customer-submenu">
                                <li class="nav-item mt-2">
                                    <a class="nav-link ccc ddd py-2 @if(request()->is('add-investment')) active @endif" href="{{ url('add-investment') }}">
                                        <i class="fa-solid fa-clipboard-list me-2 text-primary"></i>
                                        <span>Add Investment</span>
                                    </a>
                                </li>
                                <li class="nav-item mt-2">
                                    <a class="nav-link ccc ddd py-2 @if(request()->is('investment-management')) active @endif" href="{{ url('investment-management') }}">
                                        <i class="fa-solid fa-briefcase me-2 text-primary"></i>
                                        <span>Manage Investment</span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>
                    @endif
                </ul>
            </div>
        </li>
        @endif

        <!-- Commissions -->
        @if(tenant_has_module(['Commissions', 'Commission Rules']) && ($authUser?->isAdmin() || $authUser?->hasMatrixPermission('view_commission_rules')))
        <li class="nav-item mt-2 sidebar-module" data-permission="view_commission_rules" data-requires-perm="false">
            <a class="nav-link ccc ddd @if(request()->is('commission-rules*') || request()->is('commission-entries*')) active @endif" href="{{ route('commission-rules.index') }}">
                <i class="fa-solid fa-percent me-2 text-warning"></i>
                <span>Commissions</span>
            </a>
        </li>
        @endif

        @if(tenant_has_module(['Commissions', 'Withdrawal Requests']) && !$isCustomerUser && ($authUser?->isAdmin() || $authUser?->type === 'admin' || $authUser?->type === 'super_admin'))
        <!-- Admin Withdrawal Requests link -->
        <li class="nav-item mt-2 sidebar-module" data-requires-perm="false">
            <a class="nav-link ccc ddd @if(request()->is('admin/withdrawals*')) active @endif" href="{{ route('admin.withdrawals.index') }}">
                <i class="fa-solid fa-money-bill-transfer me-2 text-info"></i>
                <span>Withdrawal Requests</span>
            </a>
        </li>
        @endif

        @php
            $operationsActive = request()->is('endorsement-management*', 'upload-document*')
                || request()->routeIs('documents.*', 'tasks.*');
            $canOperations = tenant_has_module(['Operations', 'Documents', 'Tasks', 'Alteration & Endorsements', 'Endorsements']) && (
                $authUser?->isAdmin()
                || $authUser?->hasMatrixPermission('view_endorsements')
                || $authUser?->hasMatrixPermission('view_documents')
                || $authUser?->hasMatrixPermission('view_tasks')
            );
        @endphp
        <!-- Operations -->
        @if($canOperations)
        <li class="nav-item mt-2 sidebar-category" data-requires-perm="false">
            <a class="nav-link ccc ddd d-flex align-items-center {{ $operationsActive ? 'active' : '' }}"
                data-bs-toggle="collapse" href="#operationsCollapse" role="button"
                aria-expanded="{{ $operationsActive ? 'true' : 'false' }}" aria-controls="operationsCollapse">
                <i class="fa-solid fa-gears me-2 text-secondary"></i>
                <span>Operations</span>
                <i class="fa-solid fa-chevron-down ms-auto" style="font-size: 0.8rem;"></i>
            </a>
            <div class="collapse {{ $operationsActive ? 'show' : '' }}" id="operationsCollapse">
                <ul class="nav flex-column customer-submenu">
                    @if(tenant_has_module(['Documents', 'Document Management']) && ($authUser?->isAdmin() || $authUser?->hasMatrixPermission('view_documents')))
                    <li class="nav-item mt-2 sidebar-module" data-permission="view_documents" data-requires-perm="true">
                        <a class="nav-link ccc ddd ps-3 py-2 {{ request()->routeIs('documents.*') || request()->is('upload-document*') ? 'active' : '' }}"
                            href="{{ route('documents.index') }}">
                            <i class="fa-solid fa-folder-open me-2 text-warning"></i>
                            <span>Document Management</span>
                        </a>
                    </li>
                    @endif
                    @if(tenant_has_module(['Tasks', 'Manage Tasks']) && ($authUser?->isAdmin() || $authUser?->hasMatrixPermission('view_tasks')))
                    <li class="nav-item mt-2 sidebar-module" data-permission="view_tasks" data-requires-perm="true">
                        <a class="nav-link ccc ddd ps-3 py-2 {{ request()->routeIs('tasks.*') ? 'active' : '' }}"
                            href="{{ route('tasks.index') }}">
                            <i class="fa fa-tasks me-2 text-info"></i>
                            <span>Manage Tasks</span>
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
        </li>
        @endif

        <!-- Manage Staff / Sub Agent -->
        @if(tenant_has_module(['Staff', 'Sub Agent', 'Users']) && $authUser?->isAdmin())
        <li class="nav-item mt-2 sidebar-module" data-permission="view_users" data-requires-perm="true">
            <a class="nav-link ccc ddd @if(request()->routeIs('users.*')) active @endif" href="{{ route('users.index') }}">
                <i class="fa fa-users me-2 text-info"></i>
                <span>Sub Agent</span>
            </a>
        </li>
        @endif

        <!-- Manage Masters -->
        @if(tenant_has_module(['Masters', 'Manage Masters']) && ($authUser?->isAdmin() || $authUser?->hasMatrixPermission('view_masters')))
        <li class="nav-item mt-2 sidebar-module" data-permission="view_masters" data-requires-perm="true">
            <a class="nav-link ccc ddd @if(request()->routeIs('masters.masters.*')) active @endif" href="{{ route('masters.masters.index') }}">
                <i class="fa fa-users me-2 text-success"></i>
                <span>Manage Masters</span>
            </a>
        </li>
        @endif

        <!-- Claim Tracker -->
        @if(tenant_has_module(['Claim Tracker', 'Claims']))
        <li class="nav-item mt-2 sidebar-category">
            <a class="nav-link ccc ddd d-flex align-items-center @if(request()->routeIs('claim-tracker.*')) active @endif"
               data-bs-toggle="collapse" href="#claimTrackerCollapse" role="button"
               aria-expanded="{{ request()->routeIs('claim-tracker.*') ? 'true' : 'false' }}"
               aria-controls="claimTrackerCollapse">
                <i class="fa-solid fa-file-circle-check me-2 text-warning"></i>
                <span>Claim Tracker</span>
                <i class="fa-solid fa-chevron-down ms-auto" style="font-size: 0.8rem;"></i>
            </a>
            <div class="collapse @if(request()->routeIs('claim-tracker.*')) show @endif mt-2" id="claimTrackerCollapse">
                <ul class="nav flex-column ms-3 ps-2">
                    <li class="nav-item">
                        <a class="nav-link ccc ddd ps-3 py-2 @if(request()->routeIs('claim-tracker.index', 'claim-tracker.show', 'claim-tracker.edit')) active @endif" href="{{ route('claim-tracker.index') }}">
                            <i class="fa-solid fa-table-list" style="font-size: 0.9rem;"></i>
                            <span style="font-size: 0.9rem;">All Claims</span>
                        </a>
                    </li>
                    <li class="nav-item mt-1">
                        <a class="nav-link ccc ddd ps-3 py-2 @if(request()->routeIs('claim-tracker.create')) active @endif" href="{{ route('claim-tracker.create') }}">
                            <i class="fa-solid fa-plus" style="font-size: 0.9rem;"></i>
                            <span style="font-size: 0.9rem;">Add Claim</span>
                        </a>
                    </li>
                </ul>
            </div>
        </li>
        @endif

        <!-- Target & Review -->
        @if(tenant_has_module(['Target & Review', 'Reports']) && ($authUser?->isAdmin() || $authUser?->hasMatrixPermission('view_reports')))
        <li class="nav-item mt-2 sidebar-category" data-permission="view_reports" data-requires-perm="true">
            <a class="nav-link ccc ddd d-flex align-items-center @if(request()->routeIs('targetandreview.*')) active @endif"
               data-bs-toggle="collapse"
               href="#targetReviewCollapse"
               role="button"
               aria-expanded="{{ request()->routeIs('targetandreview.*') ? 'true' : 'false' }}"
               aria-controls="targetReviewCollapse">
                <i class="fa-solid fa-clipboard-check me-2 text-primary"></i>
                <span>Target & Review</span>
                <i class="fa-solid fa-chevron-down ms-auto" style="font-size: 0.8rem;"></i>
            </a>
            <div class="collapse @if(request()->routeIs('targetandreview.*')) show @endif mt-2" id="targetReviewCollapse">
                <ul class="nav flex-column ms-3 ps-2">
                    <li class="nav-item">
                        <a class="nav-link ccc ddd ps-3 py-2 @if(request()->routeIs('targetandreview.index')) active @endif"
                           href="{{ route('targetandreview.index') }}">
                            <i class="fa-solid fa-table-list" style="font-size: 0.9rem;"></i>
                            <span style="font-size: 0.9rem;">Target & Review</span>
                        </a>
                    </li>
                    <li class="nav-item mt-1">
                        <a class="nav-link ccc ddd ps-3 py-2 @if(request()->routeIs('targetandreview.report')) active @endif"
                           href="{{ route('targetandreview.report') }}">
                            <i class="fa-solid fa-layer-group" style="font-size: 0.9rem;"></i>
                            <span style="font-size: 0.9rem;">Target & Review Report</span>
                        </a>
                    </li>
                </ul>
            </div>
        </li>
        @endif

        @if(tenant_has_module(['Team Members', 'Staff']) && $authUser?->isAdmin())
        <li class="nav-item mt-2 sidebar-module" data-permission="view_users" data-requires-perm="true">
            <a class="nav-link ccc ddd @if(request()->routeIs('team-members.*')) active @endif" href="{{ route('team-members.index') }}">
                <i class="fa-solid fa-people-group me-2 text-primary"></i>
                <span>Team Members</span>
            </a>
        </li>
        @endif

        <!-- Manage Reports -->
        @if(tenant_has_module(['Reports', 'Manage Reports']) && ($authUser?->isAdmin() || $authUser?->hasMatrixPermission('view_reports')))
        <li class="nav-item mt-2 sidebar-category" data-permission="view_reports" data-requires-perm="true">
            <a class="nav-link ccc ddd d-flex align-items-center @if(request()->routeIs('reports.*')) active @endif" data-bs-toggle="collapse" href="#reportsCollapse" role="button" aria-expanded="{{ request()->routeIs('reports.*') ? 'true' : 'false' }}" aria-controls="reportsCollapse">
                <i class="fa-solid fa-file-lines me-2 text-dark"></i>
                <span>Manage Reports</span>
                <i class="fa-solid fa-chevron-down ms-auto" style="font-size: 0.8rem; transition: transform 0.3s ease;"></i>
            </a>
            <div class="collapse @if(request()->routeIs('reports.*')) show @endif" id="reportsCollapse">
                <ul class="nav flex-column customer-submenu">
                    @if(tenant_has_sub_module('Agent Reports', 'Reports'))
                    <li class="nav-item mt-2">
                        <a class="nav-link ccc ddd ps-3 py-2 @if(request()->routeIs('reports.staff_report')) active @endif" href="{{ route('reports.staff_report') }}">
                            <i class="fa-solid fa-file-lines text-primary"></i>
                            <span>Agent Reports</span>
                        </a>
                    </li>
                    @endif
                    @if(tenant_has_sub_module('Policy Reports', 'Reports'))
                    <li class="nav-item mt-2">
                        <a class="nav-link ccc ddd ps-3 py-2 @if(request()->routeIs('reports.policy_report')) active @endif" href="{{ route('reports.policy_report') }}">
                            <i class="fa-solid fa-file-lines text-primary"></i>
                            <span>Policy Reports</span>
                        </a>
                    </li>
                    @endif
                    @if(tenant_has_sub_module('Client Reports', 'Reports'))
                    <li class="nav-item mt-2">
                        <a class="nav-link ccc ddd ps-3 py-2 @if(request()->routeIs('reports.customers')) active @endif" href="{{ route('reports.customers') }}">
                            <i class="fa-solid fa-file-lines text-primary"></i>
                            <span>Client Reports</span>
                        </a>
                    </li>
                    @endif
                    @if(tenant_has_sub_module('Lead Reports', 'Reports'))
                    <li class="nav-item mt-2">
                        <a class="nav-link ccc ddd ps-3 py-2 @if(request()->routeIs('reports.leads')) active @endif" href="{{ route('reports.leads') }}">
                            <i class="fa-solid fa-file-lines text-primary"></i>
                            <span>Lead Reports</span>
                        </a>
                    </li>
                    @endif
                    @if(tenant_has_sub_module('Followup Reports', 'Reports'))
                    <li class="nav-item mt-2">
                        <a class="nav-link ccc ddd ps-3 py-2 @if(request()->routeIs('reports.followups')) active @endif" href="{{ route('reports.followups') }}">
                            <i class="fa-solid fa-file-lines text-primary"></i>
                            <span>Followup Reports</span>
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
        </li>
        @endif

        <!-- Marketing -->
        @if(tenant_has_module(['Marketing', 'Email Marketing']) && !($isBasicPlan ?? false) && ($authUser?->isAdmin() || $authUser?->hasMatrixPermission('view_email')))
        <li class="nav-item mt-2 sidebar-category" data-requires-perm="false">
            <a class="nav-link ccc ddd d-flex align-items-center {{ request()->routeIs('marketing.*') ? 'active' : '' }}"
                data-bs-toggle="collapse" href="#marketingCollapse" role="button"
                aria-expanded="{{ request()->routeIs('marketing.*') ? 'true' : 'false' }}" aria-controls="marketingCollapse">
                <i class="fa-solid fa-bullseye me-2 text-danger"></i>
                <span>Marketing</span>
                <i class="fa-solid fa-chevron-down ms-auto" style="font-size: 0.8rem;"></i>
            </a>
            <div class="collapse {{ request()->routeIs('marketing.*') ? 'show' : '' }}" id="marketingCollapse">
                <ul class="nav flex-column customer-submenu">
                    @if($authUser?->isAdmin() || $authUser?->hasMatrixPermission('view_email'))
                    <li class="nav-item mt-2 sidebar-module" data-permission="view_email" data-requires-perm="true">
                        <a class="nav-link ccc ddd ps-3 py-2 {{ request()->routeIs('marketing.templates.*') ? 'active' : '' }}"
                            href="{{ route('marketing.templates.index') }}">
                            <i class="fa fa-envelope me-2 text-info"></i>
                            <span>Email Marketing</span>
                        </a>
                    </li>
                    @endif
                </ul>
            </div>
        </li>
        @endif

    </nav>
</aside>
<div class="crm-sidebar-backdrop" id="crmSidebarBackdrop"></div>
@endif
