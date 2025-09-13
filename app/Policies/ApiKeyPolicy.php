<?php

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\User;

class ApiKeyPolicy
{
    /**
     * Determine whether the user can view any API keys.
     */
    public function viewAny(User $user): bool
    {
        return $user->merchant && $user->hasPermission('apps:read');
    }

    /**
     * Determine whether the user can view the API key.
     */
    public function view(User $user, ApiKey $apiKey): bool
    {
        return $user->merchant_id === $apiKey->app->merchant_id && $user->hasPermission('apps:read');
    }

    /**
     * Determine whether the user can update the API key.
     */
    public function update(User $user, ApiKey $apiKey): bool
    {
        return $user->merchant_id === $apiKey->app->merchant_id && $user->hasPermission('apps:write');
    }

    /**
     * Determine whether the user can delete (revoke) the API key.
     */
    public function delete(User $user, ApiKey $apiKey): bool
    {
        return $user->merchant_id === $apiKey->app->merchant_id && $user->hasPermission('apps:write');
    }

    /**
     * Determine whether the user can regenerate the API key.
     */
    public function regenerate(User $user, ApiKey $apiKey): bool
    {
        return $user->merchant_id === $apiKey->app->merchant_id && $user->hasPermission('apps:write');
    }
}