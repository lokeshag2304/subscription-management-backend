<?php

namespace App\Http\Controllers;

use App\Models\Email;
use App\Models\Product;
use App\Models\Superadmin;
use App\Models\Vendor;
use App\Models\Domain;
use App\Models\ImportHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use App\Services\ActivityLogger;
use App\Services\ClientScopeService;
use App\Services\CryptService;
use App\Services\DateFormatterService;
use App\Services\GracePeriodService;
use App\Traits\DataNormalizer;

use App\Services\AuditFileService;

class EmailController extends Controller
{
    use DataNormalizer;
    public function logExport(Request $request)
    {
        $validated = $request->validate([
            'total_records' => 'required|integer',
            'data_snapshot' => 'nullable'
        ]);

        $user = Auth::user();
        $userName = $user ? (CryptService::decryptData($user->name) ?? $user->name) : 'System';
        $userId = $user->id ?? $request->input('s_id') ?? 1;

        $role = $user ? ($user->role ?? (isset($user->login_type) ? ($user->login_type === 1 ? 'Superadmin' : ($user->login_type === 3 ? 'Client' : 'User')) : 'Unknown')) : 'System';

        try {
            ActivityLogger::exported($userId, 'Email', $validated['total_records']);
        } catch (\Exception $e) {}

        try {
            $history = AuditFileService::logExport(
                $userId,
                'Email',
                $validated['total_records'],
                $request->input('data_snapshot')
            );
            
            if ($history) {
                $history->update(['imported_by' => $userName, 'role' => $role]);
            }
        } catch (\Exception $e) {
            $history = \App\Models\ImportHistory::create([
                'user_id' => $userId,
                'module_name' => 'Email',
                'action' => 'EXPORT',
                'file_name' => 'Email_Export_' . date('Ymd_His') . '.csv',
                'imported_by' => $userName,
                'role' => $role,
                'successful_rows' => $validated['total_records'],
                'client_id' => $userId
            ]);
        }
        return Response::json(['success' => true, 'data' => $history]);
    }

    protected array $productIds = [45];

    private function formatDate($date)
    {
        return self::robustParseDate($date);
    }

