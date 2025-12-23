<?php

namespace App\Services;

use App\Models\MerchantUser;
use App\Models\Permission;
use App\Models\RbacAuditLog;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Support\Str;

class RBACService extends BaseService
{
    /**
     * Check if user has a specific permission
     */
    public function hasPermission(MerchantUser $user, string $permission): bool
    {
        return $user->hasPermission($permission);
    }

    /**
     * Check if user has any of the specified permissions
     */
    public function hasAnyPermission(MerchantUser $user, array $permissions): bool
    {
        return $user->hasAnyPermission($permissions);
    }

    /**
     * Check if user has all specified permissions
     */
    public function hasAllPermissions(MerchantUser $user, array $permissions): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $resolved = $user->getResolvedPermissions();
        return empty(array_diff($permissions, $resolved));
    }

    /**
     * Get all resolved permissions for a user
     */
    public function getUserPermissions(MerchantUser $user): array
    {
        return $user->getResolvedPermissions();
    }

    /**
     * Get all roles for a user
     */
    public function getUserRoles(MerchantUser $user): \Illuminate\Database\Eloquent\Collection
    {
        return $user->getRolesWithPermissions();
    }

    /**
     * Assign a role to a user
     */
    public function assignRole(
        MerchantUser $user,
        string|Role $role,
        MerchantUser $assignedBy,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        $roleModel = $role instanceof Role ? $role : Role::findByName($role);

        if (!$roleModel) {
            throw new \InvalidArgumentException("Role not found: {$role}");
        }

        // Check if already has role
        if ($user->hasRole($roleModel->name)) {
            return;
        }

        // Attach role
        $user->roles()->attach($roleModel->id, [
            'id' => Str::uuid(),
            'assigned_by' => $assignedBy->id,
            'assigned_at' => now(),
        ]);

        // Log the action
        RbacAuditLog::logRoleAssigned($user, $roleModel, $assignedBy, $ipAddress, $userAgent);
    }

    /**
     * Remove a role from a user
     */
    public function removeRole(
        MerchantUser $user,
        string|Role $role,
        MerchantUser $removedBy,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        $roleModel = $role instanceof Role ? $role : Role::findByName($role);

        if (!$roleModel) {
            throw new \InvalidArgumentException("Role not found: {$role}");
        }

        // Prevent removing admin role from primary user
        if ($user->isPrimary() && $roleModel->isAdmin()) {
            throw new \Exception('Cannot remove admin role from primary user.');
        }

        // Detach role
        $user->roles()->detach($roleModel->id);

        // Log the action
        RbacAuditLog::logRoleRemoved($user, $roleModel, $removedBy, $ipAddress, $userAgent);
    }

    /**
     * Grant a direct permission to a user
     */
    public function grantPermission(
        MerchantUser $user,
        string|Permission $permission,
        MerchantUser $grantedBy,
        ?Carbon $expiresAt = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        $permissionModel = $permission instanceof Permission 
            ? $permission 
            : Permission::findByName($permission);

        if (!$permissionModel) {
            throw new \InvalidArgumentException("Permission not found: {$permission}");
        }

        // Remove existing if any (to update expiry)
        $user->directPermissions()->detach($permissionModel->id);

        // Attach permission
        $user->directPermissions()->attach($permissionModel->id, [
            'id' => Str::uuid(),
            'granted_by' => $grantedBy->id,
            'granted_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        // Log the action
        RbacAuditLog::logPermissionGranted(
            $user, 
            $permissionModel, 
            $grantedBy, 
            $expiresAt, 
            $ipAddress, 
            $userAgent
        );
    }

    /**
     * Revoke a direct permission from a user
     */
    public function revokePermission(
        MerchantUser $user,
        string|Permission $permission,
        MerchantUser $revokedBy,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        $permissionModel = $permission instanceof Permission 
            ? $permission 
            : Permission::findByName($permission);

        if (!$permissionModel) {
            throw new \InvalidArgumentException("Permission not found: {$permission}");
        }

        // Detach permission
        $user->directPermissions()->detach($permissionModel->id);

        // Log the action
        RbacAuditLog::logPermissionRevoked($user, $permissionModel, $revokedBy, $ipAddress, $userAgent);
    }

    /**
     * Clean expired permissions for a user
     */
    public function cleanExpiredPermissions(MerchantUser $user): int
    {
        return $user->directPermissions()
            ->wherePivot('expires_at', '<=', now())
            ->detach();
    }

    /**
     * Assign default role to a new user
     */
    public function assignDefaultRole(MerchantUser $user, MerchantUser $assignedBy): void
    {
        $defaultRole = Role::getDefaultRole();

        if ($defaultRole) {
            $this->assignRole($user, $defaultRole, $assignedBy);
        }
    }

    /**
     * Make user the primary admin
     */
    public function makePrimaryAdmin(MerchantUser $user, MerchantUser $assignedBy): void
    {
        $user->update(['is_primary' => true]);

        $adminRole = Role::getAdminRole();
        if ($adminRole) {
            $this->assignRole($user, $adminRole, $assignedBy);
        }
    }
}
