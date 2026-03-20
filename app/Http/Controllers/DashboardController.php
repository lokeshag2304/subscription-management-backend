<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use App\Lib\SMSinteg;
use App\Lib\Whatsappinteg;
use App\Lib\EmailInteg;
use App\Services\CryptService;
use Illuminate\Support\Facades\Log;
use App\Services\AgentPermission;
use App\Services\ClientScopeService;
use App\Services\DateFormatterService;

use Carbon\Carbon;

class DashboardController extends Controller
{

public function GetDashboardData(Request $request)
{
    try {

        $DD = json_decode($request->getContent(), true) ?? [];

        $s_id = $DD['s_id'] ?? null;
        $jwtLoginType = (int) $request->attributes->get('auth_login_type', 0);

        // 4. Validate request parameters
        if (!$s_id && !$jwtLoginType) {
            return response()->json(['success' => false, 'message' => 'Invalid request parameters'], 400);
        }

        // 5. Add defensive checks
        // If there's no way to identify the user, it is unauthorized
        if (!$s_id && $jwtLoginType === 0) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        // ── JWT-based client detection (preferred) ──
        $jwtLoginType = (int) $request->attributes->get('auth_login_type', 0);
        $jwtUserId    = (int) $request->attributes->get('auth_user_id', 0);

        // If JWT says this is a client, use that. Otherwise fall back to s_id lookup.
        $isClient = false;
        $clientId = null;

        if ($jwtLoginType === 3 && $jwtUserId > 0) {
            $isClient = true;
            $clientId = $jwtUserId;
        }

        $user = null;
        if ($s_id) {
            $user = DB::table('superadmins')->where('id', $s_id)->first();
            // Fallback: if JWT didn't already identify a client, use s_id
            if (!$isClient && $user && $user->login_type == 3) {
                $isClient = true;
                $clientId = $user->id;
            }
        }

        // =========================
        // CLIENT DOMAIN IDS (FIXED)
        // =========================
        $clientDomainIds = [];
        if ($isClient && !empty($user->domain_id)) {
            $clientDomainIds = array_map(
                'intval',
                json_decode($user->domain_id, true) ?? []
            );
        } elseif ($isClient && $clientId) {
            // Fetch domain_id from the superadmins table using the clientId
            $clientUser = $user ?? DB::table('superadmins')->where('id', $clientId)->first();
            if ($clientUser && !empty($clientUser->domain_id)) {
                $clientDomainIds = array_map(
                    'intval',
                    json_decode($clientUser->domain_id, true) ?? []
                );
            }
        }

        $startDate = !empty($DD['start_date'])
            ? Carbon::createFromFormat('Y-m-d', $DD['start_date'])->startOfDay()
            : null;

        $endDate = !empty($DD['end_date'])
            ? Carbon::createFromFormat('Y-m-d', $DD['end_date'])->endOfDay()
            : null;

        // =========================
        // USERS / CLIENTS
        // =========================
        $superadminQuery = DB::table('superadmins');

        if ($startDate && $endDate) {
            $superadminQuery->whereBetween('created_at', [$startDate, $endDate]);
        }

        $totalClients = (clone $superadminQuery)->where('login_type', 3)->count();
        $totalUsers   = (clone $superadminQuery)->where('login_type', 2)->count();

        // =========================
        // DOMAINS
        // =========================
        if ($isClient) {
            $totalDomains = count($clientDomainIds);
        } else {
            $domainQuery = DB::table('domain');
            if ($startDate && $endDate) {
                $domainQuery->whereBetween('created_at', [$startDate, $endDate]);
            }
            $totalDomains = $domainQuery->count();
        }

        // =========================
        // PRODUCTS
        // =========================
        if ($isClient) {
            // Count distinct product IDs used by this client across all modules
            $pIds = DB::table('subscriptions')->where('client_id', $clientId)->pluck('product_id')
                ->merge(DB::table('s_s_l_s')->where('client_id', $clientId)->pluck('product_id'))
                ->merge(DB::table('hostings')->where('client_id', $clientId)->pluck('product_id'))
                ->merge(DB::table('emails')->where('client_id', $clientId)->pluck('product_id'))
                ->merge(DB::table('counters')->where('client_id', $clientId)->pluck('product_id'))
                ->unique();
            $totalProducts = $pIds->count();
        } else {
            $productQuery = DB::table('products');
            if ($startDate && $endDate) {
                $productQuery->whereBetween('created_at', [$startDate, $endDate]);
            }
            $totalProducts = $productQuery->count();
        }

        // =========================
        // 6 DASHBOARD BOX COUNTS — from actual module tables
        // =========================
        // For client login_type=3, filter by their user id as client_id
        $scopedClientId = $isClient ? $clientId : null;
        $applyDateFilter = $startDate && $endDate;

        $tables = [
            ['table' => 'subscriptions', 'name' => 'Subscription'],
            ['table' => 's_s_l_s',         'name' => 'SSL'],
            ['table' => 'hostings',      'name' => 'Hosting'],
            ['table' => 'domain',        'name' => 'Domain'],
            ['table' => 'emails',        'name' => 'Email'],
            ['table' => 'counters',      'name' => 'Counter']
        ];

        // 1. Subscriptions
        $subQ = DB::table('subscriptions');
        if ($scopedClientId !== null) $subQ->where('client_id', $scopedClientId);
        if ($applyDateFilter) $subQ->whereBetween('created_at', [$startDate, $endDate]);
        $subscriptionCount = $subQ->count();

        // 2. SSL
        $sslQ = DB::table('s_s_l_s');
        if ($scopedClientId !== null) $sslQ->where('client_id', $scopedClientId);
        if ($applyDateFilter) $sslQ->whereBetween('created_at', [$startDate, $endDate]);
        $sslCount = $sslQ->count();

        // 3. Hosting
        $hostQ = DB::table('hostings');
        if ($scopedClientId !== null) $hostQ->where('client_id', $scopedClientId);
        if ($applyDateFilter) $hostQ->whereBetween('created_at', [$startDate, $endDate]);
        $hostingCount = $hostQ->count();

        // 4. Domains
        if ($isClient) {
             $domainsCount = count($clientDomainIds);
        } else {
            $domQ = DB::table('domain');
            if ($scopedClientId !== null) $domQ->where('client_id', $scopedClientId);
            if ($applyDateFilter) $domQ->whereBetween('created_at', [$startDate, $endDate]);
            $domainsCount = $domQ->count();
        }

        // 5. Emails
        $emailQ = DB::table('emails');
        if ($scopedClientId !== null) $emailQ->where('client_id', $scopedClientId);
        if ($applyDateFilter) $emailQ->whereBetween('created_at', [$startDate, $endDate]);
        $emailsCount = $emailQ->count();

        // 6. Counter
        $counterQ = DB::table('counters');
        if ($scopedClientId !== null) $counterQ->where('client_id', $scopedClientId);
        if ($applyDateFilter) $counterQ->whereBetween('created_at', [$startDate, $endDate]);
        $counterCount = $counterQ->count();

        $typeCounts = [
            ['type_id' => 1, 'type_name' => 'Subscriptions', 'count' => $subscriptionCount],
            ['type_id' => 2, 'type_name' => 'SSL',           'count' => $sslCount],
            ['type_id' => 3, 'type_name' => 'Hosting',       'count' => $hostingCount],
            ['type_id' => 4, 'type_name' => 'Domains',       'count' => $domainsCount],
            ['type_id' => 7, 'type_name' => 'Products',      'count' => $totalProducts],
            ['type_id' => 5, 'type_name' => 'Emails',        'count' => $emailsCount],
            ['type_id' => 6, 'type_name' => 'Counter',       'count' => $counterCount],
        ];



        // =========================
        // RECENT CATEGORIES / SEARCH
        // =========================
        $page        = (int) ($DD['page'] ?? 0);
        $rowsPerPage = (int) ($DD['rowsPerPage'] ?? 5);
        $search      = $DD['search'] ?? null;
        $searchStr   = $search ? strtolower($search) : null;
        $offset      = $page * $rowsPerPage;

        // Strictly enforce backend sorting (newest first)
        $orderBy     = 'created_at';
        $orderDir    = 'desc';

        $today = Carbon::today();
        $recentCategoriesAll = [];

        // Helper to parse dates safely even if already formatted
        $parseSafe = function($val) {
            if (!$val) return now();
            if ($val instanceof \Carbon\Carbon) return $val;
            try {
                // If it contains a comma or slash (like j/n/Y, g:i:s a), it's likely already formatted
                if (strpos($val, ',') !== false || strpos($val, '/') !== false) {
                     return Carbon::createFromFormat('j/n/Y, g:i:s a', $val);
                }
                return Carbon::parse($val);
            } catch (\Exception $e) {
                return now();
            }
        };

        // Fetch names for bulk mapping to avoid N+1
        $allProductIds = [];
        $allVendorIds = [];
        $allClientIds = [];
        $allDomainIds = [];

        // First pass: collect IDs from recent records (limit fetch per table)
        $tableData = [];
            foreach ($tables as $t) {
                $q = DB::table($t['table']);
                if ($isClient) {
                    $q->where('client_id', $clientId);
                }
                
                // If searching, we pull a larger batch to allow local filtering on front-end
                // if search is empty, we still pull a decent amount for the "recent" view.
                $fetchLimit = 1000; 
                $records = $q->latest()->limit($fetchLimit)->get();
                $tableData[$t['table']] = $records;

            foreach ($records as $row) {
                if (isset($row->product_id)) $allProductIds[] = $row->product_id;
                if (isset($row->vendor_id))  $allVendorIds[]  = $row->vendor_id;
                if (isset($row->client_id))  $allClientIds[]  = $row->client_id;
                if (isset($row->domain_id))  $allDomainIds[]  = $row->domain_id;
                if ($t['table'] === 'domain') $allDomainIds[] = $row->id;
            }
        }

        // Bulk fetch and decrypt related names
        $productMap = [];
        if (!empty($allProductIds)) {
            DB::table('products')->whereIn('id', array_unique($allProductIds))->get(['id','name'])->each(function($p) use (&$productMap) {
                try { $productMap[$p->id] = CryptService::decryptData($p->name) ?? $p->name; } catch(\Exception $e) { $productMap[$p->id] = $p->name; }
            });
        }
        $vendorMap = [];
        if (!empty($allVendorIds)) {
            DB::table('vendors')->whereIn('id', array_unique($allVendorIds))->get(['id','name'])->each(function($v) use (&$vendorMap) {
                try { $vendorMap[$v->id] = CryptService::decryptData($v->name) ?? $v->name; } catch(\Exception $e) { $vendorMap[$v->id] = $v->name; }
            });
        }
        $clientMap = [];
        if (!empty($allClientIds)) {
            DB::table('superadmins')->whereIn('id', array_unique($allClientIds))->get(['id','name'])->each(function($c) use (&$clientMap) {
                try { $clientMap[$c->id] = CryptService::decryptData($c->name) ?? $c->name; } catch(\Exception $e) { $clientMap[$c->id] = $c->name; }
            });
        }
        $domainMap = [];
        if (!empty($allDomainIds)) {
            DB::table('domain')->whereIn('id', array_unique($allDomainIds))->get(['id','name'])->each(function($d) use (&$domainMap) {
                try { $domainMap[$d->id] = CryptService::decryptData($d->name) ?? $d->name; } catch(\Exception $e) { $domainMap[$d->id] = $d->name; }
            });
        }

        foreach ($tables as $t) {
            $records = $tableData[$t['table']];
            foreach ($records as $row) {
                $domainName  = $domainMap[$row->domain_id ?? ($t['table'] === 'domain' ? $row->id : 0)] ?? ($row->domain_name ?? 'N/A');
                $productName = $productMap[$row->product_id ?? 0] ?? 'N/A';
                $vendorName  = $vendorMap[$row->vendor_id ?? 0]   ?? 'N/A';
                $clientName  = $clientMap[$row->client_id ?? 0]   ?? 'N/A';
                $amount      = $row->amount ?? 0;
                $remarks     = '';
                if (isset($row->remarks)) {
                    try { $remarks = CryptService::decryptData($row->remarks) ?? $row->remarks; } catch(\Exception $e) { $remarks = $row->remarks; }
                }

                $expiryDate = $row->renewal_date ?? ($row->expiry_date ?? null);
                $daysToExpired = null;
                if ($expiryDate) {
                    try {
                        $expiry = Carbon::parse($expiryDate);
                        $daysToExpired = $today->diffInDays($expiry, false);
                    } catch (\Exception $e) {}
                }
                $statusText = ($row->status ?? 1) == 1 ? 'active' : 'inactive';

                if ($searchStr) {
                    $searchable = strtolower(implode(' ', [$domainName, $productName, $vendorName, $clientName, $amount, $remarks, $t['name'], $statusText, $daysToExpired, $expiryDate, $row->created_at, $row->id]));
                    if (strpos($searchable, $searchStr) === false) continue;
                }

                $cAt = $parseSafe($row->created_at ?? null);
                $recentCategoriesAll[] = [
                    'id' => $row->id,
                    'record_type' => $t['name'],
                    'status' => $row->status ?? 1,
                    'created_at_raw' => $cAt->timestamp,
                    'created_at' => DateFormatterService::format($row->created_at),
                    'updated_at' => DateFormatterService::format($row->updated_at ?? ($row->created_at ?? null)),
                    'days_to_expired' => $daysToExpired,
                    'today_date' => $today->toDateString(),
                    'domain_name' => $domainName,
                    'product_name' => $productName,
                    'amount' => $amount,
                    'client_name' => $clientName,
                    'vendor_name' => $vendorName,
                    'remarks' => $remarks,
                    'grace_period' => $row->grace_period ?? 0,
                    'due_date' => $row->due_date ?? null
                ];
            }
        }

        // Sort by created_at desc
        usort($recentCategoriesAll, function($a, $b) {
            return ($b['created_at_raw'] ?? 0) <=> ($a['created_at_raw'] ?? 0);
        });

        $totalCategories = count($recentCategoriesAll);
        $recentCategories = array_slice($recentCategoriesAll, $offset, $rowsPerPage);

        // =========================
        // FINAL RESPONSE
        // =========================
        return response()->json([
            'success' => true,
            'data' => [
                'subscription' => $subscriptionCount,
                'ssl'          => $sslCount,
                'hosting'      => $hostingCount,
                'domains'      => $domainsCount,
                'emails'       => $emailsCount,
                'counter'      => $counterCount
            ],
            // Keeping these intact below for backwards compatibility if other parts of frontend use it
            'filters' => [
                'start_date' => $DD['start_date'] ?? null,
                'end_date'   => $DD['end_date'] ?? null,
            ],
            'stats' => [
                'total_clients'  => $totalClients,
                'total_users'    => $totalUsers,
                'total_domains'  => $totalDomains,
                'total_products' => $totalProducts,
            ],
            'type_counts' => $typeCounts,
            'recent_categories' => [
                'total' => $totalCategories,
                'page' => $page,
                'rowsPerPage' => $rowsPerPage,
                'data' => array_values($recentCategories)
            ]
        ]);

    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error("Dashboard Counting Error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        return response()->json([
            'success' => false,
            'message' => 'Dashboard fetch failed',
            'error' => $e->getMessage()
        ], 500);
    }
}


