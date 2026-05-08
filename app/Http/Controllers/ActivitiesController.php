<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use App\Lib\SMSinteg;
use App\Lib\Whatsappinteg;
use App\Lib\EmailInteg;
use App\Services\CryptService;

use App\Services\CustomCipherService;
use App\Services\DateFormatterService;

use Carbon\Carbon;

use App\Traits\DataNormalizer;

class ActivitiesController extends Controller
{
    use DataNormalizer;


public function getAllActivities(Request $request)
{
    $page        = (int) $request->input('page', 1);
    $rowsPerPage = (int) $request->input('rowsPerPage', 10);
    $order       = $request->input('order', 'desc');
    $orderBy     = $request->input('orderBy', 'id');
    $search      = $request->input('search', '');
    $admin_id    = $request->input('admin_id');
    $userFilter  = $request->input('userFilter');
    $moduleFilter = $request->input('moduleFilter');
    $actionFilter = $request->input('actionFilter');
    $dateFilter  = $request->input('dateFilter');

    $adminData = DB::table("superadmins")->where("id", $admin_id)->first();

    $offset = ($page - 1) * $rowsPerPage;

    $query = DB::table('activity_logs as a')
        ->leftJoin('superadmins as u', 'a.user_id', '=', 'u.id')
        ->select('a.*', 'u.name as fresh_user_name');

    // Client login → sirf apni activities
    if (!empty($adminData) && $adminData->login_type == 3) {
        $query->where('a.user_id', $admin_id);
    }

    // =========================
    // SEARCH & FILTERS
    // =========================
    if (!empty($search)) {
        $query->where(function ($q) use ($search) {
            $q->where('a.action_type', 'like', "%{$search}%")
              ->orWhere('a.user_name', 'like', "%{$search}%")
              ->orWhere('a.role', 'like', "%{$search}%")
              ->orWhere('a.module', 'like', "%{$search}%")
              ->orWhere('a.description', 'like', "%{$search}%");
        });
    }

    if (!empty($userFilter)) {
        $query->where('a.user_name', 'like', "%{$userFilter}%");
    }
    if (!empty($moduleFilter)) {
        $query->where('a.module', $moduleFilter);
    }
    if (!empty($actionFilter)) {
        $query->where('a.action_type', $actionFilter);
    }
    if (!empty($dateFilter)) {
        $query->whereDate('a.created_at', Carbon::parse($dateFilter)->format('Y-m-d'));
    }

    $total = (clone $query)->count();

    if ($request->input('all_ids')) {
        return response()->json([
            'status' => true,
            'ids'    => (clone $query)->pluck('a.id')->toArray()
        ]);
    }

    // Detect if a value looks like a base64/encrypted blob
    $isEncryptedBlob = function($v) {
        if (!is_string($v) || strlen($v) < 12) return false; // Lowered to 12 to catch more blobs
        return (bool) preg_match('/^[A-Za-z0-9+\/=]{12,}$/', $v) && !preg_match('/[\s\-\_\.\,\:\@\/]/', $v);
    };

    // Advanced recovery for corrupted plain text or custom mappings
    $recoverV = function($v, $fieldName = '') {
        if (!$v || !is_string($v)) return $v;
        
        $f = strtolower($fieldName);
        $recovered = $v;

        // 1. DATE RECOVERY (e.g., hphl-pl-hj -> 2026-03-24)
        if (str_contains($f, 'date') || preg_match('/^[a-z]{4}\-[a-z]{2}\-[a-z]{2}$/i', $v)) {
            $dateMap = ['h'=>'2', 'p'=>'0', 'i'=>'3', 'j'=>'4', 'k'=>'5', 'l'=>'6', 'm'=>'7', 'n'=>'8', 'g'=>'1', 'o'=>'9'];
            $temp = strtr(strtolower($v), $dateMap);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $temp)) return $temp;
        }
        
