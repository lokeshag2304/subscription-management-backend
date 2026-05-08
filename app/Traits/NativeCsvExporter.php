<?php

namespace App\Traits;

use App\Services\CryptService;
use Carbon\Carbon;

trait NativeCsvExporter
{
    /**
     * Generate a native CSV string from a collection/array of data.
     */
    public function generateNativeCsv($headers, $rows, $recordType = null)
    {
        // Safely handle time limit
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $handle = fopen('php://temp', 'r+');
        fputs($handle, (chr(0xEF) . chr(0xBB) . chr(0xBF))); // BOM for Excel

        fputcsv($handle, $headers);

        $today = Carbon::today();
        $serial = 1;

        foreach ($rows as $r) {
            try {
                $rowData = $this->mapRowForExport($r, $serial++, $recordType, $today);
                if ($rowData) {
                    fputcsv($handle, $rowData);
                }
            } catch (\Throwable $e) {
                continue; // Skip faulty rows
            }
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * Map a single model/record to a CSV row array.
     */
    protected function mapRowForExport($r, $serial, $recordType, $today)
    {
        $decrypt = function($val) {
            if (!$val) return 'N/A';
            try { return CryptService::decryptData($val) ?? $val; } catch (\Throwable $e) { return $val; }
        };

        $pName   = $decrypt($r->product->name   ?? $r->product_name ?? 'N/A');
        $cName   = $decrypt($r->client->name    ?? $r->client_name  ?? 'N/A');
        $vName   = $decrypt($r->vendor->name    ?? $r->vendor_name  ?? 'N/A');
        $remarks = $decrypt($r->remarks);

        // Domain Resolution
        $domain = 'N/A';
        if ($recordType == 2 && isset($r->domainInfo)) {
            $domain = $r->domainInfo->name ?? $r->domainInfo->domain_name ?? 'N/A';
        } elseif (isset($r->domainMaster)) {
            $domain = $r->domainMaster->domain_name ?? 'N/A';
        } elseif (isset($r->domain_name)) {
            $domain = $r->domain_name;
        } elseif (isset($r->name)) {
            $domain = $r->name;
        }

        $renewalStr = $r->renewal_date ? Carbon::parse($r->renewal_date)->format('d-m-Y') : 'N/A';
        $expiryStr  = ($r->expiry_date ?? $r->renewal_date) ? Carbon::parse($r->expiry_date ?? $r->renewal_date)->format('d-m-Y') : 'N/A';

        $days = 'N/A';
        if ($r->renewal_date) {
            try { $days = (int)$today->diffInDays(Carbon::parse($r->renewal_date)->startOfDay(), false); } catch (\Throwable $e) {}
        }

        // Subscriptions (recordType 1)
        if ($recordType == 1) {
            return [
                $serial, $pName, $cName, $vName, $r->amount, $renewalStr,
                $expiryStr, $days,
                $r->status == 1 ? 'ACTIVE' : 'INACTIVE',
                $remarks, optional($r->updated_at)->format('d-m-Y H:i') ?? '--'
            ];
        } 
        // Services (recordType 2-6)
        elseif (in_array($recordType, [2, 3, 4, 5, 6])) {
            return [
                $serial, $domain, $cName, $pName, $vName,
                $r->amount, $renewalStr, $days,
                $r->status == 1 ? 'ACTIVE' : 'INACTIVE',
                $remarks, optional($r->updated_at)->format('d-m-Y H:i') ?? '--'
            ];
        }
        // ── CATEGORY 3: Other Entities ───────────────────────────────────────────
        
        $name    = $decrypt($r->name);
        $email   = $decrypt($r->email);
        $phone   = $decrypt($r->number ?? $r->phone);
        $address = $decrypt($r->address);
        $createdAt = optional($r->created_at)->format('d-m-Y H:i') ?? '--';

        // Users, SuperAdmins, Clients
        if (in_array($recordType, [7, 8, 9])) {
            return [$serial, $name, $email, $phone, $address, $createdAt];
        } 
        // Vendors
        elseif ($recordType == 10) {
            return [$serial, $name, $createdAt];
        }
        // Products
        elseif ($recordType == 11) {
            return [$serial, $name, $createdAt];
        }
        // Domain Master
        elseif ($recordType == 12) {
            return [$serial, ($r->domain_name ?? 'N/A'), $createdAt];
        }
        // Fallback
        else {
            return [$serial, $name, $email, $createdAt];
        }
    }
}
