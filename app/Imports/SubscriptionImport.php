<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SubscriptionImport
 *
 * Excel columns: Product | Client | Vendor | Renewal Date | Amount | Deletion Date | (Days Left) | Status | Remarks
 * Duplicate key: product_id + vendor_id + renewal_date + client_id
 * Client table: superadmins
 */
class SubscriptionImport extends SmartImporter
{
    protected function tableName(): string   { return 'subscriptions'; }
    protected function clientTable(): string { return 'superadmins'; }

    protected function columnHeaders(): array
    {
        return ['Product', 'Client', 'Vendor', 'Renewal Date', 'Amount', 'Deletion Date', 'Days Left', 'Status', 'Remarks'];
    }

    // ── Duplicate detection ────────────────────────────────────────────────────

    protected function duplicateKey(array $p): string
    {
        $product = $this->productNamesMap[(int)$p['productId']] ?? (string)$p['productId'];
        $vendor  = $this->vendorNamesMap[(int)$p['vendorId']]   ?? (string)$p['vendorId'];
        $client  = $this->clientNamesMap[(int)$p['clientId']]   ?? (string)$p['clientId'];
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

        DB::table('subscriptions')
            ->select(['product_id', 'vendor_id', 'renewal_date', 'client_id'])
            ->whereIn('product_id', $productIds)
            ->whereIn('vendor_id', $vendorIds)
            ->whereIn('client_id', $clientIds)
            ->whereIn('renewal_date', $dates)
            ->get()
            ->each(function ($s) use (&$set) {
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
        $productName = $this->clean($row[0] ?? '');
        $clientName  = $this->clean($row[1] ?? '');
        $vendorName  = $this->clean($row[2] ?? '');

        if ($productName === '' || $clientName === '' || $vendorName === '') {
            return []; // Ignore silently missing critical fields
        }

        $productId = $this->smartResolve('products', $productName, $this->productCache);
        $clientId  = $this->forcedClientId
                        ?? $this->smartResolve('superadmins', $clientName, $this->clientCache);
        $vendorId  = $this->smartResolve('vendors', $vendorName, $this->vendorCache, true);

        if (!$clientId) {
            $this->errors[] = ['row' => $rowNumber, 'reason' => 'Could not resolve client'];
            return null;
        }

        return [
            'productId'    => $productId,
            'clientId'     => $clientId,
            'vendorId'     => $vendorId,
            'renewalDate'  => $this->parseDate($row[3] ?? null) ?? now()->format('Y-m-d'),
            'amount'       => $this->normalizeAmount($row[4] ?? 0),
            'deletionDate' => $this->parseDate($row[5] ?? null),
            'status'       => isset($row[7]) && (strtolower(trim((string)$row[7])) === 'inactive' || (string)$row[7] === '0') ? 0 : 1,
            'remarks'      => !empty($row[8]) ? trim((string)$row[8]) : null,
            // Keep raw labels for duplicate export
            '_productName' => $productName,
            '_clientName'  => $clientName,
            '_vendorName'  => $vendorName,
        ];
    }

    protected function rawRowToArray(Collection $row): array
    {
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
        return [
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
