<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ShopController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\CustomerController;
use App\Http\Controllers\API\InvoiceController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\ExpenseController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\SyncController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ActiveLogController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuditTrailController;

// Public routes
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);

Route::post('/auth/register-shop', [AuthController::class, 'registerShop']); // Create shop + admin
Route::post('/auth/create-super-admin', [AuthController::class, 'createSuperAdmin']); 

// Protected routes
Route::middleware(['auth:sanctum'])->group(function () {
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
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
    Route::post('/invoices/{id}/pay', [InvoiceController::class, 'processPayment']);
    Route::post('/invoices/{id}/cancel', [InvoiceController::class, 'cancel']);
    Route::get('/invoices/{id}/receipt', [InvoiceController::class, 'generateReceipt']);
    
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
});