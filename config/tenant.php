<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tenancy Master Switch
    |--------------------------------------------------------------------------
    |
    | When enabled, requests and commands are mapped to a specific tenant client
    | using Admin-Product's tenant API. Each client receives its own isolated
    | database connection, cache, session, and filesystem storage directory.
    | When disabled, the application runs as a standard single-tenant app using
    | default .env DB_* credentials.
    |
    */
    'enabled' => (bool) env('TENANCY_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Admin-Product API Connection
    |--------------------------------------------------------------------------
    |
    | Base URL of the Admin-Product tenant API endpoint.
    | Example: http://127.0.0.1:8000/api/tenant or https://admin.fableadtech.com/api/tenant
    |
    */
    'api_url' => rtrim((string) env('TENANT_API_URL', ''), '/'),

    /*
    |--------------------------------------------------------------------------
    | API Secret
    |--------------------------------------------------------------------------
    |
    | Shared secret key required in the X-Tenant-Secret header to authenticate
    | with Admin-Product's tenant management API.
    |
    */
    'secret' => (string) env('TENANT_API_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Product & Project Identifiers
    |--------------------------------------------------------------------------
    |
    | Product and project codes configured in Admin-Product. Tenants are verified
    | against these codes to guarantee that accounts purchased for other products
    | cannot access this system.
    |
    */
    'product_code' => strtoupper((string) env('TENANT_PRODUCT_CODE', 'CRM')),
    'project_code' => strtoupper((string) env('TENANT_PROJECT_CODE', 'CRM')),

    /*
    |--------------------------------------------------------------------------
    | Cache & Grace Period
    |--------------------------------------------------------------------------
    |
    | cache_seconds: How long (seconds) a successful tenant lookup is cached.
    | grace_seconds: How long (seconds) a stale cache entry remains valid if
    | Admin-Product is temporarily unreachable during a network disruption.
    |
    */
    'cache_seconds' => (int) env('TENANT_CACHE_SECONDS', 300),
    'grace_seconds' => (int) env('TENANT_GRACE_SECONDS', 86400),
    'http_timeout' => (int) env('TENANT_HTTP_TIMEOUT', 8),

    /*
    |--------------------------------------------------------------------------
    | Central Hostnames
    |--------------------------------------------------------------------------
    |
    | Domains where users access central login rather than tenant-specific subdomains.
    | On central domains, tenants are identified by user login email or username.
    |
    */
    'central_hosts' => array_filter(array_map('trim', explode(',', (string) env(
        'TENANT_CENTRAL_HOSTS',
        'localhost,127.0.0.1,crm.fableadtech.com,crm.fableadtech.in,devcrm.fableadtech.com'
    )))),

];

