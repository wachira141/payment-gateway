<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\MerchantUser;
use App\Models\Role;
use App\Services\RBACService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MerchantUserController extends Controller
{
    protected RBACService $rbacService;

    public function __construct(RBACService $rbacService)
    {
        $this->rbacService = $rbacService;
    }

    /**
     * List all team members for the authenticated merchant
     */
    public function index(Request $request): JsonResponse
    {
        $merchantUser = $request->user();

        // Check permission
        if (!$merchantUser->hasPermission('team.view')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view team members.',
            ], 403);
        }
        
        $members = MerchantUser::where('merchant_id', $merchantUser->merchant_id)
            ->with(['roles.permissions'])
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get()
            ->map(function ($member) {
                return $this->formatMemberWithRBAC($member);
            });

        return response()->json([
            'success' => true,
            'data' => $members,
        ]);
    }

    /**
     * Invite a new team member
     */
    public function store(Request $request): JsonResponse
    {
        $merchantUser = $request->user();

        // Check permission
        if (!$merchantUser->hasPermission('team.invite')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to invite team members.',
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('merchant_users')->where(function ($query) use ($merchantUser) {
                    return $query->where('merchant_id', $merchantUser->merchant_id);
                }),
            ],
            'role_id' => 'nullable|uuid|exists:roles,id',
        ]);

        $inviteToken = Str::random(64);

        $newMember = MerchantUser::create([
            'merchant_id' => $merchantUser->merchant_id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make(Str::random(32)), // Temporary password
            'is_primary' => false,
            'status' => 'pending',
            'metadata' => [
                'invite_token' => $inviteToken,
                'invited_at' => now()->toISOString(),
                'invited_by' => $merchantUser->id,
            ],
        ]);

        // Assign role if provided
        if ($request->role_id) {
            $role = Role::find($request->role_id);
            if ($role) {
                $this->rbacService->assignRole(
                    $newMember,
                    $role,
                    $merchantUser,
                    $request->ip(),
                    $request->userAgent()
                );
            }
        }

        // TODO: Send invitation email with $inviteToken

        $newMember->load(['roles.permissions']);

        return response()->json([
            'success' => true,
            'message' => 'Team member invited successfully',
            'data' => $this->formatMemberWithRBAC($newMember),
        ], 201);
    }

    /**
     * Get a single team member
     */
    public function show(Request $request, string $userId): JsonResponse
    {
        $merchantUser = $request->user();

        // Check permission
        if (!$merchantUser->hasPermission('team.view')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view team members.',
            ], 403);
        }
        
        $member = MerchantUser::where('merchant_id', $merchantUser->merchant_id)
            ->where('id', $userId)
            ->with(['roles.permissions'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $this->formatMemberWithRBAC($member),
        ]);
    }

    /**
     * Update a team member
     */
    public function update(Request $request, string $userId): JsonResponse
    {
        $merchantUser = $request->user();

        // Check permission (allow users to update themselves, otherwise need team.invite permission)
        if ($userId !== $merchantUser->id && !$merchantUser->hasPermission('team.invite')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to update team members.',
            ], 403);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:active,inactive',
        ]);
        
        $member = MerchantUser::where('merchant_id', $merchantUser->merchant_id)
            ->where('id', $userId)
            ->firstOrFail();

        // Prevent deactivating primary user
        if ($member->is_primary && $request->status === 'inactive') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate the primary administrator',
            ], 422);
        }

        $member->update($request->only(['name', 'status']));
        $member->load(['roles.permissions']);

        return response()->json([
            'success' => true,
            'message' => 'Team member updated successfully',
            'data' => $this->formatMemberWithRBAC($member),
        ]);
    }

    /**
     * Remove a team member
     */
    public function destroy(Request $request, string $userId): JsonResponse
    {
        $merchantUser = $request->user();

        // Check permission
        if (!$merchantUser->hasPermission('team.remove')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to remove team members.',
            ], 403);
        }
        
        $member = MerchantUser::where('merchant_id', $merchantUser->merchant_id)
            ->where('id', $userId)
            ->firstOrFail();

        // Prevent deleting primary user
        if ($member->is_primary) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot remove the primary administrator',
            ], 422);
        }

        // Prevent self-deletion
        if ($member->id === $merchantUser->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot remove yourself',
            ], 422);
        }

        $member->delete();

        return response()->json([
            'success' => true,
            'message' => 'Team member removed successfully',
        ]);
    }

    /**
     * Resend invitation email
     */
    public function resendInvite(Request $request, string $userId): JsonResponse
    {
        $merchantUser = $request->user();

        // Check permission
        if (!$merchantUser->hasPermission('team.invite')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to resend invitations.',
            ], 403);
        }
        
        $member = MerchantUser::where('merchant_id', $merchantUser->merchant_id)
            ->where('id', $userId)
            ->whereIn('status', ['invited', 'pending'])
            ->firstOrFail();

        // Regenerate invite token
        $member->update([
            'metadata' => array_merge($member->metadata ?? [], [
                'invite_token' => Str::random(64),
                'invited_at' => now()->toISOString(),
                'invited_by' => $merchantUser->id,
            ]),
        ]);

        // TODO: Resend invitation email

        return response()->json([
            'success' => true,
            'message' => 'Invitation resent successfully',
        ]);
    }

    /**
     * Format member with RBAC data
     */
    protected function formatMemberWithRBAC(MerchantUser $member): array
    {
        return [
            'id' => $member->id,
            'merchant_id' => $member->merchant_id,
            'name' => $member->name,
            'email' => $member->email,
            'phone' => $member->phone,
            'is_primary' => $member->is_primary,
            'status' => $member->status,
            'roles' => $member->roles->map(fn($role) => [
                'id' => $role->pivot->id ?? $role->id,
                'role_id' => $role->id,
                'role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'is_system' => $role->is_system,
                    'permissions' => $role->permissions->pluck('name'),
                ],
                'assigned_by' => $role->pivot->assigned_by ?? null,
                'assigned_at' => $role->pivot->assigned_at ?? null,
            ]),
            'direct_permissions' => $member->directPermissions()
                ->where(function ($q) {
                    $q->whereNull('merchant_user_permissions.expires_at')
                      ->orWhere('merchant_user_permissions.expires_at', '>', now());
                })
                ->get()
                ->map(fn($perm) => [
                    'id' => $perm->pivot->id ?? $perm->id,
                    'permission_id' => $perm->id,
                    'permission' => [
                        'id' => $perm->id,
                        'name' => $perm->name,
                        'display_name' => $perm->display_name,
                        'module' => $perm->module,
                    ],
                    'granted_by' => $perm->pivot->granted_by ?? null,
                    'granted_at' => $perm->pivot->granted_at ?? null,
                    'expires_at' => $perm->pivot->expires_at ?? null,
                ]),
            'resolved_permissions' => $member->getResolvedPermissions(),
            'invited_at' => $member->metadata['invited_at'] ?? null,
            'created_at' => $member->created_at,
            'updated_at' => $member->updated_at,
        ];
    }
}
