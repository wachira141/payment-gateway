<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

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
}
