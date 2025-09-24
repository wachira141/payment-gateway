<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // Update existing transactions with gateway information based on payment gateway type
        $updatedCount = DB::table('payment_transactions as pt')
            ->join('payment_gateways as pg', 'pt.payment_gateway_id', '=', 'pg.id')
            ->whereNull('pt.gateway_code')
            ->orWhereNull('pt.payment_method_type')
            ->update([
                'pt.gateway_code' => DB::raw("
                    CASE 
                        WHEN pg.type = 'stripe' THEN 'stripe'
                        WHEN pg.type = 'mpesa' THEN 'mpesa'
                        WHEN pg.type = 'telebirr' THEN 'telebirr'
                        ELSE pg.type
                    END
                "),
                'pt.payment_method_type' => DB::raw("
                    CASE 
                        WHEN pg.type = 'stripe' THEN 'card'
                        WHEN pg.type = 'mpesa' THEN 'mobile_money'
                        WHEN pg.type = 'telebirr' THEN 'mobile_money'
                        WHEN pg.type LIKE '%bank%' THEN 'bank_transfer'
                        ELSE 'unknown'
                    END
                ")
            ]);

        // Log the update
        Log::info("Updated {$updatedCount} existing transactions with gateway information");
    }

    public function down(): void
    {
        // Reset gateway information for transactions
        DB::table('payment_transactions')->update([
            'gateway_code' => null,
            'payment_method_type' => null,
        ]);
    }
};