<?php

namespace App\Policies;

use App\Models\User;
use App\Models\App;
use App\Models\AppWebhook;

class AppWebhookPolicy
{
    /**
     * Determine whether the user can view any app webhooks.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the app webhook.
     */
    public function view(User $user, AppWebhook $appWebhook): bool
    {
        return $user->merchant_id === $appWebhook->app->merchant_id;
    }

    /**
     * Determine whether the user can create app webhooks.
     */
    public function create(User $user, App $app): bool
    {
        return $user->merchant_id === $app->merchant_id;
    }

    /**
     * Determine whether the user can update the app webhook.
     */
    public function update(User $user, AppWebhook $appWebhook): bool
    {
        return $user->merchant_id === $appWebhook->app->merchant_id;
    }

    /**
     * Determine whether the user can delete the app webhook.
     */
    public function delete(User $user, AppWebhook $appWebhook): bool
    {
        return $user->merchant_id === $appWebhook->app->merchant_id;
    }
}