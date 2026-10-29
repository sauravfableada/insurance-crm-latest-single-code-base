<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'type',
        'password',
        'phone',
        'country_code',
        'whatsapp',
        'whatsapp_country_code',
        'address',
        'job_title',
        'designation',
        'company',
        'city',
        'country',
        'avatar_path',
        'is_active',
        'role',
        'created_by',
        'modified_by',
        'updated_by',
        'deleted_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'avatar_url',
    ];

    public function isMainAdmin(): bool
    {
        return $this->isAdmin();
    }

    public function isAdmin(): bool
    {
        return $this->hasAdminRole();
    }

    public function hasMatrixPermission(string $permission): bool
    {
        // Enforce tenant plan boundary: if tenant does not have the module, deny
        if (config('tenant.enabled', false) && tenant()) {
            $module = $this->permissionToModule($permission);
            if ($module && !tenant_has_module($module)) {
                return false;
            }
        }

        if ($this->isAdmin()) {
            return true;
        }

        if ($this->permissions()->where('name', $permission)->exists()) {
            return true;
        }

        try {
            $service = app(\App\Services\TeamMemberService::class);
            $permissions = $service->getFlatPermissions($this->id);
            return in_array($permission, $permissions, true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function permissionToModule(string $permission): ?string
    {
        static $map = [
            'view_customers' => 'Customers', 'create_customers' => 'Customers', 'edit_customers' => 'Customers', 'delete_customers' => 'Customers',
            'view_family_groups' => 'Family Groups', 'create_family_groups' => 'Family Groups', 'edit_family_groups' => 'Family Groups', 'delete_family_groups' => 'Family Groups',
            'view_insurance' => 'Insurance', 'create_insurance' => 'Insurance', 'edit_insurance' => 'Insurance', 'delete_insurance' => 'Insurance',
            'view_investments' => 'Investments', 'create_investments' => 'Investments', 'edit_investments' => 'Investments', 'delete_investments' => 'Investments',
            'view_policies' => 'Policies', 'create_policies' => 'Policies', 'edit_policies' => 'Policies', 'delete_policies' => 'Policies',
            'view_commission_rules' => 'Commissions', 'create_commission_rules' => 'Commissions', 'edit_commission_rules' => 'Commissions', 'delete_commission_rules' => 'Commissions',
            'view_masters' => 'Masters',
            'view_endorsements' => 'Endorsements', 'create_endorsements' => 'Endorsements', 'edit_endorsements' => 'Endorsements', 'delete_endorsements' => 'Endorsements',
            'view_documents' => 'Documents', 'create_documents' => 'Documents', 'edit_documents' => 'Documents', 'delete_documents' => 'Documents',
            'view_tasks' => 'Tasks', 'create_tasks' => 'Tasks', 'edit_tasks' => 'Tasks', 'delete_tasks' => 'Tasks',
            'view_prospects' => 'Prospects', 'create_prospects' => 'Prospects', 'edit_prospects' => 'Prospects', 'delete_prospects' => 'Prospects',
            'view_followups' => 'Followups', 'create_followups' => 'Followups', 'edit_followups' => 'Followups', 'delete_followups' => 'Followups',
            'view_leads' => 'Leads', 'create_leads' => 'Leads', 'edit_leads' => 'Leads', 'delete_leads' => 'Leads',
            'view_meetings' => 'Meetings', 'create_meetings' => 'Meetings', 'edit_meetings' => 'Meetings', 'delete_meetings' => 'Meetings',
            'view_reports' => 'Reports',
            'view_email' => 'Marketing', 'create_email' => 'Marketing', 'edit_email' => 'Marketing', 'delete_email' => 'Marketing',
            'view_users' => 'Staff', 'create_users' => 'Staff', 'edit_users' => 'Staff', 'delete_users' => 'Staff',
            'view_projects' => 'Projects',
        ];

        return $map[$permission] ?? null;
    }

    public function assignedDeals()
    {
        return $this->hasMany(Deal::class, 'assigned_user_id');
    }

    protected function hasAdminRole(): bool
    {
        return $this->roles()->whereIn('name', ['admin', 'super-admin'])->exists();
    }

    public function getAvatarUrlAttribute(): string
    {
        $default = 'https://ui-avatars.com/api/?name=' . urlencode((string) $this->name) . '&background=3b82f6&color=ffffff&size=128';

        if (empty($this->avatar_path)) {
            return $default;
        }

        $path = ltrim((string) $this->avatar_path, '/');

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        if (Str::startsWith($path, 'storage/')) {
            $publicPath = public_path($path);
            return file_exists($publicPath) ? asset($path) : $default;
        }

        if (Storage::disk('public')->exists($path)) {
            return asset('storage/' . $path);
        }

        $publicPath = public_path($path);
        return file_exists($publicPath) ? asset($path) : $default;
    }
}
