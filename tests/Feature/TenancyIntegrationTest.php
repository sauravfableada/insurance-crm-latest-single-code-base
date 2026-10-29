<?php

namespace Tests\Feature;

use App\Exceptions\TenantResolutionException;
use App\Http\Middleware\EnsureTenantModuleAccess;
use App\Http\Middleware\IdentifyTenant;
use App\Models\User;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenancyIntegrationTest extends TestCase
{
    protected array $mockTenantData = [
        'id' => 99,
        'client_id' => 42,
        'client_name' => 'Acme Insurance Corp',
        'brand_name' => 'Acme',
        'domain' => 'acme.crm.fableadtech.com',
        'product_code' => 'CRM',
        'project_code' => 'CRM',
        'status' => 'Active',
        'client_status' => 'Active',
        'membership_status' => 'Active',
        'plan_name' => 'Standard Plan',
        'modules' => ['Dashboard', 'Customers', 'Leads', 'Followups', 'Policies', 'Commissions'],
        'sub_modules' => ['Manage Leads', 'Manage Policies'],
        'sub_module_names' => ['Manage Leads', 'Manage Policies'],
        'sub_module_slugs' => ['manage-leads', 'manage-policies'],
        'email' => 'admin@acme-insurance.test',
        'db' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'test_tenant_db',
            'username' => 'root',
            'password' => '',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('tenant.enabled', true);
        Config::set('tenant.api_url', 'http://127.0.0.1:8000/api/tenant');
        Config::set('tenant.secret', 'fab_erp_tenant_secret_key_8f7b2c9d1e4a6053');
        Config::set('tenant.product_code', 'CRM');
        Config::set('tenant.project_code', 'CRM');
        Config::set('tenant.cache_seconds', 300);
        Config::set('tenant.grace_seconds', 86400);
        Config::set('tenant.central_hosts', ['localhost', '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        // Clean up test tenant storage directory if created
        $testStorage = storage_path('tenants/99');
        if (File::exists($testStorage)) {
            File::deleteDirectory($testStorage);
        }

        parent::tearDown();
    }

    /**
     * 1. Test single-tenant mode when TENANCY_ENABLED=false
     */
    public function test_single_tenant_mode_when_disabled(): void
    {
        Config::set('tenant.enabled', false);

        $manager = app(TenantManager::class);
        $this->assertFalse($manager->enabled());
        $this->assertNull(tenant());

        // When tenancy is disabled, module checks should always return true
        $this->assertTrue(tenant_has_module('Leads'));
        $this->assertTrue(tenant_has_module('AnyRandomModule'));
        $this->assertTrue(tenant_has_sub_module('AnySubModule'));
    }

    /**
     * 2. Test tenant resolution by subdomain host
     */
    public function test_tenant_resolves_by_host(): void
    {
        Http::fake([
            '*/resolve' => Http::response([
                'success' => true,
                'tenant' => $this->mockTenantData,
            ], 200),
        ]);

        $manager = app(TenantManager::class);
        $tenant = $manager->resolveByHost('acme.crm.fableadtech.com');

        $this->assertEquals(99, $tenant['id']);
        $this->assertEquals('Acme Insurance Corp', $tenant['client_name']);
        $this->assertEquals('test_tenant_db', $tenant['db']['database']);
        $this->assertEquals($tenant, tenant());
        $this->assertEquals(99, tenant('id'));
        $this->assertEquals('Standard Plan', tenant_plan_name());
    }

    /**
     * 3. Test tenant resolution by email for central login
     */
    public function test_tenant_resolves_by_email(): void
    {
        Http::fake([
            '*/resolve-user' => Http::response([
                'success' => true,
                'tenant' => $this->mockTenantData,
            ], 200),
        ]);

        $manager = app(TenantManager::class);
        $tenant = $manager->resolveByEmail('admin@acme-insurance.test');

        $this->assertEquals(99, $tenant['id']);
        $this->assertEquals('Acme Insurance Corp', $tenant['client_name']);
    }

    /**
     * 4. Test caching: second call does not hit HTTP endpoint
     */
    public function test_tenant_lookup_is_cached(): void
    {
        Http::fake([
            '*/resolve' => Http::response([
                'success' => true,
                'tenant' => $this->mockTenantData,
            ], 200),
        ]);

        $manager = app(TenantManager::class);

        // First call hits HTTP
        $manager->resolveByHost('cached.crm.fableadtech.com', true);

        // Reset HTTP fake to return 500 error
        Http::fake([
            '*/resolve' => Http::response(['error' => 'Should not be called'], 500),
        ]);

        // Second call should return from cache without throwing
        $cached = $manager->resolveByHost('cached.crm.fableadtech.com', false);
        $this->assertEquals(99, $cached['id']);
    }

    /**
     * 5. Test grace-period fallback when API is down / unreachable
     */
    public function test_grace_period_fallback_when_api_fails(): void
    {
        Http::fake([
            '*/resolve' => Http::response([
                'success' => true,
                'tenant' => $this->mockTenantData,
            ], 200),
        ]);

        $manager = app(TenantManager::class);
        $manager->resolveByHost('grace.crm.fableadtech.com', true);

        // Expire the short-lived cache but leave stale grace cache intact
        $key = 'host:' . sha1('grace.crm.fableadtech.com|CRM|CRM');
        $cacheProp = new \ReflectionProperty(TenantManager::class, 'lookupCache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue($manager);
        if ($cache) {
            $cache->forget($key);
        }

        // Simulate network failure
        Http::fake([
            '*/resolve' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
            },
        ]);

        // Call should succeed via stale grace-period cache
        $recovered = $manager->resolveByHost('grace.crm.fableadtech.com', false);
        $this->assertEquals(99, $recovered['id']);
    }

    /**
     * 6. Test inactive or expired tenant rejection
     */
    public function test_inactive_tenant_is_rejected(): void
    {
        Http::fake([
            '*/resolve' => Http::response([
                'success' => false,
                'code' => 'INACTIVE',
                'message' => 'Your account is inactive.',
            ], 403),
        ]);

        $this->expectException(TenantResolutionException::class);
        $this->expectExceptionCode(403);

        $manager = app(TenantManager::class);
        $manager->resolveByHost('inactive.crm.fableadtech.com', true);
    }

    /**
     * 7. Test plan-based module authorization (EnsureTenantModuleAccess middleware)
     */
    public function test_plan_based_module_authorization(): void
    {
        $manager = app(TenantManager::class);
        $manager->activate($this->mockTenantData);

        // Allowed modules according to mockTenantData
        $this->assertTrue(tenant_has_module('Dashboard'));
        $this->assertTrue(tenant_has_module('Customers'));
        $this->assertTrue(tenant_has_module('Leads'));
        $this->assertTrue(tenant_has_module('Policies'));
        $this->assertTrue(tenant_has_module('Commissions'));

        // Disallowed modules (not subscribed in plan)
        $this->assertFalse(tenant_has_module('Claim Tracker'));
        $this->assertFalse(tenant_has_module('Email Marketing'));
        $this->assertFalse(tenant_has_module('Investments'));
        $this->assertFalse(tenant_has_module('Alteration & Endorsements'));

        $middleware = app(EnsureTenantModuleAccess::class);

        // Allowed route should pass to next closure
        $requestAllowed = Request::create('/leads', 'GET');
        $requestAllowed->setRouteResolver(function () {
            $route = new \Illuminate\Routing\Route('GET', '/leads', []);
            $route->name('leads.index');
            return $route;
        });

        $passed = false;
        $response = $middleware->handle($requestAllowed, function () use (&$passed) {
            $passed = true;
            return response('OK', 200);
        });

        $this->assertTrue($passed);
        $this->assertEquals(200, $response->getStatusCode());

        // Disallowed API route should return HTTP 403 JSON
        $requestDisallowedApi = Request::create('/api/claim-tracker', 'GET');
        $requestDisallowedApi->headers->set('Accept', 'application/json');
        $requestDisallowedApi->setRouteResolver(function () {
            $route = new \Illuminate\Routing\Route('GET', '/api/claim-tracker', []);
            $route->name('claim-tracker.index');
            return $route;
        });

        $apiResponse = $middleware->handle($requestDisallowedApi, function () {
            return response('SHOULD_NOT_REACH', 200);
        });

        $this->assertEquals(403, $apiResponse->getStatusCode());
        $this->assertStringContainsString('Claim Tracker', $apiResponse->getContent());

        // Disallowed Web route should redirect to dashboard with flash message
        $requestDisallowedWeb = Request::create('/claim-tracker', 'GET');
        $requestDisallowedWeb->setRouteResolver(function () {
            $route = new \Illuminate\Routing\Route('GET', '/claim-tracker', []);
            $route->name('claim-tracker.index');
            return $route;
        });

        $webResponse = $middleware->handle($requestDisallowedWeb, function () {
            return response('SHOULD_NOT_REACH', 200);
        });

        $this->assertTrue($webResponse->isRedirection());
    }

    /**
     * 8. Test User::hasMatrixPermission respects tenant plan
     */
    public function test_user_matrix_permission_respects_tenant_plan(): void
    {
        $manager = app(TenantManager::class);
        $manager->activate($this->mockTenantData);

        // Subscribed module: view_leads
        $this->assertTrue(tenant_has_module('Leads'));

        // Non-subscribed module: view_claims / view_investments
        $this->assertFalse(tenant_has_module('Investments'));

        // Mock a user
        $user = new User();
        // Since Investments is not in plan, hasMatrixPermission('view_investments') must return false immediately
        $this->assertFalse($user->hasMatrixPermission('view_investments'));
    }

    /**
     * 9. Test filesystem storage isolation per tenant
     */
    public function test_tenant_storage_isolation(): void
    {
        $manager = app(TenantManager::class);
        $manager->activate($this->mockTenantData);

        $isolatedStorage = storage_path();
        $this->assertStringContainsString('tenants' . DIRECTORY_SEPARATOR . '99', $isolatedStorage);
        $this->assertDirectoryExists($isolatedStorage . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'public');

        // Test helper tenant_storage_url
        $url = tenant_storage_url('documents/contract.pdf');
        $this->assertStringContainsString('storage/documents/contract.pdf', $url);
    }

    /**
     * 10. Test multi-tenant isolation: switching tenants updates DB, storage, cache prefix, and session
     */
    public function test_multi_client_isolation_between_tenants(): void
    {
        $tenantA = $this->mockTenantData;
        $tenantA['id'] = 101;
        $tenantA['db']['database'] = 'client_101_db';

        $tenantB = $this->mockTenantData;
        $tenantB['id'] = 102;
        $tenantB['db']['database'] = 'client_102_db';

        $manager = app(TenantManager::class);

        // Activate Tenant A
        $manager->activate($tenantA);
        $this->assertEquals(101, tenant('id'));
        $this->assertEquals('tenant_101_', config('cache.prefix'));
        $this->assertStringContainsString('tenants' . DIRECTORY_SEPARATOR . '101', storage_path());
        $this->assertStringContainsString('_t101', config('session.cookie'));

        // Switch to Tenant B
        $manager->activate($tenantB);
        $this->assertEquals(102, tenant('id'));
        $this->assertEquals('tenant_102_', config('cache.prefix'));
        $this->assertStringContainsString('tenants' . DIRECTORY_SEPARATOR . '102', storage_path());
        $this->assertStringContainsString('_t102', config('session.cookie'));

        // Clean up
        foreach ([101, 102] as $id) {
            $dir = storage_path('tenants/' . $id);
            if (File::exists($dir)) {
                File::deleteDirectory($dir);
            }
        }
    }

    /**
     * 11. Test unprovisioned tenant database throws NOT_PROVISIONED exception
     */
    public function test_unprovisioned_tenant_throws_exception(): void
    {
        $unprovisioned = $this->mockTenantData;
        $unprovisioned['db']['database'] = '';

        $this->expectException(TenantResolutionException::class);
        $this->expectExceptionCode(409);

        $manager = app(TenantManager::class);
        $manager->activate($unprovisioned);
    }

    /**
     * 12. Test sidebar blade rendering hides unauthorized modules
     */
    public function test_sidebar_rendering_respects_tenant_modules(): void
    {
        $manager = app(TenantManager::class);
        $manager->activate($this->mockTenantData);

        $admin = new class extends User {
            public function isAdmin(): bool { return true; }
            public function hasMatrixPermission(string $permission): bool {
                $module = $this->permissionToModule($permission);
                return !$module || tenant_has_module($module);
            }
        };

        // Render sidebar view with admin user
        $html = view('layouts.sidebar', [
            'active' => 'dashboard',
            'authUser' => $admin,
            'isCustomerUser' => false,
            'currentTenant' => tenant(),
            'currentClientId' => tenant('client_id'),
            'tenantPlanName' => tenant_plan_name(),
            'tenantModules' => tenant('modules', []),
        ])->render();

        // Subscribed modules should appear in HTML
        $this->assertStringContainsString('sidebar-category-dashboard', $html);
        $this->assertStringContainsString('<span>All Clients</span>', $html);
        $this->assertStringContainsString('<span>Manage Leads</span>', $html);
        $this->assertStringContainsString('<span>Manage Policies</span>', $html);

        // Non-subscribed modules must be omitted from sidebar HTML
        $this->assertStringNotContainsString('<span>Claim Tracker</span>', $html);
        $this->assertStringNotContainsString('<span>Email Marketing</span>', $html);
        $this->assertStringNotContainsString('manage-investment', $html);
    }
}
