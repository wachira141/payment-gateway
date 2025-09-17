<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexCustomerRequest;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;

class CustomerController extends Controller
{
    protected CustomerService $customerService;

    public function __construct(CustomerService $customerService)
    {
        $this->customerService = $customerService;
    }

    /**
     * Get customers for the authenticated merchant.
     */
    public function index(IndexCustomerRequest $request): JsonResponse
    {
        $merchant = $request->user()->merchant;
        $params = $request->validated();

        $customers = $this->customerService->getCustomersForMerchant($merchant, $params);

        return response()->json([
            'success' => true,
            'data' => $customers->items(),
            'pagination' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
                'from' => $customers->firstItem(),
                'to' => $customers->lastItem(),
            ],
        ]);
    }

    /**
     * Get a specific customer.
     */
    public function show(string $customerId): JsonResponse
    {
        $merchant = request()->user()->merchant;

        $customer = $this->customerService->getCustomerForMerchant($merchant, $customerId);

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found'
            ], 404);
        }

        // Get customer statistics
        $stats = $this->customerService->getCustomerStats($customer);

        return response()->json([
            'success' => true,
            'data' => $customer,
            'stats' => $stats,
        ]);
    }

    /**
     * Create a new customer.
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $merchant = $request->user()->merchant;
        $customerData = $request->validated();

        $customer = $this->customerService->createCustomer($merchant, $customerData);

        return response()->json([
            'success' => true,
            'data' => $customer->load(['paymentMethods']),
            'message' => 'Customer created successfully'
        ], 201);
    }

    /**
     * Update a customer.
     */
    public function update(UpdateCustomerRequest $request, int $customerId): JsonResponse
    {
        $merchant = $request->user()->merchant;

        $customer = $this->customerService->getCustomerForMerchant($merchant, $customerId);

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found'
            ], 404);
        }

        $updateData = $request->validated();
        $updatedCustomer = $this->customerService->updateCustomer($customer, $updateData);

        return response()->json([
            'success' => true,
            'data' => $updatedCustomer->load(['paymentMethods']),
            'message' => 'Customer updated successfully'
        ]);
    }

    /**
     * Get customer payment methods.
     */
    public function paymentMethods(string $customerId): JsonResponse
    {
        $merchant = request()->user()->merchant;

        $customer = $this->customerService->getCustomerForMerchant($merchant, $customerId);

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found'
            ], 404);
        }

        $paymentMethods = $this->customerService->getCustomerPaymentMethods($customer);

        return response()->json([
            'success' => true,
            'data' => $paymentMethods,
        ]);
    }

    /**
     * Get customer payment intents with pagination.
     */
    public function paymentIntents(string $customerId): JsonResponse
    {
        $merchant = request()->user()->merchant;
        $params = request()->all();

        $customer = $this->customerService->getCustomerForMerchant($merchant, $customerId);

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found'
            ], 404);
        }

        $paymentIntents = $this->customerService->getCustomerPaymentIntents($customer, $params);

        return response()->json([
            'success' => true,
            'data' => $paymentIntents->items(),
            'pagination' => [
                'current_page' => $paymentIntents->currentPage(),
                'last_page' => $paymentIntents->lastPage(),
                'per_page' => $paymentIntents->perPage(),
                'total' => $paymentIntents->total(),
                'from' => $paymentIntents->firstItem(),
                'to' => $paymentIntents->lastItem(),
            ],
        ]);
    }
}
