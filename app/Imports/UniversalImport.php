<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * UniversalImport — covers Hosting, Email, Counter, and Domain modules.
 *
 * Standard Excel columns (non-domain):
 *   Product | Client | Vendor | Renewal Date | Amount | Deletion Date | (Days Left) | Status | Remarks
 *
 * Domain Excel columns (module = 'domains'):
 *   Domain Name | Product | Client | Vendor | Renewal Date | Amount | Deletion Date | Status | Remarks
 *
 * Client table: users
 * Duplicate key: product_id + vendor_id + renewal_date + client_id
 */
class UniversalImport extends SmartImporter
{
    private string $module;

    public function __construct(string $tableName = 'hostings', ?int $forcedClientId = null)
    {
        parent::__construct($forcedClientId);
        $this->module = $tableName;
        $this->moduleName = $tableName; // default label; overridden by ImportService
    }

    protected function tableName(): string   { return $this->module; }
    protected function clientTable(): string { return 'superadmins'; }

    protected function columnHeaders(): array
    {
        if ($this->module === 'domains') {
            return ['Domain Name', 'Product', 'Client', 'Vendor', 'Renewal Date', 'Amount', 'Deletion Date', 'Status', 'Remarks'];
        }
        return ['Product', 'Client', 'Vendor', 'Renewal Date', 'Amount', 'Deletion Date', 'Days Left', 'Status', 'Remarks'];
    }

    // ── Duplicate detection ────────────────────────────────────────────────────

    protected function duplicateKey(array $p): string
    {
        $product = $this->productNamesMap[(int)$p['productId']] ?? (string)$p['productId'];
        $vendor  = $this->vendorNamesMap[(int)$p['vendorId']]   ?? (string)$p['vendorId'];
        $client  = $this->clientNamesMap[(int)$p['clientId']]   ?? (string)$p['clientId'];

        if ($this->module === 'domains') {
            $domain = $this->normalize($p['domainNameRaw']);
            return "{$domain}_{$product}_{$vendor}_{$p['renewalDate']}_{$client}";
        }

        return "{$product}_{$vendor}_{$p['renewalDate']}_{$client}";
    }

    protected function existingDupKeys(array $parsedRows): array
    {
        $set = [];
        if (empty($parsedRows)) return $set;

        $productIds = array_unique(array_column($parsedRows, 'productId'));
        $vendorIds  = array_unique(array_column($parsedRows, 'vendorId'));
        $clientIds  = array_unique(array_column($parsedRows, 'clientId'));
        $dates      = array_unique(array_column($parsedRows, 'renewalDate'));

        if ($this->module === 'domains') {
            $domainNames = array_unique(array_filter(array_column($parsedRows, 'domainNameRaw')));
            $query = DB::table($this->module)->select(['name', 'product_id', 'vendor_id', 'renewal_date', 'client_id']);
            
            if (!empty($productIds)) $query->whereIn('product_id', $productIds);
            if (!empty($vendorIds))  $query->whereIn('vendor_id', $vendorIds);
            if (!empty($clientIds))  $query->whereIn('client_id', $clientIds);
            if (!empty($dates))      $query->whereIn('renewal_date', $dates);

            $query->get()->each(function ($s) use (&$set) {
                try { $n = \App\Services\CryptService::decryptData($s->name) ?? $s->name; } catch (\Throwable $e) { $n = $s->name; }
                $domain = $this->normalize($n);
                $p = $this->productNamesMap[(int)$s->product_id] ?? (string)$s->product_id;
                $v = $this->vendorNamesMap[(int)$s->vendor_id]   ?? (string)$s->vendor_id;
                $c = $this->clientNamesMap[(int)$s->client_id]   ?? (string)$s->client_id;
                $set["{$domain}_{$p}_{$v}_{$s->renewal_date}_{$c}"] = true;
            });
            return $set;
        }

        $query = DB::table($this->module)->select(['product_id', 'vendor_id', 'renewal_date', 'client_id']);
        if (!empty($productIds)) $query->whereIn('product_id', $productIds);
        if (!empty($vendorIds))  $query->whereIn('vendor_id', $vendorIds);
        if (!empty($clientIds))  $query->whereIn('client_id', $clientIds);
        if (!empty($dates))      $query->whereIn('renewal_date', $dates);

        $query->get()->each(function ($s) use (&$set) {
            $p = $this->productNamesMap[(int)$s->product_id] ?? (string)$s->product_id;
            $v = $this->vendorNamesMap[(int)$s->vendor_id]   ?? (string)$s->vendor_id;
            $c = $this->clientNamesMap[(int)$s->client_id]   ?? (string)$s->client_id;
            $set["{$p}_{$v}_{$s->renewal_date}_{$c}"] = true;
        });

        return $set;
    }

