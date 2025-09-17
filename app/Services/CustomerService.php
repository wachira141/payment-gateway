<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class CustomerService
{
    /**
     * Get paginated customers for a merchant with minimal data for listing.
     */
    public function getCustomersForMerchant(Merchant $merchant, array $params = []): LengthAwarePaginator
    {
        $query = Customer::forMerchant($merchant->id)
                        ->selectRaw('
                            customers.*,
                            (SELECT COUNT(*) FROM customer_payment_methods WHERE customer_id = customers.id) as payment_methods_count,
                            (SELECT COUNT(*) FROM payment_intents WHERE customer_id = customers.id) as payment_intents_count,
                            (SELECT COALESCE(SUM(amount), 0) FROM payment_intents WHERE customer_id = customers.id AND status = "succeeded") as total_spent,
                            (SELECT MAX(created_at) FROM payment_intents WHERE customer_id = customers.id AND status = "succeeded") as last_payment_date,
                            (SELECT COUNT(*) > 0 FROM customer_payment_methods WHERE customer_id = customers.id AND verified_at IS NOT NULL) as has_verified_methods
                        ');

        // Apply search
        if (!empty($params['search'])) {
            $query->searchByTerm($params['search']);
        }

        // Apply specific filters
        if (!empty($params['email_filter'])) {
            $query->where('email', 'like', '%' . $params['email_filter'] . '%');
        }

        if (!empty($params['phone_filter'])) {
            $query->where('phone', 'like', '%' . $params['phone_filter'] . '%');
        }

        if (!empty($params['external_id_filter'])) {
            $query->where('external_id', 'like', '%' . $params['external_id_filter'] . '%');
        }

        // Apply date filters
        if (!empty($params['created_from'])) {
            $query->where('created_at', '>=', $params['created_from']);
        }

        if (!empty($params['created_to'])) {
            $query->where('created_at', '<=', $params['created_to'] . ' 23:59:59');
        }

        // Apply sorting
        $sortBy = $params['sort_by'] ?? 'created_at';
        $sortDirection = $params['sort_direction'] ?? 'desc';
        $query->orderBy($sortBy, $sortDirection);

        // Paginate results
        $limit = min($params['limit'] ?? 20, 100);
        return $query->paginate($limit);
    }

    /**
     * Get paginated payment intents for a specific customer.
     */
    public function getCustomerPaymentIntents(Customer $customer, array $params = []): LengthAwarePaginator
    {
        $query = $customer->paymentIntents();

        // Apply search
        if (!empty($params['search'])) {
            $query->where(function($q) use ($params) {
                $q->where('description', 'like', '%' . $params['search'] . '%')
                  ->orWhere('intent_id', 'like', '%' . $params['search'] . '%')
                  ->orWhere('amount', 'like', '%' . $params['search'] . '%');
            });
        }

        // Apply status filter
        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        // Apply date filters
        if (!empty($params['date_from'])) {
            $query->where('created_at', '>=', $params['date_from']);
        }

        if (!empty($params['date_to'])) {
            $query->where('created_at', '<=', $params['date_to'] . ' 23:59:59');
        }

        // Apply amount filters
        if (!empty($params['amount_min'])) {
            $query->where('amount', '>=', $params['amount_min']);
        }

        if (!empty($params['amount_max'])) {
            $query->where('amount', '<=', $params['amount_max']);
        }

        // Apply sorting
        $sortBy = $params['sort_by'] ?? 'created_at';
        $sortDirection = $params['sort_direction'] ?? 'desc';
        $query->orderBy($sortBy, $sortDirection);

        // Paginate results
        $limit = min($params['limit'] ?? 20, 50);
        return $query->paginate($limit);
    }

    /**
     * Get a specific customer for a merchant with relationships.
     */
    public function getCustomerForMerchant(Merchant $merchant, string $customerId): ?Customer
    {
        return Customer::forMerchant($merchant->id)
                      ->find($customerId);
    }

    /**
     * Create a new customer for a merchant.
     */
    public function createCustomer(Merchant $merchant, array $customerData): Customer
    {
        return Customer::findOrCreate($merchant->id, $customerData);
    }

    /**
     * Update an existing customer.
     */
    public function updateCustomer(Customer $customer, array $updateData): Customer
    {
        $customer->updateCustomerData($updateData);
        return $customer->fresh();
    }

    /**
     * Get customer payment methods.
     */
    public function getCustomerPaymentMethods(Customer $customer): Collection
    {
        return $customer->paymentMethods()
                       ->orderBy('is_default', 'desc')
                       ->orderBy('created_at', 'desc')
                       ->get();
    }

    /**
     * Get customer analytics/statistics.
     */
    public function getCustomerStats(Customer $customer): array
    {
        $paymentIntents = $customer->paymentIntents();
        
        return [
            'total_payment_intents' => $paymentIntents->count(),
            'successful_payments' => $paymentIntents->where('status', 'succeeded')->count(),
            'total_amount_spent' => $paymentIntents->where('status', 'succeeded')->sum('amount'),
            'average_order_value' => $paymentIntents->where('status', 'succeeded')->avg('amount') ?: 0,
            'first_payment_date' => $paymentIntents->oldest()->first()?->created_at,
            'last_payment_date' => $paymentIntents->latest()->first()?->created_at,
            'total_payment_methods' => $customer->paymentMethods()->count(),
            'verified_payment_methods' => $customer->paymentMethods()->whereNotNull('verified_at')->count(),
        ];
    }

    /**
     * Search customers across all fields.
     */
    public function searchCustomers(Merchant $merchant, string $searchTerm, int $limit = 20): LengthAwarePaginator
    {
        return Customer::forMerchant($merchant->id)
                      ->searchByTerm($searchTerm)
                      ->with(['paymentMethods'])
                      ->orderBy('created_at', 'desc')
                      ->paginate($limit);
    }

    /**
     * Get customer summary for merchant dashboard.
     */
    public function getMerchantCustomerSummary(Merchant $merchant): array
    {
        $customers = Customer::forMerchant($merchant->id);
        
        return [
            'total_customers' => $customers->count(),
            'new_customers_this_month' => $customers->whereMonth('created_at', now()->month)
                                                   ->whereYear('created_at', now()->year)
                                                   ->count(),
            'customers_with_payment_methods' => $customers->has('paymentMethods')->count(),
            'active_customers' => $customers->has('paymentIntents')->count(),
        ];
    }
}