<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PaymentIntent;
use App\Models\Merchant;
use Illuminate\Support\Facades\Log;

/**
 * Service to resolve customer identity for payment transactions
 */
class CustomerResolutionService
{
    /**
     * Resolve customer from payment intent
     */
    public function resolveFromPaymentIntent(PaymentIntent $paymentIntent): ?Customer
    {
        // If payment intent already has a customer, use it
        if ($paymentIntent->customer_id) {
            return $paymentIntent->customer;
        }

        // Try to resolve from billing details or metadata
        return $this->resolveFromPaymentData($paymentIntent->merchant, [
            'billing_details' => $paymentIntent->billing_details,
            'receipt_email' => $paymentIntent->receipt_email,
            'metadata' => $paymentIntent->metadata,
            'description' => $paymentIntent->description,
        ]);
    }

    /**
     * Resolve customer from payment data
     */
    public function resolveFromPaymentData(Merchant $merchant, array $paymentData): ?Customer
    {
        $email = $this->extractEmail($paymentData);
        $phone = $this->extractPhone($paymentData);
        $name = $this->extractName($paymentData);

        if (!$email && !$phone && !$name) {
            return null;
        }

        // Try to find existing customer
        $customer = $this->findExistingCustomer($merchant, $email, $phone);

        if ($customer) {
            // Update customer info if we have new data
            $this->updateCustomerIfNeeded($customer, $name, $email, $phone, $paymentData);
            return $customer;
        }

        // Create new customer if we have enough information
        if ($name || $email) {
            return $this->createCustomer($merchant, $name, $email, $phone, $paymentData);
        }

        return null;
    }

    /**
     * Find existing customer by email or phone
     */
    private function findExistingCustomer(Merchant $merchant, ?string $email, ?string $phone): ?Customer
    {
        $query = Customer::where('merchant_id', $merchant->id);

        if ($email) {
            $query->where('email', $email);
        } elseif ($phone) {
            $query->where('phone', $phone);
        } else {
            return null;
        }

        return $query->first();
    }

    /**
     * Create new customer
     */
    private function createCustomer(Merchant $merchant, ?string $name, ?string $email, ?string $phone, array $paymentData): Customer
    {
        $customerData = [
            'merchant_id' => $merchant->id,
            'name' => $name ?: ($email ? explode('@', $email)[0] : 'Unknown Customer'),
            'email' => $email,
            'phone' => $phone,
            'metadata' => $this->extractCustomerMetadata($paymentData),
        ];

        $customer = Customer::create($customerData);

        Log::info('Auto-created customer from payment data', [
            'customer_id' => $customer->id,
            'merchant_id' => $merchant->id,
            'email' => $email,
            'phone' => $phone,
        ]);

        return $customer;
    }

    /**
     * Update customer if we have new information
     */
    private function updateCustomerIfNeeded(Customer $customer, ?string $name, ?string $email, ?string $phone, array $paymentData): void
    {
        $updates = [];

        if ($name && !$customer->name) {
            $updates['name'] = $name;
        }

        if ($email && !$customer->email) {
            $updates['email'] = $email;
        }

        if ($phone && !$customer->phone) {
            $updates['phone'] = $phone;
        }

        if (!empty($updates)) {
            $customer->update($updates);
            
            Log::info('Updated customer from payment data', [
                'customer_id' => $customer->id,
                'updates' => $updates,
            ]);
        }
    }

    /**
     * Extract email from payment data
     */
    private function extractEmail(array $paymentData): ?string
    {
        // Check receipt_email first
        if (!empty($paymentData['receipt_email'])) {
            return $paymentData['receipt_email'];
        }

        // Check billing details
        if (!empty($paymentData['billing_details']['email'])) {
            return $paymentData['billing_details']['email'];
        }

        // Check metadata
        if (!empty($paymentData['metadata']['email'])) {
            return $paymentData['metadata']['email'];
        }

        if (!empty($paymentData['metadata']['customer_email'])) {
            return $paymentData['metadata']['customer_email'];
        }

        return null;
    }

    /**
     * Extract phone from payment data
     */
    private function extractPhone(array $paymentData): ?string
    {
        // Check billing details
        if (!empty($paymentData['billing_details']['phone'])) {
            return $paymentData['billing_details']['phone'];
        }

        // Check metadata
        if (!empty($paymentData['metadata']['phone'])) {
            return $paymentData['metadata']['phone'];
        }

        if (!empty($paymentData['metadata']['customer_phone'])) {
            return $paymentData['metadata']['customer_phone'];
        }

        return null;
    }

    /**
     * Extract name from payment data
     */
    private function extractName(array $paymentData): ?string
    {
        // Check billing details
        if (!empty($paymentData['billing_details']['name'])) {
            return $paymentData['billing_details']['name'];
        }

        // Check metadata
        if (!empty($paymentData['metadata']['name'])) {
            return $paymentData['metadata']['name'];
        }

        if (!empty($paymentData['metadata']['customer_name'])) {
            return $paymentData['metadata']['customer_name'];
        }

        return null;
    }

    /**
     * Extract customer metadata from payment data
     */
    private function extractCustomerMetadata(array $paymentData): array
    {
        $metadata = [];

        if (!empty($paymentData['billing_details'])) {
            $metadata['billing_details'] = $paymentData['billing_details'];
        }

        if (!empty($paymentData['metadata'])) {
            $metadata['payment_metadata'] = $paymentData['metadata'];
        }

        return $metadata;
    }
}