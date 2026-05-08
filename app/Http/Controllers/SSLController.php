<?php

namespace App\Http\Controllers;

use App\Models\SSL;
use App\Models\Product;
use App\Models\Superadmin;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use App\Services\ActivityLogger;
use App\Services\ClientScopeService;
use App\Services\CryptService;
use App\Services\DateFormatterService;
use App\Services\GracePeriodService;

use App\Services\AuditFileService;
use Illuminate\Support\Facades\Auth;
use App\Traits\DataNormalizer;

class SSLController extends Controller
{
    use DataNormalizer;
    public function logExport(Request $request)
    {
        $validated = $request->validate([
            'total_records' => 'required|integer',
            'data_snapshot' => 'nullable'
        ]);

        $user = auth()->user();
        $userName = $user ? (CryptService::decryptData($user->name) ?? $user->name) : 'System';
        $userId = $user->id ?? $request->input('s_id') ?? 1;

        $role = $user ? ($user->role ?? (isset($user->login_type) ? ($user->login_type === 1 ? 'Superadmin' : ($user->login_type === 3 ? 'Client' : 'User')) : 'Unknown')) : 'System';

        try {
            ActivityLogger::exported($userId, 'SSL', $validated['total_records']);
        } catch (\Exception $e) {
        }

        try {
            $history = AuditFileService::logExport(
                $userId,
                'SSL',
                $validated['total_records'],
                $request->input('data_snapshot'),
                $userId
            );
        } catch (\Exception $e) {
            $history = \App\Models\ImportHistory::create([
                'user_id' => $userId,
                'module_name' => 'SSL',
                'action' => 'EXPORT',
                'file_name' => 'SSL_Export_' . date('Ymd_His') . '.csv',
                'imported_by' => $userName,
                'role' => $role,
                'successful_rows' => $validated['total_records'],
                'client_id' => $userId
            ]);
        }
        return response()->json(['success' => true, 'data' => $history]);
    }

    protected array $productIds = [42, 43];

