<?php

namespace App\Http\Controllers\Api\v1;


use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePaymentIntentRequest;
use App\Http\Requests\ConfirmPaymentIntentRequest;
use App\Services\PaymentIntentService;
use App\Models\PaymentIntent;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PaymentIntentController extends Controller
{
    public function __construct(
        private PaymentIntentService $paymentIntentService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $merchant = $request->user()->merchant;

            $filters = $request->only([
                'status',
                'client_reference_id',
                'limit',
            ]);

            $paymentIntents = $this->paymentIntentService->getForMerchant($merchant, $filters);

            return response()->json($this->paginatedResponse($paymentIntents));
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment intents',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(CreatePaymentIntentRequest $request): JsonResponse
    {
        try {
            $merchant = $request->user()->merchant;

            $requestData = $request->validated();

            // If merchant_app_id not provided, the service will handle it
            $paymentIntent = $this->paymentIntentService->create(
                $merchant,
                $requestData
            );

            return response()->json([
                'success' => true,
                'data' => $paymentIntent,
                'message' => 'Payment intent created successfully',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment intent',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function show(Request $request, string $intentId): JsonResponse
    {
        try {
            $merchant = $request->user()->merchant;

            $paymentIntent = $this->paymentIntentService->findByIdAndMerchant($intentId, $merchant);

            if (!$paymentIntent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment intent not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $paymentIntent,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment intent',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function confirm(ConfirmPaymentIntentRequest $request, string $intentId): JsonResponse
    {
        try {
            $merchant = $request->user()->merchant;

            $paymentIntent = $this->paymentIntentService->findByIdAndMerchant($intentId, $merchant);

            if (!$paymentIntent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment intent not found',
                ], 404);
            }

            $confirmedIntent = $this->paymentIntentService->confirm(
                $paymentIntent,
                $request->validated()['payment_method']
            );

            return response()->json([
                'success' => true,
                'data' => $confirmedIntent,
                'message' => 'Payment intent confirmed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function capture(Request $request, string $intentId): JsonResponse
    {
        try {
            $merchant = $request->user()->merchant;

            $paymentIntent = $this->paymentIntentService->findByIdAndMerchant($intentId, $merchant);

            if (!$paymentIntent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment intent not found',
                ], 404);
            }

            $capturedIntent = $this->paymentIntentService->capture($paymentIntent);

            return response()->json([
                'success' => true,
                'data' => $capturedIntent,
                'message' => 'Payment intent captured successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function cancel(Request $request, string $intentId): JsonResponse
    {
        try {
            $merchant = $request->user()->merchant;

            $paymentIntent = $this->paymentIntentService->findByIdAndMerchant($intentId, $merchant);

            if (!$paymentIntent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment intent not found',
                ], 404);
            }

            $cancelledIntent = $this->paymentIntentService->cancel(
                $paymentIntent,
                $request->input('cancellation_reason')
            );

            return response()->json([
                'success' => true,
                'data' => $cancelledIntent,
                'message' => 'Payment intent cancelled successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function analytics(Request $request): JsonResponse
    {
        try {
            $merchant = $request->user()->merchant;

            $filters = $request->only(['date_from', 'date_to']);

            $analytics = $this->paymentIntentService->getAnalytics($merchant, $filters);

            return response()->json([
                'success' => true,
                'data' => $analytics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch analytics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
