<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends BaseModel
{
    use HasUuids;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'module',
    ];

    // Relationships
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')
            ->withTimestamps();
    }

    public function merchantUsers(): BelongsToMany
    {
        return $this->belongsToMany(MerchantUser::class, 'merchant_user_permissions')
            ->withPivot(['granted_by', 'granted_at', 'expires_at']);
    }

    // Scopes
    public function scopeByModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    // Static helpers
    public static function findByName(string $name): ?self
    {
        return static::where('name', $name)->first();
    }

    public static function getByModule(string $module): \Illuminate\Database\Eloquent\Collection
    {
        return static::byModule($module)->get();
    }

    public static function getAllGroupedByModule(): array
    {
        return static::all()
            ->groupBy('module')
            ->toArray();
    }
}
