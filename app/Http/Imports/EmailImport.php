<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EmailImport extends SmartImporter implements \Maatwebsite\Excel\Concerns\WithHeadingRow
{
    protected function tableName(): string   { return 'emails'; }
    protected function clientTable(): string { return 'superadmins'; }

    protected function columnHeaders(): array
    {
        return [
            'domain', 'product_id', 'client_id', 'vendor_id', 
            'quantity', 'bill_type', 'amount', 'renewal_date', 'grace_period', 'remarks'
        ];
    }

    protected function duplicateKey(array $p): string
    {
        $domain  = $this->domainMasterNamesMap[(int)$p['domainMasterId']] ?? (string)$p['domainMasterId'];
        $product = $this->productNamesMap[(int)$p['productId']] ?? (string)$p['productId'];
        $vendor  = $this->vendorNamesMap[(int)$p['vendorId']]   ?? (string)$p['vendorId'];
        $client  = $this->clientNamesMap[(int)$p['clientId']]   ?? (string)$p['clientId'];
        
        return "{$domain}_{$product}_{$vendor}_{$p['renewalDate']}_{$client}";
    }

    protected function existingDupKeys(array $parsedRows): array
    {
        $set = [];
        if (empty($parsedRows)) return $set;

        $dmIds      = array_unique(array_column($parsedRows, 'domainMasterId'));
        $productIds = array_unique(array_column($parsedRows, 'productId'));
        $vendorIds  = array_unique(array_column($parsedRows, 'vendorId'));
        $clientIds  = array_unique(array_column($parsedRows, 'clientId'));
        $dates      = array_unique(array_column($parsedRows, 'renewalDate'));

        DB::table('emails')
            ->select(['domain_master_id', 'product_id', 'vendor_id', 'renewal_date', 'client_id'])
            ->whereIn('domain_master_id', $dmIds)
            ->whereIn('product_id', $productIds)
            ->whereIn('vendor_id', $vendorIds)
            ->whereIn('client_id', $clientIds)
            ->whereIn('renewal_date', $dates)
            ->get()
            ->each(function ($s) use (&$set) {
                $d = $this->domainMasterNamesMap[(int)$s->domain_master_id] ?? (string)$s->domain_master_id;
                $p = $this->productNamesMap[(int)$s->product_id] ?? (string)$s->product_id;
                $v = $this->vendorNamesMap[(int)$s->vendor_id]   ?? (string)$s->vendor_id;
                $c = $this->clientNamesMap[(int)$s->client_id]   ?? (string)$s->client_id;
                
                $set["{$d}_{$p}_{$v}_{$s->renewal_date}_{$c}"] = true;
            });
        return $set;
    }

    protected function parseRow(Collection $row, int $rowNumber): ?array
    {
        $domainName  = $this->clean($row->get('domain') ?? '');
        $clientName  = $this->clean($row->get('client_id') ?? '');
        $productName = $this->clean($row->get('product_id') ?? '');
        $vendorName  = $this->clean($row->get('vendor_id') ?? '');

        if ($domainName === '' || $clientName === '' || $productName === '' || $vendorName === '') {
            return [];
        }

        $domainMasterId = $this->smartResolve('domain_master', $domainName, $this->domainMasterCache);
        $clientId  = $this->forcedClientId ?? $this->smartResolve('superadmins', $clientName, $this->clientCache);
        $productId = $this->smartResolve('products', $productName, $this->productCache);
        $vendorId  = $this->smartResolve('vendors', $vendorName, $this->vendorCache, true);

        if (!$clientId) {
            $this->errors[] = ['row' => $rowNumber, 'reason' => "Could not resolve client: $clientName"];
            return null;
        }

        $renewalDate = $this->parseDate($row->get('renewal_date') ?? null) ?? now()->format('Y-m-d');
        $gracePeriod = (int)($row->get('grace_period') ?? 0);
        $deletionDate = \Illuminate\Support\Carbon::parse($renewalDate)->addDay()->format('Y-m-d');
        
        $graceRes = \App\Services\GracePeriodService::calculate($renewalDate, $gracePeriod);
        $status = $graceRes['should_be_inactive'] ? 0 : 1;

        return [
            'domainMasterId' => $domainMasterId,
            'productId' => $productId,
            'clientId' => $clientId,
            'vendorId' => $vendorId,
            'quantity' => (int)($row->get('quantity') ?? 1),
            'bill_type' => $row->get('bill_type'),
            'amount' => (float)($row->get('amount') ?? 0),
            'renewalDate' => $renewalDate,
            'deletionDate' => $deletionDate,
            'gracePeriod' => $gracePeriod,
            'dueDate' => $graceRes['due_date'],
            'status' => $status,
            'remarks' => $row->get('remarks') ? \App\Services\CryptService::encryptData($row->get('remarks')) : null,
        ];
    }

    protected function rawRowToArray(Collection $row): array
    {
        return [
            $this->clean($row->get('domain') ?? ''),
            $this->clean($row->get('client_id') ?? ''),
            $this->clean($row->get('product_id') ?? ''),
            $this->clean($row->get('vendor_id') ?? ''),
            (string)($row->get('quantity') ?? ''),
            (string)($row->get('bill_type') ?? ''),
            (string)($row->get('amount') ?? ''),
            (string)($row->get('renewal_date') ?? ''),
            (string)($row->get('grace_period') ?? ''),
            (string)($row->get('remarks') ?? ''),
        ];
    }

    protected function buildInsertRow(array $p, string $nowTimestamp): array
    {
        return [
            'domain_master_id' => $p['domainMasterId'],
            'product_id' => $p['productId'],
            'client_id' => $p['clientId'],
            'vendor_id' => $p['vendorId'],
            'quantity' => $p['quantity'],
            'bill_type' => $p['bill_type'],
            'amount' => $p['amount'],
            'renewal_date' => $p['renewalDate'],
            'deletion_date' => $p['deletionDate'],
            'days_left' => $this->calcDaysLeft($p['renewalDate']),
            'days_to_delete' => $this->calcDaysLeft($p['deletionDate']),
            'grace_period' => $p['gracePeriod'],
            'due_date' => $p['dueDate'],
            'status' => $p['status'],
            'remarks' => $p['remarks'],
            'created_at' => $nowTimestamp,
            'updated_at' => $nowTimestamp,
        ];
    }
}
