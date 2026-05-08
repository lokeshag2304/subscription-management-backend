<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Activity;
use App\Models\ImportHistory;
use App\Models\ImportExportHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use App\Services\ActivityLogger;
use App\Services\ClientScopeService;
use App\Services\DateFormatterService;
use App\Services\CryptService;
use App\Traits\DataNormalizer;

use App\Services\AuditFileService;

class DomainController extends Controller
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
            ActivityLogger::exported($userId, 'Domains', $validated['total_records']);
        } catch (\Exception $e) {
        }

        try {
            $history = AuditFileService::logExport(
                $userId,
                'Domains',
                $validated['total_records'],
                $request->input('data_snapshot'),
                $userId
            );
        } catch (\Exception $e) {
            $history = \App\Models\ImportHistory::create([
                'user_id' => $userId,
                'module_name' => 'Domains',
                'action' => 'EXPORT',
                'file_name' => 'Domains_Export_' . date('Ymd_His') . '.csv',
                'imported_by' => $userName,
                'role' => $role,
                'successful_rows' => $validated['total_records'],
                'client_id' => $userId
            ]);
        }
        return Response::json(['success' => true, 'data' => $history]);
    }

    protected array $productIds = [46];

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

                $arr['Product'] = $arr['Product'] ?? $arr['product_name'] ?? ($record->product ? \App\Services\CryptService::decryptData($record->product->name) : 'N/A');
                $arr['Client'] = $arr['Client'] ?? $arr['client_name'] ?? ($record->client ? \App\Services\CryptService::decryptData($record->client->name) : 'N/A');
                $arr['Vendor'] = $arr['Vendor'] ?? $arr['vendor_name'] ?? ($record->vendor ? \App\Services\CryptService::decryptData($record->vendor->name) : 'N/A');
                $arr['Domain'] = $arr['Domain'] ?? $arr['domain_name'] ?? ($record->domainMaster->domain_name ?? 'N/A');

                if (isset($arr['renewal_date']))
                    $arr['Renewal Date'] = $arr['renewal_date'];
                if (isset($arr['deletion_date']))
                    $arr['Deletion Date'] = $arr['deletion_date'];
                if (isset($arr['amount']))
                    $arr['Amount'] = ($arr['currency'] ?? 'INR') . ' ' . number_format((float)($arr['amount'] ?? 0), 2);
                if (isset($arr['status']))
                    $arr['Status'] = (isset($arr['status']) && (int)$arr['status'] == 1) ? 'Active' : 'Inactive';
                if (isset($arr['remarks']))
                    $arr['Remarks'] = is_string($arr['remarks']) ? (\App\Services\CryptService::decryptData($arr['remarks']) ?? $arr['remarks']) : $arr['remarks'];

                // Grace End Date: stored as due_date on the domain record
                $graceEndDate = $arr['due_date'] ?? $arr['grace_end_date'] ?? null;
                $arr['Grace End Date'] = $graceEndDate ?? 'N/A';

                // Grace Period (days)
                if (isset($arr['grace_period']) && $arr['grace_period'] !== null && $arr['grace_period'] !== '') {
                    $arr['Grace Period'] = $arr['grace_period'] . ' days';
                }

                // Domain Protect
                if (isset($arr['domain_protected'])) {
                    $arr['Domain Protect'] = ($arr['domain_protected'] == 1 || $arr['domain_protected'] === true || $arr['domain_protected'] === '1') ? 'Yes' : 'No';
                }

                return $arr;
            };


            $actionType = strtoupper($action === 'created' ? 'CREATE' : ($action === 'deleted' ? 'DELETE' : $action));

            ActivityLogger::logActivity(
                $user,
                $actionType,
                'Domains',
                'domains',
                $record->id,
                $standardize($oldData),
                $standardize($newData),
                null,
                request()
            );
        } catch (\Exception $e) {
        }
    }

    public function DomainList(Request $request)
    {
        $limit = $request->input('rowsPerPage', $request->input('limit', 10));
        $page = (int) $request->input('page', 1);
        if ($page < 1)
            $page = 1;

        $search = $request->input('search', '');
        $order = $request->input('order', 'desc');
        $orderBy = $request->input('orderBy', 'id');

        $query = Domain::select([
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

        // Apply search if $search is provided
        // Since many fields are encrypted, we might need to fetch all and filter in memory 
        // OR use specific IDs if we have a search index. 
        // For now, let's keep it simple and consistent with your other modules.

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

            $dmIds = \App\Models\DomainName::where('domain_name', 'LIKE', '%' . $searchLow . '%')->pluck('id');
            $pIds = \App\Models\Product::pluck('name', 'id')->filter(function ($n) use ($searchLow) {
                try {
                    $d = \App\Services\CryptService::decryptData($n) ?? $n;
                } catch (\Exception $e) {
                    $d = $n;
                }
                return str_contains(strtolower($d), $searchLow);
            })->keys();
            $cIds = \App\Models\Superadmin::pluck('name', 'id')->filter(function ($n) use ($searchLow) {
                try {
                    $d = \App\Services\CryptService::decryptData($n) ?? $n;
                } catch (\Exception $e) {
                    $d = $n;
                }
                return str_contains(strtolower($d), $searchLow);
            })->keys();
            $vIds = \App\Models\Vendor::pluck('name', 'id')->filter(function ($n) use ($searchLow) {
                try {
                    $d = \App\Services\CryptService::decryptData($n) ?? $n;
                } catch (\Exception $e) {
                    $d = $n;
                }
                return str_contains(strtolower($d), $searchLow);
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
        $filterBy = $request->input('filter_by');    // domain | client | product
        $filterValue = $request->input('filter_value');
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

        if ($request->input('all_ids')) {
            return response()->json([
                'status' => true,
                'ids' => (clone $query)->pluck('id')->toArray()
            ]);
        }

        $total = (clone $query)->count();
        $skip = ($page - 1) * $limit;

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
                $query->orderBy("{$tableName}.created_at", 'desc');
                break;
            default:
                $query->orderBy(str_contains($orderBy, '.') ? $orderBy : "{$tableName}.{$orderBy}", $order);
                break;
        }

        $rows = $query->skip($skip)
            ->take($limit)
            ->get()
            ->map(function ($item) {
                $today = now()->startOfDay();
                $item->days_left = $item->renewal_date ? $today->diffInDays(Carbon::parse($item->renewal_date)->startOfDay(), false) : null;
                $item->days_to_delete = $item->deletion_date ? $today->diffInDays(Carbon::parse($item->deletion_date)->startOfDay(), false) : null;

                // Decrypt Names
                $clientName = optional($item->client)->name;
                try {
                    $clientName = \App\Services\CryptService::decryptData($clientName) ?? $clientName;
                } catch (\Exception $e) {
                }

                $vendorName = optional($item->vendor)->name;
                try {
                    $vendorName = \App\Services\CryptService::decryptData($vendorName) ?? $vendorName;
                } catch (\Exception $e) {
                }

                $productName = optional($item->product)->name;
                try {
                    $productName = \App\Services\CryptService::decryptData($productName) ?? $productName;
                } catch (\Exception $e) {
                }

                $domainName = optional($item->domainMaster)->domain_name ?? '-';

                $remarks = $item->remarks;
                try {
                    $remarks = \App\Services\CryptService::decryptData($remarks) ?? $remarks;
                } catch (\Exception $e) {
                }

                $data = $item->toArray();
                $data['name'] = $domainName;
                $data['domain_name'] = $domainName;
                $data['client_name'] = $clientName;
                $data['vendor_name'] = $vendorName;
                $data['product_name'] = $productName;
                $data['remarks'] = $remarks;
                $data['days_left'] = $item->days_left;
                $data['days_to_delete'] = $item->days_to_delete;
                $data['last_updated'] = DateFormatterService::formatDateTime($item->updated_at);
                $data['updated_at_formatted'] = DateFormatterService::formatDateTime($item->updated_at);
                $data['created_at_formatted'] = DateFormatterService::formatDateTime($item->created_at ?? $item->updated_at);

                return $data;
            });

        return response()->json([
            'status' => true,
            'success' => true,
            'rows' => $rows,
            'total' => $total,
        ]);
    }

    public function index(Request $request)
    {
        $limit = $request->query('limit', $request->query('rowsPerPage', 100));
        $offset = $request->query('offset', $request->query('page', 0) * $limit);
        $search = $request->query('search', '');

        $query = Domain::select([
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
            ->whereNotNull('product_id') // 👈 Filter out stub domains (added via SSL/Dropdowns)
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
            // Only search relevant domains if a client scope is active
            $dQuery = \App\Models\Domain::query();
            ClientScopeService::applyScope($dQuery, $request);
            $dIds = $dQuery->with(['domainMaster', 'product', 'client', 'vendor'])->get()
                ->filter(function ($d) use ($search) {
                    $domainName = $d->domainMaster->domain_name ?? '';

                    $productName = $d->product->name ?? '';
                    try {
                        $productName = \App\Services\CryptService::decryptData($productName) ?? $productName;
                    } catch (\Exception $e) {
                    }

                    $clientName = $d->client->name ?? '';
                    try {
                        $clientName = \App\Services\CryptService::decryptData($clientName) ?? $clientName;
                    } catch (\Exception $e) {
                    }

                    $vendorName = $d->vendor->name ?? '';
                    try {
                        $vendorName = \App\Services\CryptService::decryptData($vendorName) ?? $vendorName;
                    } catch (\Exception $e) {
                    }

                    $remarks = '';
                    try {
                        $remarks = \App\Services\CryptService::decryptData($d->remarks) ?? $d->remarks;
                    } catch (\Exception $e) {
                        $remarks = $d->remarks;
                    }

                    $searchable = implode(' ', [
                        $domainName,
                        $productName,
                        $clientName,
                        $vendorName,
                        $remarks,
                        $d->amount,
                        $d->id
                    ]);

                    // Case-sensitive comparison
                    return str_contains($searchable, $search);
                })->pluck('id');

            $query->whereIn('id', $dIds);
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

        $records = collect($paginator->items())->map(function ($item) {
            $today = now()->startOfDay();
            $item->days_left = $item->renewal_date ? $today->diffInDays(Carbon::parse($item->renewal_date)->startOfDay(), false) : null;
            $item->days_to_delete = $item->deletion_date ? $today->diffInDays(Carbon::parse($item->deletion_date)->startOfDay(), false) : null;
            $item->client_name = $item->client->name ?? null;
            try {
                $item->client_name = \App\Services\CryptService::decryptData($item->client_name) ?? $item->client_name;
            } catch (\Exception $e) {
            }

            $item->product_name = $item->product->name ?? null;
            try {
                $item->product_name = \App\Services\CryptService::decryptData($item->product_name) ?? $item->product_name;
            } catch (\Exception $e) {
            }

            $item->vendor_name = $item->vendor->name ?? null;
            try {
                $item->vendor_name = \App\Services\CryptService::decryptData($item->vendor_name) ?? $item->vendor_name;
            } catch (\Exception $e) {
            }
            $item->has_remark_history = $item->remark_histories_count > 0;
            try {
                $item->remarks = \App\Services\CryptService::decryptData($item->remarks) ?? $item->remarks;
            } catch (\Exception $e) {
            }
            $data = $item->toArray();

            $domainName = optional($item->domainMaster)->domain_name ?? '-';

            $data['name'] = $domainName;
            $data['days_left'] = $item->days_left;
            $data['days_to_delete'] = $item->days_to_delete;
            $data['client_name'] = $item->client_name;
            $data['product_name'] = $item->product_name;
            $data['vendor_name'] = $item->vendor_name;
            $data['remarks'] = $item->remarks;
            $data['domain_name'] = $domainName;
            $data['grace_period'] = $item->grace_period ?? 0;
            $data['due_date'] = $item->due_date;

            $data['last_updated'] = DateFormatterService::formatDateTime($item->updated_at);
            $data['updated_at_formatted'] = DateFormatterService::formatDateTime($item->updated_at);
            $data['created_at_formatted'] = DateFormatterService::formatDateTime($item->created_at ?? $item->updated_at);
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
    public function store(Request $request)
    {
        return $this->storeDomain($request);
    }
    public function update(Request $request, $id)
    {
        $request->merge(['id' => $id]);
        return $this->updateDomain($request);
    }
    public function destroy($id)
    {
        return $this->deleteDomains(new \Illuminate\Http\Request(['id' => $id]));
    }

    public function storeDomain(Request $request)
    {
        try {
            ClientScopeService::enforceClientId($request);

            $request->merge([
                'domain_master_id' => $request->domain_master_id ?? data_get($request->domain_master_id, 'value'),
                'product_id' => $request->product_id ?? data_get($request->product, 'value'),
                'client_id' => $request->client_id ?? data_get($request->client, 'value'),
                'vendor_id' => $request->vendor_id ?? data_get($request->vendor, 'value'),
                'renewal_date' => $request->renewal_date ?? $request->expiry_date,
            ]);

            $validated = validator($request->all(), [
                'domain_master_id' => 'required|exists:domain_master,id',
                'product_id' => 'required|exists:products,id',
                'client_id' => 'required|exists:superadmins,id',
                'vendor_id' => 'required|exists:vendors,id',
                'amount' => 'required|numeric|min:0.01',
                'renewal_date' => 'required',
                'deletion_date' => 'required',
            ])->validate();

            if ($request->renewal_date)
                $request->merge(['renewal_date' => \Carbon\Carbon::parse($request->renewal_date)->format('Y-m-d')]);
            if ($request->deletion_date)
                $request->merge(['deletion_date' => \Carbon\Carbon::parse($request->deletion_date)->format('Y-m-d')]);

            $today = now()->startOfDay();
            $renewalDate = $request->renewal_date ? \Carbon\Carbon::parse($request->renewal_date)->startOfDay() : null;
            $deletionDate = $request->deletion_date ? \Carbon\Carbon::parse($request->deletion_date)->startOfDay() : null;

            // ── DUPLICATE CHECK ──
            $duplicateExists = Domain::where('domain_master_id', $request->domain_master_id)
                ->where('client_id', $request->client_id)
                ->where('renewal_date', $request->renewal_date)
                ->exists();

            if ($duplicateExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate entry: This domain already exists for the same client and renewal date'
                ], 422);
            }

            $days_left = $renewalDate ? $today->diffInDays($renewalDate, false) : null;
            $days_to_delete = $deletionDate ? $today->diffInDays($deletionDate, false) : null;

            $model = Domain::create([
                'domain_master_id' => $request->domain_master_id,
                'product_id' => $request->product_id,
                'client_id' => $request->client_id,
                'vendor_id' => $request->vendor_id,
                'amount' => $request->amount ?? 0,
                'renewal_date' => $request->renewal_date,
                'deletion_date' => $request->deletion_date,
                'days_left' => $days_left,
                'days_to_delete' => $days_to_delete,
                'domain_protected' => $request->domain_protected ?? 0,
                'grace_period' => $request->grace_period ?? 0,
                'status' => $request->status ?? 1,
                'remarks' => $request->remarks ? \App\Services\CryptService::encryptData($request->remarks) : null
            ]);

            \App\Services\GracePeriodService::syncModel($model);
            $model->save();

            $model->refresh()->load(['domainMaster', 'product', 'client', 'vendor']);

            $model->client_name = optional($model->client)->name;
            try {
                $model->client_name = \App\Services\CryptService::decryptData($model->client_name) ?? $model->client_name;
            } catch (\Exception $e) {
            }

            $model->product_name = optional($model->product)->name;
            try {
                $model->product_name = \App\Services\CryptService::decryptData($model->product_name) ?? $model->product_name;
            } catch (\Exception $e) {
            }

            $model->vendor_name = optional($model->vendor)->name;
            try {
                $model->vendor_name = \App\Services\CryptService::decryptData($model->vendor_name) ?? $model->vendor_name;
            } catch (\Exception $e) {
            }

            $domainName = optional($model->domainMaster)->domain_name ?? '-';

            $model->expiry_date = $model->renewal_date;
            $resp = $model->toArray();
            $resp['client_name'] = $model->client_name;
            $resp['product_name'] = $model->product_name;
            $resp['vendor_name'] = $model->vendor_name;
            $resp['remarks'] = $model->remarks ? \App\Services\CryptService::decryptData($model->remarks) : null;
            $resp['expiry_date'] = $model->expiry_date;

            $resp['name'] = $domainName;
            $resp['domain_name'] = $domainName;
            $resp['last_updated'] = DateFormatterService::formatDateTime($model->updated_at);
            $resp['updated_at_formatted'] = DateFormatterService::formatDateTime($model->updated_at);
            $resp['created_at_formatted'] = DateFormatterService::formatDateTime($model->created_at);

            $this->logActivity('created', $model, null, $resp);

            return response()->json([
                'status' => true,
                'success' => true,
                'data' => $resp
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile())
            ], 500);
        }
    }

    public function updateDomain(Request $request)
    {
        Log::info('Update Domain Request', ['url' => $request->fullUrl(), 'id' => $request->input('id'), 'payload' => $request->all()]);
        $id = $request->input('id');
        $record = Domain::find($id);
        if (!$record)
            return response()->json(['success' => false, 'message' => 'Not found'], 404);

        // ── OWNERSHIP GUARD: Client can only edit their own records ──
        ClientScopeService::assertOwnership($record, $request);

        $data = $request->all();
        foreach ($data as $key => $value) {
            if ($value === '')
                $data[$key] = null;
        }

        if (array_key_exists('expiry_date', $data) && !array_key_exists('renewal_date', $data)) {
            $data['renewal_date'] = $data['expiry_date'];
        }
        if (array_key_exists('valid_till', $data) && !array_key_exists('renewal_date', $data)) {
            $data['renewal_date'] = $data['valid_till'];
        }
        if (array_key_exists('grace_end_date', $data) && !array_key_exists('due_date', $data)) {
            $data['due_date'] = $data['grace_end_date'];
        }

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

        $validator = Validator::make($data, [
            'amount' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors()
            ], 422);
        }

        $this->calculateFields($data);

        // ── DUPLICATE CHECK ──
        $duplicateExists = Domain::where('domain_master_id', $data['domain_master_id'] ?? $record->domain_master_id)
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

        // Track Remark History
        \App\Services\RemarkHistoryService::logUpdate('Domain', $record, $data);

        if (isset($data['remarks'])) {
            $data['remarks'] = \App\Services\CryptService::encryptData($data['remarks']);
        }

        $oldData = clone $record;
        $record->update([
            'domain_master_id' => array_key_exists('domain_master_id', $data) ? $data['domain_master_id'] : $record->domain_master_id,
            'product_id' => array_key_exists('product_id', $data) ? $data['product_id'] : $record->product_id,
            'client_id' => array_key_exists('client_id', $data) ? $data['client_id'] : $record->client_id,
            'vendor_id' => array_key_exists('vendor_id', $data) ? $data['vendor_id'] : $record->vendor_id,
            'amount' => array_key_exists('amount', $data) ? $data['amount'] : $record->amount,
            'renewal_date' => array_key_exists('renewal_date', $data) ? $data['renewal_date'] : $record->renewal_date,
            'deletion_date' => array_key_exists('deletion_date', $data) ? $data['deletion_date'] : $record->deletion_date,
            'due_date' => array_key_exists('due_date', $data) ? $data['due_date'] : $record->due_date,
            'days_left' => array_key_exists('days_left', $data) ? $data['days_left'] : $record->days_left,
            'days_to_delete' => array_key_exists('days_to_delete', $data) ? $data['days_to_delete'] : $record->days_to_delete,
            'grace_period' => array_key_exists('grace_period', $data) ? $data['grace_period'] : $record->grace_period,
            'status' => array_key_exists('status', $data) ? $data['status'] : $record->status,
            'domain_protected' => array_key_exists('domain_protected', $data) ? $data['domain_protected'] : $record->domain_protected,
            'remarks' => array_key_exists('remarks', $data) ? $data['remarks'] : $record->remarks,
        ]);

        \App\Services\GracePeriodService::syncModel($record);
        $record->save();

        $record->refresh()->load(['domainMaster', 'product', 'client', 'vendor'])->loadCount('remarkHistories');

        $clientName = $record->client->name ?? null;
        try {
            $clientName = \App\Services\CryptService::decryptData($clientName) ?? $clientName;
        } catch (\Exception $e) {
        }

        $productName = $record->product->name ?? null;
        try {
            $productName = \App\Services\CryptService::decryptData($productName) ?? $productName;
        } catch (\Exception $e) {
        }

        $vendorName = $record->vendor->name ?? null;
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
            'message' => 'Domain Record updated successfully',
            'data' => $resp
        ]);
    }

    public function deleteDomains(Request $request)
    {
        $id = $request->input('domain_id') ?? $request->input('id');
        $record = Domain::find($id);
        if (!$record)
            return response()->json(['success' => false, 'message' => 'Not found'], 404);

        // ── OWNERSHIP GUARD: Client can only delete their own records ──
        ClientScopeService::assertOwnership($record, new \Illuminate\Http\Request());

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
            'message' => 'Domain Record deleted successfully'
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
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF")
                rewind($handle);

            $suffixCache = \Illuminate\Support\Facades\DB::table('suffix_masters')->pluck('suffix')->toArray();
            \Illuminate\Support\Facades\Log::info("Domain Import Suffix Cache: " . implode(', ', $suffixCache));

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

            $map = array_flip($headerMod);

                    $idxDomain = $map['domain'] ?? $map['domain_name'] ?? $map['url'] ?? $map['domain_id'] ?? -1;
                    $idxClient = $map['client'] ?? $map['client_id'] ?? $map['customer'] ?? $map['client_name'] ?? -1;
                    $idxProduct = $map['product'] ?? $map['product_id'] ?? $map['product_name'] ?? $map['name'] ?? -1;
                    $idxVendor = $map['vendor'] ?? $map['vendor_id'] ?? $map['vendor_name'] ?? -1;
                    $idxAmount = $map['amount'] ?? $map['price'] ?? $map['cost'] ?? $map['renewal_amount'] ?? -1;
                    $idxRenewal = $map['renewal_date'] ?? $map['renewal'] ?? $map['date'] ?? $map['expiry_date'] ?? $map['valid_till'] ?? $map['expiry'] ?? -1;
                    $idxDeletion = $map['deletion_date'] ?? $map['deletion'] ?? $map['delete_date'] ?? $map['grace_period_date'] ?? $map['due_date'] ?? $map['grace_end_date'] ?? -1;
                    $idxStatus = $map['status'] ?? $map['active'] ?? -1;
                    $idxRemarks = $map['remarks'] ?? $map['remark'] ?? $map['note'] ?? $map['notes'] ?? -1;

                    $mandatoryIndices = [
                        'Domain' => $idxDomain,
                        'Product' => $idxProduct,
                        'Client' => $idxClient,
                        'Vendor' => $idxVendor,
                        'Amount' => $idxAmount,
                        'Renewal Date' => $idxRenewal,
                        'Deletion Date' => $idxDeletion,
                    ];

                    $forceImport = $request->input('force_import') === 'true' || $request->input('force_import') === true;
                    if (!$forceImport) {
                        $rowNum = 1;
                        $issues = [];
                        while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                            $rowNum++;
                            if (empty(array_filter($data)))
                                continue;

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
                                'module_name' => 'Domain',
                                'action' => 'IMPORT',
                                'file_name' => $file->getClientOriginalName(),
                                'imported_by' => $user->name ?? 'System / Admin',
                                'successful_rows' => 0,
                                'failed_rows' => count($issues),
                                'duplicates_count' => 0,
                                'data_snapshot' => json_encode($issues),
                                'user_id' => $user->id ?? 1,
                                'client_id' => $user->id ?? 1,
                                'role' => $user->role ?? 'Admin'
                            ]);
                            \App\Services\AuditFileService::storeImport($history, $file);

                            \App\Services\ActivityLogger::imported($user->id ?? 1, 'Domain', 0, $history->id, count($issues), 0);

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
                            $rawClient = $idxClient !== -1 ? trim($data[$idxClient] ?? '') : '';
                            $rawDomain = $idxDomain !== -1 ? trim($data[$idxDomain] ?? '') : 'default.com';
                            $rawVendor = $idxVendor !== -1 ? trim($data[$idxVendor] ?? '') : '';
                            $rawAmount = $idxAmount !== -1 ? ($data[$idxAmount] ?? 0) : 0;
                            $rawRenewal = $idxRenewal !== -1 ? ($data[$idxRenewal] ?? null) : null;
                            $rawDeletion = $idxDeletion !== -1 ? ($data[$idxDeletion] ?? null) : null;
                            $rawStatus = $idxStatus !== -1 ? trim($data[$idxStatus] ?? '1') : '1';
                            $rawRemarks = $idxRemarks !== -1 ? trim($data[$idxRemarks] ?? '') : '';

                            if (!$rawProduct) throw new \Exception("Product Name is missing");
                            if (!$rawClient && !$clientIdFromRequest) throw new \Exception("Client Name is missing");
                            if (!$rawVendor) throw new \Exception("Vendor Name is missing");
                            if (!$rawAmount || !is_numeric(str_replace([',', ' '], '', (string) $rawAmount))) throw new \Exception("Valid Amount is missing");
                            if (!$rawRenewal) throw new \Exception("Renewal Date is missing");
                            if (!$rawDeletion) throw new \Exception("Deletion Date is missing");

                            // Strict Amount Check
                            if ($rawAmount && !is_numeric(str_replace([',', ' '], '', (string) $rawAmount))) {
                                throw new \Exception("Invalid Amount format: " . $rawAmount);
                            }
                            $amount = (float) str_replace([',', ' '], '', (string) $rawAmount);

                            $renewalDate = $parseDate($rawRenewal);
                            if (!$renewalDate) {
                                throw new \Exception("Invalid Renewal Date: '$rawRenewal'");
                            }

                            $deletionDate = $parseDate($rawDeletion);
                            if (!$deletionDate && $rawDeletion) {
                                throw new \Exception("Invalid Deletion Date format: " . $rawDeletion);
                            }

                            $status = (strtolower(trim($rawStatus)) === 'active' || $rawStatus === '1' || $rawStatus === '') ? 1 : 0;

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
                                $domainMasterCache[$domainLower] = $dId;
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
                            $vId = $vendorCache[strtolower(trim($rawVendor))] ?? null;
                            if (!$vId) {
                                $vId = \Illuminate\Support\Facades\DB::table('vendors')->insertGetId(['name' => \App\Services\CryptService::encryptData(trim($rawVendor)), 'created_at' => now(), 'updated_at' => now()]);
                                $vendorCache[strtolower(trim($rawVendor))] = $vId;
                            }

                            $amount = (float) str_replace([',', ' '], '', (string) $rawAmount);
                            $renewalDate = $parseDate($rawRenewal);
                            $status = (strtolower(trim($rawStatus)) === 'active' || $rawStatus === '1' || $rawStatus === '') ? 1 : 0;

                            $exists = \Illuminate\Support\Facades\DB::table('domains')
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

                            \Illuminate\Support\Facades\DB::table('domains')->insert([
                                'domain_master_id' => $dId,
                                'client_id' => $cId,
                                'product_id' => $pId,
                                'vendor_id' => $vId,
                                'amount' => $amount,
                                'renewal_date' => $renewalDate,
                                'deletion_date' => $deletionDate,
                                'status' => $status,
                                'remarks' => \App\Services\CryptService::encryptData($rawRemarks),
                                'due_date' => $deletionDate ?? $renewalDate,
                                'grace_period' => $grace_period,
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
            $filePath = $file->store('imports');
            $user = Auth::user();
            $userId = $user->id ?? 1;
            $role = $user->role ?? 'System';

            if ($inserted > 0)
                ActivityLogger::imported($userId, 'Domain', $inserted);

            $history = ImportHistory::create([
                'user_id' => $userId,
                'role' => $role,
                'module_name' => 'Domain',
                'action' => 'IMPORT',
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $filePath,
                'imported_by' => $user->name ?? 'System / Admin',
                'successful_rows' => $inserted,
                'failed_rows' => $failed,
                'duplicates_count' => $duplicates
            ]);

            if ($duplicates > 0) {
                \App\Services\AuditFileService::storeDuplicates($history, $firstRow, $duplicateRows);
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

            return response()->json(['success' => ($failed === 0), 'inserted' => $inserted, 'failed' => $failed, 'duplicates' => $duplicates, 'message' => "Import processed: $inserted added, $failed failed."]);
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
                $record = Domain::with(['product', 'client', 'vendor', 'domainMaster'])->find($id);
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

                ActivityLogger::logActivity(
                    $uObj,
                    'DELETE',
                    'Domains',
                    'domains',
                    $record->id,
                    $logData,
                    null,
                    "Domain Record Deleted",
                    $request
                );

                $record->delete();
                $deletedCount++;
            }

            return response()->json(['status' => true, 'success' => true, 'message' => $deletedCount . ' Domain records deleted successfully']);
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
                \Illuminate\Support\Facades\DB::table('domains')->distinct()->pluck('domain_master_id')
            )->orderBy('domain_name')
                ->pluck('domain_name')
                ->filter()
                ->values()
                ->toArray();
        } elseif ($category === 'client') {
            $data = \App\Models\Superadmin::whereIn(
                'id',
                \Illuminate\Support\Facades\DB::table('domains')->distinct()->pluck('client_id')
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
                \Illuminate\Support\Facades\DB::table('domains')->distinct()->pluck('product_id')
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