    // ── Row parsing ────────────────────────────────────────────────────────────

    protected function parseRow(Collection $row, int $rowNumber): ?array
    {
        $isDomains = ($this->module === 'domains');

        if ($isDomains) {
            $domainNameRaw = $this->clean($row[0] ?? '');
            $productName   = $this->clean($row[1] ?? '');
            $clientName    = $this->clean($row[2] ?? '');
            $vendorName    = $this->clean($row[3] ?? '');
            $renewalDate   = $this->parseDate($row[4] ?? null) ?? now()->format('Y-m-d');
            $amount        = $this->normalizeAmount($row[5] ?? 0);
            $deletionDate  = $this->parseDate($row[6] ?? null);
            $status        = isset($row[7]) && (strtolower(trim((string)$row[7])) === 'inactive' || (string)$row[7] === '0') ? 0 : 1;
            $remarks       = !empty($row[8]) ? trim((string)$row[8]) : null;
        } else {
            $domainNameRaw = null;
            $productName   = $this->clean($row[0] ?? '');
            $clientName    = $this->clean($row[1] ?? '');
            $vendorName    = $this->clean($row[2] ?? '');
            $renewalDate   = $this->parseDate($row[3] ?? null) ?? now()->format('Y-m-d');
            $amount        = $this->normalizeAmount($row[4] ?? 0);
            $deletionDate  = $this->parseDate($row[5] ?? null);
            $status        = isset($row[7]) && (strtolower(trim((string)$row[7])) === 'inactive' || (string)$row[7] === '0') ? 0 : 1;
            $remarks       = !empty($row[8]) ? trim((string)$row[8]) : null;
        }

        if ($isDomains) {
            if ($domainNameRaw === '' || $productName === '' || $clientName === '' || $vendorName === '') {
                return []; // Skip silently
            }
        } else {
            if ($productName === '' || $clientName === '' || $vendorName === '') {
                return []; // Skip silently
            }
        }

        $productId = $this->smartResolve('products', $productName, $this->productCache);
        $clientId  = $this->forcedClientId
                        ?? $this->smartResolve('superadmins', $clientName, $this->clientCache);
        $vendorId  = $this->smartResolve('vendors', $vendorName, $this->vendorCache, true);

        if (!$clientId) {
            $this->errors[] = ['row' => $rowNumber, 'reason' => 'Could not resolve client'];
            return null;
        }

        return compact(
            'productId', 'clientId', 'vendorId', 'amount',
            'renewalDate', 'deletionDate', 'status', 'remarks', 'domainNameRaw'
        );
    }

    protected function rawRowToArray(Collection $row): array
    {
        if ($this->module === 'domains') {
            return [
                $this->clean($row[0] ?? ''), // Domain Name
                $this->clean($row[1] ?? ''), // Product
                $this->clean($row[2] ?? ''), // Client
                $this->clean($row[3] ?? ''), // Vendor
                (string)($row[4] ?? ''),     // Renewal Date
                (string)($row[5] ?? ''),     // Amount
                (string)($row[6] ?? ''),     // Deletion Date
                (string)($row[7] ?? ''),     // Status
                (string)($row[8] ?? ''),     // Remarks
            ];
        }

        return [
            $this->clean($row[0] ?? ''), // Product
            $this->clean($row[1] ?? ''), // Client
            $this->clean($row[2] ?? ''), // Vendor
            (string)($row[3] ?? ''),     // Renewal Date
            (string)($row[4] ?? ''),     // Amount
            (string)($row[5] ?? ''),     // Deletion Date
            (string)($row[6] ?? ''),     // Days Left
            (string)($row[7] ?? ''),     // Status
            (string)($row[8] ?? ''),     // Remarks
        ];
    }

    // ── Insert builder ─────────────────────────────────────────────────────────

    protected function buildInsertRow(array $p, string $nowTimestamp): array
    {
        $row = [
            'product_id'     => $p['productId'],
            'client_id'      => $p['clientId'],
            'vendor_id'      => $p['vendorId'],
            'amount'         => $p['amount'],
            'renewal_date'   => $p['renewalDate'],
            'deletion_date'  => $p['deletionDate'],
            'days_left'      => $this->calcDaysLeft($p['renewalDate']),
            'days_to_delete' => $this->calcDaysLeft($p['deletionDate']),
            'status'         => $p['status'],
            'remarks'        => $p['remarks'],
            'created_at'     => $nowTimestamp,
            'updated_at'     => $nowTimestamp,
        ];

        if ($this->module === 'domains' && !empty($p['domainNameRaw'])) {
            $row['name'] = \App\Services\CryptService::encryptData($p['domainNameRaw']);
        }

        return $row;
    }
}
