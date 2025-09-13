<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Services\StripePaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class PaymentMethodController extends Controller
{
    protected $stripeService;

    public function __construct(StripePaymentService $stripeService)
    {
        $this->stripeService = $stripeService;
    }

    /**
     * Get user's payment methods
     */
    public function index()
    {
        $paymentMethods = PaymentMethod::where('user_id', Auth::id())
            ->active()
            ->with('paymentGateway')
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $paymentMethods,
        ]);
    }

    /**
     * Store a new payment method
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'gateway_code' => 'required|string',
            'gateway_payment_method_id' => 'required|string',
            'type' => 'required|string|in:card,mobile_money,bank_transfer',
            'is_default' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Get gateway
            $gateway = \App\Models\PaymentGateway::where('code', $request->gateway_code)->first();
            if (!$gateway) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment gateway not found',
                ], 404);
            }

            // Save payment method using appropriate service
            if ($gateway->type === 'stripe') {
                $result = $this->stripeService->savePaymentMethod(
                    Auth::user(),
                    $request->gateway_payment_method_id,
                    $gateway->id
                );

                if (!$result['success']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to save payment method',
                        'error' => $result['error'],
                    ], 400);
                }

                $paymentMethod = $result['payment_method'];
            } else {
                // For other gateways, create payment method directly
                $paymentMethod = PaymentMethod::create([
                    'user_id' => Auth::id(),
                    'payment_gateway_id' => $gateway->id,
                    'gateway_payment_method_id' => $request->gateway_payment_method_id,
                    'type' => $request->type,
                    'is_default' => $request->get('is_default', false),
                    'is_active' => true,
                ]);
            }

            // If this is set as default, update other payment methods
            if ($request->get('is_default', false)) {
                PaymentMethod::where('user_id', Auth::id())
                    ->where('id', '!=', $paymentMethod->id)
                    ->update(['is_default' => false]);
            }

            return response()->json([
                'success' => true,
                'data' => $paymentMethod->load('paymentGateway'),
                'message' => 'Payment method saved successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to save payment method',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Set a payment method as default
     */
    public function setDefault($id)
    {
        $paymentMethod = PaymentMethod::where('id', $id)
            ->where('user_id', Auth::id())
            ->active()
            ->first();

        if (!$paymentMethod) {
            return response()->json([
                'success' => false,
                'message' => 'Payment method not found',
            ], 404);
        }

        // Update all payment methods to not be default
        PaymentMethod::where('user_id', Auth::id())
            ->update(['is_default' => false]);

        // Set this one as default
        $paymentMethod->update(['is_default' => true]);

        return response()->json([
            'success' => true,
            'data' => $paymentMethod,
            'message' => 'Default payment method updated',
        ]);
    }

    /**
     * Delete a payment method
     */
    public function destroy($id)
    {
        $paymentMethod = PaymentMethod::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        if (!$paymentMethod) {
            return response()->json([
                'success' => false,
                'message' => 'Payment method not found',
            ], 404);
        }

        // Deactivate instead of deleting for audit purposes
        $paymentMethod->update(['is_active' => false]);

        // If this was the default, set another one as default
        if ($paymentMethod->is_default) {
            $newDefault = PaymentMethod::where('user_id', Auth::id())
                ->where('id', '!=', $paymentMethod->id)
                ->active()
                ->first();

            if ($newDefault) {
                $newDefault->update(['is_default' => true]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment method removed',
        ]);
    }
}
