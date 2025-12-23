<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\MerchantUser;
use App\Models\Permission;
use App\Models\RbacAuditLog;
use App\Models\Role;
use App\Services\RBACService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleManagementController extends Controller
{
    protected RBACService $rbacService;

    public function __construct(RBACService $rbacService)
    {
        $this->rbacService = $rbacService;
    }

    /**
     * List all available roles
     */
    public function listRoles(): JsonResponse
    {
        $roles = Role::with('permissions')->get();

        return response()->json([
            'success' => true,
            'data' => $roles,
        ]);
    }

    /**
     * List all permissions grouped by module
     */
    public function listPermissions(): JsonResponse
    {
        $permissions = Permission::all()->groupBy('module');

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }

    /**
     * Get roles for a specific user
     */
    public function getUserRoles(Request $request, string $userId): JsonResponse
    {
        $currentUser = $request->user();
        
        // Verify user belongs to same merchant
        $targetUser = MerchantUser::where('id', $userId)
            ->where('merchant_id', $currentUser->merchant_id)
            ->firstOrFail();

        $roles = $this->rbacService->getUserRoles($targetUser);

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $userId,
                'roles' => $roles,
            ],
        ]);
    }

    /**
     * Assign a role to a user
     */
    public function assignRole(Request $request, string $userId): JsonResponse
    {
        $request->validate([
            'role_id' => 'required|uuid|exists:roles,id',
        ]);

        $currentUser = $request->user();

        // Verify admin permission
        if (!$currentUser->hasPermission('team.manage_roles')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to manage roles.',
            ], 403);
        }

        $targetUser = MerchantUser::where('id', $userId)
            ->where('merchant_id', $currentUser->merchant_id)
            ->firstOrFail();

        $role = Role::findOrFail($request->role_id);

        $this->rbacService->assignRole(
            $targetUser,
            $role,
            $currentUser,
            $request->ip(),
            $request->userAgent()
        );

        return response()->json([
            'success' => true,
            'message' => 'Role assigned successfully.',
            'data' => [
                'user_id' => $userId,
                'role' => $role,
            ],
        ]);
    }

    /**
     * Remove a role from a user
     */
    public function removeRole(Request $request, string $userId, string $roleId): JsonResponse
    {
        $currentUser = $request->user();

        // Verify admin permission
        if (!$currentUser->hasPermission('team.manage_roles')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to manage roles.',
            ], 403);
        }

        $targetUser = MerchantUser::where('id', $userId)
            ->where('merchant_id', $currentUser->merchant_id)
            ->firstOrFail();

        $role = Role::findOrFail($roleId);

        try {
            $this->rbacService->removeRole(
                $targetUser,
                $role,
                $currentUser,
                $request->ip(),
                $request->userAgent()
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Role removed successfully.',
        ]);
    }

    /**
     * Get direct permissions for a user
     */
    public function getUserPermissions(Request $request, string $userId): JsonResponse
    {
        $currentUser = $request->user();

        $targetUser = MerchantUser::where('id', $userId)
            ->where('merchant_id', $currentUser->merchant_id)
            ->firstOrFail();

        $directPermissions = $targetUser->getDirectPermissionsWithDetails();
        $resolvedPermissions = $this->rbacService->getUserPermissions($targetUser);

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $userId,
                'direct_permissions' => $directPermissions,
                'resolved_permissions' => $resolvedPermissions,
            ],
        ]);
    }

    /**
     * Grant a permission to a user
     */
    public function grantPermission(Request $request, string $userId): JsonResponse
    {
        $request->validate([
            'permission_id' => 'required|uuid|exists:permissions,id',
            'expires_at' => 'nullable|date|after:now',
        ]);

        $currentUser = $request->user();

        // Verify admin permission
        if (!$currentUser->hasPermission('team.manage_roles')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to grant permissions.',
            ], 403);
        }

        $targetUser = MerchantUser::where('id', $userId)
            ->where('merchant_id', $currentUser->merchant_id)
            ->firstOrFail();

        $permission = Permission::findOrFail($request->permission_id);
        $expiresAt = $request->expires_at ? Carbon::parse($request->expires_at) : null;

        $this->rbacService->grantPermission(
            $targetUser,
            $permission,
            $currentUser,
            $expiresAt,
            $request->ip(),
            $request->userAgent()
        );

        return response()->json([
            'success' => true,
            'message' => 'Permission granted successfully.',
            'data' => [
                'user_id' => $userId,
                'permission' => $permission,
                'expires_at' => $expiresAt?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Revoke a permission from a user
     */
    public function revokePermission(Request $request, string $userId, string $permissionId): JsonResponse
    {
        $currentUser = $request->user();

        // Verify admin permission
        if (!$currentUser->hasPermission('team.manage_roles')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to revoke permissions.',
            ], 403);
        }

        $targetUser = MerchantUser::where('id', $userId)
            ->where('merchant_id', $currentUser->merchant_id)
            ->firstOrFail();

        $permission = Permission::findOrFail($permissionId);

        $this->rbacService->revokePermission(
            $targetUser,
            $permission,
            $currentUser,
            $request->ip(),
            $request->userAgent()
        );

        return response()->json([
            'success' => true,
            'message' => 'Permission revoked successfully.',
        ]);
    }

    /**
     * Get RBAC audit logs for the merchant
     */
    public function getAuditLogs(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        // Verify admin permission
        if (!$currentUser->hasPermission('team.manage_roles')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view audit logs.',
            ], 403);
        }

        $logs = RbacAuditLog::forMerchant($currentUser->merchant_id)
            ->with(['actor', 'targetUser'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }
}