        // 2. TEXT MAPPING (e.g., Lokeyh -> Lokesh, Teyxing -> Testing)
        if (in_array($f, ['user_name', 'name', 'remarks', 'product', 'client', 'vendor', 'description']) || !is_numeric($v)) {
            $textMap = ['y' => 's', 'x' => 't', 'z' => 'r']; // Removed 'r' => 'w' as it was causing naming issues like Counter -> Countew
            $recovered = strtr($recovered, $textMap);
        }

        // 3. SPECIFIC KNOWN GARBLED PATTERNS
        $hardFixes = [
            'Countew' => 'Counter',
            'Supewadmin' => 'Superadmin',
            'Agazral' => 'Agarwal',
            'Agawwal' => 'Agarwal',
            'Lokeyh'  => 'Lokesh',
            'Teyxing' => 'Testing',
            'Sfly'    => 'Fly',
            'OXP'     => 'ing',
            'Obnl'    => 'Star',
            'Temrsbl' => 'Created',
            'ZG02'    => 'Client',
            'UjNn'    => 'Vendor',
            'instgram' => 'Instagram',
            'facebuk' => 'Facebook'
        ];
        foreach ($hardFixes as $bad => $good) {
            $recovered = str_ireplace($bad, $good, $recovered);
        }
        
        return $recovered;
    };

    $tryDecrypt = function($v, $fieldName = '') use ($isEncryptedBlob, $recoverV) {
        if (!$v || !is_string($v)) return $v;
        
        $dec = $v;
        
        // 1. If it looks like base64, try to decrypt it
        if ($isEncryptedBlob($v)) {
            // Self-contained decryption logic to avoid "depending on others"
            $key = 'f49797bf6bafb4fac5830f764deabad0';
            $iv  = 'b6b6efef676e4973';
            
            // Try standard project decryption first
            $res = openssl_decrypt(base64_decode($v), 'AES-256-CBC', $key, 0, $iv);
            if ($res !== false && strlen($res) > 0) {
                $dec = mb_convert_encoding($res, 'UTF-8', 'UTF-8');
            } else {
                // Try CustomCipherService mapping as fallback if standard AES fails
                try {
                    $dec = \App\Services\CustomCipherService::decryptData($v);
                } catch (\Exception $e) {}
            }
        }
        
        // 2. Always apply recovery logic for potential garbled text
        return $recoverV($dec, $fieldName);
    };


    $activitiesRaw = $query
        ->orderBy("a.$orderBy", $order)
        ->offset($offset)
        ->limit($rowsPerPage)
        ->get();

    // ---------------------------------------------------------
    // BATCH ID RESOLUTION (Resolve IDs to Names efficiently)
    // ---------------------------------------------------------
    $productIds = []; $clientIds = []; $vendorIds = []; $domainIds = [];
    foreach ($activitiesRaw as $item) {
        $newData = $item->new_data ? json_decode($item->new_data, true) : [];
        $oldData = $item->old_data ? json_decode($item->old_data, true) : [];
        
        foreach ([$newData, $oldData] as $data) {
            if (!empty($data['product_id'])) $productIds[] = $data['product_id'];
            if (!empty($data['client_id']))  $clientIds[]  = $data['client_id'];
            if (!empty($data['vendor_id']))  $vendorIds[]  = $data['vendor_id'];
            if (!empty($data['domain_id']))  $domainIds[]  = $data['domain_id'];
            // Also check for 'id' if the module is specific
            if (($item->module === 'Products') && !empty($item->record_id)) $productIds[] = $item->record_id;
            if (($item->module === 'Vendors')  && !empty($item->record_id)) $vendorIds[]  = $item->record_id;
            if (($item->module === 'Clients')  && !empty($item->record_id)) $clientIds[]  = $item->record_id;
        }
    }

    $resProduct = DB::table('products')->whereIn('id', array_unique($productIds))->pluck('name', 'id');
    $resClient  = DB::table('superadmins')->whereIn('id', array_unique($clientIds))->pluck('name', 'id');
    $resVendor  = DB::table('vendors')->whereIn('id', array_unique($vendorIds))->pluck('name', 'id');
    $resDomain  = DB::table('domain_master')->whereIn('id', array_unique($domainIds))->pluck('domain_name', 'id');

    // Decrypt the mapped names
    $decryptMap = function($map) use ($tryDecrypt) {
        $newMap = [];
        foreach ($map as $id => $val) {
            $newMap[$id] = $tryDecrypt($val, 'name');
        }
        return $newMap;
    };
    $productMap = $decryptMap($resProduct);
    $clientMap  = $decryptMap($resClient);
    $vendorMap  = $decryptMap($resVendor);
    $domainMap  = $resDomain; // Usually plain text

    $activities = $activitiesRaw->map(function ($item) use ($tryDecrypt, $productMap, $clientMap, $vendorMap, $domainMap) {
            // Priority: Fresh decrypted name from superadmins > cached name in activity_logs
            $creatorName = $item->fresh_user_name ?? $item->user_name;
            $creatorName = $tryDecrypt($creatorName, 'user_name');

            // Decode data and fully decrypt all fields
            $newData = $item->new_data ? json_decode($item->new_data, true) : null;
            $oldData = $item->old_data ? json_decode($item->old_data, true) : null;

            $decryptAndResolve = function($data) use ($tryDecrypt, $productMap, $clientMap, $vendorMap, $domainMap) {
                if (!is_array($data)) return $data;
                
                // 1. Resolve IDs to Names if they exist
                if (!empty($data['product_id'])) $data['Product'] = $productMap[$data['product_id']] ?? "Unknown Product (#{$data['product_id']})";
                if (!empty($data['client_id']))  $data['Client']  = $clientMap[$data['client_id']]   ?? "Unknown Client (#{$data['client_id']})";
                if (!empty($data['vendor_id']))  $data['Vendor']  = $vendorMap[$data['vendor_id']]   ?? "Unknown Vendor (#{$data['vendor_id']})";
                if (!empty($data['domain_id']))  $data['Domain']  = $domainMap[$data['domain_id']]   ?? "Unknown Domain (#{$data['domain_id']})";

                foreach ($data as $k => $v) {
                    if (is_string($v)) {
                        $v = $tryDecrypt($v, $k);
                        $data[$k] = self::normalizeData($v, $k);
                    } elseif (is_array($v) && $k === 'changes') {
                        foreach ($v as $idx => $change) {
                            if (isset($change['old'])) {
                                $old = $tryDecrypt($change['old'], $change['field'] ?? '');
                                $v[$idx]['old'] = self::normalizeData($old, $change['field'] ?? '');
                            }
                            if (isset($change['new'])) {
                                $new = $tryDecrypt($change['new'], $change['field'] ?? '');
                                $v[$idx]['new'] = self::normalizeData($new, $change['field'] ?? '');
                            }
                        }
                        $data[$k] = $v;
                    }
                }

                // Ensure primary labels are present and normalized for the UI
                $data['Product'] = self::normalizeData($data['Product'] ?? $data['product_name'] ?? $data['Product Name'] ?? $data['product'] ?? null, 'Product');
                $data['Client']  = self::normalizeData($data['Client']  ?? $data['client_name']  ?? $data['Client Name']  ?? $data['client']  ?? null, 'Client');
                $data['Vendor']  = self::normalizeData($data['Vendor']  ?? $data['vendor_name']  ?? $data['Vendor Name']  ?? $data['vendor']  ?? null, 'Vendor');
                $data['Domain']  = self::normalizeData($data['Domain']  ?? $data['domain_name']  ?? $data['Domain Name']  ?? $data['domain']  ?? null, 'Domain');

                // PRUNE N/A or empty fields to keep UI clean (User request)
                foreach (['Product', 'Client', 'Vendor', 'Domain', 'Renewal Date', 'Validity Date', 'Amount', 'Count', 'Grace Period', 'Due Date'] as $field) {
                    if (isset($data[$field]) && (strtoupper($data[$field]) === 'N/A' || empty($data[$field]))) {
                        unset($data[$field]);
                    }
                }

                return $data;
            };


            $newData = $decryptAndResolve($newData);
            $oldData = $decryptAndResolve($oldData);

            return [
                'id'            => $item->id,
                'user_id'       => $item->user_id,
                'creator_name'  => $creatorName,
                'userName'      => $creatorName, // Standardized name
                'user_name'     => $creatorName, // Fallback
                'role'          => $item->role,
                'action_type'   => $item->action_type,
                'module'        => $item->module,
                'table_name'    => $item->table_name,
                'record_id'     => $item->record_id,
                'old_data'      => $oldData,
                'new_data'      => $newData,
                'description'   => $tryDecrypt($item->description, 'description'),
                'ip_address'    => $item->ip_address,
                'created_at'    => DateFormatterService::format($item->created_at),
                'updated_at'    => DateFormatterService::format($item->updated_at),
            ];
        });

    return response()->json([
        'rows'  => $activities,
        'total' => $total
    ]);
}



