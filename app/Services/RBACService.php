<?php

namespace App\Services;

use App\Models\MerchantUser;
use App\Models\Permission;
use App\Models\RbacAuditLog;
use App\Models\Role;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
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
    public function getUserRoles(MerchantUser $user)
    {
        return $user->getRolesWithPermissions();
    }

    /**
     * Assign a role to a user
     */
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
        // Start transaction for atomic operations
        DB::beginTransaction();

        try {
            // Log role assignment attempt
            Log::info('Role assignment attempt', [
                'target_user_id' => $user->id,
                'target_user_email' => $user->email,
                'role_input' => $role instanceof Role ? $role->name : $role,
                'assigned_by_id' => $assignedBy->id,
                'assigned_by_email' => $assignedBy->email,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'timestamp' => now()
            ]);

            $roleModel = $role instanceof Role ? $role : Role::findByName($role);

            if (!$roleModel) {
                Log::error('Role assignment failed - role not found', [
                    'role_input' => $role,
                    'target_user_id' => $user->id,
                    'assigned_by_id' => $assignedBy->id
                ]);

                throw new \InvalidArgumentException("Role not found: {$role}");
            }

            // Check if already has role
            if ($user->hasRole($roleModel->name)) {
                Log::info('Role assignment skipped - user already has role', [
                    'user_id' => $user->id,
                    'role_id' => $roleModel->id,
                    'role_name' => $roleModel->name
                ]);
                DB::commit();
                return;
            }

            // Log before role attachment
            Log::info('Attaching role to user', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'role_id' => $roleModel->id,
                'role_name' => $roleModel->name,
                'assigned_by_id' => $assignedBy->id,
                'assigned_by_email' => $assignedBy->email,
                'assignment_uuid' => Str::uuid()
            ]);

            // Attach role
            $user->roles()->attach($roleModel->id, [
                'id' => Str::uuid(),
                'assigned_by' => $assignedBy->id,
                'assigned_at' => now(),
            ]);

            // Log successful role attachment
            Log::info('Role attached successfully', [
                'user_id' => $user->id,
                'role_id' => $roleModel->id,
                'assignment_timestamp' => now()
            ]);

            // Log the action in audit log
            Log::info('Creating RBAC audit log entry', [
                'event_type' => 'role_assigned',
                'user_id' => $user->id,
                'role_id' => $roleModel->id
            ]);

            RbacAuditLog::logRoleAssigned($user, $roleModel, $assignedBy, $ipAddress, $userAgent);

            // Log user's updated permissions
            $updatedPermissions = $user->getResolvedPermissions();
            Log::info('User permissions after role assignment', [
                'user_id' => $user->id,
                'total_permissions' => count($updatedPermissions),
                'permissions_sample' => array_slice($updatedPermissions, 0, 10) // Log first 10 permissions
            ]);

            // Send notification if needed (e.g., email notification for role assignment)
            $this->notifyRoleAssignment($user, $roleModel, $assignedBy);

            // Commit transaction
            DB::commit();

            // Log successful completion
            Log::info('Role assignment completed successfully', [
                'user_id' => $user->id,
                'role_name' => $roleModel->name,
                'assigned_by_id' => $assignedBy->id,
                'completion_time' => now()
            ]);
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();
            throw $e; // Re-throw validation errors

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Role assignment failed', [
                'user_id' => $user->id,
                'role_input' => $role instanceof Role ? $role->name : $role,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip_address' => $ipAddress,
                'timestamp' => now()
            ]);

            throw new \RuntimeException("Failed to assign role: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Send role assignment notification
     */
    protected function notifyRoleAssignment(MerchantUser $user, Role $role, MerchantUser $assignedBy): void
    {
        try {
            Log::info('Sending role assignment notification', [
                'user_id' => $user->id,
                'role_name' => $role->name,
                'assigned_by' => $assignedBy->id
            ]);

            // Example: Send email notification
            // Notification::send($user, new RoleAssignedNotification($role, $assignedBy));

        } catch (\Exception $e) {
            Log::warning('Failed to send role assignment notification', [
                'error' => $e->getMessage(),
                'user_id' => $user->id
            ]);
        }
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
