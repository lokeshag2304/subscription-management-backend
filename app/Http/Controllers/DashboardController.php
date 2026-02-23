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

use Carbon\Carbon;

class DashboardController extends Controller
{

public function GetDashboardData(Request $request)
{
    try {

        $DD = json_decode($request->getContent(), true);

        $s_id = $DD['s_id'] ?? null;

        $user = null;
        if ($s_id) {
            $user = DB::table('superadmins')->where('id', $s_id)->first();
        }

        // =========================
        // CLIENT DOMAIN IDS (FIXED)
        // =========================
        $clientDomainIds = [];
        if ($user && $user->login_type == 3 && !empty($user->domain_id)) {
            $clientDomainIds = array_map(
                'intval',
                json_decode($user->domain_id, true) ?? []
            );
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
        if ($user && $user->login_type == 3) {
            $totalDomains = empty($clientDomainIds)
                ? 0
                : DB::table('domain')->whereIn('id', $clientDomainIds)->count();
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
        if ($user && $user->login_type == 3) {
            $totalProducts = empty($clientDomainIds)
                ? 0
                : DB::table('categories')
                    ->whereIn('domain_id', $clientDomainIds)
                    ->whereNotNull('product_id')
                    ->count();
        } else {
            $productQuery = DB::table('products');
            if ($startDate && $endDate) {
                $productQuery->whereBetween('created_at', [$startDate, $endDate]);
            }
            $totalProducts = $productQuery->count();
        }

        // =========================
        // TYPE MAPS
        // =========================
        $typeMap = [
            1 => 'Subscriptions',
            2 => 'SSL',
            3 => 'Hosting',
            4 => 'Domains',
            5 => 'Emails',
            6 => 'Counter'
        ];

        $statusMap = [
            1 => 'Active',
            2 => 'Deactive'
        ];

        // =========================
        // 6 DASHBOARD BOX COUNTS
        // =========================
        $typeCounts = [];

        foreach ($typeMap as $typeKey => $typeName) {

            $typeQuery = DB::table('categories')
                ->where('record_type', $typeKey);

            if ($startDate && $endDate) {
                $typeQuery->whereBetween('created_at', [$startDate, $endDate]);
            }

            if ($user && $user->login_type == 3) {
                if (!empty($clientDomainIds)) {
                    $typeQuery->whereIn('domain_id', $clientDomainIds);
                } else {
                    $typeQuery->whereRaw('1 = 0');
                }
            }

            $typeCounts[] = [
                'type_id'   => $typeKey,
                'type_name' => $typeName,
                'count'     => $typeQuery->count()
            ];
        }

        // =========================
        // RECENT CATEGORIES
        // =========================
        $page        = (int) ($DD['page'] ?? 0);
        $rowsPerPage = (int) ($DD['rowsPerPage'] ?? 10);
        $search      = $DD['search'] ?? null;
        $orderBy     = $DD['orderBy'] ?? 'id';
        $orderDir    = $DD['orderDir'] ?? 'desc';

        $offset = $page * $rowsPerPage;

        $catQuery = DB::table('categories');

        if ($user && $user->login_type == 3) {
            if (!empty($clientDomainIds)) {
                $catQuery->whereIn('domain_id', $clientDomainIds);
            } else {
                $catQuery->whereRaw('1 = 0');
            }
        }

        $today = Carbon::today();

        if (!empty($search)) {
            $catQuery->where(function ($q) use ($search, $typeMap, $statusMap) {
                foreach ($typeMap as $key => $label) {
                    if (stripos($label, $search) !== false) {
                        $q->orWhere('record_type', $key);
                    }
                }
                foreach ($statusMap as $key => $label) {
                    if (stripos($label, $search) !== false) {
                        $q->orWhere('status', $key);
                    }
                }
            });
        }

        $totalCategories = (clone $catQuery)->count();

        $recentCategoriesRaw = $catQuery
            ->orderBy($orderBy, $orderDir)
            ->offset($offset)
            ->limit($rowsPerPage)
            ->get();

        $recentCategories = [];

        foreach ($recentCategoriesRaw as $row) {

            $daysToExpired = null;

            if (!empty($row->expiry_date)) {
                $expiry = Carbon::parse($row->expiry_date);

                if ($expiry->gte($today)) {
                    $daysToExpired = $today->diffInDays($expiry);
                } else {
                    $daysToExpired = -$expiry->diffInDays($today);
                }
            }

            $recentCategories[] = [
                'id' => $row->id,
                'record_type' => $typeMap[$row->record_type] ?? 'Unknown',
                'status' => $statusMap[$row->status] ?? 'Unknown',
                'created_at' => Carbon::parse($row->created_at)->format('d F Y'),
                'days_to_expired' => $daysToExpired,
                'today_date' => $today->toDateString()
            ];
        }

        // =========================
        // FINAL RESPONSE
        // =========================
        return response()->json([
            'status' => true,
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
                'data' => $recentCategories
            ]
        ]);

    } catch (\Exception $e) {

        return response()->json([
            'status' => false,
            'message' => 'Dashboard fetch failed',
            'error' => $e->getMessage()
        ], 500);
    }
}







    
}