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

    $adminData = DB::table("superadmins")->where("id", $admin_id)->first();

    $offset = ($page - 1) * $rowsPerPage;

    $query = DB::table('activities as a')
        ->leftJoin('superadmins as sa', 'a.user_id', '=', 'sa.id')
        ->select(
            'a.*',
            'sa.name',
            'sa.login_type',
            DB::raw("CASE 
                WHEN sa.login_type = 1 THEN 'Superadmin' 
                WHEN sa.login_type = 2 THEN 'User' 
                WHEN sa.login_type = 3 THEN 'Client' 
                ELSE 'Unknown' 
            END AS role")
        );

    // Client login → sirf apni activities
    if (!empty($adminData) && $adminData->login_type == 3) {
        $query->where('a.user_id', $admin_id);
    }

    // =========================
    // SEARCH
    // =========================
    if (!empty($search)) {

        $encryptedSearch = CustomCipherService::encryptData($search);

        $query->where(function ($q) use ($search, $encryptedSearch) {
            $q->where('a.action', 'like', "%{$search}%")
              ->orWhere('sa.name', 'like', "%{$search}%")
              ->orWhereRaw("CASE 
                    WHEN sa.login_type = 1 THEN 'Superadmin' 
                    WHEN sa.login_type = 2 THEN 'User' 
                    WHEN sa.login_type = 3 THEN 'Client' 
                    ELSE 'Unknown' 
                END LIKE ?", ["%{$search}%"])
              ->orWhere('a.s_action', 'like', "%{$encryptedSearch}%")
              ->orWhere('a.s_message', 'like', "%{$encryptedSearch}%");
        });
    }

    $total = (clone $query)->count();

    // =========================
    // FETCH & FORMAT
    // =========================
    $activities = $query
        ->orderBy("a.$orderBy", $order)
        ->offset($offset)
        ->limit($rowsPerPage)
        ->get()
        ->map(function ($item) {

            $creatorName = null;
            try {
                $creatorName = $item->name
                    ? CryptService::decryptData($item->name)
                    : null;
            } catch (\Exception $e) {}

            return [
                'id'            => $item->id,
                'user_id'       => $item->user_id,
                'action'        => CryptService::decryptData($item->action),
                'message'       => CryptService::decryptData($item->message),
                'creator_name'  => $creatorName, // ✅ NEW KEY
                'login_type'    => $item->login_type,
                'role'          => $item->role,
                'created_at'    => Carbon::parse($item->created_at)->format('M d, Y h:i A'),
                'updated_at'    => $item->updated_at,
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
            'rows' => $activities,
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
        'rows' => $activities,
        'total' => $total
    ]);
}

    
    
}
