<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use App\Lib\SMSInteg;
use App\Lib\WhatsappInteg;
use App\Lib\EmailInteg;
use App\Services\CryptService;



use Carbon\Carbon;

class StatusController extends Controller
{

    public function __construct()
    {
        // $this->middleware('cors');
    }
public function statusList(Request $request)
{
    $postData = $request->all();

    $draw = $postData["draw"] ?? 1;
    $start = $postData["start"] ?? 0;
    $rowperpage = $postData["length"] ?? 10;

    $columnIndex = $postData["order"][0]["column"] ?? 0;
    $columnName = $postData["columns"][$columnIndex]["data"] ?? "name";
    $columnSortOrder = $postData["order"][0]["dir"] ?? "asc";

    $searchValue = $postData["search"]["value"] ?? "";
    $subadminId = $postData["subadmin_id"] ?? null;

    $totalRecords = DB::table("status")
        ->when($subadminId, function ($query) use ($subadminId) {
            $query->where("subadmin_id", $subadminId);
        })
        ->count();

    $filteredQuery = DB::table("status")
        ->when($subadminId, function ($query) use ($subadminId) {
            $query->where("subadmin_id", $subadminId);
        })
        ->when(!empty($searchValue), function ($query) use ($searchValue) {
            $query->where("name", "like", "%" . $searchValue . "%");
        });

    $totalFiltered = $filteredQuery->count();

    $records = DB::table("status")
        ->select("id", "name", "status", "order", "subadmin_id")
        ->when($subadminId, function ($query) use ($subadminId) {
            $query->where("subadmin_id", $subadminId);
        })
        ->when(!empty($searchValue), function ($query) use ($searchValue) {
            $query->where("name", "like", "%" . $searchValue . "%");
        })
        ->orderBy("order", "asc") 
        ->orderBy($columnName, $columnSortOrder)
        ->offset($start)
        ->limit($rowperpage)
        ->get();

    $data = $records->map(function ($row) {
        return [
            "id" => $row->id,
            "name" => $row->name,
            "status" => $row->status,
            "order" => $row->order,
            "subadmin_id" => $row->subadmin_id,
        ];
    });

    return response()->json([
        "draw" => intval($draw),
        "iTotalRecords" => $totalRecords,
        "iTotalDisplayRecords" => $totalFiltered,
        "aaData" => $data,
    ]);
}




public function Status_Add(Request $request)
{
    $request->validate([
        'name' => 'required|string|max:255',
        'status' => 'required|integer',
        'admin_id' => 'required|integer',
        'subadmin_id' => 'required|integer'
    ]);

    $name = $request->name;
    $status = $request->status;
    $subadminId = $request->subadmin_id;

    // Check if same name already exists for this subadmin_id
    $exists = DB::table('status')
        ->where('name', $name)
        ->where('subadmin_id', $subadminId)
        ->exists();

    if ($exists) {
        return response()->json([
            'status' => false,
            'message' => 'This status name already exists for the selected Subadmin.',
        ], 200);
    }

    // Insert into status table
    $insertedId = DB::table('status')->insertGetId([
        'name' => $name,
        'status' => $status,
        'subadmin_id' => $subadminId
    ]);

    // Encrypt activity logs
    $action = CryptService::encryptData("Add Status");
    $message = CryptService::encryptData("Added new status: $name");
    $details = CryptService::encryptData(json_encode([
        'name' => $name,
        'status' => $status,
        'subadmin_id' => $subadminId
    ]));

    DB::table('activities')->insert([
        'action' => $action,
        'user_id' => $request->admin_id,
        'message' => $message,
        'details' => $details,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json([
        'status' => true,
        'message' => 'Status added successfully.',
        'id' => $insertedId,
    ]);
}



public function Status_Update(Request $request)
{
    $request->validate([
        'id' => 'required|integer|exists:status,id',
        'name' => 'required|string|max:255',
        'status' => 'required|integer',
        'admin_id' => 'required|integer',
        'subadmin_id' => 'required|integer'
    ]);

    $id = (int) $request->id;
    $name = trim($request->name);
    $status = (int) $request->status;
    $subadminId = (int) $request->subadmin_id;

    // Duplicate check within same subadmin_id
    $duplicate = DB::table('status')
        ->where('name', $name)
        ->where('subadmin_id', $subadminId)
        ->where('id', '!=', $id)
        ->exists();

    if ($duplicate) {
        return response()->json([
            'status' => false,
            'message' => 'Another status with the same name already exists for this Subadmin.',
        ], 200);
    }

    $oldStatus = DB::table('status')->where('id', $id)->first();

    // Update the record
    DB::table('status')->where('id', $id)->update([
        'name' => $name,
        'status' => $status,
        'subadmin_id' => $subadminId
    ]);

    $action = CryptService::encryptData("Update Status");

    $changes = [];
    if ($oldStatus->name !== $name) {
        $changes[] = "Name changed from '{$oldStatus->name}' to '{$name}'";
    }
    if ((int)$oldStatus->status !== $status) {
        $changes[] = "Status changed from '{$oldStatus->status}' to '{$status}'";
    }

    $message = CryptService::encryptData(
        count($changes) > 0 ? implode(", ", $changes) : "No actual changes"
    );

    $details = CryptService::encryptData(json_encode([
        'id' => $id,
        'name' => $name,
        'status' => $status,
        'subadmin_id' => $subadminId
    ]));

    DB::table('activities')->insert([
        'action' => $action,
        'user_id' => (int)$request->admin_id,
        'message' => $message,
        'details' => $details,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json([
        'status' => true,
        'message' => 'Status updated successfully.',
    ]);
}


public function DeleteStatus(Request $request)
{
    try {
        $data = json_decode($request->getContent(), true);
        $id = $data['id'] ?? null;
        $admin_id = $data['admin_id'] ?? null;

        if (is_null($id)) {
            return response()->json([
                "status" => false,
                "message" => "Status ID is required",
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
                "message" => "Superadmin not found",
            ]);
        }

        $status = DB::table('status')->where('id', $id)->first();
        if (!$status) {
            return response()->json([
                "status" => false,
                "message" => "Status not found",
            ]);
        }

        DB::beginTransaction();

        try {
            $deleted = DB::table('status')->where('id', $id)->delete();

            if ($deleted) {
                // Prepare encrypted log details
                $action = CryptService::encryptData("Deleted Status");
                $message = CryptService::encryptData("Status '{$status->name}' deleted successfully");
                $details = CryptService::encryptData(json_encode([
                    'status_id' => $id,
                    'status_name' => $status->name,
                    'admin_id' => $admin_id,
                ]));

                DB::table('activities')->insert([
                    'action' => $action,
                    'user_id' => $admin_id,
                    'message' => $message,
                    'details' => $details,
                    'created_at' => Carbon::now()->setTimezone('Asia/Kolkata'),
                    'updated_at' => Carbon::now()->setTimezone('Asia/Kolkata'),
                ]);

                DB::commit();

                return response()->json([
                    "status" => true,
                    "message" => "Status deleted successfully",
                ]);
            } else {
                DB::rollBack();
                return response()->json([
                    "status" => false,
                    "message" => "Failed to delete status",
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

public function updateOrder(Request $request)
{
    $data = json_decode($request->getContent(), true);

    if (!isset($data['orders']) || !is_array($data['orders'])) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid data format. Expected array of orders with id and order.',
        ], 400);
    }

    foreach ($data['orders'] as $item) {
        if (isset($item['id']) && isset($item['order'])) {
            DB::table('status')
                ->where('id', $item['id'])
                ->update(['order' => $item['order']]);
        }
    }

    return response()->json([
        'success' => true,
        'message' => 'Order updated successfully.',
    ]);
}
    
}