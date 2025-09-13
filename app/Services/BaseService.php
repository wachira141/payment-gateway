<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

abstract class BaseService
{
    /**
     * Validate data against rules
     */
    protected function validateData(array $data, array $rules, array $messages = [])
    {
        $validator = Validator::make($data, $rules, $messages);
        
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        
        return $validator->validated();
    }

    /**
     * Handle service exceptions consistently
     */
    protected function handleException(\Exception $e, string $context = 'Operation')
    {
        Log::error("{$context} failed: " . $e->getMessage(), [
            'exception' => $e,
            'trace' => $e->getTraceAsString()
        ]);
        
        throw $e;
    }

    /**
     * Apply filters to query
     */
    protected function applyFilters($query, array $filters = [])
    {
        foreach ($filters as $column => $value) {
            if ($value !== null && $value !== '') {
                if (is_array($value)) {
                    $query->whereIn($column, $value);
                } elseif (strpos($column, '_like') !== false) {
                    $actualColumn = str_replace('_like', '', $column);
                    $query->where($actualColumn, 'LIKE', "%{$value}%");
                } elseif (strpos($column, '_from') !== false) {
                    $actualColumn = str_replace('_from', '', $column);
                    $query->where($actualColumn, '>=', $value);
                } elseif (strpos($column, '_to') !== false) {
                    $actualColumn = str_replace('_to', '', $column);
                    $query->where($actualColumn, '<=', $value);
                } else {
                    $query->where($column, $value);
                }
            }
        }
        
        return $query;
    }

    /**
     * Transform model to array with specific fields
     */
    protected function transformModel(Model $model, array $fields = [])
    {
        if (empty($fields)) {
            return $model->toArray();
        }
        
        return $model->only($fields);
    }

    /**
     * Paginate results with default parameters
     */
    protected function getPaginatedResults($query, int $perPage = 15, array $filters = [])
    {
        $query = $this->applyFilters($query, $filters);
        
        return $query->paginate($perPage);
    }

    /**
     * Generate unique reference ID
     */
    protected function generateReferenceId(string $prefix = '')
    {
        $timestamp = now()->format('YmdHis');
        $random = strtoupper(substr(md5(uniqid()), 0, 6));
        
        return $prefix . $timestamp . $random;
    }

    /**
     * Sanitize metadata array
     */
    protected function sanitizeMetadata(array $metadata = [])
    {
        return array_filter($metadata, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * Check if user has permission for merchant
     */
    protected function checkMerchantPermission($merchantId, $userId = null)
    {
        if (!$userId) {
            $userId = auth()->id();
        }
        
        // Add your permission logic here
        // This is a placeholder - implement based on your auth system
        return true;
    }

    /**
     * Log service activity
     */
    protected function logActivity(string $action, array $data = [])
    {
        Log::info("Service Activity: {$action}", array_merge($data, [
            'user_id' => auth()->id() ?? null,
            'timestamp' => now(),
            'service' => get_class($this)
        ]));
    }
}