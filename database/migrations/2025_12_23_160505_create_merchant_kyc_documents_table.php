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
        Schema::create('merchant_kyc_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id');
            $table->string('document_type'); // matches kyc_document_types.document_key
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type');
            $table->integer('file_size');
            $table->enum('side', ['front', 'back' ])->default('front'); // front, back (for IDs needing both sides)
            $table->enum('status', ['pending', 'verified', 'rejected', 'expired'])->default('pending'); // pending, verified, rejected, expired
            $table->text('verification_notes')->nullable();
            $table->json('verification_data')->nullable(); // API response from Smile ID/YouVerify
            $table->json('extracted_data')->nullable(); // Data extracted from document (name, id number, etc.)
            $table->uuid('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->uuid('uploaded_by')->nullable(); // User who uploaded
            $table->timestamps();

            $table->foreign('merchant_id')->references('id')->on('merchants')->onDelete('cascade');
            
            $table->index('merchant_id');
            $table->index('document_type');
            $table->index('status');
            $table->index(['merchant_id', 'document_type', 'side']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_kyc_documents');
    }
};
