<?php

namespace App\Policies;

use App\Models\App;
use App\Models\MerchantUser;

class AppPolicy
{
    /**
     * Determine whether the user can view any apps.
     */
    public function viewAny(MerchantUser $user): bool
    {
        return $user->merchant && $user->hasPermission('apps:read');
    }

    /**
     * Determine whether the user can view the app.
     */
    public function view(MerchantUser $user, App $app): bool
    {
        return $user->merchant_id === $app->merchant_id && $user->hasPermission('apps:read');
    }

    /**
     * Determine whether the user can create apps.
     */
    public function create(MerchantUser $user): bool
    {
        return $user->merchant && $user->hasPermission('apps:write');
    }

    /**
     * Determine whether the user can update the app.
     */
    public function update(MerchantUser $user, App $app): bool
    {
        return $user->merchant_id === $app->merchant_id && $user->hasPermission('apps:write');
    }

    /**
     * Determine whether the user can delete the app.
     */
    public function delete(MerchantUser $user, App $app): bool
    {
        return $user->merchant_id === $app->merchant_id && $user->hasPermission('apps:delete');
    }

    /**
     * Determine whether the user can create API keys for the app.
     */
    public function createApiKey(MerchantUser $user, App $app): bool
    {
        return $user->merchant_id === $app->merchant_id 
            && $user->hasPermission('apps:write') 
            && $app->is_active;
    }

    /**
     * Determine whether the user can regenerate the app's client secret.
     */
    public function regenerateSecret(MerchantUser $user, App $app): bool
    {
        return $user->merchant_id === $app->merchant_id && $user->hasPermission('apps:write');
    }

    /**
     * Determine whether the user can manage webhook settings.
     */
    public function manageWebhooks(MerchantUser $user, App $app): bool
    {
        return $user->merchant_id === $app->merchant_id && $user->hasPermission('webhooks:manage');
    }

    /**
     * Determine whether the user can view app statistics.
     */
    public function viewStatistics(MerchantUser $user, App $app): bool
    {
        return $user->merchant_id === $app->merchant_id && $user->hasPermission('apps:read');
    }
}