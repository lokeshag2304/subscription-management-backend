<?php

namespace App\Services;

use Carbon\Carbon;

class DateFormatterService
{
    /**
     * Formats a date string or Carbon instance to ISO-8601 string
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
            return Carbon::parse($date)->toIso8601String();
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function formatDateTime($value)
    {
        if (!$value) return null;

        try {
            return Carbon::parse($value)
                ->timezone('Asia/Kolkata')
                ->format('j/n/Y, g:i:s a');
        } catch (\Exception $e) {
            return null;
        }
    }
}
