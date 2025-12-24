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
        Schema::table('merchants', function (Blueprint $table) {
            $table->integer('kyc_tier')->default(0)->after('compliance_status'); // 0=unverified, 1, 2, 3
            $table->enum('kyc_status', ['pending', 'documents_required', 'in_review', 'approved', 'rejected'])->default('pending')->after('kyc_tier'); // pending, documents_required, in_review, approved, rejected
            $table->timestamp('kyc_submitted_at')->nullable()->after('kyc_status');
            $table->timestamp('kyc_approved_at')->nullable()->after('kyc_submitted_at');
            $table->text('kyc_rejection_reason')->nullable()->after('kyc_approved_at');
            $table->string('business_registration_number')->nullable()->after('registration_number');
            $table->json('beneficial_owners')->nullable()->after('metadata'); // UBO data
            $table->json('kyc_metadata')->nullable()->after('beneficial_owners'); // Extra verified data from APIs
            
            // Indexes for KYC queries
            $table->index('kyc_tier');
            $table->index('kyc_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropIndex(['kyc_tier']);
            $table->dropIndex(['kyc_status']);
            
            $table->dropColumn([
                'kyc_tier',
                'kyc_status',
                'kyc_submitted_at',
                'kyc_approved_at',
                'kyc_rejection_reason',
                'business_registration_number',
                'beneficial_owners',
                'kyc_metadata',
            ]);
        });
    }
};
