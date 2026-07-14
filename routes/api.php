<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ActiveLogController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuditTrailController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\ShopSettingsController;
use App\Http\Controllers\Api\SMSController;
use App\Http\Controllers\SuperAdmin\DashboardController as SuperAdminDashboardController;
use App\Http\Controllers\SuperAdmin\BackupController as SuperAdminBackupController;
use App\Http\Controllers\SuperAdmin\SubscriptionsController as SuperAdminSubscriptionsController;
use App\Http\Controllers\Api\SubscriptionController;
use Illuminate\Http\Request;

// Public routes
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);

Route::post('/auth/register-shop', [AuthController::class, 'registerShop']); // Create shop + admin
Route::post('/auth/create-super-admin', [AuthController::class, 'createSuperAdmin']);

// Subscription public endpoints
Route::get('/plans', [SubscriptionController::class, 'plans']);
Route::post('/paystack/webhook', [SubscriptionController::class, 'webhook']);

// Public product-image endpoint — see ProductController::serveImage() for why
// this streams through PHP instead of relying on the public/storage symlink
// (shared hosts often disable FollowSymLinks, which 403s the symlinked path
// even when the underlying file is fine). Registered here (not web.php) so it
// only goes through the lightweight `api` middleware group — no sessions/CSRF.
Route::get('/product-images/{shopId}/{filename}', [ProductController::class, 'serveImage'])
    ->where('filename', '.*');

// Public shop-logo endpoint — same rationale as above, see ShopSettingsController::serveLogo().
Route::get('/shop-logos/{shopId}/{filename}', [ShopSettingsController::class, 'serveLogo'])
    ->where('filename', '.*');

