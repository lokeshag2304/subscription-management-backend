<?php

namespace App\Http\Controllers;

use App\Models\SSL;
use App\Models\Activity;
use App\Models\ImportExportHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Services\ActivityLogger;
use App\Services\ClientScopeService;
use App\Services\DateFormatterService;

class SSLController extends Controller
{
    protected array $productIds = [42, 43];

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

    private function logActivity($action, $record, $oldData = null, $newData = null)
    {
        try {
            $module = 'SSL';
            $label = ucfirst($action);
            $domainName = $record->domain_name ?? $record->id;
            $clientName = $record->client_name ?? 'N/A';
            $details = "Domain: {$domainName} | Client: {$clientName} | Renewal: " . ($record->renewal_date ?? '-');
            
            $actionType = $action === 'created' ? 'CREATE' : ($action === 'deleted' ? 'DELETE' : 'UPDATE');
            if ($actionType === 'CREATE' && !$newData && $record) $newData = is_array($record) ? $record : ((is_object($record) && method_exists($record, 'toArray')) ? $record->toArray() : (array)$record);
            if ($actionType === 'DELETE' && !$oldData && $record) $oldData = is_array($record) ? $record : ((is_object($record) && method_exists($record, 'toArray')) ? $record->toArray() : (array)$record);

            ActivityLogger::logActivity(auth()->user(), $actionType, $module, 's_s_l_s', $record->id ?? null, $oldData, $newData, "SSL {$label}", request());
        } catch (\Exception $e) {}
    }

