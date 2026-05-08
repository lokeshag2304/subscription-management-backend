<?php

use App\Http\Controllers\ActivitiesController;
use App\Http\Controllers\CsvController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserManagement;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardAlertsController;
use App\Http\Controllers\DropdownsController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\DomainMasterController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RemarkCategoriesController;
use App\Http\Controllers\VendorsController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SubscriptionModelController;
use App\Http\Controllers\SSLController;
use App\Http\Controllers\HostingController;
use App\Http\Controllers\CounterController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\ToolController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\SSLExportController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
// Search nand notification work start
Route::prefix('secure')->middleware('route.access')->group(function () {


Route::post('/dashboard/counting', [DashboardController::class, 'GetDashboardData']);
Route::get('/dashboard/upcoming-expiries', [DashboardAlertsController::class, 'getUpcomingExpiries']);
Route::get('/dashboard/recent-activity', [DashboardAlertsController::class, 'getRecentActivity']);
Route::get('/dashboard/subscriptions', [DashboardController::class, 'getSubscriptions']);
Route::get('/dashboard/search-all', [DashboardController::class, 'searchAll']);

Route::prefix('Domain')->group(function () {
Route::post('/add-domain', [DomainController::class, 'storeDomain']);
Route::post('/update-domain', [DomainController::class, 'updateDomain']);
Route::post('/list-domain', [DomainController::class, 'DomainList']);
Route::post('/delete-domain', [DomainController::class, 'deleteDomains']);

});

Route::prefix('Products')->group(function () {
    Route::match(['get', 'post'], '/add-products', [ProductsController::class, 'storeProducts']);
    Route::match(['get', 'post', 'put'], '/update-products', [ProductsController::class, 'updateProducts']);
    Route::match(['get', 'post'], '/list-products', [ProductsController::class, 'ProductsList']);
    Route::match(['get', 'post'], '/delete-products', [ProductsController::class, 'deleteProducts']);
    Route::post('/export-log', [ProductsController::class, 'logExport']);
    Route::post('/import', [ProductsController::class, 'import']);
});

Route::prefix('products')->group(function () {
    Route::post('/import', [ProductsController::class, 'import']);
});


    Route::match(['get', 'post'], '/get-domains', [DropdownsController::class, 'getDomains']);
    Route::match(['get', 'post'], '/get-domain', [DropdownsController::class, 'getDomains']);
    Route::match(['get', 'post'], '/add-master-domain', [DropdownsController::class, 'addMasterDomain']);
    Route::match(['get', 'post'], '/get-products', [DropdownsController::class, 'getProducts']);
    Route::match(['get', 'post'], '/get-product', [DropdownsController::class, 'getProducts']);
    Route::match(['get', 'post'], '/get-clients', [DropdownsController::class, 'getClients']);
    Route::match(['get', 'post'], '/get-client', [DropdownsController::class, 'getClients']);
    Route::match(['get', 'post'], '/get-venders', [DropdownsController::class, 'getVendors']);
    Route::match(['get', 'post'], '/get-vendors', [DropdownsController::class, 'getVendors']);
    Route::match(['get', 'post'], '/get-vendor', [DropdownsController::class, 'getVendors']);

// Dropdowns - Global aliases (outside secure just in case)
Route::match(['get', 'post'], '/get-domains', [DropdownsController::class, 'getDomains']);
Route::match(['get', 'post'], '/get-products', [DropdownsController::class, 'getProducts']);
Route::match(['get', 'post'], '/get-clients', [DropdownsController::class, 'getClients']);
Route::match(['get', 'post'], '/get-venders', [DropdownsController::class, 'getVendors']);
Route::match(['get', 'post'], '/get-vendors', [DropdownsController::class, 'getVendors']);
Route::prefix('Dropdowns')->group(function () {
    Route::match(['get', 'post'], '/get-domains', [DropdownsController::class, 'getDomains']);
    Route::match(['get', 'post'], '/get-products', [DropdownsController::class, 'getProducts']);
    Route::match(['get', 'post'], '/get-clients', [DropdownsController::class, 'getClients']);
    Route::match(['get', 'post'], '/get-venders', [DropdownsController::class, 'getVendors']);
    Route::match(['get', 'post'], '/get-vendors', [DropdownsController::class, 'getVendors']);
});
Route::prefix('dropdowns')->group(function () {
    Route::match(['get', 'post'], '/get-domains', [DropdownsController::class, 'getDomains']);
    Route::match(['get', 'post'], '/get-products', [DropdownsController::class, 'getProducts']);
    Route::match(['get', 'post'], '/get-clients', [DropdownsController::class, 'getClients']);
    Route::match(['get', 'post'], '/get-venders', [DropdownsController::class, 'getVendors']);
    Route::match(['get', 'post'], '/get-vendors', [DropdownsController::class, 'getVendors']);
});






Route::prefix('Remark')->group(function () {
Route::post('/add', [RemarkCategoriesController::class, 'addCategoryRemark']);

});

// In this I have created 2 Routes for adding because one is being added for Dropdown and one for sections.
Route::prefix('Vendors')->group(function () {
Route::post('/add', [VendorsController::class, 'storeVendors']);
Route::post('/add-venders', [VendorsController::class, 'storeVendors']);
Route::post('/update-venders', [VendorsController::class, 'updateVendors']);
Route::post('/list-venders', [VendorsController::class, 'VendorsList']);
Route::post('/delete-venders', [VendorsController::class, 'deleteVendors']);
Route::post('/export-log', [VendorsController::class, 'logExport']);
Route::post('/import', [VendorsController::class, 'import']);
});

Route::prefix('vendors')->group(function () {
    Route::post('/import', [VendorsController::class, 'import']);
});


Route::prefix('Venders')->group(function () {
Route::post('/add', [VendorsController::class, 'storeVendors']);
Route::post('/add-venders', [VendorsController::class, 'storeVendors']);
Route::post('/update-venders', [VendorsController::class, 'updateVendors']);
Route::post('/list-venders', [VendorsController::class, 'VendorsList']);
Route::post('/delete-venders', [VendorsController::class, 'deleteVendors']);
});

Route::prefix('Usermanagement')->group(function () {
Route::post('/add-clients-user', [UserManagement::class, 'AddUsermanagement']);
Route::post('/update-clients-user', [UserManagement::class, 'updateUsermanagement']);
Route::post('/get-clients-user-details', [UserManagement::class, 'getUsermanagementDetails']);
Route::post('/get-clients-user-list', [UserManagement::class, 'list']);
Route::post('/get-clients-user-delete', [UserManagement::class, 'deleteUsers']);
Route::post('/get-clients-details', [UserManagement::class, 'GetClientDetails']);
Route::post('/export-log', [UserManagement::class, 'logExport']);



});



Route::prefix('Profile')->group(function () {
Route::post('/Get-Profile', [ProfileController::class, 'getProfile']);
Route::post('/Update-Profile', [ProfileController::class, 'updateProfile']);


});

Route::prefix('Activites')->group(function () {
    Route::post('/Get-acitivites', [ActivitiesController::class, 'getAllActivities']);
    Route::post('/Delete-activies', [ActivitiesController::class, 'DeleteActivies']);
    Route::post('/log-activites', [ActivitiesController::class, 'logActivity']);
});

    Route::post('/ssl/bulk-delete', [SSLController::class, 'bulkDelete']);
    Route::get('/ssl/filter-options', [SSLController::class, 'filterOptions']);
    Route::get('/ssl/export', [SSLExportController::class, 'export']);
    Route::post('/ssl/export-log', [SSLController::class, 'logExport']);
    Route::apiResource('ssl', SSLController::class);
    Route::post('/hostings/bulk-delete', [HostingController::class, 'bulkDelete']);
    Route::post('/hostings/export-log', [HostingController::class, 'logExport']);
    Route::apiResource('hostings', HostingController::class);

    Route::get('/domains/filter-options', [DomainController::class, 'filterOptions']);
    Route::post('/domains/bulk-delete', [DomainController::class, 'bulkDelete']);
    Route::post('/domains/export-log', [DomainController::class, 'logExport']);
    Route::apiResource('domains', DomainController::class);

    // ── Domain Master (domain_master table) ──────────────────────────────────
    Route::get('/domain-master', [DomainMasterController::class, 'index']);
    Route::post('/domain-master', [DomainMasterController::class, 'store']);
    Route::put('/domain-master/{id}', [DomainMasterController::class, 'update']);
    Route::delete('/domain-master/{id}', [DomainMasterController::class, 'destroy']);
    Route::post('/domain-master/bulk-delete', [DomainMasterController::class, 'bulkDelete']);
    Route::post('/domain-master/export-log', [DomainMasterController::class, 'logExport']);
    Route::post('/domain-master/import', [DomainMasterController::class, 'import']);
    Route::get('/domain-master/decrypt-legacy-data', [DomainMasterController::class, 'decryptLegacyData']);
    
    // ── Suffix Master ────────────────────────────────────────────────────────
    Route::get('/suffix-master', [\App\Http\Controllers\SuffixMasterController::class, 'index']);
    Route::post('/suffix-master', [\App\Http\Controllers\SuffixMasterController::class, 'store']);
    Route::delete('/suffix-master/{id}', [\App\Http\Controllers\SuffixMasterController::class, 'destroy']);
    Route::post('/suffix-master/bulk-delete', [\App\Http\Controllers\SuffixMasterController::class, 'bulkDelete']);
    Route::post('/suffix-master/import', [\App\Http\Controllers\SuffixMasterController::class, 'import']);
    Route::post('/suffix-master/export-log', [\App\Http\Controllers\SuffixMasterController::class, 'logExport']);
    // ─────────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────────

    Route::get('/emails/filter-options', [EmailController::class, 'filterOptions']);
    Route::post('/emails/bulk-delete', [EmailController::class, 'bulkDelete']);
    Route::post('/emails/export-log', [EmailController::class, 'logExport']);
    Route::apiResource('emails', EmailController::class);
    Route::post('/counters/fetch-count', [CounterController::class, 'fetchCount']);
    Route::post('/counters/bulk-delete', [CounterController::class, 'bulkDelete']);
    Route::post('/counters/export-log', [CounterController::class, 'logExport']);
    Route::apiResource('counters', CounterController::class);
    Route::apiResource('tools', ToolController::class);
    Route::apiResource('users-management', UserManagementController::class);
    Route::apiResource('activities', ActivityController::class);

    Route::post('/ssl/import', [SSLController::class, 'import']);
    Route::post('/domains/import', [DomainController::class, 'import']);
    Route::post('/hostings/import', [HostingController::class, 'import']);
    Route::post('/hosting/import', [HostingController::class, 'import']);
    Route::post('/emails/import', [EmailController::class, 'import']);
    Route::post('/email/import', [EmailController::class, 'import']);
    Route::post('/counter/import', [CounterController::class, 'import']);
    Route::post('/counters/import', [CounterController::class, 'import']);
    
    Route::post('/superadmins/import', [UserManagement::class, 'import']);
    Route::post('/clients/import', [UserManagement::class, 'import']);
    Route::post('/users/import', [UserManagement::class, 'import']);

    Route::post('/import-records', [ImportController::class, 'importRecords']);
    Route::get('/migrate-legacy-data', [\App\Http\Controllers\LegacyMigrationController::class, 'migrate']);
    
    // Subscriptions (New Unified Flow)
    Route::get('/subscriptions/filter-options', [SubscriptionController::class, 'filterOptions']);
    Route::get('/subscriptions', [SubscriptionController::class, 'index']);
    Route::post('/subscriptions/bulk-delete', [SubscriptionController::class, 'bulkDelete']);
    Route::post('/subscriptions', [SubscriptionController::class, 'store']);
    Route::post('/subscriptions/import', [SubscriptionController::class, 'import']);
    Route::post('/subscription/import', [SubscriptionController::class, 'import']);
    Route::put('/subscriptions/{id}', [SubscriptionController::class, 'update']);
    Route::get('/subscriptions/export', [SubscriptionController::class, 'export']);
    Route::post('/subscriptions/export-log', [SubscriptionController::class, 'logExport']);
    Route::delete('/subscriptions/{id}', [SubscriptionController::class, 'destroy']);
    
    // History Downloads (Moved inside secure group to avoid 404)
    Route::get('/history/download/{id}', [\App\Http\Controllers\ImportHistoryController::class, 'download']);
    Route::get('/import-history/download/{id}', [\App\Http\Controllers\ImportHistoryController::class, 'download']);
    
    // Dedicated Remark Update & History
    Route::put('/subscription/update-remark', [SubscriptionController::class, 'updateRemark']);
    Route::get('/subscription/{id}/remark-history', [SubscriptionController::class, 'getRemarkHistory']);

    // Legacy Support routes just in case frontend still hits them in places
    Route::get('/subscription-models', [SubscriptionModelController::class, 'index']);
    Route::post('/subscription-models', [SubscriptionModelController::class, 'store']);
    Route::post('/subscription-models/import', [SubscriptionModelController::class, 'import']);
    Route::post('/subscription-models/export-categories', [CsvController::class, 'exportCategoryRecords']);

    Route::post('/Categories/search-results', [DashboardController::class, 'GetDashboardData']);
    Route::post('/Categories/get-categories-details', [DashboardController::class, 'getCategoryDetails']);

    // ── Duplicate-rows Excel download ─────────────────────────────────────────
    Route::get('/import-logs/{id}/download-duplicates', function ($id) {
        $log = \App\Models\ImportLog::find($id);
        if (!$log || !$log->duplicate_file) {
            return response()->json(['success' => false, 'message' => 'Duplicate file not found'], 404);
        }

        $path = storage_path('app/' . $log->duplicate_file);
        if (!file_exists($path)) {
            return response()->json(['success' => false, 'message' => 'File no longer exists on server'], 404);
        }

        return response(\Illuminate\Support\Facades\Storage::disk('local')->get($log->duplicate_file))
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="' . basename($path) . '"');
    });

    Route::get('/global-search', [SearchController::class, 'globalSearch']);
}); // <-- End of secure group

