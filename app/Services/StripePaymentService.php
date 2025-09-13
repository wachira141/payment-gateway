<?php

namespace App\Services;

use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Customer;
use Stripe\PaymentMethod;
use Stripe\Refund;
use App\Contracts\PaymentStatusInterface;
use App\Services\PaymentStatusMapper;

use App\Models\PaymentTransaction;
use App\Models\PaymentMethod as PaymentMethodModel;

class StripePaymentService implements PaymentStatusInterface
{
    private $stripeSecretKey;

    public function __construct()
    {
        $this->stripeSecretKey = config('services.stripe.secret');
        Stripe::setApiKey($this->stripeSecretKey);
    }

    /**
     * Create a payment intent
     */
    public function createPaymentIntent($amount, $currency, $user, $metadata = [])
    {
        try {
            $customer = $this->getOrCreateCustomer($user);

            $paymentIntent = PaymentIntent::create([
                'amount' => $amount * 100, // Convert to cents
                'currency' => strtolower($currency),
                'customer' => $customer->id,
                'metadata' => $metadata,
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
            ]);

            return [
                'success' => true,
                'payment_intent_id' => $paymentIntent->id,
                'client_secret' => $paymentIntent->client_secret,
                'customer_id' => $customer->id,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Confirm a payment intent
     */
    public function confirmPaymentIntent($paymentIntentId, $paymentMethodId = null)
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);

            if ($paymentMethodId) {
                $paymentIntent->confirm([
                    'payment_method' => $paymentMethodId,
                ]);
            } else {
                $paymentIntent->confirm();
            }

            return [
                'success' => true,
                'status' => $paymentIntent->status,
                'payment_intent' => $paymentIntent,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create or get existing Stripe customer
     */
    public function getOrCreateCustomer($user)
    {
        // Check if customer already exists
        $customers = Customer::all([
            'email' => $user->email,
            'limit' => 1,
        ]);

        if (!empty($customers->data)) {
            return $customers->data[0];
        }

        // Create new customer
        return Customer::create([
            'email' => $user->email,
            'name' => $user->name ?? '',
            'metadata' => [
                'user_id' => $user->id,
            ],
        ]);
    }

    /**
     * Save payment method for future use
     */
    public function savePaymentMethod($user, $paymentMethodId, $gatewayId)
    {
        try {
            $stripePaymentMethod = PaymentMethod::retrieve($paymentMethodId);
            $customer = $this->getOrCreateCustomer($user);

            // Attach payment method to customer
            $stripePaymentMethod->attach(['customer' => $customer->id]);

            // Save to our database
            $paymentMethod = PaymentMethodModel::create([
                'user_id' => $user->id,
                'payment_gateway_id' => $gatewayId,
                'gateway_payment_method_id' => $paymentMethodId,
                'type' => 'card',
                'provider' => $stripePaymentMethod->card->brand ?? 'unknown',
                'last_four' => $stripePaymentMethod->card->last4 ?? null,
                'expiry_month' => $stripePaymentMethod->card->exp_month ?? null,
                'expiry_year' => $stripePaymentMethod->card->exp_year ?? null,
                'brand' => $stripePaymentMethod->card->brand ?? null,
                'country' => $stripePaymentMethod->card->country ?? null,
                'is_active' => true,
            ]);

            return [
                'success' => true,
                'payment_method' => $paymentMethod,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process refund
     */
    public function processRefund($paymentIntentId, $amount = null, $reason = 'requested_by_customer')
    {
        try {
            $refundData = [
                'payment_intent' => $paymentIntentId,
                'reason' => $reason,
            ];

            if ($amount) {
                $refundData['amount'] = $amount * 100; // Convert to cents
            }

            $refund = Refund::create($refundData);

            return [
                'success' => true,
                'refund_id' => $refund->id,
                'status' => $refund->status,
                'amount' => $refund->amount / 100, // Convert back to dollars
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get payment intent status
     */
    public function getPaymentIntentStatus($paymentIntentId)
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);

            return [
                'success' => true,
                'status' => $paymentIntent->status,
                'payment_intent' => $paymentIntent,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get payment status with standardized response format
     */
    public function getPaymentStatus(string $transactionId): array
    {
        $response = $this->getPaymentIntentStatus($transactionId);
        
        if (!$response['success']) {
            return PaymentStatusMapper::createStandardResponse(
                false,
                PaymentStatusMapper::STATUS_FAILED,
                'error',
                $transactionId,
                null,
                null,
                $response['error'],
                $response
            );
        }

        $paymentIntent = $response['payment_intent'];
        $standardStatus = PaymentStatusMapper::mapStripeStatus($paymentIntent->status);
        
        return PaymentStatusMapper::createStandardResponse(
            true,
            $standardStatus,
            $paymentIntent->status,
            $transactionId,
            $paymentIntent->amount ? $paymentIntent->amount / 100 : null,
            $standardStatus === PaymentStatusMapper::STATUS_COMPLETED && $paymentIntent->charges->data 
                ? date('c', $paymentIntent->charges->data[0]->created) : null,
            null,
            $response
        );
    }
}
