<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Activity;
use App\Models\ImportExportHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Services\ActivityLogger;
use App\Services\ClientScopeService;
use App\Services\DateFormatterService;

class DomainController extends Controller
{
    protected array $productIds = [46];

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
            $clientName = $record->client_name ?? ($record->client?->name ?? 'N/A');
            try { $clientName = \App\Services\CryptService::decryptData($clientName); } catch (\Exception $e) {}
            
            $domainName = $record->name ?? $record->domain_name_display ?? 'N/A';
            try { $domainName = \App\Services\CryptService::decryptData($domainName); } catch (\Exception $e) {}

            $details = "Domain: {$domainName} | Client: {$clientName} | Renewal: " . ($record->renewal_date ?? '-');
            ActivityLogger::log(null, "Domain {$label}", $details, 'Domains');
        } catch (\Exception $e) {}
    }

    public function DomainList(Request $request)
    {
        $page        = (int) $request->input('page', 0);
        $rowsPerPage = (int) $request->input('rowsPerPage', 10);
        $order       = $request->input('order', 'desc');
        $orderBy     = $request->input('orderBy', 'id');
        $search      = strtolower($request->input('search', ''));

        $validColumns = ['id', 'renewal_date', 'deletion_date', 'created_at', 'amount'];
        if (!in_array($orderBy, $validColumns)) {
            $orderBy = 'id';
        }

        $query = \App\Models\Domain::with(['client', 'vendor', 'product']);

        // ── CLIENT SCOPE: filter to only this client's records ──
        ClientScopeService::applyScope($query, $request);

        $total = (clone $query)->count();

        $rows = (clone $query)
            ->orderBy($orderBy, $order)
            ->skip($page * $rowsPerPage)
            ->take($rowsPerPage)
            ->get()
            ->map(function ($item) {
                $today = now()->startOfDay();
                $item->days_left      = $item->renewal_date  ? $today->diffInDays(Carbon::parse($item->renewal_date)->startOfDay(), false)  : null;
                $item->days_to_delete = $item->deletion_date ? $today->diffInDays(Carbon::parse($item->deletion_date)->startOfDay(), false) : null;

                // Decrypt client name
                $clientName = optional($item->client)->name;
                try { $clientName = \App\Services\CryptService::decryptData($clientName) ?? $clientName; } catch (\Exception $e) {}
                $item->client_name = $clientName;

                // Vendor name
                $vendorName = optional($item->vendor)->name;
                try { $vendorName = \App\Services\CryptService::decryptData($vendorName) ?? $vendorName; } catch (\Exception $e) {}
                $item->vendor_name = $vendorName;

                // Product name
                $productName = optional($item->product)->name;
                try { $productName = \App\Services\CryptService::decryptData($productName) ?? $productName; } catch (\Exception $e) {}
                $item->product_name = $productName;

                // Decrypt Domain Name
                $domainName = $item->name;
                try { $domainName = \App\Services\CryptService::decryptData($domainName) ?? $domainName; } catch (\Exception $e) {}
                $item->domain_name_display = $domainName ?? ('Domain #' . $item->id);

                try { $item->remarks = \App\Services\CryptService::decryptData($item->remarks) ?? $item->remarks; } catch (\Exception $e) {}

                $data = $item->toArray();
                $data['days_left'] = $item->days_left;
                $data['days_to_delete'] = $item->days_to_delete;
                $data['client_name'] = $item->client_name;
                $data['vendor_name'] = $item->vendor_name;
                $data['name'] = $item->domain_name_display;
                $data['remarks'] = \App\Services\CryptService::decryptData(\App\Services\CryptService::decryptData($item->remarks));
                $data['domain_name'] = $item->domain_name_display; // Add this for consistency

                $data['last_updated']         = DateFormatterService::format($item->updated_at);
                $data['updated_at_formatted']   = DateFormatterService::format($item->updated_at);
                $data['created_at_formatted']   = DateFormatterService::format($item->created_at ?? $item->updated_at);
                // Do NOT overwrite updated_at — keep raw ISO timestamp for Eloquent casting

                return $data;
            });

        // Apply search filter after decryption (in-memory on this page)
        if ($search !== '') {
            $rows = $rows->filter(function ($item) use ($search) {
                return str_contains(strtolower($item->client_name ?? ''), $search)
                    || str_contains(strtolower($item->vendor_name ?? ''), $search)
                    || str_contains(strtolower($item->product_name ?? ''), $search)
                    || str_contains(strtolower($item->remarks ?? ''), $search);
            })->values();
            $total = $rows->count();
        }

        return response()->json([
            'status' => true,
            'success' => true,
            'rows'  => $rows,
            'total' => $total,
        ]);
    }

    public function index(Request $request)
    {
        $limit  = $request->query('limit', $request->query('rowsPerPage', 100));
        $offset = $request->query('offset', $request->query('page', 0) * $limit);
        $search = $request->query('search', '');

        $query = Domain::select([
            'id', 'name', 'product_id', 'client_id', 'vendor_id', 
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

            // Searching Domain Name (this can be slow if there are many domains, 
            // but usually domains are manageable per client)
            $dIds = Domain::all()->filter(function($d) use ($searchLow) {
                $dec = \App\Services\CryptService::decryptData($d->name);
                return str_contains(strtolower($dec ?? $d->name), $searchLow);
            })->pluck('id');

            $query->where(function($q) use ($pIds, $cIds, $vIds, $dIds) {
                $q->whereIn('product_id', $pIds)
                  ->orWhereIn('client_id', $cIds)
                  ->orWhereIn('vendor_id', $vIds)
                  ->orWhereIn('id', $dIds);
            });
        }

        $total = $query->count();
        $query->orderBy('created_at', 'desc')->skip($offset)->take($limit);

        $records = $query->get()->map(function ($item) {
                $today = now()->startOfDay();
                $item->days_left = $item->renewal_date ? $today->diffInDays(Carbon::parse($item->renewal_date)->startOfDay(), false) : null;
                $item->days_to_delete = $item->deletion_date ? $today->diffInDays(Carbon::parse($item->deletion_date)->startOfDay(), false) : null;
                $item->client_name = $item->client->name ?? null;
                try { $item->client_name = \App\Services\CryptService::decryptData($item->client_name) ?? $item->client_name; } catch (\Exception $e) {}

                $item->product_name = $item->product->name ?? null;
                try { $item->product_name = \App\Services\CryptService::decryptData($item->product_name) ?? $item->product_name; } catch (\Exception $e) {}

                $item->vendor_name = $item->vendor->name ?? null;
                try { $item->vendor_name = \App\Services\CryptService::decryptData($item->vendor_name) ?? $item->vendor_name; } catch (\Exception $e) {}
                $item->has_remark_history = $item->remark_histories_count > 0;
                try {
                    $item->remarks = \App\Services\CryptService::decryptData($item->remarks) ?? $item->remarks;
                } catch (\Exception $e) {}
                $data = $item->toArray();
                
                // Decrypt Domain Name for display
                $domainName = $item->name;
                try { $domainName = \App\Services\CryptService::decryptData($domainName) ?? $domainName; } catch (\Exception $e) {}
                
                $data['name'] = $domainName ?? ('Domain #' . $item->id);
                $data['days_left'] = $item->days_left;
                $data['days_to_delete'] = $item->days_to_delete;
                $data['client_name'] = $item->client_name;
                $data['product_name'] = $item->product_name;
                $data['vendor_name'] = $item->vendor_name;
                $data['remarks'] = $item->remarks;
                $data['domain_name'] = $data['name'];

                $data['last_updated'] = DateFormatterService::format($item->updated_at);
                $data['updated_at_formatted'] = DateFormatterService::format($item->updated_at);
                $data['created_at_formatted'] = DateFormatterService::format($item->created_at ?? $item->updated_at);
                // Do NOT overwrite updated_at — keep raw ISO timestamp for Eloquent casting
                
                return $data;
            });

        return response()->json([
            'status' => true,
            'success' => true,
            'data' => $records,
            'total' => $total
        ]);
    }

    // ── Standard resource aliases so Route::apiResource('domains', ...) works ──
    public function store(Request $request)   { return $this->storeDomain($request); }
    public function update(Request $request, $id) { $request->merge(['id' => $id]); return $this->updateDomain($request); }
    public function destroy($id)              { return $this->deleteDomains(new \Illuminate\Http\Request(['id' => $id])); }

    public function storeDomain(Request $request)
    {
        try {
            // ── CLIENT SCOPE: force client_id from JWT if client ──
            ClientScopeService::enforceClientId($request);

            // STEP 2 — STANDARDIZE REQUEST INPUT
            $request->merge([
               'product_id' => $request->product_id ?? data_get($request->product, 'value'),
               'client_id'  => $request->client_id ?? data_get($request->client, 'value'),
               'vendor_id'  => $request->vendor_id ?? data_get($request->vendor, 'value'),
               'renewal_date' => $request->renewal_date ?? $request->expiry_date,
            ]);

            // STEP 3 — UNIFIED VALIDATION RULE
            $validated = validator($request->all(), [
               'name'        => 'required_without:domain_name|string',
               'domain_name' => 'required_without:name|string',
               'product_id'  => 'nullable|exists:products,id',
               'client_id'   => 'nullable|exists:superadmins,id',
               'vendor_id'   => 'nullable|exists:vendors,id',
               'amount'      => 'nullable|numeric'
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
            $renewalDate = $request->renewal_date ? \Carbon\Carbon::parse($request->renewal_date)->startOfDay() : null;
            $deletionDate = $request->deletion_date ? \Carbon\Carbon::parse($request->deletion_date)->startOfDay() : null;
            
            $days_left = $renewalDate ? $today->diffInDays($renewalDate, false) : null;
            $days_to_delete = $deletionDate ? $today->diffInDays($deletionDate, false) : null;

            $encryptedName = \App\Services\CryptService::encryptData($request->name ?? $request->domain_name);

            // STEP 5 — STANDARD CREATE LOGIC
            $model = Domain::create([
               'name'          => $encryptedName,
               'product_id'    => $request->product_id,
               'client_id'     => $request->client_id,
               'vendor_id'     => $request->vendor_id,
               'amount'        => $request->amount ?? 0,
               'renewal_date'  => $request->renewal_date,
               'deletion_date' => $request->deletion_date,
               'days_left'     => $days_left,
               'days_to_delete'=> $days_to_delete,
               'domain_protected' => $request->domain_protected ?? 0,
               'status'        => $request->status ?? 1,
               'remarks'       => $request->remarks ? \App\Services\CryptService::encryptData($request->remarks) : null
            ]);
            
            try { $model->remarks = \App\Services\CryptService::decryptData($model->remarks) ?? $model->remarks; } catch (\Exception $e) {}

            // STEP 7 — STANDARD SUCCESS RESPONSE
            $model->refresh()->load(['product', 'client', 'vendor']);
            $model->client_name  = optional($model->client)->name;
            try { $model->client_name = \App\Services\CryptService::decryptData($model->client_name) ?? $model->client_name; } catch (\Exception $e) {}
            
            $model->product_name = optional($model->product)->name;
            try { $model->product_name = \App\Services\CryptService::decryptData($model->product_name) ?? $model->product_name; } catch (\Exception $e) {}
            
            $model->vendor_name  = optional($model->vendor)->name;
            try { $model->vendor_name = \App\Services\CryptService::decryptData($model->vendor_name) ?? $model->vendor_name; } catch (\Exception $e) {}
            $model->expiry_date  = $model->renewal_date;
            $resp = $model->toArray();
            $resp['client_name']  = $model->client_name;
            $resp['product_name'] = $model->product_name;
            $resp['vendor_name']  = $model->vendor_name;
            $resp['remarks']      = $model->remarks;
            $resp['expiry_date']  = $model->expiry_date;
            
            // Decrypt name for UI
            $decName = $model->name;
            try { $decName = \App\Services\CryptService::decryptData($decName) ?? $decName; } catch (\Exception $e) {}
            $resp['name'] = $decName;
            $resp['domain_name'] = $decName;
            $resp['last_updated'] = DateFormatterService::format($model->updated_at);
            $resp['updated_at_formatted'] = DateFormatterService::format($model->updated_at);
            $resp['created_at_formatted'] = DateFormatterService::format($model->created_at);
            // updated_at / created_at kept as raw ISO from toArray() — do NOT overwrite
            $this->logActivity('created', $model);

            return response()->json([
               'status' => true,
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

    public function updateDomain(Request $request)
    {
        $id = $request->input('id');
        $record = Domain::find($id);
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

        if (isset($data['renewal_date'])) $data['renewal_date'] = $this->formatDate($data['renewal_date']);
        if (isset($data['deletion_date'])) $data['deletion_date'] = $this->formatDate($data['deletion_date']);

        $this->calculateFields($data);

        if (isset($data['remarks'])) {
            // Track Remark History
            \App\Services\RemarkHistoryService::trackChange('Domains', $record->id, $record->remarks, $data['remarks']);
            $data['remarks'] = \App\Services\CryptService::encryptData($data['remarks']);
        }

        if (isset($data['name']) || isset($data['domain_name'])) {
            $record->name = \App\Services\CryptService::encryptData($data['name'] ?? $data['domain_name']);
        }

        $record->update([
            'name'             => $record->name, // use the value we just set if any
            'product_id'       => $data['product_id'] ?? $record->product_id,
            'client_id'        => $data['client_id'] ?? $record->client_id,
            'vendor_id'        => $data['vendor_id'] ?? $record->vendor_id,
            'amount'           => $data['amount'] ?? $record->amount,
            'renewal_date'     => $data['renewal_date'] ?? $record->renewal_date,
            'deletion_date'    => $data['deletion_date'] ?? $record->deletion_date,
            'days_left'        => $data['days_left'] ?? $record->days_left,
            'days_to_delete'   => $data['days_to_delete'] ?? $record->days_to_delete,
            'status'           => $data['status'] ?? $record->status,
            'domain_protected' => $data['domain_protected'] ?? $record->domain_protected,
            'remarks'          => $data['remarks'] ?? $record->remarks,
        ]);
        $record->refresh()->load(['product', 'client', 'vendor'])->loadCount('remarkHistories');
        $record->client_name  = $record->client->name  ?? null;
        try { $record->client_name = \App\Services\CryptService::decryptData($record->client_name) ?? $record->client_name; } catch (\Exception $e) {}
        
        $record->product_name = $record->product->name ?? null;
        try { $record->product_name = \App\Services\CryptService::decryptData($record->product_name) ?? $record->product_name; } catch (\Exception $e) {}
        
        $record->vendor_name  = $record->vendor->name  ?? null;
        try { $record->vendor_name = \App\Services\CryptService::decryptData($record->vendor_name) ?? $record->vendor_name; } catch (\Exception $e) {}
        
        $decryptedRemarks = \App\Services\CryptService::decryptData($record->remarks);
        $record->remarks      = \App\Services\CryptService::decryptData($decryptedRemarks); 
        
        $record->expiry_date  = $record->renewal_date;
        $record->has_remark_history = $record->remark_histories_count > 0;
        $this->logActivity('updated', $record);

        $resp = $record->toArray();
        $resp['client_name']  = $record->client_name;
        $resp['product_name'] = $record->product_name;
        $resp['vendor_name']  = $record->vendor_name;
        $resp['remarks']      = $record->remarks;
        $resp['expiry_date']  = $record->expiry_date;
        $resp['has_remark_history'] = $record->has_remark_history;

        // Decrypt name for UI
        $decName = $record->name;
        try { $decName = \App\Services\CryptService::decryptData($decName) ?? $decName; } catch (\Exception $e) {}
        $resp['name'] = $decName;
        $resp['domain_name'] = $decName;
        $resp['last_updated']         = DateFormatterService::format($record->updated_at);
        $resp['updated_at_formatted']   = DateFormatterService::format($record->updated_at);
        $resp['created_at_formatted']   = DateFormatterService::format($record->created_at);
        // updated_at / created_at kept as raw ISO from toArray() — do NOT overwrite

        return response()->json([
            'status' => true,
            'success' => true,
            'message' => 'Domain Record updated successfully',
            'data' => $resp
        ]);
    }

    public function deleteDomains(Request $request)
    {
        $id = $request->input('domain_id') ?? $request->input('id');
        $record = Domain::find($id);
        if (!$record) return response()->json(['success' => false, 'message' => 'Not found'], 404);

        // ── OWNERSHIP GUARD: Client can only delete their own records ──
        ClientScopeService::assertOwnership($record, new \Illuminate\Http\Request());

        $this->logActivity('deleted', $record);
        $record->delete();

        return response()->json([
            'status' => true,
            'success' => true,
            'message' => 'Domain Record deleted successfully'
        ]);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file']);
        
        try {
            $clientId = $request->input('client_id') ?? null;
            $importer = new \App\Imports\UniversalImport('domains', $clientId);
            $result = \App\Services\ImportService::handleImport($request, $importer, 'Domain');
            
            $history          = $result['history'];
            $importer         = $result['importer'];
            $duplicateFile    = $result['duplicate_file'] ?? null;
            $duplicateFileUrl = $result['duplicate_file_url'] ?? null;
            $inserted   = $importer->inserted;
            $duplicates = $importer->duplicates;
            $failed     = $importer->failed;
            $errors     = $importer->errors;

            $latest = $inserted > 0
                ? Domain::with(['product', 'client', 'vendor'])
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
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}