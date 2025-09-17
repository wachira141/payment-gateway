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
        DB::statement("
            UPDATE payment_transactions pt
            INNER JOIN payment_gateways pg ON pt.payment_gateway_id = pg.id
            SET 
                pt.gateway_code = CASE 
                    WHEN pg.type = 'stripe' THEN 'stripe'
                    WHEN pg.type = 'mpesa' THEN 'mpesa'
                    WHEN pg.type = 'telebirr' THEN 'telebirr'
                    ELSE pg.type
                END,
                pt.payment_method_type = CASE 
                    WHEN pg.type = 'stripe' THEN 'card'
                    WHEN pg.type = 'mpesa' THEN 'mobile_money'
                    WHEN pg.type = 'telebirr' THEN 'mobile_money'
                    WHEN pg.type LIKE '%bank%' THEN 'bank_transfer'
                    ELSE 'unknown'
                END
            WHERE pt.gateway_code IS NULL OR pt.payment_method_type IS NULL
        ");

        // Log the update
        $updatedCount = DB::affectedRows();
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