    private function calculateFields(&$data)
    {
        $today = Carbon::now()->startOfDay();
        
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

    private function logActivity($action, $record, $oldData = null, $newData = null, $message = null)
    {
        try {
            $user = auth()->user() ?: (object)['id' => request()->input('auth_user_id') ?: 1, 'name' => 'Admin', 'role' => 'Superadmin'];
            
            $standardize = function($data) use ($record) {
                if (!$data) return $data;
                $arr = is_array($data) ? $data : (method_exists($data, 'toArray') ? $data->toArray() : (array)$data);
                
                $decrypt = function($val) {
                    if (!$val || !is_string($val)) return $val;
                    try { return CryptService::decryptData($val) ?? $val; } catch (\Exception $e) { return $val; }
                };
 
                $res = [];
                // 1. Identity Fields
                $res['Product']  = $decrypt($arr['Product']  ?? $arr['product_name'] ?? optional($record->product)->name  ?? 'N/A');
                $res['Client']   = $decrypt($arr['Client']   ?? $arr['client_name']  ?? optional($record->client)->name   ?? 'N/A');
                $res['Domain']   = $arr['Domain']   ?? $arr['domain_name']  ?? optional($record->domainMaster)->domain_name ?? 'N/A';
                $res['Vendor']   = $decrypt($arr['Vendor']   ?? $arr['vendor_name']  ?? optional($record->vendor)->name   ?? 'N/A');
                
                // 2. Values & Dates
                if (isset($arr['renewal_date']))  $res['Renewal Date'] = $arr['renewal_date'];
                if (isset($arr['quantity']))      $res['Quantity']     = $arr['quantity'];
                if (isset($arr['bill_type']))     $res['Bill Type']    = $arr['bill_type'];
                if (isset($arr['amount']))        $res['Amount']       = (isset($arr['currency']) ? $arr['currency'] . ' ' : 'INR ') . number_format((float)$arr['amount'], 2);
                if (isset($arr['days_left']))     $res['DaysToExpire'] = $arr['days_left'];
                if (isset($arr['deletion_date'])) $res['DeletionDate'] = $arr['deletion_date'];
                if (isset($arr['days_to_delete']))$res['DaystoDelete'] = $arr['days_to_delete'];
                if (isset($arr['grace_period']))  $res['GracePeriod']  = ($arr['grace_period'] ?? 0) . ' days';
                if (isset($arr['due_date']))      $res['DueDate']       = $arr['due_date'];
                
                // 3. Status & Remarks
                if (isset($arr['status']))        $res['Status']       = (int)$arr['status'] === 1 ? 'Active' : 'Inactive';
                if (isset($arr['remarks']))       $res['Remarks']      = $decrypt($arr['remarks']);
 
                return $res;
            };
 
            $actionType = strtoupper($action === 'created' ? 'CREATE' : ($action === 'deleted' ? 'DELETE' : $action));
            
            ActivityLogger::logActivity(
                $user, 
                $actionType, 
                'Email', 
                'emails', 
                $record->id, 
                $standardize($oldData), 
                $standardize($newData), 
                $message, 
                request()
            );
        } catch (\Exception $e) {}
    }

    public function index(Request $request)
    {
        $limit  = $request->query('limit', 100);
        $offset = $request->query('offset', 0);
        $search = $request->query('search', '');

        $query = Email::select([
            'id', 'product_id', 'client_id', 'vendor_id', 'domain_master_id',
            'quantity', 'bill_type', 'start_date',
            'amount', 'renewal_date', 'deletion_date', 'status', 
            'remarks', 'created_at', 'updated_at',
            'grace_period', 'due_date'
        ])
        ->with([
            'product:id,name', 
            'client:id,name', 
            'vendor:id,name',
            'domainMaster:id,domain_name'
        ])
        ->withCount('remarkHistories');

        // ── CLIENT SCOPE: filter to only this client's records ──
        ClientScopeService::applyScope($query, $request);

        // ── CASCADING FILTER ──
        $filterBy    = $request->query('filter_by');    // domain | client | product
        $filterValue = $request->query('filter_value');
        if ($filterBy && $filterValue) {
            if ($filterBy === 'domain') {
                $dmIds = \App\Models\DomainName::where('domain_name', $filterValue)->pluck('id');
                $query->whereIn('domain_master_id', $dmIds);
            } elseif ($filterBy === 'client') {
                $cIds = \App\Models\Superadmin::pluck('name', 'id')
                    ->filter(function($name) use ($filterValue) {
                        try { $dec = \App\Services\CryptService::decryptData($name) ?? $name; } catch (\Exception $e) { $dec = $name; }
                        return strtolower(trim($dec)) === strtolower(trim($filterValue));
                    })->keys();
                $query->whereIn('client_id', $cIds);
            } elseif ($filterBy === 'product') {
                $pIds = \App\Models\Product::pluck('name', 'id')
                    ->filter(function($name) use ($filterValue) {
                        try { $dec = \App\Services\CryptService::decryptData($name) ?? $name; } catch (\Exception $e) { $dec = $name; }
                        return strtolower(trim($dec)) === strtolower(trim($filterValue));
                    })->keys();
                $query->whereIn('product_id', $pIds);
            }
        }

        if (!empty($search)) {
            $searchLow = strtolower($search);
            $lk = '%' . $searchLow . '%';

            $dateMatch = null;
            if (strlen($search) >= 3) {
                try {
                    $date = \Illuminate\Support\Carbon::parse(str_replace(['/', '.'], '-', $search));
                    $dateMatch = $date->format('Y-m-d');
                } catch (\Exception $e) {}
            }
            
            $dmIds = \App\Models\DomainName::where('domain_name', 'LIKE', '%' . $searchLow . '%')->pluck('id');

            $pIds = \App\Models\Product::pluck('name', 'id')
                ->filter(function($name) use ($searchLow) {
                    try { $dec = \App\Services\CryptService::decryptData($name) ?? $name; } catch (\Exception $e) { $dec = $name; }
                    return str_contains(strtolower($dec), $searchLow);
                })->keys();

            $cIds = \App\Models\Superadmin::pluck('name', 'id')
                ->filter(function($name) use ($searchLow) {
                    try { $dec = \App\Services\CryptService::decryptData($name) ?? $name; } catch (\Exception $e) { $dec = $name; }
                    return str_contains(strtolower($dec), $searchLow);
                })->keys();

            $vIds = \App\Models\Vendor::pluck('name', 'id')
                ->filter(function($name) use ($searchLow) {
                    try { $dec = \App\Services\CryptService::decryptData($name) ?? $name; } catch (\Exception $e) { $dec = $name; }
                    return str_contains(strtolower($dec), $searchLow);
                })->keys();

            $query->where(function($q) use ($pIds, $cIds, $vIds, $dmIds, $lk, $dateMatch) {
                $q->whereIn('product_id', $pIds)
                  ->orWhereIn('client_id', $cIds)
                  ->orWhereIn('vendor_id', $vIds)
                  ->orWhereIn('domain_master_id', $dmIds)
                  ->orWhere('amount', 'LIKE', $lk)
                  ->orWhere('renewal_date', 'LIKE', $lk)
                  ->orWhere('deletion_date', 'LIKE', $lk)
                  ->orWhere('due_date', 'LIKE', $lk);

                if ($dateMatch) {
                    $q->orWhere('renewal_date', 'LIKE', '%' . $dateMatch . '%')
                      ->orWhere('deletion_date', 'LIKE', '%' . $dateMatch . '%')
                      ->orWhere('due_date', 'LIKE', '%' . $dateMatch . '%');
                }
            });
        }

        // ── NEW: ALL IDs FETCH ──
        if ($request->query('all_ids')) {
            return Response::json($query->pluck('id'));
        }

        $perPage = (int)$request->query('rowsPerPage', $request->query('limit', 20));
        if ($perPage < 1) $perPage = 20;

        $currentPage = $request->query('page');
        if ($currentPage !== null) {
            $currentPage = (int)$currentPage;
        } else {
            $offset = (int)$request->query('offset', 0);
            $currentPage = (int)(($offset / $perPage) + 1);
        }
        if ($currentPage < 1) $currentPage = 1;

        $tableName = $query->getModel()->getTable();
        $sortBy = $request->query('sortBy');
        
        switch ($sortBy) {
            case 'date_asc':
                $query->orderBy("{$tableName}.created_at", 'asc');
                break;
            case 'name_asc':
                $query->leftJoin('domain_master', "{$tableName}.domain_master_id", '=', 'domain_master.id')
                      ->orderBy('domain_master.domain_name', 'asc')->select("{$tableName}.*");
                break;
            case 'name_desc':
                $query->leftJoin('domain_master', "{$tableName}.domain_master_id", '=', 'domain_master.id')
                      ->orderBy('domain_master.domain_name', 'desc')->select("{$tableName}.*");
                break;
            case 'amount_asc':
                $query->orderBy("{$tableName}.amount", 'asc');
                break;
            case 'amount_desc':
                $query->orderBy("{$tableName}.amount", 'desc');
                break;
            case 'days_asc':
                $query->orderBy("{$tableName}.renewal_date", 'asc');
                break;
            case 'days_desc':
                $query->orderBy("{$tableName}.renewal_date", 'desc');
                break;
            case 'date_desc':
            default:
                if (empty($sortBy) && $request->query('orderBy')) {
                    $col = $request->query('orderBy');
                    if (!str_contains($col, '.')) $col = "{$tableName}.{$col}";
                    $query->orderBy($col, $request->query('order', 'desc'));
                } else {
                    $query->orderBy("{$tableName}.created_at", 'desc');
                }
                break;
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $currentPage);
        $total = $paginator->total();

        $records = \Illuminate\Support\Collection::make($paginator->items())->map(function ($item) {
                $today = Carbon::now()->startOfDay();
                $item->days_left = $item->renewal_date ? $today->diffInDays(Carbon::parse($item->renewal_date)->startOfDay(), false) : null;
                $item->days_to_delete = $item->deletion_date ? $today->diffInDays(Carbon::parse($item->deletion_date)->startOfDay(), false) : null;
                
                $clientName = optional($item->client)->name;
                try { $clientName = CryptService::decryptData($clientName) ?? $clientName; } catch (\Exception $e) {}
                
                $productName = optional($item->product)->name;
                try { $productName = CryptService::decryptData($productName) ?? $productName; } catch (\Exception $e) {}
                
                $vendorName = optional($item->vendor)->name;
                try { $vendorName = CryptService::decryptData($vendorName) ?? $vendorName; } catch (\Exception $e) {}
                
                $remarks = $item->remarks;
                try { $remarks = CryptService::decryptData($remarks) ?? $remarks; } catch (\Exception $e) {}

                $data = $item->toArray();
                $data['domain_name'] = $item->domainMaster->domain_name ?? '-';
                $data['days_left'] = $item->days_left;
                $data['days_to_delete'] = $item->days_to_delete;
                $data['client_name'] = $clientName;
                $data['product_name'] = $productName;
                $data['vendor_name'] = $vendorName;
                $data['has_remark_history'] = $item->remark_histories_count > 0;
                $data['remarks'] = $remarks;
                $data['grace_period'] = $item->grace_period ?? 0;
                $data['due_date'] = $item->due_date;

                $data['last_updated'] = DateFormatterService::formatDateTime($item->updated_at);
                $data['updated_at_formatted'] = DateFormatterService::formatDateTime($item->updated_at);
                $data['created_at_formatted'] = DateFormatterService::formatDateTime($item->created_at ?? $item->updated_at);
                
                return $data;
            });

        return Response::json([
            'success' => true,
            'data' => $records,
            'total' => $total
        ]);
    }

    public function store(Request $request)
    {
        try {
            ClientScopeService::enforceClientId($request);

            $request->merge([
               'domain_master_id' => $request->domain_master_id ?? data_get($request->domain_master_id, 'value'),
               'product_id'       => $request->product_id      ?? data_get($request->product, 'value'),
               'client_id'        => $request->client_id       ?? data_get($request->client, 'value'),
               'vendor_id'        => $request->vendor_id       ?? data_get($request->vendor, 'value'),
               'renewal_date'     => $request->renewal_date ?? $request->expiry_date,
            ]);

            if ($request->has('amount')) {
                $request->merge(['amount' => str_replace([',', ' '], '', (string)$request->amount)]);
            }

            $validated = Validator::make($request->all(), [
               'domain_master_id' => 'required|exists:domain_master,id',
               'product_id'       => 'required|exists:products,id',
               'client_id'        => 'required',
               'vendor_id'        => 'required|exists:vendors,id',
               'amount'           => 'required|numeric|min:0.01',
               'renewal_date'     => 'required',
               'deletion_date'    => 'required',
            ])->validate();

            if ($request->renewal_date) $request->merge(['renewal_date' => \Carbon\Carbon::parse($request->renewal_date)->format('Y-m-d')]);
            if ($request->deletion_date) $request->merge(['deletion_date' => \Carbon\Carbon::parse($request->deletion_date)->format('Y-m-d')]);

            $today = now()->startOfDay();

            // ── DUPLICATE CHECK ──
            $duplicateExists = Email::where('domain_master_id', $request->domain_master_id)
                ->where('client_id', $request->client_id)
                ->where('renewal_date', $request->renewal_date)
                ->exists();

            if ($duplicateExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate entry: This domain already exists for the same client and renewal date'
                ], 422);
            }

            $model = Email::create([
               'domain_master_id' => $request->domain_master_id,
               'product_id'    => $request->product_id,
               'client_id'     => $request->client_id,
               'vendor_id'     => $request->vendor_id,
               'quantity'      => $request->quantity ?? 1,
               'bill_type'     => $request->bill_type,
               'start_date'    => $request->start_date,
               'amount'        => $request->amount ?? 0,
               'renewal_date'  => $request->renewal_date,
               'deletion_date' => $request->deletion_date,
               'days_left'     => $request->renewal_date ? $today->diffInDays($request->renewal_date, false) : null,
               'days_to_delete'=> $request->deletion_date ? $today->diffInDays($request->deletion_date, false) : null,
               'grace_period'  => $request->grace_period ?? 0,
               'status'        => $request->status ?? 1,
               'remarks'       => $request->remarks ? \App\Services\CryptService::encryptData($request->remarks) : null
            ]);

            \App\Services\GracePeriodService::syncModel($model);
            $model->save();

            $model->refresh()->load(['domainMaster', 'product', 'client', 'vendor']);
            
            $clientName = $model->client->name  ?? null;
            try { $clientName = CryptService::decryptData($clientName) ?? $clientName; } catch (\Exception $e) {}
            
            $productName = $model->product->name ?? null;
            try { $productName = CryptService::decryptData($productName) ?? $productName; } catch (\Exception $e) {}
            
            $vendorName = $model->vendor->name  ?? null;
            try { $vendorName = CryptService::decryptData($vendorName) ?? $vendorName; } catch (\Exception $e) {}
            
            $resp = $model->toArray();
            $resp['domain_name']  = $model->domainMaster->domain_name ?? 'N/A';
            $resp['client_name']  = $clientName;
            $resp['product_name'] = $productName;
            $resp['vendor_name']  = $vendorName;
            $resp['expiry_date']  = $model->renewal_date;
            try { $resp['remarks'] = CryptService::decryptData($model->remarks) ?? $model->remarks; } catch (\Exception $e) {}
            $resp['last_updated'] = DateFormatterService::formatDateTime($model->updated_at);
            $resp['updated_at_formatted'] = DateFormatterService::formatDateTime($model->updated_at);
            $resp['created_at_formatted'] = DateFormatterService::formatDateTime($model->created_at);
            
            $this->logActivity('created', $model, null, $resp);

            return response()->json([ 'success' => true, 'data' => $resp ], 200);

        } catch (\Exception $e) {
            return Response::json([ 'success' => false, 'message' => $e->getMessage() ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $record = Email::find($id);
        if (!$record) return response()->json(['success' => false, 'message' => 'Not found'], 404);

        ClientScopeService::assertOwnership($record, $request);

        $data = $request->all();
        foreach ($data as $key => $value) if ($value === '') $data[$key] = null;

        if (isset($data['amount'])) {
            $data['amount'] = str_replace([',', ' '], '', (string)$data['amount']);
            if (!is_numeric($data['amount'])) {
                return response()->json(['success' => false, 'message' => 'Invalid Amount format'], 422);
            }
        }

        if (array_key_exists('expiry_date', $data) && !array_key_exists('renewal_date', $data)) $data['renewal_date'] = $data['expiry_date'];
        if (array_key_exists('valid_till', $data) && !array_key_exists('renewal_date', $data)) $data['renewal_date'] = $data['valid_till'];
        if (array_key_exists('grace_end_date', $data) && !array_key_exists('due_date', $data)) $data['due_date'] = $data['grace_end_date'];

        if (array_key_exists('renewal_date', $data) && $data['renewal_date'] === null) {
            return response()->json(['success' => false, 'message' => 'Validity Date cannot be empty.'], 422);
        }
        if (array_key_exists('deletion_date', $data) && $data['deletion_date'] === null) {
            return response()->json(['success' => false, 'message' => 'Deletion Date cannot be empty.'], 422);
        }
        if (array_key_exists('due_date', $data) && $data['due_date'] === null) {
            return response()->json(['success' => false, 'message' => 'Grace Period Date cannot be empty.'], 422);
        }

        if (isset($data['renewal_date'])) $data['renewal_date'] = $this->formatDate($data['renewal_date']);
        if (isset($data['deletion_date'])) $data['deletion_date'] = $this->formatDate($data['deletion_date']);
        if (isset($data['due_date'])) $data['due_date'] = $this->formatDate($data['due_date']);

        $this->calculateFields($data);

        // ── DUPLICATE CHECK ──
        $duplicateExists = Email::where('domain_master_id', $data['domain_master_id'] ?? $record->domain_master_id)
            ->where('client_id', $data['client_id'] ?? $record->client_id)
            ->where('renewal_date', $data['renewal_date'] ?? $record->renewal_date)
            ->where('id', '!=', $id)
            ->exists();

        if ($duplicateExists) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate entry: This domain already exists for the same client and renewal date'
            ], 422);
        }