// Correct Domain update route as requested: PUT /api/domains/{id}
Route::put('/domains/{id}', [DomainController::class, 'update'])->middleware('route.access');

Route::get('/import-history', [\App\Http\Controllers\ImportHistoryController::class, 'index']);
Route::get('/history/download/{id}', [\App\Http\Controllers\ImportHistoryController::class, 'download']);
Route::get('/import-history/download/{id}', [\App\Http\Controllers\ImportHistoryController::class, 'download']);
Route::get('/import-export-history/{id}/download', [\App\Http\Controllers\ImportHistoryController::class, 'download']);
Route::get('/import-export-history/{id}/download-duplicates', function ($id) {
    $log = \App\Models\ImportHistory::find($id);
    if (!$log) {
        return response()->json(['success' => false, 'message' => 'History record not found'], 404);
    }
    
    if (empty($log->duplicate_file)) {
        return response()->json(['success' => false, 'message' => 'This import record has no duplicate file linked.'], 404);
    }

    $path = storage_path('app/' . $log->duplicate_file);
    if (!file_exists($path)) {
        return response()->json(['success' => false, 'message' => 'The physical duplicate file is missing from the server.'], 404);
    }

    return response(\Illuminate\Support\Facades\Storage::disk('local')->get($log->duplicate_file))
        ->header('Content-Type', 'text/csv')
        ->header('Content-Disposition', 'attachment; filename="' . basename($path) . '"');
});

