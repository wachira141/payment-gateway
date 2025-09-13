<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\ApplicationDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplicationDataController extends Controller
{
    /**
     * @var ApplicationDataService
     */
    protected $applicationDataService;

    /**
     * ApplicationDataController constructor.
     *
     * @param ApplicationDataService $applicationDataService
     */
    public function __construct(ApplicationDataService $applicationDataService)
    {
        $this->applicationDataService = $applicationDataService;
    }

    /**
     * Get all application data or specific data based on the query parameter.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        // Get the query parameter (e.g., ?type=frequencies)
        $type = $request->query('type');

        // If no type is specified, return all data
        if (!$type) {
            return response()->json([
                'hours' => $this->applicationDataService->getHours(),
                'countriesAndCurrencies' => $this->applicationDataService->getCountriesAndCurrencies(),
                'systemLanguages' => $this->applicationDataService->getSystemLanguages(),
            ]);
        }

        
        // Return specific data based on the query parameter
        switch ($type) {
            case 'hours':
                return response()->json(['hours' => $this->applicationDataService->getHours()]);
            case 'countriesAndCurrencies':
                return response()->json(['countriesAndCurrencies' => $this->applicationDataService->getCountriesAndCurrencies()]);
            case 'languages':
                return response()->json(['languages' => $this->applicationDataService->getLanguages()]);
            case 'systemLanguages':
                return response()->json(['systemLanguages' => $this->applicationDataService->getSystemLanguages()]);
            default:
                return response()->json(['error' => 'Invalid type specified'], 400);
        }
    }

    /**
     * Clear the cache for all application data.
     *
     * @return JsonResponse
     */
    public function clearCache(): JsonResponse
    {
        $this->applicationDataService->clearCache();
        return response()->json(['message' => 'Cache cleared successfully']);
    }
}