        \App\Services\RemarkHistoryService::logUpdate('Email', $record, $data);

        if (isset($data['remarks'])) $data['remarks'] = \App\Services\CryptService::encryptData($data['remarks']);

        $oldData = clone $record;
        $record->update($data);
        
        \App\Services\GracePeriodService::syncModel($record);
        $record->save();
        
        $record->refresh()->load(['domainMaster', 'product', 'client', 'vendor'])->loadCount('remarkHistories');
        
        $clientName = $record->client->name ?? null;
        try { $clientName = CryptService::decryptData($clientName) ?? $clientName; } catch (\Exception $e) {}
        
        $productName = $record->product->name ?? null;
        try { $productName = CryptService::decryptData($productName) ?? $productName; } catch (\Exception $e) {}
        
        $vendorName = $record->vendor->name  ?? null;
        try { $vendorName = CryptService::decryptData($vendorName) ?? $vendorName; } catch (\Exception $e) {}
        
        $remarks = $record->remarks;
        try { $remarks = CryptService::decryptData($remarks) ?? $remarks; } catch (\Exception $e) {}
        
        $resp = $record->toArray();
        $resp['domain_name']  = $record->domainMaster->domain_name ?? 'N/A';
        $resp['client_name']  = $clientName;
        $resp['product_name'] = $productName;
        $resp['vendor_name']  = $vendorName;
        $resp['remarks']      = $remarks;
        $resp['expiry_date']  = $record->renewal_date;
        $resp['has_remark_history'] = $record->remark_histories_count > 0;
        $resp['last_updated'] = DateFormatterService::formatDateTime($record->updated_at);
        $resp['updated_at_formatted'] = DateFormatterService::formatDateTime($record->updated_at);
        $resp['created_at_formatted'] = DateFormatterService::formatDateTime($record->created_at);
        
