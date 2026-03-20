<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class GracePeriodService
{
    /**
     * Calculate Due Date and check if status should be updated.
     * 
     * @param string|null $renewalDate
     * @param int $gracePeriod
     * @return array
     */
    public static function calculate(?string $renewalDate, int $gracePeriod = 0): array
    {
        if (!$renewalDate) {
            return [
                'due_date' => null,
                'should_be_inactive' => false
            ];
        }

        $renewal = Carbon::parse($renewalDate);
        $dueDate = $renewal->copy()->addDays($gracePeriod);
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
        $renewalDate = $model->renewal_date ?? $model->expiry_date;
        $res = self::calculate($renewalDate, $model->grace_period ?? 0);
        
        $model->due_date = $res['due_date'];
        
        // If it should be inactive, update status to 0 (Assuming 0 is Inactive/Not-in-Use)
        if ($res['should_be_inactive']) {
            $model->status = 0;
        }
        
        // \Log::info("GracePeriodSync for " . get_class($model) . " ID: " . $model->id . " | Due: " . $model->due_date);
    }
}
