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
class SubscriptionImport extends SmartImporter implements \Maatwebsite\Excel\Concerns\WithHeadingRow
{
    protected function tableName(): string   { return 'subscriptions'; }
    protected function clientTable(): string { return 'superadmins'; }

    protected function columnHeaders(): array
    {
        return [
            'domain', 'client_id', 'product_id', 'vendor_id', 'amount', 'currency', 
            'renewal_date', 'deletion_date', 'days_to_delete', 'grace_period', 
            'due_date', 'status', 'remarks'
        ];
    }

    // ── Duplicate detection ────────────────────────────────────────────────────

    protected function duplicateKey(array $p): string
    {
        $product = $this->productNamesMap[(int)$p['productId']] ?? (string)$p['productId'];
        $vendor  = $this->vendorNamesMap[(int)$p['vendorId']]   ?? (string)$p['vendorId'];
        $client  = $this->clientNamesMap[(int)$p['clientId']]   ?? (string)$p['clientId'];
        $domain  = $this->domainMasterNamesMap[(int)$p['domainMasterId']] ?? (string)$p['domainMasterId'];
        
        return "{$domain}_{$product}_{$vendor}_{$p['renewalDate']}_{$client}";
    }

    protected function existingDupKeys(array $parsedRows): array
    {
        $set = [];
        if (empty($parsedRows)) return $set;

        $productIds = array_unique(array_column($parsedRows, 'productId'));
        $vendorIds  = array_unique(array_column($parsedRows, 'vendorId'));
        $clientIds  = array_unique(array_column($parsedRows, 'clientId'));
        $dates      = array_unique(array_column($parsedRows, 'renewalDate'));
        $dmIds      = array_unique(array_column($parsedRows, 'domainMasterId'));

        DB::table('subscriptions')
            ->select(['product_id', 'vendor_id', 'renewal_date', 'client_id', 'domain_master_id'])
            ->whereIn('product_id', $productIds)
            ->whereIn('vendor_id', $vendorIds)
            ->whereIn('client_id', $clientIds)
            ->whereIn('renewal_date', $dates)
            ->whereIn('domain_master_id', $dmIds)
            ->get()
            ->each(function ($s) use (&$set) {
                $p = $this->productNamesMap[(int)$s->product_id] ?? (string)$s->product_id;
                $v = $this->vendorNamesMap[(int)$s->vendor_id]   ?? (string)$s->vendor_id;
                $c = $this->clientNamesMap[(int)$s->client_id]   ?? (string)$s->client_id;
                $d = $this->domainMasterNamesMap[(int)$s->domain_master_id] ?? (string)$s->domain_master_id;
                
                $set["{$d}_{$p}_{$v}_{$s->renewal_date}_{$c}"] = true;
            });
        return $set;
    }

    // ── Row parsing ────────────────────────────────────────────────────────────

    protected function parseRow(\Illuminate\Support\Collection $row, int $rowNumber): ?array
    {
        $domainName  = $this->clean($row['domain'] ?? '');
        $clientName  = $this->clean($row['client_id'] ?? '');
        $productName = $this->clean($row['product_id'] ?? '');
        $vendorName  = $this->clean($row['vendor_id'] ?? '');

        if ($domainName === '' || $clientName === '' || $productName === '' || $vendorName === '') {
            return []; // Ignore silently if critical fields missing
        }

        $domainMasterId = $this->smartResolve('domain_master', $domainName, $this->domainMasterCache);
        $clientId  = $this->forcedClientId
                        ?? $this->smartResolve('superadmins', $clientName, $this->clientCache);
        $productId = $this->smartResolve('products', $productName, $this->productCache);
        $vendorId  = $this->smartResolve('vendors', $vendorName, $this->vendorCache, true);

        if (!$clientId) {
            $this->errors[] = ['row' => $rowNumber, 'reason' => "Could not resolve client: $clientName"];
            return null;
        }

        $renewalDate  = $this->parseDate($row['renewal_date'] ?? null) ?? now()->format('Y-m-d');
        $gracePeriod  = (int)($row['grace_period'] ?? 0);
        
        // Calculate due date based on grace period logic
        $graceRes = \App\Services\GracePeriodService::calculate($renewalDate, $gracePeriod);
        $dueDate  = $this->parseDate($row['due_date'] ?? null) ?? $graceRes['due_date'];
        
        // Determine status: 1 = Active (default), 0 = Inactive
        $statusRaw = strtolower(trim((string)($row['status'] ?? '')));
        $status = 1; // Default to active
        if ($statusRaw !== '') {
            if ($statusRaw === 'inactive' || $statusRaw === '0') {
                $status = 0;
            }
        }
        
        // Final guard for status based on due date
        if ($graceRes['should_be_inactive']) {
            $status = 0;
        }

        return [
            'domainMasterId' => $domainMasterId,
            'productId'    => $productId,
            'clientId'     => $clientId,
            'vendorId'     => $vendorId,
            'amount'       => $this->normalizeAmount($row['amount'] ?? 0),
            'currency'     => trim((string)($row['currency'] ?? 'INR')) ?: 'INR',
            'renewalDate'  => $renewalDate,
            'deletionDate' => $this->parseDate($row['deletion_date'] ?? null),
            'gracePeriod'  => $gracePeriod,
            'dueDate'      => $dueDate,
            'status'       => $status,
            'remarks'      => !empty($row['remarks']) ? \App\Services\CryptService::encryptData($row['remarks']) : null,
            // Labels for duplicate export
            '_domainName'  => $domainName,
            '_clientName'  => $clientName,
            '_productName' => $productName,
            '_vendorName'  => $vendorName,
        ];
    }

    protected function rawRowToArray(\Illuminate\Support\Collection $row): array
    {
        return [
            $this->clean($row['domain'] ?? ''),
            $this->clean($row['client_id'] ?? ''),
            $this->clean($row['product_id'] ?? ''),
            $this->clean($row['vendor_id'] ?? ''),
            $this->clean($row['amount'] ?? ''),
            $this->clean($row['currency'] ?? ''),
            $this->clean($row['renewal_date'] ?? ''),
            $this->clean($row['deletion_date'] ?? ''),
            $this->clean($row['days_to_delete'] ?? ''),
            $this->clean($row['grace_period'] ?? ''),
            $this->clean($row['due_date'] ?? ''),
            $this->clean($row['status'] ?? ''),
            $this->clean($row['remarks'] ?? ''),
        ];
    }

    // ── Insert builder ─────────────────────────────────────────────────────────

    protected function buildInsertRow(array $p, string $nowTimestamp): array
    {
        return [
            'domain_master_id' => $p['domainMasterId'],
            'product_id'     => $p['productId'],
            'client_id'      => $p['clientId'],
            'vendor_id'      => $p['vendorId'],
            'amount'         => $p['amount'],
            'currency'       => $p['currency'],
            'renewal_date'   => $p['renewalDate'],
            'deletion_date'  => $p['deletionDate'],
            'days_left'      => $this->calcDaysLeft($p['renewalDate']),
            'days_to_delete' => $this->calcDaysLeft($p['deletionDate']),
            'grace_period'   => $p['gracePeriod'],
            'due_date'       => $p['dueDate'],
            'status'         => $p['status'],
            'remarks'        => $p['remarks'],
            'created_at'     => $nowTimestamp,
            'updated_at'     => $nowTimestamp,
        ];
    }
}
