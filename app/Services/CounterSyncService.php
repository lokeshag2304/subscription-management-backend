<?php

namespace App\Services;

use App\Models\Counter;
use App\Models\Subscription;
use App\Models\Superadmin;
use App\Models\Product;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class CounterSyncService
{
    /**
     * Calculate count correctly based on logical records.
     */
    public static function calculate(?int $clientId, ?int $productId, ?int $vendorId): int
    {
        if (!$clientId || !$productId || !$vendorId) return 0;

        $matching = self::getMatchingIds($clientId, $productId, $vendorId);

        return Subscription::whereIn('client_id', $matching['clients'])
            ->whereIn('product_id', $matching['products'])
            ->whereIn('vendor_id', $matching['vendors'])
            ->count();
    }

    /**
     * Re-calculate and update counter for a specific combination.
     */
    public static function sync(?int $clientId, ?int $productId, ?int $vendorId): void
    {
        if (!$clientId || !$productId || !$vendorId) return;

        $matching = self::getMatchingIds($clientId, $productId, $vendorId);
        
        // Calculate total count
        $count = Subscription::whereIn('client_id', $matching['clients'])
            ->whereIn('product_id', $matching['products'])
            ->whereIn('vendor_id', $matching['vendors'])
            ->count();

        // Find existing counter row matching ANY of the logical siblings
        $counter = Counter::whereIn('client_id', $matching['clients'])
            ->whereIn('product_id', $matching['products'])
            ->whereIn('vendor_id', $matching['vendors'])
            ->first();

        if ($counter) {
            $counter->update(['amount' => $count]);
        } else if ($count > 0) {
            // Fetch the latest subscription for this combination to inherit dates/status
            $latestSub = Subscription::whereIn('client_id', $matching['clients'])
                ->whereIn('product_id', $matching['products'])
                ->whereIn('vendor_id', $matching['vendors'])
                ->orderBy('renewal_date', 'desc')
                ->first();

            $today = now()->startOfDay();
            $renewalDate = $latestSub->renewal_date ?? null;
            $deletionDate = $latestSub->deletion_date ?? null;
            
            Counter::create([
                'client_id' => $clientId,
                'product_id' => $productId,
                'vendor_id' => $vendorId,
                'amount' => $count,
                'renewal_date' => $renewalDate,
                'deletion_date' => $deletionDate,
                'days_left' => $renewalDate ? $today->diffInDays(Carbon::parse($renewalDate)->startOfDay(), false) : null,
                'days_to_delete' => $deletionDate ? $today->diffInDays(Carbon::parse($deletionDate)->startOfDay(), false) : null,
                'status' => 1,
                'remarks' => CryptService::encryptData('Automatically created by system synchronization.')
            ]);
        }
    }

    /**
     * Helper to find all IDs belonging to the same logical names.
     */
    private static function getMatchingIds(int $clientId, int $productId, int $vendorId): array
    {
        $clientReq = Superadmin::find($clientId);
        $productReq = Product::find($productId);
        $vendorReq = Vendor::find($vendorId);
        
        $cNameDec = null; $pNameDec = null; $vNameDec = null;
        if ($clientReq) { try { $cNameDec = CryptService::decryptData($clientReq->name) ?? $clientReq->name; } catch (\Exception $e) {} }
        if ($productReq) { try { $pNameDec = CryptService::decryptData($productReq->name) ?? $productReq->name; } catch (\Exception $e) {} }
        if ($vendorReq) { try { $vNameDec = CryptService::decryptData($vendorReq->name) ?? $vendorReq->name; } catch (\Exception $e) {} }

        $find = function ($modelClass, $decName, $defId) {
            if (!$decName) return [$defId];
            return $modelClass::all()->filter(function ($item) use ($decName) {
                try {
                    $dec = CryptService::decryptData($item->name) ?? $item->name;
                    return strtolower(trim($dec)) === strtolower(trim($decName));
                } catch (\Exception $e) {
                    return strtolower(trim($item->name)) === strtolower(trim($decName));
                }
            })->pluck('id')->toArray();
        };

        return [
            'clients' => $find(Superadmin::class, $cNameDec, $clientId),
            'products' => $find(Product::class, $pNameDec, $productId),
            'vendors' => $find(Vendor::class, $vNameDec, $vendorId),
        ];
    }
}
