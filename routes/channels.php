<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Merchant;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Admin channels
Broadcast::channel('admin.monitoring', function ($user) {
    return $user->is_admin ?? false;
});

Broadcast::channel('admin.alerts', function ($user) {
    return $user->is_admin ?? false;
});

Broadcast::channel('admin.payments', function ($user) {
    return $user->is_admin ?? false;
});

// Merchant-specific channels
Broadcast::channel('merchant.{merchantId}.disbursements', function ($user, $merchantId) {
    // Check if the user has access to this merchant
    $merchant = Merchant::where('merchant_id', $merchantId)->first();
    
    if (!$merchant) {
        return false;
    }
    
    // Check if user owns this merchant or is a team member
    return $merchant->user_id === $user->id || 
           $merchant->teamMembers()->where('user_id', $user->id)->exists();
});

Broadcast::channel('merchant.{merchantId}.payments', function ($user, $merchantId) {
    $merchant = Merchant::where('merchant_id', $merchantId)->first();
    
    if (!$merchant) {
        return false;
    }
    
    return $merchant->user_id === $user->id || 
           $merchant->teamMembers()->where('user_id', $user->id)->exists();
});

Broadcast::channel('merchant.{merchantId}.wallets', function ($user, $merchantId) {
    $merchant = Merchant::where('merchant_id', $merchantId)->first();
    
    if (!$merchant) {
        return false;
    }
    
    return $merchant->user_id === $user->id || 
           $merchant->teamMembers()->where('user_id', $user->id)->exists();
});
