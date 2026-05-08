<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Product;
use App\Models\Superadmin;
use App\Models\Vendor;
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

class SubscriptionController extends Controller
{
    use \App\Traits\DataNormalizer;

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

        // Log History First
        $history = null;
        try {
            $history = ImportHistory::create([
                'module_name' => 'Subscription',
                'action' => 'EXPORT',
                'file_name' => 'Subscription_Export_' . date('Ymd_His') . '.csv',
                'imported_by' => $userName,
                'successful_rows' => $validated['total_records'],
                'data_snapshot' => $request->has('data_snapshot') ? json_encode($request->input('data_snapshot')) : null,
            ]);

            // Persist file physically if data is present using NEW SERVICE
            if ($request->has('data_snapshot')) {
                \App\Services\AuditFileService::storeExport($history, $request->input('data_snapshot'));
            }
        } catch (\Exception $e) {
            Log::error("SubscriptionController::logExport failed: " . $e->getMessage());
            // Fallback
            try {
                $history = ImportHistory::create([
                    'module_name' => 'Subscription',
                    'action' => 'EXPORT',
                    'file_name' => 'Subscription_Export_' . date('Ymd_His') . '.csv',
                    'imported_by' => $userName,
                    'successful_rows' => $validated['total_records'],
                ]);
            } catch (\Exception $e2) {
            }
        }

        // Log Activity with History ID
        try {
            ActivityLogger::exported($userId, 'Subscription', $validated['total_records'], $history ? $history->id : null);
        } catch (\Exception $e) {
        }

