<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends BaseModel
{
    use HasUuids;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'is_system',
        'is_default',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_default' => 'boolean',
    ];

    // Relationships
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withTimestamps();
    }

    public function merchantUsers(): BelongsToMany
    {
        return $this->belongsToMany(MerchantUser::class, 'merchant_user_roles')
            ->withPivot(['assigned_by', 'assigned_at']);
    }

    // Helpers
    public function isSystem(): bool
    {
        return $this->is_system;
    }

    public function isAdmin(): bool
    {
        return $this->name === 'admin';
    }

    public function isDefault(): bool
    {
        return $this->is_default;
    }

    // Scopes
    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    // Static helpers
    public static function findByName(string $name): ?self
    {
        return static::where('name', $name)->first();
    }

    public static function getAdminRole(): ?self
    {
        return static::findByName('admin');
    }

    public static function getDefaultRole(): ?self
    {
        return static::default()->first();
    }
}
