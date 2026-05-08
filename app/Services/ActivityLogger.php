<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use App\Traits\DataNormalizer;
use Carbon\Carbon;

use Illuminate\Support\Collection;

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
    use DataNormalizer;

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
                $userId = Request::input('auth_user_id') 
                       ?? Request::instance()->attributes->get('auth_user_id')
                       ?? Request::input('admin_id')
                       ?? Request::input('client_id');
            }

            // Normalize first
            $action  = self::normalizeData($action);
            $message = self::normalizeData($message);

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
            $actionLower = strtolower($action);
            if (!str_contains($actionLower, 'import') && !str_contains($actionLower, 'export')) {
                $aType = str_contains($actionLower, 'added') || str_contains($actionLower, 'create') ? 'CREATE' 
                       : (str_contains($actionLower, 'delete') ? 'DELETE' : 'UPDATE');
                
                self::logActivity(
                    $uObj,
                    $aType,
                    $module ?? 'Activities',
                    null,
                    null,
                    null,
                    null,
                    $message,
                    Request::instance()
                );
            }
        } catch (\Exception $e) {
            Log::warning('ActivityLogger failed: ' . $e->getMessage());
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

    public static function imported(?int $userId, string $module, int $count, $historyId = null, int $failed = 0, int $duplicates = 0): void
    {
        try {
            $uObj = $userId ? DB::table('superadmins')->where('id', $userId)->first() : null;
            
            $msg = "{$count} record(s) imported into {$module}";
            
            if ($historyId) {
                $history = DB::table('import_histories')->where('id', $historyId)->first();
                if ($history) {
                    $failed = max($failed, $history->failed_rows ?? 0);
                    $duplicates = max($duplicates, $history->duplicates_count ?? 0);
                }
            }

            if ($failed > 0 || $duplicates > 0) {
                if ($count === 0 && $failed > 0) {
                    $msg = "Import failed for {$module}: {$failed} errors detected";
                } else {
                    $msg .= " | Failed: {$failed} | Duplicates: {$duplicates}";
                }
            }

            self::logActivity(
                $uObj,
                'IMPORT',
                $module,
                null,
                $historyId,
                null,
                ['failed_count' => $failed, 'success_count' => $count],
                $msg,
                Request::instance()
            );
            
            // Legacy activities table
            self::log($userId, ($count === 0 && $failed > 0) ? "{$module} Import Failed" : "{$module} Imported", $msg, $module);
        } catch (\Exception $e) {
            Log::warning('ActivityLogger::imported failed: ' . $e->getMessage());
        }
    }

    public static function exported(?int $userId, string $module, int $count, $historyId = null, $req = null): void
    {
        try {
            $uObj = $userId ? DB::table('superadmins')->where('id', $userId)->first() : null;
            self::logActivity(
                $uObj,
                'EXPORT',
                $module,
                null,
                $historyId,
                null,
                null,
                "{$count} record(s) exported from {$module}",
                $req ?? Request::instance()
            );
        } catch (\Exception $e) {
            Log::warning('ActivityLogger::exported failed: ' . $e->getMessage());
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
                $role = $user->role ?? (isset($user->login_type) ? ($user->login_type === 1 ? 'Superadmin' : ($user->login_type === 3 ? 'Client' : 'User')) : 'Unknown');
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
                $role = isset($dbUser->login_type) ? ($dbUser->login_type === 1 ? 'Superadmin' : ($dbUser->login_type === 3 ? 'Client' : 'User')) : 'Unknown';
            } else {
                $userId = null;
                $userName = null;
                $role = null;
            }

            // FILTER & CLEANUP DATA
            $oldClean = is_array($oldData) ? self::flattenFields($oldData, $module) : [];
            $newClean = is_array($newData) ? self::flattenFields($newData, $module) : [];

            $changes = [];
            $allKeys = array_unique(array_merge(array_keys($oldClean), array_keys($newClean)));

            foreach ($allKeys as $key) {
                // Ignore technical / huge / redundant fields
                if (in_array($key, [
                    'updated_at', 'created_at', 'password', 'token', 'metadata', 
                    'id', 's_id', 'client_id', 'vendor_id', 'product_id', 'domain_id', 'domain_master_id',
                    'deleted_at', 'last_updated', 'created_at_formatted', 'updated_at_formatted', 
                    'has_remark_history', 'login_type', 'profile',
                    // Ignore remark history noise
                    'remark_histories_count', 'record_type', 'days_left', 'days_to_delete',
                    'grace_period', 'expiry_date', 'due_date'
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

            // Set specific description for UPDATES, CREATES and DELETES
            $actionTypeUpper = strtoupper($actionType);
            if (!$description || preg_match('/(updated|created|deleted)$/i', trim($description)) || strlen($description) < 5) {
                if ($actionTypeUpper === 'UPDATE' && Collection::make($changes)->count() > 0) {
                    $detailStr = [];
                    foreach ($changes as $c) {
                        $cOld = $c['old'] ?? 'N/A';
                        $cNew = $c['new'] ?? 'N/A';
                        $detailStr[] = "{$c['field']} changed from {$cOld} to {$cNew}";
                    }
                    
                    if (count($changes) === 1) {
                        $description = "{$changes[0]['field']} changed from " . ($changes[0]['old'] ?? 'N/A') . " to " . ($changes[0]['new'] ?? 'N/A');
                    } else {
                        $description = "{$module} updated: " . implode(', ', $detailStr);
                    }
                } else if ($actionTypeUpper === 'CREATE') {
                    $itemLabel = $newClean['product'] ?? $newClean['client'] ?? $newClean['domain'] ?? $recordId;
                    $description = "{$module} created: " . $itemLabel;
                    if (!empty($newClean['client']) && !empty($newClean['product'])) {
                        $description = "{$module} created: {$newClean['product']} for {$newClean['client']}";
                    }
                } else if ($actionTypeUpper === 'DELETE') {
                    $itemLabel = $oldClean['product'] ?? $oldClean['client'] ?? $oldClean['domain'] ?? $recordId;
                    $description = "{$module} deleted: " . $itemLabel;
                    if (!empty($oldClean['client']) && !empty($oldClean['product'])) {
                        $description = "{$module} deleted: {$oldClean['product']} for {$oldClean['client']}";
                    }
                }
            }

            // Prepare JSON payload - include full cleaned data for CREATE/DELETE
            $payload = [];
            if (strtoupper($actionType) === 'CREATE') {
                $payload = $newClean;
            } else if (strtoupper($actionType) === 'DELETE') {
                $payload = $oldClean;
            }

            // Prioritize key fields for single-glance view
            $payload['Product'] = $newClean['Product'] ?? $newClean['Product Name'] ?? $newClean['product'] ?? $newClean['product_name'] ?? $oldClean['Product'] ?? $oldClean['Product Name'] ?? $oldClean['product'] ?? $oldClean['product_name'] ?? null;
            $payload['Client'] = $newClean['Client'] ?? $newClean['Client Name'] ?? $newClean['client'] ?? $newClean['client_name'] ?? $oldClean['Client'] ?? $oldClean['Client Name'] ?? $oldClean['client'] ?? $oldClean['client_name'] ?? null;
            $payload['Vendor'] = $newClean['Vendor'] ?? $newClean['Vendor Name'] ?? $newClean['vendor'] ?? $newClean['vendor_name'] ?? $oldClean['Vendor'] ?? $oldClean['Vendor Name'] ?? $oldClean['vendor'] ?? $oldClean['vendor_name'] ?? null;
            $payload['Domain'] = $newClean['Domain'] ?? $newClean['Domain Name'] ?? $newClean['domain'] ?? $newClean['domain_name'] ?? $oldClean['Domain'] ?? $oldClean['Domain Name'] ?? $oldClean['domain'] ?? $oldClean['domain_name'] ?? null;
            
            // Legacy/Redundant keys for safety
            $payload['product_name'] = $payload['Product'];
            $payload['client_name'] = $payload['Client'];
            $payload['vendor_name'] = $payload['Vendor'];
            $payload['domain_name'] = $payload['Domain'];
            
            $payload['changes'] = $changes;

            $logPayload = (!empty($changes) || strtoupper($actionType) !== 'UPDATE') ? json_encode($payload) : null;

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
                'ip_address' => $req ? $req->ip() : Request::ip(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now()
            ]);
        } catch (\Exception $e) {
            Log::warning('AuditLog failed: ' . $e->getMessage());
        }
    }

    private static function flattenFields(array $data, ?string $module = null)
    {
        $flat = [];
        
        // 1. Resolve known IDs dynamically — fetch readable names and decrypt them
        if (!empty($data['product_id']) && !isset($data['product'])) {
            $p = DB::table('products')->where('id', $data['product_id'])->first();
            if ($p) {
                $pName = $p->product_name ?? $p->name ?? 'Unknown Product';
                try { $dec = CryptService::decryptData($pName); if ($dec && $dec !== $pName) $pName = $dec; } catch (\Exception $e) {}
                $data['product'] = self::normalizeData($pName, 'Product');

            }
        }
        if (!empty($data['client_id']) && !isset($data['client'])) {
            $c = DB::table('superadmins')->where('id', $data['client_id'])->first();
            if ($c) {
                $cName = $c->name ?? 'Unknown Client';
                try { $dec = CryptService::decryptData($cName); if ($dec && $dec !== $cName) $cName = $dec; } catch (\Exception $e) {}
                $data['client'] = self::normalizeData($cName, 'Client');

            }
        }
        if (!empty($data['vendor_id']) && !isset($data['vendor'])) {
            $v = DB::table('vendors')->where('id', $data['vendor_id'])->first();
            if ($v) {
                $vName = $v->name ?? 'Unknown Vendor';
                try { $dec = CryptService::decryptData($vName); if ($dec && $dec !== $vName) $vName = $dec; } catch (\Exception $e) {}
                $data['vendor'] = self::normalizeData($vName, 'Vendor');

            }
        }
        if ((!empty($data['domain_id']) || !empty($data['domain_master_id'])) && !isset($data['domain'])) {
            $dId = $data['domain_id'] ?? $data['domain_master_id'];
            // Check both 'domains' and 'domain_master' tables
            $d = DB::table('domain_master')->where('id', $dId)->first() 
                 ?? DB::table('domains')->where('id', $dId)->first();
            if ($d) {
                $dName = $d->domain_name ?? $d->name ?? 'Unknown Domain';
                try { $dec = CryptService::decryptData($dName); if ($dec && $dec !== $dName) $dName = $dec; } catch (\Exception $e) {}
                $data['domain'] = self::normalizeData($dName, 'Domain');

            }
        }


        foreach ($data as $key => $val) {
            // Format dates for display
            if (in_array($key, ['renewal_date', 'deletion_date', 'start_date', 'validity_date', 'valid_till', 'due_date']) && !empty($val)) {
                try {
                    $val = Carbon::parse($val)->format('j/n/Y');
                } catch (\Exception $e) {}
            }
            // IGNORE technical / irrelevant fields from being logged
            $keyLower = strtolower($key);
            if (in_array($keyLower, [
                'updated_at', 'created_at', 'password', 'token', 'metadata', 
                'id', 's_id', 'client_id', 'vendor_id', 'product_id', 'domain_id', 
                'deleted_at', 'last_updated', 'created_at_formatted', 
                'updated_at_formatted', 'has_remark_history', 'login_type', 'profile',
                'remark_histories_count', 'domain_ids', 'domain_records',
                'auth_user_id', 'session_id', 'admin_id', 'ids',
                // Skip raw encrypted name fields — resolved and decrypted above as product/client/vendor
                // Skip computed/noise fields
                'record_type', 'expiry_date', 'days_left', 'days_to_delete', 'grace_period',
                // Skip alias duplicates — already covered by primary fields (amount, renewal_date)
                'counter_count', 'valid_till', 'validity_date',
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
                        // Try standard CryptService first
                        $dec = CryptService::decryptData($val);
                        if (!$dec || preg_match('/^[A-Za-z0-9+\/=]{16,}$/', $dec)) {
                            // Try CustomCipherService::decryptData as fallback
                            $dec = CustomCipherService::decryptData($val) ?? $dec;
                        }
                        
                        if ($dec && !preg_match('/^[A-Za-z0-9+\/=]{16,}$/', $dec)) {
                            $val = $dec;
                        }
                    } catch (\Exception $e) {}
                }
                // Rename specific fields for user-friendly logging
                $logKey = ucwords(str_replace('_', ' ', $key));
                if ($keyLower === 'amount') {
                    $logKey = ($module === 'Counter') ? 'Count' : 'Amount';
                }
                if ($keyLower === 'renewal_date' || $keyLower === 'valid_till' || $keyLower === 'expiry_date' || $keyLower === 'validity_date') {
                    $logKey = 'Renewal Date';
                }
                if ($keyLower === 'domain_name') $logKey = 'Domain';
                if ($keyLower === 'client_name') $logKey = 'Client';
                if ($keyLower === 'vendor_name') $logKey = 'Vendor';
                if ($keyLower === 'product_name') $logKey = 'Product';
                
                $flat[$logKey] = self::normalizeData($val, $logKey);

            }
        }
        return $flat;
    }
}
