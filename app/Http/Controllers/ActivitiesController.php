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

use Carbon\Carbon;

class ActivitiesController extends Controller
{

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

    // Detect if a value looks like a base64/encrypted blob
    $isEncryptedBlob = function($v) {
        if (!is_string($v) || strlen($v) < 16) return false;
        return (bool) preg_match('/^[A-Za-z0-9+\/=]{16,}$/', $v) && !preg_match('/[\s\-\_\.\,\:\@\/]/', $v);
    };

    // Advanced recovery for corrupted plain text
    $recoverV = function($v, $fieldName = '') use ($isEncryptedBlob) {
        if (!$v || !is_string($v) || $isEncryptedBlob($v)) return $v;
        
        $f = strtolower($fieldName);
        
        // DATE RECOVERY (e.g., hphl-pl-hj -> 2026-03-24)
        if (str_contains($f, 'date') || preg_match('/^[a-z]{4}\-[a-z]{2}\-[a-z]{2}$/i', $v)) {
            $dateMap = ['h'=>'2', 'p'=>'0', 'i'=>'3', 'j'=>'4', 'k'=>'5', 'l'=>'6', 'm'=>'7', 'n'=>'8', 'g'=>'1', 'o'=>'9'];
            $recovered = strtr(strtolower($v), $dateMap);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $recovered)) return $recovered;
        }
        
        // NAME / REMARK RECOVERY (e.g., Lokeyh -> Lokesh, Teyxing -> Testing)
        if ($f === 'user_name' || $f === 'remarks' || str_contains($v, 'Agazral') || str_contains($v, 'Lokeyh') || str_contains($v, 'Teyxing')) {
            $textMap = ['y' => 's', 'x' => 't', 'z' => 'r', 'r' => 'w'];
            return strtr($v, $textMap);
        }
        
        return $v;
    };

    $tryDecrypt = function($v, $fieldName = '') use ($isEncryptedBlob, $recoverV) {
        if (!$v || !is_string($v)) return $v;
        
        // 1. If it looks like base64, decrypt it
        if ($isEncryptedBlob($v)) {
            try { $dec = CryptService::decryptData($v); if ($dec && $dec !== $v && strlen($dec) <= strlen($v)) return $dec; } catch (\Exception $e) {}
            try { $dec = \App\Services\CustomCipherService::decryptData($v); if ($dec && $dec !== $v && !preg_match('/^[A-Za-z0-9+\/=]{16,}$/', $dec)) return $dec; } catch (\Exception $e) {}
        }
        
        // 2. If it's plain but potentially garbled, recover it
        return $recoverV($v, $fieldName);
    };

    $activities = $query
        ->orderBy("a.$orderBy", $order)
        ->offset($offset)
        ->limit($rowsPerPage)
        ->get()
        ->map(function ($item) use ($tryDecrypt) {
            // Priority: Fresh decrypted name from superadmins > cached name in activity_logs
            $creatorName = $item->fresh_user_name ?? $item->user_name;
            $creatorName = $tryDecrypt($creatorName, 'user_name');

            // Decode new_data and clean it up
            $newData = $item->new_data ? json_decode($item->new_data, true) : null;
            if (is_array($newData)) {
                // Decrypt top-level name fields
                foreach (['client_name', 'product_name', 'vendor_name'] as $field) {
                    if (!empty($newData[$field])) {
                        $newData[$field] = $tryDecrypt($newData[$field], $field);
                    }
                }
                // Process changes array
                if (isset($newData['changes']) && is_array($newData['changes'])) {
                    $cleanChanges = [];
                    foreach ($newData['changes'] as $change) {
                        $fName = $change['field'] ?? '';
                        if (isset($change['old'])) $change['old'] = $tryDecrypt($change['old'], $fName);
                        if (isset($change['new'])) $change['new'] = $tryDecrypt($change['new'], $fName);
                        $cleanChanges[] = $change;
                    }
                    $newData['changes'] = $cleanChanges;
                }
            }

            return [
                'id'            => $item->id,
                'user_id'       => $item->user_id,
                'creator_name'  => $creatorName,
                'role'          => $item->role,
                'action_type'   => $item->action_type,
                'module'        => $item->module,
                'table_name'    => $item->table_name,
                'record_id'     => $item->record_id,
                'old_data'      => $item->old_data ? json_decode($item->old_data, true) : null,
                'new_data'      => $newData,
                'description'   => $item->description,
                'ip_address'    => $item->ip_address,
                'created_at'    => Carbon::parse($item->created_at)->format('j/n/Y, g:i:s a'),
                'updated_at'    => Carbon::parse($item->updated_at)->format('j/n/Y, g:i:s a'),
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
        $id = $data['id'] ?? null; // Activity ID to delete
        $admin_id = $data['admin_id'] ?? null; // Admin performing the action

        if (is_null($id)) {
            return response()->json([
                "status" => false,
                "message" => "Activity ID is required",
            ]);
        }

        if (is_null($admin_id)) {
            return response()->json([
                "status" => false,
                "message" => "Admin ID is required",
            ]);
        }

        $admin = DB::table('superadmins')->where('id', $admin_id)->first();

        if (!$admin) {
            return response()->json([
                "status" => false,
                "message" => "Admin not found",
            ]);
        }

        DB::beginTransaction();

        try {
            $deleted = DB::table('activities')->where('id', $id)->delete();

            if ($deleted) {
                DB::commit();
                return response()->json([
                    "status" => true,
                    "message" => "Activity deleted successfully",
                ]);
            } else {
                DB::rollBack();
                return response()->json([
                    "status" => false,
                    "message" => "No matching activity found",
                ]);
            }

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
                $data = $item->toArray();
                $data['created_at'] = Carbon::parse($item->created_at)->format('j/n/Y, g:i:s a');
                $data['updated_at'] = Carbon::parse($item->updated_at)->format('j/n/Y, g:i:s a');
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
            $data = $item->toArray();
            $data['created_at'] = Carbon::parse($item->created_at)->format('j/n/Y, g:i:s a');
            $data['updated_at'] = Carbon::parse($item->updated_at)->format('j/n/Y, g:i:s a');
            return $data;
        }),
        'total' => $total
    ]);
}

    
    
}
