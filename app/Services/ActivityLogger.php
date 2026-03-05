<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * ActivityLogger — logs CRUD operations from all modules into the `activities` table
 * in the format expected by ActivitiesController::getAllActivities().
 *
 * Usage:
 *   ActivityLogger::log($userId, 'SSL Added', 'SSL record added for domain: example.com');
 *   ActivityLogger::log($userId, 'Subscription Updated', 'Renewal date changed');
 */
class ActivityLogger
{
    /**
     * Log an activity entry.
     *
     * @param int|null    $userId   The superadmin/user ID performing the action
     * @param string      $action   Plain-text action label (e.g. "SSL Added")
     * @param string      $message  Human-readable details (e.g. "domain: example.com | client: Acme")
     * @param string|null $module   Optional module name (Subscription, SSL, Hosting, etc.)
     */
    public static function log(?int $userId, string $action, string $message, ?string $module = null): void
    {
        try {
            if ($userId === null) {
                $userId = request()->input('auth_user_id') 
                       ?? request()->attributes->get('auth_user_id')
                       ?? request()->input('admin_id')
                       ?? request()->input('client_id');
            }

            // Encrypt for the encrypted columns
            $encAction  = CryptService::encryptData($action);
            $encMessage = CryptService::encryptData($message);
            $sAction    = CustomCipherService::encryptData($action);
            $sMessage   = CustomCipherService::encryptData($message);

            DB::table('activities')->insert([
                'user_id'    => $userId,
                'action'     => $encAction,
                's_action'   => $sAction,
                'message'    => $encMessage,
                's_message'  => $sMessage,
                'module'     => $module,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        } catch (\Exception $e) {
            // Never let activity logging crash the main request
            \Illuminate\Support\Facades\Log::warning('ActivityLogger failed: ' . $e->getMessage());
        }
    }

    /**
     * Shorthand helpers for common CRUD operations.
     */
    public static function added(?int $userId, string $module, string $details): void
    {
        self::log($userId, "{$module} Added", $details, $module);
    }

    public static function updated(?int $userId, string $module, string $details): void
    {
        self::log($userId, "{$module} Updated", $details, $module);
    }

    public static function deleted(?int $userId, string $module, string $details): void
    {
        self::log($userId, "{$module} Deleted", $details, $module);
    }

    public static function imported(?int $userId, string $module, int $count): void
    {
        self::log($userId, "{$module} Imported", "{$count} record(s) imported into {$module}", $module);
    }
}
