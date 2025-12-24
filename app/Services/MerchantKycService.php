<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\MerchantKycDocument;
use App\Models\KycCountryRequirement;
use App\Models\KycDocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MerchantKycService extends BaseService
{
    protected KycConfigurationService $kycConfigService;
    protected DocumentVerificationService $verificationService;

    public function __construct(
        KycConfigurationService $kycConfigService,
        DocumentVerificationService $verificationService
    ) {
        $this->kycConfigService = $kycConfigService;
        $this->verificationService = $verificationService;
    }

    /**
     * Get complete KYC status for a merchant
     */
    public function getKycStatus(string $merchantId): array
    {
        $merchant = Merchant::findOrFail($merchantId);
        $countryCode = $merchant->country_code;

        // Get current tier requirements
        $currentTierReq = $this->kycConfigService->getTierRequirements($countryCode, $merchant->kyc_tier);
        
        // Get documents uploaded by merchant
        $documents = MerchantKycDocument::getForMerchant($merchantId);
        
        // Get verified documents
        $verifiedDocuments = $documents->where('status', MerchantKycDocument::STATUS_VERIFIED)
            ->pluck('document_type')
            ->unique()
            ->toArray();

        // Get pending documents
        $pendingDocuments = $documents->where('status', MerchantKycDocument::STATUS_PENDING)
            ->pluck('document_type')
            ->unique()
            ->toArray();

        // Calculate missing documents for current tier
        $requiredDocs = $currentTierReq ? $currentTierReq['required_documents'] : [];
        $missingDocuments = array_diff($requiredDocs, $verifiedDocuments);

        // Get next tier info
        $nextTier = $merchant->kyc_tier + 1;
        $nextTierReq = $this->kycConfigService->getTierRequirements($countryCode, $nextTier);

        // Calculate limits
        $limits = $currentTierReq ? [
            'daily_limit' => $currentTierReq['daily_limit'],
            'monthly_limit' => $currentTierReq['monthly_limit'],
            'single_transaction_limit' => $currentTierReq['single_transaction_limit'],
            'currency' => $currentTierReq['limit_currency'],
        ] : [
            'daily_limit' => 0,
            'monthly_limit' => 0,
            'single_transaction_limit' => 0,
            'currency' => 'USD',
        ];

        return [
            'merchant_id' => $merchant->merchant_id,
            'country_code' => $countryCode,
            'kyc_tier' => $merchant->kyc_tier,
            'kyc_tier_name' => $currentTierReq ? $currentTierReq['tier_name'] : 'Unverified',
            'kyc_status' => $merchant->kyc_status,
            'kyc_submitted_at' => $merchant->kyc_submitted_at?->toIso8601String(),
            'kyc_approved_at' => $merchant->kyc_approved_at?->toIso8601String(),
            'kyc_rejection_reason' => $merchant->kyc_rejection_reason,
            'limits' => $limits,
            'documents' => [
                'verified' => $verifiedDocuments,
                'pending' => $pendingDocuments,
                'missing' => array_values($missingDocuments),
                'total_uploaded' => $documents->count(),
            ],
            'can_upgrade' => $nextTierReq !== null,
            'next_tier' => $nextTierReq ? [
                'tier_level' => $nextTier,
                'tier_name' => $nextTierReq['tier_name'],
                'required_documents' => $nextTierReq['required_documents'],
                'limits' => [
                    'daily' => $nextTierReq['daily_limit'],
                    'monthly' => $nextTierReq['monthly_limit'],
                ],
                'benefits' => $nextTierReq['tier_benefits'] ?? [],
            ] : null,
            'tier_benefits' => $currentTierReq['tier_benefits'] ?? [],
        ];
    }

    /**
     * Upload a KYC document
     */
    public function submitDocument(
        string $merchantId,
        string $documentType,
        UploadedFile $file,
        string $side = 'front',
        ?string $uploadedBy = null
    ): MerchantKycDocument {
        $merchant = Merchant::findOrFail($merchantId);
        
        // Validate document type exists for this country
        $docTypeConfig = KycDocumentType::getByKey($merchant->country_code, $documentType);
        if (!$docTypeConfig) {
            throw new \InvalidArgumentException("Document type '{$documentType}' is not valid for country '{$merchant->country_code}'");
        }

        // Validate file type
        if (!$docTypeConfig->isAcceptedMimeType($file->getMimeType())) {
            throw new \InvalidArgumentException(
                "File type '{$file->getMimeType()}' is not accepted. Accepted formats: " . 
                implode(', ', $docTypeConfig->accepted_formats ?? ['PDF', 'JPG', 'PNG'])
            );
        }

        // Generate file path
        $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $filePath = "kyc/{$merchant->merchant_id}/{$documentType}/{$fileName}";

        // Store file
        Storage::disk('local')->put($filePath, file_get_contents($file));

        // Create document record
        $document = MerchantKycDocument::create([
            'merchant_id' => $merchant->id,
            'document_type' => $documentType,
            'file_path' => $filePath,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'side' => $side,
            'status' => MerchantKycDocument::STATUS_PENDING,
            'uploaded_by' => $uploadedBy,
        ]);

        // Update merchant status if needed
        if ($merchant->kyc_status === 'pending') {
            $merchant->update(['kyc_status' => 'documents_required']);
        }

        // Trigger verification if document type requires API verification
        if ($docTypeConfig->requires_verification_api) {
            $this->triggerAsyncVerification($document);
        }

        $this->logActivity('kyc_document_uploaded', [
            'merchant_id' => $merchantId,
            'document_type' => $documentType,
            'document_id' => $document->id,
        ]);

        return $document;
    }

    /**
     * Delete a KYC document
     */
    public function deleteDocument(string $merchantId, string $documentId): bool
    {
        $document = MerchantKycDocument::where('id', $documentId)
            ->where('merchant_id', $merchantId)
            ->firstOrFail();

        // Only allow deletion of pending or rejected documents
        if ($document->status === MerchantKycDocument::STATUS_VERIFIED) {
            throw new \InvalidArgumentException('Cannot delete verified documents');
        }

        // Delete file from storage
        if (Storage::disk('private')->exists($document->file_path)) {
            Storage::disk('private')->delete($document->file_path);
        }

        $document->delete();

        $this->logActivity('kyc_document_deleted', [
            'merchant_id' => $merchantId,
            'document_id' => $documentId,
        ]);

        return true;
    }

    /**
     * Submit KYC for review
     */
    public function submitForReview(string $merchantId): bool
    {
        $merchant = Merchant::findOrFail($merchantId);
        
        // Get required documents for next tier (or tier 1 if unverified)
        $targetTier = $merchant->kyc_tier === 0 ? 1 : $merchant->kyc_tier + 1;
        $tierReq = $this->kycConfigService->getTierRequirements($merchant->country_code, $targetTier);

        if (!$tierReq) {
            throw new \InvalidArgumentException('No tier requirements found for upgrade');
        }

        // Check if all required documents are uploaded
        $verifiedDocs = MerchantKycDocument::getVerifiedForMerchant($merchant->id)
            ->pluck('document_type')
            ->unique()
            ->toArray();

        $pendingDocs = MerchantKycDocument::where('merchant_id', $merchant->id)
            ->where('status', MerchantKycDocument::STATUS_PENDING)
            ->pluck('document_type')
            ->unique()
            ->toArray();

        $allUploadedDocs = array_unique(array_merge($verifiedDocs, $pendingDocs));
        $missingDocs = array_diff($tierReq['required_documents'], $allUploadedDocs);

        if (!empty($missingDocs)) {
            throw new \InvalidArgumentException(
                'Missing required documents: ' . implode(', ', $missingDocs)
            );
        }

        $merchant->update([
            'kyc_status' => 'in_review',
            'kyc_submitted_at' => now(),
        ]);

        $this->logActivity('kyc_submitted_for_review', [
            'merchant_id' => $merchantId,
            'target_tier' => $targetTier,
        ]);

        return true;
    }

    /**
     * Check if a transaction is within merchant's limits
     */
    public function checkTransactionLimit(string $merchantId, float $amount, string $currency): array
    {
        $merchant = Merchant::findOrFail($merchantId);
        $tierReq = $this->kycConfigService->getTierRequirements($merchant->country_code, $merchant->kyc_tier);

        if (!$tierReq) {
            return [
                'allowed' => false,
                'reason' => 'Merchant has no valid KYC tier',
                'limits' => null,
            ];
        }

        // TODO: Convert amount to limit currency if different
        // For now, assume same currency

        // Check single transaction limit
        if ($amount > $tierReq['single_transaction_limit']) {
            return [
                'allowed' => false,
                'reason' => 'Exceeds single transaction limit',
                'limits' => [
                    'single_transaction_limit' => $tierReq['single_transaction_limit'],
                    'requested_amount' => $amount,
                    'currency' => $tierReq['limit_currency'],
                ],
                'upgrade_available' => $this->canUpgrade($merchantId),
            ];
        }

        // TODO: Check daily and monthly limits by summing transactions
        // This would require querying transaction history

        return [
            'allowed' => true,
            'limits' => [
                'daily_limit' => $tierReq['daily_limit'],
                'monthly_limit' => $tierReq['monthly_limit'],
                'single_transaction_limit' => $tierReq['single_transaction_limit'],
                'currency' => $tierReq['limit_currency'],
            ],
        ];
    }

    /**
     * Upgrade merchant to a new tier (admin action)
     */
    public function upgradeToTier(string $merchantId, int $tier, string $approvedBy): bool
    {
        $merchant = Merchant::findOrFail($merchantId);

        // Validate tier exists for country
        $tierReq = $this->kycConfigService->getTierRequirements($merchant->country_code, $tier);
        if (!$tierReq) {
            throw new \InvalidArgumentException("Tier {$tier} does not exist for country {$merchant->country_code}");
        }

        $merchant->update([
            'kyc_tier' => $tier,
            'kyc_status' => 'approved',
            'kyc_approved_at' => now(),
            'kyc_rejection_reason' => null,
        ]);

        $this->logActivity('kyc_tier_upgraded', [
            'merchant_id' => $merchantId,
            'new_tier' => $tier,
            'approved_by' => $approvedBy,
        ]);

        return true;
    }

    /**
     * Reject merchant KYC (admin action)
     */
    public function rejectKyc(string $merchantId, string $reason, string $rejectedBy): bool
    {
        $merchant = Merchant::findOrFail($merchantId);

        $merchant->update([
            'kyc_status' => 'rejected',
            'kyc_rejection_reason' => $reason,
        ]);

        $this->logActivity('kyc_rejected', [
            'merchant_id' => $merchantId,
            'reason' => $reason,
            'rejected_by' => $rejectedBy,
        ]);

        return true;
    }

    /**
     * Get upgrade requirements for a merchant
     */
    public function getUpgradeRequirements(string $merchantId): array
    {
        $merchant = Merchant::findOrFail($merchantId);
        $nextTier = $merchant->kyc_tier + 1;

        return $this->kycConfigService->getUpgradeRequirements(
            $merchant->country_code,
            $merchant->kyc_tier,
            $nextTier
        );
    }

    /**
     * Check if merchant can upgrade to next tier
     */
    public function canUpgrade(string $merchantId): bool
    {
        $merchant = Merchant::findOrFail($merchantId);
        $nextTier = $merchant->kyc_tier + 1;

        return $this->kycConfigService->getTierRequirements($merchant->country_code, $nextTier) !== null;
    }

    /**
     * Get merchant documents with status
     */
    public function getMerchantDocuments(string $merchantId): array
    {
        $documents = MerchantKycDocument::getForMerchant($merchantId);

        return $documents->map(function ($doc) {
            return [
                'id' => $doc->id,
                'document_type' => $doc->document_type,
                'file_name' => $doc->file_name,
                'mime_type' => $doc->mime_type,
                'file_size' => $doc->file_size,
                'side' => $doc->side,
                'status' => $doc->status,
                'verification_notes' => $doc->verification_notes,
                'verified_at' => $doc->verified_at?->toIso8601String(),
                'expires_at' => $doc->expires_at?->toIso8601String(),
                'created_at' => $doc->created_at->toIso8601String(),
            ];
        })->toArray();
    }

    /**
     * Trigger async verification for a document
     */
    protected function triggerAsyncVerification(MerchantKycDocument $document): void
    {
        // TODO: Dispatch job to verify document with external API
        // For now, we'll handle this manually (hybrid approach)
        Log::info('Document queued for verification', [
            'document_id' => $document->id,
            'document_type' => $document->document_type,
        ]);
    }
}
