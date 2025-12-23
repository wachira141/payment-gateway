<?php

namespace App\Policies;

use App\Models\MerchantUser;
use App\Models\App;
use App\Models\AppWebhook;

class AppWebhookPolicy
{
    /**
     * Determine whether the user can view any app webhooks.
     */
    public function viewAny(MerchantUser $user): bool
    {
        return $user->hasPermission('webhooks:read');
    }

    /**
     * Determine whether the user can view the app webhook.
     */
    public function view(MerchantUser $user, AppWebhook $appWebhook): bool
    {
        return $user->merchant_id === $appWebhook->app->merchant_id
            && $user->hasPermission('webhooks:read');
    }

    /**
     * Determine whether the user can create app webhooks.
     */
    public function create(MerchantUser $user, App $app): bool
    {
        return $user->merchant_id === $app->merchant_id
            && $user->hasPermission('webhooks:manage');
    }

    /**
     * Determine whether the user can update the app webhook.
     */
    public function update(MerchantUser $user, AppWebhook $appWebhook): bool
    {
        return $user->merchant_id === $appWebhook->app->merchant_id
            && $user->hasPermission('webhooks:manage');
    }

    /**
     * Determine whether the user can delete the app webhook.
     */
    public function delete(MerchantUser $user, AppWebhook $appWebhook): bool
    {
        return $user->merchant_id === $appWebhook->app->merchant_id
            && $user->hasPermission('webhooks:manage');
    }
}