    private function formatDate($date)
    {
        return self::robustParseDate($date);
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
            $user = auth()->user() ?: (object) ['id' => request()->input('auth_user_id') ?: 1, 'name' => 'Admin', 'role' => 'Superadmin'];

            $standardize = function ($data) use ($record) {
                if (!$data)
                    return $data;
                $arr = is_array($data) ? $data : $data->toArray();

                $decrypt = function ($val) {
                    if (!$val || !is_string($val))
                        return $val;
                    try {
                        return CryptService::decryptData($val) ?? $val;
                    } catch (\Exception $e) {
                        return $val;
                    }
                };

                $arr['Product'] = $decrypt($arr['Product'] ?? $arr['product_name'] ?? optional($record->product)->name ?? 'N/A');
                $arr['Client'] = $decrypt($arr['Client'] ?? $arr['client_name'] ?? optional($record->client)->name ?? 'N/A');
                $arr['Vendor'] = $decrypt($arr['Vendor'] ?? $arr['vendor_name'] ?? optional($record->vendor)->name ?? 'N/A');
                $arr['Domain'] = $arr['Domain'] ?? $arr['domain_name'] ?? optional($record->domainMaster)->domain_name ?? 'N/A';

                if (isset($arr['renewal_date']))
                    $arr['Renewal Date'] = $arr['renewal_date'];
                if (isset($arr['amount']))
                    $arr['Amount'] = $arr['amount'];
                if (isset($arr['days_left']))
                    $arr['Days Left'] = $arr['days_left'];
                if (isset($arr['deletion_date']))
                    $arr['Deletion Date'] = $arr['deletion_date'];
                if (isset($arr['days_to_delete']))
                    $arr['Days to Delete'] = $arr['days_to_delete'];
                if (array_key_exists('grace_period', $arr))
                    $arr['Grace Period'] = $arr['grace_period'];
                if (array_key_exists('due_date', $arr))
                    $arr['Due Date'] = $arr['due_date'];

                if (isset($arr['status']))
                    $arr['Status'] = $arr['status'] == 1 ? 'Active' : 'Inactive';
                if (isset($arr['remarks']))
                    $arr['Remarks'] = $decrypt($arr['remarks']);

                return $arr;
            };

            $actionType = strtoupper($action === 'created' ? 'CREATE' : ($action === 'deleted' ? 'DELETE' : $action));

            ActivityLogger::logActivity(
                $user,
                $actionType,
                'SSL',
                's_s_l_s',
                $record->id,
                $standardize($oldData),
                $standardize($newData),
                null,
                request()
            );
        } catch (\Exception $e) {
        }
    }

    public function index(Request $request)
    {
        $limit = $request->query('limit', 100);
        $offset = $request->query('offset', 0);
        $search = $request->query('search', '');

        $query = SSL::select([
            'id',
            'domain_master_id',
            'product_id',
            'client_id',
            'vendor_id',
            'amount',
            'renewal_date',
            'deletion_date',
            'status',
            'remarks',
            'created_at',
            'updated_at',
            'grace_period',
            'due_date'
        ])
            ->with([
                'domainMaster:id,domain_name',
                'product:id,name',
                'client:id,name',
                'vendor:id,name'
            ])
            ->withCount('remarkHistories');

        // ── CLIENT SCOPE: filter to only this client's records ──
        ClientScopeService::applyScope($query, $request);

        if (!empty($search)) {
            $searchLow = strtolower($search);
            $lk = '%' . $searchLow . '%';

            $dateMatch = null;
            if (strlen($search) >= 3) {
                try {
                    $date = \Illuminate\Support\Carbon::parse(str_replace(['/', '.'], '-', $search));
                    $dateMatch = $date->format('Y-m-d');
                } catch (\Exception $e) {
                }
            }

            // Link to domain_master for search
            $dmIds = \App\Models\DomainName::where('domain_name', 'LIKE', '%' . $searchLow . '%')->pluck('id');

            $pIds = \App\Models\Product::pluck('name', 'id')
                ->filter(function ($name) use ($searchLow) {
                    try {
                        $dec = \App\Services\CryptService::decryptData($name) ?? $name;
                    } catch (\Exception $e) {
                        $dec = $name;
                    }
                    return str_contains(strtolower($dec), $searchLow);
                })->keys();

            $cIds = \App\Models\Superadmin::pluck('name', 'id')
                ->filter(function ($name) use ($searchLow) {
                    try {
                        $dec = \App\Services\CryptService::decryptData($name) ?? $name;
                    } catch (\Exception $e) {
                        $dec = $name;
                    }
                    return str_contains(strtolower($dec), $searchLow);
                })->keys();

            $vIds = \App\Models\Vendor::pluck('name', 'id')
                ->filter(function ($name) use ($searchLow) {
                    try {
                        $dec = \App\Services\CryptService::decryptData($name) ?? $name;
                    } catch (\Exception $e) {
                        $dec = $name;
                    }
                    return str_contains(strtolower($dec), $searchLow);
                })->keys();

            $query->where(function ($q) use ($pIds, $cIds, $vIds, $dmIds, $lk, $dateMatch) {
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

        // ── CASCADING FILTER ──
        $filterBy = $request->query('filter_by');    // domain | client | product
        $filterValue = $request->query('filter_value');
        if ($filterBy && $filterValue) {
            if ($filterBy === 'domain') {
                $dmIds = \App\Models\DomainName::where('domain_name', $filterValue)->pluck('id');
                $query->whereIn('domain_master_id', $dmIds);
            } elseif ($filterBy === 'client') {
                $cIds = \App\Models\Superadmin::pluck('name', 'id')
                    ->filter(function ($name) use ($filterValue) {
                        try {
                            $dec = \App\Services\CryptService::decryptData($name) ?? $name;
                        } catch (\Exception $e) {
                            $dec = $name;
                        }
                        return strtolower(trim($dec)) === strtolower(trim($filterValue));
                    })->keys();
                $query->whereIn('client_id', $cIds);
            } elseif ($filterBy === 'product') {
                $pIds = \App\Models\Product::pluck('name', 'id')
                    ->filter(function ($name) use ($filterValue) {
                        try {
                            $dec = \App\Services\CryptService::decryptData($name) ?? $name;
                        } catch (\Exception $e) {
                            $dec = $name;
                        }
                        return strtolower(trim($dec)) === strtolower(trim($filterValue));
                    })->keys();
                $query->whereIn('product_id', $pIds);
            }
        }

        // ── NEW: ALL IDs FETCH ──
        if ($request->query('all_ids')) {
            return response()->json($query->pluck('id'));
        }

        $perPage = (int) $request->query('rowsPerPage', $request->query('limit', 20));
        if ($perPage < 1)
            $perPage = 20;

        $currentPage = $request->query('page');
        if ($currentPage !== null) {
            $currentPage = (int) $currentPage;
        } else {
            $offset = (int) $request->query('offset', 0);
            $currentPage = (int) (($offset / $perPage) + 1);
        }
        if ($currentPage < 1)
            $currentPage = 1;

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
                    if (!str_contains($col, '.'))
                        $col = "{$tableName}.{$col}";
                    $query->orderBy($col, $request->query('order', 'desc'));
                } else {
                    $query->orderBy("{$tableName}.created_at", 'desc');
                }
                break;
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $currentPage);
        $total = $paginator->total();

        $records = collect($paginator->items());

        $data = $records->map(function ($item) {
            $today = now()->startOfDay();

            $daysLeft = $item->renewal_date
                ? $today->diffInDays(Carbon::parse($item->renewal_date)->startOfDay(), false)
                : null;

            $daysToDelete = $item->deletion_date
                ? $today->diffInDays(Carbon::parse($item->deletion_date)->startOfDay(), false)
                : null;

            $domainName = $item->domainMaster->domain_name ?? '-';

            // Derive Client Name
            $clientName = $item->client?->name ?? null;
            if ($clientName)
                try {
                    $clientName = \App\Services\CryptService::decryptData($clientName) ?? $clientName;
                } catch (\Exception $e) {
                }

            $productName = $item->product?->name ?? null;
            try {
                $productName = \App\Services\CryptService::decryptData($productName) ?? $productName;
            } catch (\Exception $e) {
            }

            $vendorName = $item->vendor?->name ?? null;
            try {
                $vendorName = \App\Services\CryptService::decryptData($vendorName) ?? $vendorName;
            } catch (\Exception $e) {
            }

            $remarks = $item->remarks;
            try {
                $remarks = \App\Services\CryptService::decryptData($remarks) ?? $remarks;
            } catch (\Exception $e) {
            }

            return [
                'id' => $item->id,
                'domain_name' => $domainName,
                'domain_master_id' => $item->domain_master_id,
                'client_name' => $clientName ?? 'N/A',
                'client_id' => $item->client_id,
                'product_name' => $productName,
                'product_id' => $item->product_id,
                'vendor_name' => $vendorName,
                'vendor_id' => $item->vendor_id,
                'amount' => (float) $item->amount,
                'renewal_date' => $item->renewal_date,
                'expiry_date' => $item->renewal_date,
                'days_left' => $daysLeft,
                'deletion_date' => $item->deletion_date,
                'days_to_delete' => $daysToDelete,
                'grace_period' => $item->grace_period ?? 0,
                'due_date' => $item->due_date,
                'status' => $item->status,
                'remarks' => $remarks,
                'has_remark_history' => $item->remark_histories_count > 0,
                'last_updated' => DateFormatterService::formatDateTime($item->updated_at),
                'updated_at_formatted' => DateFormatterService::formatDateTime($item->updated_at),
                'created_at_formatted' => DateFormatterService::formatDateTime($item->created_at ?? $item->updated_at),
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
            ClientScopeService::enforceClientId($request);

            $request->merge([
                'domain_master_id' => $request->domain_master_id ?? data_get($request->domain_master_id, 'value'),
                'product_id' => $request->product_id ?? data_get($request->product, 'value'),
                'client_id' => $request->client_id ?? data_get($request->client, 'value'),
                'vendor_id' => $request->vendor_id ?? data_get($request->vendor, 'value'),
                'renewal_date' => ($request->renewal_date ?: $request->expiry_date),
            ]);

            $validated = validator($request->all(), [
                'domain_master_id' => 'required|exists:domain_master,id',
                'client_id' => 'required|exists:superadmins,id',
                'product_id' => 'required|exists:products,id',
                'vendor_id' => 'required|exists:vendors,id',
                'amount' => 'required|numeric',
                'renewal_date' => 'required|date',
                'deletion_date' => 'required|date',
                'grace_period' => 'required',
                'status' => 'required',
                'remarks' => 'nullable'
            ]);

            if ($validated->fails())
                return response()->json(['success' => false, 'message' => 'Validation error'], 422);

            if ($request->renewal_date)
                $request->merge(['renewal_date' => \Carbon\Carbon::parse($request->renewal_date)->format('Y-m-d')]);
            if ($request->deletion_date)
                $request->merge(['deletion_date' => \Carbon\Carbon::parse($request->deletion_date)->format('Y-m-d')]);

            $today = now()->startOfDay();

            // ── DUPLICATE CHECK ──
            $duplicateExists = SSL::where('domain_master_id', $request->domain_master_id)
                ->where('client_id', $request->client_id)
                ->where('renewal_date', $request->renewal_date)
                ->exists();

            if ($duplicateExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate entry: This domain already exists for the same client and renewal date'
                ], 422);
            }

            $days_left = $request->renewal_date ? $today->diffInDays($request->renewal_date, false) : null;
            $days_to_delete = $request->deletion_date ? $today->diffInDays($request->deletion_date, false) : null;

            $model = SSL::create([
                'domain_master_id' => $request->domain_master_id,
                'product_id' => $request->product_id,
                'client_id' => $request->client_id,
                'vendor_id' => $request->vendor_id,
                'amount' => $request->amount ?? 0,
                'renewal_date' => $request->renewal_date,
                'deletion_date' => $request->deletion_date,
                'days_left' => $days_left,
                'days_to_delete' => $days_to_delete,
                'grace_period' => $request->grace_period ?? 0,
                'due_date' => $request->due_date,
                'status' => $request->status ?? 1,
                'remarks' => $request->remarks ? \App\Services\CryptService::encryptData($request->remarks) : null
            ]);

            \App\Services\GracePeriodService::syncModel($model);
            $model->save();

            $model->refresh()->load(['domainMaster', 'product', 'vendor', 'client']);

            $clientName = $model->client->name ?? 'N/A';
            try {
                $clientName = \App\Services\CryptService::decryptData($clientName) ?? $clientName;
            } catch (\Exception $e) {
            }

            $productName = $model->product->name ?? null;
            try {
                $productName = \App\Services\CryptService::decryptData($productName) ?? $productName;
            } catch (\Exception $e) {
            }

            $vendorName = $model->vendor->name ?? null;
            try {
                $vendorName = \App\Services\CryptService::decryptData($vendorName) ?? $vendorName;
            } catch (\Exception $e) {
            }

            $data = [
                'id' => $model->id,
                'domain_name' => $model->domainMaster->domain_name ?? 'N/A',
                'domain_master_id' => $model->domain_master_id,
                'client_name' => $clientName,
                'client_id' => $model->client_id,
                'product_name' => $productName,
                'product_id' => $model->product_id,
                'vendor_name' => $vendorName,
                'vendor_id' => $model->vendor_id,
                'amount' => (float) $model->amount,
                'renewal_date' => $model->renewal_date,
                'expiry_date' => $model->renewal_date,
                'deletion_date' => $model->deletion_date,
                'days_to_delete' => $model->days_to_delete,
                'grace_period' => $model->grace_period ?? 0,
                'due_date' => $model->due_date,
                'status' => $model->status,
                'remarks' => $request->remarks,
                'last_updated' => DateFormatterService::formatDateTime($model->updated_at),
                'updated_at_formatted' => DateFormatterService::formatDateTime($model->updated_at),
                'created_at_formatted' => DateFormatterService::formatDateTime($model->created_at),
                'updated_at' => $model->updated_at,
                'created_at' => $model->created_at,
            ];

            $this->logActivity('created', $model, null, $data);

            return response()->json([
                'status' => true,
                'success' => true,
                'message' => 'SSL Record created successfully',
                'data' => $data
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $record = SSL::find($id);
        if (!$record)
            return response()->json(['success' => false, 'message' => 'Not found'], 404);

        $data = $request->all();
        foreach ($data as $key => $value)
            if ($value === '')
                $data[$key] = null;

        if (array_key_exists('expiry_date', $data) && !array_key_exists('renewal_date', $data))
            $data['renewal_date'] = $data['expiry_date'];
        if (array_key_exists('valid_till', $data) && !array_key_exists('renewal_date', $data))
            $data['renewal_date'] = $data['valid_till'];
        if (array_key_exists('grace_end_date', $data) && !array_key_exists('due_date', $data))
            $data['due_date'] = $data['grace_end_date'];

        if (array_key_exists('renewal_date', $data) && $data['renewal_date'] === null) {
            return response()->json(['success' => false, 'message' => 'Validity Date cannot be empty.'], 422);
        }
        if (array_key_exists('deletion_date', $data) && $data['deletion_date'] === null) {
            return response()->json(['success' => false, 'message' => 'Deletion Date cannot be empty.'], 422);
        }
        if (array_key_exists('due_date', $data) && $data['due_date'] === null) {
            return response()->json(['success' => false, 'message' => 'Grace Period Date cannot be empty.'], 422);
        }

        if (isset($data['renewal_date']))
            $data['renewal_date'] = $this->formatDate($data['renewal_date']);
        if (isset($data['deletion_date']))
            $data['deletion_date'] = $this->formatDate($data['deletion_date']);
        if (isset($data['due_date']))
            $data['due_date'] = $this->formatDate($data['due_date']);

        $this->calculateFields($data);

        // ── DUPLICATE CHECK ──
        $duplicateExists = SSL::where('domain_master_id', $data['domain_master_id'] ?? $record->domain_master_id)
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

        \App\Services\RemarkHistoryService::logUpdate('SSL', $record, $data);

        if (isset($data['remarks']))
            $data['remarks'] = \App\Services\CryptService::encryptData($data['remarks']);

        $oldData = clone $record;
        $record->update($data);

        \App\Services\GracePeriodService::syncModel($record);
        $record->save();
        $record->refresh()->load(['domainMaster', 'product', 'vendor', 'client'])->loadCount('remarkHistories');

        $clientName = $record->client->name ?? null;
        if ($clientName)
            try {
                $clientName = \App\Services\CryptService::decryptData($clientName) ?? $clientName;
            } catch (\Exception $e) {
            }

        $productName = $record->product?->name ?? null;
        try {
            $productName = \App\Services\CryptService::decryptData($productName) ?? $productName;
        } catch (\Exception $e) {
        }

        $vendorName = $record->vendor?->name ?? null;
        try {
            $vendorName = \App\Services\CryptService::decryptData($vendorName) ?? $vendorName;
        } catch (\Exception $e) {
        }

        $remarks = $record->remarks;
        try {
            $remarks = \App\Services\CryptService::decryptData($remarks) ?? $remarks;
        } catch (\Exception $e) {
        }

        $resp = $record->toArray();
        $resp['domain_name'] = $record->domainMaster->domain_name ?? 'N/A';
        $resp['client_name'] = $clientName;
        $resp['product_name'] = $productName;
        $resp['vendor_name'] = $vendorName;
        $resp['remarks'] = $remarks;
        $resp['expiry_date'] = $record->renewal_date;
        $resp['has_remark_history'] = $record->remark_histories_count > 0;
        $resp['last_updated'] = DateFormatterService::formatDateTime($record->updated_at);
        $resp['updated_at_formatted'] = DateFormatterService::formatDateTime($record->updated_at);
        $resp['created_at_formatted'] = DateFormatterService::formatDateTime($record->created_at);

        $this->logActivity('UPDATE', $record, $oldData->toArray(), $resp);

        return response()->json([
            'status' => true,
            'success' => true,
            'message' => 'SSL Record updated successfully',
            'data' => $resp
        ]);
    }

    public function destroy($id)
    {
        $record = SSL::find($id);
        if (!$record)
            return response()->json(['success' => false, 'message' => 'Not found'], 404);

        // Enrich data with resolved names before logging deletion
        $logData = $record->toArray();
        $pName = optional($record->product)->name;
        $cName = optional($record->client)->name;
        $vName = optional($record->vendor)->name;
        try {
            $pName = \App\Services\CryptService::decryptData($pName) ?? $pName;
        } catch (\Exception $e) {
        }
        try {
            $cName = \App\Services\CryptService::decryptData($cName) ?? $cName;
        } catch (\Exception $e) {
        }
        try {
            $vName = \App\Services\CryptService::decryptData($vName) ?? $vName;
        } catch (\Exception $e) {
        }

        $logData['product'] = $pName;
        $logData['client'] = $cName;
        $logData['vendor'] = $vName;
        $logData['domain'] = $record->domainMaster->domain_name ?? '-';

        $this->logActivity('deleted', $record, $logData);
        $record->delete();
        return response()->json([
            'status' => true,
            'success' => true,
            'message' => 'SSL Record deleted successfully'
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

            if (!$content)
                return response()->json(['success' => false, 'message' => 'Failed to read file content'], 400);

            $handle = fopen('php://temp', 'r+');
            fwrite($handle, $content);
            rewind($handle);

            $inserted = 0;
            $failed = 0;
            $duplicates = 0;
            $duplicateRows = [];
            $errors = [];

            // 1. Warm caches (decryption-aware)
            $productCache = [];
            $clientCache = [];
            $vendorCache = [];
            $domainMasterCache = [];
            \Illuminate\Support\Facades\DB::table('products')->get(['id', 'name'])->each(function ($p) use (&$productCache) {
                try {
                    $name = \App\Services\CryptService::decryptData($p->name);
                } catch (\Throwable $e) {
                    $name = $p->name;
                }
                $productCache[strtolower(trim($name ?? ''))] = (int) $p->id;
            });
            \Illuminate\Support\Facades\DB::table('superadmins')->get(['id', 'name'])->each(function ($c) use (&$clientCache) {
                try {
                    $name = \App\Services\CryptService::decryptData($c->name);
                } catch (\Throwable $e) {
                    $name = $c->name;
                }
                $clientCache[strtolower(trim($name ?? ''))] = (int) $c->id;
            });
            \Illuminate\Support\Facades\DB::table('vendors')->get(['id', 'name'])->each(function ($v) use (&$vendorCache) {
                try {
                    $name = \App\Services\CryptService::decryptData($v->name);
                } catch (\Throwable $e) {
                    $name = $v->name;
                }
                $vendorCache[strtolower(trim($name ?? ''))] = (int) $v->id;
            });
            \Illuminate\Support\Facades\DB::table('domain_master')->get(['id', 'domain_name'])->each(function ($dm) use (&$domainMasterCache) {
                $domainMasterCache[strtolower(trim($dm->domain_name ?? ''))] = (int) $dm->id;
            });

            $clientIdFromRequest = $request->input('client_id');

            if ($handle !== FALSE) {
                // Handle UTF-8 BOM if present
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }
                $firstRow = fgetcsv($handle, 1000, ',');
                if ($firstRow) {
                    $headerMod = array_map(function($h) { return str_replace([' ', '-'], '_', strtolower(trim($h ?? ''))); }, $firstRow);
            
            // Strict Header Validation
            $allowedHeaders = [
                'domain', 'domain_name', 'url',
                'product', 'product_id', 'product_name', 'name',
                'client', 'client_id', 'customer', 'client_name',
                'vendor', 'vendor_id', 'vendor_name',
                'amount', 'price', 'cost',
                'currency',
                'renewal_date', 'renewal', 'expiry_date', 'valid_till',
                'deletion_date', 'deletion', 'delete_date', 'grace_period_date', 'due_date', 'grace_end_date',
                'status',
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

            $suffixCache = \Illuminate\Support\Facades\DB::table('suffix_masters')->pluck('suffix')->toArray();
            \Illuminate\Support\Facades\Log::info("SSL Import Suffix Cache: " . implode(', ', $suffixCache));

            $map = array_flip($headerMod);

                    $idxDomain = $map['domain'] ?? $map['domain_name'] ?? $map['url'] ?? -1;
                    $idxClient = $map['client'] ?? $map['client_id'] ?? $map['customer'] ?? $map['client_name'] ?? -1;
                    $idxProduct = $map['product'] ?? $map['product_id'] ?? $map['product_name'] ?? $map['name'] ?? -1;
                    $idxVendor = $map['vendor'] ?? $map['vendor_id'] ?? $map['vendor_name'] ?? -1;
                    $idxAmount = $map['amount'] ?? $map['price'] ?? $map['cost'] ?? -1;
                    $idxRenewal = $map['renewal_date'] ?? $map['renewal'] ?? $map['date'] ?? $map['expiry_date'] ?? $map['valid_till'] ?? -1;
                    $idxDeletion = $map['deletion_date'] ?? $map['deletion'] ?? $map['delete_date'] ?? $map['grace_period_date'] ?? $map['due_date'] ?? -1;
                    $idxGraceEndDate = $map['grace_end_date'] ?? $map['grace_date'] ?? -1;
                    $idxStatus = $map['status'] ?? -1;
                    $idxRemarks = $map['remarks'] ?? $map['remark'] ?? $map['note'] ?? $map['notes'] ?? -1;

                    $mandatoryFields = ['domain', 'product', 'client', 'vendor', 'amount', 'renewal_date', 'deletion_date', 'grace_end_date', 'status'];
                    $mandatoryIndices = [
                        'domain' => $idxDomain,
                        'product' => $idxProduct,
                        'client' => $idxClient,
                        'vendor' => $idxVendor,
                        'amount' => $idxAmount,
                        'renewal_date' => $idxRenewal,
                        'deletion_date' => $idxDeletion,
                        'grace_end_date' => $idxGraceEndDate,
                        'status' => $idxStatus
                    ];

                    $forceImport = $request->input('force_import') === 'true' || $request->input('force_import') === true;
                    // 2. Strict Validation Pass
                    if (!$forceImport) {
                        $rowNum = 1;
                        $issues = [];
                        while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                            $rowNum++;
                            if (empty(array_filter($data)))
                                continue;

                            $missing = [];
                            foreach ($mandatoryIndices as $field => $idx) {
                                $val = ($idx !== -1 && isset($data[$idx])) ? trim((string) $data[$idx]) : '';
                                if ($val === '') {
                                    $missing[] = $field;
                                } else {
                                    // Numeric check for amount
                                    if ($field === 'amount') {
                                        $cleanAmount = str_replace([',', ' '], '', $val);
                                        if (!is_numeric($cleanAmount)) {
                                            $missing[] = "amount (invalid number: '$val')";
                                        }
                                    }

                                    // Date format check
                                    if ($field === 'renewal_date' || $field === 'deletion_date' || $field === 'grace_end_date') {
                                        if ($val !== '') {
                                            $parsed = self::robustParseDate($val);
                                            if (!$parsed) {
                                                $missing[] = "{$field} (invalid format: '$val'. Please use DD-MM-YYYY or YYYY-MM-DD)";
                                            }
                                        }
                                    }

                                    // Status value check
                                    if ($field === 'status') {
                                        $sVal = strtolower($val);
                                        if ($sVal !== 'active' && $sVal !== 'inactive' && $sVal !== '1' && $sVal !== '0') {
                                            $missing[] = "status (invalid value: '$val'. Must be 'Active' or 'Inactive')";
                                        }
                                    }
                                }
                            }

                            // ── DOMAIN VALIDATION PASS ──
                            $domainVal = ($idxDomain !== -1 && isset($data[$idxDomain])) ? trim((string) $data[$idxDomain]) : '';
                            if ($domainVal !== '') {
                                $domainLower = strtolower($domainVal);
                                if (!str_contains($domainLower, '.')) {
                                    $missing[] = "domain (invalid format: '$domainVal'. Must contain a dot)";
                                } else {
                                    $hasValidSuffix = false;
                                    foreach ($suffixCache as $sfx) {
                                        if (str_ends_with($domainLower, '.' . ltrim($sfx, '.'))) {
                                            $hasValidSuffix = true;
                                            break;
                                        }
                                    }
                                    if (!$hasValidSuffix) {
                                        $missing[] = "domain (invalid suffix: '$domainVal'. Suffix not in Suffix Master)";
                                    }
                                }
                            }

                            if (!empty($missing)) {
                                $issues[] = ['row' => $rowNum, 'missing_fields' => $missing];
                            }
                        }

                        if (!empty($issues)) {
                            fclose($handle);
                            $user = \Illuminate\Support\Facades\Auth::user();
                            $history = \App\Models\ImportHistory::create([
                                'module_name' => 'SSL',
                                'action' => 'IMPORT',
                                'file_name' => $file->getClientOriginalName(),
                                'imported_by' => $user->name ?? 'System / Admin',
                                'successful_rows' => 0,
                                'failed_rows' => count($issues),
                                'duplicates_count' => 0,
                                'data_snapshot' => json_encode($issues),
                                'created_at' => now()
                            ]);
                            \App\Services\AuditFileService::storeImport($history, $file);

                            \App\Services\ActivityLogger::imported($user->id, 'SSL', 0, $history->id, count($issues), 0);

                            return response()->json([
                                'success' => false,
                                'requires_confirmation' => true,
                                'message' => 'Validation failed: Mandatory fields are missing or invalid data format detected.',
                                'issues' => $issues,
                                'history_id' => $history->id,
                                'total_affected' => count($issues)
                            ], 422);
                        }
                        rewind($handle);
                        $bom = fread($handle, 3);
                        if ($bom !== "\xEF\xBB\xBF")
                            rewind($handle);
                        fgetcsv($handle, 1000, ',');
                    }

                    while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                        try {
                            if (empty(array_filter($data)))
                                continue;

                            $parseDate = function ($val) {
                                return self::robustParseDate($val);
                            };

                            $rawProduct = trim($data[$idxProduct] ?? '');
                            $rawClient = ($idxClient !== -1 && isset($data[$idxClient])) ? trim($data[$idxClient]) : '';
                            $rawDomain = ($idxDomain !== -1 && isset($data[$idxDomain])) ? trim($data[$idxDomain]) : '';
                            $rawVendor = ($idxVendor !== -1 && isset($data[$idxVendor])) ? trim($data[$idxVendor]) : '';
                            $rawAmount = ($idxAmount !== -1 && isset($data[$idxAmount])) ? $data[$idxAmount] : 0;
                            $rawRenewal = ($idxRenewal !== -1 && isset($data[$idxRenewal])) ? $data[$idxRenewal] : null;
                            $rawDeletion = ($idxDeletion !== -1 && isset($data[$idxDeletion])) ? $data[$idxDeletion] : null;
                            $rawGraceEndDate = ($idxGraceEndDate !== -1 && isset($data[$idxGraceEndDate])) ? trim($data[$idxGraceEndDate]) : null;
                            $rawStatus = ($idxStatus !== -1 && isset($data[$idxStatus])) ? trim($data[$idxStatus]) : 'Active';
                            $rawRemarks = ($idxRemarks !== -1 && isset($data[$idxRemarks])) ? trim($data[$idxRemarks]) : '';

                            // Strict Amount Check
                            if ($rawAmount && !is_numeric(str_replace([',', ' '], '', (string) $rawAmount))) {
                                throw new \Exception("Invalid Amount format: " . $rawAmount);
                            }
                            $amount = (float) str_replace([',', ' '], '', (string) $rawAmount);

                            // Strict Status Restriction
                            $normalizedStatus = strtolower($rawStatus);
                            if (!in_array($normalizedStatus, ['active', 'inactive', '1', '0'])) {
                                throw new \Exception("Invalid Status: '$rawStatus'. Must be 'Active' or 'Inactive'.");
                            }
                            $status = ($normalizedStatus === 'active' || $normalizedStatus === '1') ? 1 : 0;

                            // Strict Date Check
                            $renewalDate = $parseDate($rawRenewal);
                            if (!$renewalDate) {
                                throw new \Exception("Invalid Renewal Date: '$rawRenewal'");
                            }
                            $deletionDate = $parseDate($rawDeletion);
                            if (!$deletionDate && $rawDeletion && trim($rawDeletion) !== '') {
                                throw new \Exception("Invalid Deletion Date format: " . $rawDeletion);
                            }
                            
                            $dueDate = $parseDate($rawGraceEndDate) ?? $deletionDate ?? $renewalDate;
                            $gracePeriod = 0;
                            if ($dueDate && $renewalDate) {
                                $gracePeriod = max(0, Carbon::parse($renewalDate)->diffInDays(Carbon::parse($dueDate), false));
                            }

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
                                $dId = \Illuminate\Support\Facades\DB::table('domain_master')->insertGetId(['domain_name' => trim($rawDomain), 'created_at' => now(), 'updated_at' => now()]);
                                $domainMasterCache[strtolower(trim($rawDomain))] = $dId;
                            }

                            // Resolve Product
                            $pId = $productCache[strtolower(trim($rawProduct))] ?? null;
                            if (!$pId) {
                                $pId = \Illuminate\Support\Facades\DB::table('products')->insertGetId(['name' => \App\Services\CryptService::encryptData(trim($rawProduct)), 'created_at' => now(), 'updated_at' => now()]);
                                $productCache[strtolower(trim($rawProduct))] = $pId;
                            }

                            // Resolve Client
                            $cId = $clientIdFromRequest;
                            if (!$cId && $rawClient) {
                                $cId = $clientCache[strtolower(trim($rawClient))] ?? null;
                                if (!$cId) {
                                    $cId = \Illuminate\Support\Facades\DB::table('superadmins')->insertGetId([
                                        'name' => \App\Services\CryptService::encryptData(trim($rawClient)),
                                        'email' => strtolower(preg_replace('/[^a-z0-9]/', '', $rawClient)) . '+' . uniqid() . '@import.local',
                                        'password' => bcrypt(uniqid()),
                                        'login_type' => 3,
                                        'status' => 1,
                                        'created_at' => now(),
                                        'updated_at' => now()
                                    ]);
                                    $clientCache[strtolower(trim($rawClient))] = $cId;
                                }
                            }
                            if (!$cId)
                                $cId = \Illuminate\Support\Facades\DB::table('superadmins')->where('login_type', 1)->value('id');

                            // Resolve Vendor
                            $vId = $vendorCache[strtolower(trim($rawVendor ?: 'Imported Vendor'))] ?? null;
                            if (!$vId) {
                                $vId = \Illuminate\Support\Facades\DB::table('vendors')->insertGetId(['name' => \App\Services\CryptService::encryptData($rawVendor ?: 'Imported Vendor'), 'created_at' => now(), 'updated_at' => now()]);
                                $vendorCache[strtolower(trim($rawVendor ?: 'Imported Vendor'))] = $vId;
                            }

                            $exists = \Illuminate\Support\Facades\DB::table('s_s_l_s')
                                ->where('domain_master_id', $dId)
                                ->where('client_id', $cId)
                                ->where('renewal_date', $renewalDate)
                                ->exists();

                            if ($exists) {
                                $duplicates++;
                                $duplicateRows[] = $data;
                                continue;
                            }

                            \Illuminate\Support\Facades\DB::table('s_s_l_s')->insert([
                                'domain_master_id' => $dId,
                                'client_id' => $cId,
                                'product_id' => $pId,
                                'vendor_id' => $vId,
                                'amount' => $amount,
                                'renewal_date' => $renewalDate,
                                'deletion_date' => $deletionDate,
                                'status' => $status,
                                'remarks' => \App\Services\CryptService::encryptData($rawRemarks),
                                'grace_period' => $gracePeriod,
                                'due_date' => $dueDate,
                                'updated_at' => now(),
                                'created_at' => now()
                            ]);
                            $inserted++;
                        } catch (\Throwable $e) {
                            $failed++;
                            $errors[] = $e->getMessage();
                        }
                    }
                }
                fclose($handle);
            }

            // Save the file physically for audit history
            $filePath = AuditFileService::saveImportFile($file);
            $user = Auth::user();
            $userId = $user->id ?? 1;
            $role = $user->role ?? 'System';

            if ($inserted > 0)
                ActivityLogger::imported($userId, 'SSL', $inserted);

            $history = AuditFileService::logImport(
                $userId,
                'SSL',
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

            return response()->json([
                'success' => ($failed === 0),
                'inserted' => $inserted,
                'failed' => $failed,
                'duplicates' => $duplicates,
                'errors' => $errors,
                'message' => "Import processed: $inserted added, $failed failed."
            ]);
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
                $record = SSL::with(['product', 'client', 'vendor', 'domainMaster'])->find($id);
                if (!$record)
                    continue;

                $logData = $record->toArray();
                $pName = optional($record->product)->name;
                $cName = optional($record->client)->name;
                $vName = optional($record->vendor)->name;
                try {
                    if ($pName)
                        $pName = \App\Services\CryptService::decryptData($pName) ?? $pName;
                } catch (\Exception $e) {
                }
                try {
                    if ($cName)
                        $cName = \App\Services\CryptService::decryptData($cName) ?? $cName;
                } catch (\Exception $e) {
                }
                try {
                    if ($vName)
                        $vName = \App\Services\CryptService::decryptData($vName) ?? $vName;
                } catch (\Exception $e) {
                }

                $logData['product'] = $pName;
                $logData['client'] = $cName;
                $logData['vendor'] = $vName;
                $logData['domain'] = $record->domainMaster->domain_name ?? '-';

                $this->logActivity('deleted', $record, $logData);

                $record->delete();
                $deletedCount++;
            }

            return response()->json(['status' => true, 'success' => true, 'message' => $deletedCount . ' SSL records deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Deletion failed: ' . $e->getMessage()], 500);
        }
    }

    public function filterOptions(Request $request)
    {
        $category = $request->query('category'); // domain | client | product
        $data = [];

        if ($category === 'domain') {
            $data = \App\Models\DomainName::whereIn(
                'id',
                \Illuminate\Support\Facades\DB::table('s_s_l_s')->distinct()->pluck('domain_master_id')
            )->orderBy('domain_name')
                ->pluck('domain_name')
                ->filter()
                ->values()
                ->toArray();
        } elseif ($category === 'client') {
            $data = \App\Models\Superadmin::whereIn(
                'id',
                \Illuminate\Support\Facades\DB::table('s_s_l_s')->distinct()->pluck('client_id')
            )->pluck('name')
                ->map(function ($name) {
                    try {
                        return \App\Services\CryptService::decryptData($name) ?? $name;
                    } catch (\Exception $e) {
                        return $name;
                    }
                })
                ->filter()->sort()->values()->toArray();
        } elseif ($category === 'product') {
            $data = \App\Models\Product::whereIn(
                'id',
                \Illuminate\Support\Facades\DB::table('s_s_l_s')->distinct()->pluck('product_id')
            )->pluck('name')
                ->map(function ($name) {
                    try {
                        return \App\Services\CryptService::decryptData($name) ?? $name;
                    } catch (\Exception $e) {
                        return $name;
                    }
                })
                ->filter()->sort()->values()->toArray();
        }

        return response()->json(['status' => true, 'data' => $data]);
    }
}
