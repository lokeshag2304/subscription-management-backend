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
use App\Models\Domain;
use App\Models\Hosting;
use App\Models\Email;
use App\Models\Counter;
use App\Models\Vendor;
use App\Models\Product;
use App\Models\Superadmin;

class CsvController extends Controller
{
    use \App\Traits\NativeCsvExporter;

    public function exportCategoryRecords(Request $request)
    {
        try {
            $data = is_array($request->all()) ? $request->all() : json_decode($request->getContent(), true);
            $recordType = $data['record_type'] ?? null;

            if (!$recordType) {
                return response()->json(['success' => false, 'message' => 'record_type is required'], 400);
            }

            $typeMap = [
                1 => ['label' => 'Subscriptions', 'model' => Subscription::class],
                2 => ['label' => 'SSL',           'model' => SSL::class],
                3 => ['label' => 'Hosting',       'model' => Hosting::class],
                4 => ['label' => 'Domains',       'model' => Domain::class],
                5 => ['label' => 'Emails',        'model' => Email::class],
                6 => ['label' => 'Counter',       'model' => Counter::class],
                7 => ['label' => 'Users',         'model' => Superadmin::class],
                8 => ['label' => 'SuperAdmins',   'model' => Superadmin::class],
                9 => ['label' => 'Clients',       'model' => Superadmin::class],
                10 => ['label' => 'Vendors',      'model' => Vendor::class],
                11 => ['label' => 'Products',     'model' => Product::class],
                12 => ['label' => 'DomainMaster', 'model' => \App\Models\DomainName::class]
            ];

            if (!isset($typeMap[$recordType])) {
                return response()->json(['success' => false, 'message' => 'Invalid record_type'], 400);
            }

            $moduleInfo = $typeMap[$recordType];
            $typeLabel  = $moduleInfo['label'];
            $modelClass = $moduleInfo['model'];

            $relations = [];
            if (in_array($recordType, [1, 2, 3, 4, 5, 6])) {
                $relations = ['product', 'client', 'vendor', 'domainMaster'];
                if ($recordType == 2) { $relations[] = 'domainInfo'; }
            }
            
            $query = $modelClass::with($relations)->orderBy('id', 'desc');

            // Apply filters for UserManagement (Superadmin model)
            if (in_array($recordType, [7, 8, 9])) {
                $loginType = 2; // Default User
                if ($recordType == 8) $loginType = 1; // SuperAdmin
                if ($recordType == 9) $loginType = 3; // Client
                $query->where('login_type', $loginType);
            }

            $rows = $query->get();

            if ($rows->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No records found'], 404);
            }

            // Headers Mapping
            if ($recordType == 1) {
                $headers = ['S.No','Product Name','Client Name','Vendor Name','Amount','Renewal Date','Expiry Date','Days Left','Status','Remark','Last Updated'];
            } elseif (in_array($recordType, [2, 3, 4, 5, 6])) {
                $headers = ['S.No','Domain Name','Client Name','Product Name','Vendor Name','Amount','Renewal Date','Days To Expire','Status','Remark','Last Updated'];
            } elseif (in_array($recordType, [7, 8, 9])) {
                $headers = ['S.No', 'Name', 'Email', 'Phone', 'Address', 'Created At'];
            } elseif ($recordType == 10) {
                $headers = ['S.No', 'Vendor Name', 'Created At'];
            } elseif ($recordType == 11) {
                $headers = ['S.No', 'Product Name', 'Created At'];
            } elseif ($recordType == 12) {
                $headers = ['S.No', 'Domain Name', 'Created At'];
            } else {
                $headers = ['S.No', 'Name', 'Details', 'Created At'];
            }

            $csvContent = $this->generateNativeCsv($headers, $rows, $recordType);

            $filename = $typeLabel . '_Export_' . now()->format('Ymd_His') . '.csv';
            
            // Store History Log
            $importedBy = 'System / Admin';
            if (auth()->check()) {
                try { 
                    $name = auth()->user()->name;
                    $importedBy = CryptService::decryptData($name) ?? $name;
                } catch (\Throwable $e) {}
            }

            // Save file to disk physically
            \Illuminate\Support\Facades\Storage::disk('local')->put('exports/' . $filename, $csvContent);

            $user = auth()->user();
            $userId = $user->id ?? 1;
            $role = $user->role ?? 'System';

            $history = ImportHistory::create([
                'user_id'         => $userId,
                'role'            => $role,
                'module_name'     => $typeLabel,
                'action'          => 'export',
                'file_name'       => $filename,
                'file_path'       => 'exports/' . $filename,
                'imported_by'     => $importedBy,
                'successful_rows' => count($rows),
                'failed_rows'     => 0, 
                'duplicates_count'=> 0,
            ]);

            \App\Services\ActivityLogger::exported(null, $typeLabel, count($rows));

            // Return raw stream for Blob handling in frontend
            return response($csvContent)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Length', strlen($csvContent))
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Native Export Error: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Export failed: ' . $e->getMessage()], 500);
        }
    }



    
 
}