        return Response::json(['success' => true, 'data' => $history]);
    }

    private function formatDate($date)
    {
        if (empty($date))
            return null;
        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
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

    public function index(Request $request)
    {
        $limit = $request->query('limit', 100);
        $offset = $request->query('offset', 0);
        $search = $request->query('search', '');

        $query = Subscription::select([
            'id',
            'product_id',
            'client_id',
            'vendor_id',
            'domain_master_id',
            'amount',
            'currency',
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
                'product:id,name',
                'client:id,name',
                'vendor:id,name',
                'domainMaster:id,domain_name'
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
                    $date = Carbon::parse(str_replace(['/', '.'], '-', $search));
                    $dateMatch = $date->format('Y-m-d');
                } catch (\Exception $e) {
                }
            }

            $dmIds = \App\Models\DomainName::where('domain_name', 'LIKE', '%' . $searchLow . '%')->pluck('id');

            $pIds = Product::pluck('name', 'id')->filter(function ($n) use ($searchLow) {
                try {
                    $d = CryptService::decryptData($n) ?? $n;
                } catch (\Exception $e) {
                    $d = $n;
                }
                return str_contains(strtolower($d), $searchLow);
            })->keys();
            $cIds = Superadmin::pluck('name', 'id')->filter(function ($n) use ($searchLow) {
                try {
                    $d = CryptService::decryptData($n) ?? $n;
                } catch (\Exception $e) {
                    $d = $n;
                }
                return str_contains(strtolower($d), $searchLow);
            })->keys();
            $vIds = Vendor::pluck('name', 'id')->filter(function ($n) use ($searchLow) {
                try {
                    $d = CryptService::decryptData($n) ?? $n;
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
        $filterBy = $request->query('filter_by');    // domain | client | product
        $filterValue = $request->query('filter_value');
        if ($filterBy && $filterValue) {
            if ($filterBy === 'domain') {
                $dmIds = \App\Models\DomainName::where('domain_name', $filterValue)->pluck('id');
                $query->whereIn('domain_master_id', $dmIds);
            } elseif ($filterBy === 'client') {
                $cIds = Superadmin::pluck('name', 'id')
                    ->filter(function ($name) use ($filterValue) {
                        try {
                            $dec = CryptService::decryptData($name) ?? $name;
                        } catch (\Exception $e) {
                            $dec = $name;
                        }
                        return strtolower(trim($dec)) === strtolower(trim($filterValue));
                    })->keys();
                $query->whereIn('client_id', $cIds);
            } elseif ($filterBy === 'product') {
                $pIds = Product::pluck('name', 'id')
                    ->filter(function ($name) use ($filterValue) {
                        try {
                            $dec = CryptService::decryptData($name) ?? $name;
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
            return Response::json($query->pluck('id'));
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

        $records = \Illuminate\Support\Collection::make($paginator->items())->map(function ($item) {
            $today = Carbon::now()->startOfDay();
            $item->days_left = $item->renewal_date ? $today->diffInDays(Carbon::parse($item->renewal_date)->startOfDay(), false) : null;
            $item->days_to_delete = $item->deletion_date ? $today->diffInDays(Carbon::parse($item->deletion_date)->startOfDay(), false) : null;
            $item->grace_period = $item->due_date ? $today->diffInDays(Carbon::parse($item->due_date)->startOfDay(), false) : null;

            // Decrypt Names
            $clientName = optional($item->client)->name;
            try {
                $clientName = CryptService::decryptData($clientName) ?? $clientName;
            } catch (\Exception $e) {
            }

            $vendorName = optional($item->vendor)->name;
            try {
                $vendorName = CryptService::decryptData($vendorName) ?? $vendorName;
            } catch (\Exception $e) {
            }

            $productName = optional($item->product)->name;
            try {
                $productName = CryptService::decryptData($productName) ?? $productName;
            } catch (\Exception $e) {
            }

            $domainName = optional($item->domainMaster)->domain_name ?? '-';

            $remarks = $item->remarks;
            try {
                $remarks = CryptService::decryptData($remarks) ?? $remarks;
            } catch (\Exception $e) {
            }

            $data = $item->toArray();
            $data['domain_name'] = $domainName;
            $data['days_left'] = $item->days_left;
            $data['days_to_delete'] = $item->days_to_delete;
            $data['client_name'] = $clientName;
            $data['product_name'] = $productName;
            $data['vendor_name'] = $vendorName;
            $data['remarks'] = $remarks;
            $data['has_remark_history'] = $item->remark_histories_count > 0;
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

    public function logExportActivity($count)
    {
        ActivityLogger::logActivity(
            Auth::user(),
            'EXPORT',
            'Subscription',
            'subscriptions',
            null,
            null,
            [
                'message' => "Exported {$count} subscription records",
                'count' => $count
            ],
            null,
            Request::instance()
        );
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
                'renewal_date' => $request->renewal_date ?? $request->expiry_date,
            ]);

            $validated = Validator::make($request->all(), [
                'domain_master_id' => 'required|exists:domain_master,id',
                'product_id' => 'required|exists:products,id',
                'client_id' => 'required|exists:superadmins,id',
                'vendor_id' => 'required|exists:vendors,id',
                'amount' => 'required|numeric',
                'renewal_date' => 'required|date',
                'deletion_date' => 'required|date',
                'grace_period' => 'required'
            ])->validate();

            if ($request->renewal_date)
                $request->merge(['renewal_date' => Carbon::parse($request->renewal_date)->format('Y-m-d')]);
            if ($request->deletion_date)
                $request->merge(['deletion_date' => Carbon::parse($request->deletion_date)->format('Y-m-d')]);

            $today = now()->startOfDay();

            // ── DUPLICATE CHECK ──
            $duplicateExists = Subscription::where('domain_master_id', $request->domain_master_id)
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

            $dueDate = $request->due_date ?? $request->grace_end_date;
            $gracePeriod = $dueDate ? now()->startOfDay()->diffInDays(Carbon::parse($dueDate)->startOfDay(), false) : ($request->grace_period ?? 0);

            $model = Subscription::create([
                'domain_master_id' => $request->domain_master_id,
                'product_id' => $request->product_id,
                'client_id' => $request->client_id,
                'vendor_id' => $request->vendor_id,
                'amount' => $request->amount,
                'currency' => $request->currency ?? 'INR',
                'renewal_date' => $request->renewal_date,
                'deletion_date' => $request->deletion_date,
                'due_date' => $dueDate,
                'days_left' => $days_left,
                'days_to_delete' => $days_to_delete,
                'grace_period' => $gracePeriod,
                'status' => $request->status ?? 1,
                'remarks' => $request->remarks ? CryptService::encryptData($request->remarks) : null
            ]);

            $model->save();

            // Manually handle status inactivation if due_date is in the past
            if ($model->due_date && now()->startOfDay()->greaterThan(Carbon::parse($model->due_date)->startOfDay())) {
                if ($model->status == 1) {
                    $model->status = 0;
                    $model->save();
                }
            }

            $model->refresh()->load(['domainMaster', 'product', 'client', 'vendor']);

            $clientName = optional($model->client)->name;
            try {
                $clientName = CryptService::decryptData($clientName) ?? $clientName;
            } catch (\Exception $e) {
            }

            $productName = optional($model->product)->name;
            try {
                $productName = CryptService::decryptData($productName) ?? $productName;
            } catch (\Exception $e) {
            }

            $vendorName = optional($model->vendor)->name;
            try {
                $vendorName = CryptService::decryptData($vendorName) ?? $vendorName;
            } catch (\Exception $e) {
            }

            $resp = $model->toArray();
            $resp['domain_name'] = $model->domainMaster->domain_name ?? 'N/A';
            $resp['client_name'] = $clientName;
            $resp['product_name'] = $productName;
            $resp['vendor_name'] = $vendorName;
            $resp['remarks'] = $request->remarks;
            $resp['last_updated'] = DateFormatterService::formatDateTime($model->updated_at);
            $resp['updated_at_formatted'] = DateFormatterService::formatDateTime($model->updated_at);
            $resp['created_at_formatted'] = DateFormatterService::formatDateTime($model->created_at);
            ActivityLogger::logActivity(Auth::user(), 'CREATE', 'Subscription', 'subscriptions', $model->id, null, $resp, null, $request);

            return Response::json([
                'success' => true,
                'data' => $resp
            ], 200);

        } catch (\Exception $e) {
            return Response::json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $subscription = Subscription::find($id);
        if (!$subscription)
            return Response::json(['success' => false, 'message' => 'Not found'], 404);

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

        if (isset($data['due_date'])) {
            $due = $data['due_date'];
            if ($due) {
                $data['grace_period'] = now()->startOfDay()->diffInDays(Carbon::parse($due)->startOfDay(), false);
            }
        }

        $this->calculateFields($data);

        // ── DUPLICATE CHECK ──
        $duplicateExists = Subscription::where('domain_master_id', $data['domain_master_id'] ?? $subscription->domain_master_id)
            ->where('client_id', $data['client_id'] ?? $subscription->client_id)
            ->where('renewal_date', $data['renewal_date'] ?? $subscription->renewal_date)
            ->where('id', '!=', $id)
            ->exists();

        if ($duplicateExists) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate entry: This domain already exists for the same client and renewal date'
            ], 422);
        }

        \App\Services\RemarkHistoryService::logUpdate('Subscription', $subscription, $data);

        if (isset($data['remarks']))
            $data['remarks'] = CryptService::encryptData($data['remarks']);

        $oldData = $subscription->toArray();
        $subscription->update($data);

        $subscription->save();

        // Manually handle status inactivation if due_date is in the past
        if ($subscription->due_date && now()->startOfDay()->greaterThan(Carbon::parse($subscription->due_date)->startOfDay())) {
            if ($subscription->status == 1) {
                $subscription->status = 0;
                $subscription->save();
            }
        }

        $subscription->refresh()->load(['domainMaster', 'product', 'client', 'vendor'])->loadCount('remarkHistories');

        $clientName = optional($subscription->client)->name;
        try {
            $clientName = CryptService::decryptData($clientName) ?? $clientName;
        } catch (\Exception $e) {
        }

        $productName = optional($subscription->product)->name;
        try {
            $productName = CryptService::decryptData($productName) ?? $productName;
        } catch (\Exception $e) {
        }

        $vendorName = optional($subscription->vendor)->name;
        try {
            $vendorName = CryptService::decryptData($vendorName) ?? $vendorName;
        } catch (\Exception $e) {
        }

        $remarks = $subscription->remarks;
        try {
            $remarks = CryptService::decryptData($remarks) ?? $remarks;
        } catch (\Exception $e) {
        }

        $resp = $subscription->toArray();
        $resp['domain_name'] = self::normalizeData($subscription->domainMaster->domain_name ?? 'N/A', 'Domain');
        $resp['client_name'] = self::normalizeData($clientName, 'Client');
        $resp['product_name'] = self::normalizeData($productName, 'Product');
        $resp['vendor_name'] = self::normalizeData($vendorName, 'Vendor');
        $resp['remarks'] = self::normalizeData($remarks, 'Remarks');

        $resp['has_remark_history'] = $subscription->remark_histories_count > 0;
        $resp['last_updated'] = DateFormatterService::formatDateTime($subscription->updated_at);
        $resp['updated_at_formatted'] = DateFormatterService::formatDateTime($subscription->updated_at);
        $resp['created_at_formatted'] = DateFormatterService::formatDateTime($subscription->created_at);

        ActivityLogger::logActivity(auth()->user(), 'UPDATE', 'Subscription', 'subscriptions', $subscription->id, $oldData, $resp, null, $request);

        return Response::json([
            'success' => true,
            'message' => 'Subscription updated successfully',
            'data' => $resp
        ]);
    }

    public function destroy($id)
    {
        $subscription = Subscription::with(['product', 'client', 'vendor', 'domainMaster'])->find($id);
        if (!$subscription)
            return Response::json(['success' => false, 'message' => 'Not found'], 404);

        // ── OWNERSHIP GUARD ──
        ClientScopeService::assertOwnership($subscription, Request::instance());

        // Enrich data with resolved names before logging deletion
        $logData = $subscription->toArray();
        $pName = $subscription->product->name ?? null;
        $cName = $subscription->client->name ?? null;
        $vName = $subscription->vendor->name ?? null;

        try {
            if ($pName)
                $pName = CryptService::decryptData($pName) ?? $pName;
        } catch (\Exception $e) {
        }
        try {
            if ($cName)
                $cName = CryptService::decryptData($cName) ?? $cName;
        } catch (\Exception $e) {
        }
        try {
            if ($vName)
                $vName = CryptService::decryptData($vName) ?? $vName;
        } catch (\Exception $e) {
        }

        $logData['Product'] = $pName ?: 'N/A';
        $logData['Client'] = $cName ?: 'N/A';
        $logData['Vendor'] = $vName ?: 'N/A';
        $logData['Domain'] = $subscription->domainMaster->domain_name ?? 'N/A';
        $logData['Amount'] = (float) $subscription->amount;

        ActivityLogger::logActivity(Auth::user(), 'DELETE', 'Subscription', 'subscriptions', $subscription->id, $logData, null, null, Request::instance());

        $subscription->delete();

        return Response::json([
            'success' => true,
            'message' => 'Subscription deleted successfully'
        ]);
    }

    use \App\Traits\NativeXlsxParser;

    public function import(Request $request)
    {
        $user = \Illuminate\Support\Facades\Auth::user();
        $userId = $user ? $user->id : 1;

        if (!$request->hasFile('file')) {
            return Response::json(['success' => false, 'message' => 'File not received'], 400);
        }

        $forceImport = $request->input('force_import') === 'true' || $request->input('force_import') === true;

        try {
            $file = $request->file('file');
            $extension = strtolower($file->getClientOriginalExtension());

            if ($extension === 'xlsx') {
                $content = $this->parseXlsxToCsvString($file->getRealPath());
            } else {
                $content = file_get_contents($file->getRealPath());
            }

            if (!$content)
                return Response::json(['success' => false, 'message' => 'Failed to read file content'], 400);

            $handle = fopen('php://temp', 'r+');
            fwrite($handle, $content);
            rewind($handle);

            // Handle UTF-8 BOM if present
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            // 1. Setup Header & Mapping
            $firstRow = fgetcsv($handle, 1000, ',');
            if (!$firstRow) {
                fclose($handle);
                return Response::json(['success' => false, 'message' => 'Empty file'], 400);
            }

            $headerMod = array_map(function($h) { return str_replace([' ', '-'], '_', strtolower(trim($h ?? ''))); }, $firstRow);
            
            // Strict Header Validation
            $allowedHeaders = [
                'domain', 'product', 'client', 'vendor', 'amount', 'currency', 
                'renewal_date', 'deletion_date', 'status', 'remarks', 'grace_end_date',
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
            \Illuminate\Support\Facades\Log::info("Import Suffix Cache: " . implode(', ', $suffixCache));

            $map = array_flip($headerMod);

            $idxDomain = $map['domain'] ?? $map['domain_name'] ?? $map['url'] ?? -1;
            $idxProduct = $map['product'] ?? $map['product_id'] ?? $map['product_name'] ?? $map['name'] ?? -1;
            $idxClient = $map['client'] ?? $map['client_id'] ?? $map['customer'] ?? $map['client_name'] ?? -1;
            $idxVendor = $map['vendor'] ?? $map['vendor_id'] ?? $map['vendor_name'] ?? -1;
            $idxAmount = $map['amount'] ?? $map['price'] ?? $map['cost'] ?? -1;
            $idxRenewal = $map['renewal_date'] ?? $map['renewal'] ?? $map['date'] ?? $map['expiry_date'] ?? $map['valid_till'] ?? $map['renewal_da'] ?? -1;
            $idxStatus = $map['status'] ?? -1;
            $idxRemarks = $map['remarks'] ?? $map['remark'] ?? $map['note'] ?? $map['notes'] ?? -1;
            $idxDeletion = $map['deletion_date'] ?? $map['deletion'] ?? $map['grace_period_date'] ?? -1;

            $mandatoryIndices = [
                'domain' => $idxDomain,
                'product' => $idxProduct,
                'client' => $idxClient,
                'vendor' => $idxVendor,
                'amount' => $idxAmount,
                'renewal_date' => $idxRenewal,
                'status' => $idxStatus,
                'deletion_date' => $idxDeletion,
                'grace_end_date' => $map['grace_end_date'] ?? -1,
            ];

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
                            // Format checks
                            if ($field === 'amount') {
                                $cleanAmount = str_replace([',', ' '], '', $val);
                                if (!is_numeric($cleanAmount)) {
                                    $missing[] = "amount (invalid number: '$val')";
                                }
                            }

                            if ($field === 'renewal_date') {
                                if ($val !== '') {
                                    $parsed = self::robustParseDate($val);
                                    if (!$parsed) {
                                        $missing[] = "renewal_date (invalid format: '$val'. Please use YYYY-MM-DD or DD-MM-YYYY)";
                                    }
                                }
                            }
                        }
                    }

                    // Optional date validation (deletion_date)
                    if ($idxDeletion !== -1 && isset($data[$idxDeletion])) {
                        $dVal = trim((string) $data[$idxDeletion]);
                        if ($dVal !== '' && $dVal !== '--' && $dVal !== 'N/A') {
                            if (!self::robustParseDate($dVal)) {
                                $missing[] = "deletion_date (invalid format: '$dVal')";
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
                        $issues[] = [
                            'row' => $rowNum,
                            'missing_fields' => $missing
                        ];
                    }
                }

                if (!empty($issues)) {
                    fclose($handle);

                    $history = \App\Models\ImportHistory::create([
                        'module_name' => 'Subscription',
                        'action' => 'IMPORT',
                        'file_name' => $file->getClientOriginalName(),
                        'imported_by' => $user->name ?? 'System / Admin',
                        'successful_rows' => 0,
                        'failed_rows' => count($issues),
                        'duplicates_count' => 0,
                        'data_snapshot' => json_encode($issues)
                    ]);
                    \App\Services\AuditFileService::storeImport($history, $file);

                    \App\Services\ActivityLogger::imported($user->id, 'Subscription', 0, $history->id, count($issues), 0);

                    return Response::json([
                        'success' => false,
                        'requires_confirmation' => true,
                        'message' => 'Validation failed: Mandatory fields are missing.',
                        'issues' => $issues,
                        'history_id' => $history->id,
                        'total_affected' => count($issues)
                    ], 422);
                }
                rewind($handle);
                fgetcsv($handle); // Skip header again
            }

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
                    $name = CryptService::decryptData($p->name);
                } catch (\Throwable $e) {
                    $name = $p->name;
                }
                $productCache[strtolower(trim($name ?? ''))] = (int) $p->id;
            });
            \Illuminate\Support\Facades\DB::table('superadmins')->get(['id', 'name'])->each(function ($c) use (&$clientCache) {
                try {
                    $name = CryptService::decryptData($c->name);
                } catch (\Throwable $e) {
                    $name = $c->name;
                }
                $clientCache[strtolower(trim($name ?? ''))] = (int) $c->id;
            });
            \Illuminate\Support\Facades\DB::table('vendors')->get(['id', 'name'])->each(function ($v) use (&$vendorCache) {
                try {
                    $name = CryptService::decryptData($v->name);
                } catch (\Throwable $e) {
                    $name = $v->name;
                }
                $vendorCache[strtolower(trim($name ?? ''))] = (int) $v->id;
            });
            \Illuminate\Support\Facades\DB::table('domain_master')->get(['id', 'domain_name'])->each(function ($dm) use (&$domainMasterCache) {
                $domainMasterCache[strtolower(trim($dm->domain_name ?? ''))] = (int) $dm->id;
            });

            $clientIdFromRequest = $request->input('client_id');

            while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                try {
                    if (empty(array_filter($data)))
                        continue;

                    $parseDate = function ($val) {
                        return self::robustParseDate($val);
                    };

                    $rawDomain = self::normalizeData(($idxDomain !== -1 && isset($data[$idxDomain])) ? trim($data[$idxDomain]) : 'default.com', 'Domain');
                    if ($rawDomain === '')
                        $rawDomain = 'default.com';

                    $rawProduct = self::normalizeData(($idxProduct !== -1 && isset($data[$idxProduct])) ? trim($data[$idxProduct]) : 'General Product', 'Product');
                    if ($rawProduct === '')
                        $rawProduct = 'General Product';

                    $rawClient = self::normalizeData(($idxClient !== -1 && isset($data[$idxClient])) ? trim($data[$idxClient]) : 'Generic Client', 'Client');
                    if ($rawClient === '')
                        $rawClient = 'Generic Client';

                    $rawVendor = self::normalizeData(($idxVendor !== -1 && isset($data[$idxVendor])) ? trim($data[$idxVendor]) : 'N/A', 'Vendor');
                    if ($rawVendor === '')
                        $rawVendor = 'N/A';

                    $rawAmount = $idxAmount !== -1 ? ($data[$idxAmount] ?? 0) : 0;
                    $rawRenewal = $idxRenewal !== -1 ? ($data[$idxRenewal] ?? null) : null;
                    $rawDeletion = $idxDeletion !== -1 ? ($data[$idxDeletion] ?? null) : null;
                    $rawStatus = self::normalizeData(($idxStatus !== -1 && isset($data[$idxStatus])) ? trim($data[$idxStatus]) : '1', 'Status');
                    if ($rawStatus === '')
                        $rawStatus = '1';

                    $rawRemarks = self::normalizeData(($idxRemarks !== -1 && isset($data[$idxRemarks])) ? trim($data[$idxRemarks]) : 'N/A', 'Remarks');
                    if ($rawRemarks === '')
                        $rawRemarks = 'N/A';

                    \Illuminate\Support\Facades\Log::info("Processing Row: " . ($inserted + $failed + $duplicates + 1), ['domain' => $rawDomain]);

                    // Resolve Domain
                    $domainLower = strtolower(trim($rawDomain));
                    if (!str_contains($domainLower, '.')) {
                        \Illuminate\Support\Facades\Log::warning("Domain missing dot: $rawDomain");
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
                        \Illuminate\Support\Facades\Log::warning("Suffix not found for: $rawDomain");
                        throw new \Exception("Invalid Domain: '$rawDomain'. Suffix not found in Suffix Master.");
                    }

                    $dId = $domainMasterCache[$domainLower] ?? null;
                    if (!$dId) {
                        $dId = DB::table('domain_master')->insertGetId(['domain_name' => trim($rawDomain), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
                        $domainMasterCache[$domainLower] = $dId;
                    }

                    // Resolve Product
                    $pId = $productCache[strtolower(trim($rawProduct))] ?? null;
                    if (!$pId) {
                        $pId = DB::table('products')->insertGetId(['name' => CryptService::encryptData(trim($rawProduct)), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
                        $productCache[strtolower(trim($rawProduct))] = $pId;
                    }

                    // Resolve Client
                    $cId = $clientIdFromRequest;
                    if (!$cId && $rawClient) {
                        $cId = $clientCache[strtolower(trim($rawClient))] ?? null;
                        if (!$cId) {
                            $cId = \Illuminate\Support\Facades\DB::table('superadmins')->insertGetId([
                                'name' => CryptService::encryptData(trim($rawClient)),
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
                        $vId = \Illuminate\Support\Facades\DB::table('vendors')->insertGetId(['name' => CryptService::encryptData($rawVendor), 'created_at' => now(), 'updated_at' => now()]);
                        $vendorCache[strtolower(trim($rawVendor))] = $vId;
                    }

                    $amount = (float) str_replace([',', ' '], '', (string) $rawAmount);
                    $renewalDate = $parseDate($rawRenewal);
                    if (!$renewalDate) {
                        throw new \Exception("Invalid Renewal Date: '$rawRenewal'");
                    }
                    $deletionDate = $parseDate($rawDeletion);
                    if (!$deletionDate && $rawDeletion && trim($rawDeletion) !== '') {
                        throw new \Exception("Invalid Deletion Date format: " . $rawDeletion);
                    }
                    $status = (strtolower(trim($rawStatus)) === 'active' || $rawStatus === '1' || $rawStatus === '') ? 1 : 0;

                    $exists = \Illuminate\Support\Facades\DB::table('subscriptions')
                        ->where('domain_master_id', $dId)
                        ->where('client_id', $cId)
                        ->where('renewal_date', $renewalDate)
                        ->exists();

                    if ($exists) {
                        $duplicates++;
                        $duplicateRows[] = $data;
                        continue;
                    }

                    \Illuminate\Support\Facades\DB::table('subscriptions')->insert([
                        'domain_master_id' => $dId,
                        'client_id' => $cId,
                        'product_id' => $pId,
                        'vendor_id' => $vId,
                        'amount' => $amount,
                        'renewal_date' => $renewalDate,
                        'deletion_date' => $deletionDate,
                        'status' => $status,
                        'remarks' => CryptService::encryptData($rawRemarks),
                        'due_date' => $deletionDate ?? $renewalDate,
                        'updated_at' => Carbon::now(),
                        'created_at' => Carbon::now()
                    ]);
                    $inserted++;
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = $e->getMessage();
                }
            }
            fclose($handle);

            $history = ImportHistory::create([
                'module_name' => 'Subscription',
                'action' => 'IMPORT',
                'file_name' => $file->getClientOriginalName(),
                'imported_by' => $user->name ?? 'System / Admin',
                'successful_rows' => $inserted,
                'failed_rows' => $failed,
                'duplicates_count' => $duplicates
            ]);

            // Save file via AuditFileService
            \App\Services\AuditFileService::storeImport($history, $file);

            if ($duplicates > 0) {
                \Illuminate\Support\Facades\Log::info("SubscriptionController: Attempting to store $duplicates duplicates for history ID: {$history->id}");
                $dupPath = \App\Services\AuditFileService::storeDuplicates($history, $firstRow, $duplicateRows);
                if ($dupPath) {
                    \Illuminate\Support\Facades\Log::info("SubscriptionController: Successfully stored duplicates at $dupPath");
                } else {
                    \Illuminate\Support\Facades\Log::error("SubscriptionController: Failed to store duplicates for history ID: {$history->id}");
                }
            }

            if ($inserted > 0)
                ActivityLogger::imported($userId, 'Subscription', $inserted, $history->id);

            if ($inserted === 0 && $failed > 0) {
                return Response::json([
                    'success' => false,
                    'inserted' => 0,
                    'failed' => $failed,
                    'duplicates' => $duplicates,
                    'message' => "Import failed: $failed rows had errors. " . ($errors[0] ?? "")
                ], 422);
            }

            return Response::json(['success' => true, 'inserted' => $inserted, 'failed' => $failed, 'duplicates' => $duplicates, 'message' => "Import processed: $inserted added, $failed failed."]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'message' => 'Import failed: ' . $e->getMessage()], 500);
        }
    }

    public function bulkDelete(Request $request)
    {
        $ids = $request->get('ids', []);
        if (empty($ids))
            return Response::json(['success' => false, 'message' => 'No IDs provided'], 400);

        try {
            $records = Subscription::with(['product', 'client', 'vendor', 'domainMaster'])->whereIn('id', $ids)->get();
            $deletedCount = $records->count();

            if ($deletedCount === 0) {
                return Response::json(['success' => true, 'message' => '0 subscriptions deleted successfully']);
            }

            $uObj = auth()->user() ?? \Illuminate\Support\Facades\DB::table('superadmins')->where('id', $request->input('s_id'))->first();

            foreach ($records as $subscription) {
                // Enrich data before deletion for robust logging
                $logData = $subscription->toArray();
                $pName = $subscription->product->name ?? null;
                $cName = $subscription->client->name ?? null;
                $vName = $subscription->vendor->name ?? null;

                try {
                    if ($pName)
                        $pName = CryptService::decryptData($pName) ?? $pName;
                } catch (\Exception $e) {
                }
                try {
                    if ($cName)
                        $cName = CryptService::decryptData($cName) ?? $cName;
                } catch (\Exception $e) {
                }
                try {
                    if ($vName)
                        $vName = CryptService::decryptData($vName) ?? $vName;
                } catch (\Exception $e) {
                }

                $logData['Product'] = $pName ?: 'N/A';
                $logData['Client'] = $cName ?: 'N/A';
                $logData['Vendor'] = $vName ?: 'N/A';
                $logData['Domain'] = $subscription->domainMaster->domain_name ?? 'N/A';
                $logData['Amount'] = (float) $subscription->amount;

                ActivityLogger::logActivity(
                    $uObj,
                    'DELETE',
                    'Subscription',
                    'subscriptions',
                    $subscription->id,
                    $logData,
                    null,
                    null,
                    $request
                );

                $subscription->delete();
            }

            return Response::json(['success' => true, 'message' => $deletedCount . ' subscriptions deleted successfully']);
        } catch (\Exception $e) {
            return Response::json(['success' => false, 'message' => 'Deletion failed: ' . $e->getMessage()], 500);
        }
    }

    public function filterOptions(Request $request)
    {
        $category = $request->query('category'); // domain | client | product
        $data = [];

        if ($category === 'domain') {
            $data = \App\Models\DomainName::whereIn(
                'id',
                \Illuminate\Support\Facades\DB::table('subscriptions')->distinct()->pluck('domain_master_id')
            )->orderBy('domain_name')
                ->pluck('domain_name')
                ->filter()
                ->values()
                ->toArray();
        } elseif ($category === 'client') {
            $data = Superadmin::whereIn(
                'id',
                \Illuminate\Support\Facades\DB::table('subscriptions')->distinct()->pluck('client_id')
            )->pluck('name')
                ->map(function ($name) {
                    try {
                        return CryptService::decryptData($name) ?? $name;
                    } catch (\Exception $e) {
                        return $name;
                    }
                })
                ->filter()->sort()->values()->toArray();
        } elseif ($category === 'product') {
            $data = Product::whereIn(
                'id',
                \Illuminate\Support\Facades\DB::table('subscriptions')->distinct()->pluck('product_id')
            )->pluck('name')
                ->map(function ($name) {
                    try {
                        return CryptService::decryptData($name) ?? $name;
                    } catch (\Exception $e) {
                        return $name;
                    }
                })
                ->filter()->sort()->values()->toArray();
        }

        return Response::json(['status' => true, 'data' => $data]);
    }

    /**
     * Completely fresh Export logic for Subscription.
     * Built from scratch without third-party libraries.
     */
    public function export(Request $request)
    {
        set_time_limit(1200); // 20 minutes
        ini_set('memory_limit', '512M');

        $filename = 'Subscriptions_Export_' . date('Y-m-d_H-i-s') . '.csv';

        $headers = [
            'ID',
            'Domain Name',
            'Product',
            'Client',
            'Vendor',
            'Amount',
            'Currency',
            'Renewal Date',
            'Deletion Date',
            'Status',
            'Remarks',
            'Last Updated'
        ];

        return Response::streamDownload(function () use ($headers, $request, $filename) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, $headers);

            // 1. Raw DB Query for efficiency (Import style)
            $query = \Illuminate\Support\Facades\DB::table('subscriptions')
                ->leftJoin('domain_master', 'subscriptions.domain_master_id', '=', 'domain_master.id')
                ->leftJoin('products', 'subscriptions.product_id', '=', 'products.id')
                ->leftJoin('superadmins', 'subscriptions.client_id', '=', 'superadmins.id')
                ->leftJoin('vendors', 'subscriptions.vendor_id', '=', 'vendors.id')
                ->select([
                    'subscriptions.*',
                    'domain_master.domain_name',
                    'products.name as p_name',
                    'superadmins.name as c_name',
                    'vendors.name as v_name'
                ]);

            // 2. Client Isolation (Manual for DB Builder)
            if ($request->has('client_id')) {
                $query->where('subscriptions.client_id', $request->input('client_id'));
            } else if ($request->query('s_id')) {
                $query->where('subscriptions.client_id', $request->query('s_id'));
            }

            // 3. Search Filters
            if ($request->filled('search')) {
                $lk = '%' . $request->input('search') . '%';
                $query->where(function ($q) use ($lk) {
                    $q->where('domain_master.domain_name', 'LIKE', $lk)
                        ->orWhere('subscriptions.amount', 'LIKE', $lk);
                });
            }

            // 4. Specific Filters
            if ($request->filled('filter_by') && $request->filled('filter_value')) {
                $fBy = strtolower($request->input('filter_by'));
                $fVal = $request->input('filter_value');
                if ($fBy === 'domain') {
                    $query->where('domain_master.domain_name', $fVal);
                }
            }

            $currentRows = 0;
            // Use chunk for safe iteration
            $query->orderBy('subscriptions.id', 'desc')->chunk(200, function ($records) use ($handle, &$currentRows) {
                foreach ($records as $item) {
                    $currentRows++;

                    // Manual Decryption
                    try {
                        $pName = CryptService::decryptData($item->p_name) ?? $item->p_name;
                    } catch (\Exception $e) {
                        $pName = $item->p_name;
                    }
                    try {
                        $cName = CryptService::decryptData($item->c_name) ?? $item->c_name;
                    } catch (\Exception $e) {
                        $cName = $item->c_name;
                    }
                    try {
                        $pName = self::normalizeData(CryptService::decryptData($item->p_name) ?? $item->p_name, 'Product');
                    } catch (\Exception $e) {
                        $pName = $item->p_name;
                    }
                    try {
                        $cName = self::normalizeData(CryptService::decryptData($item->c_name) ?? $item->c_name, 'Client');
                    } catch (\Exception $e) {
                        $cName = $item->c_name;
                    }
                    try {
                        $vName = self::normalizeData(CryptService::decryptData($item->v_name) ?? $item->v_name, 'Vendor');
                    } catch (\Exception $e) {
                        $vName = $item->v_name;
                    }
                    try {
                        $remarks = self::normalizeData(CryptService::decryptData($item->remarks) ?? $item->remarks, 'Remarks');
                    } catch (\Exception $e) {
                        $remarks = $item->remarks;
                    }

                    fputcsv($handle, [
                        $item->id,
                        $item->domain_name ?? 'N/A',
                        $pName ?: 'N/A',
                        $cName ?: 'N/A',
                        $vName ?: 'N/A',
                        $item->amount ?? 0,
                        $item->currency ?? 'INR',
                        $item->renewal_date ?? '--',
                        $item->deletion_date ?? '--',
                        $item->status == 1 ? 'Active' : 'Inactive',
                        $remarks,
                        $item->updated_at
                    ]);
                }
            });

            fclose($handle);

            // 5. Activity Logging (Log to general activities, but history is handled by frontend export-log)
            try {
                $count = $currentRows;
                $this->logExportActivity($count);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Export log error: " . $e->getMessage());
            }

        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

}


