<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\CryptService;
use App\Services\DateFormatterService;
use App\Services\ClientScopeService;

class DashboardAlertsController extends Controller
{
    /**
     * Fetch upcoming expiries across all modules dynamically.
     * sno. Action Product Domain Client Vendor Renewal Date Deletion date Grace Period 
     */
    public function getUpcomingExpiries(Request $request)
    {
        try {
            $days = (int) $request->input('days', 7);
            $today = Carbon::now()->startOfDay();
            $targetDate = $today->copy()->addDays($days)->endOfDay();

            $todayStr = $today->format('Y-m-d');
            $targetStr = $targetDate->format('Y-m-d');

            // Define modules and their table structures
            // We need: Action, Product, Domain, Client, Vendor, Renewal Date, Deletion Date, Grace Period
            $modules = [
                [
                    'name' => 'Subscription',
                    'table' => 'subscriptions',
                    'has_domain' => true,
                    'has_currency' => true,
                ],
                [
                    'name' => 'SSL',
                    'table' => 's_s_l_s',
                    'has_domain' => true,
                    'has_currency' => false,
                ],
                [
                    'name' => 'Domain',
                    'table' => 'domains',
                    'has_domain' => true,
                    'has_currency' => false,
                ],
                [
                    'name' => 'Hosting',
                    'table' => 'hostings',
                    'has_domain' => true,
                    'has_currency' => false,
                ],
                [
                    'name' => 'Email',
                    'table' => 'emails',
                    'has_domain' => true,
                    'has_currency' => false,
                ],
                [
                    'name' => 'Counter',
                    'table' => 'counters',
                    'has_domain' => true,
                    'has_currency' => false,
                ],
            ];

            $allAlerts = collect();

            foreach ($modules as $mod) {
                try {
                    $query = DB::table($mod['table']);
                    
                    $query->select([
                        DB::raw("'{$mod['name']}' as module_type"),
                        "{$mod['table']}.id as record_id",
                        "{$mod['table']}.renewal_date",
                        "{$mod['table']}.deletion_date",
                        "{$mod['table']}.due_date",
                        "{$mod['table']}.grace_period",
                        "{$mod['table']}.amount",
                        DB::raw($mod['has_currency'] ? "{$mod['table']}.currency" : "'₹' as currency"),
                        "{$mod['table']}.status",
                        'products.name as product_name',
                        'superadmins.name as client_name',
                        'vendors.name as vendor_name'
                    ]);

                    $query->leftJoin('products', "{$mod['table']}.product_id", '=', 'products.id')
                          ->leftJoin('superadmins', "{$mod['table']}.client_id", '=', 'superadmins.id')
                          ->leftJoin('vendors', "{$mod['table']}.vendor_id", '=', 'vendors.id');

                    if ($mod['has_domain']) {
                        $query->addSelect('domain_master.domain_name as domain_name');
                        $query->leftJoin('domain_master', "{$mod['table']}.domain_master_id", '=', 'domain_master.id');
                    } else {
                        $query->addSelect(DB::raw("NULL as domain_name"));
                    }

                    // Apply client scope
                    ClientScopeService::applyScope($query, $request);

                    $results = $query->get();

                    foreach ($results as $row) {
                        $product = $this->decrypt($row->product_name);
                        $domain = $this->decrypt($row->domain_name) ?? 'N/A';
                        $client = $this->decrypt($row->client_name);
                        $vendor = $this->decrypt($row->vendor_name);

                        // Check Deletion Alert (Highest Priority)
                        if ($row->deletion_date && $row->deletion_date >= $todayStr && $row->deletion_date <= $targetStr) {
                            $alert = $this->formatAlert($row, 'Deletion', $product, $domain, $client, $vendor, $today);
                            if ($alert) $allAlerts->push($alert);
                        }
                        // Check Grace Period (Due Date) Alert
                        else if (isset($row->due_date) && $row->due_date && $row->due_date >= $todayStr && $row->due_date <= $targetStr) {
                            $alert = $this->formatAlert($row, 'Grace Period', $product, $domain, $client, $vendor, $today);
                            if ($alert) $allAlerts->push($alert);
                        }
                        // Check Renewal Alert
                        else if ($row->renewal_date && $row->renewal_date >= $todayStr && $row->renewal_date <= $targetStr) {
                            $alert = $this->formatAlert($row, 'Renewal', $product, $domain, $client, $vendor, $today);
                            if ($alert) $allAlerts->push($alert);
                        }
                    }
                } catch (\Exception $modEx) {
                    \Log::error("Dashboard Alerts Module Error ({$mod['name']}): " . $modEx->getMessage());
                }
            }

            // Tools (special case)
            $tools = DB::table('tools')
                ->select([
                    DB::raw("'Tool' as module_type"),
                    'id as record_id',
                    'expiry_date as renewal_date',
                    'name as product_name',
                    'client_name',
                    'amount',
                    'status'
                ])
                ->whereBetween('expiry_date', [$todayStr, $targetStr])
                ->get();

            foreach ($tools as $row) {
                $allAlerts->push($this->formatAlert($row, 'Expiry', $row->product_name, 'N/A', $row->client_name, 'N/A', $today));
            }

            // Sort by days_left
            $sorted = $allAlerts->sortBy('days_left')->values();

            return response()->json([
                'success' => true,
                'alerts' => $sorted,
                'total' => $sorted->count()
            ]);

        } catch (\Exception $e) {
            \Log::error("Dashboard Alerts Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error: ' . $e->getMessage()
            ], 500);
        }
    }

    private function decrypt($val)
    {
        if (!$val) return $val;
        try {
            return CryptService::decryptData($val) ?? $val;
        } catch (\Exception $e) {
            return $val;
        }
    }

    private function formatAlert($row, $type, $product, $domain, $client, $vendor, $today)
    {
        $date = ($type === 'Renewal' || $type === 'Expiry') ? $row->renewal_date : ($type === 'Grace Period' ? ($row->due_date ?? $row->renewal_date) : $row->deletion_date);
        
        if (!$date || str_starts_with($date, '1900') || str_starts_with($date, '0000')) {
            return null;
        }

        $dateObj = Carbon::parse($date);
        $diff = $today->diffInDays($dateObj, false);

        $daysToDelete = null;
        if (!empty($row->deletion_date) && !str_starts_with($row->deletion_date, '1900')) {
            $delDate = Carbon::parse($row->deletion_date);
            $daysToDelete = $today->diffInDays($delDate, false);
        }

        return [
            'id' => $row->module_type . '_' . $row->record_id . '_' . strtolower($type),
            'action' => $type,
            'module' => $row->module_type,
            'product' => $product ?? 'N/A',
            'domain' => $domain ?? 'N/A',
            'client' => $client ?? 'N/A',
            'vendor' => $vendor ?? 'N/A',
            'amount' => $row->amount ?? 0,
            'currency' => $row->currency ?? '₹',
            'status' => ($row->status ?? 1) == 1 ? 'Active' : 'Inactive',
            'renewal_date' => $row->renewal_date && !str_starts_with($row->renewal_date, '1900') ? Carbon::parse($row->renewal_date)->format('Y-m-d') : null,
            'deletion_date' => $row->deletion_date && !str_starts_with($row->deletion_date, '1900') ? Carbon::parse($row->deletion_date)->format('Y-m-d') : null,
            'due_date' => (isset($row->due_date) && $row->due_date && !str_starts_with($row->due_date, '1900')) ? Carbon::parse($row->due_date)->format('Y-m-d') : null,
            'grace_period' => $row->grace_period ?? 0,
            'days_left' => $diff,
            'days_to_delete' => $daysToDelete,
            'urgency' => $diff <= 3 ? 'critical' : ($diff <= 10 ? 'high' : 'normal')
        ];
    }

    public function getRecentActivity(Request $request)
    {
        try {
            $days = (int) $request->input('days', 30);
            $limit = (int) $request->input('limit', 50);
            $filterModule = $request->input('module'); // ALL, Subscription, SSL, Domain, Hosting, Email, Counter

            $modules = [
                ['name' => 'Subscription', 'table' => 'subscriptions', 'has_domain' => true, 'has_currency' => true],
                ['name' => 'SSL', 'table' => 's_s_l_s', 'has_domain' => true, 'has_currency' => false],
                ['name' => 'Domain', 'table' => 'domains', 'has_domain' => true, 'has_currency' => false],
                ['name' => 'Hosting', 'table' => 'hostings', 'has_domain' => true, 'has_currency' => false],
                ['name' => 'Email', 'table' => 'emails', 'has_domain' => true, 'has_currency' => false],
                ['name' => 'Counter', 'table' => 'counters', 'has_domain' => true, 'has_currency' => false],
            ];

            $allActivities = collect();

            foreach ($modules as $mod) {
                if ($filterModule && $filterModule !== 'ALL' && strtolower($filterModule) !== strtolower($mod['name'])) {
                    continue;
                }

                try {
                    $query = DB::table($mod['table']);
                    $query->select([
                        DB::raw("'{$mod['name']}' as module_type"),
                        "{$mod['table']}.id as record_id",
                        "{$mod['table']}.amount",
                        DB::raw($mod['has_currency'] ? "{$mod['table']}.currency" : "'₹' as currency"),
                        "{$mod['table']}.status",
                        "{$mod['table']}.created_at",
                        "{$mod['table']}.renewal_date",
                        'products.name as product_name',
                        'superadmins.name as client_name',
                        'vendors.name as vendor_name'
                    ]);

                    $query->leftJoin('products', "{$mod['table']}.product_id", '=', 'products.id')
                          ->leftJoin('superadmins', "{$mod['table']}.client_id", '=', 'superadmins.id')
                          ->leftJoin('vendors', "{$mod['table']}.vendor_id", '=', 'vendors.id');

                    if ($mod['has_domain']) {
                        $query->addSelect('domain_master.domain_name as domain_name');
                        $query->leftJoin('domain_master', "{$mod['table']}.domain_master_id", '=', 'domain_master.id');
                    } else {
                        $query->addSelect(DB::raw("NULL as domain_name"));
                    }

                    ClientScopeService::applyScope($query, $request);

                    $results = $query->orderBy("{$mod['table']}.created_at", 'desc')
                                    ->limit($limit)
                                    ->get();

                    foreach ($results as $row) {
                        $allActivities->push([
                            'id' => $row->module_type . '_' . $row->record_id,
                            'action' => 'CREATED',
                            'module' => $row->module_type,
                            'product' => $this->decrypt($row->product_name) ?? 'N/A',
                            'domain' => $this->decrypt($row->domain_name) ?? 'N/A',
                            'client' => $this->decrypt($row->client_name) ?? 'N/A',
                            'vendor' => $this->decrypt($row->vendor_name) ?? 'N/A',
                            'amount' => $row->amount ?? 0,
                            'currency' => $row->currency ?? '₹',
                            'status' => ($row->status ?? 1) == 1 ? 'Active' : 'Inactive',
                            'created_at' => $row->created_at,
                            'renewal_date' => $row->renewal_date && !str_starts_with($row->renewal_date, '1900') ? Carbon::parse($row->renewal_date)->format('Y-m-d') : null,
                        ]);
                    }
                } catch (\Exception $modEx) {
                    \Log::error("Dashboard Recent Activity Module Error ({$mod['name']}): " . $modEx->getMessage());
                }
            }

            $sorted = $allActivities->sortByDesc('created_at')->values()->take($limit);

            return response()->json([
                'success' => true,
                'activities' => $sorted,
                'total' => $sorted->count()
            ]);

        } catch (\Exception $e) {
            \Log::error("Dashboard Recent Activity Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error: ' . $e->getMessage()
            ], 500);
        }
    }
}
