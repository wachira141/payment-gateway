<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class MerchantUser extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    protected $primaryKey = 'id'; // Ensure primary key is 'id'
    protected $keyType = 'string'; // Set key type to string
    public $incrementing = false; // Disable auto-incrementin

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
    ];

    /**
     * Get the merchant
     */
    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Check if user has permission
     */
    public function hasPermission($permission)
    {
        // Owner and admin have all permissions
        if (in_array($this->role, ['owner', 'admin'])) {
            return true;
        }

        // Check specific permissions
        return in_array($permission, $this->permissions ?? []);
    }

    /**
     * Check if user can manage apps
     */
    public function canManageApps()
    {
        return $this->hasPermission('manage_apps');
    }

    /**
     * Check if user can view payments
     */
    public function canViewPayments()
    {
        return $this->hasPermission('view_payments');
    }

    /**
     * Check if user can manage payouts
     */
    public function canManagePayouts()
    {
        return $this->hasPermission('manage_payouts');
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