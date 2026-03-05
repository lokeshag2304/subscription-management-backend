<?php

namespace App\Http\Controllers;

use App\Models\Counter;
use App\Models\Activity;
use App\Models\ImportExportHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Services\ActivityLogger;
use App\Services\ClientScopeService;
use App\Services\DateFormatterService;

class CounterController extends Controller
{
    protected array $productIds = [47];

    private function formatDate($date)
    {
        if (empty($date)) return null;
        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function calculateFields(&$data)
    {
        $today = now()->startOfDay();
        
        if (!empty($data['renewal_date'])) {
            $renewal = Carbon::parse($data['renewal_date'])->startOfDay();
            $data['days_left'] = $today->diffInDays($renewal, false);
        } else {
            $data['days_left'] = null;
        }

        if (!empty($data['deletion_date'])) {
            $deletion = Carbon::parse($data['deletion_date'])->startOfDay();
            $data['days_to_delete'] = $today->diffInDays($deletion, false);
        } else {
            $data['days_to_delete'] = null;
        }
    }

    private function logActivity($action, $record)
    {
        try {
            $label = ucfirst($action);
            $clientName = $record->client_name ?? ($record->client->name ?? 'N/A');
            $details = "Client: {$clientName} | Renewal: " . ($record->renewal_date ?? '-');
            ActivityLogger::log(null, "Counter {$label}", $details, 'Counter');
        } catch (\Exception $e) {}
    }

    public function index(Request $request)
    {
        $limit  = $request->query('limit', 100);
        $offset = $request->query('offset', 0);
        $search = $request->query('search', '');

        $query = Counter::select([
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
            // Search inside Products, Vendors, Clients once (these are small tables)
            $pIds = \App\Models\Product::all()->filter(function($p) use ($searchLow) {
                $dec = \App\Services\CryptService::decryptData($p->name);
                return str_contains(strtolower($dec ?? $p->name), $searchLow);
            })->pluck('id');

            $cIds = \App\Models\Superadmin::all()->filter(function($c) use ($searchLow) {
                $dec = \App\Services\CryptService::decryptData($c->name);
                return str_contains(strtolower($dec ?? $c->name), $searchLow);
            })->pluck('id');

            $vIds = \App\Models\Vendor::all()->filter(function($v) use ($searchLow) {
                $dec = \App\Services\CryptService::decryptData($v->name);
                return str_contains(strtolower($dec ?? $v->name), $searchLow);
            })->pluck('id');

            $query->where(function($q) use ($pIds, $cIds, $vIds) {
                $q->whereIn('product_id', $pIds)
                  ->orWhereIn('client_id', $cIds)
                  ->orWhereIn('vendor_id', $vIds);
            });
        }

        $total = $query->count();
        $query->orderBy('created_at', 'desc')->skip($offset)->take($limit);

        $records = $query->get()->map(function ($item) {
                $today = now()->startOfDay();
                $item->days_left = $item->renewal_date ? $today->diffInDays(\Illuminate\Support\Carbon::parse($item->renewal_date)->startOfDay(), false) : null;
                $item->days_to_delete = $item->deletion_date ? $today->diffInDays(\Illuminate\Support\Carbon::parse($item->deletion_date)->startOfDay(), false) : null;
                
                $item->client_name = optional($item->client)->name;
                $item->product_name = optional($item->product)->name;
                $item->vendor_name = optional($item->vendor)->name;

                try { $item->client_name = \App\Services\CryptService::decryptData($item->client_name) ?? $item->client_name; } catch (\Exception $e) {}
                try { $item->product_name = \App\Services\CryptService::decryptData($item->product_name) ?? $item->product_name; } catch (\Exception $e) {}
                try { $item->vendor_name = \App\Services\CryptService::decryptData($item->vendor_name) ?? $item->vendor_name; } catch (\Exception $e) {}

                $item->has_remark_history = $item->remark_histories_count > 0;
                try {
                    $dec = \App\Services\CryptService::decryptData($item->remarks);
                    $item->remarks = \App\Services\CryptService::decryptData($dec);
                } catch (\Exception $e) {}

                $formattedUpdated = Carbon::parse($item->updated_at)->format('j/n/Y, g:i:s a');
                $formattedCreated = Carbon::parse($item->created_at ?? $item->updated_at)->format('j/n/Y, g:i:s a');

                $data = $item->toArray();
                $data['days_left'] = $item->days_left;
                $data['days_to_delete'] = $item->days_to_delete;
                $data['client_name'] = $item->client_name;
                $data['product_name'] = $item->product_name;
                $data['vendor_name'] = $item->vendor_name;
                $data['has_remark_history'] = $item->has_remark_history;
                $data['remarks'] = $item->remarks;

                $data['last_updated'] = DateFormatterService::format($item->updated_at);
                $data['updated_at_formatted'] = DateFormatterService::format($item->updated_at);
                $data['created_at_formatted'] = DateFormatterService::format($item->created_at ?? $item->updated_at);
                $data['counter_count'] = $item->amount;
                $data['valid_till']    = $item->renewal_date;
                $data['expiry_date']   = $item->renewal_date; // fallback
                // updated_at / created_at kept as raw ISO from toArray() — do NOT overwrite
                
                return $data;
            });

        return response()->json([
            'success' => true,
            'data' => $records,
            'total' => $total
        ]);
    }

    public function store(Request $request)
    {
        try {
            // ── CLIENT SCOPE: force client_id from JWT if client ──
            ClientScopeService::enforceClientId($request);

            // STEP 2 — STANDARDIZE REQUEST INPUT
            $request->merge([
               'product_id' => $request->product_id ?? data_get($request->product, 'value'),
               'client_id'  => $request->client_id ?? data_get($request->client, 'value'),
               'vendor_id'  => $request->vendor_id ?? data_get($request->vendor, 'value'),
               'renewal_date' => $request->renewal_date ?? $request->expiry_date ?? $request->valid_till,
            ]);

            // STEP 3 — UNIFIED VALIDATION RULE
            $validated = validator($request->all(), [
               'product_id' => 'required|exists:products,id',
               'client_id'  => 'required',
               'vendor_id'  => 'required|exists:vendors,id',
               'amount'     => 'nullable|numeric'
            ])->validate();

            // STEP 4 — SAFE DATE NORMALIZATION
            if ($request->renewal_date) {
               $request->merge([
                  'renewal_date' => \Carbon\Carbon::parse($request->renewal_date)->format('Y-m-d')
               ]);
            }
            if ($request->deletion_date) {
               $request->merge([
                  'deletion_date' => \Carbon\Carbon::parse($request->deletion_date)->format('Y-m-d')
               ]);
            }

            // Calculate auto fields before create
            $today = now()->startOfDay();
            $days_left = $request->renewal_date ? $today->diffInDays($request->renewal_date, false) : null;
            $days_to_delete = $request->deletion_date ? $today->diffInDays($request->deletion_date, false) : null;

            $amount = $request->amount ?? $request->counter_count ?? 0;

            // STEP 5 — STANDARD CREATE LOGIC
            $model = Counter::create([
               'product_id'    => $request->product_id,
               'client_id'     => $request->client_id,
               'vendor_id'     => $request->vendor_id,
               'amount'        => $amount,
               'renewal_date'  => $request->renewal_date,
               'deletion_date' => $request->deletion_date,
               'days_left'     => $days_left,
               'days_to_delete'=> $days_to_delete,
               'status'        => $request->status ?? 1,
               'remarks'       => \App\Services\CryptService::encryptData($request->remarks)
            ]);

            // STEP 7 — STANDARD SUCCESS RESPONSE
            $model->refresh()->load(['product', 'client', 'vendor']);
            $model->client_name  = $model->client->name  ?? null;
            try { $model->client_name = \App\Services\CryptService::decryptData($model->client_name) ?? $model->client_name; } catch (\Exception $e) {}
            
            $model->product_name = $model->product->name ?? null;
            try { $model->product_name = \App\Services\CryptService::decryptData($model->product_name) ?? $model->product_name; } catch (\Exception $e) {}
            
            $model->vendor_name  = $model->vendor->name  ?? null;
            try { $model->vendor_name = \App\Services\CryptService::decryptData($model->vendor_name) ?? $model->vendor_name; } catch (\Exception $e) {}
            $model->expiry_date  = $model->renewal_date;
            try { $model->remarks = \App\Services\CryptService::decryptData($model->remarks) ?? $model->remarks; } catch (\Exception $e) {}
            $resp = $model->toArray();
            $resp['client_name']  = $model->client_name;
            $resp['product_name'] = $model->product_name;
            $resp['vendor_name']  = $model->vendor_name;
            $resp['counter_count'] = $model->amount;
            $resp['valid_till']    = $model->renewal_date;
            $resp['expiry_date']  = $model->expiry_date;
            $resp['last_updated'] = DateFormatterService::format($model->updated_at);
            $resp['updated_at_formatted'] = DateFormatterService::format($model->updated_at);
            $resp['created_at_formatted'] = DateFormatterService::format($model->created_at);
            // updated_at / created_at kept as raw ISO from toArray() — do NOT overwrite
            $this->logActivity('created', $model);

            return response()->json([
               'success' => true,
               'data'    => $resp
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
               'success' => false,
               'message' => $e->getMessage(),
               'line'    => $e->getLine(),
               'file'    => basename($e->getFile())
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $record = Counter::find($id);
        if (!$record) return response()->json(['success' => false, 'message' => 'Not found'], 404);

        // ── OWNERSHIP GUARD: Client can only edit their own records ──
        ClientScopeService::assertOwnership($record, $request);

        $data = $request->all();
        foreach ($data as $key => $value) {
            if ($value === '') $data[$key] = null;
        }

        if (isset($data['expiry_date']) && !isset($data['renewal_date'])) {
            $data['renewal_date'] = $data['expiry_date'];
        }

        if (isset($data['valid_till']) && !isset($data['renewal_date'])) {
            $data['renewal_date'] = $data['valid_till'];
        }

        if (isset($data['counter_count']) && !isset($data['amount'])) {
            $data['amount'] = $data['counter_count'];
        }

        if (isset($data['renewal_date'])) $data['renewal_date'] = $this->formatDate($data['renewal_date']);
        if (isset($data['deletion_date'])) $data['deletion_date'] = $this->formatDate($data['deletion_date']);
 
        $this->calculateFields($data);

        if (isset($data['remarks'])) {
            // Track Remark History
            \App\Services\RemarkHistoryService::trackChange('Counter', $record->id, $record->remarks, $data['remarks']);
            $data['remarks'] = \App\Services\CryptService::encryptData($data['remarks']);
        }

        $record->update($data);
        $record->refresh()->load(['product', 'client', 'vendor'])->loadCount('remarkHistories');
        $record->client_name  = $record->client->name  ?? null;
        try { $record->client_name = \App\Services\CryptService::decryptData($record->client_name) ?? $record->client_name; } catch (\Exception $e) {}
        
        $record->product_name = $record->product->name ?? null;
        try { $record->product_name = \App\Services\CryptService::decryptData($record->product_name) ?? $record->product_name; } catch (\Exception $e) {}
        
        $record->vendor_name  = $record->vendor->name  ?? null;
        try { $record->vendor_name = \App\Services\CryptService::decryptData($record->vendor_name) ?? $record->vendor_name; } catch (\Exception $e) {}
        $record->remarks      = \App\Services\CryptService::decryptData($record->remarks) ?? $record->remarks;
        $record->expiry_date  = $record->renewal_date;
        $record->has_remark_history = $record->remark_histories_count > 0;
        $resp = $record->toArray();
        $resp['client_name']  = $record->client_name;
        $resp['product_name'] = $record->product_name;
        $resp['vendor_name']  = $record->vendor_name;
        $resp['counter_count'] = $record->amount;
        $resp['valid_till']    = $record->renewal_date;
        $resp['remarks']      = $record->remarks;
        $resp['expiry_date']  = $record->expiry_date;
        $resp['has_remark_history'] = $record->has_remark_history;
        $resp['last_updated'] = DateFormatterService::format($record->updated_at);
        $resp['updated_at_formatted'] = DateFormatterService::format($record->updated_at);
        $resp['created_at_formatted'] = DateFormatterService::format($record->created_at);
        // updated_at / created_at kept as raw ISO from toArray() — do NOT overwrite
        
        $this->logActivity('updated', $record);

        return response()->json([
            'success' => true,
            'message' => 'Counter Record updated successfully',
            'data' => $resp
        ]);
    }

    public function destroy($id)
    {
        $record = Counter::find($id);
        if (!$record) return response()->json(['success' => false, 'message' => 'Not found'], 404);

        // ── OWNERSHIP GUARD: Client can only delete their own records ──
        ClientScopeService::assertOwnership($record, new \Illuminate\Http\Request());

        $this->logActivity('deleted', $record);
        $record->delete();

        return response()->json([
            'success' => true,
            'message' => 'Counter Record deleted successfully'
        ]);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file']);
        
        try {
            $clientId = $request->input('client_id') ?? null;
            $importer = new \App\Imports\UniversalImport('counters', $clientId);
            $result = \App\Services\ImportService::handleImport($request, $importer, 'Counter');
            
            $history          = $result['history'];
            $importer         = $result['importer'];
            $duplicateFile    = $result['duplicate_file'] ?? null;
            $duplicateFileUrl = $result['duplicate_file_url'] ?? null;

            $inserted   = $importer->inserted;
            $duplicates = $importer->duplicates;
            $failed     = $importer->failed;
            $errors     = $importer->errors;

            $latest = $inserted > 0
                ? Counter::with(['product', 'client', 'vendor'])
                    ->orderBy('id', 'desc')
                    ->take($inserted)
                    ->get()
                    ->map(function ($item) {
                        $pName = optional($item->product)->name;
                        $cName = optional($item->client)->name;
                        $vName = optional($item->vendor)->name;
                        try { $pName = \App\Services\CryptService::decryptData($pName) ?? $pName; } catch (\Exception $e) {}
                        try { $cName = \App\Services\CryptService::decryptData($cName) ?? $cName; } catch (\Exception $e) {}
                        try { $vName = \App\Services\CryptService::decryptData($vName) ?? $vName; } catch (\Exception $e) {}
                        $item->product_name = $pName;
                        $item->client_name  = $cName;
                        $item->vendor_name  = $vName;
                        return $item;
                    })
                    ->reverse()
                    ->values()
                : collect();

            $msg = "$inserted record(s) imported.";
            if ($duplicates > 0) $msg .= " $duplicates duplicate(s) skipped.";
            if ($failed > 0)     $msg .= " $failed row(s) failed.";

            if ($inserted > 0) ActivityLogger::imported(null, 'Counter', $inserted);

            return response()->json([
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
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function fetchCount(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required',
            'product_id' => 'required',
            'vendor_id' => 'required',
        ]);

        // Fetch requested entities and decrypt their names to find siblings
        $clientReq = \App\Models\Superadmin::find($validated['client_id']);
        $productReq = \App\Models\Product::find($validated['product_id']);
        $vendorReq = \App\Models\Vendor::find($validated['vendor_id']);
        
        $cNameDecrypted = null;
        $pNameDecrypted = null;
        $vNameDecrypted = null;

        if ($clientReq) {
            try { $cNameDecrypted = \App\Services\CryptService::decryptData($clientReq->name) ?? $clientReq->name; } catch (\Exception $e) {}
        }
        if ($productReq) {
            try { $pNameDecrypted = \App\Services\CryptService::decryptData($productReq->name) ?? $productReq->name; } catch (\Exception $e) {}
        }
        if ($vendorReq) {
            try { $vNameDecrypted = \App\Services\CryptService::decryptData($vendorReq->name) ?? $vendorReq->name; } catch (\Exception $e) {}
        }

        // Helper to find all IDs matching a decrypted name exactly (case insensitive)
        $findMatchingIds = function ($modelClass, $decryptedName, $defaultId) {
            if (!$decryptedName) return [$defaultId];
            return $modelClass::all()->filter(function ($item) use ($decryptedName) {
                try {
                    $dec = \App\Services\CryptService::decryptData($item->name) ?? $item->name;
                    return strtolower(trim($dec)) === strtolower(trim($decryptedName));
                } catch (\Exception $e) {
                    return strtolower(trim($item->name)) === strtolower(trim($decryptedName));
                }
            })->pluck('id')->toArray();
        };

        $matchingClients = $findMatchingIds(\App\Models\Superadmin::class, $cNameDecrypted, $validated['client_id']);
        $matchingProducts = $findMatchingIds(\App\Models\Product::class, $pNameDecrypted, $validated['product_id']);
        $matchingVendors = $findMatchingIds(\App\Models\Vendor::class, $vNameDecrypted, $validated['vendor_id']);

        $count = \App\Models\Subscription::whereIn('client_id', $matchingClients)
                    ->whereIn('product_id', $matchingProducts)
                    ->whereIn('vendor_id', $matchingVendors)
                    ->count();

        return response()->json([
            'success' => true,
            'count' => $count
        ]);
    }
}
