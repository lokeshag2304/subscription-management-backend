<?php

namespace App\Traits;

use App\Services\GracePeriodService;

trait GracePeriodTrait
{
    /**
     * Boot the trait.
     */
    /**
     * Cache for schema checks to avoid redundant queries.
     */
    protected static array $gracePeriodSchemaChecked = [];

    /**
     * Boot the trait.
     */
    protected static function bootGracePeriodTrait()
    {
        // We REMOVE the retrieved event because it causes massive performance issues
        // and unintended DB writes during read operations.
        // Status sync should be handled by the controller or a scheduled task.

        static::saving(function ($model) {
            $table = $model->getTable();
            if (!isset(self::$gracePeriodSchemaChecked[$table])) {
                self::$gracePeriodSchemaChecked[$table] = \Illuminate\Support\Facades\Schema::hasColumn($table, 'grace_period');
            }

            if (self::$gracePeriodSchemaChecked[$table]) {
                GracePeriodService::syncModel($model);
            }
        });
    }
}
