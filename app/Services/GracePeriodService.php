<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;

class GracePeriodService
{
    /**
     * Calculate Due Date and check if status should be updated.
     * 
     * @param string|null $renewalDate
     * @param int $gracePeriod
     * @return array
     */
    public static function calculate(?string $renewalDate, int $gracePeriod = 0, ?string $manualDueDate = null): array
    {
        // If we have a manualDueDate, respect it (usually for explicit manual overrides)
        if ($manualDueDate) {
            $dueDate = Carbon::parse($manualDueDate);
        } elseif ($renewalDate) {
            $renewal = Carbon::parse($renewalDate);
            $dueDate = $renewal->copy()->addDays($gracePeriod);
        } else {
            return [
                'due_date' => null,
                'should_be_inactive' => false
            ];
        }

        $today = now()->startOfDay();

        return [
            'due_date' => $dueDate->toDateTimeString(),
            'should_be_inactive' => $today->greaterThan($dueDate->startOfDay())
        ];
    }

    /**
     * Sync grace period logic for a model.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return void
     */
    public static function syncModel($model)
    {
        // When syncing, we always want to recalculate from the renewal_date to ensure consistency
        // ONLY if a manual due_date has not been explicitly provided.
        $renewalDate = $model->renewal_date ?? $model->expiry_date;
        $res = self::calculate($renewalDate, $model->grace_period ?? 0, $model->due_date);
        
        $model->due_date = $res['due_date'];
        
        if ($res['should_be_inactive']) {
            $model->status = 0;
        }
    }
}
