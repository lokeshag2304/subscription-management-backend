<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\SubscriptionImport;
use App\Services\ActivityLogger;
use App\Services\ClientScopeService;
use App\Services\DateFormatterService;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        $limit  = $request->query('limit', 100);
        $offset = $request->query('offset', 0);
        $search = $request->query('search', '');

        $query = Subscription::select([
            'id', 'product_id', 'client_id', 'vendor_id', 
            'amount', 'renewal_date', 'deletion_date', 'status', 
            'remarks', 'created_at', 'updated_at'
        ])
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
            
            $pIds = \App\Models\Product::pluck('name', 'id')
                ->filter(function($name) use ($searchLow) {
                    $dec = \App\Services\CryptService::decryptData($name);
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
            $today = now()->startOfDay();

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
                'renewal_date' => $sub->renewal_date,
                'deletion_date' => $sub->deletion_date,
                'days_left' => $daysLeft,
                'days_to_delete' => $daysToDelete,
                'status' => $sub->status,
                'remarks' => \App\Services\CryptService::decryptData(\App\Services\CryptService::decryptData($sub->remarks)),
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
                    ? $today->diffInDays(\Carbon\Carbon::parse($request->renewal_date)->startOfDay(), false)
                    : null;
                $computedDaysToDelete = $request->deletion_date
                    ? $today->diffInDays(\Carbon\Carbon::parse($request->deletion_date)->startOfDay(), false)
                    : null;

                $subscription = Subscription::create([
                    'product_id' => $request->product_id,
                    'client_id' => $request->client_id,
                    'vendor_id' => $request->vendor_id,
                    'amount' => $request->amount,
                    'renewal_date' => $request->renewal_date,
                    'deletion_date' => $request->deletion_date,
                    'days_left' => $computedDaysLeft,
                    'days_to_delete' => $computedDaysToDelete,
                    'status' => $request->status ?? 1,
                    'remarks' => \App\Services\CryptService::encryptData($request->remarks)
                ]);

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

            if (isset($data['remarks'])) {
                // Track before encrypting
                \App\Services\RemarkHistoryService::trackChange('Subscription', $record->id, $record->remarks, $data['remarks']);
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

            $oldData = $record->toArray();
            $record->update($data);
            $record->refresh()->load(['product', 'client', 'vendor'])->loadCount('remarkHistories');
            
            $pName = optional($record->product)->name;
            $cName = optional($record->client)->name;
            $vName = optional($record->vendor)->name;
            try { $pName = \App\Services\CryptService::decryptData($pName) ?? $pName; } catch (\Exception $e) {}
            try { $cName = \App\Services\CryptService::decryptData($cName) ?? $cName; } catch (\Exception $e) {}
            try { $vName = \App\Services\CryptService::decryptData($vName) ?? $vName; } catch (\Exception $e) {}

            $resp = $record->toArray();
            $resp['remarks'] = \App\Services\CryptService::decryptData($record->remarks) ?? $record->remarks;
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

            ActivityLogger::logActivity(
                auth()->user() ?? (object)['id' => $request->input('s_id') ?? null],
                'UPDATE',
                'Subscription',
                'subscriptions',
                $record->id,
                $oldData,
                $record->toArray(),
                "Subscription updated",
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
