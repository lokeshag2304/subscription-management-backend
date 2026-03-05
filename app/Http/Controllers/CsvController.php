<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Carbon\Carbon;
use App\Services\CryptService;
use App\Services\CustomCipherService;
use Mpdf\Mpdf;
use App\Models\ImportHistory;
use App\Models\Subscription;
use App\Models\SSL;
use App\Models\Hosting;
use App\Models\Domain;
use App\Models\Email;
use App\Models\Counter;

class CsvController extends Controller
{
    public function exportCategoryRecords(Request $request)
    {
        try {
            // Increase time limit for potentially large exports
            set_time_limit(180);

            $data = is_array($request->all()) ? $request->all() : json_decode($request->getContent(), true);
            $recordType = $data['record_type'] ?? null;

            if (!$recordType) {
                return response()->json(['success' => false, 'message' => 'record_type is required'], 400);
            }

            // =========================
            // MODEL MAPPING
            // =========================
            $typeMap = [
                1 => ['label' => 'Subscriptions', 'model' => Subscription::class],
                2 => ['label' => 'SSL',           'model' => SSL::class],
                3 => ['label' => 'Hosting',       'model' => Hosting::class],
                4 => ['label' => 'Domains',       'model' => Domain::class],
                5 => ['label' => 'Emails',        'model' => Email::class],
                6 => ['label' => 'Counter',       'model' => Counter::class]
            ];

            if (!isset($typeMap[$recordType])) {
                return response()->json(['success' => false, 'message' => 'Invalid record_type'], 400);
            }

            $moduleInfo = $typeMap[$recordType];
            $typeLabel  = $moduleInfo['label'];
            $modelClass = $moduleInfo['model'];

            // =========================
            // FETCH DATA WITH RELATIONS
            // =========================
            $relations = ['product', 'client', 'vendor'];
            if ($recordType == 2) { $relations[] = 'domainInfo'; }
            
            $rows = $modelClass::with($relations)->orderBy('id', 'desc')->get();

            if ($rows->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No records found'], 404);
            }

            // =========================
            // EXCEL INITIALIZATION
            // =========================
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $rowNo = 1;

            $sheet->mergeCells("A$rowNo:Z$rowNo");
            $sheet->setCellValue("A$rowNo", strtoupper($typeLabel) . ' REPORT');
            $sheet->getStyle("A$rowNo")->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle("A$rowNo")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $rowNo += 2;

            // =========================
            // HEADERS SELECTION
            // =========================
            switch ($recordType) {
                case 1: 
                    $headers = ['S.No','Product Name','Client Name','Vendor Name','Amount','Renewal Date','Expiry Date','Days Left','Status','Remark','Last Updated']; 
                    break;
                default: 
                    $headers = ['S.No','Domain Name','Client Name','Product Name','Vendor Name','Amount','Renewal Date','Days To Expire','Status','Remark','Last Updated']; 
                    break;
            }

            $colChar = 'A';
            foreach ($headers as $h) {
                $sheet->setCellValue($colChar . $rowNo, $h);
                $sheet->getStyle($colChar . $rowNo)->getFont()->setBold(true);
                $colChar++;
            }
            $rowNo++;

            // =========================
            // FILL DATA ROWS
            // =========================
            $today  = Carbon::today();
            $serial = 1;

            foreach ($rows as $r) {
                // Decrypt core fields
                $pName = $r->product->name ?? 'N/A';
                $cName = $r->client->name ?? 'N/A';
                $vName = $r->vendor->name ?? 'N/A';
                
                try { $dec = CryptService::decryptData($pName); if($dec) $pName = $dec; } catch (\Exception $e) {}
                try { $dec = CryptService::decryptData($cName); if($dec) $cName = $dec; } catch (\Exception $e) {}
                
                $domain = ($recordType == 2 && $r->domainInfo) ? $r->domainInfo->name : 'N/A';

                $days = null;
                if (!empty($r->renewal_date)) {
                    $renewal = Carbon::parse($r->renewal_date);
                    $days = (int)$today->diffInDays($renewal, false);
                }

                if ($recordType == 1) {
                    $rowData = [
                        $serial++, $pName, $cName, $vName, $r->amount, $r->renewal_date,
                        $r->expiry_date ?? $r->renewal_date, $days,
                        $r->status == 1 ? 'Active' : 'Inactive',
                        $r->remarks, optional($r->updated_at)->format('d M Y') ?? '--'
                    ];
                } else {
                    $rowData = [
                        $serial++, $domain, $cName, $pName, $vName,
                        $r->amount, $r->renewal_date, $days,
                        $r->status == 1 ? 'Active' : 'Inactive',
                        $r->remarks, optional($r->updated_at)->format('d M Y') ?? '--'
                    ];
                }

                $colChar = 'A';
                foreach ($rowData as $val) {
                    $sheet->setCellValue($colChar . $rowNo, $val);
                    $colChar++;
                }
                $rowNo++;
            }

            foreach (range('A', 'L') as $c) {
                $sheet->getColumnDimension($c)->setAutoSize(true);
            }

            // =========================
            // GENERATE FILE & SAVE
            // =========================
            $writer = new Xlsx($spreadsheet);
            $filename = $typeLabel . '_Export_' . now()->format('Ymd_His') . '.xlsx';
            
            ob_start();
            $writer->save('php://output');
            $excelOutput = ob_get_clean();

            // Save to local storage for History Box
            $filePath = 'exports/' . $filename;
            \Illuminate\Support\Facades\Storage::disk('local')->put($filePath, $excelOutput);

            // Dynamically resolve User
            $importedBy = 'System / Admin';
            if (auth()->check()) {
                $user = auth()->user();
                $importedBy = $user->name ?? $user->email ?? ('User ID: ' . $user->id);
                try { $dec = CryptService::decryptData($importedBy); if($dec) $importedBy = $dec; } catch (\Exception $e) {}
            }

            // Create History Record
            $history = ImportHistory::create([
                'module_name'     => $typeLabel,
                'action'          => 'export',
                'file_name'       => $filename,
                'file_path'       => $filePath,
                'imported_by'     => $importedBy,
                'successful_rows' => count($rows),
                'failed_rows'     => 0,
                'duplicates_count'=> 0,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Export completed successfully',
                'data' => [
                    'filename' => $filename,
                    'base64'   => base64_encode($excelOutput),
                    'history'  => $history
                ]
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Excel Export Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage()
            ], 500);
        }
    }



    
 
}
