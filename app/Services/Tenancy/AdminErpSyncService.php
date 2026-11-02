<?php

namespace App\Services\Tenancy;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AdminErpSyncService
{
    /**
     * Resolve the client ID in admin_erp for the current tenant or user.
     */
    public function resolveClientId(?User $user = null, ?string $oldEmail = null): ?int
    {
        // 1. Direct tenant client_id from active tenant session
        if (function_exists('tenant') && tenant() && !empty(tenant('client_id'))) {
            return (int) tenant('client_id');
        }

        // 2. Direct tenant setup id -> client_id via admin_erp setups
        if (function_exists('tenant') && tenant() && !empty(tenant('id'))) {
            try {
                $setup = DB::connection('admin_erp')
                    ->table('setups')
                    ->where('id', (int) tenant('id'))
                    ->first();
                if ($setup && !empty($setup->client_id)) {
                    return (int) $setup->client_id;
                }
            } catch (\Throwable $e) {
                // proceed
            }
        }

        // 3. Match current database in admin_erp setups
        try {
            $defaultConn = config('database.default', 'mysql');
            $currentDb = config("database.connections.{$defaultConn}.database");
            if (!empty($currentDb)) {
                $strippedDb = preg_replace('/^u\d+_/', '', $currentDb);
                $setup = DB::connection('admin_erp')
                    ->table('setups')
                    ->where('database_name', $currentDb)
                    ->orWhere('database_name', 'LIKE', '%' . $strippedDb . '%')
                    ->first();
                if ($setup && !empty($setup->client_id)) {
                    return (int) $setup->client_id;
                }
            }
        } catch (\Throwable $e) {
            // proceed
        }

        // 4. Match client by work_email in admin_erp clients
        $emailToCheck = $oldEmail ?: ($user?->email);
        if (!empty($emailToCheck)) {
            try {
                $client = DB::connection('admin_erp')
                    ->table('clients')
                    ->where('work_email', strtolower(trim($emailToCheck)))
                    ->first();
                if ($client && !empty($client->id)) {
                    return (int) $client->id;
                }
            } catch (\Throwable $e) {
                // proceed
            }
        }

        // 5. Match client by username in admin_erp clients
        if ($user && !empty($user->username)) {
            try {
                if (Schema::connection('admin_erp')->hasColumn('clients', 'username')) {
                    $client = DB::connection('admin_erp')
                        ->table('clients')
                        ->where('username', strtolower(trim($user->username)))
                        ->first();
                    if ($client && !empty($client->id)) {
                        return (int) $client->id;
                    }
                }
            } catch (\Throwable $e) {
                // proceed
            }
        }

        return null;
    }

    /**
     * Synchronize updated user/client profile data back into admin_erp database.
     */
    public function syncClientProfile(User $user, array $extraData = [], ?string $oldEmail = null): bool
    {
        try {
            $clientId = $this->resolveClientId($user, $oldEmail);
            if (!$clientId) {
                Log::info('AdminErpSyncService: No matching client_id found in admin_erp.');
                return false;
            }

            $clientsCols = Schema::connection('admin_erp')->getColumnListing('clients');
            $updateData = [];

            if (in_array('client_name', $clientsCols, true) && !empty($user->name)) {
                $updateData['client_name'] = $user->name;
            }

            if (in_array('brand_name', $clientsCols, true)) {
                $brandName = $extraData['company_name'] ?? ($user->name ?? null);
                if (!empty($brandName)) {
                    $updateData['brand_name'] = $brandName;
                }
            }

            if (in_array('username', $clientsCols, true) && !empty($user->username)) {
                $updateData['username'] = strtolower(trim($user->username));
            }

            if (in_array('work_email', $clientsCols, true) && !empty($user->email)) {
                $updateData['work_email'] = strtolower(trim($user->email));
            }

            if (in_array('mobile', $clientsCols, true) && !empty($user->phone)) {
                $updateData['mobile'] = $user->phone;
            }

            if (in_array('location', $clientsCols, true) && !empty($extraData['company_address'])) {
                $updateData['location'] = $extraData['company_address'];
            }

            if (in_array('updated_at', $clientsCols, true)) {
                $updateData['updated_at'] = now();
            }

            if (!empty($updateData)) {
                DB::connection('admin_erp')
                    ->table('clients')
                    ->where('id', $clientId)
                    ->update($updateData);
            }

            // Also update setups table if exists
            if (Schema::connection('admin_erp')->hasTable('setups')) {
                $setupsCols = Schema::connection('admin_erp')->getColumnListing('setups');
                $setupUpdate = [];
                if (in_array('client_name', $setupsCols, true) && !empty($user->name)) {
                    $setupUpdate['client_name'] = $user->name;
                }
                if (in_array('updated_at', $setupsCols, true)) {
                    $setupUpdate['updated_at'] = now();
                }
                if (!empty($setupUpdate)) {
                    DB::connection('admin_erp')
                        ->table('setups')
                        ->where('client_id', $clientId)
                        ->update($setupUpdate);
                }
            }

            Log::info("AdminErpSyncService: Successfully synced client #{$clientId} in admin_erp database.");
            return true;
        } catch (\Throwable $e) {
            Log::warning('AdminErpSyncService: Failed to sync client profile to admin_erp: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Synchronize updated password back into admin_erp database.
     */
    public function syncClientPassword(User $user, string $password): bool
    {
        try {
            $clientId = $this->resolveClientId($user);
            if (!$clientId) {
                return false;
            }

            $clientsCols = Schema::connection('admin_erp')->getColumnListing('clients');
            if (!in_array('password', $clientsCols, true)) {
                return false;
            }

            $hashedPassword = str_starts_with($password, '$2y$') || str_starts_with($password, '$2a$')
                ? $password
                : Hash::make($password);

            DB::connection('admin_erp')
                ->table('clients')
                ->where('id', $clientId)
                ->update([
                    'password' => $hashedPassword,
                    'updated_at' => now(),
                ]);

            Log::info("AdminErpSyncService: Successfully updated password for client #{$clientId} in admin_erp.");
            return true;
        } catch (\Throwable $e) {
            Log::warning('AdminErpSyncService: Failed to sync password to admin_erp: ' . $e->getMessage());
            return false;
        }
    }
}

