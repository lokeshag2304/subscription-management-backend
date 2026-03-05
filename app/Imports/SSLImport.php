<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SSLImport
 *
 * Excel columns: Domain | Client | Product | Vendor | Amount | Renewal Date | Deletion Date | Status | Remarks
 * Client table: users
 * Duplicate key: product_id + vendor_id + renewal_date + client_id
 */
class SSLImport extends SmartImporter
{
    protected function tableName(): string   { return 's_s_l_s'; }
    protected function clientTable(): string { return 'superadmins'; }

    protected function columnHeaders(): array
    {
        return ['Domain', 'Client', 'Product', 'Vendor', 'Amount', 'Renewal Date', 'Deletion Date', 'Status', 'Remarks'];
    }

    // ── Duplicate detection ────────────────────────────────────────────────────

    protected function duplicateKey(array $p): string
    {
        $product = $this->productNamesMap[(int)$p['productId']] ?? (string)$p['productId'];
        $vendor  = $this->vendorNamesMap[(int)$p['vendorId']]   ?? (string)$p['vendorId'];
        $client  = $this->clientNamesMap[(int)$p['clientId']]   ?? (string)$p['clientId'];
        return "{$p['domainId']}_{$product}_{$vendor}_{$p['renewalDate']}_{$client}";
    }

    protected function existingDupKeys(array $parsedRows): array
    {
        $set = [];
        if (empty($parsedRows)) return $set;

        $domainIds  = array_unique(array_column($parsedRows, 'domainId'));
        $productIds = array_unique(array_column($parsedRows, 'productId'));
        $vendorIds  = array_unique(array_column($parsedRows, 'vendorId'));
        $clientIds  = array_unique(array_column($parsedRows, 'clientId'));
        $dates      = array_unique(array_column($parsedRows, 'renewalDate'));

        $query = DB::table('s_s_l_s')->select(['domain_id', 'product_id', 'vendor_id', 'renewal_date', 'client_id']);
        
        // Since domainId might be 0 (meaning no domain), we should fetch conservatively
        if (!empty($domainIds)) $query->whereIn('domain_id', $domainIds);
        if (!empty($productIds)) $query->whereIn('product_id', $productIds);
        if (!empty($vendorIds)) $query->whereIn('vendor_id', $vendorIds);
        if (!empty($clientIds)) $query->whereIn('client_id', $clientIds);
        if (!empty($dates)) $query->whereIn('renewal_date', $dates);

        $query->get()->each(function ($s) use (&$set) {
            $d = $this->domainNamesMap[(int)$s->domain_id]   ?? (string)$s->domain_id;
            $p = $this->productNamesMap[(int)$s->product_id] ?? (string)$s->product_id;
            $v = $this->vendorNamesMap[(int)$s->vendor_id]   ?? (string)$s->vendor_id;
            $c = $this->clientNamesMap[(int)$s->client_id]   ?? (string)$s->client_id;
            $set["{$d}_{$p}_{$v}_{$s->renewal_date}_{$c}"] = true;
        });

        return $set;
    }

    // ── Row parsing ────────────────────────────────────────────────────────────

    protected function parseRow(Collection $row, int $rowNumber): ?array
    {
        $domainName  = $this->clean($row[0] ?? '');
        $clientName  = $this->clean($row[1] ?? '');
        $productName = $this->clean($row[2] ?? '');
        $vendorName  = $this->clean($row[3] ?? '');

        if ($domainName === '' || $clientName === '' || $productName === '' || $vendorName === '') {
            return []; // Skip silently if critical fields are missing
        }

        $domainId  = $domainName !== 'N/A'
            ? $this->smartResolve('domains', $domainName, $this->domainCache)
            : 0;
        $clientId  = $this->forcedClientId
                        ?? $this->smartResolve('superadmins', $clientName, $this->clientCache);
        $productId = $this->smartResolve('products', $productName, $this->productCache);
        $vendorId  = $this->smartResolve('vendors', $vendorName, $this->vendorCache, true);

        if (!$clientId) {
            $this->errors[] = ['row' => $rowNumber, 'reason' => 'Could not resolve client'];
            return null;
        }

        return [
            'domainId'     => $domainId,
            'clientId'     => $clientId,
            'productId'    => $productId,
            'vendorId'     => $vendorId,
            'amount'       => $this->normalizeAmount($row[4] ?? 0),
            'renewalDate'  => $this->parseDate($row[5] ?? null) ?? now()->format('Y-m-d'),
            'deletionDate' => $this->parseDate($row[6] ?? null),
            'status'       => isset($row[7]) && (strtolower(trim((string)$row[7])) === 'inactive' || (string)$row[7] === '0') ? 0 : 1,
            'remarks'      => !empty($row[8]) ? trim((string)$row[8]) : null,
        ];
    }

    protected function rawRowToArray(Collection $row): array
    {
        return [
            $this->clean($row[0] ?? ''), // Domain
            $this->clean($row[1] ?? ''), // Client
            $this->clean($row[2] ?? ''), // Product
            $this->clean($row[3] ?? ''), // Vendor
            (string)($row[4] ?? ''),     // Amount
            (string)($row[5] ?? ''),     // Renewal Date
            (string)($row[6] ?? ''),     // Deletion Date
            (string)($row[7] ?? ''),     // Status
            (string)($row[8] ?? ''),     // Remarks
        ];
    }

    // ── Insert builder ─────────────────────────────────────────────────────────

    protected function buildInsertRow(array $p, string $nowTimestamp): array
    {
        return [
            'domain_id'      => $p['domainId'] ?: null,
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
    }
}
