<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entry_id')->unique()->comment('Public ledger entry ID');
            $table->string('merchant_id', 36);
            // Foreign key constraint
            $table->foreign('merchant_id')->references('merchant_id')->on('merchants')->onDelete('cascade');
            $table->string('transaction_id')->comment('Groups related debits/credits');
            $table->string('related_id')->comment('ID of the related model (charge, payout, refund, etc.)');
            $table->morphs('related'); // creates related_type + related_id + index
            $table->comment('Related model (charge, payout, refund, etc.)');
            $table->enum('account_type', ['assets', 'liabilities', 'revenue', 'fees', 'fx_gains', 'fx_losses']);
            $table->string('account_name');
            $table->enum('entry_type', ['debit', 'credit']);
            $table->decimal('amount', 15, 4);
            $table->string('currency', 3);
            $table->text('description');
            $table->json('metadata')->nullable()->comment('Gateway info, fee breakdown, etc.');
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->index(['merchant_id', 'account_type']);
            $table->index(['transaction_id']);
            $table->index(['posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
