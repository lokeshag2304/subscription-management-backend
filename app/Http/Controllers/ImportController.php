<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use App\Services\CryptService;
use App\Models\ImportExportHistory;

class ImportController extends Controller
{
    public function importRecords(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt'
        ]);

        $inserted = 0;
        $failed   = 0;
        $errors   = [];

        try {
            $data = Excel::toArray(new class {}, $request->file('file'));

            if (empty($data) || empty($data[0])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Empty file provided.',
                    'inserted' => 0, 'failed' => 0, 'errors' => []
                ], 400);
            }

            $rows = $data[0];

            if (count($rows) < 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'File has no data rows below the header row.',
                    'inserted' => 0, 'failed' => 0, 'errors' => []
                ], 400);
            }

            // Build lookup maps: ID-keyed AND name-keyed (both encrypted and plain)
            $productMap = [];
            foreach (DB::table('products')->get() as $p) {
                $productMap[(int)$p->id] = (int)$p->id;
                try { $productMap[strtolower(trim(CryptService::decryptData($p->name)))] = (int)$p->id; }
                catch (\Exception $e) { $productMap[strtolower(trim((string)$p->name))] = (int)$p->id; }
            }

            $clientMap = [];
            foreach (DB::table('superadmins')->get() as $c) {
                $clientMap[(int)$c->id] = (int)$c->id;
                try { $clientMap[strtolower(trim(CryptService::decryptData($c->name)))] = (int)$c->id; }
                catch (\Exception $e) { $clientMap[strtolower(trim((string)$c->name))] = (int)$c->id; }
            }

            $vendorMap = [];
            foreach (DB::table('vendors')->get() as $v) {
                $vendorMap[(int)$v->id] = (int)$v->id;
                try { $vendorMap[strtolower(trim(CryptService::decryptData($v->name)))] = (int)$v->id; }
                catch (\Exception $e) { $vendorMap[strtolower(trim((string)$v->name))] = (int)$v->id; }
            }

            $insertedRecords = [];

            DB::transaction(function () use (
                &$inserted, &$failed, &$errors, &$insertedRecords,
                $rows, $productMap, $clientMap, $vendorMap
            ) {
                foreach (array_slice($rows, 1) as $index => $row) {
                    $rowNumber = $index + 2;

                    if (empty(array_filter((array)$row))) continue;

                    $productRaw  = trim((string)($row[0] ?? ''));
                    $clientRaw   = trim((string)($row[1] ?? ''));
                    $vendorRaw   = trim((string)($row[2] ?? ''));
                    $renewalRaw  = $row[3] ?? null;
                    $amountRaw   = $row[4] ?? null;
                    $deletionRaw = $row[5] ?? null;
                    $statusRaw   = $row[7] ?? null;
                    $remarks     = $row[8] ?? null;

                    $rowErrors = [];

                    // Resolve product — try numeric ID first, then name
                    $productId = null;
                    if (is_numeric($productRaw) && isset($productMap[(int)$productRaw])) {
                        $productId = $productMap[(int)$productRaw];
                    } elseif (isset($productMap[strtolower($productRaw)])) {
                        $productId = $productMap[strtolower($productRaw)];
                    }
                    if (!$productId) {
                        $rowErrors[] = "product '$productRaw' not found in DB.";
                    }

                    // Resolve client — try numeric ID first, then name
                    $clientId = null;
                    if (is_numeric($clientRaw) && isset($clientMap[(int)$clientRaw])) {
                        $clientId = $clientMap[(int)$clientRaw];
                    } elseif (isset($clientMap[strtolower($clientRaw)])) {
                        $clientId = $clientMap[strtolower($clientRaw)];
                    }
                    if (!$clientId) {
                        $rowErrors[] = "client '$clientRaw' not found in DB.";
                    }

                    // Resolve vendor — optional, null if not found
                    $vendorId = null;
                    if (!empty($vendorRaw)) {
                        if (is_numeric($vendorRaw) && isset($vendorMap[(int)$vendorRaw])) {
                            $vendorId = $vendorMap[(int)$vendorRaw];
                        } elseif (isset($vendorMap[strtolower($vendorRaw)])) {
                            $vendorId = $vendorMap[strtolower($vendorRaw)];
                        }
                    }

                    // Renewal date — required
                    $renewalDate = $this->parseDate($renewalRaw);
                    if (!$renewalDate) {
                        $rowErrors[] = "invalid renewal_date '$renewalRaw'.";
                    }

                    // Amount — required
                    $amountCleaned = str_replace([',', ' '], '', trim((string)$amountRaw));
                    if ($amountCleaned === '' || !is_numeric($amountCleaned)) {
                        $rowErrors[] = "amount '$amountRaw' is not numeric.";
                    }
                    $amount = (float)$amountCleaned;

                    if (!empty($rowErrors)) {
                        $errors[] = ['row' => $rowNumber, 'message' => implode(' | ', $rowErrors)];
                        $failed++;
                        continue;
                    }

                    // Optional fields
                    $deletionDate = $this->parseDate($deletionRaw);
                    $today        = Carbon::now()->startOfDay();
                    $daysLeft     = (int) $today->diffInDays(Carbon::parse($renewalDate)->startOfDay(), false);
                    $daysToDelete = $deletionDate
                        ? (int) $today->diffInDays(Carbon::parse($deletionDate)->startOfDay(), false)
                        : null;

                    $status = 1;
                    if (strtolower(trim((string)$statusRaw)) === 'inactive' || (string)$statusRaw === '0') {
                        $status = 0;
                    }

                    $newRec = \App\Models\Subscription::create([
                        'product_id'     => $productId,
                        'client_id'      => $clientId,
                        'vendor_id'      => $vendorId,
                        'amount'         => $amount,
                        'renewal_date'   => $renewalDate,
                        'deletion_date'  => $deletionDate,
                        'days_left'      => $daysLeft,
                        'days_to_delete' => $daysToDelete,
                        'status'         => $status,
                        'remarks'        => $remarks,
                    ]);

                    $inserted++;
                    $insertedRecords[] = $newRec;
                }
            });

            // Return failure when nothing inserted so frontend shows actual errors
            if ($inserted === 0 && $failed > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No records imported. Check errors for details.',
                    'inserted' => 0,
                    'failed'   => $failed,
                    'errors'   => $errors,
                ], 422);
            }

            $sortedInserted = collect($insertedRecords)->sortByDesc('created_at')->values()->all();

            $filePath = $request->file('file')->store('imports');
            \App\Models\ImportHistory::create([
                'module_name'     => 'General',
                'action'          => 'import',
                'file_name'       => $request->file('file')->getClientOriginalName(),
                'file_path'       => $filePath,
                'imported_by'     => 'System / Admin',
                'successful_rows' => $inserted,
                'failed_rows'     => $failed,
            ]);

            return response()->json([
                'success'       => true,
                'message'       => "$inserted record(s) imported successfully." . ($failed > 0 ? " $failed row(s) skipped." : ""),
                'inserted'      => $inserted,
                'failed'        => $failed,
                'errors'        => $errors,
                'inserted_data' => $sortedInserted
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Import failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'System error: ' . $e->getMessage()
            ], 500);
        }
    }

    private function parseDate($value)
    {
        if (empty($value)) return null;

        // Excel numeric date (serial number)
        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
            } catch (\Exception $e) {
                return null;
            }
        }

        // String date
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
}
