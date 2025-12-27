<?php
namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BalanceController extends Controller
{
    /**
     * Get merchant balances
     */
    public function index(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;
        
        $balances = $merchant->balances()
            ->orderBy('currency')
            ->get();

        $data = $balances->map(function ($balance) {
            return [
                'object' => 'balance',
                'currency' => $balance->currency,
                'available' => (int) $balance->available_amount,
                'pending' => (int) $balance->pending_amount,
                'reserved' => (int) $balance->reserved_amount,
                'total_volume' => (int) $balance->total_volume,
                'last_transaction_at' => $balance->last_transaction_at?->timestamp,
            ];
        });

        return response()->json([
            'object' => 'list',
            'data' => $data,
        ]);
    }

    /**
     * Get balance for specific currency
     */
    public function show(Request $request, string $currency): JsonResponse
    {
        $merchant = $request->user()->merchant;
        
        $balance = $merchant->balances()
            ->where('currency', strtoupper($currency))
            ->first();

        if (!$balance) {
            return response()->json([
                'error' => [
                    'type' => 'invalid_request_error',
                    'message' => 'No balance found for currency: ' . $currency,
                ]
            ], 404);
        }

        return response()->json([
            'object' => 'balance',
            'currency' => $balance->currency,
            'available' => (int) $balance->available_amount,
            'pending' => (int) $balance->pending_amount,
            'reserved' => (int) $balance->reserved_amount,
            'total_volume' => (int) $balance->total_volume,
            'last_transaction_at' => $balance->last_transaction_at?->timestamp,
        ]);
    }

    /**
     * Get balance transactions (ledger entries)
     */
    public function transactions(Request $request, string $currency): JsonResponse
    {
        $request->validate([
            'limit' => 'integer|min:1|max:100',
            'starting_after' => 'string',
            'account_type' => 'string|in:assets,liabilities,revenue,fees,fx_gains,fx_losses',
            'entry_type' => 'string|in:debit,credit',
        ]);

        $merchant = $request->user()->merchant;
        $limit = $request->get('limit', 25);

        $query = $merchant->ledgerEntries()
            ->where('currency', strtoupper($currency))
            ->with('related');

        // Apply filters
        if ($request->account_type) {
            $query->where('account_type', $request->account_type);
        }

        if ($request->entry_type) {
            $query->where('entry_type', $request->entry_type);
        }

        // Apply cursor pagination
        if ($request->starting_after) {
            $cursor = $merchant->ledgerEntries()
                ->where('entry_id', $request->starting_after)
                ->first();
            if ($cursor) {
                $query->where('posted_at', '<', $cursor->posted_at);
            }
        }

        $entries = $query->orderBy('posted_at', 'desc')
            ->limit($limit + 1)
            ->get();

        $hasMore = $entries->count() > $limit;
        if ($hasMore) {
            $entries = $entries->slice(0, $limit);
        }

        $data = $entries->map(function ($entry) {
            return [
                'id' => $entry->entry_id,
                'object' => 'balance_transaction',
                'amount' => (int) ($entry->entry_type === 'credit' ? $entry->amount : -$entry->amount),
                'currency' => $entry->currency,
                'description' => $entry->description,
                'account_type' => $entry->account_type,
                'account_name' => $entry->account_name,
                'entry_type' => $entry->entry_type,
                'transaction_id' => $entry->transaction_id,
                'related_object' => $entry->related_type ? [
                    'id' => $entry->related_id,
                    'type' => class_basename($entry->related_type),
                ] : null,
                'posted_at' => $entry->posted_at->timestamp,
            ];
        });

        return response()->json([
            'object' => 'list',
            'data' => $data,
            'has_more' => $hasMore,
            'url' => "/v1/balances/{$currency}/transactions",
        ]);
    }
}