Route::get('/import-export-history/{id}/download-errors', function ($id) {
    $log = \App\Models\ImportHistory::find($id);
    if (!$log || empty($log->data_snapshot)) {
        return response()->json(['success' => false, 'message' => 'Error report not found'], 404);
    }
    
    $issues = json_decode($log->data_snapshot, true);
    if (!is_array($issues)) {
        return response()->json(['success' => false, 'message' => 'Invalid error format'], 400);
    }

    $csvData = "Row Number,Column,Error Reason\n";
    foreach ($issues as $issue) {
        $row = $issue['row'] ?? '-';
        if (isset($issue['missing_fields']) && is_array($issue['missing_fields'])) {
            foreach ($issue['missing_fields'] as $field) {
                $displayField = strtolower($field) === 'suffix' ? 'TLD' : $field;
                $csvData .= "{$row},{$displayField},Missing Mandatory Data\n";
            }
        } else {
            $csvData .= "{$row},-,Invalid or missing data\n";
        }
    }

    return response($csvData)
        ->header('Content-Type', 'text/csv')
        ->header('Content-Disposition', 'attachment; filename="error_report_' . $id . '.csv"');
});
Route::get('/remark-history', [\App\Http\Controllers\RemarkHistoryController::class, 'index']);

Route::get('/import-export-history', function () {
    $history = \App\Models\ImportHistory::whereIn('action', ['IMPORT', 'EXPORT'])
        ->orderBy('created_at', 'desc')
        ->paginate(10);
    
    $history->getCollection()->transform(function ($item) {
        $item->badge_color = strtolower($item->action) === 'import' ? 'green' : 'blue';
        $item->user_name = $item->imported_by;
        try {
            $dec = \App\Services\CryptService::decryptData($item->imported_by);
            if ($dec && $dec !== $item->imported_by) $item->user_name = $dec;
        } catch (\Exception $e) {}
        $item->userName = $item->user_name;
        $item->role = $item->role ?? 'Unknown';
        $item->inserted = (int)$item->successful_rows;
        $item->failed = (int)$item->failed_rows;
        $item->duplicates = (int)$item->duplicates_count;
        $item->inserted_count = (int)$item->successful_rows;
        $item->failed_count = (int)$item->failed_rows;
        $item->total_records = (int)($item->successful_rows ?? $item->inserted_count ?? 0);
        return $item;
    });

    return $history;
});

