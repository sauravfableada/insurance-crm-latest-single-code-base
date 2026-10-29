<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Delivers tenant-isolated uploaded documents, PDFs, and assets.
 * Respects tenant boundaries so one client cannot view another client's private files.
 */
class TenantStorageController extends Controller
{
    public function __invoke(Request $request, string $path): BinaryFileResponse
    {
        $normalizedPath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

        // Pattern 1: Explicit tenant storage path: tenants/{id}/app/public/{subpath}
        if (preg_match('#^tenants[\\/](\d+)[\\/]app[\\/]public[\\/](.*)$#i', $normalizedPath, $matches)) {
            $requestedTenantId = (int) $matches[1];
            $currentTenantId = tenant('id');

            // Prevent accessing another tenant's files directly
            if (config('tenant.enabled', false) && $currentTenantId && $currentTenantId !== $requestedTenantId) {
                abort(403, 'Unauthorized file access across tenant boundaries.');
            }

            $relativePath = $matches[2];
            $base = base_path('storage' . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $requestedTenantId . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'public');
            $file = realpath($base . DIRECTORY_SEPARATOR . $relativePath);

            if ($file && str_starts_with($file, realpath($base) . DIRECTORY_SEPARATOR) && is_file($file)) {
                return response()->file($file, [
                    'Cache-Control' => 'private, max-age=86400',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        // Pattern 2: Storage disk public (supports Storage::fake in tests and configured tenant disk)
        if (\Illuminate\Support\Facades\Storage::disk('public')->exists($normalizedPath)) {
            $storageFile = \Illuminate\Support\Facades\Storage::disk('public')->path($normalizedPath);
            return response()->file($storageFile, [
                'Cache-Control' => 'private, max-age=86400',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        // Pattern 3: Current tenant's isolated storage (storage_path('app/public'))
        $tenantRoot = realpath(storage_path('app/public'));
        if ($tenantRoot) {
            $file = realpath($tenantRoot . DIRECTORY_SEPARATOR . $normalizedPath);
            if ($file && str_starts_with($file, $tenantRoot . DIRECTORY_SEPARATOR) && is_file($file)) {
                return response()->file($file, [
                    'Cache-Control' => 'private, max-age=86400',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        // Pattern 3: Current tenant's private storage (storage_path('app'))
        $tenantPrivateRoot = realpath(storage_path('app'));
        if ($tenantPrivateRoot) {
            $file = realpath($tenantPrivateRoot . DIRECTORY_SEPARATOR . $normalizedPath);
            if ($file && str_starts_with($file, $tenantPrivateRoot . DIRECTORY_SEPARATOR) && is_file($file)) {
                return response()->file($file, [
                    'Cache-Control' => 'private, max-age=86400',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        // Pattern 4: Shared storage fallback (storage/app/public)
        $sharedRoot = realpath(base_path('storage/app/public'));
        if ($sharedRoot) {
            $sharedFile = realpath($sharedRoot . DIRECTORY_SEPARATOR . $normalizedPath);
            if ($sharedFile && str_starts_with($sharedFile, $sharedRoot . DIRECTORY_SEPARATOR) && is_file($sharedFile)) {
                return response()->file($sharedFile, [
                    'Cache-Control' => 'private, max-age=86400',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        // Pattern 5: Public uploads fallback (public/uploads)
        $uploadsRoot = realpath(public_path('uploads'));
        if ($uploadsRoot) {
            $uploadFile = realpath($uploadsRoot . DIRECTORY_SEPARATOR . $normalizedPath);
            if ($uploadFile && str_starts_with($uploadFile, $uploadsRoot . DIRECTORY_SEPARATOR) && is_file($uploadFile)) {
                return response()->file($uploadFile, [
                    'Cache-Control' => 'private, max-age=86400',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        abort(404, 'Requested document or file not found.');
    }
}
