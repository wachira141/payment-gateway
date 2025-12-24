<?php

namespace App\Services;

use App\Models\MerchantKycDocument;
use App\Models\KycDocumentType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class DocumentVerificationService extends BaseService
{
    /**
     * Verify a document (hybrid: manual + API)
     */
    public function verifyDocument(MerchantKycDocument $document): array
    {
        $docTypeConfig = $document->getDocumentTypeConfig();

        if (!$docTypeConfig) {
            return [
                'success' => false,
                'error' => 'Document type configuration not found',
            ];
        }

        // If document requires API verification, attempt it
        if ($docTypeConfig->requires_verification_api) {
            $apiResult = $this->verifyWithApi($document, $docTypeConfig);
            
            if ($apiResult['success']) {
                return $apiResult;
            }
            
            // If API verification fails, mark for manual review
            Log::warning('API verification failed, marking for manual review', [
                'document_id' => $document->id,
                'error' => $apiResult['error'] ?? 'Unknown error',
            ]);
        }

        // Return pending for manual verification
        return [
            'success' => true,
            'requires_manual_review' => true,
            'message' => 'Document submitted for manual verification',
        ];
    }

    /**
     * Verify document with external API (Smile ID, YouVerify, etc.)
     */
    protected function verifyWithApi(MerchantKycDocument $document, KycDocumentType $docTypeConfig): array
    {
        $provider = $docTypeConfig->verification_provider;

        try {
            switch ($provider) {
                case 'smile_id':
                    return $this->verifyWithSmileId($document, $docTypeConfig);
                case 'youverify':
                    return $this->verifyWithYouVerify($document, $docTypeConfig);
                default:
                    return [
                        'success' => false,
                        'error' => "Unknown verification provider: {$provider}",
                    ];
            }
        } catch (\Exception $e) {
            Log::error('Document verification API error', [
                'document_id' => $document->id,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify with Smile ID (placeholder for future integration)
     */
    protected function verifyWithSmileId(MerchantKycDocument $document, KycDocumentType $docTypeConfig): array
    {
        // TODO: Implement Smile ID integration
        // https://docs.smileidentity.com/
        
        $apiKey = config('services.smile_id.api_key');
        $partnerId = config('services.smile_id.partner_id');

        if (!$apiKey || !$partnerId) {
            return [
                'success' => false,
                'error' => 'Smile ID not configured',
                'requires_manual_review' => true,
            ];
        }

        // Placeholder: Return manual review required
        // In production, you would:
        // 1. Upload document image to Smile ID
        // 2. Submit verification job
        // 3. Poll for results or use webhook
        // 4. Update document status based on result

        Log::info('Smile ID verification would be triggered here', [
            'document_id' => $document->id,
            'document_type' => $document->document_type,
        ]);

        return [
            'success' => true,
            'requires_manual_review' => true,
            'message' => 'Smile ID integration pending - manual review required',
        ];
    }

    /**
     * Verify with YouVerify (placeholder for future integration)
     */
    protected function verifyWithYouVerify(MerchantKycDocument $document, KycDocumentType $docTypeConfig): array
    {
        // TODO: Implement YouVerify integration
        // https://docs.youverify.co/

        $apiKey = config('services.youverify.api_key');

        if (!$apiKey) {
            return [
                'success' => false,
                'error' => 'YouVerify not configured',
                'requires_manual_review' => true,
            ];
        }

        Log::info('YouVerify verification would be triggered here', [
            'document_id' => $document->id,
            'document_type' => $document->document_type,
        ]);

        return [
            'success' => true,
            'requires_manual_review' => true,
            'message' => 'YouVerify integration pending - manual review required',
        ];
    }

    /**
     * Perform liveness check (for selfie verification)
     */
    public function performLivenessCheck(string $selfieBase64, string $provider = 'smile_id'): array
    {
        // TODO: Implement liveness check with provider
        // This typically involves:
        // 1. Sending the selfie image
        // 2. Getting a liveness score
        // 3. Comparing face to ID document

        return [
            'success' => true,
            'requires_manual_review' => true,
            'message' => 'Liveness check integration pending',
        ];
    }

    /**
     * Manual verification by admin
     */
    public function manualVerification(
        string $documentId,
        string $status,
        ?string $notes,
        string $verifiedBy,
        ?array $extractedData = null
    ): bool {
        $document = MerchantKycDocument::findOrFail($documentId);

        if ($status === MerchantKycDocument::STATUS_VERIFIED) {
            return $document->markVerified($verifiedBy, $notes, [
                'verification_method' => 'manual',
                'verified_at' => now()->toIso8601String(),
                'extracted_data' => $extractedData,
            ]);
        } elseif ($status === MerchantKycDocument::STATUS_REJECTED) {
            return $document->markRejected($verifiedBy, $notes ?? 'Document rejected');
        }

        throw new \InvalidArgumentException("Invalid status: {$status}");
    }

    /**
     * Verify ID number against government database (placeholder)
     */
    public function verifyIdNumber(string $countryCode, string $idType, string $idNumber): array
    {
        // TODO: Implement country-specific ID verification
        // Kenya: IPRS (Integrated Population Registration System)
        // Nigeria: NIMC (National Identity Management Commission)
        // Uganda: NIRA (National Identification and Registration Authority)

        Log::info('ID number verification requested', [
            'country_code' => $countryCode,
            'id_type' => $idType,
            'id_number' => substr($idNumber, 0, 4) . '****', // Mask for logging
        ]);

        return [
            'success' => true,
            'verified' => false, // Not actually verified
            'requires_manual_review' => true,
            'message' => 'Government database verification not yet integrated',
        ];
    }

    /**
     * Extract data from document using OCR (placeholder)
     */
    public function extractDocumentData(MerchantKycDocument $document): array
    {
        // TODO: Implement OCR extraction
        // This could use services like:
        // - AWS Textract
        // - Google Document AI
        // - Smile ID document extraction

        return [
            'success' => false,
            'error' => 'OCR extraction not yet implemented',
            'extracted_data' => null,
        ];
    }

    /**
     * Get pending documents for manual review
     */
    public function getPendingDocuments(int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return MerchantKycDocument::with('merchant')
            ->where('status', MerchantKycDocument::STATUS_PENDING)
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get verification statistics
     */
    public function getVerificationStats(): array
    {
        return [
            'pending' => MerchantKycDocument::where('status', MerchantKycDocument::STATUS_PENDING)->count(),
            'verified' => MerchantKycDocument::where('status', MerchantKycDocument::STATUS_VERIFIED)->count(),
            'rejected' => MerchantKycDocument::where('status', MerchantKycDocument::STATUS_REJECTED)->count(),
            'expired' => MerchantKycDocument::where('status', MerchantKycDocument::STATUS_EXPIRED)->count(),
        ];
    }
}
