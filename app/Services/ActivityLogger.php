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

            // Automatically mirror into new activity_logs to ensure full transparency
            $uObj = $userId ? DB::table('superadmins')->where('id', $userId)->first() : null;
            $aType = str_contains(strtolower($action), 'added') || str_contains(strtolower($action), 'create') ? 'CREATE' 
                   : (str_contains(strtolower($action), 'delete') ? 'DELETE' : 'UPDATE');
            
            self::logActivity(
                $uObj,
                $aType,
                $module ?? 'Activities',
                null,
                null,
                null,
                null,
                $message,
                request()
            );
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

    public static function exported(?int $userId, string $module, int $count): void
    {
        try {
            $uObj = $userId ? DB::table('superadmins')->where('id', $userId)->first() : null;
            self::logActivity(
                $uObj,
                'EXPORT',
                $module,
                null,
                null,
                null,
                null,
                "{$count} record(s) exported from {$module}",
                request()
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('ActivityLogger::exported failed: ' . $e->getMessage());
        }
    }

    /**
     * Audit Trail logging logic.
     */
    public static function logActivity($user, $actionType, $module, $tableName, $recordId, $oldData, $newData, $description, $req = null)
    {
        try {
            if ($user && is_object($user)) {
                $userId = $user->id;
                $userName = $user->name ?? '';
                $role = $user->role ?? (isset($user->login_type) && $user->login_type === 1 ? 'Superadmin' : (isset($user->login_type) ? 'ClientAdmin' : 'Unknown'));
                // Decrypt name if stored encrypted
                if (is_string($userName) && strlen($userName) > 16 && preg_match('/^[A-Za-z0-9+\/=]{16,}$/', $userName) && !preg_match('/[\s\-\_\.\,\:\@\/]/', $userName)) {
                    try { $dec = CryptService::decryptData($userName); if ($dec && $dec !== $userName) $userName = $dec; } catch (\Exception $e) {}
                    try { $dec = CustomCipherService::decryptData($userName); if ($dec && $dec !== $userName) $userName = $dec; } catch (\Exception $e) {}
                }
            } elseif ($user && isset($user->id)) {
                // Resolve from DB as fallback
                $userId = $user->id;
                $dbUser = DB::table('superadmins')->where('id', $userId)->first();
                $userName = $dbUser->name ?? null;
                if ($userName && is_string($userName) && strlen($userName) > 16 && preg_match('/^[A-Za-z0-9+\/=]{16,}$/', $userName) && !preg_match('/[\s\-\_\.\,\:\@\/]/', $userName)) {
                    try { $dec = CryptService::decryptData($userName); if ($dec && $dec !== $userName) $userName = $dec; } catch (\Exception $e) {}
                }
                $role = isset($dbUser->login_type) ? ($dbUser->login_type === 1 ? 'Superadmin' : ($dbUser->login_type === 3 ? 'ClientAdmin' : 'Admin')) : 'Unknown';
            } else {
                $userId = null;
                $userName = null;
                $role = null;
            }

            // FILTER & CLEANUP DATA
            $oldClean = is_array($oldData) ? self::flattenFields($oldData) : [];
            $newClean = is_array($newData) ? self::flattenFields($newData) : [];

            $changes = [];
            $allKeys = array_unique(array_merge(array_keys($oldClean), array_keys($newClean)));

            foreach ($allKeys as $key) {
                // Ignore technical / huge / redundant fields
                if (in_array($key, [
                    'updated_at', 'created_at', 'password', 'token', 'metadata', 
                    'id', 's_id', 'client_id', 'vendor_id', 'product_id', 'domain_id', 
                    'deleted_at', 'last_updated', 'created_at_formatted', 'updated_at_formatted', 
                    'has_remark_history', 'login_type', 'profile',
                    // Ignore raw encrypted _name fields; we use clean product/client/vendor resolved above
                    'product_name', 'client_name', 'vendor_name',
                    // Ignore raw relation objects
                    'product', 'client', 'vendor',
                    // Ignore remark history noise
                    'remark_histories_count', 'record_type', 'days_left', 'days_to_delete',
                ])) {
                    continue;
                }
                
                $oldVal = $oldClean[$key] ?? null;
                $newVal = $newClean[$key] ?? null;

                // Skip if either value still looks like an encrypted token (base64-like, long random string)
                $isEncrypted = function($v) {
                    if (!is_string($v) || strlen($v) < 20) return false;
                    // If it does not contain spaces and is all base64 chars, likely still encrypted
                    return preg_match('/^[A-Za-z0-9+\/=]{20,}$/', $v) && !preg_match('/\s/', $v);
                };
                if ($isEncrypted($oldVal) || $isEncrypted($newVal)) continue;

                if (strtoupper($actionType) === 'UPDATE') {
                    if ((string)$oldVal !== (string)$newVal) {
                        $changes[] = [
                            'field' => ucwords(str_replace('_', ' ', $key)),
                            'old' => $oldVal,
                            'new' => $newVal
                        ];
                    }
                } else if (strtoupper($actionType) === 'CREATE') {
                    if ($newVal !== null && $newVal !== "") {
                        $changes[] = [
                            'field' => ucwords(str_replace('_', ' ', $key)),
                            'old' => null,
                            'new' => $newVal
                        ];
                    }
                } else if (strtoupper($actionType) === 'DELETE') {
                    if ($oldVal !== null && $oldVal !== "") {
                        $changes[] = [
                            'field' => ucwords(str_replace('_', ' ', $key)),
                            'old' => $oldVal,
                            'new' => null
                        ];
                    }
                }
            }

            // Set specific description for UPDATES
            if (strtoupper($actionType) === 'UPDATE' && collect($changes)->count() > 0) {
                $detailStr = [];
                foreach ($changes as $c) {
                    $cOld = $c['old'] ?? 'N/A';
                    $cNew = $c['new'] ?? 'N/A';
                    $detailStr[] = "{$c['field']} changed from {$cOld} to {$cNew}";
                }
                
                $newDescription = "";
                if (count($changes) === 1) {
                    $newDescription = "{$changes[0]['field']} changed from " . ($changes[0]['old'] ?? 'N/A') . " to " . ($changes[0]['new'] ?? 'N/A');
                } else {
                    $newDescription = "{$module} updated: " . implode(', ', $detailStr);
                }

                // Only overwrite if current description is generic, empty, or just "Module Updated"
                if (!$description || preg_match('/(updated|created|deleted)$/i', trim($description)) || strlen($description) < 5) {
                    $description = $newDescription;
                }
            }

            // Prepare JSON payload
            $logPayload = !empty($changes) || strtoupper($actionType) !== 'UPDATE' ? json_encode([
                'product_name' => $newClean['product'] ?? $oldClean['product'] ?? null,
                'client_name' => $newClean['client'] ?? $oldClean['client'] ?? null,
                'changes' => $changes
            ]) : null;

            DB::table('activity_logs')->insert([
                'user_id' => $userId,
                'user_name' => $userName,
                'role' => $role,
                'action_type' => strtoupper($actionType),
                'module' => $module,
                'table_name' => $tableName,
                'record_id' => $recordId,
                'old_data' => null, // We store simplified changes array in new_data
                'new_data' => $logPayload,
                'description' => $description,
                'ip_address' => $req ? $req->ip() : request()->ip(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('AuditLog failed: ' . $e->getMessage());
        }
    }

    private static function flattenFields(array $data)
    {
        $flat = [];
        
        // Resolve known IDs dynamically — fetch readable names and decrypt them
        if (!empty($data['product_id']) && !isset($data['product'])) {
            $p = DB::table('products')->where('id', $data['product_id'])->first();
            if ($p) {
                $pName = $p->product_name ?? $p->name ?? 'Unknown Product';
                if ($pName && is_string($pName) && strlen($pName) > 16 && preg_match('/^[A-Za-z0-9+\/=]{16,}$/', $pName) && !preg_match('/[\s\-\_\.\,\:\@\/]/', $pName)) {
                    try { $dec = CryptService::decryptData($pName); if ($dec && $dec !== $pName) $pName = $dec; } catch (\Exception $e) {}
                }
                $data['product'] = $pName;
            }
        }
        if (!empty($data['client_id']) && !isset($data['client'])) {
            $c = DB::table('superadmins')->where('id', $data['client_id'])->first();
            if ($c) {
                $cName = $c->name ?? 'Unknown Client';
                if ($cName && is_string($cName) && strlen($cName) > 16 && preg_match('/^[A-Za-z0-9+\/=]{16,}$/', $cName) && !preg_match('/[\s\-\_\.\,\:\@\/]/', $cName)) {
                    try { $dec = CryptService::decryptData($cName); if ($dec && $dec !== $cName) $cName = $dec; } catch (\Exception $e) {}
                }
                $data['client'] = $cName;
            }
        }
        if (!empty($data['vendor_id']) && !isset($data['vendor'])) {
            $v = DB::table('vendors')->where('id', $data['vendor_id'])->first();
            if ($v) {
                $vName = $v->name ?? 'Unknown Vendor';
                if ($vName && is_string($vName) && strlen($vName) > 16 && preg_match('/^[A-Za-z0-9+\/=]{16,}$/', $vName) && !preg_match('/[\s\-\_\.\,\:\@\/]/', $vName)) {
                    try { $dec = CryptService::decryptData($vName); if ($dec && $dec !== $vName) $vName = $dec; } catch (\Exception $e) {}
                }
                $data['vendor'] = $vName;
            }
        }

        if (!empty($data['domain_id']) && !isset($data['domain'])) {
            $d = DB::table('domains')->where('id', $data['domain_id'])->first();
            if ($d) {
                $dName = $d->domain_name ?? $d->name ?? 'Unknown Domain';
                if ($dName && is_string($dName) && strlen($dName) > 16 && preg_match('/^[A-Za-z0-9+\/=]{16,}$/', $dName) && !preg_match('/[\s\-\_\.\,\:\@\/]/', $dName)) {
                    try { $dec = CryptService::decryptData($dName); if ($dec && $dec !== $dName) $dName = $dec; } catch (\Exception $e) {}
                }
                $data['domain'] = $dName;
            }
        }

        foreach ($data as $key => $val) {
            // Format dates for display
            if (in_array($key, ['renewal_date', 'deletion_date', 'start_date']) && !empty($val)) {
                try {
                    $val = \Carbon\Carbon::parse($val)->format('d-m-Y');
                } catch (\Exception $e) {}
            }
            // IGNORE technical / irrelevant fields from being logged
            if (in_array($key, [
                'updated_at', 'created_at', 'password', 'token', 'metadata', 
                'id', 's_id', 'client_id', 'vendor_id', 'product_id', 'domain_id', 
                'deleted_at', 'last_updated', 'created_at_formatted', 
                'updated_at_formatted', 'has_remark_history', 'login_type', 'profile',
                'remark_histories_count', 'domain_ids', 'domain_records',
                'auth_user_id', 'session_id', 'admin_id',
                // Skip raw encrypted name fields — resolved and decrypted above as product/client/vendor
                'product_name', 'client_name', 'vendor_name',
                // Skip computed/noise fields
                'record_type', 'expiry_date', 'days_left', 'days_to_delete',
            ])) {
                continue;
            }

            if (is_array($val) || is_object($val)) {
                $nested = (array) $val;
                // Attempt to grab a readable name from objects
                if (!empty($nested['name'])) $flat[$key] = $nested['name'];
                elseif (!empty($nested['title'])) $flat[$key] = $nested['title'];
                elseif (!empty($nested['domain_name'])) $flat[$key] = $nested['domain_name'];
                elseif (!empty($nested['product_name'])) $flat[$key] = $nested['product_name'];
                else $flat[$key] = json_encode($nested);
            } else {
                // Try decryption for any string that looks encrypted
                if (is_string($val) && strlen($val) > 16 && preg_match('/^[A-Za-z0-9+\/=]{16,}$/', $val) && !preg_match('/[\s\-\_\.\,\:\@\/]/', $val)) {
                    try {
                        $dec = CryptService::decryptData($val);
                        if ($dec && $dec !== $val) {
                            $val = $dec;
                        }
                    } catch (\Exception $e) {}
                    // Second-layer: try CustomCipher if still looks encoded
                    if (is_string($val) && strlen($val) > 16 && preg_match('/^[A-Za-z0-9+\/=]{16,}$/', $val) && !preg_match('/[\s\-\_\.\,\:\@\/]/', $val)) {
                        try {
                            $dec = CustomCipherService::decryptData($val);
                            if ($dec && $dec !== $val) $val = $dec;
                        } catch (\Exception $e) {}
                    }
                }
                // Rename specific fields for user-friendly logging
                $logKey = $key;
                if ($key === 'amount') $logKey = 'Amount';
                
                $flat[$logKey] = $val;
            }
        }
        return $flat;
    }
}
