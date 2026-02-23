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
class CsvController extends Controller
{

public function exportCategoryRecords(Request $request)
{
    try {

        $data = json_decode($request->getContent(), true);

        $recordType = $data['record_type'] ?? null;
        $s_id       = $data['s_id'] ?? null;

        if (!$recordType || !$s_id) {
            return response()->json([
                'success' => false,
                'message' => 'record_type and s_id are required'
            ], 400);
        }

        // =========================
        // TYPE MAP
        // =========================
        $typeMap = [
            1 => 'Subscriptions',
            2 => 'SSL',
            3 => 'Hosting',
            4 => 'Domains',
            5 => 'Emails',
            6 => 'Counter'
        ];

        if (!isset($typeMap[$recordType])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid record_type'
            ], 400);
        }

        $typeLabel = $typeMap[$recordType];

        // =========================
        // FETCH DATA (WITH VENDOR)
        // =========================
        $rows = DB::table('categories as c')
            ->leftJoin('superadmins as sa', 'sa.id', '=', 'c.client_id')
            ->leftJoin('domain as d', 'd.id', '=', 'c.domain_id')
            ->leftJoin('products as p', 'p.id', '=', 'c.product_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'c.vendor_id')
            ->select(
                'c.*',
                'sa.name as client_name_enc',
                'd.name as domain_name_enc',
                'p.name as product_name_enc',
                'v.name as vendor_name_enc'
            )
            ->where('c.record_type', $recordType)
            ->orderBy('c.id', 'desc')
            ->get();

        if ($rows->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No records found'
            ], 404);
        }

        // =========================
        // EXCEL INIT
        // =========================
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $rowNo = 1;

        // =========================
        // TITLE
        // =========================
        $sheet->mergeCells("A$rowNo:Z$rowNo");
        $sheet->setCellValue("A$rowNo", strtoupper($typeLabel) . ' REPORT');
        $sheet->getStyle("A$rowNo")->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle("A$rowNo")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $rowNo += 2;

        // =========================
        // HEADERS (SWITCH)
        // =========================
        switch ($recordType) {

            case 1:
                $headers = ['S.No','Product Name','Amount','Renewal Date','Expiry Date','Days Left','Status','Remark','Last Updated'];
                break;

            case 2:
                $headers = ['S.No','Domain Name','Client Name','Product Name','Vendor Name','Amount','Renewal Date','Days To Expire','Status','Remark','Last Updated'];
                break;

            case 3:
                $headers = ['S.No','Domain Name','Client Name','Product Name','Vendor Name','Valid Till','Today Date','Amount','Renewal Date','Days To Expire','Status','Remark','Last Updated'];
                break;

            case 4:
                $headers = ['S.No','Domain Name','Client Name','Product Name','Vendor Name','Amount','Renewal Date','Days To Expire','Status','Remark','Domain Protected','Deletion Date','Last Updated'];
                break;

            case 5:
                $headers = ['S.No','Domain Name','Client Name','Product Name','Vendor Name','Amount','Renewal Date','Days To Expire','Status','Remark','Domain Quantity','Bill Type','Start Date','Last Updated'];
                break;

            case 6:
                $headers = ['S.No','Domain Name','Client Name','Product Name','Vendor Name','Amount','Renewal Date','Days To Expire','Status','Remark','Last Updated'];
                break;
        }

        $col = 'A';
        foreach ($headers as $h) {
            $sheet->setCellValue($col . $rowNo, $h);
            $sheet->getStyle($col . $rowNo)->getFont()->setBold(true);
            $col++;
        }
        $rowNo++;

        // =========================
        // DATA ROWS
        // =========================
        $today  = Carbon::today();
        $serial = 1;

        foreach ($rows as $r) {

            try { $client  = CryptService::decryptData($r->client_name_enc); } catch (\Exception $e) { $client = ''; }
            try { $domain  = CryptService::decryptData($r->domain_name_enc); } catch (\Exception $e) { $domain = ''; }
            try { $product = CryptService::decryptData($r->product_name_enc); } catch (\Exception $e) { $product = ''; }
            try { $vendor  = CryptService::decryptData($r->vendor_name_enc); } catch (\Exception $e) { $vendor = ''; }

            $days = null;
            if (!empty($r->expiry_date)) {
                $expiry = Carbon::parse($r->expiry_date);
                $days = $expiry->gte($today)
                    ? $today->diffInDays($expiry)
                    : -$expiry->diffInDays($today);
            }

            switch ($recordType) {

                case 1:
                    $rowData = [
                        $serial++, $product, $r->amount, $r->renewal_date,
                        $r->expiry_date, $days,
                        $r->status == 1 ? 'Active' : 'Deactive',
                        $r->remarks, Carbon::parse($r->updated_at)->format('d M Y')
                    ];
                    break;

                case 2:
                    $rowData = [
                        $serial++, $domain, $client, $product, $vendor,
                        $r->amount, $r->renewal_date, $days,
                        $r->status == 1 ? 'Active' : 'Deactive',
                        $r->remarks, Carbon::parse($r->updated_at)->format('d M Y')
                    ];
                    break;

                case 3:
                    $rowData = [
                        $serial++, $domain, $client, $product, $vendor,
                        $r->valid_till, now()->toDateString(),
                        $r->amount, $r->renewal_date, $days,
                        $r->status == 1 ? 'Active' : 'Deactive',
                        $r->remarks, Carbon::parse($r->updated_at)->format('d M Y')
                    ];
                    break;

                case 4:
                    $rowData = [
                        $serial++, $domain, $client, $product, $vendor,
                        $r->amount, $r->renewal_date, $days,
                        $r->status == 1 ? 'Active' : 'Deactive',
                        $r->remarks, $r->domain_protected,
                        $r->deleted_at, Carbon::parse($r->updated_at)->format('d M Y')
                    ];
                    break;

                case 5:
                    $rowData = [
                        $serial++, $domain, $client, $product, $vendor,
                        $r->amount, $r->renewal_date, $days,
                        $r->status == 1 ? 'Active' : 'Deactive',
                        $r->remarks, $r->quantity, $r->bill_type,
                        $r->start_date, Carbon::parse($r->updated_at)->format('d M Y')
                    ];
                    break;

                case 6:
                    $rowData = [
                        $serial++, $domain, $client, $product, $vendor,
                        $r->amount, $r->renewal_date, $days,
                        $r->status == 1 ? 'Active' : 'Deactive',
                        $r->remarks, Carbon::parse($r->updated_at)->format('d M Y')
                    ];
                    break;
            }

            $col = 'A';
            foreach ($rowData as $val) {
                $sheet->setCellValue($col . $rowNo, $val);
                $col++;
            }

            $rowNo++;
        }

        foreach (range('A', 'Z') as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }

        // =========================
        // EXPORT + ACTIVITY
        // =========================
        $writer = new Xlsx($spreadsheet);
        $filename = $typeLabel . '_Export_' . now()->format('His') . '.xlsx';

        ob_start();
        $writer->save('php://output');
        $excelOutput = ob_get_clean();

        DB::table('activities')->insert([
            'action'    => CryptService::encryptData($typeLabel . ' Export'),
            's_action'  => CustomCipherService::encryptData($typeLabel . ' Export'),
            'user_id'   => $s_id,
            'cat_id'    => null,
            'message'   => CryptService::encryptData($typeLabel . ' records exported successfully'),
            's_message' => CustomCipherService::encryptData($typeLabel . ' records exported successfully'),
            'details'   => CryptService::encryptData(json_encode([
                'record_type'   => $recordType,
                'record_count' => count($rows),
                'filename'     => $filename
            ])),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'filename' => $filename,
                'base64'   => base64_encode($excelOutput)
            ]
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'success' => false,
            'message' => 'Export failed',
            'error'   => $e->getMessage()
        ], 500);
    }
}



    
 
}
