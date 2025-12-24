<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // database/migrations/2025_12_24_000003_create_disbursements_table.php

        Schema::create('disbursements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('disbursement_id', 36)->unique();
            $table->string('merchant_id', 36);
            $table->string('wallet_id', 36)->nullable();
            $table->string('beneficiary_id', 36);
            $table->uuid('disbursement_batch_id')->nullable();

            // Funding & payout
            $table->enum('funding_source', ['wallet', 'balance'])->default('wallet');
            $table->enum('payout_method', ['bank_transfer', 'mobile_money', 'card'])->default('bank_transfer');

            // Amounts
            $table->decimal('gross_amount', 15, 4);
            $table->decimal('fee_amount', 15, 4)->default(0);
            $table->decimal('net_amount', 15, 4);
            $table->string('currency', 3);

            // Status
            $table->enum('status', ['pending', 'processing', 'sending', 'completed', 'failed', 'cancelled'])->default('pending');

            // Gateway info
            $table->string('gateway_disbursement_id')->nullable();
            $table->string('gateway_transaction_id')->nullable();
            $table->json('gateway_response')->nullable();

            // Details
            $table->text('description')->nullable();
            $table->string('external_reference')->nullable();
            $table->text('failure_reason')->nullable();

            // Timestamps
            $table->timestamp('estimated_arrival')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Indexes
            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->foreign('wallet_id')->references('wallet_id')->on('merchant_wallets')->onDelete('set null');
            $table->foreign('beneficiary_id')->references('beneficiary_id')->on('beneficiaries')->onDelete('cascade');
            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('disbursement_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('batch_id', 36)->unique();
            $table->string('batch_name');
            $table->string('merchant_id', 36);
            $table->string('wallet_id', 36)->nullable();
            $table->enum('funding_source', ['wallet', 'balance'])->default('wallet');
            $table->string('currency', 3);
            $table->integer('total_disbursements')->default(0);
            $table->decimal('total_amount', 15, 4)->default(0);
            $table->decimal('total_fees', 15, 4)->default(0);
            $table->enum('status', ['pending', 'processing', 'partially_completed', 'completed', 'failed'])->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            $table->foreign('wallet_id')->references('wallet_id')->on('merchant_wallets')->onDelete('set null');
            $table->index(['merchant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disbursements');
        Schema::dropIfExists('disbursement_batches');

    }
};
