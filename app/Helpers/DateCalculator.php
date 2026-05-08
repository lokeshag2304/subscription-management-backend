<?php

namespace App\Helpers;

use Carbon\Carbon;
use Exception;

class DateCalculator
{
    /**
     * Safely calculate the difference in days between renewal date and deletion date.
     * Discards any frontend values and strictly computes server-side.
     * 
     * @param string|null $renewalDate
     * @param string|null $deletionDate
     * @return int|null
     * @throws Exception
     */
    public static function calculateDaysToDelete($renewalDate, $deletionDate)
    {
        if (empty($renewalDate) || empty($deletionDate)) {
            return null;
        }

        // Parsing dates securely with timezone safety (startOfDay ensures pure date difference)
        $renewal = Carbon::parse($renewalDate)->startOfDay();
        $deletion = Carbon::parse($deletionDate)->startOfDay();

        if ($deletion->lt($renewal)) {
            throw new Exception("Deletion date cannot be earlier than renewal/expiry date.");
        }

        // Returns absolute int days difference
        return (int) $renewal->diffInDays($deletion);
    }
}
