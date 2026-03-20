<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\Superadmin;
use App\Services\ActivityLogger;
use App\Services\ClientScopeService;
use App\Services\CryptService;
use App\Services\DateFormatterService;
use App\Services\GracePeriodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\SubscriptionImport;

class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        $limit  = $request->query('limit', 100);
        $offset = $request->query('offset', 0);
        $search = $request->query('search', '');

        // Detect if the currency column exists (safe for pre-migration environments)
        static $hasCurrencyCache = null;
        if ($hasCurrencyCache === null) {
            $hasCurrencyCache = Schema::hasColumn('subscriptions', 'currency');
        }

        $selectFields = [
            'id', 'product_id', 'client_id', 'vendor_id',
            'amount', 'renewal_date', 'deletion_date', 'days_to_delete',
            'grace_period', 'due_date', 'status',
            'remarks', 'created_at', 'updated_at'
        ];
        if ($hasCurrencyCache) {
            array_splice($selectFields, 4, 0, ['currency']);
        }

        $query = Subscription::select($selectFields)
        ->with([
            'product:id,name', 
            'client:id,name', 
            'vendor:id,name'
        ])
        ->withCount('remarkHistories');

        // ── CLIENT SCOPE: filter to only this client's records ──
        ClientScopeService::applyScope($query, $request);

        if (!empty($search)) {
            $searchLow = strtolower($search);
            
            $pIds = Product::pluck('name', 'id')
                ->filter(function($name) use ($searchLow) {
                    $dec = CryptService::decryptData($name);
                    return str_contains(strtolower($dec ?? $name), $searchLow);
                })->keys();

            $cIds = \App\Models\Superadmin::pluck('name', 'id')
                ->filter(function($name) use ($searchLow) {
                    $dec = \App\Services\CryptService::decryptData($name);
                    return str_contains(strtolower($dec ?? $name), $searchLow);
                })->keys();

            $vIds = \App\Models\Vendor::pluck('name', 'id')
                ->filter(function($name) use ($searchLow) {
                    $dec = \App\Services\CryptService::decryptData($name);
                    return str_contains(strtolower($dec ?? $name), $searchLow);
                })->keys();

            $query->where(function($q) use ($pIds, $cIds, $vIds) {
                $q->whereIn('product_id', $pIds)
                  ->orWhereIn('client_id', $cIds)
                  ->orWhereIn('vendor_id', $vIds);
            });
        }

        $total = $query->count();
        $query->orderBy('created_at', 'desc')->skip($offset)->take($limit);

        $subscriptions = $query->get();

        $subscriptions = $subscriptions->map(function ($sub) {
            // Calculate days without hitting DB if possible
            $today = now()->startOfDay();

            // Apply Grace Period logic in memory
            // We AVOID saving in the index loop to prevent performance timeouts
            $renewalDate = $sub->renewal_date;
            $res = \App\Services\GracePeriodService::calculate($renewalDate, $sub->grace_period ?? 0);
            
            // Only update model in memory
            $sub->due_date = $res['due_date'];
            if ($res['should_be_inactive']) {
                $sub->status = 0;
            }

            $daysLeft = $sub->renewal_date
                ? $today->diffInDays(\Illuminate\Support\Carbon::parse($sub->renewal_date)->startOfDay(), false)
                : null;

            $daysToDelete = $sub->deletion_date
                ? $today->diffInDays(\Illuminate\Support\Carbon::parse($sub->deletion_date)->startOfDay(), false)
                : null;

            $pName = optional($sub->product)->name;
            $cName = optional($sub->client)->name;
            $vName = optional($sub->vendor)->name;

            try { $pName = \App\Services\CryptService::decryptData($pName) ?? $pName; } catch (\Exception $e) {}
            try { $cName = \App\Services\CryptService::decryptData($cName) ?? $cName; } catch (\Exception $e) {}
            try { $vName = \App\Services\CryptService::decryptData($vName) ?? $vName; } catch (\Exception $e) {}

            return [
                'id' => $sub->id,
                'product' => $pName,
                'product_name' => $pName,
                'client' => $cName,
                'client_name' => $cName,
                'vendor_name' => $vName,
                'product_id' => $sub->product_id,
                'client_id' => $sub->client_id,
                'vendor_id' => $sub->vendor_id,
                'amount' => (float) $sub->amount,
                'currency' => $sub->currency ?? 'INR',
                'renewal_date' => $sub->renewal_date,
                'deletion_date' => $sub->deletion_date,
                'days_left' => $daysLeft,
                'days_to_delete' => $daysToDelete,
                'grace_period' => $sub->grace_period ?? 0,
                'due_date' => $sub->due_date,
                'status' => $sub->status,
                'remarks' => CryptService::decryptData($sub->remarks),
                'has_remark_history' => $sub->remark_histories_count > 0,
                'last_updated' => DateFormatterService::format($sub->updated_at),
                'updated_at_formatted' => DateFormatterService::format($sub->updated_at),
                'created_at_formatted' => DateFormatterService::format($sub->created_at),
                'updated_at' => $sub->updated_at,
                'created_at' => $sub->created_at,
            ];
        });

        return response()->json([
            'status' => true,
            'success' => true,
            'data' => $subscriptions,
            'total' => $total
        ]);
    }

    public function store(Request $request)
    {
        try {
            // If logged-in user is a Client, override client_id with their own ID
            ClientScopeService::enforceClientId($request);

            // Frontend dynamically sends these as objects sometimes. Normalize.
            $request->merge([
               'product_id' => $request->product_id ?? data_get($request->product, 'value'),
               'client_id'  => $request->client_id  ?? data_get($request->client, 'value'),
               'vendor_id'  => $request->vendor_id  ?? data_get($request->vendor, 'value'),
            ]);
            
            if ($request->renewal_date) {
               $request->merge(['renewal_date' => \Carbon\Carbon::parse($request->renewal_date)->format('Y-m-d')]);
            }
            if ($request->deletion_date) {
               $request->merge(['deletion_date' => \Carbon\Carbon::parse($request->deletion_date)->format('Y-m-d')]);
            }

            $request->validate([
                'product_id' => 'required|exists:products,id',
                'client_id'  => 'required|exists:superadmins,id',
                'vendor_id'  => 'nullable|exists:vendors,id',
                'amount'     => 'required|numeric|min:0',
                'renewal_date' => 'required|date'
            ]);

            try {
                // Compute days_left and days_to_delete server-side
                $today = now()->startOfDay();
                $computedDaysLeft = $request->renewal_date
                    ? $today->diffInDays(Carbon::parse($request->renewal_date)->startOfDay(), false)
                    : null;
                $computedDaysToDelete = $request->deletion_date
                    ? $today->diffInDays(Carbon::parse($request->deletion_date)->startOfDay(), false)
                    : null;

                $hasCurrency = Schema::hasColumn('subscriptions', 'currency');
                $hasGracePeriod = Schema::hasColumn('subscriptions', 'grace_period');

                $subscription = Subscription::create([
                    'product_id' => $request->product_id,
                    'client_id' => $request->client_id,
                    'vendor_id' => $request->vendor_id,
                    'amount' => $request->amount,
                    ...($hasCurrency ? ['currency' => $request->input('currency', 'INR')] : []),
                    'renewal_date' => $request->renewal_date,
                    'deletion_date' => $request->deletion_date,
                    'days_left' => $computedDaysLeft,
                    'days_to_delete' => $computedDaysToDelete,
                    'grace_period' => $hasGracePeriod ? ($request->grace_period ?? 0) : 0,
                    'status' => $request->status ?? 1,
                    'remarks' => \App\Services\CryptService::encryptData($request->remarks)
                ]);

                if ($hasGracePeriod) {
                    \App\Services\GracePeriodService::syncModel($subscription);
                    $subscription->save();
                }

                $pName = optional($subscription->product)->name;
                $cName = optional($subscription->client)->name;
                $vName = optional($subscription->vendor)->name;
                try { $pName = \App\Services\CryptService::decryptData($pName) ?? $pName; } catch (\Exception $e) {}
                try { $cName = \App\Services\CryptService::decryptData($cName) ?? $cName; } catch (\Exception $e) {}
                try { $vName = \App\Services\CryptService::decryptData($vName) ?? $vName; } catch (\Exception $e) {}

                $data = $subscription->toArray();
                $data['product_name'] = $pName;
                $data['client_name']  = $cName;
                $data['vendor_name']  = $vName;
                $data['remarks'] = \App\Services\CryptService::decryptData($subscription->remarks);
                $data['has_remark_history'] = false;
                $data['last_updated'] = DateFormatterService::format($subscription->updated_at);
                $data['updated_at_formatted'] = DateFormatterService::format($subscription->updated_at);
                $data['created_at_formatted'] = DateFormatterService::format($subscription->created_at);
                // Ensure computed values are in the response (toArray() should have them but be explicit)
                $data['days_left']     = $computedDaysLeft;
                $data['days_to_delete'] = $computedDaysToDelete;
                $data['currency']      = $subscription->currency ?? 'INR';
                ActivityLogger::logActivity(
                    auth()->user() ?? (object)['id' => $request->input('s_id') ?? null],
                    'CREATE',
                    'Subscription',
                    'subscriptions',
                    $subscription->id,
                    null,
                    $subscription->toArray(),
                    "Subscription created",
                    $request
                );

                // AUTOMATIC COUNTER SYNC
                \App\Services\CounterSyncService::sync($subscription->client_id, $subscription->product_id, $subscription->vendor_id);

                return response()->json([
                    'status' => true,
                    'success' => true,
                    'data' => $data
                ], 201);
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
            }
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $record = Subscription::find($id);
            if (!$record) return response()->json(['success' => false, 'message' => 'Not found'], 404);

            // ── OWNERSHIP GUARD ──
            ClientScopeService::assertOwnership($record, $request);

            $data = $request->all();
            foreach ($data as $key => $value) {
                if ($value === '') $data[$key] = null;
            }

            // Normalize object values (sometimes frontend sends objects for dropdowns)
            foreach (['product_id', 'client_id', 'vendor_id'] as $field) {
                if (isset($data[$field]) && is_array($data[$field])) {
                    $data[$field] = $data[$field]['value'] ?? $data[$field]['id'] ?? null;
                }
            }

            if (!empty($data['renewal_date'])) {
                 try { $data['renewal_date'] = \Illuminate\Support\Carbon::parse($data['renewal_date'])->format('Y-m-d'); } catch (\Exception $e) {}
            }
            if (!empty($data['deletion_date'])) {
                 try { $data['deletion_date'] = \Illuminate\Support\Carbon::parse($data['deletion_date'])->format('Y-m-d'); } catch (\Exception $e) {}
            }

            // Log changes before update
            \App\Services\RemarkHistoryService::logUpdate('Subscription', $record, $data);

            if (isset($data['remarks'])) {
                $data['remarks'] = \App\Services\CryptService::encryptData($data['remarks']);
            }

            // Compute and persist days_left / days_to_delete
            $today = now()->startOfDay();
            $renewalDate = !empty($data['renewal_date']) ? $data['renewal_date'] : $record->renewal_date;
            $deletionDate = !empty($data['deletion_date']) ? $data['deletion_date'] : $record->deletion_date;
            $data['days_left'] = $renewalDate
                ? $today->diffInDays(\Illuminate\Support\Carbon::parse($renewalDate)->startOfDay(), false)
                : null;
            $data['days_to_delete'] = $deletionDate
                ? $today->diffInDays(\Illuminate\Support\Carbon::parse($deletionDate)->startOfDay(), false)
                : null;

            // Guard: remove currency from payload if the column doesn't exist yet (pre-migration safety)
            $hasCurrency = \Illuminate\Support\Facades\Schema::hasColumn('subscriptions', 'currency');
            if (!$hasCurrency) {
                unset($data['currency']);
            }

            $oldData = $record->toArray();
            $record->update($data);

            if (\Illuminate\Support\Facades\Schema::hasColumn('subscriptions', 'grace_period')) {
                \App\Services\GracePeriodService::syncModel($record);
                $record->save();
            }
            
            $record->refresh()->load(['product', 'client', 'vendor'])->loadCount('remarkHistories');
            
            $pName = optional($record->product)->name;
            $cName = optional($record->client)->name;
            $vName = optional($record->vendor)->name;
            try { $pName = \App\Services\CryptService::decryptData($pName) ?? $pName; } catch (\Exception $e) {}
            try { $cName = \App\Services\CryptService::decryptData($cName) ?? $cName; } catch (\Exception $e) {}
            try { $vName = \App\Services\CryptService::decryptData($vName) ?? $vName; } catch (\Exception $e) {}

            $resp = $record->toArray();
            $resp['remarks'] = \App\Services\CryptService::decryptData($record->remarks);
            $resp['product_name'] = $pName;
            $resp['client_name']  = $cName;
            $resp['vendor_name']  = $vName;
            $resp['has_remark_history'] = $record->remark_histories_count > 0;
            $resp['last_updated'] = DateFormatterService::format($record->updated_at);
            $resp['updated_at_formatted'] = DateFormatterService::format($record->updated_at);
            $resp['created_at_formatted'] = DateFormatterService::format($record->created_at);
            $resp['status'] = $record->status;

            // Compute days_left and days_to_delete dynamically
            $today = now()->startOfDay();
            $resp['days_left'] = $record->renewal_date
                ? $today->diffInDays(\Illuminate\Support\Carbon::parse($record->renewal_date)->startOfDay(), false)
                : null;
            $resp['days_to_delete'] = $record->deletion_date
                ? $today->diffInDays(\Illuminate\Support\Carbon::parse($record->deletion_date)->startOfDay(), false)
                : null;

            // ── Build activity log description with field-level change details ──
            $changedLines = [];

            // CURRENCY — read from request directly (not $data, which may have been unset pre-migration)
            $newCurrency = $request->input('currency');
            $oldCurrency = $oldData['currency'] ?? 'INR';
            if ($newCurrency !== null && $hasCurrency && (string)$oldCurrency !== (string)$newCurrency) {
                $changedLines[] = "Currency: {$oldCurrency} → {$newCurrency}";
            }

            // AMOUNT
            if (isset($data['amount']) && (float)($oldData['amount'] ?? 0) !== (float)$data['amount']) {
                $changedLines[] = "Amount: " . ($oldData['amount'] ?? '0') . " → " . $data['amount'];
            }

            // RENEWAL DATE
            if (!empty($data['renewal_date']) && ($oldData['renewal_date'] ?? '') !== $data['renewal_date']) {
                $changedLines[] = "Renewal Date: " . ($oldData['renewal_date'] ?? 'N/A') . " → " . $data['renewal_date'];
            }

            // VENDOR
            if (!empty($data['vendor_id']) && (string)($oldData['vendor_id'] ?? '') !== (string)$data['vendor_id']) {
                $vOld = optional(\App\Models\Vendor::find($oldData['vendor_id']))->name;
                $vNew = optional(\App\Models\Vendor::find($data['vendor_id']))->name;
                try { $vOld = \App\Services\CryptService::decryptData($vOld) ?? $vOld; } catch (\Exception $e) {}
                try { $vNew = \App\Services\CryptService::decryptData($vNew) ?? $vNew; } catch (\Exception $e) {}
                $changedLines[] = "Vendor: " . ($vOld ?? $oldData['vendor_id']) . " → " . ($vNew ?? $data['vendor_id']);
            }

            // PRODUCT
            if (!empty($data['product_id']) && (string)($oldData['product_id'] ?? '') !== (string)$data['product_id']) {
                $pOld = optional(\App\Models\Product::find($oldData['product_id']))->name;
                $pNew = optional(\App\Models\Product::find($data['product_id']))->name;
                try { $pOld = \App\Services\CryptService::decryptData($pOld) ?? $pOld; } catch (\Exception $e) {}
                try { $pNew = \App\Services\CryptService::decryptData($pNew) ?? $pNew; } catch (\Exception $e) {}
                $changedLines[] = "Product: " . ($pOld ?? $oldData['product_id']) . " → " . ($pNew ?? $data['product_id']);
            }

            // STATUS
            if (isset($data['status']) && (string)($oldData['status'] ?? '') !== (string)$data['status']) {
                $statusLabel = fn($s) => $s == 1 ? 'Active' : 'Inactive';
                $changedLines[] = "Status: " . $statusLabel($oldData['status']) . " → " . $statusLabel($data['status']);
            }

            $activityDescription = count($changedLines) > 0
                ? implode("\n", $changedLines)
                : "Subscription updated";

            ActivityLogger::logActivity(
                auth()->user() ?? (object)['id' => $request->input('s_id') ?? null],
                'UPDATE',
                'Subscription',
                'subscriptions',
                $record->id,
                $oldData,
                $record->toArray(),
                $activityDescription,
                $request
            );

            // AUTOMATIC COUNTER SYNC (for both old and new to handle transfers)
            \App\Services\CounterSyncService::sync($oldData['client_id'], $oldData['product_id'], $oldData['vendor_id']);
            \App\Services\CounterSyncService::sync($record->client_id, $record->product_id, $record->vendor_id);

            return response()->json([
                'status' => true,
                'success' => true,
                'message' => 'Subscription updated successfully',
                'data'    => $resp
            ]);
        } catch (\Throwable $e) {
             return response()->json(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }

    public function import(\Illuminate\Http\Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json([
                'success'    => false,
                'message'    => 'File not received'
            ], 400);
        }

        try {
            $clientId = $request->input('client_id') ?? null;
            // SubscriptionImport uses 'superadmins' for client resolution
            $importer = new \App\Imports\SubscriptionImport($clientId);
            $result = \App\Services\ImportService::handleImport($request, $importer, 'Subscription');
            
            $history           = $result['history'];
            $importer          = $result['importer'];
            $duplicateFile     = $result['duplicate_file'] ?? null;
            $duplicateFileUrl  = $result['duplicate_file_url'] ?? null;

            $inserted   = $importer->inserted;
            $duplicates = $importer->duplicates;
            $failed     = $importer->failed;
            $errors     = $importer->errors;

            // Fetch latest records (newest first) to return to frontend
            $latest = $inserted > 0
                ? Subscription::with(['product', 'client', 'vendor'])
                    ->orderBy('id', 'desc')
                    ->take($inserted)
                    ->get()
                    ->map(function ($sub) {
                        $pName = optional($sub->product)->name;
                        $cName = optional($sub->client)->name;
                        $vName = optional($sub->vendor)->name;
                        try { $pName = \App\Services\CryptService::decryptData($pName) ?? $pName; } catch (\Exception $e) {}
                        try { $cName = \App\Services\CryptService::decryptData($cName) ?? $cName; } catch (\Exception $e) {}
                        try { $vName = \App\Services\CryptService::decryptData($vName) ?? $vName; } catch (\Exception $e) {}
                        $sub->product_name = $pName;
                        $sub->client_name  = $cName;
                        $sub->vendor_name  = $vName;
                        $sub->product_name_decrypted = $pName; // Extra safety
                        return $sub;
                    })
                    ->reverse()
                    ->values()
                : collect();

            $msg = "$inserted record(s) imported.";
            if ($duplicates > 0) $msg .= " $duplicates duplicate(s) skipped.";
            if ($failed > 0)     $msg .= " $failed row(s) failed.";

            if ($inserted > 0) {
                ActivityLogger::imported(null, 'Subscription', $inserted);
                
                // AUTOMATIC COUNTER SYNC for bulk imports
                foreach ($latest as $sub) {
                    \App\Services\CounterSyncService::sync($sub->client_id, $sub->product_id, $sub->vendor_id);
                }
            }

            return response()->json([
                'status'              => true,
                'success'             => true,
                'message'             => $msg,
                'inserted'            => $inserted,
                'duplicate'           => $duplicates,
                'duplicates'          => $duplicates,
                'failed'              => $failed,
                'errors'              => $errors,
                'inserted_data'       => $latest,
                'history'             => $history,
                'duplicate_file'      => $duplicateFile,
                'duplicate_file_url'  => $duplicateFileUrl,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $record = Subscription::find($id);
        if (!$record) return response()->json(['success' => false, 'message' => 'Not found'], 404);

        // ── OWNERSHIP GUARD ──
        ClientScopeService::assertOwnership($record, $request);

        ActivityLogger::logActivity(
            auth()->user() ?? (object)['id' => $request->input('s_id') ?? null],
            'DELETE',
            'Subscription',
            'subscriptions',
            $record->id,
            $record->toArray(),
            null,
            $request
        );
        $oldClient = $record->client_id;
        $oldProduct = $record->product_id;
        $oldVendor = $record->vendor_id;

        $record->delete();

        // AUTOMATIC COUNTER SYNC after deletion
        \App\Services\CounterSyncService::sync($oldClient, $oldProduct, $oldVendor);
        return response()->json([
            'status' => true,
            'success' => true, 
            'message' => 'Subscription deleted successfully'
        ]);
    }
    public function updateRemark(Request $request)
    {
        $id = $request->input('id') ?? $request->input('subscription_id');
        $newRemark = $request->input('remarks') ?? $request->input('remark') ?? '';

        try {
            // 1) Fetch existing subscription by ID
            $record = Subscription::findOrFail($id);
            
            // ── OWNERSHIP GUARD ──
            ClientScopeService::assertOwnership($record, $request);

            $oldRemark = $record->remarks;

            // 2) Start transaction
            \Illuminate\Support\Facades\DB::beginTransaction();

            // 3) If old_remark exists (we track if it's different and not null/empty out of thin air)
            if (!empty($oldRemark) && $oldRemark !== $newRemark) {
                $userName = 'System / Admin';
                if (auth()->check()) {
                    $userName = auth()->user()->name ?? ('User ID: ' . auth()->id());
                    try { $dec = \App\Services\CryptService::decryptData($userName); if($dec) $userName = $dec; } catch (\Exception $e) {}
                }

                \Illuminate\Support\Facades\DB::table('remark_histories')->insert([
                    'module'     => 'Subscription',
                    'record_id'  => $record->id,
                    'remark'     => $oldRemark,
                    'user_name'  => $userName,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // 4) Then update subscription table
            $record->remarks = \App\Services\CryptService::encryptData($newRemark);
            $record->updated_at = now();
            $record->save();

            // 5) Commit transaction
            \Illuminate\Support\Facades\DB::commit();

            $record->refresh()->load(['product', 'client', 'vendor'])->loadCount('remarkHistories');
            
            $pName = optional($record->product)->name;
            $cName = optional($record->client)->name;
            $vName = optional($record->vendor)->name;
            try { $pName = \App\Services\CryptService::decryptData($pName) ?? $pName; } catch (\Exception $e) {}
            try { $cName = \App\Services\CryptService::decryptData($cName) ?? $cName; } catch (\Exception $e) {}
            try { $vName = \App\Services\CryptService::decryptData($vName) ?? $vName; } catch (\Exception $e) {}

            $record->product_name = $pName;
            $record->client_name  = $cName;
            $record->vendor_name  = $vName;
            $record->has_remark_history = $record->remark_histories_count > 0;

            // 6) Return structured response
            return response()->json([
                'success' => true,
                'message' => 'Remark updated successfully',
                'data'    => $record
            ]);

        } catch (\Throwable $e) {
            // 7) On failure
            \Illuminate\Support\Facades\DB::rollBack();
            \Illuminate\Support\Facades\Log::error("Failed to update subscription remark: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update subscription'
            ], 500);
        }
    }

    public function getRemarkHistory($id)
    {
        try {
            $history = \Illuminate\Support\Facades\DB::table('remark_histories')
                ->where('module', 'Subscription')
                ->where('record_id', $id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $history
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch remark history'
            ], 500);
        }
    }
}