        $this->logActivity('UPDATE', $record, $oldData->toArray(), $resp);

        return Response::json([
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

        // Enrich data with resolved names before logging deletion
        $logData = $record->toArray();
        $pName = optional($record->product)->name;
        $cName = optional($record->client)->name;
        $vName = optional($record->vendor)->name;
        try { $pName = \App\Services\CryptService::decryptData($pName) ?? $pName; } catch (\Exception $e) {}
        try { $cName = \App\Services\CryptService::decryptData($cName) ?? $cName; } catch (\Exception $e) {}
        try { $vName = \App\Services\CryptService::decryptData($vName) ?? $vName; } catch (\Exception $e) {}
        
        $logData['product'] = $pName;
        $logData['client']  = $cName;
        $logData['vendor']  = $vName;
        $logData['domain']  = $record->domainMaster->domain_name ?? '-';

        $this->logActivity('DELETE', $record, $logData, null, "Email Record Deleted");
        $record->delete();

        return Response::json([
            'success' => true,
            'message' => 'Email Record deleted successfully'
        ]);
    }

    use \App\Traits\NativeXlsxParser;

    public function import(Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json(['success' => false, 'message' => 'File not received'], 400);
        }

        try {
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());
            
            if ($extension === 'xlsx') {
                $content = $this->parseXlsxToCsvString($file->getRealPath());
            } else {
                $content = file_get_contents($file->getRealPath());
            }
            
            if (!$content) return response()->json(['success' => false, 'message' => 'Failed to read file content'], 400);

            $handle = fopen('php://temp', 'r+');
            fwrite($handle, $content);
            rewind($handle);
            
            // Clean BOM and detect headers
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($handle);

            $suffixCache = \Illuminate\Support\Facades\DB::table('suffix_masters')->pluck('suffix')->toArray();
            \Illuminate\Support\Facades\Log::info("Email Import Suffix Cache: " . implode(', ', $suffixCache));
            
            $inserted = 0; $failed = 0; $duplicates = 0; $duplicateRows = []; $errors = [];

            // 1. Warm caches (decryption-aware)
            $productCache = []; $clientCache = []; $vendorCache = []; $domainMasterCache = [];
            DB::table('products')->get(['id', 'name'])->each(function ($p) use (&$productCache) {
                try { $name = CryptService::decryptData($p->name); } catch (\Throwable $e) { $name = $p->name; }
                $productCache[strtolower(trim($name ?? ''))] = (int)$p->id;
            });
            DB::table('superadmins')->get(['id', 'name'])->each(function ($c) use (&$clientCache) {
                try { $name = CryptService::decryptData($c->name); } catch (\Throwable $e) { $name = $c->name; }
                $clientCache[strtolower(trim($name ?? ''))] = (int)$c->id;
            });
            DB::table('vendors')->get(['id', 'name'])->each(function ($v) use (&$vendorCache) {
                try { $name = CryptService::decryptData($v->name); } catch (\Throwable $e) { $name = $v->name; }
                $vendorCache[strtolower(trim($name ?? ''))] = (int)$v->id;
            });
            DB::table('domain_master')->get(['id', 'domain_name'])->each(function ($dm) use (&$domainMasterCache) {
                $domainMasterCache[strtolower(trim($dm->domain_name ?? ''))] = (int)$dm->id;
            });

            $clientIdFromRequest = $request->input('client_id');

            if ($handle !== FALSE) {
                $firstRow = fgetcsv($handle, 1000, ',');
                if ($firstRow) {
                    $headerMod = array_map(function($h) { 
                        return str_replace([' ', '-', '/', '(', ')'], '_', strtolower(trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $h ?? '')))); 
                    }, $firstRow);

                    // Strict Header Validation
                    $allowedHeaders = [
                        'domain', 'domain_name', 'url', 'domain_id',
                        'product', 'product_id', 'product_name', 'name',
                        'client', 'client_id', 'customer', 'client_name',
                        'vendor', 'vendor_id', 'vendor_name',
                        'amount', 'price', 'cost', 'total_amount',
                        'currency',
                        'renewal_date', 'renewal', 'expiry_date', 'expiry', 'valid_till',
                        'deletion_date', 'deletion', 'delete_date', 'grace_period_date', 'due_date', 'grace_end_date',
                        'status', 'active', 'quantity', 'qty', 'count', 'bill_type', 'billing_type', 'bill', 'start_date', 'start',
                        'remarks', 'remark', 'note', 'notes',
                        'id', 'last_updated'
                    ];
                    $invalidHeaders = array_diff($headerMod, $allowedHeaders);
                    if (!empty($invalidHeaders)) {
                        return response()->json([
                            'success' => false,
                            'message' => "Invalid columns found: " . implode(', ', $invalidHeaders) . ". Please check with sample file."
                        ], 422);
                    }
                    
                    $map = array_flip($headerMod);
                    
                    $idxDomain    = $map['domain'] ?? $map['domain_name'] ?? $map['url'] ?? $map['domain_id'] ?? -1;
                    $idxClient    = $map['client'] ?? $map['client_id'] ?? $map['customer'] ?? $map['client_name'] ?? -1;
                    $idxProduct   = $map['product'] ?? $map['product_id'] ?? $map['product_name'] ?? $map['name'] ?? -1;
                    $idxVendor    = $map['vendor'] ?? $map['vendor_id'] ?? $map['vendor_name'] ?? -1;
                    $idxAmount    = $map['amount'] ?? $map['price'] ?? $map['cost'] ?? $map['total_amount'] ?? -1;
                    $idxStartDate = $map['start_date'] ?? $map['start'] ?? -1;
                    $idxRenewal   = $map['renewal_date'] ?? $map['renewal'] ?? $map['expiry_date'] ?? $map['expiry'] ?? $map['valid_till'] ?? -1;
                    $idxDeletion  = $map['deletion_date'] ?? $map['deletion'] ?? $map['delete_date'] ?? $map['grace_period_date'] ?? $map['due_date'] ?? $map['grace_end_date'] ?? -1;
                    $idxStatus    = $map['status'] ?? $map['active'] ?? -1;
                    $idxRemarks   = $map['remarks'] ?? $map['remark'] ?? $map['note'] ?? $map['notes'] ?? -1;
                    $idxQuantity  = $map['quantity'] ?? $map['qty'] ?? $map['count'] ?? -1;
                    $idxBillType  = $map['bill_type'] ?? $map['billing_type'] ?? $map['bill'] ?? -1;

                    $mandatoryIndices = [
                        'Domain' => $idxDomain, 
                        'Product' => $idxProduct,
                        'Client' => $idxClient, 
                        'Vendor' => $idxVendor,
                        'Renewal Date' => $idxRenewal,
                        'Deletion Date' => $idxDeletion,
                        'Quantity' => $idxQuantity,
                        'Amount' => $idxAmount
                    ];

                    $forceImport = $request->input('force_import') === 'true' || $request->input('force_import') === true;
                    if (!$forceImport) {
                        $rowNum = 1;
                        $issues = [];
                        while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                            $rowNum++;
                            if (empty(array_filter($data))) continue;
                            
                            $missing = [];
                            foreach ($mandatoryIndices as $field => $idx) {
                                $val = ($idx !== -1 && isset($data[$idx])) ? trim((string)$data[$idx]) : '';
                                if ($val === '') {
                                    $missing[] = $field;
                                } else {
                                    // Format checks
                                    if ($field === 'Renewal Date') {
                                        if (!self::robustParseDate($val)) {
                                            $missing[] = "Renewal Date (invalid format: '$val')";
                                        }
                                    }
                                    if ($field === 'Amount') {
                                        $cleanAmount = str_replace([',', ' '], '', $val);
                                        if (!is_numeric($cleanAmount)) {
                                            $missing[] = "Amount (invalid number: '$val')";
                                        }
                                    }
                                }
                            }

                            // ── DOMAIN VALIDATION PASS ──
                            $domainVal = ($idxDomain !== -1 && isset($data[$idxDomain])) ? trim((string)$data[$idxDomain]) : '';
                            if ($domainVal !== '') {
                                $domainLower = strtolower($domainVal);
                                if (!str_contains($domainLower, '.')) {
                                    $missing[] = "Domain (invalid format: '$domainVal'. Must contain a dot)";
                                } else {
                                    $hasValidSuffix = false;
                                    foreach ($suffixCache as $sfx) {
                                        if (str_ends_with($domainLower, '.' . ltrim($sfx, '.'))) {
                                            $hasValidSuffix = true;
                                            break;
                                        }
                                    }
                                    if (!$hasValidSuffix) {
                                        $missing[] = "Domain (invalid suffix: '$domainVal'. Suffix not in Suffix Master)";
                                    }
                                }
                            }

                            // Optional Deletion Date check
                            if ($idxDeletion !== -1 && isset($data[$idxDeletion])) {
                                $dVal = trim((string)$data[$idxDeletion]);
                                if ($dVal !== '' && $dVal !== '--' && $dVal !== 'N/A' && !self::robustParseDate($dVal)) {
                                    $missing[] = "Deletion Date (invalid format: '$dVal')";
                                }
                            }
                            
                            if (!empty($missing)) {
                                $issues[] = ['row' => $rowNum, 'missing_fields' => $missing];
                            }
                        }
                        
                        if (!empty($issues)) {
                            fclose($handle);
                            $user = Auth::user();
                            $history = \App\Models\ImportHistory::create([
                                'module_name' => 'Email', 
                                'action' => 'IMPORT', 
                                'file_name' => $file->getClientOriginalName(), 
                                'imported_by' => $user->name ?? 'System / Admin', 
                                'successful_rows' => 0, 
                                'failed_rows' => count($issues), 
                                'duplicates_count' => 0,
                                'data_snapshot' => json_encode($issues)
                            ]);
                            \App\Services\AuditFileService::storeImport($history, $file);

                            \App\Services\ActivityLogger::imported($user->id ?? 1, 'Email', 0, $history->id, count($issues), 0);

                            return response()->json([
                                'success' => false,
                                'requires_confirmation' => true,
                                'message' => 'Validation failed: Mandatory fields are missing in ' . count($issues) . ' rows.',
                                'issues' => $issues,
                                'history_id' => $history->id,
                                'total_affected' => count($issues)
                            ], 422);
                        }
                        rewind($handle);
                        $bom = fread($handle, 3);
                        if ($bom !== "\xEF\xBB\xBF") rewind($handle);
                        fgetcsv($handle, 1000, ','); // Skip header
                    }

