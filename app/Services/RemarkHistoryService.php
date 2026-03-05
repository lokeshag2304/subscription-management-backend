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

        // Track if changed, even if old was empty (we store empty to show when it was first set)
        if ($oldRem !== $newRem) {
            
            $userName = 'System / Admin';
            
            // Try to get from auth() first
            if (auth()->check()) {
                $u = auth()->user();
                $userName = $u->name ?? $u->email ?? ('User ID: ' . $u->id);
            } else {
                // Fallback to request attributes (injected by middleware)
                $request = request();
                $uid = $request->attributes->get('auth_user_id');
                if ($uid) {
                    $u = \Illuminate\Support\Facades\DB::table('superadmins')->where('id', $uid)->first();
                    $userName = $u->name ?? $u->email ?? ("User #$uid");
                }
            }

            try {
                $decrypted = CryptService::decryptData($userName);
                if ($decrypted) $userName = $decrypted;
            } catch (\Exception $e) {}

            RemarkHistory::create([
                'module'    => $module,
                'record_id' => $recordId,
                'remark'    => $oldRemark ?? '', // Store the actual old value
                'user_name' => $userName,
            ]);
        }
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
