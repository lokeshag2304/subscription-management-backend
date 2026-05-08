<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use App\Http\Controllers\Controller;
use App\Services\CryptService;
use App\Services\ActivityLogger;
use App\Models\ImportExportHistory;
use App\Models\Subscription;
use App\Models\ImportHistory;
use App\Traits\DataNormalizer;

class ImportController extends Controller
{
    use DataNormalizer;
    public function importRecords(Request $request)
    {
        if (!$request->hasFile('file')) {
            // Handle case where we're just saving export history (no file)
            $validated = $request->validate([
                'module' => 'required|string',
                'action' => 'required|string',
                'file_name' => 'required|string',
                'total_records' => 'required|integer',
                'data_snapshot' => 'nullable'
            ]);

            $user = auth()->user();
            $userName = $user ? (CryptService::decryptData($user->name) ?? $user->name) : 'System';
            $userId = $user->id ?? $request->input('s_id') ?? 1;

            // Log activity first to ensure it happens
            try {
                ActivityLogger::exported($userId, $validated['module'], $validated['total_records']);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Export log error: " . $e->getMessage());
            }

            $history = null;
            try {
                $history = ImportHistory::create([
                    'module_name' => $validated['module'],
                    'action' => $validated['action'],
                    'file_name' => $validated['file_name'],
                    'imported_by' => $userName,
                    'successful_rows' => $validated['total_records'],
                    'data_snapshot' => $request->has('data_snapshot') ? json_encode($request->input('data_snapshot')) : null,
                    'client_id' => $userId
                ]);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("History save failed (snapshot too large or column missing): " . $e->getMessage());
                // Final fallback without snapshot
                try {
                    $history = ImportHistory::create([
                        'module_name' => $validated['module'],
                        'action' => $validated['action'],
                        'file_name' => $validated['file_name'],
                        'imported_by' => $userName,
                        'successful_rows' => $validated['total_records'],
                        'client_id' => $userId
                    ]);
                } catch (\Exception $e2) {
                    \Illuminate\Support\Facades\Log::error("Total history failure: " . $e2->getMessage());
                }
            }

            return response()->json(['success' => true, 'data' => $history]);
        }

        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt'
        ]);

        $inserted = 0;
        $failed = 0;
        $duplicates = 0;
        $duplicateRows = [];
        $errors = [];

        try {
            $file = $request->file('file');
            $path = $file->getRealPath();

            // 1. Build lookup maps (same logic as before, but optimized)
            $productMap = [];
            foreach (DB::table('products')->get() as $p) {
                try {
                    $name = CryptService::decryptData($p->name);
                } catch (\Exception $e) {
                    $name = $p->name;
                }
                $productMap[strtolower(trim($name ?? ''))] = (int) $p->id;
                $productMap[(int) $p->id] = (int) $p->id;
            }

            $clientMap = [];
            foreach (DB::table('superadmins')->get() as $c) {
                try {
                    $name = CryptService::decryptData($c->name);
                } catch (\Exception $e) {
                    $name = $c->name;
                }
                $clientMap[strtolower(trim($name ?? ''))] = (int) $c->id;
                $clientMap[(int) $c->id] = (int) $c->id;
            }

            $vendorMap = [];
            foreach (DB::table('vendors')->get() as $v) {
                try {
                    $name = CryptService::decryptData($v->name);
                } catch (\Exception $e) {
                    $name = $v->name;
                }
                $vendorMap[strtolower(trim($name ?? ''))] = (int) $v->id;
                $vendorMap[(int) $v->id] = (int) $v->id;
            }

            $domainMap = [];
            foreach (DB::table('domain_master')->get() as $dm) {
                $domainMap[strtolower(trim($dm->domain_name ?? ''))] = (int) $dm->id;
            }

            $extension = strtolower($file->getClientOriginalExtension());
            $allRows = [];

            if ($extension === 'csv' || $extension === 'txt') {
                if (($handle = fopen($file->getRealPath(), 'r')) !== FALSE) {
                    // Handle UTF-8 BOM if present
                    $bom = fread($handle, 3);
                    if ($bom !== "\xEF\xBB\xBF") {
                        rewind($handle);
                    }

                    while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                        $allRows[] = $data;
                    }
                    fclose($handle);
                }
            } else {
                // XLSX / XLS processing - Maatwebsite/Excel handles parsing correctly
                // XLSX / XLS processing - Maatwebsite/Excel expects an import object
                $dataArray = Excel::toArray(new \stdClass(), $file);
                $allRows = $dataArray[0] ?? [];
            }

            if (empty($allRows)) {
                return response()->json(['success' => false, 'message' => 'File is empty or invalid'], 400);
            }

            $header = array_shift($allRows);
            if ($header) {
                $headerMod = array_map(function ($h) {
                    return strtolower(trim($h ?? ''));
                }, $header);
                $hMap = array_flip($headerMod);

                // Map columns
                $idxProduct = $hMap['product'] ?? $hMap['product_name'] ?? $hMap['name'] ?? $hMap['subscription'] ?? 0;
                $idxClient = $hMap['client'] ?? $hMap['client_name'] ?? $hMap['customer'] ?? 1;
                $idxVendor = $hMap['vendor'] ?? $hMap['vendor_name'] ?? 2;
                $idxRenewal = $hMap['renewal_date'] ?? $hMap['renewal'] ?? $hMap['date'] ?? 3;
                $idxAmount = $hMap['amount'] ?? $hMap['price'] ?? $hMap['cost'] ?? 4;
                $idxDeletion = $hMap['deletion_date'] ?? $hMap['deletion'] ?? $hMap['expiry'] ?? 5;
                $idxDomain = $hMap['domain'] ?? $hMap['domain_name'] ?? $hMap['url'] ?? -1;
                $idxStatus = $hMap['status'] ?? 7;
                $idxRemarks = $hMap['remarks'] ?? $hMap['remark'] ?? $hMap['notes'] ?? 8;

                foreach ($allRows as $data) {
                    try {
                        if (empty(array_filter($data)))
                            continue;

                        $productRaw = trim((string) ($data[$idxProduct] ?? ''));
                        $clientRaw = trim((string) ($data[$idxClient] ?? ''));
                        $vendorRaw = trim((string) ($data[$idxVendor] ?? ''));
                        $renewalRaw = $data[$idxRenewal] ?? null;
                        $amountRaw = $data[$idxAmount] ?? null;
                        $deletionRaw = $data[$idxDeletion] ?? null;
                        $domainRaw = $idxDomain !== -1 ? trim((string) ($data[$idxDomain] ?? '')) : 'default.com';
                        $statusRaw = $data[$idxStatus] ?? null;
                        $remarks = (string) ($data[$idxRemarks] ?? '');

                        // Skip rows that contain binary garbage or XML tags (indicating incorrect parsing)
                        if (str_contains($productRaw, 'xl/') || str_contains($productRaw, 'xml') || strlen($productRaw) > 255) {
                            $failed++;
                            continue;
                        }

                        // Resolve IDs
                        $productId = $productMap[strtolower($productRaw)] ?? null;
                        if (!$productId && $productRaw) {
                            $productId = DB::table('products')->insertGetId(['name' => CryptService::encryptData($productRaw), 'created_at' => now()]);
                            $productMap[strtolower($productRaw)] = $productId;
                        }

                        $clientId = $clientMap[strtolower($clientRaw)] ?? null;
                        if (!$clientId && $clientRaw) {
                            $clientId = DB::table('superadmins')->insertGetId([
                                'name' => CryptService::encryptData($clientRaw),
                                'email' => strtolower(preg_replace('/[^a-z0-9]/', '', $clientRaw)) . '+' . uniqid() . '@import.local',
                                'password' => bcrypt(uniqid()),
                                'login_type' => 3,
                                'status' => 1,
                                'created_at' => now()
                            ]);
                            $clientMap[strtolower($clientRaw)] = $clientId;
                        }

                        $vendorId = $vendorMap[strtolower($vendorRaw)] ?? null;
                        if (!$vendorId && $vendorRaw) {
                            $vendorId = DB::table('vendors')->insertGetId(['name' => CryptService::encryptData($vendorRaw), 'created_at' => now()]);
                            $vendorMap[strtolower($vendorRaw)] = $vendorId;
                        }

                        $domainId = $domainMap[strtolower($domainRaw)] ?? null;
                        if (!$domainId && $domainRaw) {
                            $domainId = DB::table('domain_master')->insertGetId(['domain_name' => $domainRaw, 'created_at' => now()]);
                            $domainMap[strtolower($domainRaw)] = $domainId;
                        }

                        if (!$productRaw || !$clientRaw) {
                            $failed++;
                            continue;
                        }

                        $renewalDate = self::robustParseDate($renewalRaw) ?? now()->format('Y-m-d');
                        $amount = (float) str_replace([',', ' '], '', (string) $amountRaw);
                        $status = (strtolower(trim((string) $statusRaw)) === 'inactive' || $statusRaw === '0') ? 0 : 1;

                        // Duplicate check
                        $exists = DB::table('subscriptions')
                            ->where('product_id', $productId)
                            ->where('client_id', $clientId)
                            ->where('domain_master_id', $domainId)
                            ->where('renewal_date', $renewalDate)
                            ->exists();

                        if ($exists) {
                            $duplicates++;
                            $duplicateRows[] = $data;
                            continue;
                        }

                        $today = Carbon::now()->startOfDay();
                        $daysLeft = (int) $today->diffInDays(Carbon::parse($renewalDate)->startOfDay(), false);

                        Subscription::create([
                            'product_id' => $productId,
                            'client_id' => $clientId,
                            'vendor_id' => $vendorId,
                            'domain_master_id' => $domainId,
                            'amount' => $amount,
                            'renewal_date' => $renewalDate,
                            'deletion_date' => self::robustParseDate($deletionRaw),
                            'days_left' => $daysLeft,
                            'status' => $status,
                            'remarks' => CryptService::encryptData($remarks),
                        ]);

                        $inserted++;
                    } catch (\Exception $e) {
                        $failed++;
                    }
                }
            }

            $user = auth()->user();
            $userName = $user ? (CryptService::decryptData($user->name) ?? $user->name) : 'System';
            $userId = $user->id ?? $request->input('s_id') ?? 1;

            $history = ImportHistory::create([
                'module_name' => 'Subscription',
                'action' => 'IMPORT',
                'file_name' => $file->getClientOriginalName(),
                'imported_by' => $userName,
                'total_records' => $inserted + $failed + $duplicates,
                'inserted_count' => $inserted,
                'failed_count' => $failed,
                'duplicates_count' => $duplicates,
                'client_id' => $userId
            ]);

            if ($duplicates > 0) {
                \Illuminate\Support\Facades\Log::info("ImportController: Attempting to store $duplicates duplicates for history ID: {$history->id}");
                $dupPath = \App\Services\AuditFileService::storeDuplicates($history, $header, $duplicateRows);
                if ($dupPath) {
                    \Illuminate\Support\Facades\Log::info("ImportController: Successfully stored duplicates at $dupPath");
                } else {
                    \Illuminate\Support\Facades\Log::error("ImportController: Failed to store duplicates for history ID: {$history->id}");
                }
            }

            ActivityLogger::imported($userId, 'Subscription', $inserted, $history->id, $failed, $duplicates);

            return response()->json([
                'success' => true,
                'inserted' => $inserted,
                'failed' => $failed,
                'duplicates' => $duplicates,
                'message' => "Import completed: $inserted added."
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage(),
                'inserted' => 0,
                'failed' => 0
            ], 500);
        }
    }

    private function parseDate($value)
    {
        return self::robustParseDate($value);
    }
}