                    while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                        try {
                            if (empty(array_filter($data))) continue;
                            
                            $parseDate = function($val) {
                                return self::robustParseDate($val);
                            };

                            $rawProduct  = trim($data[$idxProduct] ?? '');
                            $rawClient   = $idxClient !== -1 ? trim($data[$idxClient] ?? '') : '';
                            $rawDomain   = $idxDomain !== -1 ? trim($data[$idxDomain] ?? '') : 'default.com';
                            $rawVendor   = $idxVendor !== -1 ? trim($data[$idxVendor] ?? '') : '';
                            $rawAmount   = $idxAmount !== -1 ? ($data[$idxAmount] ?? 0) : 0;
                            $rawStartDate= $idxStartDate !== -1 ? ($data[$idxStartDate] ?? null) : null;
                            $rawRenewal  = $idxRenewal !== -1 ? ($data[$idxRenewal] ?? null) : null;
                            $rawDeletion = $idxDeletion !== -1 ? ($data[$idxDeletion] ?? null) : null;
                            $rawStatus   = $idxStatus !== -1 ? trim($data[$idxStatus] ?? 'Active') : 'Active';
                            $rawRemarks  = $idxRemarks !== -1 ? trim($data[$idxRemarks] ?? '') : '';
                            $rawQuantity = $idxQuantity !== -1 ? trim($data[$idxQuantity] ?? '1') : '1';
                            $rawBillType = $idxBillType !== -1 ? trim($data[$idxBillType] ?? 'yearly') : 'yearly';

                            if (!$rawProduct) $rawProduct = "Imported_" . ($idxProduct !== -1 ? "Col$idxProduct" : "General");
                            if (!$rawDomain) $rawDomain = 'default.com';

                            // Amount Strict Check
                            if ($rawAmount && !is_numeric(str_replace([',', ' '], '', (string)$rawAmount))) {
                                throw new \Exception("Invalid Amount format: " . $rawAmount);
                            }
                            $amount = (float)str_replace([',', ' '], '', (string)$rawAmount);

                            // Parse dates
                            $startDate    = $parseDate($rawStartDate);
                            $renewalDate  = $parseDate($rawRenewal);
                            $deletionDate = $parseDate($rawDeletion);

                            if (!$renewalDate && $rawRenewal) {
                                throw new \Exception("Invalid Renewal Date: " . $rawRenewal);
                            }

                            $status = (strtolower($rawStatus) === 'active' || $rawStatus === '1' || $rawStatus === '') ? 1 : 0;

                            // Resolve Domain
                            $domainLower = strtolower(trim($rawDomain));
                            if (!str_contains($domainLower, '.')) {
                                throw new \Exception("Invalid Domain format: '$rawDomain'. Must contain at least one dot.");
                            }
                            $hasValidSuffix = false;
                            foreach ($suffixCache as $sfx) {
                                if (str_ends_with($domainLower, '.' . ltrim($sfx, '.'))) {
                                    $hasValidSuffix = true;
                                    break;
                                }
                            }
                            if (!$hasValidSuffix) {
                                throw new \Exception("Invalid Domain: '$rawDomain'. Suffix not found in Suffix Master.");
                            }

                            $dId = $domainMasterCache[$domainLower] ?? null;
                            if (!$dId) {
                                $dId = DB::table('domain_master')->insertGetId(['domain_name' => trim($rawDomain), 'created_at' => now(), 'updated_at' => now()]);
                                $domainMasterCache[$domainLower] = $dId;
                            }

                            // Resolve Product
                            $pId = $productCache[strtolower(trim($rawProduct))] ?? null;
                            if (!$pId) {
                                $pId = DB::table('products')->insertGetId(['name' => \App\Services\CryptService::encryptData(trim($rawProduct)), 'created_at' => now(), 'updated_at' => now()]);
                                $productCache[strtolower(trim($rawProduct))] = $pId;
                            }

                            // Resolve Client
                            $cId = $clientIdFromRequest;
                            if (!$cId && $rawClient) {
                                $cId = $clientCache[strtolower(trim($rawClient))] ?? null;
                                if (!$cId) {
                                    $cId = DB::table('superadmins')->insertGetId([
                                        'name' => \App\Services\CryptService::encryptData(trim($rawClient)),
                                        'email' => strtolower(preg_replace('/[^a-z0-9]/', '', $rawClient)) . '+' . uniqid() . '@import.local',
                                        'password' => bcrypt(uniqid()),
                                        'login_type' => 3, 'status' => 1, 'created_at' => now(), 'updated_at' => now()
                                    ]);
                                    $clientCache[strtolower(trim($rawClient))] = $cId;
                                }
                            }
                            if (!$cId) $cId = DB::table('superadmins')->where('login_type', 1)->value('id');

                            // Resolve Vendor
                            $vId = $vendorCache[strtolower(trim($rawVendor ?: 'Imported Vendor'))] ?? null;
                            if (!$vId) {
                                $vId = DB::table('vendors')->insertGetId(['name' => \App\Services\CryptService::encryptData($rawVendor ?: 'Imported Vendor'), 'created_at' => now(), 'updated_at' => now()]);
                                $vendorCache[strtolower(trim($rawVendor ?: 'Imported Vendor'))] = $vId;
                            }

                            $exists = DB::table('emails')
                                ->where('domain_master_id', $dId)
                                ->where('client_id', $cId)
                                ->where('renewal_date', $renewalDate)
                                ->exists();

                            if ($exists) { 
                                $duplicates++; 
                                $duplicateRows[] = $data;
                                continue; 
                            }

                            $grace_period = 0;
                            if ($renewalDate && $deletionDate) {
                                $rd = new \DateTime($renewalDate);
                                $dd = new \DateTime($deletionDate);
                                $diff = $rd->diff($dd);
                                $grace_period = $diff->invert ? 0 : $diff->days;
                            }

                            DB::table('emails')->insert([
                                'domain_master_id' => $dId,
                                'client_id'        => $cId,
                                'product_id'       => $pId,
                                'vendor_id'        => $vId,
                                'amount'           => $amount,
                                'start_date'       => $startDate,
                                'renewal_date'     => $renewalDate,
                                'deletion_date'    => $deletionDate,
                                'quantity'         => is_numeric($rawQuantity) ? (int)$rawQuantity : 1,
                                'bill_type'        => $rawBillType ?: 'yearly',
                                'status'           => $status,
                                'remarks'          => $rawRemarks ? \App\Services\CryptService::encryptData($rawRemarks) : null,
                                'due_date'         => $deletionDate ?? $renewalDate,
                                'grace_period'     => $grace_period,
                                'updated_at'       => now(),
                                'created_at'       => now(),
                            ]);
                            $inserted++;
                        } catch (\Throwable $e) { $failed++; $errors[] = $e->getMessage(); }
                    }
                }
                fclose($handle);
            }

            // Save the file physically for audit history
            $filePath = AuditFileService::saveImportFile($file);
            $user = Auth::user();
            $userId = $user->id ?? 1;
            $role = $user->role ?? 'System';

            if ($inserted > 0) ActivityLogger::imported($userId, 'Email', $inserted);

            $history = AuditFileService::logImport(
                $userId,
                'Email',
                $file->getClientOriginalName(),
                $filePath,
                $inserted,
                $failed,
                $duplicates,
                $user->name ?? 'System / Admin',
                $role
            );

            if ($duplicates > 0) {
                AuditFileService::storeDuplicates($history, $firstRow, $duplicateRows);
            }

            if ($inserted === 0 && $failed > 0) {
                return response()->json([
                    'success' => false,
                    'inserted' => 0,
                    'failed' => $failed,
                    'duplicates' => $duplicates,
                    'message' => "Import failed: $failed rows had errors. " . ($errors[0] ?? "")
                ], 422);
            }

            return response()->json(['success' => ($failed === 0), 'inserted' => $inserted, 'failed' => $failed, 'duplicates' => $duplicates, 'errors' => $errors]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Import failed: ' . $e->getMessage()], 500);
        }
    }
    public function bulkDelete(Request $request)
    {
        $ids = $request->get('ids', []);
        if (empty($ids)) {
            return response()->json(['success' => false, 'message' => 'No IDs provided'], 400);
        }

        try {
            $deletedCount = 0;
            $uObj = auth()->user() ?? \Illuminate\Support\Facades\DB::table('superadmins')->where('id', $request->input('s_id'))->first();

            foreach ($ids as $id) {
                $record = Email::with(['product', 'client', 'vendor', 'domainMaster'])->find($id);
                if (!$record) continue;

                $logData = $record->toArray();
                $pName = optional($record->product)->name;
                $cName = optional($record->client)->name;
                $vName = optional($record->vendor)->name;
                try { if($pName) $pName = \App\Services\CryptService::decryptData($pName) ?? $pName; } catch (\Exception $e) {}
                try { if($cName) $cName = \App\Services\CryptService::decryptData($cName) ?? $cName; } catch (\Exception $e) {}
                try { if($vName) $vName = \App\Services\CryptService::decryptData($vName) ?? $vName; } catch (\Exception $e) {}
                
                $logData['product'] = $pName;
                $logData['client']  = $cName;
                $logData['vendor']  = $vName;
                $logData['domain']  = $record->domainMaster->domain_name ?? '-';

                $this->logActivity('DELETE', $record, $logData, null, "Email Record Deleted");
                
                $record->delete();
                $deletedCount++;
            }

            return response()->json(['status' => true, 'success' => true, 'message' => $deletedCount . ' Email records deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Deletion failed: ' . $e->getMessage()], 500);
        }
    }

    public function filterOptions(Request $request)
    {
        $category = $request->query('category'); // domain | client | product
        $data = [];

        if ($category === 'domain') {
            $data = \App\Models\DomainName::whereIn('id', 
                \Illuminate\Support\Facades\DB::table('emails')->distinct()->pluck('domain_master_id')
            )->orderBy('domain_name')
                ->pluck('domain_name')
                ->filter()
                ->values()
                ->toArray();
        } elseif ($category === 'client') {
            $data = \App\Models\Superadmin::whereIn('id', 
                \Illuminate\Support\Facades\DB::table('emails')->distinct()->pluck('client_id')
            )->pluck('name')
            ->map(function($name) {
                try { return \App\Services\CryptService::decryptData($name) ?? $name; } catch (\Exception $e) { return $name; }
            })
            ->filter()->sort()->values()->toArray();
        } elseif ($category === 'product') {
            $data = \App\Models\Product::whereIn('id', 
                \Illuminate\Support\Facades\DB::table('emails')->distinct()->pluck('product_id')
            )->pluck('name')
            ->map(function($name) {
                try { return \App\Services\CryptService::decryptData($name) ?? $name; } catch (\Exception $e) { return $name; }
            })
            ->filter()->sort()->values()->toArray();
        }

        return response()->json(['status' => true, 'data' => $data]);
    }
}