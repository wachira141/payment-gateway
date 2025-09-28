<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;


    /**
     * Get the start of the day for a given date.
     * @param string $date
     * @return \Carbon\Carbon
     */
    protected function endOfDay($date)
    {
        return \Carbon\Carbon::parse($date)->endOfDay();
    }

    /**
     * Standard pagination response format
     */
    protected function paginatedResponse(LengthAwarePaginator $paginated, bool $success = true, string $message = null): array
    {
        return [
            'success' => $success,
            'data' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
            ],
            'message' => $message,
        ];
    }
}
