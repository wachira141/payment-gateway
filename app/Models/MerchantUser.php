<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class MerchantUser extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuids;
    
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'merchant_id',
        'name',
        'email',
        'password',
        'role',
        'status',
        'permissions',
        'last_login_at',
        'phone',
        'metadata',
        'is_primary',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'permissions' => 'array',
        'last_login_at' => 'datetime',
        'metadata' => 'array',
        'is_primary' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleting(function (MerchantUser $user) {
            if ($user->is_primary) {
                throw new \Exception('Cannot delete the primary admin user.');
            }
        });
    }

    /**
     * Get the merchant
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Get the roles assigned to this user
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'merchant_user_roles', 'merchant_user_id', 'role_id')
            ->withPivot(['assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    /**
     * Get direct permissions assigned to this user
     */
    public function directPermissions()
    {
        return $this->belongsToMany(Permission::class, 'merchant_user_permissions', 'merchant_user_id', 'permission_id')
            ->withPivot(['granted_by', 'expires_at', 'granted_at'])
            ->withTimestamps();
    }

    /**
     * Check if user is primary admin (first admin created for merchant)
     */
    public function isPrimary(): bool
    {
        return $this->is_primary ?? false;
    }

    /**
     * Check if user has admin role
     */
    public function isAdmin(): bool
    {
        return $this->roles()->where('name', 'admin')->exists();
    }

    /**
     * Check if user has a specific role
     */
    public function hasRole(string $roleName): bool
    {
        return $this->roles()->where('name', $roleName)->exists();
    }

    /**
     * Check if user has a specific permission (database-backed)
     */
    public function hasPermission(string $permission): bool
    {
        // Primary users have all permissions
        if ($this->isPrimary()) {
            return true;
        }

        // Admin role has all permissions
        if ($this->isAdmin()) {
            return true;
        }

        // Check role-based permissions
        $rolePermissions = $this->roles()
            ->with('permissions')
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->pluck('name')
            ->unique()
            ->toArray();

        if (in_array($permission, $rolePermissions)) {
            return true;
        }

        // Check direct permissions (non-expired)
        return $this->directPermissions()
            ->where('name', $permission)
            ->where(function ($query) {
                $query->whereNull('merchant_user_permissions.expires_at')
                    ->orWhere('merchant_user_permissions.expires_at', '>', now());
            })
            ->exists();
    }

    /**
     * Check if user has any of the given permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has all of the given permissions
     */
    public function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get all resolved permissions for this user
     */
    public function getResolvedPermissions(): array
    {
        if ($this->isPrimary() || $this->isAdmin()) {
            return Permission::pluck('name')->toArray();
        }

        // Role-based permissions
        $rolePermissions = $this->roles()
            ->with('permissions')
            ->get()
            ->pluck('permissions')
            ->flatten()
            ->pluck('name')
            ->unique();

        // Direct permissions (non-expired)
        $directPermissions = $this->directPermissions()
            ->where(function ($query) {
                $query->whereNull('merchant_user_permissions.expires_at')
                    ->orWhere('merchant_user_permissions.expires_at', '>', now());
            })
            ->pluck('name');

        return $rolePermissions->merge($directPermissions)->unique()->values()->toArray();
    }

    /**
     * Get roles with their permissions
     */
    public function getRolesWithPermissions(): array
    {
        return $this->roles()
            ->with('permissions:id,name,display_name,module')
            ->get()
            ->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'permissions' => $role->permissions->pluck('name')->toArray(),
                ];
            })
            ->toArray();
    }

    /**
     * Get direct permissions with details
     */
    public function getDirectPermissionsWithDetails(): array
    {
        return $this->directPermissions()
            ->where(function ($query) {
                $query->whereNull('merchant_user_permissions.expires_at')
                    ->orWhere('merchant_user_permissions.expires_at', '>', now());
            })
            ->get()
            ->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'display_name' => $permission->display_name,
                    'module' => $permission->module,
                    'expires_at' => $permission->pivot->expires_at,
                ];
            })
            ->toArray();
    }

    /**
     * Update last login timestamp
     */
    public function updateLastLogin()
    {
        $this->last_login_at = now();
        $this->save();
    }
}
