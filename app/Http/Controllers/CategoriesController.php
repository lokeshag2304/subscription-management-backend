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
use Carbon\Carbon;
use App\Services\CryptService;
use App\Services\CustomCipherService;



class CategoriesController extends Controller
{

public function listCategoryRecords(Request $request)
{
    try {

        $recordType   = $request->input('record_type');
        $search       = $request->input('search');
        $orderBy      = $request->input('orderBy', 'id');
        $orderDir     = $request->input('orderDir', 'desc');

        $page         = (int) $request->input('page', 0);
        $rowsPerPage  = (int) $request->input('rowsPerPage', 10);
        $offset       = $page * $rowsPerPage;

        $today = \Carbon\Carbon::today();
        $s_id = $request->input('s_id');

        $user = null;
        if ($s_id) {
            $user = DB::table('superadmins')->where('id', $s_id)->first();
        }

        $clientDomainIds = [];
        if ($user && $user->login_type == 3 && !empty($user->domain_id)) {
            $clientDomainIds = json_decode($user->domain_id, true) ?? [];
        }

        // ======================================
        // BASE QUERY (+ vendor join added)
        // ======================================
        $query = DB::table('categories as c')
            ->leftJoin('superadmins as sa', 'sa.id', '=', 'c.client_id')
            ->leftJoin('domain as d', 'd.id', '=', 'c.domain_id')
            ->leftJoin('products as p', 'p.id', '=', 'c.product_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'c.vendor_id') // ✅ NEW
            ->select(
                'c.*',
                'sa.name as client_name_enc',
                'd.name as domain_name_enc',
                'p.name as product_name_enc',
                'v.name as vendor_name_enc' // ✅ NEW
            );

        // ======================================
        // CLIENT RESTRICTION
        // ======================================
        if ($user && $user->login_type == 3) {
            if (!empty($clientDomainIds)) {
                $query->whereIn('c.domain_id', $clientDomainIds);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (!empty($recordType)) {
            $query->where('c.record_type', $recordType);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('sa.name', 'like', "%$search%")
                  ->orWhere('d.name', 'like', "%$search%")
                  ->orWhere('p.name', 'like', "%$search%")
                  ->orWhere('v.name', 'like', "%$search%"); // ✅ vendor search
            });
        }

        $total = (clone $query)->count();

        $rows = $query
            ->orderBy("c.$orderBy", $orderDir)
            ->offset($offset)
            ->limit($rowsPerPage)
            ->get();

        // ======================================
        // FETCH LATEST REMARKS
        // ======================================
        $categoryIds = $rows->pluck('id')->toArray();

        $latestRemarks = DB::table('category_remarks')
            ->whereIn('cat_id', $categoryIds)
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('cat_id');

        $data = [];

        foreach ($rows as $row) {

            $clientName  = null;
            $domainName  = null;
            $productName = null;
            $vendorName  = null;

            try { $clientName  = $row->client_name_enc  ? CryptService::decryptData($row->client_name_enc)  : null; } catch (\Exception $e) {}
            try { $domainName  = $row->domain_name_enc  ? CryptService::decryptData($row->domain_name_enc)  : null; } catch (\Exception $e) {}
            try { $productName = $row->product_name_enc ? CryptService::decryptData($row->product_name_enc) : null; } catch (\Exception $e) {}
            try { $vendorName  = $row->vendor_name_enc  ? CryptService::decryptData($row->vendor_name_enc)  : null; } catch (\Exception $e) {}

            $daysToExpired = null;

            if (!empty($row->expiry_date)) {
                $expiry = \Carbon\Carbon::parse($row->expiry_date);

                if ($expiry->gte($today)) {
                    $daysToExpired = $today->diffInDays($expiry);
                } else {
                    $daysToExpired = -$expiry->diffInDays($today);
                }
            }

            $row->updated_at = \Carbon\Carbon::parse($row->updated_at)
                ->format('d M Y, h:i A');

            // ======================================
            // LATEST REMARK
            // ======================================
            $latestRemark = null;
            if (isset($latestRemarks[$row->id]) && $latestRemarks[$row->id]->isNotEmpty()) {
                $r = $latestRemarks[$row->id]->first();
                $latestRemark = [
                    'id'     => $r->id,
                    'remark' => CryptService::decryptData($r->remark),
                ];
            }

            // ======================================
            // FINAL RESPONSE FIELDS
            // ======================================
            $row->client_name  = $clientName;
            $row->domain_name  = $domainName;
            $row->product_name = $productName;
            $row->vendor_name  = $vendorName; // ✅ NEW

            $row->days_to_expired = $daysToExpired;
            $row->today_date = $today->toDateString();
            $row->latest_remark = $latestRemark;

            unset(
                $row->client_name_enc,
                $row->domain_name_enc,
                $row->product_name_enc,
                $row->vendor_name_enc
            );

            $data[] = $row;
        }

        return response()->json([
            'status' => true,
            'total' => $total,
            'page' => $page,
            'rowsPerPage' => $rowsPerPage,
            'data' => $data
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}



public function SearchResult(Request $request)
{
    try {

        $typeMap = [
            1 => 'Subscriptions',
            2 => 'Hosting',
            3 => 'Hosting',
            4 => 'Domains',
            5 => 'Emails',
            6 => 'Counter'
        ];

        $search       = $request->input('search');
        $orderBy      = $request->input('orderBy', 'id');
        $orderDir     = $request->input('orderDir', 'desc');

        $page         = (int) $request->input('page', 0);
        $rowsPerPage  = (int) $request->input('rowsPerPage', 10);
        $offset       = $page * $rowsPerPage;

        $today = \Carbon\Carbon::today();

        $s_id = $request->input('s_id');

        $user = null;
        if ($s_id) {
            $user = DB::table('superadmins')->where('id', $s_id)->first();
        }

        $clientDomainIds = [];
        if ($user && $user->login_type == 3 && !empty($user->domain_id)) {
            $clientDomainIds = json_decode($user->domain_id, true) ?? [];
        }

        $query = DB::table('categories as c')
            ->leftJoin('domain as d', 'd.id', '=', 'c.domain_id')
            ->leftJoin('products as p', 'p.id', '=', 'c.product_id')
            ->select(
                'c.*',
                'd.name as domain_name_enc',
                'p.name as product_name_enc'
            );

        if ($user && $user->login_type == 3) {
            if (!empty($clientDomainIds)) {
                $query->whereIn('c.domain_id', $clientDomainIds);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (!empty($search)) {

            $domainIds = [];

            $domains = DB::table('domain')->get();
            foreach ($domains as $d) {
                try {
                    $decName = CryptService::decryptData($d->name);
                    if (stripos($decName, $search) !== false) {
                        $domainIds[] = $d->id;
                    }
                } catch (\Exception $e) {}
            }

            if (!empty($domainIds)) {
                $query->whereIn('c.domain_id', $domainIds);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $total = (clone $query)->count();

        $rows = $query
            ->orderBy("c.$orderBy", $orderDir)
            ->offset($offset)
            ->limit($rowsPerPage)
            ->get();

        $data = [];

        foreach ($rows as $row) {

            $domainName = null;
            $productName = null;

            try { $domainName = $row->domain_name_enc ? CryptService::decryptData($row->domain_name_enc) : null; } catch (\Exception $e) {}
            try { $productName = $row->product_name_enc ? CryptService::decryptData($row->product_name_enc) : null; } catch (\Exception $e) {}

            $daysToExpired = null;

            if (!empty($row->expiry_date)) {
                $expiry = \Carbon\Carbon::parse($row->expiry_date);

                if ($expiry->gte($today)) {
                    $daysToExpired = $today->diffInDays($expiry);
                } else {
                    $daysToExpired = -$expiry->diffInDays($today);
                }
            }

            $statusText = $row->status == 1 ? 'Active' : 'Deactive';

            $data[] = [
                'id' => $row->id,
                'record_type_name' => $typeMap[$row->record_type] ?? 'Unknown',
                'expiry_date' => $row->expiry_date,
                'days_to_expired' => $daysToExpired,
                'today_date' => $today->toDateString(),
                'domain_name' => $domainName,
                'product_name' => $productName,
                'status' => $statusText
            ];
        }

        return response()->json([
            'status' => true,
            'total' => $total,
            'page' => $page,
            'rowsPerPage' => $rowsPerPage,
            'data' => $data
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}


    
public function addCategoryRecord(Request $request)
{
    try {

        $validator = Validator::make($request->all(), [
            'record_type' => 'required|integer',
            'client_id'   => 'nullable|integer',
            'domain_id'   => 'nullable|integer',
            'product_id'  => 'nullable|integer',
            's_id'        => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        // =======================
        // Record type mapping
        // =======================
        $typeMap = [
            1 => 'Subscriptions',
            2 => 'SSL',
            3 => 'Hosting',
            4 => 'Domains',
            5 => 'Emails',
            6 => 'Counter'
        ];


        $typeLabel = $typeMap[$request->record_type] ?? 'Category';

        // =======================
        // Status & Expiry logic
        // =======================
       // =======================
        // Status & Expiry logic (FIXED)
        // =======================
        $statusValue = $request->has('status')
            ? (int) $request->status
            : 1; // default active

        $daysToExpire = null;

        if (!empty($request->expiry_date)) {

            $today  = \Carbon\Carbon::today();
            $expiry = \Carbon\Carbon::parse($request->expiry_date);

            if ($expiry->lt($today)) {
                $statusValue = 0; // deactive
                $daysToExpire = 0;
            } else {
                // agar status explicitly nahi bheja
                if (!$request->has('status')) {
                    $statusValue = 1;
                }
                $daysToExpire = $today->diffInDays($expiry);
            }
        }


        // =======================
        // Insert into categories
        // =======================
        $categoryId = DB::table('categories')->insertGetId([

            'record_type' => $request->record_type,
            'client_id'   => $request->client_id,
            'domain_id'   => $request->domain_id,
            'product_id'  => $request->product_id,
            'vendor_id'  => $request->vendor_id,


            'renewal_date' => $request->renewal_date,
            'amount'      => $request->amount,

            'next_recurring_date' => $request->next_recurring_date,

            'expiry_date' => $request->expiry_date,
            'days_to_expire_today' => $daysToExpire,

            'domain_protected' => $request->domain_protected,

            'quantity'   => $request->quantity,
            'bill_type'  => $request->bill_type,
            'start_date' => $request->start_date,
            'deleted_at' => $request->deleted_at,

            'end_date'   => $request->end_date,

            'status'     => $statusValue,

            'counter_count' => $request->counter_count,
            'valid_till'    => $request->valid_till,
            'updated_at_custom' => now(),

            'created_at' => now(),
            'updated_at' => now(),
        ]);

          if (!empty($request->remarks)) {
            DB::table('category_remarks')->insert([
                's_id'       => $request->s_id,
                'cat_id'     => $categoryId,
                'remark'     => $request->remarks,
                'created_at' => now()
            ]);
        }

        // =======================
        // Fetch names for activity
        // =======================
        $clientName = null;
        $domainName = null;
        $productName = null;

        if ($request->client_id) {
            $c = DB::table('superadmins')->where('id', $request->client_id)->first();
            if ($c) {
                try { $clientName = CryptService::decryptData($c->name); }
                catch (\Exception $e) { $clientName = $c->name; }
            }
        }

        if ($request->domain_id) {
            $d = DB::table('domain')->where('id', $request->domain_id)->first();
            if ($d) {
                try { $domainName = CryptService::decryptData($d->name); }
                catch (\Exception $e) { $domainName = $d->name; }
            }
        }

        if ($request->product_id) {
            $p = DB::table('products')->where('id', $request->product_id)->first();
            if ($p) {
                try { $productName = CryptService::decryptData($p->name); }
                catch (\Exception $e) { $productName = $p->name; }
            }
        }

        // =======================
        // Activity message
        // =======================
        $activityMessage = "{$typeLabel} record added";

        if ($clientName)  $activityMessage .= ", Client: {$clientName}";
        if ($domainName)  $activityMessage .= ", Domain: {$domainName}";
        if ($productName) $activityMessage .= ", Product: {$productName}";

        // =======================
        // Activity log insert
        // =======================
        DB::table('activities')->insert([
            'action'     => CryptService::encryptData("{$typeLabel} Record Added"),
            's_action'   => CustomCipherService::encryptData("{$typeLabel} Record Added"),
            'user_id'    => $request->s_id,
            'cat_id' => $categoryId,
            'message'    => CryptService::encryptData($activityMessage),
            's_message'  => CustomCipherService::encryptData($activityMessage),
            'details'    => CryptService::encryptData(json_encode([
                'category_id' => $categoryId,
                'record_type' => $request->record_type,
                'client_id'   => $request->client_id,
                'domain_id'   => $request->domain_id,
                'product_id'  => $request->product_id,
                'status'      => $statusValue
            ])),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // =======================
        // Response
        // =======================
        return response()->json([
            'status' => true,
            'message' => "{$typeLabel} record added successfully",
            'id' => $categoryId,
            'record_status' => $statusValue,
            'days_to_expire_today' => $daysToExpire
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}

public function updateCategoryRecord(Request $request)
{
    try {

        $validator = Validator::make($request->all(), [
            'id'          => 'required|integer',
            'record_type' => 'required|integer',
            'client_id'   => 'nullable|integer',
            'domain_id'   => 'nullable|integer',
            'product_id'  => 'nullable|integer',
            's_id'        => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first()
            ], 422);
        }

        $oldRecord = DB::table('categories')->where('id', $request->id)->first();

        if (!$oldRecord) {
            return response()->json([
                'status' => false,
                'message' => 'Record not found'
            ], 404);
        }

        $typeMap = [
            1 => 'Subscriptions',
            2 => 'SSL',
            3 => 'Hosting',
            4 => 'Domains',
            5 => 'Emails',
            6 => 'Counter'
        ];

        $typeLabel = $typeMap[$request->record_type] ?? 'Category';

        $statusValue = $request->has('status')
            ? (int)$request->status
            : $oldRecord->status;

        $daysToExpire = null;
        if (!empty($request->expiry_date)) {
            $today  = \Carbon\Carbon::today();
            $expiry = \Carbon\Carbon::parse($request->expiry_date);
            $daysToExpire = $expiry->lt($today) ? 0 : $today->diffInDays($expiry);
        }

        /*
        |--------------------------------------------------
        | UPDATE CATEGORY
        |--------------------------------------------------
        */
        $newData = [
            'record_type' => $request->record_type,
            'client_id'   => $request->client_id,
            'domain_id'   => $request->domain_id,
            'product_id'  => $request->product_id,
            'vendor_id'   => $request->vendor_id,
            'renewal_date' => $request->renewal_date,
            'amount' => $request->amount,
            'next_recurring_date' => $request->next_recurring_date,
            'expiry_date' => $request->expiry_date,
            'days_to_expire_today' => $daysToExpire,
            'domain_protected' => $request->domain_protected,
            'quantity' => $request->quantity,
            'bill_type' => $request->bill_type,
            'start_date' => $request->start_date,
            'deleted_at' => $request->deleted_at,
            'end_date' => $request->end_date,
            'status' => $statusValue,
            'counter_count' => $request->counter_count,
            'valid_till' => $request->valid_till,
            'updated_at_custom' => now(),
            'updated_at' => now()
        ];

        DB::table('categories')->where('id', $request->id)->update($newData);

        /*
        |--------------------------------------------------
        | 🔥 REMARK LOGIC (FIXED)
        |--------------------------------------------------
        */
        $remarkChangeLog = null;

        if (!empty($request->remarks)) {

            // latest old remark
            $lastRemark = DB::table('category_remarks')
                ->where('cat_id', $request->id)
                ->orderBy('created_at', 'desc')
                ->first();

            $oldRemark = '';
            if ($lastRemark) {
                try {
                    $oldRemark = CryptService::decryptData($lastRemark->remark);
                } catch (\Exception $e) {}
            }

            $newRemark = trim($request->remarks);

            // ✅ only if remark changed
            if ($newRemark !== $oldRemark) {

                DB::table('category_remarks')->insert([
                    'cat_id'     => $request->id,
                    'remark'     => CryptService::encryptData($newRemark),
                    'created_at' => now(),
                ]);

                $remarkChangeLog = "REMARK : OLD -> {$oldRemark} , NEW -> {$newRemark}";
            }
        }

        /*
        |--------------------------------------------------
        | FIELD CHANGE LOG
        |--------------------------------------------------
        */
        $changes = [];

        foreach ($newData as $field => $newValue) {
            if (!property_exists($oldRecord, $field)) continue;

            $oldValue = $oldRecord->$field;

            if ($oldValue != $newValue) {

                if ($field === 'status') {
                    $oldText = $oldValue == 1 ? 'Active' : 'Deactive';
                    $newText = $newValue == 1 ? 'Active' : 'Deactive';
                } else {
                    $oldText = (string)$oldValue;
                    $newText = (string)$newValue;
                }

                $changes[] = strtoupper($field) . " : OLD -> {$oldText} , NEW -> {$newText}";
            }
        }

        if ($remarkChangeLog) {
            $changes[] = $remarkChangeLog;
        }

        $changeMessage = empty($changes)
            ? 'No changes made'
            : implode(' | ', $changes);

        $activityMessage = "{$typeLabel} record updated. {$changeMessage}";

        /*
        |--------------------------------------------------
        | ACTIVITY LOG
        |--------------------------------------------------
        */
        DB::table('activities')->insert([
            'action'     => CryptService::encryptData("{$typeLabel} Record Updated"),
            's_action'   => CustomCipherService::encryptData("{$typeLabel} Record Updated"),
            'user_id'    => $request->s_id,
            'cat_id'     => $request->id,
            'message'    => CryptService::encryptData($activityMessage),
            's_message'  => CustomCipherService::encryptData($activityMessage),
            'details'    => CryptService::encryptData(json_encode([
                'category_id' => $request->id,
                'record_type' => $request->record_type,
                'status' => $statusValue
            ])),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => "{$typeLabel} record updated successfully",
            'id' => $request->id,
            'record_status' => $statusValue,
            'days_to_expire_today' => $daysToExpire
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
}



public function changeActiveDeactiveMultiple(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'id' => 'required|array|min:1',
            'id.*' => 'integer|exists:superadmins,id',
            'status' => 'required|in:0,1',
            'added_by' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => $validator->errors()->first(),
            ], 200);
        }

        $subAdmins = DB::table('superadmins')
            ->whereIn('id', $request->id)
            ->get();

        if ($subAdmins->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No matching users found.',
            ], 404);
        }

        DB::table('superadmins')
            ->whereIn('id', $request->id)
            ->update([
                'status' => $request->status,
                'updated_at' => Carbon::now(),
            ]);

        $statusText = $request->status == 1 ? 'Activated' : 'Deactivated';

        foreach ($subAdmins as $user) {
            $decryptedName = CryptService::decryptData($user->name);
            $decryptedEmail = CryptService::decryptData($user->email);
            $loginType = $user->login_type;

            $typeLabel = $loginType == 2 ? 'Agent' : ($loginType == 3 ? 'Customer' : 'User');
            $action = "$statusText $typeLabel";
            $message = "$statusText $typeLabel with email: $decryptedEmail";

            DB::table('activities')->insert([
                'action' => CryptService::encryptData($action),
                's_action' => CustomCipherService::encryptData($action),

                'user_id' => $request->added_by,
                'message' => CryptService::encryptData($message),
                's_message' => CustomCipherService::encryptData($message),

                'details' => CryptService::encryptData(json_encode([
                    'superadmin_id' => $user->id,
                    'status' => $request->status,
                ])),
                'created_at' => Carbon::now()->setTimezone('Asia/Kolkata'),
                'updated_at' => Carbon::now()->setTimezone('Asia/Kolkata'),
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => "{$typeLabel}s $statusText successfully",
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Something went wrong: ' . $e->getMessage(),
        ], 500);
    }
}

public function deleteCategories(Request $request)
{
    $data = json_decode($request->getContent(), true);

    $categoryIds = $data['ids'] ?? [];
    $deletedBy   = $data['s_id'] ?? null;
    $recordType  = $data['record_type'] ?? null;

    if (empty($categoryIds) || !$deletedBy || !$recordType || !is_array($categoryIds)) {
        return response()->json([
            'success' => false,
            'message' => 'ids (array), record_type and s_id are required',
        ], 422);
    }

    // Record type map
    $recordTypeMap = [
        1 => 'Subscriptions',
        2 => 'SSL',
        3 => 'Hosting',
        4 => 'Domains',
        5 => 'Emails',
        6 => 'Counter',
    ];

    $recordTypeName = $recordTypeMap[$recordType] ?? 'Unknown';

    // Fetch categories
    $categories = DB::table('categories')
        ->whereIn('id', $categoryIds)
        ->get();

    if ($categories->isEmpty()) {
        return response()->json([
            'success' => false,
            'message' => 'No categories found',
        ], 404);
    }

    // Decrypt names


    // Delete records
    DB::table('categories')
        ->whereIn('id', $categoryIds)
        ->delete();

    // Activity message
    $activityMessage = "Categories deleted from {$recordTypeName}: ";

    DB::table('activities')->insert([
        'action'     => CryptService::encryptData("Categories Deleted"),
        's_action'   => CustomCipherService::encryptData("Categories Deleted"),
        'user_id'    => $deletedBy,
        'message'    => CryptService::encryptData($activityMessage),
        's_message'  => CustomCipherService::encryptData($activityMessage),
        'details'    => CryptService::encryptData(json_encode([
            'category_ids' => $categoryIds,
            'record_type'  => $recordType,
            'record_name'  => $recordTypeName,
        ])),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json([
        'status' => true,
        'message' => "Categories deleted successfully from {$recordTypeName}.",
        'record_type' => $recordTypeName
    ]);
}

public function getCategoryRemarksAndActivities(Request $request)
{
    try {

        $cat_id = $request->input('cat_id');

        if (empty($cat_id)) {
            return response()->json([
                'status' => false,
                'message' => 'cat_id is required'
            ], 422);
        }

        $today = \Carbon\Carbon::today();

        /* =========================
           FETCH CATEGORY
        ========================= */
        $row = DB::table('categories as c')
            ->leftJoin('superadmins as sa', 'sa.id', '=', 'c.client_id')
            ->leftJoin('domain as d', 'd.id', '=', 'c.domain_id')
            ->leftJoin('products as p', 'p.id', '=', 'c.product_id')
            ->where('c.id', $cat_id)
            ->select(
                'c.*',
                'sa.name as client_name_enc',
                'd.name as domain_name_enc',
                'p.name as product_name_enc'
            )
            ->first();

        if (!$row) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found'
            ], 404);
        }

        /* =========================
           DECRYPT NAMES
        ========================= */
        $clientName  = null;
        $domainName  = null;
        $productName = null;

        try { $clientName  = $row->client_name_enc  ? CryptService::decryptData($row->client_name_enc)  : null; } catch (\Exception $e) {}
        try { $domainName  = $row->domain_name_enc  ? CryptService::decryptData($row->domain_name_enc)  : null; } catch (\Exception $e) {}
        try { $productName = $row->product_name_enc ? CryptService::decryptData($row->product_name_enc) : null; } catch (\Exception $e) {}

        /* =========================
           EXPIRY
        ========================= */
        $daysToExpired = null;
        if (!empty($row->expiry_date)) {
            $expiry = \Carbon\Carbon::parse($row->expiry_date);
            $daysToExpired = $expiry->gte($today)
                ? $today->diffInDays($expiry)
                : -$expiry->diffInDays($today);
        }

        /* =========================
           CATEGORY DATA
        ========================= */
        $categoryData = [
            'id'              => $row->id,
            'record_type'     => $row->record_type,
            'client_name'     => $clientName,
            'domain_name'     => $domainName,
            'product_name'    => $productName,
            'expiry_date'     => $row->expiry_date,
            'days_to_expired' => $daysToExpired,
            'valid_till'      => $row->valid_till,
            'today_date'      => $today->toDateString(),
            'created_at'      => \Carbon\Carbon::parse($row->created_at)->format('d M Y, h:i A'),
            'updated_at'      => \Carbon\Carbon::parse($row->updated_at)->format('d M Y, h:i A'),
        ];

        /* =========================
           FETCH REMARKS (WITH CREATOR)
        ========================= */
        $remarks = DB::table('category_remarks as cr')
            ->leftJoin('superadmins as sa', 'sa.id', '=', 'cr.s_id')
            ->where('cr.cat_id', $cat_id)
            ->orderBy('cr.created_at', 'desc')
            ->select(
                'cr.id',
                'cr.remark',
                'cr.created_at',
                'sa.name as creator_name_enc'
            )
            ->get();

        $remarksData = [];
        foreach ($remarks as $r) {

            $creatorName = null;
            try {
                $creatorName = $r->creator_name_enc
                    ? CryptService::decryptData($r->creator_name_enc)
                    : null;
            } catch (\Exception $e) {}

            $remarksData[] = [
                'id'           => $r->id,
                'remark'       => CryptService::decryptData($r->remark),
                'creator_name' => $creatorName,
                'created_at'   => \Carbon\Carbon::parse($r->created_at)
                                    ->format('d M Y, h:i A')
            ];
        }

        /* =========================
           FETCH ACTIVITIES
        ========================= */
        $activities = DB::table('activities as a')
            ->leftJoin('superadmins as sa', 'sa.id', '=', 'a.user_id')
            ->where('a.cat_id', $cat_id)
            ->orderBy('a.created_at', 'desc')
            ->select(
                'a.id',
                'a.action',
                'a.message',
                'a.created_at',
                'sa.name as creator_name_enc'
            )
            ->get();

        $activitiesData = [];
        foreach ($activities as $a) {

            $creatorName = null;
            try {
                $creatorName = $a->creator_name_enc
                    ? CryptService::decryptData($a->creator_name_enc)
                    : null;
            } catch (\Exception $e) {}

            $activitiesData[] = [
                'id'           => $a->id,
                'action'       => CryptService::decryptData($a->action),
                'message'      => CryptService::decryptData($a->message),
                'creator_name' => $creatorName,
                'created_at'   => \Carbon\Carbon::parse($a->created_at)
                                    ->format('d M Y, h:i A')
            ];
        }

        /* =========================
           FINAL RESPONSE
        ========================= */
        return response()->json([
            'status'     => true,
            'category'   => $categoryData,
            'remarks'    => $remarksData,
            'activities' => $activitiesData
        ]);

    } catch (\Exception $e) {

        \Log::error('getCategoryRemarksAndActivities error', [
            'error' => $e->getMessage()
        ]);

        return response()->json([
            'status'  => false,
            'message' => 'Something went wrong',
            'error'   => $e->getMessage()
        ], 500);
    }
}




}
