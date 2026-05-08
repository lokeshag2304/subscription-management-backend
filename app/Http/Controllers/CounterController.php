<?php

namespace App\Http\Controllers;

use App\Models\Counter;
use App\Models\Product;
use App\Models\Superadmin;
use App\Models\Vendor;
use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use App\Services\ActivityLogger;
use App\Services\ClientScopeService;
use App\Services\CryptService;
use App\Models\ImportHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\DB;
use App\Services\GracePeriodService;
use App\Traits\DataNormalizer;

use App\Services\AuditFileService;
use App\Services\DateFormatterService;

class CounterController extends Controller
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
        $userId = $user->id ?? 1;

        $role = $user ? ($user->role ?? (isset($user->login_type) ? ($user->login_type === 1 ? 'Superadmin' : ($user->login_type === 3 ? 'Client' : 'User')) : 'Unknown')) : 'System';

        try {
            ActivityLogger::exported($userId, 'Counter', $validated['total_records']);
        } catch (\Exception $e) {
        }

        try {
            $history = AuditFileService::logExport(
                $userId,
                'Counter',
                $validated['total_records'],
                $request->input('data_snapshot'),
                $userId
            );
        } catch (\Exception $e) {
            $history = \App\Models\ImportHistory::create([
                'user_id' => $userId,
                'module_name' => 'Counter',
                'action' => 'EXPORT',
                'file_name' => 'Counter_Export_' . date('Ymd_His') . '.csv',
                'imported_by' => $userName,
                'role' => $role,
                'successful_rows' => $validated['total_records'],
                'client_id' => $userId
            ]);
        }
        return response()->json(['success' => true, 'data' => $history]);
    }

    protected array $productIds = [47];

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

    private function getDerivedCount($clientId, $productId, $vendorId)
    {
        return \App\Services\CounterSyncService::calculate($clientId, $productId, $vendorId);
    }

    private function logActivity($action, $record, $oldData = null, $newData = null)
    {
        try {
            $user = auth()->user() ?: (object) ['id' => request()->input('auth_user_id') ?: 1, 'name' => 'Admin', 'role' => 'Superadmin'];

            ActivityLogger::logActivity(
                $user,
                strtoupper($action),
                'Counter',
                'counters',
                $record->id,
                $oldData, // Send raw arrays, ActivityLogger handles decryption and mapping
                $newData,
                null,
                request()
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Counter logActivity failed: " . $e->getMessage());
        }
    }

    public function index(Request $request)
    {
        $limit = $request->query('limit', 100);
        $offset = $request->query('offset', 0);
        $search = $request->query('search', '');

        $query = Counter::select([
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
                    $date = Carbon::parse(str_replace(['/', '.'], '-', $search));
                    $dateMatch = $date->format('Y-m-d');
                } catch (\Exception $e) {
                }
            }

            $dmIds = \App\Models\DomainName::where('domain_name', 'LIKE', '%' . $searchLow . '%')->pluck('id');

            $pIds = Product::pluck('name', 'id')
                ->filter(function ($name) use ($searchLow) {
                    try {
                        $dec = CryptService::decryptData($name) ?? $name;
                    } catch (\Exception $e) {
                        $dec = $name;
                    }
                    return str_contains(strtolower($dec), $searchLow);
                })->keys();

            $cIds = Superadmin::pluck('name', 'id')
                ->filter(function ($name) use ($searchLow) {
                    try {
                        $dec = CryptService::decryptData($name) ?? $name;
                    } catch (\Exception $e) {
                        $dec = $name;
                    }
                    return str_contains(strtolower($dec), $searchLow);
                })->keys();

            $vIds = Vendor::pluck('name', 'id')
                ->filter(function ($name) use ($searchLow) {
                    try {
                        $dec = CryptService::decryptData($name) ?? $name;
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

            $clientName = optional($item->client)->name;
            try {
                $clientName = CryptService::decryptData($clientName) ?? $clientName;
            } catch (\Exception $e) {
            }

            $productName = optional($item->product)->name;
            try {
                $productName = CryptService::decryptData($productName) ?? $productName;
            } catch (\Exception $e) {
            }

            $vendorName = optional($item->vendor)->name;
            try {
                $vendorName = CryptService::decryptData($vendorName) ?? $vendorName;
            } catch (\Exception $e) {
            }

            $remarks = $item->remarks;
            try {
                $remarks = CryptService::decryptData($remarks) ?? $remarks;
            } catch (\Exception $e) {
            }

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
            $data['counter_count'] = $item->amount;
            $data['valid_till'] = $item->renewal_date;
            $data['expiry_date'] = $item->renewal_date;

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
            ClientScopeService::enforceClientId($request);

            $request->merge([
                'domain_master_id' => $request->domain_master_id ?? data_get($request->domain_master_id, 'value'),
                'product_id' => $request->product_id ?? data_get($request->product, 'value'),
                'client_id' => $request->client_id ?? data_get($request->client, 'value'),
                'vendor_id' => $request->vendor_id ?? data_get($request->vendor, 'value'),
                'renewal_date' => $request->renewal_date ?? $request->expiry_date ?? $request->valid_till,
                'deletion_date' => $request->deletion_date,
            ]);

            $validated = validator($request->all(), [
                'domain_master_id' => 'required|exists:domain_master,id',
                'product_id' => 'required|exists:products,id',
                'client_id' => 'required',
                'vendor_id' => 'required|exists:vendors,id',
                'amount' => 'required|numeric',
                'renewal_date' => 'required|date',
                'deletion_date' => 'required|date',
                'grace_period' => 'required',
                'status' => 'required'
            ])->validate();

            if ($request->renewal_date)
                $request->merge(['renewal_date' => Carbon::parse($request->renewal_date)->format('Y-m-d')]);
            if ($request->deletion_date)
                $request->merge(['deletion_date' => Carbon::parse($request->deletion_date)->format('Y-m-d')]);

            // ── DUPLICATE CHECK ──
            $duplicateExists = Counter::where('domain_master_id', $request->domain_master_id)
                ->where('client_id', $request->client_id)
                ->where('renewal_date', $request->renewal_date)
                ->exists();

            if ($duplicateExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate entry: This domain already exists for the same client and renewal date'
                ], 422);
            }

            $today = now()->startOfDay();
            $dueDate = $request->due_date ?? $request->grace_end_date;
            $gracePeriod = $dueDate ? $today->diffInDays(Carbon::parse($dueDate)->startOfDay(), false) : ($request->grace_period ?? 0);

            $model = Counter::create([
                'domain_master_id' => $request->domain_master_id,
                'product_id' => $request->product_id,
                'client_id' => $request->client_id,
                'vendor_id' => $request->vendor_id,
                'amount' => (int) ($request->amount ?? $request->counter_count ?? 0),
                'renewal_date' => $request->renewal_date,
                'deletion_date' => $request->deletion_date,
                'due_date' => $dueDate,
                'days_left' => $request->renewal_date ? $today->diffInDays($request->renewal_date, false) : null,
                'days_to_delete' => $request->deletion_date ? $today->diffInDays($request->deletion_date, false) : null,
                'grace_period' => $gracePeriod,
                'status' => $request->status ?? 1,
                'remarks' => CryptService::encryptData($request->remarks)
            ]);

            GracePeriodService::syncModel($model);
            $model->save();

            $model->refresh()->load(['domainMaster', 'product', 'client', 'vendor']);

            $clientName = $model->client->name ?? null;
            try {
                $clientName = CryptService::decryptData($clientName) ?? $clientName;
            } catch (\Exception $e) {
            }

            $productName = $model->product->name ?? null;
            try {
                $productName = CryptService::decryptData($productName) ?? $productName;
            } catch (\Exception $e) {
            }

            $vendorName = $model->vendor->name ?? null;
            try {
                $vendorName = CryptService::decryptData($vendorName) ?? $vendorName;
            } catch (\Exception $e) {
            }

            $resp = $model->toArray();
            $resp['domain_name'] = $model->domainMaster->domain_name ?? 'N/A';
            $resp['client_name'] = $clientName;
            $resp['product_name'] = $productName;
            $resp['vendor_name'] = $vendorName;
            $resp['counter_count'] = $model->amount;
            $resp['valid_till'] = $model->renewal_date;
            $resp['expiry_date'] = $model->renewal_date;
            $resp['validity_date'] = $model->renewal_date;
            try {
                $resp['remarks'] = CryptService::decryptData($model->remarks) ?? $model->remarks;
            } catch (\Exception $e) {
            }
            $resp['last_updated'] = DateFormatterService::formatDateTime($model->updated_at);
            $resp['updated_at_formatted'] = DateFormatterService::formatDateTime($model->updated_at);
            $resp['created_at_formatted'] = DateFormatterService::formatDateTime($model->created_at);

            $this->logActivity('CREATE', $model, null, $resp);

            return response()->json(['success' => true, 'data' => $resp], 200);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $record = Counter::find($id);
        if (!$record)
            return response()->json(['success' => false, 'message' => 'Not found'], 404);

        ClientScopeService::assertOwnership($record, $request);

        $data = $request->all();
        foreach ($data as $key => $value)
            if ($value === '')
                $data[$key] = null;

        // Use array_key_exists to detect if user explicitly sent empty/null to clear the date
        $amount = $data['amount'] ?? $data['counter_count'] ?? $record->amount;
        $renewalDate = array_key_exists('valid_till', $data) ? $data['valid_till'] : (array_key_exists('expiry_date', $data) ? $data['expiry_date'] : (array_key_exists('renewal_date', $data) ? $data['renewal_date'] : $record->renewal_date));
        $deletionDate = array_key_exists('deletion_date', $data) ? $data['deletion_date'] : $record->deletion_date;
        $dueDate = array_key_exists('grace_end_date', $data) ? $data['grace_end_date'] : (array_key_exists('due_date', $data) ? $data['due_date'] : $record->due_date);

        if ($renewalDate === null) {
            return response()->json(['success' => false, 'message' => 'Validity Date cannot be empty.'], 422);
        }
        if ($deletionDate === null) {
            return response()->json(['success' => false, 'message' => 'Deletion Date cannot be empty.'], 422);
        }
        if ($dueDate === null) {
            return response()->json(['success' => false, 'message' => 'Grace Period Date cannot be empty.'], 422);
        }

        if ($renewalDate)
            $renewalDate = $this->formatDate($renewalDate);
        if ($deletionDate)
            $deletionDate = $this->formatDate($deletionDate);
        if ($dueDate)
            $dueDate = $this->formatDate($dueDate);

        $gracePeriod = $dueDate ? now()->startOfDay()->diffInDays(Carbon::parse($dueDate)->startOfDay(), false) : ($data['grace_period'] ?? $record->grace_period);

        \App\Services\RemarkHistoryService::logUpdate('Counter', $record, $data);

        // ── DUPLICATE CHECK ──
        $duplicateExists = Counter::where('domain_master_id', $data['domain_master_id'] ?? $record->domain_master_id)
            ->where('client_id', $data['client_id'] ?? $record->client_id)
            ->where('renewal_date', $renewalDate)
            ->where('id', '!=', $id)
            ->exists();

        if ($duplicateExists) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate entry: This domain already exists for the same client and renewal date'
            ], 422);
        }

        $oldData = $record->toArray();
        // Capture pre-update dates to detect false positives from GracePeriodService recalculation
        $oldDeletionDate = $record->deletion_date;
        $oldDueDate = $record->due_date;
        $userExplicitlyChangedDeletion = isset($data['deletion_date']) || isset($data['grace_end_date']);
        $userExplicitlyChangedGrace = isset($data['grace_period']) && (string)$data['grace_period'] !== (string)$record->grace_period;

        $today = now()->startOfDay();
        $updatePayload = [
            'domain_master_id' => $data['domain_master_id'] ?? $record->domain_master_id,
            'client_id' => $data['client_id'] ?? $record->client_id,
            'product_id' => $data['product_id'] ?? $record->product_id,
            'vendor_id' => $data['vendor_id'] ?? $record->vendor_id,
            'amount' => (int) $amount,
            'renewal_date' => $renewalDate,
            'deletion_date' => $deletionDate,
            'due_date' => $dueDate,
            'days_left' => $renewalDate ? $today->diffInDays(\Illuminate\Support\Carbon::parse($renewalDate), false) : null,
            'days_to_delete' => $deletionDate ? $today->diffInDays(\Illuminate\Support\Carbon::parse($deletionDate), false) : null,
            'status' => $data['status'] ?? $record->status,
            'remarks' => isset($data['remarks']) ? $data['remarks'] : $record->remarks,
            'grace_period' => (int) $gracePeriod,
        ];

        $record->update($updatePayload);

        // Sync grace period logic (recalculates due_date and updates status if expired)
        \App\Services\GracePeriodService::syncModel($record);
        $record->save();

        $record->refresh()->load(['domainMaster', 'product', 'client', 'vendor'])->loadCount('remarkHistories');


        $clientName = $record->client->name ?? null;
        try {
            $clientName = CryptService::decryptData($clientName) ?? $clientName;
        } catch (\Exception $e) {
        }

        $productName = $record->product->name ?? null;
        try {
            $productName = CryptService::decryptData($productName) ?? $productName;
        } catch (\Exception $e) {
        }

        $vendorName = $record->vendor->name ?? null;
        try {
            $vendorName = CryptService::decryptData($vendorName) ?? $vendorName;
        } catch (\Exception $e) {
        }

        $remarks = $record->remarks;
        try {
            $remarks = CryptService::decryptData($remarks) ?? $remarks;
        } catch (\Exception $e) {
        }

        $resp = $record->toArray();
        $resp['domain_name'] = $record->domainMaster->domain_name ?? 'N/A';
        $resp['client_name'] = $clientName;
        $resp['product_name'] = $productName;
        $resp['vendor_name'] = $vendorName;
        $resp['counter_count'] = $record->amount;
        $resp['amount'] = $record->amount;
        $resp['valid_till'] = $record->renewal_date;
        $resp['remarks'] = $remarks;
        $resp['expiry_date'] = $record->renewal_date;
        $resp['validity_date'] = $record->renewal_date;
        $resp['has_remark_history'] = $record->remark_histories_count > 0;
        $resp['last_updated'] = DateFormatterService::formatDateTime($record->updated_at);
        $resp['updated_at_formatted'] = DateFormatterService::formatDateTime($record->updated_at);
        $resp['created_at_formatted'] = DateFormatterService::formatDateTime($record->created_at);

        // If user didn't explicitly change deletion_date or grace_period, restore pre-update values
        // to prevent GracePeriodService auto-recalculation from appearing as a false-positive change
        if (!$userExplicitlyChangedDeletion && !$userExplicitlyChangedGrace) {
            $resp['deletion_date'] = $oldDeletionDate;
            $resp['due_date'] = $oldDueDate;
        }

        $this->logActivity('UPDATE', $record, $oldData, $resp);

        return response()->json([
            'success' => true,
            'message' => 'Counter Record updated successfully',
            'data' => $resp
        ]);
    }

    public function destroy($id)
    {
        $record = Counter::find($id);
        if (!$record)
            return response()->json(['success' => false, 'message' => 'Not found'], 404);

        // ── OWNERSHIP GUARD: Client can only delete their own records ──
        ClientScopeService::assertOwnership($record, new Request());

        // Enrich data with resolved names before logging deletion
        $logData = $record->toArray();
        try {
            $logData['product'] = $record->product ? (CryptService::decryptData($record->product->name) ?? $record->product->name) : 'N/A';
            $logData['client'] = $record->client ? (CryptService::decryptData($record->client->name) ?? $record->client->name) : 'N/A';
            $logData['vendor'] = $record->vendor ? (CryptService::decryptData($record->vendor->name) ?? $record->vendor->name) : 'N/A';
            $logData['domain'] = $record->domainMaster->domain_name ?? 'N/A';
        } catch (\Exception $e) {
        }

        $this->logActivity('DELETE', $record, $logData);
        $record->delete();

        return response()->json([
            'success' => true,
            'message' => 'Counter Record deleted successfully'
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
            \Illuminate\Support\Facades\Log::info("Counter Import Suffix Cache: " . implode(', ', $suffixCache));

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

            if ($handle !== FALSE) {
                $firstRow = fgetcsv($handle, 1000, ',');
                if ($firstRow) {
                    $headerMod = array_map(function ($h) {
                        return str_replace([' ', '-'], '_', strtolower(trim($h ?? '')));
                    }, $firstRow);

                    // Strict Header Validation
                    $allowedHeaders = [
                        'domain',
                        'domain_name',
                        'url',
                        'product',
                        'product_id',
                        'product_name',
                        'name',
                        'client',
                        'client_id',
                        'customer',
                        'client_name',
                        'vendor',
                        'vendor_id',
                        'vendor_name',
                        'amount',
                        'price',
                        'counter_count',
                        'count',
                        'quantity',
                        'cost',
                        'currency',
                        'renewal_date',
                        'renewal',
                        'date',
                        'expiry_date',
                        'valid_till',
                        'deletion_date',
                        'deletion',
                        'delete_date',
                        'grace_period_date',
                        'grace_period',
                        'due_date',
                        'grace_end_date',
                        'status',
                        'remarks',
                        'remark',
                        'note',
                        'notes',
                        'id',
                        'last_updated'
                    ];
                    $invalidHeaders = array_diff($headerMod, $allowedHeaders);
                    if (!empty($invalidHeaders)) {
                        return response()->json([
                            'success' => false,
                            'message' => "Invalid columns found: " . implode(', ', $invalidHeaders) . ". Please check with sample file."
                        ], 422);
                    }

                    $map = array_flip($headerMod);

                    $idxDomain = $map['domain'] ?? $map['domain_name'] ?? $map['url'] ?? -1;
                    $idxClient = $map['client'] ?? $map['client_id'] ?? $map['customer'] ?? $map['client_name'] ?? -1;
                    $idxProduct = $map['product'] ?? $map['product_id'] ?? $map['product_name'] ?? $map['name'] ?? -1;
                    $idxVendor = $map['vendor'] ?? $map['vendor_id'] ?? $map['vendor_name'] ?? -1;
                    $idxAmount = $map['amount'] ?? $map['price'] ?? $map['counter_count'] ?? $map['count'] ?? $map['quantity'] ?? $map['cost'] ?? -1;
                    $idxRenewal = $map['renewal_date'] ?? $map['renewal'] ?? $map['date'] ?? $map['expiry_date'] ?? $map['valid_till'] ?? -1;
                    $idxDeletion = $map['deletion_date'] ?? $map['deletion'] ?? $map['delete_date'] ?? -1;
                    $idxStatus = $map['status'] ?? -1;
                    $idxGraceEndDate = $map['grace_end_date'] ?? $map['grace_date'] ?? $map['due_date'] ?? $map['grace_period_date'] ?? -1;
                    $idxRemarks = $map['remarks'] ?? $map['remark'] ?? $map['note'] ?? $map['notes'] ?? -1;

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
                                    // Date format check
                                    if ($field === 'renewal_date' || $field === 'deletion_date' || $field === 'grace_end_date') {
                                        if ($val !== '') {
                                            $parsed = self::robustParseDate($val);
                                            if (!$parsed) {
                                                $missing[] = "{$field} (invalid format: '$val'. Please use DD-MM-YYYY or YYYY-MM-DD)";
                                            }
                                        }
                                    }
                                    if ($field === 'amount') {
                                        $cleanAmount = str_replace([',', ' '], '', $val);
                                        if (!is_numeric($cleanAmount)) {
                                            $missing[] = "Amount (invalid number: '$val')";
                                        }
                                    }
                                }
                            }

                            // ── DOMAIN VALIDATION PASS ──
                            $domainVal = ($idxDomain !== -1 && isset($data[$idxDomain])) ? trim((string) $data[$idxDomain]) : '';
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
                                $dVal = trim((string) $data[$idxDeletion]);
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
                            $user = \Illuminate\Support\Facades\Auth::user();
                            $history = \App\Models\ImportHistory::create([
                                'module_name' => 'Counter',
                                'action' => 'IMPORT',
                                'file_name' => $file->getClientOriginalName(),
                                'imported_by' => $user->name ?? 'System / Admin',
                                'successful_rows' => 0,
                                'failed_rows' => count($issues),
                                'duplicates_count' => 0,
                                'data_snapshot' => json_encode($issues)
                            ]);
                            \App\Services\AuditFileService::storeImport($history, $file);

                            \App\Services\ActivityLogger::imported($user->id, 'Counter', 0, $history->id, count($issues), 0);

                            return response()->json([
                                'success' => false,
                                'requires_confirmation' => true,
                                'message' => 'Validation failed: Mandatory fields are missing.',
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
                            $rawAmount = ($idxAmount !== -1 && isset($data[$idxAmount])) ? $data[$idxAmount] : 0;
                            $rawRenewal = $idxRenewal !== -1 ? ($data[$idxRenewal] ?? null) : null;
                            $rawDeletion = $idxDeletion !== -1 ? ($data[$idxDeletion] ?? null) : null;
                            $rawGraceEndDate = $idxGraceEndDate !== -1 ? ($data[$idxGraceEndDate] ?? null) : null;
                            $rawStatus = $idxStatus !== -1 ? trim($data[$idxStatus] ?? '1') : '1';
                            $rawRemarks = $idxRemarks !== -1 ? trim($data[$idxRemarks] ?? '') : '';

                            if (!$rawProduct)
                                $rawProduct = "Imported_" . ($idxProduct !== -1 ? "Col$idxProduct" : "General");

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

                            $graceEndDate = $parseDate($rawGraceEndDate);
                            if (!$graceEndDate && $rawGraceEndDate) {
                                throw new \Exception("Invalid Grace End Date format: " . $rawGraceEndDate);
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
                                $pId = \Illuminate\Support\Facades\DB::table('products')->insertGetId(['name' => CryptService::encryptData(trim($rawProduct)), 'created_at' => now(), 'updated_at' => now()]);
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
                            $vId = $vendorCache[strtolower(trim($rawVendor ?: 'Imported Vendor'))] ?? null;
                            if (!$vId) {
                                $vId = \Illuminate\Support\Facades\DB::table('vendors')->insertGetId(['name' => CryptService::encryptData($rawVendor ?: 'Imported Vendor'), 'created_at' => now(), 'updated_at' => now()]);
                                $vendorCache[strtolower(trim($rawVendor ?: 'Imported Vendor'))] = $vId;
                            }

                            $amount = (float) str_replace([',', ' '], '', (string) $rawAmount);
                            $renewalDate = $parseDate($rawRenewal);
                            $status = (strtolower(trim($rawStatus)) === 'active' || $rawStatus === '1' || $rawStatus === '') ? 1 : 0;

                            $exists = \Illuminate\Support\Facades\DB::table('counters')
                                ->where('domain_master_id', $dId)
                                ->where('client_id', $cId)
                                ->where('renewal_date', $renewalDate)
                                ->exists();

                            if ($exists) {
                                $duplicates++;
                                $duplicateRows[] = $data;
                                continue;
                            }

                            $gracePeriod = $graceEndDate ? now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($graceEndDate)->startOfDay(), false) : 0;

                            \Illuminate\Support\Facades\DB::table('counters')->insert([
                                'domain_master_id' => $dId,
                                'client_id' => $cId,
                                'product_id' => $pId,
                                'vendor_id' => $vId,
                                'amount' => $amount,
                                'renewal_date' => $renewalDate,
                                'deletion_date' => $deletionDate,
                                'status' => $status,
                                'remarks' => CryptService::encryptData($rawRemarks),
                                'due_date' => $graceEndDate,
                                'grace_period' => $gracePeriod,
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
                ActivityLogger::imported($userId, 'Counter', $inserted);

            $history = ImportHistory::create([
                'user_id' => $userId,
                'role' => $role,
                'module_name' => 'Counter',
                'action' => 'IMPORT',
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $filePath,
                'imported_by' => $user->name ?? 'System / Admin',
                'successful_rows' => $inserted,
                'failed_rows' => $failed,
                'duplicates_count' => $duplicates
            ]);

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

            return response()->json(['success' => true, 'inserted' => $inserted, 'failed' => $failed, 'duplicates' => $duplicates, 'message' => "Import processed: $inserted added, $failed failed."]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Import failed: ' . $e->getMessage()], 500);
        }
    }

    public function fetchCount(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required',
            'product_id' => 'required',
            'vendor_id' => 'required',
        ]);

        $count = \App\Services\CounterSyncService::calculate($validated['client_id'], $validated['product_id'], $validated['vendor_id']);

        return response()->json([
            'success' => true,
            'count' => $count
        ]);
    }
    public function bulkDelete(Request $request)
    {
        $ids = $request->get('ids', []);
        if (empty($ids)) {
            return response()->json(['success' => false, 'message' => 'No IDs provided'], 400);
        }

        try {
            $records = Counter::with(['product', 'client', 'vendor', 'domainMaster'])->whereIn('id', $ids)->get();
            $deletedCount = $records->count();

            foreach ($records as $record) {
                // Enrich data with resolved names before logging deletion
                $logData = $record->toArray();
                try {
                    $logData['product'] = $record->product ? (CryptService::decryptData($record->product->name) ?? $record->product->name) : 'N/A';
                    $logData['client'] = $record->client ? (CryptService::decryptData($record->client->name) ?? $record->client->name) : 'N/A';
                    $logData['vendor'] = $record->vendor ? (CryptService::decryptData($record->vendor->name) ?? $record->vendor->name) : 'N/A';
                    $logData['domain'] = $record->domainMaster->domain_name ?? 'N/A';
                } catch (\Exception $e) {
                }

                $this->logActivity('DELETE', $record, $logData);
                $record->delete();
            }

            return response()->json(['success' => true, 'message' => $deletedCount . ' Counter records deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Deletion failed: ' . $e->getMessage()], 500);
        }
    }
}
