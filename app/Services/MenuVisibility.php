<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

class MenuVisibility
{
    private ?array $disabled = null;

    public function catalog(): array
    {
        return config('sidebar_modules', []);
    }

    public function enabled(string $module): bool
    {
        return array_key_exists($module, $this->catalog()) && !in_array($module, $this->disabled(), true);
    }

    public function permissionEnabled(string $permission): bool
    {
        if (!preg_match('/^(?:view|create|edit|delete)_(.+)$/', $permission, $match)) return true;
        $module = $match[1];
        return !array_key_exists($module, $this->catalog()) || $this->enabled($module);
    }

    public function filterPermissionModules(array $modules): array
    {
        return array_filter($modules, function ($meta, $module) {
            $module = (string) $module;
            return !array_key_exists($module, $this->catalog()) || $this->enabled($module);
        }, ARRAY_FILTER_USE_BOTH);
    }

    public function disabled(): array
    {
        if ($this->disabled !== null) return $this->disabled;
        if (!Schema::hasTable('settings')) return $this->disabled = [];
        $raw = Setting::where('key', 'sidebar_disabled_modules')->value('value');
        $values = is_string($raw) ? json_decode($raw, true) : [];
        return $this->disabled = array_values(array_intersect(array_keys($this->catalog()), is_array($values) ? $values : []));
    }
}
