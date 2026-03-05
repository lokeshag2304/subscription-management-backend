<?php

namespace App\Services;

use Carbon\Carbon;

class DateFormatterService
{
    /**
     * Formats a date string or Carbon instance to DD-MM-YYYY HH:MM:SS
     *
     * @param mixed $date
     * @return string|null
     */
    public static function format($date)
    {
        if (empty($date)) {
            return null;
        }

        try {
            return Carbon::parse($date)->format('d-m-Y H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
}