Route::delete('/import-export-history/{id}', function ($id) {
    $log = \App\Models\ImportHistory::find($id);
    if ($log) {
        $log->delete();
        return response()->json(['success' => true, 'message' => 'Log deleted successfully']);
    }
    return response()->json(['success' => false, 'message' => 'Log not found'], 404);
});

Route::get('/history', function (Request $request) {
    $entity = $request->query('entity');
    $query = \App\Models\ImportHistory::whereIn('action', ['IMPORT', 'EXPORT'])
        ->orderBy('created_at', 'desc');
    
    if ($entity) {
        $query->where('module_name', 'LIKE', '%' . $entity . '%');
    }
    
    $history = $query->paginate(10);
    
    $history->getCollection()->transform(function ($item) {
        $item->badge_color = strtolower($item->action) === 'import' ? 'green' : 'blue';
        $item->download_url = url('/api/import-history/download/' . $item->id);
        $item->inserted_count = $item->successful_rows;
        $item->failed_count = $item->failed_rows;
        $item->duplicates = (int)$item->duplicates_count;
        $item->user_name = $item->imported_by;
        try {
            $dec = \App\Services\CryptService::decryptData($item->imported_by);
            if ($dec && $dec !== $item->imported_by) $item->user_name = $dec;
        } catch (\Exception $e) {}
        $item->userName = $item->user_name;
        $item->role = $item->role ?? 'Unknown';
        $item->total_records = (int)($item->successful_rows ?? $item->inserted_count ?? 0);
        return $item;
    });

    return $history;
});

Route::get('/getLogo', [SettingController::class, 'getLogo']);

// 🟢 SuperAdmin Authentication Routes
Route::prefix('auth')->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class.':api'])->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    // Route::get('/hack-password', [AuthController::class, 'hackPass']); // REMOVED – security risk
    Route::post('/two_step_otp', [AuthController::class, 'two_step_otp']);
    Route::post('/verifyOtp', [AuthController::class, 'verifyOtp']);
    Route::post('/verify-for-forget', [AuthController::class, 'verifyOtpForForget']);
    Route::post('/send_reset_link', [AuthController::class, 'sendResetLink']);
    Route::post('/send_sms_otp', [AuthController::class, 'send_sms_otp']);
    Route::post('/send_whatsap_otp', [AuthController::class, 'send_whatsap_otp']);
    Route::post('/change_password', [AuthController::class, 'change_password']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

// 🟢 SuperAdmin Authentication Routes




 



// 
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
