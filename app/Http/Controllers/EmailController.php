<?php

namespace App\Http\Controllers;

use App\Models\Email;
use App\Models\Activity;
use App\Models\ImportExportHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Services\ActivityLogger;
use App\Services\ClientScopeService;
use App\Services\DateFormatterService;

class EmailController extends Controller
{
    protected array $productIds = [45];

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
            ActivityLogger::log(null, "Email {$label}", $details, 'Email');
        } catch (\Exception $e) {}
    }

    public function index(Request $request)
    {
        $limit  = $request->query('limit', 100);
        $offset = $request->query('offset', 0);
        $search = $request->query('search', '');

        $query = Email::select([
            'id', 'product_id', 'client_id', 'vendor_id', 'domain_id',
            'quantity', 'bill_type', 'start_date',
            'amount', 'renewal_date', 'deletion_date', 'status', 
            'remarks', 'created_at', 'updated_at'
        ])
        ->with([
            'product:id,name', 
            'client:id,name', 
            'vendor:id,name',
            'domainInfo:id,name'
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

        $records = $query->get()->map(function ($item) {
                $today = now()->startOfDay();
                $item->days_left = $item->renewal_date ? $today->diffInDays(\Illuminate\Support\Carbon::parse($item->renewal_date)->startOfDay(), false) : null;
                $item->days_to_delete = $item->deletion_date ? $today->diffInDays(\Illuminate\Support\Carbon::parse($item->deletion_date)->startOfDay(), false) : null;
                
                $item->client_name = optional($item->client)->name;
                $item->product_name = optional($item->product)->name;
                $item->vendor_name = optional($item->vendor)->name;
                $item->domain_name = optional($item->domainInfo)->name;

                try { $item->client_name = \App\Services\CryptService::decryptData($item->client_name) ?? $item->client_name; } catch (\Exception $e) {}
                try { $item->product_name = \App\Services\CryptService::decryptData($item->product_name) ?? $item->product_name; } catch (\Exception $e) {}
                try { $item->vendor_name = \App\Services\CryptService::decryptData($item->vendor_name) ?? $item->vendor_name; } catch (\Exception $e) {}
                try { $item->domain_name = \App\Services\CryptService::decryptData($item->domain_name) ?? $item->domain_name; } catch (\Exception $e) {}

                $item->has_remark_history = $item->remark_histories_count > 0;
                try {
                    $dec = \App\Services\CryptService::decryptData($item->remarks);
                    $item->remarks = \App\Services\CryptService::decryptData($dec);
                } catch (\Exception $e) {}

                $data = $item->toArray();
                $data['days_left'] = $item->days_left;
                $data['days_to_delete'] = $item->days_to_delete;
                $data['client_name'] = $item->client_name;
                $data['product_name'] = $item->product_name;
                $data['vendor_name'] = $item->vendor_name;
                $data['domain_name'] = $item->domain_name;
                $data['has_remark_history'] = $item->has_remark_history;
                $data['remarks'] = $item->remarks;

                $data['last_updated'] = DateFormatterService::format($item->updated_at);
                $data['updated_at_formatted'] = DateFormatterService::format($item->updated_at);
                $data['created_at_formatted'] = DateFormatterService::format($item->created_at ?? $item->updated_at);
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
               'renewal_date' => $request->renewal_date ?? $request->expiry_date,
            ]);

            // STEP 3 — UNIFIED VALIDATION RULE
            $validated = validator($request->all(), [
               'product_id' => 'required|exists:products,id',
               'client_id'  => 'required',
               'vendor_id'  => 'nullable|exists:vendors,id',
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

            // STEP 5 — STANDARD CREATE LOGIC
            $model = Email::create([
               'product_id'    => $request->product_id,
               'client_id'     => $request->client_id,
               'vendor_id'     => $request->vendor_id,
               'domain_id'     => $request->domain_id,
               'quantity'      => $request->quantity ?? 1,
               'bill_type'     => $request->bill_type,
               'start_date'    => $request->start_date,
               'amount'        => $request->amount ?? 0,
               'renewal_date'  => $request->renewal_date,
               'deletion_date' => $request->deletion_date,
               'days_left'     => $days_left,
               'days_to_delete'=> $days_to_delete,
                'status'        => $request->status ?? 1,
                'remarks'       => $request->remarks ? \App\Services\CryptService::encryptData($request->remarks) : null
            ]);

            try { $model->remarks = \App\Services\CryptService::decryptData($model->remarks) ?? $model->remarks; } catch (\Exception $e) {}

            // STEP 7 — STANDARD SUCCESS RESPONSE
            $model->refresh()->load(['product', 'client', 'vendor', 'domainInfo']);
            $model->client_name  = $model->client->name  ?? null;
            try { $model->client_name = \App\Services\CryptService::decryptData($model->client_name) ?? $model->client_name; } catch (\Exception $e) {}
            
            $model->product_name = $model->product->name ?? null;
            try { $model->product_name = \App\Services\CryptService::decryptData($model->product_name) ?? $model->product_name; } catch (\Exception $e) {}
            
            $model->vendor_name  = $model->vendor->name  ?? null;
            try { $model->vendor_name = \App\Services\CryptService::decryptData($model->vendor_name) ?? $model->vendor_name; } catch (\Exception $e) {}

            $model->domain_name  = $model->domainInfo->name ?? null;
            try { $model->domain_name = \App\Services\CryptService::decryptData($model->domain_name) ?? $model->domain_name; } catch (\Exception $e) {}
            
            $model->expiry_date  = $model->renewal_date;
            $resp = $model->toArray();
            $resp['client_name']  = $model->client_name;
            $resp['product_name'] = $model->product_name;
            $resp['vendor_name']  = $model->vendor_name;
            $resp['domain_name']  = $model->domain_name;
            $resp['expiry_date']  = $model->expiry_date;
            $resp['remarks']      = $model->remarks;
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
        $record = Email::find($id);
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
            \App\Services\RemarkHistoryService::trackChange('Emails', $record->id, $record->remarks, $data['remarks']);
            $data['remarks'] = \App\Services\CryptService::encryptData($data['remarks']);
        }

        $record->update($data);
        $record->refresh()->load(['product', 'client', 'vendor', 'domainInfo'])->loadCount('remarkHistories');
        $record->client_name  = $record->client->name  ?? null;
        try { $record->client_name = \App\Services\CryptService::decryptData($record->client_name) ?? $record->client_name; } catch (\Exception $e) {}
        
        $record->product_name = $record->product->name ?? null;
        try { $record->product_name = \App\Services\CryptService::decryptData($record->product_name) ?? $record->product_name; } catch (\Exception $e) {}
        
        $record->vendor_name  = $record->vendor->name  ?? null;
        try { $record->vendor_name = \App\Services\CryptService::decryptData($record->vendor_name) ?? $record->vendor_name; } catch (\Exception $e) {}

        $record->domain_name  = $record->domainInfo->name ?? null;
        try { $record->domain_name = \App\Services\CryptService::decryptData($record->domain_name) ?? $record->domain_name; } catch (\Exception $e) {}
        $record->remarks      = \App\Services\CryptService::decryptData($record->remarks) ?? $record->remarks;
        $record->expiry_date  = $record->renewal_date;
        $record->has_remark_history = $record->remark_histories_count > 0;
        $resp = $record->toArray();
        $resp['client_name']  = $record->client_name;
        $resp['product_name'] = $record->product_name;
        $resp['vendor_name']  = $record->vendor_name;
        $resp['domain_name']  = $record->domain_name;
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
            'message' => 'Email Record updated successfully',
            'data' => $resp
        ]);
    }

    public function destroy($id)
    {
        $record = Email::find($id);
        if (!$record) return response()->json(['success' => false, 'message' => 'Not found'], 404);

        // ── OWNERSHIP GUARD: Client can only delete their own records ──
        ClientScopeService::assertOwnership($record, new \Illuminate\Http\Request());

        $this->logActivity('deleted', $record);
        $record->delete();

        return response()->json([
            'success' => true,
            'message' => 'Email Record deleted successfully'
        ]);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file']);
        
        try {
            $clientId = $request->input('client_id') ?? null;
            $importer = new \App\Imports\UniversalImport('emails', $clientId);
            $result = \App\Services\ImportService::handleImport($request, $importer, 'Email');
            
            $history          = $result['history'];
            $importer         = $result['importer'];
            $duplicateFile    = $result['duplicate_file'] ?? null;
            $duplicateFileUrl = $result['duplicate_file_url'] ?? null;

            $inserted   = $importer->inserted;
            $duplicates = $importer->duplicates;
            $failed     = $importer->failed;
            $errors     = $importer->errors;

            $latest = $inserted > 0
                ? Email::with(['product', 'client', 'vendor'])
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

            if ($inserted > 0) ActivityLogger::imported(null, 'Email', $inserted);

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
}