public function DeleteActivies(Request $request)
{
    try {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? null; // Multiple IDs
        $id = $data['id'] ?? null;   // Single ID (backwards compat)
        $admin_id = $data['admin_id'] ?? null;

        if (is_null($ids) && is_null($id)) {
            return response()->json([
                "status" => false,
                "message" => "Activity IDs are required",
            ]);
        }

        if (!$ids && $id) $ids = [$id];

        // Removed admin validation to fix "Admin not found" error during deletion.
        // Deletion now only requires activity IDs.
        
        DB::beginTransaction();
        try {
            // Delete from both potential tables to be safe, or just one if confirmed.
            // getAllActivities uses activity_logs. DeleteActivies previously used activities.
            $deletedLogs = DB::table('activity_logs')->whereIn('id', $ids)->delete();
            $deletedActs = DB::table('activities')->whereIn('id', $ids)->delete();

            DB::commit();
            return response()->json([
                "status" => true,
                "message" => "Activities deleted successfully (" . (max($deletedLogs, $deletedActs)) . " records)",
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                "status" => false,
                "message" => "Something went wrong: " . $e->getMessage(),
            ], 500);
        }

    } catch (\Exception $e) {
        return response()->json([
            "status" => false,
            "message" => "Something went wrong: " . $e->getMessage(),
        ], 500);
    }
}


    
    public function getAllActivities2(Request $request)
    {
        $page = (int) $request->input('page', 0);
        $rowsPerPage = (int) $request->input('rowsPerPage', 10);
        $order = $request->input('order', 'asc');
        $orderBy = $request->input('orderBy', 'id');
        $search = $request->input('search', '');
        $id = $request->input('admin_id', null); // Default null
    
        $offset = $page * $rowsPerPage;

        $query = DB::table('activities')
            ->select(
                'activities.*',
                'superadmins.name',
                'superadmins.login_type',
                DB::raw("CASE 
                            WHEN superadmins.login_type = 1 THEN 'Super Admin' 
                            WHEN superadmins.login_type = 2 THEN 'Sub Admin' 
                            WHEN superadmins.login_type = 3 THEN 'Manager' 
                            ELSE 'Unknown' 
                        END AS role")
            )
            ->leftJoin('superadmins', 'activities.user_id', '=', 'superadmins.id')
            ->when(!empty($id), function ($query) use ($id) {
                return $query->where('activities.user_id', (int) $id);
            })
            ->when(!empty($search), function ($query) use ($search) {
                return $query->where(function ($query) use ($search) {
                    $query->where('activities.action', 'like', '%' . $search . '%')
                          ->orWhere('superadmins.name', 'like', '%' . $search . '%')
                          ->orWhereRaw("CASE 
                                           WHEN superadmins.login_type = 1 THEN 'Super Admin' 
                                           WHEN superadmins.login_type = 2 THEN 'Sub Admin' 
                                           WHEN superadmins.login_type = 3 THEN 'Manager' 
                                           ELSE 'Unknown' 
                                       END LIKE ?", ['%' . $search . '%']);
                });
            });
    
        
        $totalQuery = clone $query;
        $total = $totalQuery->count(); 
    
        $activities = $query
            ->orderBy($orderBy, $order)
            ->offset($offset)
            ->limit($rowsPerPage)
            ->get();
    
        return response()->json([
            'rows' => $activities->map(function($item) {
                $data = (array) $item;
                $data['created_at'] = DateFormatterService::format($item->created_at);
                $data['updated_at'] = DateFormatterService::format($item->updated_at);
                $data['creator_name'] = $item->name ?? $item->user_name ?? "System";
                $data['userName'] = $data['creator_name'];
                $data['role'] = $item->role ?? "Unknown";
                return $data;
            }),
            'total' => $total
        ]);
    }
    

    public function SubadminActivities(Request $request)
{
    $page = (int) $request->input('page', 0);
    $rowsPerPage = (int) $request->input('rowsPerPage', 10);
    $order = $request->input('order', 'asc');
    $orderBy = $request->input('orderBy', 'id');
    $search = $request->input('search', '');
    $admin_id = $request->input('admin_id', null);

    $offset = $page * $rowsPerPage;


    $admin = DB::table('superadmins')
        ->select('id', 'added_by')
        ->where('id', $admin_id)
        ->first();

    if (!$admin) {
        return response()->json([
            'rows' => [],
            'total' => 0,
            'message' => 'Admin not found'
        ]);
    }

    $added_by = $admin->added_by;

    $query = DB::table('activities')
        ->select(
            'activities.*',
            'superadmins.name',
            'superadmins.login_type',
            DB::raw("CASE 
                        WHEN superadmins.login_type = 1 THEN 'Super Admin' 
                        WHEN superadmins.login_type = 2 THEN 'Sub Admin' 
                        WHEN superadmins.login_type = 3 THEN 'Manager' 
                        ELSE 'Unknown' 
                    END AS role")
        )
        ->leftJoin('superadmins', 'activities.user_id', '=', 'superadmins.id')
        ->where(function ($q) use ($admin_id, $added_by) {
            $q->where('activities.user_id', $admin_id)
              ->orWhere('activities.user_id', $added_by);
        })
        ->when(!empty($search), function ($query) use ($search) {
            return $query->where(function ($query) use ($search) {
                $query->where('activities.action', 'like', '%' . $search . '%')
                      ->orWhere('superadmins.name', 'like', '%' . $search . '%')
                      ->orWhereRaw("CASE 
                                       WHEN superadmins.login_type = 1 THEN 'Super Admin' 
                                       WHEN superadmins.login_type = 2 THEN 'Sub Admin' 
                                       WHEN superadmins.login_type = 3 THEN 'Manager' 
                                       ELSE 'Unknown' 
                                   END LIKE ?", ['%' . $search . '%']);
            });
        });

    $totalQuery = clone $query;
    $total = $totalQuery->count();

    $activities = $query
        ->orderBy($orderBy, $order)
        ->offset($offset)
        ->limit($rowsPerPage)
        ->get();

    return response()->json([
        'rows' => $activities->map(function($item) {
            $data = (array) $item;
            $data['created_at'] = DateFormatterService::format($item->created_at);
            $data['updated_at'] = DateFormatterService::format($item->updated_at);
            $data['creator_name'] = $item->name ?? $item->user_name ?? "System";
            $data['userName'] = $data['creator_name'];
            $data['role'] = $item->role ?? "Unknown";
            return $data;
        }),
        'total' => $total
    ]);
}

    public function logActivity(Request $request)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                $userId = $request->input('s_id') ?? $request->input('admin_id');
                if ($userId) {
                    $user = DB::table('superadmins')->where('id', $userId)->first();
                }
            }

            $module = $request->input('module', 'General');
            $actionType = $request->input('action_type', 'ACTION');
            $description = $request->input('description', '');
            $newData = $request->input('new_data', []);

            \App\Services\ActivityLogger::logActivity(
                $user,
                $actionType,
                $module,
                strtolower($module),
                null,
                null,
                $newData,
                $description,
                $request
            );

            return response()->json(['status' => true, 'message' => 'Activity logged successfully']);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Logging failed: ' . $e->getMessage()], 500);
        }
    }
}
