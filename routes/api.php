<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\PaymentIntentController;
use App\Http\Controllers\Api\v1\BalanceController;
use App\Http\Controllers\Api\v1\MerchantAuthController;
use App\Http\Controllers\Api\v1\AppController;
use App\Http\Controllers\Api\v1\ApiKeyController;
use App\Http\Controllers\Api\v1\WebhookController;
use App\Http\Controllers\Api\v1\AppWebhookController;
use App\Http\Controllers\Api\v1\ChargeController;
use App\Http\Controllers\Api\v1\RefundController;
use App\Http\Controllers\Api\v1\BeneficiaryController;
use App\Http\Controllers\Api\v1\CustomerController;
use App\Http\Controllers\Api\v1\PayoutController;
use App\Http\Controllers\Api\v1\SettlementController;
use App\Http\Controllers\Api\v1\ApplicationDataController;
use App\Http\Controllers\Api\v1\PaymentController;
use App\Http\Controllers\Api\v1\LedgerController;
use App\Http\Controllers\Api\v1\WebhookFlowController;
use App\Http\Controllers\Api\v1\FXController;
use App\Http\Controllers\Api\v1\MerchantWebhookController;
use App\Http\Controllers\Api\v1\SupportedBankController;
use App\Http\Controllers\Api\v1\SupportedPayoutMethodController;
use App\Http\Controllers\Api\v1\RoleManagementController;
use App\Http\Controllers\Api\v1\KycConfigController;
use App\Http\Controllers\Api\v1\MerchantKycController;
use App\Http\Controllers\Api\v1\MerchantUserController;
use App\Http\Controllers\Api\v1\WalletController;
use App\Http\Controllers\Api\v1\WalletTopUpController;
use App\Http\Controllers\Api\v1\WalletTransferController;