    public function index(Request $request)
    {
        $limit  = $request->query('limit', 100);
        $offset = $request->query('offset', 0);
        $search = $request->query('search', '');

        $query = SSL::select([
            'id', 'domain_id', 'product_id', 'client_id', 'vendor_id', 
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
            
            // Only search relevant domains if a client scope is active
            $dQuery = \App\Models\Domain::query();
            ClientScopeService::applyScope($dQuery, $request);
            $dIds = $dQuery->select(['id', 'name'])->get()
                ->filter(function($d) use ($searchLow) {
                    $dec = \App\Services\CryptService::decryptData($d->name);
                    return str_contains(strtolower($dec ?? $d->name), $searchLow);
                })->pluck('id');

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

            $query->where(function($q) use ($pIds, $cIds, $vIds, $dIds) {
                $q->whereIn('product_id', $pIds)
                  ->orWhereIn('client_id', $cIds)
                  ->orWhereIn('vendor_id', $vIds)
                  ->orWhereIn('domain_id', $dIds);
            });
        }

        $total = $query->count();
        $query->orderBy('created_at', 'desc')->skip($offset)->take($limit);

        $records = $query->get();

        $data = $records->map(function ($item) {
            $today = now()->startOfDay();
            
            $daysLeft = $item->renewal_date 
                ? $today->diffInDays(Carbon::parse($item->renewal_date)->startOfDay(), false) 
                : null;
            
            $daysToDelete = $item->deletion_date 
                ? $today->diffInDays(Carbon::parse($item->deletion_date)->startOfDay(), false) 
                : null;

            // Decrypt Domain Name
            $rawDomain = $item->domainInfo?->name;
            $decryptedDomain = $rawDomain;
            if ($rawDomain) {
                try {
                    $decryptedDomain = \App\Services\CryptService::decryptData($rawDomain) ?? $rawDomain;
                } catch (\Exception $e) {}
            }

            // Derive Client Name
            $clientName = $item->client?->name ?? null;
            if ($clientName) {
                try {
                    $clientName = \App\Services\CryptService::decryptData($clientName) ?? $clientName;
                } catch (\Exception $e) {}
            }

            $productName = $item->product?->name ?? null;
            try { 
                $productName = \App\Services\CryptService::decryptData($productName) ?? $productName; 
            } catch (\Exception $e) {}
            
            $vendorName = $item->vendor?->name ?? null;
            try { 
                $vendorName = \App\Services\CryptService::decryptData($vendorName) ?? $vendorName; 
            } catch (\Exception $e) {}

            $remarks = \App\Services\CryptService::decryptData($item->remarks);
            $remarks = \App\Services\CryptService::decryptData($remarks); // Attempt double decryption for recovery

            return [
                'id' => $item->id,
                'domain_name' => $decryptedDomain ?? 'N/A',
                'domain_id' => $item->domain_id,
                'client_name' => $clientName ?? 'N/A',
                'client_id' => $item->client_id,
                'product_name' => $productName,
                'product_id' => $item->product_id,
                'vendor_name' => $vendorName,
                'vendor_id' => $item->vendor_id,
                'amount' => (float)$item->amount,
                'renewal_date' => $item->renewal_date,
                'expiry_date' => $item->renewal_date,
                'days_left' => $daysLeft,
                'deletion_date' => $item->deletion_date,
                'days_to_delete' => $daysToDelete,
                'status' => $item->status,
                'remarks' => $remarks,
                'has_remark_history' => $item->remark_histories_count > 0,
                'last_updated' => DateFormatterService::format($item->updated_at),
                'updated_at_formatted' => DateFormatterService::format($item->updated_at),
                'created_at_formatted' => DateFormatterService::format($item->created_at ?? $item->updated_at),
                'updated_at' => $item->updated_at,
                'created_at' => $item->created_at,
            ];
        });

        return response()->json([
            'status' => true,
            'success' => true,
            'data' => $data,
            'total' => $total
        ]);
    }

    public function store(Request $request)
    {
        try {
            // ── CLIENT SCOPE: force client_id from JWT if client is logged in ──
            ClientScopeService::enforceClientId($request);

            // STEP 2 — STANDARDIZE REQUEST INPUT
            $request->merge([
               'domain_id'  => $request->domain_id ?? data_get($request->domain, 'value'),
               'product_id' => $request->product_id ?? data_get($request->product, 'value'),
               'client_id'  => $request->client_id ?? data_get($request->client, 'value'),
               'vendor_id'  => $request->vendor_id ?? data_get($request->vendor, 'value'),
               'renewal_date' => ($request->renewal_date ?: $request->expiry_date),
            ]);

            // STEP 3 — UNIFIED VALIDATION RULE
            $validated = validator($request->all(), [
               'domain_id'     => 'required|exists:domains,id',
               'client_id'     => 'required|exists:superadmins,id', 
               'product_id'    => 'required|exists:products,id',
               'vendor_id'     => 'required|exists:vendors,id',
               'amount'        => 'required|numeric',
               'renewal_date'  => 'required|date',
               'deletion_date' => 'nullable|date',
               'status'        => 'required',
               'remarks'       => 'nullable'
            ]);
            
            if ($validated->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed: ' . implode(', ', $validated->errors()->all())
                ], 422);
            }

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
            $model = SSL::create([
               'domain_id'     => $request->domain_id,
               'product_id'    => $request->product_id,
               'client_id'     => $request->client_id,
               'vendor_id'     => $request->vendor_id,
               'amount'        => $request->amount ?? 0,
               'renewal_date'  => $request->renewal_date,
               'deletion_date' => $request->deletion_date,
               'days_left'     => $days_left,
               'days_to_delete'=> $days_to_delete,
               'status'        => $request->status ?? 1,
               'remarks'       => $request->remarks ? \App\Services\CryptService::encryptData($request->remarks) : null
            ]);

            try { 
                $model->remarks = \App\Services\CryptService::decryptData($model->remarks);
                // Try again if still looks like encrypted data
                $model->remarks = \App\Services\CryptService::decryptData($model->remarks);
            } catch (\Exception $e) {}

            // Sync client_id to the domain table so derived relations work
            if ($model->domain_id && $model->client_id) {
                \App\Models\Domain::where('id', $model->domain_id)->update(['client_id' => $model->client_id]);
            }

            // STEP 7 — STANDARD SUCCESS RESPONSE
            $model->refresh()->load(['product', 'vendor', 'domainInfo.client']);
            
            // Decrypt domain name for response
            $rawDomain = $model->domainInfo?->name ?? null;
            $decryptedDomain = $rawDomain;
            if ($rawDomain) try { $decryptedDomain = \App\Services\CryptService::decryptData($rawDomain); } catch (\Exception $e) {}

            // Derive client name from domain relation
            $client = $model->domainInfo?->client ?? null;
            $clientName = $client?->name ?? null;
            if ($clientName) try { $clientName = \App\Services\CryptService::decryptData($clientName) ?? $clientName; } catch (\Exception $e) {}

            $productName = $model->product?->name ?? null;
            try { $productName = \App\Services\CryptService::decryptData($productName) ?? $productName; } catch (\Exception $e) {}

            $vendorName = $model->vendor?->name  ?? null;
            try { $vendorName = \App\Services\CryptService::decryptData($vendorName) ?? $vendorName; } catch (\Exception $e) {}

            $data = [
                'id' => $model->id,
                'domain_name' => $decryptedDomain ?? 'N/A',
                'domain_id' => $model->domain_id,
                'client_name' => $clientName ?? 'N/A',
                'client_id' => $model->domainInfo?->client_id ?? $model->client_id,
                'product_name' => $productName,
                'product_id' => $model->product_id,
                'vendor_name' => $vendorName,
                'vendor_id' => $model->vendor_id,
                'amount' => (float)$model->amount,
                'renewal_date' => $model->renewal_date,
                'expiry_date' => $model->renewal_date,
                'days_left' => $model->days_left,
                'days_to_delete' => $model->days_to_delete,
                'status' => $model->status,
                'remarks' => $model->remarks,
                'last_updated' => DateFormatterService::format($model->updated_at),
                'updated_at_formatted' => DateFormatterService::format($model->updated_at),
                'created_at_formatted' => DateFormatterService::format($model->created_at),
                'updated_at' => $model->updated_at,
                'created_at' => $model->created_at,
            ];

            $this->logActivity('created', (object)$data);

            return response()->json([
               'status'  => true,
               'success' => true,
               'message' => 'SSL Record created successfully',
               'data'    => $data
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
        $record = SSL::find($id);
        if (!$record) return response()->json(['success' => false, 'message' => 'Not found'], 404);

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
            // Track before encrypting
            \App\Services\RemarkHistoryService::trackChange('SSL', $record->id, $record->remarks, $data['remarks']);
            $data['remarks'] = \App\Services\CryptService::encryptData($data['remarks']);
        }

        $oldData = clone $record;
        $record->update($data);

        // Sync client_id to the domain table so derived relations work
        if ($record->domain_id && $record->client_id) {
            \App\Models\Domain::where('id', $record->domain_id)->update(['client_id' => $record->client_id]);
        }

        $record->refresh()->load(['product', 'vendor', 'domainInfo.client'])->loadCount('remarkHistories');
        
        // Decrypt domain name for response
        $rawDomain = $record->domainInfo?->name ?? null;
        $decryptedDomain = $rawDomain;
        if ($rawDomain) try { $decryptedDomain = \App\Services\CryptService::decryptData($rawDomain); } catch (\Exception $e) {}

        // Derive client name from domain relation
        $client = $record->domainInfo?->client ?? null;
        $clientName = $client?->name ?? null;
        if ($clientName) try { $clientName = \App\Services\CryptService::decryptData($clientName) ?? $clientName; } catch (\Exception $e) {}

        $record->client_name  = $clientName;
        $record->product_name = $record->product?->name ?? null;
        try { $record->product_name = \App\Services\CryptService::decryptData($record->product_name) ?? $record->product_name; } catch (\Exception $e) {}
        
        $record->vendor_name  = $record->vendor?->name  ?? null;
        try { $record->vendor_name = \App\Services\CryptService::decryptData($record->vendor_name) ?? $record->vendor_name; } catch (\Exception $e) {}
        
        $record->domain_name  = $decryptedDomain;
        $decodedRemarks       = \App\Services\CryptService::decryptData($record->remarks);
        $record->remarks      = \App\Services\CryptService::decryptData($decodedRemarks); // Recovery
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
        $resp['client_id']    = $record->domainInfo?->client_id ?? null;
        
        $resp['last_updated'] = DateFormatterService::format($record->updated_at);
        $resp['updated_at_formatted'] = DateFormatterService::format($record->updated_at);
        $resp['created_at_formatted'] = DateFormatterService::format($record->created_at);
        // updated_at / created_at kept as raw ISO from toArray() — do NOT overwrite

        $this->logActivity('updated', $record, $oldData->toArray(), $record->toArray());

        return response()->json([
            'status'  => true,
            'success' => true,
            'message' => 'SSL Record updated successfully',
            'data' => $resp
        ]);
    }

    public function destroy($id)
    {
        $record = SSL::find($id);
        if (!$record) return response()->json(['success' => false, 'message' => 'Not found'], 404);

        $this->logActivity('deleted', $record);
        $record->delete();

        return response()->json([
            'status'  => true,
            'success' => true,
            'message' => 'SSL Record deleted successfully'
        ]);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file']);
        
        try {
            $clientId = $request->input('client_id') ?? null;
            $importer = new \App\Imports\SSLImport($clientId);
            $result = \App\Services\ImportService::handleImport($request, $importer, 'SSL');
            
            $history          = $result['history'];
            $importer         = $result['importer'];
            $duplicateFile    = $result['duplicate_file'] ?? null;
            $duplicateFileUrl = $result['duplicate_file_url'] ?? null;

            $inserted   = $importer->inserted;
            $duplicates = $importer->duplicates;
            $failed     = $importer->failed;
            $errors     = $importer->errors;

            // Fetch the most recently inserted rows to return to the frontend
            $latestRows = $inserted > 0
                ? SSL::with(['product', 'client', 'vendor', 'domainInfo'])
                    ->orderBy('id', 'desc')
                    ->take($inserted)
                    ->get()
                    ->map(function ($item) {
                        $rawDomain = $item->domainInfo?->name;
                        $decryptedDomain = $rawDomain;
                        if ($rawDomain) try { $decryptedDomain = \App\Services\CryptService::decryptData($rawDomain) ?? $rawDomain; } catch (\Exception $e) {}

                        $pName = $item->product?->name;
                        if ($pName) try { $pName = \App\Services\CryptService::decryptData($pName) ?? $pName; } catch (\Exception $e) {}

                        $cName = $item->client?->name;
                        if ($cName) try { $cName = \App\Services\CryptService::decryptData($cName) ?? $cName; } catch (\Exception $e) {}

                        $vName = $item->vendor?->name;
                        if ($vName) try { $vName = \App\Services\CryptService::decryptData($vName) ?? $vName; } catch (\Exception $e) {}

                        $item->domain_name = $decryptedDomain;
                        $item->product_name = $pName;
                        $item->client_name = $cName;
                        $item->vendor_name = $vName;
                        $item->expiry_date = $item->renewal_date;
                        return $item;
                    })
                    ->reverse()
                    ->values()
                : collect();

            $msg = "$inserted record(s) imported.";
            if ($duplicates > 0) $msg .= " $duplicates duplicate(s) skipped.";
            if ($failed > 0)     $msg .= " $failed row(s) failed.";

            return response()->json([
                'success'             => true,
                'message'             => $msg,
                'inserted'            => $inserted,
                'duplicate'           => $duplicates,
                'duplicates'          => $duplicates,
                'failed'              => $failed,
                'errors'              => $errors,
                'inserted_data'       => $latestRows,
                'history'             => $history,
                'duplicate_file'      => $duplicateFile,
                'duplicate_file_url'  => $duplicateFileUrl,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