public function getActivities(Request $request)
{
    try {
        return response()->json([
            'success' => true,
            'message' => 'Fetched successfully',
            'data' => \App\Models\Activity::latest()->get()->map(function($act) {
                try { $act->action = \App\Services\CryptService::decryptData($act->action) ?? $act->action; } catch (\Exception $e) {}
                try { $act->message = \App\Services\CryptService::decryptData($act->message) ?? $act->message; } catch (\Exception $e) {}
                try {
                    $dec = \App\Services\CryptService::decryptData($act->details);
                    if ($dec) $act->details = $dec;
                } catch (\Exception $e) {}
                return $act;
            })
        ]);
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::error("Activity fetch error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        return response()->json([
            'success' => false,
            'message' => 'Failed to fetch activities',
            'error' => $e->getMessage()
        ], 500);
    }
}


    public function getSubscriptions(Request $request)
    {
        try {
            $query = \App\Models\Subscription::with(['product', 'client', 'vendor'])
                ->orderBy('id', 'desc');

            // ── CLIENT SCOPE: filter to only this client's records ──
            ClientScopeService::applyScope($query, $request);

            $subscriptions = $query->get()->map(function ($sub) {
                    // Decrypt names
                    $productName = $sub->product ? $sub->product->name : null;
                    $clientName = $sub->client ? $sub->client->name : null;
                    
                    try {
                        $productName = \App\Services\CryptService::decryptData($productName);
                    } catch (\Exception $e) {}
                    
                    try {
                        $clientName = \App\Services\CryptService::decryptData($clientName);
                    } catch (\Exception $e) {}

                    // calculate days_left
                    $daysLeft = null;
                    if ($sub->renewal_date) {
                        $renewal = Carbon::parse($sub->renewal_date);
                        $today = Carbon::today();
                        if ($renewal->gte($today)) {
                            $daysLeft = $today->diffInDays($renewal);
                        } else {
                            $daysLeft = -$renewal->diffInDays($today);
                        }
                    }

                    // calculate dynamically if missing
                    $daysToDelete = $sub->days_to_delete;
                    if ($daysToDelete === null && $sub->deletion_date) {
                        $daysToDelete = (int) now()->startOfDay()->diffInDays(Carbon::parse($sub->deletion_date)->startOfDay(), false);
                    }

                    $decryptedRemarks = $sub->remarks;
                    try {
                        $decryptedRemarks = \App\Services\CryptService::decryptData($sub->remarks);
                    } catch (\Exception $e) {}

                    return [
                        'id' => $sub->id,
                        'product' => $productName,
                        'client' => $clientName,
                        'amount' => $sub->amount,
                        'renewal_date' => $sub->renewal_date ? Carbon::parse($sub->renewal_date)->format('d/m/Y') : null,
                        'deletion_date' => $sub->deletion_date ? Carbon::parse($sub->deletion_date)->format('d/m/Y') : null,
                        'days_left' => $daysLeft,
                        'days_to_delete' => $daysToDelete,
                        'status' => $sub->status,
                        'remarks' => $decryptedRemarks,
                        'updated_at' => Carbon::parse($sub->updated_at)->format('j/n/Y, g:i:s a'),
                    ];
                });

            return response()->json($subscriptions);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch subscriptions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get details for a specific category record
     */
    public function getCategoryDetails(Request $request)
    {
        try {
            $catId = $request->input('cat_id');
            if (!$catId) {
                return response()->json(['status' => false, 'message' => 'Category ID is required'], 400);
            }

            // For now, focusing on Subscriptions as requested by frontend (record_type = 1)
            $subscription = \App\Models\Subscription::with(['product', 'client'])->find($catId);

            if (!$subscription) {
                return response()->json(['status' => false, 'message' => 'Subscription not found'], 404);
            }

            // Decrypt names
            $productName = $subscription->product ? $subscription->product->name : 'N/A';
            $clientName = $subscription->client ? $subscription->client->name : 'N/A';
            $domainName = $subscription->domain_id ? DB::table('domain')->where('id', $subscription->domain_id)->value('name') : null;

            try { $productName = CryptService::decryptData($productName); } catch (\Exception $e) {}
            try { $clientName = CryptService::decryptData($clientName); } catch (\Exception $e) {}
            if ($domainName) {
                try { $domainName = CryptService::decryptData($domainName); } catch (\Exception $e) {}
            }

            // Calculate days to expired
            $daysToExpired = 0;
            if ($subscription->renewal_date) {
                $renewal = Carbon::parse($subscription->renewal_date);
                $daysToExpired = (int) Carbon::today()->diffInDays($renewal, false);
            }

            $categoryData = [
                'id' => $subscription->id,
                'record_type' => 'Subscription',
                'product_name' => $productName,
                'client_name' => $clientName,
                'domain_name' => $domainName,
                'expiry_date' => $subscription->renewal_date,
                'valid_till' => $subscription->renewal_date,
                'days_to_expired' => $daysToExpired,
                'created_at' => $subscription->created_at->toDateTimeString(),
                'updated_at' => $subscription->updated_at->toDateTimeString(),
                'grace_period' => $subscription->grace_period ?? 0,
                'due_date' => $subscription->due_date,
            ];

            // Fetch remarks
            $remarks = DB::table('remark_history')
                ->where('subscription_id', $catId)
                ->orderBy('created_at', 'desc')
                ->get();

            // Fetch activities
            $activities = \App\Models\Activity::where('module_name', 'Subscriptions')
                ->where('module_id', $catId)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function($act) {
                    try { $act->action = CryptService::decryptData($act->action) ?? $act->action; } catch (\Exception $e) {}
                    try { $act->message = CryptService::decryptData($act->message) ?? $act->message; } catch (\Exception $e) {}
                    return $act;
                });

            return response()->json([
                'status' => true,
                'category' => $categoryData,
                'remarks' => $remarks,
                'activities' => $activities
            ]);

        } catch (\Exception $e) {
            Log::error("Category details fetch error: " . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch details',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}