Route::middleware(['network.private'])->prefix('v1')->group(function () {
    // Public merchant authentication routes
    Route::prefix('auth/merchant')->group(function () {
        Route::post('login', [MerchantAuthController::class, 'login']);
        Route::post('register', [MerchantAuthController::class, 'register']);
    });
    Route::get('health', [\App\Http\Controllers\Api\v1\HealthController::class, 'check']);

    // application data
    Route::get('application-data', [ApplicationDataController::class, 'index'])->name('index');


    // Protected merchant routes (require authentication)
    Route::middleware(['auth:api'])->group(function () {
        // Merchant authentication management
        Route::prefix('/')->group(function () {
            Route::post('logout', [MerchantAuthController::class, 'logout']);
            Route::get('auth', [MerchantAuthController::class, 'me']);
        });

        // Merchant and app management
        // Route::get('apps', [MerchantController::class, 'getApps']);
        // Route::post('apps', [MerchantController::class, 'createApp']);
        // Route::get('apps/{appId}', [MerchantController::class, 'getApp']);
        // Route::post('apps/{appId}/api_keys', [MerchantController::class, 'createApiKey']);

        Route::prefix('apps')->group(function () {
            // Main CRUD operations
            Route::get('/', [AppController::class, 'index']);
            Route::post('/', [AppController::class, 'store'])->name('store');
            Route::get('/usage-summary', [AppController::class, 'usageSummary'])->name('usage-summary');
            Route::get('/options', [AppController::class, 'options'])->name('options');


            Route::prefix('{appId}')->group(function () {
                Route::get('/', [AppController::class, 'show'])->name('show');
                Route::put('/', [AppController::class, 'update'])->name('update');
                Route::delete('/', [AppController::class, 'destroy'])->name('destroy');

                // App-specific operations
                Route::get('/statistics', [AppController::class, 'statistics'])->name('statistics');
                Route::post('/regenerate-secret', [AppController::class, 'regenerateSecret'])->name('regenerate-secret');

                // Webhook management
                Route::put('/webhook-settings', [AppController::class, 'updateWebhookSettings'])->name('webhook-settings.update');
                Route::post('/test-webhook', [AppController::class, 'testWebhook'])->name('test-webhook');

                // API key management
                Route::post('/api-keys', [AppController::class, 'createApiKey'])->name('api-keys.store');
                Route::get('/api-keys', [ApiKeyController::class, 'apiKeys'])->name('api-keys.apiKeys');
            });

            // App webhooks (outbound)
            Route::prefix('/{appId}/webhooks')->group(function () {
                Route::get('/', [AppWebhookController::class, 'index']);
                Route::post('/', [AppWebhookController::class, 'store']);
                Route::get('{webhookId}', [AppWebhookController::class, 'show']);
            });
            // Webhook event types for outbound webhooks
            Route::get('webhook-event-types', [WebhookFlowController::class, 'getEventTypes']);
            Route::get('webhooks/event-types', [AppWebhookController::class, 'getEventTypes']);
        });

        // Webhook logs (incoming)
        Route::prefix('webhooks')->group(function () {
            Route::get('/', [MerchantWebhookController::class, 'index']);
            Route::get('logs', [WebhookController::class, 'getLogs']);
            Route::post('retry/{webhookId}', [WebhookController::class, 'retryWebhook']);
            Route::get('flow-stats', [WebhookFlowController::class, 'getFlowStats']);
            Route::get('stats', [WebhookController::class, 'getStats']);
            Route::post('bulk-retry', [WebhookController::class, 'bulkRetryWebhooks']);
            Route::get('event-types', [WebhookController::class, 'getAvailableEventTypes']);
            Route::post('replay/{webhookId}', [WebhookController::class, 'replayWebhook']);
            Route::get('{webhookId}', [WebhookController::class, 'getWebhook']);
            Route::patch('{webhookId}', [AppWebhookController::class, 'update']); // update webhook
            Route::post('{webhookId}/rotate-secret', [AppWebhookController::class, 'rotateSecret']); // rotate secret
            Route::post('{webhookId}/test', [AppWebhookController::class, 'test']);
            Route::delete('{webhookId}', [AppWebhookController::class, 'destroy']);
        });

        // beneficiaries management
        Route::prefix('beneficiaries')->group(function () {
            Route::get('/', [BeneficiaryController::class, 'index'])->name('index');
            Route::post('/', [BeneficiaryController::class, 'store'])->name('store');
            Route::get('/{beneficiaryId}', [BeneficiaryController::class, 'show'])->name('show');
            Route::put('/{beneficiaryId}', [BeneficiaryController::class, 'update'])->name('update');
            Route::delete('/{beneficiaryId}', [BeneficiaryController::class, 'destroy'])->name('destroy');
        });

        //Payouts management
        Route::prefix('payouts')->group(function () {
            Route::get('/stats', [PayoutController::class, 'stats'])->name('stats');
            Route::get('/', [PayoutController::class, 'index'])->name('index');
            Route::post('/', [PayoutController::class, 'store'])->name('store');
            Route::get('/{payoutId}', [PayoutController::class, 'show'])->name('show');
            Route::post('/{payoutId}/cancel', [PayoutController::class, 'cancel'])->name('cancel');
        });

        // Settlements management
        Route::prefix('settlements')->group(function () {
            Route::get('/', [SettlementController::class, 'index'])->name('index');
            Route::get('/{settlementId}', [SettlementController::class, 'show'])->name('show');
            Route::get('/{settlementId}/transactions', [SettlementController::class, 'transactions'])->name('transactions');
        });

        // Customers management
        Route::prefix('customers')->group(function () {
            Route::get('/', [CustomerController::class, 'index']);
            Route::post('/', [CustomerController::class, 'store']);
            Route::put('/{customerId}', [CustomerController::class, 'update']);
            Route::get('/{customerId}/payment-methods', [CustomerController::class, 'paymentMethods']);
            Route::get('/{customerId}/payment-intents', [CustomerController::class, 'paymentIntents']);
            Route::get('/{customerId}', [CustomerController::class, 'show']);
        });


        // Balances
        Route::prefix('balances')->group(function () {
            Route::get('/', [BalanceController::class, 'index'])->name('index');
            Route::get('/{currency}', [BalanceController::class, 'show'])->name('show');
            Route::get('/{currency}/transactions', [BalanceController::class, 'transactions'])->name('transactions');
        });

        // Charges
        Route::prefix('charges')->group(function () {
            Route::get('/', [ChargeController::class, 'index'])->name('index');
            Route::get('/{chargeId}', [ChargeController::class, 'show'])->name('show');
            Route::post('/{chargeId}/capture', [ChargeController::class, 'capture'])->name('capture');
        });
        // Refunds  
        Route::prefix('refunds')->group(function () {
            Route::get('/', [RefundController::class, 'index']);
            Route::post('/', [RefundController::class, 'create']);
            Route::get('/{refundId}', [RefundController::class, 'show']);
        });

        // Ledger and Financial Reports
        Route::prefix('ledger')->group(function () {
            Route::get('reports', [LedgerController::class, 'getFinancialReports']);
            Route::get('merchant-balances', [LedgerController::class, 'getMerchantBalances']);
            Route::get('balances', [LedgerController::class, 'getAccountBalances']);
            Route::get('validate', [LedgerController::class, 'validateLedger']);
            Route::get('reconciliation', [LedgerController::class, 'getReconciliation']);
            Route::get('gateway-analysis', [LedgerController::class, 'getGatewayFeeAnalysis']);
            Route::get('anomalies', [LedgerController::class, 'detectAnomalies']);
        });

        //Payments Management
        Route::prefix('payment-gateways')->group(function () {
            Route::get('/available-gateways', [PaymentController::class, 'getAvailableGateways']);
            Route::get('/gateway-fees', [PaymentController::class, 'gatewayFees']);
            Route::get('/best-gateway', [PaymentController::class, 'getBestGateway']);
        });

        // Merchant Profile
        Route::put('merchant/profile', [MerchantAuthController::class, 'updateProfile']);

        // Merchant KYC
        Route::prefix('merchants/kyc')->group(function () {
            Route::get('status', [MerchantKycController::class, 'getStatus']);
            Route::get('documents', [MerchantKycController::class, 'getDocuments']);
            Route::post('documents', [MerchantKycController::class, 'uploadDocument']);
            Route::delete('documents/{documentId}', [MerchantKycController::class, 'deleteDocument']);
            Route::post('submit', [MerchantKycController::class, 'submitForReview']);
            Route::get('upgrade-requirements', [MerchantKycController::class, 'getUpgradeRequirements']);
            Route::post('check-limit', [MerchantKycController::class, 'checkTransactionLimit']);
            Route::get('config', [MerchantKycController::class, 'getConfig']);
            Route::get('profile', [MerchantKycController::class, 'getKycProfile']);
            Route::put('profile', [MerchantKycController::class, 'updateKycProfile']);
        });


        // api keys management
        Route::prefix('api-keys')->group(function () {
            Route::get('/', [ApiKeyController::class, 'index'])->name('index');
            Route::post('/', [ApiKeyController::class, 'create'])->name('create');
            Route::get('/{keyId}', [ApiKeyController::class, 'show'])->name('show');
            Route::post('/{keyId}/revoke', [ApiKeyController::class, 'revoke'])->name('revoke');
            Route::post('/{keyId}/regenerate', [ApiKeyController::class, 'regenerate'])->name('regenerate');
        });


        // Dashboard routes (for merchant dashboard - protected by Sanctum)
        // Merchant dashboard endpoints
        // Route::get('merchants/me', [MerchantController::class, 'me']);
        // Route::get('apps', [MerchantController::class, 'getApps']);
        // Route::post('apps', [MerchantController::class, 'createApp']);
        // Route::get('apps/{appId}', [MerchantController::class, 'getApp']);
        // Route::post('apps/{appId}/api_keys', [MerchantController::class, 'createApiKey']);

        // Dashboard balances
        Route::get('balances', [BalanceController::class, 'index']);
        Route::get('balances/{currency}', [BalanceController::class, 'show']);
        Route::get('balances/{currency}/transactions', [BalanceController::class, 'transactions']);

        // Dashboard payment intents
        Route::prefix('payment-intents')->group(function () {
            Route::apiResource('/', PaymentIntentController::class)->only(['index', 'store', 'show']);
            Route::post('/{id}/capture', [PaymentIntentController::class, 'capture']);
            Route::post('/{id}/cancel', [PaymentIntentController::class, 'cancel']);
            Route::post('/{id}/confirm', [PaymentIntentController::class, 'confirm']);
            Route::get('/analytics', [PaymentIntentController::class, 'analytics']);
        });

        // Gateway Pricing Management
        Route::prefix('gateway-pricing')->group(function () {
            Route::get('merchant/{merchantId}', [\App\Http\Controllers\Api\v1\GatewayPricingController::class, 'getMerchantPricing']);
            Route::get('default', [\App\Http\Controllers\Api\v1\GatewayPricingController::class, 'getDefaultPricing']);
            Route::post('merchant', [\App\Http\Controllers\Api\v1\GatewayPricingController::class, 'createMerchantPricing']);
            Route::put('merchants/{configId}', [\App\Http\Controllers\Api\v1\GatewayPricingController::class, 'updateMerchantPricing']);
            Route::delete('merchants/{configId}', [\App\Http\Controllers\Api\v1\GatewayPricingController::class, 'deleteMerchantPricing']);
            Route::post('calculate-fees', [\App\Http\Controllers\Api\v1\GatewayPricingController::class, 'calculateFees']);
            Route::get('options', [\App\Http\Controllers\Api\v1\GatewayPricingController::class, 'getGatewayOptions']);
        });

        // Analytics
        Route::prefix('analytics')->group(function () {
            Route::get('dashboard', [\App\Http\Controllers\Api\v1\AnalyticsController::class, 'getDashboardMetrics']);
            Route::get('developer', [\App\Http\Controllers\Api\v1\AnalyticsController::class, 'getDeveloperMetrics']);
            Route::get('activity', [\App\Http\Controllers\Api\v1\AnalyticsController::class, 'getSystemActivity']);
            Route::get('chart', [\App\Http\Controllers\Api\v1\AnalyticsController::class, 'getChartData']);
            Route::get('system-health', [\App\Http\Controllers\Api\v1\AnalyticsController::class, 'getSystemHealth']);
        });

        // Team Management (MerchantUserController)
        Route::prefix('merchant/users')->group(function () {
            Route::get('/', [MerchantUserController::class, 'index']);
            Route::post('/', [MerchantUserController::class, 'store']);
            Route::get('/{userId}', [MerchantUserController::class, 'show']);
            Route::put('/{userId}', [MerchantUserController::class, 'update']);
            Route::delete('/{userId}', [MerchantUserController::class, 'destroy']);
            Route::post('/{userId}/resend-invite', [MerchantUserController::class, 'resendInvite']);
        });

        // Role & Permission routes (move out of nested prefix)
        Route::prefix('roles')->group(function () {
            Route::get('/', [RoleManagementController::class, 'listRoles']);
            Route::get('/permissions', [RoleManagementController::class, 'listPermissions']);
        });

        // User role/permission management  
        Route::prefix('merchant/users/{userId}')->group(function () {
            Route::get('/roles', [RoleManagementController::class, 'getUserRoles']);
            Route::post('/roles', [RoleManagementController::class, 'assignRole']);
            Route::delete('/roles/{roleId}', [RoleManagementController::class, 'removeRole']);
            Route::get('/permissions', [RoleManagementController::class, 'getUserPermissions']);
            Route::post('/permissions', [RoleManagementController::class, 'grantPermission']);
            Route::delete('/permissions/{permissionId}', [RoleManagementController::class, 'revokePermission']);
        });

        Route::get('merchant/rbac/audit-logs', [RoleManagementController::class, 'getAuditLogs']);




        // API routes (for third-party integrations - protected by API key)
        // Route::middleware(['api.key', 'tenant.context'])->group(function () {
        //     // Payment intents
        //     Route::prefix('payment-intents')->group(function () {
        //         Route::apiResource('/', PaymentIntentController::class)->only(['index', 'store', 'show']);
        //         Route::post('/{id}/capture', [PaymentIntentController::class, 'capture']);
        //         Route::post('/{id}/cancel', [PaymentIntentController::class, 'cancel']);
        //         Route::post('/{id}/confirm', [PaymentIntentController::class, 'confirm']);
        //         Route::get('/analytics', [PaymentIntentController::class, 'analytics']);
        //     });
        // });

        // Balances
        Route::get('balances', [BalanceController::class, 'index']);
        Route::get('balances/{currency}', [BalanceController::class, 'show']);
        Route::get('balances/{currency}/transactions', [BalanceController::class, 'transactions']);

        //fx trades
        Route::prefix('fx')->group(function () {
            Route::post('quotes', [FXController::class, 'getQuotes']);
            Route::post('trades', [FXController::class, 'executeTrade']);
            Route::get('trades', [FXController::class, 'getTradeHistory']);
            Route::get('rates', [FXController::class, 'getExchangeRates']);
        });

        // Supported Banks and Payout Methods
        Route::get('supported-banks', [SupportedBankController::class, 'index']);
        Route::get('supported-payout-methods', [SupportedPayoutMethodController::class, 'index']);

        // Wallet Management
        Route::prefix('wallets')->group(function () {
            Route::get('/', [WalletController::class, 'index']);
            Route::post('/', [WalletController::class, 'store']);
            Route::post('/transfer', [WalletTransferController::class, 'transfer']);

            Route::prefix('{walletId}')->group(function () {
                Route::get('/', [WalletController::class, 'show']);
                Route::patch('/', [WalletController::class, 'update']);
                Route::get('/balance', [WalletController::class, 'balance']);
                Route::get('/transactions', [WalletController::class, 'transactions']);
                Route::post('/freeze', [WalletController::class, 'freeze']);
                Route::post('/unfreeze', [WalletController::class, 'unfreeze']);
                Route::post('/sweep', [WalletTransferController::class, 'sweepFromBalance']);

                // Wallet Top-ups
                Route::prefix('top-ups')->group(function () {
                    Route::get('/', [WalletTopUpController::class, 'index']);
                    Route::post('/', [WalletTopUpController::class, 'store']);
                    Route::get('/{topUpId}', [WalletTopUpController::class, 'show']);
                    Route::post('/{topUpId}/cancel', [WalletTopUpController::class, 'cancel']);
                });
            });
        });
    });

    // Public webhook endpoints (no auth required)
    Route::prefix('webhooks')->group(function () {
        Route::post('stripe', [WebhookController::class, 'handleStripe']);
        Route::post('mpesa', [WebhookController::class, 'handleMpesa']);
        Route::post('mpesa/b2c/result', [WebhookController::class, 'handleMpesaB2CResult']);
        Route::post('mpesa/b2c/timeout', [WebhookController::class, 'handleMpesaB2CTimeout']);
        Route::post('telebirr', [WebhookController::class, 'handleTelebirr']);
        Route::post('airtel/collection', [WebhookController::class, 'handleAirtelMoneyCollection']);
        Route::post('airtel/disbursement', [WebhookController::class, 'handleAirtelMoneyDisbursement']);
        // MTN MoMo webhooks
        Route::post('mtn-momo/collection', [WebhookController::class, 'handleMTNMoMoCollection']);
        Route::post('mtn-momo/disbursement', [WebhookController::class, 'handleMTNMoMoDisbursement']);

        // Wallet Top-up webhooks
        Route::post('wallet/top-up/{topUpId}', [WebhookController::class, 'handleWalletTopUpCallback']);
        Route::post('wallet/top-up/bank-transfer', [WebhookController::class, 'handleWalletBankTransferCallback']);
        Route::post('wallet/top-up/mobile-money/{provider}', [WebhookController::class, 'handleWalletMobileMoneyCallback']);
    });

    // Public KYC config endpoints (no auth required - for onboarding)
    Route::prefix('kyc')->group(function () {
        Route::get('countries', [KycConfigController::class, 'getSupportedCountries']);
        Route::get('config/{countryCode}', [KycConfigController::class, 'getCountryConfig']);
        Route::get('/config/{countryCode}/tiers', [KycConfigController::class, 'getTiers']);
        Route::get('tiers/{countryCode}/{tierLevel}', [KycConfigController::class, 'getTierRequirements']);
        Route::get('config/{countryCode}/document-types', [KycConfigController::class, 'getDocumentTypes']);
        Route::post('validate-document-value', [KycConfigController::class, 'validateDocumentValue']);
    });
});