// Protected routes
Route::middleware(['auth:sanctum'])->group(function () {

Route::any('/sync/{dbName}', function ($dbName, Request $request) {
        // This endpoint handles PouchDB replication
        // You'll need to implement a CouchDB-compatible sync endpoint
        // or use a package like: laravel-couchdb
        return response()->json(['ok' => true]);
    });
    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    
    // Shops (Super Admin only)
    Route::apiResource('shops', ShopController::class);
    Route::get('shops/{shop}/stats', [ShopController::class, 'stats']);

    Route::get('/all-shops', [AuthController::class, 'getAllShops']);
    Route::get('/all-shops/{shopId}/users', [AuthController::class, 'getShopUsers']);
    
    // User management (Shop Admin)
    Route::post('/users/add-to-shop', [AuthController::class, 'addUserToShop']);

    Route::get('/users', [AuthController::class, 'getUsers']);
    Route::get('/users/stats', [AuthController::class, 'getUserStats']);
    Route::post('/users', [AuthController::class, 'addUserToShop']);  // Create user
    Route::put('/users/{id}', [AuthController::class, 'updateUser']);
    Route::delete('/users/{id}', [AuthController::class, 'deleteUser']);
    Route::patch('/users/{id}/status', [AuthController::class, 'updateUserStatus']);
    
    // Shop management (Super Admin only)
    Route::get('/shops', [AuthController::class, 'getAllShops']);
    Route::get('/shops/{shopId}/users', [AuthController::class, 'getShopUsers']);
    
    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/weekly-revenue', [DashboardController::class, 'weeklyRevenue']);
    Route::get('/dashboard/hourly-sales', [DashboardController::class, 'hourlySales']);
    Route::get('/dashboard/top-products', [DashboardController::class, 'topProducts']);
    Route::get('/dashboard/low-stock', [DashboardController::class, 'lowStock']);
    
    // Categories
    Route::apiResource('categories', CategoryController::class);

    // Products
    // Route::apiResource('products', ProductController::class);
    // Route::get('products/{product}/stock-history', [ProductController::class, 'stockHistory']);
    // Route::post('products/bulk-import', [ProductController::class, 'bulkImport']);

    Route::get('products', [ProductController::class, 'index']);
    Route::post('products', [ProductController::class, 'store']);
    Route::get('products/low-stock', [ProductController::class, 'lowStock']);
    Route::get('products/expiring', [ProductController::class, 'expiring']);
    Route::get('products/expired', [ProductController::class, 'expired']);
    Route::get('products/damaged', [ProductController::class, 'damaged']);
    Route::post('products/bulk-import', [ProductController::class, 'bulkImport']);
    Route::get('products/{id}', [ProductController::class, 'show']);
    Route::put('products/{id}', [ProductController::class, 'update']);
    Route::delete('products/{id}', [ProductController::class, 'destroy']);
    Route::get('products/{id}/stock-history', [ProductController::class, 'stockHistory']);
    Route::post('products/{id}/adjust-stock', [ProductController::class, 'adjustStock']);

    Route::get('/stock-movements', [StockMovementController::class, 'index']);
    Route::get('/stock-movements/summary', [StockMovementController::class, 'summary']);
    Route::post('/stock-movements/adjust', [StockMovementController::class, 'adjustStock']);
    
    // Customers
    Route::apiResource('customers', CustomerController::class);
    Route::get('customers/{customer}/invoices', [CustomerController::class, 'invoices']);
    
    // Invoices
     // Invoice routes
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/pending', [InvoiceController::class, 'pendingInvoices']);
    Route::post('/invoices', [InvoiceController::class, 'store']);
    Route::post('invoices/create-with-payment', [InvoiceController::class, 'createWithPayment']);
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
    Route::put('/invoices/{id}', [InvoiceController::class, 'update']);
    Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy']);
    Route::post('/invoices/{id}/pay', [InvoiceController::class, 'processPayment']);
    Route::post('/invoices/{id}/cancel', [InvoiceController::class, 'cancel']);
    Route::get('/invoices/{id}/receipt', [InvoiceController::class, 'generateReceipt']);
    Route::post('/invoices/{id}/whatsapp', [InvoiceController::class, 'sendWhatsAppReceipt']);
    
    // Payments
    Route::apiResource('payments', PaymentController::class);
    Route::post('payments/{payment}/refund', [PaymentController::class, 'refund']);

    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/customers/{id}', [CustomerController::class, 'show']);
    Route::put('/customers/{id}', [CustomerController::class, 'update']);
    Route::delete('/customers/{id}', [CustomerController::class, 'destroy']);
    Route::get('/customers/{id}/invoices', [CustomerController::class, 'invoices']);
    Route::get('/customers/{id}/stats', [CustomerController::class, 'stats']);

    // Admin Dashboard
    Route::get('/admin/dashboard', [ReportController::class, 'adminDashboard']);

     Route::get('/accountant/dashboard', [ReportController::class, 'accountantDashboard']);
     Route::get('/accountant/export', [ReportController::class, 'exportFinancialReport']);

     Route::get('/reports/dashboard', [ReportController::class, 'dashboard']);
    Route::get('/reports/financial', [ReportController::class, 'financial']);
    Route::get('/reports/customers', [ReportController::class, 'customers']);
    Route::get('/reports/products', [ReportController::class, 'products']);
    Route::get('/reports/stock', [ReportController::class, 'stock']);
    
    // Expenses
   Route::get('/expense-categories', [ExpenseController::class, 'categories']);
    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);
    Route::get('/expenses/summary', [ExpenseController::class, 'summary']);
    Route::get('/expenses/{id}', [ExpenseController::class, 'show']);
    Route::put('/expenses/{id}', [ExpenseController::class, 'update']);
    Route::delete('/expenses/{id}', [ExpenseController::class, 'destroy']);
    

    // Shop settings
    Route::get('/shop/settings', [ShopSettingsController::class, 'show']);
    Route::put('/shop/settings', [ShopSettingsController::class, 'update']);
    Route::post('/shop/settings/logo', [ShopSettingsController::class, 'uploadLogo']);

    // Subscriptions
    Route::get('/subscription', [SubscriptionController::class, 'show']);
    Route::get('/subscription/limits', [SubscriptionController::class, 'usage']);
    Route::post('/subscription/initialize', [SubscriptionController::class, 'initialize']);
    Route::post('/subscription/verify', [SubscriptionController::class, 'verify']);
    Route::post('/subscription/cancel', [SubscriptionController::class, 'cancel']);

    // Branches (Multi-branch system)
    Route::get('/branches', [BranchController::class, 'index']);
    Route::post('/branches', [BranchController::class, 'store']);
    Route::get('/branches/{id}', [BranchController::class, 'show']);
    Route::put('/branches/{id}', [BranchController::class, 'update']);
    Route::delete('/branches/{id}', [BranchController::class, 'destroy']);
    Route::patch('/branches/{id}/status', [BranchController::class, 'updateStatus']);

    Route::post('/send', [SMSController::class, 'sendSingle']);
    Route::post('/send-bulk', [SMSController::class, 'sendBulk']);
    Route::get('/balance/{shop_id}', [SMSController::class, 'getSmsBalance']);
    Route::get('/history', [SMSController::class, 'getHistory']);
    
     Route::get('/active-logs', [ActiveLogController::class, 'index']);
     Route::get('/active-logs/summary', [ActiveLogController::class, 'summary']);

      // Audit Trail Routes
    Route::get('/audit-logs', [AuditTrailController::class, 'index']);
    Route::get('/audit-logs/{id}', [AuditTrailController::class, 'show']);
    Route::delete('/audit-logs/clear', [AuditTrailController::class, 'clear']);
    Route::get('/audit-logs/export', [AuditTrailController::class, 'export']);
    
    // Sync (Offline support)
    Route::post('/sync/upload', [SyncController::class, 'upload']);
    Route::get('/sync/download', [SyncController::class, 'download']);
    Route::post('/sync/queue', [SyncController::class, 'queue']);
    Route::get('/sync/status', [SyncController::class, 'status']);



    // Add this route for serving resized product images
Route::get('/product-image/{shopId}/{filename}', function($shopId, $filename, Request $request) {
    $size = $request->get('size', 300);
    $path = storage_path("app/public/products/{$shopId}/medium/{$filename}");
    
    // Try different size directories
    $sizeMap = [
        80 => 'thumb',
        150 => 'small',
        300 => 'medium',
        600 => 'large',
    ];
    
    $sizeName = $sizeMap[$size] ?? 'medium';
    $path = storage_path("app/public/products/{$shopId}/{$sizeName}/{$filename}");
    
    if (!file_exists($path)) {
        // Return placeholder if image not found
        return response()->file(public_path('product-placeholder.png'));
    }
    
    return response()->file($path, [
        'Cache-Control' => 'public, max-age=86400',
        'Content-Type' => mime_content_type($path),
    ]);
})->where('filename', '.*');

    // Super Admin Routes
    Route::middleware(['superadmin'])->prefix('superadmin')->group(function () {
        // Dashboard
         Route::get('/dashboard', [SuperAdminDashboardController::class, 'getDashboard']);
        
        // Shop Management
        Route::get('/shops', [SuperAdminDashboardController::class, 'getShops']);
        Route::post('/shops', [SuperAdminDashboardController::class, 'createShop']);
        Route::get('/shops/{id}', [SuperAdminDashboardController::class, 'getShopDetails']);
        Route::put('/shops/{id}', [SuperAdminDashboardController::class, 'updateShop']);
        Route::delete('/shops/{id}', [SuperAdminDashboardController::class, 'deleteShop']);
        
        // User Management
        Route::get('/super-users', [SuperAdminDashboardController::class, 'getUsers']);
        
        // Financial Reports
        Route::get('/super-reports/consolidated', [SuperAdminDashboardController::class, 'getConsolidatedReports']);
        Route::get('/super-transactions', [SuperAdminDashboardController::class, 'getGlobalTransactions']);
    
        
        // User Management
        Route::get('/users', [DashboardController::class, 'getUsers']);
        
        // Financial Reports
        Route::get('/reports/consolidated', [DashboardController::class, 'getConsolidatedReports']);
        Route::get('/transactions', [DashboardController::class, 'getGlobalTransactions']);

        // Audit Logs (all shops — AuditTrailController::index() detects the
        // super_admin role and drops the per-shop scope automatically)
        Route::get('/audit-logs', [AuditTrailController::class, 'index']);

        // Subscriptions (all shops)
        Route::get('/subscriptions', [SuperAdminSubscriptionsController::class, 'index']);
        Route::put('/shops/{id}/subscription', [SuperAdminSubscriptionsController::class, 'update']);
        Route::post('/shops/{id}/subscription/cancel', [SuperAdminSubscriptionsController::class, 'cancel']);

        // Backups
        Route::get('/backups', [SuperAdminBackupController::class, 'index']);
        Route::post('/backups', [SuperAdminBackupController::class, 'store']);
        Route::get('/backups/{filename}/download', [SuperAdminBackupController::class, 'download'])->where('filename', '.*');
        Route::delete('/backups/{filename}', [SuperAdminBackupController::class, 'destroy'])->where('filename', '.*');
    });
});