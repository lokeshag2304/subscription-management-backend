<?php

use App\Http\Controllers\ActivitiesController;
use App\Http\Controllers\CsvController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserManagement;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DropdownsController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\DomainController;
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
Route::get('/dashboard/subscriptions', [DashboardController::class, 'getSubscriptions']);

Route::prefix('Domain')->group(function () {
Route::post('/add-domain', [DomainController::class, 'storeDomain']);
Route::post('/update-domain', [DomainController::class, 'updateDomain']);
Route::post('/list-domain', [DomainController::class, 'DomainList']);
Route::post('/delete-domain', [DomainController::class, 'deleteDomains']);

});

Route::prefix('Products')->group(function () {
Route::post('/add-products', [ProductsController::class, 'storeProducts']);
Route::post('/update-products', [ProductsController::class, 'updateProducts']);
Route::post('/list-products', [ProductsController::class, 'ProductsList']);
Route::post('/delete-products', [ProductsController::class, 'deleteProducts']);

});

Route::prefix('Dropdowns')->group(function () {
Route::post('/get-domains', [DropdownsController::class, 'getDomains']);
Route::post('/get-products', [DropdownsController::class, 'getProduct']);
Route::post('/get-clients', [DropdownsController::class, 'getClients']);
Route::post('/get-venders', [DropdownsController::class, 'getVendors']);





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


});

Route::prefix('Profile')->group(function () {
Route::post('/Get-Profile', [ProfileController::class, 'getProfile']);
Route::post('/Update-Profile', [ProfileController::class, 'updateProfile']);


});

Route::prefix('Activites')->group(function () {
    Route::post('/Get-acitivites', [ActivitiesController::class, 'getAllActivities']);
});

Route::apiResource('ssl', SSLController::class);
Route::apiResource('hostings', HostingController::class);

    Route::apiResource('domains', DomainController::class);
    Route::apiResource('emails', EmailController::class);
    Route::post('/counters/fetch-count', [CounterController::class, 'fetchCount']);
    Route::apiResource('counters', CounterController::class);
    Route::apiResource('tools', ToolController::class);
    Route::apiResource('users-management', UserManagementController::class);
    Route::apiResource('activities', ActivityController::class);

    Route::post('/ssl/import', [SSLController::class, 'import']);
    Route::post('/domains/import', [DomainController::class, 'import']);
    Route::post('/hostings/import', [HostingController::class, 'import']);
    Route::post('/emails/import', [EmailController::class, 'import']);

    Route::post('/import-records', [ImportController::class, 'importRecords']);
    Route::get('/migrate-legacy-data', [\App\Http\Controllers\LegacyMigrationController::class, 'migrate']);
    
    // Subscriptions (New Unified Flow)
    Route::get('/subscriptions', [SubscriptionController::class, 'index']);
    Route::post('/subscriptions', [SubscriptionController::class, 'store']);
    Route::post('/subscriptions/import', [SubscriptionController::class, 'import']);
    Route::put('/subscriptions/{id}', [SubscriptionController::class, 'update']);
    Route::delete('/subscriptions/{id}', [SubscriptionController::class, 'destroy']);
    
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

        return response()->download($path, basename($path));
    });

}); // <-- End of secure group



// The routes were moved up into the auth group


Route::get('/import-history', [\App\Http\Controllers\ImportHistoryController::class, 'index']);
Route::get('/import-history/download/{id}', [\App\Http\Controllers\ImportHistoryController::class, 'download']);
Route::get('/import-export-history/{id}/download', [\App\Http\Controllers\ImportHistoryController::class, 'download']);
Route::get('/import-export-history/{id}/download-duplicates', function ($id) {
    $log = \App\Models\ImportHistory::find($id);
    if (!$log || empty($log->duplicate_file)) {
        return response()->json(['success' => false, 'message' => 'Duplicate file not found'], 404);
    }

    $path = storage_path('app/' . $log->duplicate_file);
    if (!file_exists($path)) {
        return response()->json(['success' => false, 'message' => 'File no longer exists on server'], 404);
    }

    return response()->download($path, basename($path));
});
Route::get('/remark-history', [\App\Http\Controllers\RemarkHistoryController::class, 'index']);

Route::get('/import-export-history', function () {
    $history = \App\Models\ImportHistory::orderBy('created_at', 'desc')->paginate(10);
    
    $history->getCollection()->transform(function ($item) {
        $item->badge_color = strtolower($item->action) === 'import' ? 'green' : 'blue';
        $item->user_name = $item->imported_by;
        $item->inserted = (int)$item->successful_rows;
        $item->failed = (int)$item->failed_rows;
        $item->duplicates = (int)$item->duplicates_count;
        $item->duplicate_file = $item->duplicate_file;
        $item->inserted_count = (int)$item->successful_rows;
        $item->failed_count = (int)$item->failed_rows;
        // removed download_url to force frontend to use axios blob download via /api/import-export-history/{id}/download
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

Route::get('/history', function (\Illuminate\Http\Request $request) {
    $entity = $request->query('entity');
    $query = \App\Models\ImportHistory::orderBy('created_at', 'desc');
    
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
        $item->duplicate_file = $item->duplicate_file;
        $item->user_name = $item->imported_by;
        return $item;
    });

    return $history;
});

Route::get('/getLogo', [SettingController::class, 'getLogo']);

// 🟢 SuperAdmin Authentication Routes
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/hack-password', [AuthController::class, 'hackPass']);
    Route::post('/two_step_otp', [AuthController::class, 'two_step_otp']);
    Route::post('/verifyOtp', [AuthController::class, 'verifyOtp']);
    Route::post('/verify-for-forget', [AuthController::class, 'verifyOtpForForget']);
    Route::post('/send_reset_link', [AuthController::class, 'sendResetLink']);
    Route::post('/send_sms_otp', [AuthController::class, 'send_sms_otp']);
    Route::post('/send_whatsap_otp', [AuthController::class, 'send_whatsap_otp']);
    Route::post('/change_password', [AuthController::class, 'change_password']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

});

// 🟢 SuperAdmin Authentication Routes




 



// 
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
