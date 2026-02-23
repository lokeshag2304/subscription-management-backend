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
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RemarkCategoriesController;
use App\Http\Controllers\VendorsController;

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
Route::post('/add-venders', [ProductsController::class, 'storeVendors']);
Route::post('/update-venders', [ProductsController::class, 'updateVendors']);
Route::post('/list-venders', [ProductsController::class, 'VendorsList']);
Route::post('/delete-venders', [ProductsController::class, 'deleteProducts']);

});

Route::prefix('Venders')->group(function () {
Route::post('/add', [VendorsController::class, 'storeVendors']);
Route::post('/add-venders', [VendorsController::class, 'storeVendors']);
Route::post('/update-venders', [VendorsController::class, 'updateVendors']);
Route::post('/list-venders', [VendorsController::class, 'VendorsList']);
Route::post('/delete-venders', [VendorsController::class, 'deleteProducts']);

});

Route::prefix('Categories')->group(function () {
Route::post('/add-categories', [CategoriesController::class, 'addCategoryRecord']);
Route::post('/edit-categories', [CategoriesController::class, 'updateCategoryRecord']);
Route::post('/list-categories', [CategoriesController::class, 'listCategoryRecords']);
Route::post('/get-categories-details', [CategoriesController::class, 'getCategoryRemarksAndActivities']);

Route::post('/search-results', [CategoriesController::class, 'SearchResult']);
Route::post('/delete-categories', [CategoriesController::class, 'deleteCategories']);
Route::post('/export-categories', [CsvController::class, 'exportCategoryRecords']);



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
