<?php
namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\PaymentProcessorService;
use App\Services\PaymentGatewayService;

use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    protected $paymentProcessor;
    protected $paymentGatewayService;


    public function __construct(
        PaymentProcessorService $paymentProcessor, 
        PaymentGatewayService $paymentGatewayService
        )
    {
        $this->paymentProcessor = $paymentProcessor;
        $this->paymentGatewayService = $paymentGatewayService;
    }

   /**
     * Get available payment gateways for a country and currency
     */
    public function getAvailableGateways(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'country' => 'required|string',
            'currency' => 'nullable|string|size:3',
            'payment_method_types' => 'nullable|array',
            'payment_method_types.*' => 'string|in:card,mobile_money,bank',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        $countryCode = $request->input('country');
        $currency = $request->input('currency', 'USD');
        $paymentMethodTypes = $request->input('payment_method_types', []);

        try {
            if (!empty($paymentMethodTypes)) {
                // Filter gateways by payment method types
                $gateways = $this->paymentGatewayService->getAvailableGatewaysForPaymentMethods(
                    $countryCode, 
                    $currency, 
                    $paymentMethodTypes
                );
            } else {
                // Get all available gateways for country and currency
                $gateways = $this->paymentGatewayService->getAvailableGateways($countryCode, $currency);
            }

            return response()->json([
                'success' => true,
                'data' => $gateways,
                'country' => $countryCode,
                'currency' => $currency,
                'payment_method_types' => $paymentMethodTypes
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch available gateways',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get best payment gateway for user
     */
    public function getBestGateway(Request $request)
    {
        $countryCode = $request->get('country', $this->paymentGatewayService->detectUserCountry(Auth::user()));
        $currency = $request->get('currency', 'USD');
        $preference = $request->get('preference');

        $gateway = $this->paymentGatewayService->getBestGateway($countryCode, $currency, $preference);

        if (!$gateway) {
            return response()->json([
                'success' => false,
                'message' => 'No payment gateway available for your location',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $gateway,
        ]);
    }

    /**
     * Process a payment
     */
    public function processPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'gateway_code' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'payable_type' => 'required|string',
            'payable_id' => 'required|string',
            'description' => 'nullable|string',
            'payment_method_id' => 'nullable|string',
            'phone_number' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->paymentProcessor->processPayment([
                'user_id' => Auth::id(),
                'gateway_code' => $request->gateway_code,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'payable_type' => $request->payable_type,
                'payable_id' => $request->payable_id,
                'description' => $request->description,
                'payment_method_id' => $request->payment_method_id,
                'phone_number' => $request->phone_number,
                'metadata' => $request->metadata ?? [],
            ]);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment processing failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get payment transaction status
     */
    public function getTransactionStatus($transactionId)
    {
        $transaction = PaymentTransaction::where('transaction_id', $transactionId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'transaction_id' => $transaction->transaction_id,
                'status' => $transaction->status,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'gateway' => $transaction->paymentGateway->name,
                'created_at' => $transaction->created_at,
                'completed_at' => $transaction->completed_at,
                'failed_at' => $transaction->failed_at,
                'failure_reason' => $transaction->failure_reason,
            ],
        ]);
    }

    /**
     * Retry a failed payment
     */
    public function retryPayment($transactionId)
    {
        $transaction = PaymentTransaction::where('transaction_id', $transactionId)
            ->where('user_id', Auth::id())
            ->first();

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found',
            ], 404);
        }

        if (!$transaction->canRetry()) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction cannot be retried',
            ], 400);
        }

        try {
            $result = $this->paymentProcessor->retryPayment($transaction);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment retry failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's payment history
     */
    public function getPaymentHistory(Request $request)
    {
        $query = PaymentTransaction::where('user_id', Auth::id())
            ->with(['paymentGateway', 'payable'])
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $transactions = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $transactions,
        ]);
    }

    /**
     * Get the gateway fee
     */
    public function gatewayFees(Request $request){
        $gateway = $request->query('gateway', null);
        $amount = $request->query('amount', 0);

        if(!$gateway || $amount ===0 ) return response()->json([
            'success' => false,
            'message' => 'Failed to retrive fees structure for '. $gateway,
            'error' => 'Internal Server Error',
        ], 500);

        $feeAmount = $this->paymentGatewayService->calculateGatewayDisbursementFee($amount, $gateway);
        return response()->json(['amount'=> $feeAmount]);
    }
}
