<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RbacAuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'merchant_id',
        'actor_id',
        'target_user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    // Action constants
    public const ACTION_ROLE_ASSIGNED = 'role_assigned';
    public const ACTION_ROLE_REMOVED = 'role_removed';
    public const ACTION_PERMISSION_GRANTED = 'permission_granted';
    public const ACTION_PERMISSION_REVOKED = 'permission_revoked';

    // Entity type constants
    public const ENTITY_ROLE = 'role';
    public const ENTITY_PERMISSION = 'permission';

    // Relationships
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(MerchantUser::class, 'actor_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(MerchantUser::class, 'target_user_id');
    }

    // Static logging methods
    public static function logRoleAssigned(
        MerchantUser $targetUser,
        Role $role,
        MerchantUser $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): self {
        return static::create([
            'id' => Str::uuid(),
            'merchant_id' => $targetUser->merchant_id,
            'actor_id' => $actor->id,
            'target_user_id' => $targetUser->id,
            'action' => self::ACTION_ROLE_ASSIGNED,
            'entity_type' => self::ENTITY_ROLE,
            'entity_id' => $role->id,
            'new_values' => [
                'role_name' => $role->name,
                'role_display_name' => $role->display_name,
            ],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'created_at' => now(),
        ]);
    }

    public static function logRoleRemoved(
        MerchantUser $targetUser,
        Role $role,
        MerchantUser $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): self {
        return static::create([
            'id' => Str::uuid(),
            'merchant_id' => $targetUser->merchant_id,
            'actor_id' => $actor->id,
            'target_user_id' => $targetUser->id,
            'action' => self::ACTION_ROLE_REMOVED,
            'entity_type' => self::ENTITY_ROLE,
            'entity_id' => $role->id,
            'old_values' => [
                'role_name' => $role->name,
                'role_display_name' => $role->display_name,
            ],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'created_at' => now(),
        ]);
    }

    public static function logPermissionGranted(
        MerchantUser $targetUser,
        Permission $permission,
        MerchantUser $actor,
        ?\Carbon\Carbon $expiresAt = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): self {
        return static::create([
            'id' => Str::uuid(),
            'merchant_id' => $targetUser->merchant_id,
            'actor_id' => $actor->id,
            'target_user_id' => $targetUser->id,
            'action' => self::ACTION_PERMISSION_GRANTED,
            'entity_type' => self::ENTITY_PERMISSION,
            'entity_id' => $permission->id,
            'new_values' => [
                'permission_name' => $permission->name,
                'permission_display_name' => $permission->display_name,
                'expires_at' => $expiresAt?->toIso8601String(),
            ],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'created_at' => now(),
        ]);
    }

    public static function logPermissionRevoked(
        MerchantUser $targetUser,
        Permission $permission,
        MerchantUser $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): self {
        return static::create([
            'id' => Str::uuid(),
            'merchant_id' => $targetUser->merchant_id,
            'actor_id' => $actor->id,
            'target_user_id' => $targetUser->id,
            'action' => self::ACTION_PERMISSION_REVOKED,
            'entity_type' => self::ENTITY_PERMISSION,
            'entity_id' => $permission->id,
            'old_values' => [
                'permission_name' => $permission->name,
                'permission_display_name' => $permission->display_name,
            ],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'created_at' => now(),
        ]);
    }

    // Scopes
    public function scopeForMerchant($query, string $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopeByActor($query, string $actorId)
    {
        return $query->where('actor_id', $actorId);
    }

    public function scopeForTargetUser($query, string $targetUserId)
    {
        return $query->where('target_user_id', $targetUserId);
    }
}
