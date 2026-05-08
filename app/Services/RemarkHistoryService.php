<?php

namespace App\Services;

use App\Models\RemarkHistory;
use App\Services\CryptService;

class RemarkHistoryService
{
    /**
     * Store the old remark into history if it exists and is different from the new one.
     *
     * @param string $module
     * @param int $recordId
     * @param string|null $oldRemark
     * @param string|null $newRemark
     * @return void
     */
    public static function trackChange($module, $recordId, $oldRemark, $newRemark)
    {
        $oldDecrypted = $oldRemark;
        try {
            $decrypted = CryptService::decryptData($oldRemark);
            if ($decrypted) $oldDecrypted = $decrypted;
        } catch (\Exception $e) {}

        $oldRem = trim($oldDecrypted ?? '');
        $newRem = trim($newRemark ?? '');

        // If remark actually changed, store the OLD one into history (so we have a trail)
        if ($oldRem !== $newRem && !empty($oldRem)) {
            self::logEntry($module, $recordId, $oldRemark, "Remark updated");
        }
    }

    /**
     * Log a specific record update even if remarks didn't change.
     */
    public static function logUpdate($module, $record, array $newData)
    {
        $changedFields = [];
        $importantFields = ['grace_period', 'renewal_date', 'deletion_date', 'amount', 'status'];
        
        foreach ($importantFields as $field) {
            if (isset($newData[$field]) && $record->$field != $newData[$field]) {
                $changedFields[] = str_replace('_', ' ', ucfirst($field));
            }
        }

        $newRemark = $newData['remarks'] ?? null;
        $oldRemark = $record->remarks;
        
        $oldDec = CryptService::decryptData($oldRemark);
        if (trim($oldDec ?? '') !== trim($newRemark ?? '') && !empty($newRemark)) {
             // Remark changed. Log the OLD one before it's gone.
             self::logEntry($module, $record->id, $oldRemark);
        } elseif (!empty($changedFields)) {
             // Other fields changed. Log current remark as context for this "event".
             $msg = "Updated " . implode(', ', $changedFields);
             // If remark is empty, we still log that a change happened.
             self::logEntry($module, $record->id, $oldRemark, $msg);
        }
    }

    /**
     * Internal helper to create a history entry.
     */
    private static function logEntry($module, $recordId, $encRemark, $suffix = "")
    {
        $userName = 'System / Admin';
        if (auth()->check()) {
            $u = auth()->user();
            $userName = $u->name ?? $u->email ?? ('User ID: ' . $u->id);
        } else {
            $request = request();
            $uid = $request->attributes->get('auth_user_id');
            if ($uid) {
                $u = \Illuminate\Support\Facades\DB::table('superadmins')->where('id', $uid)->first();
                $userName = $u->name ?? $u->email ?? ("User #$uid");
            }
        }

        try {
            $dec = CryptService::decryptData($userName);
            if ($dec) $userName = $dec;
        } catch (\Exception $e) {}

        RemarkHistory::create([
            'module'    => $module,
            'record_id' => $recordId,
            'remark'    => $encRemark ?? '', // Keep it encrypted in history too
            'user_name' => $userName . ($suffix ? " ($suffix)" : ""),
        ]);
    }

    /**
     * Get history for a specific record.
     *
     * @param string $module
     * @param int $recordId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getHistory($module, $recordId)
    {
        return RemarkHistory::where('module', $module)
            ->where('record_id', $recordId)
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
