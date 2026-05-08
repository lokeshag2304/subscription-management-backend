<?php

namespace App\Http\Controllers;

use App\Models\SSL;
use App\Models\Superadmin;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\ImportHistory;
use App\Services\CryptService;
use App\Services\ClientScopeService;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SSLExportController extends Controller
{
    /**
     * "Zero-Crash" Mode SSL Export Logic.
     * Fixed: Removed non-existent database columns (client_id) from history logging.
     */
    public function export(Request $request)
    {
        // Suppress any server-side output before stream starts
        if (ob_get_level()) ob_end_clean();

        // Safe environment settings
        @set_time_limit(1200);
        @ini_set('memory_limit', '1024M');

        $filename = 'SSL_Export_' . date('Y-m-d') . '.csv';

        // Standard Table Headers
        $headers = [
            'ID', 'Domain Name', 'Client', 'Product', 'Vendor', 
            'Amount', 'Renewal Date', 'Deletion Date', 'Days Left', 
            'Days to Delete', 'Grace End Date', 'Due Date', 'Status', 
            'Remarks', 'Last Updated'
        ];

        return response()->streamDownload(function() use ($headers, $request) {
            try {
                $handle = fopen('php://output', 'w');
            
            // UTF-8 BOM for Windows Excel
            fwrite($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            
            fputcsv($handle, $headers);

            $query = SSL::with(['product', 'client', 'vendor', 'domainMaster']);
            
            // Apply Client Separation Logic
            ClientScopeService::applyScope($query, $request);

            $rowCount = 0;
            $today = now()->startOfDay();

            // Chunked processing
            $query->chunk(150, function($records) use ($handle, $today, &$rowCount) {
                foreach ($records as $item) {
                    $rowCount++;
                    $domainName  = optional($item->domainMaster)->domain_name ?? 'N/A';
                    
                    try {
                        $clientName  = $item->client ? (CryptService::decryptData($item->client->name) ?? $item->client->name) : 'N/A';
                        $productName = $item->product ? (CryptService::decryptData($item->product->name) ?? $item->product->name) : 'N/A';
                        $vendorName  = $item->vendor ? (CryptService::decryptData($item->vendor->name) ?? $item->vendor->name) : 'N/A';
                        $remarks     = $item->remarks ? (CryptService::decryptData($item->remarks) ?? $item->remarks) : '';
                    } catch (\Exception $e) {
                        $clientName  = optional($item->client)->name ?? 'N/A';
                        $productName = optional($item->product)->name ?? 'N/A';
                        $vendorName  = optional($item->vendor)->name ?? 'N/A';
                        $remarks     = $item->remarks ?? '';
                    }

                    $daysLeft = $item->renewal_date ? $today->diffInDays(Carbon::parse($item->renewal_date)->startOfDay(), false) : '--';
                    $daysToDelete = $item->deletion_date ? $today->diffInDays(Carbon::parse($item->deletion_date)->startOfDay(), false) : '--';

                    fputcsv($handle, [
                        $item->id,
                        $domainName,
                        $clientName,
                        $productName,
                        $vendorName,
                        $item->amount ?? 0,
                        $item->renewal_date ?? '--',
                        $item->deletion_date ?? '--',
                        $daysLeft,
                        $daysToDelete,
                        $item->grace_period ?? 0,
                        $item->due_date ?? '--',
                        $item->status == 1 ? 'Active' : 'Inactive',
                        $remarks,
                        $item->updated_at ? $item->updated_at->format('Y-m-d H:i:s') : '--'
                    ]);
                }
            });

            fclose($handle);

            } catch (\Exception $e) {
                Log::error("SSL Export Finalization failed: " . $e->getMessage());
            }

        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Content-Transfer-Encoding' => 'binary',
        ]);
    }
}
