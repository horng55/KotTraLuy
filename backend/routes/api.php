<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\TelegramBotController;
use App\Http\Controllers\Api\TelegramLinkController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Controllers\Api\WasteController;
use App\Http\Controllers\Api\FinancingController;
use App\Http\Controllers\Api\InvitationController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', [TelegramBotController::class, 'webhook'])->middleware('throttle:120,1');
Route::post('/telegram/mini-app/auth', [TelegramLinkController::class, 'authenticateMiniApp'])->middleware('throttle:10,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::get('/invitations/{token}', [InvitationController::class, 'show'])->middleware('throttle:30,1');
Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:10,1');
Route::post('/invitations/{token}/accept-existing', [InvitationController::class, 'acceptExisting'])->middleware(['auth:sanctum', 'throttle:10,1']);
Route::get('/reports/pdf-download/{token}', [\App\Http\Controllers\Api\PdfExportController::class, 'download'])
    ->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:30,1')->name('reports.pdf.download');

Route::middleware(['auth:sanctum', 'workspace.context'])->group(function () {
    Route::get('/workspaces', [\App\Http\Controllers\Api\WorkspaceController::class, 'index']);
    Route::post('/workspaces/{business}/switch', [\App\Http\Controllers\Api\WorkspaceController::class, 'switch']);
    Route::get('/permissions', [\App\Http\Controllers\Api\WorkspaceController::class, 'permissions'])->middleware('permission:team.manage');
    Route::put('/users/{user}/permissions', [\App\Http\Controllers\Api\WorkspaceController::class, 'updatePermissions'])->middleware('permission:team.manage');
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [UserManagementController::class, 'changePassword']);

    Route::middleware('password.changed')->group(function () {
        Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [\App\Http\Controllers\Api\NotificationController::class, 'count']);
        Route::post('/notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'readAll']);
        Route::patch('/notifications/{notification}/read', [\App\Http\Controllers\Api\NotificationController::class, 'read']);
        Route::patch('/notifications/{notification}/unread', [\App\Http\Controllers\Api\NotificationController::class, 'unread']);
        Route::middleware('permission:team.manage')->group(function () {
            Route::get('/users', [UserManagementController::class, 'index']);
            Route::get('/invitations', [InvitationController::class, 'index']);
            Route::post('/invitations', [InvitationController::class, 'store']);
            Route::post('/invitations/{invitation}/revoke', [InvitationController::class, 'revoke']);
            Route::post('/users', [UserManagementController::class, 'store']);
            Route::patch('/users/{user}', [UserManagementController::class, 'update']);
            Route::post('/users/{user}/reset-password', [UserManagementController::class, 'resetPassword']);
            Route::post('/users/{user}/status', [UserManagementController::class, 'setStatus']);
        });
        Route::middleware('permission:telegram.link')->group(function () {
            Route::post('/telegram/link-code', [TelegramLinkController::class, 'createCode'])->middleware('throttle:5,1');
            Route::get('/telegram/status', [TelegramLinkController::class, 'status'])->middleware('throttle:30,1');
            Route::post('/telegram/test-notification', [TelegramLinkController::class, 'sendTestNotification'])->middleware('throttle:3,1');
            Route::delete('/telegram/link', [TelegramLinkController::class, 'disconnect'])->middleware('throttle:5,1');
        });
        Route::middleware('permission:telegram.settings')->group(function () {
            Route::get('/telegram/notification-settings', [TelegramLinkController::class, 'notificationSettings']);
            Route::put('/telegram/notification-settings', [TelegramLinkController::class, 'updateNotificationSettings']);
        });

        Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('permission:dashboard.personal');
        Route::get('/my-dashboard', [DashboardController::class, 'personal'])->middleware('permission:dashboard.personal');

        Route::get('/inventory-expiration', [\App\Http\Controllers\Api\ExpirationController::class, 'index'])->middleware('permission:inventory.read');
        Route::put('/inventory-expiration/settings', [\App\Http\Controllers\Api\ExpirationController::class, 'settings'])->middleware('permission:inventory.settings');
        Route::patch('/stock-batches/{batch}', [\App\Http\Controllers\Api\ExpirationController::class, 'update'])->middleware('permission:inventory.manage');
        Route::post('/stock-batches/{batch}/dispose', [\App\Http\Controllers\Api\ExpirationController::class, 'dispose'])->middleware('permission:inventory.dispose');
        Route::apiResource('/products', ProductController::class)->only(['index', 'show'])->middleware('permission:inventory.read');
        Route::apiResource('/products', ProductController::class)->only(['store', 'update', 'destroy'])->middleware('permission:inventory.manage');
        Route::get('/products/{product}/image', [ProductController::class, 'image'])->middleware('permission:inventory.read');
        Route::apiResource('/purchases', PurchaseController::class)->only(['index', 'show'])->middleware(['permission:purchases.manage', 'permission:reports.financial']);
        Route::apiResource('/purchases', PurchaseController::class)->only(['store', 'update', 'destroy'])->middleware('permission:purchases.manage');
        Route::get('/sales', [SaleController::class, 'index'])->middleware('permission:sales.read.own');
        Route::post('/sales', [SaleController::class, 'store'])->middleware('permission:sales.create');
        Route::get('/sales/{sale}', [SaleController::class, 'show'])->middleware('permission:sales.read.own');
        Route::patch('/sales/{sale}', [SaleController::class, 'update'])->middleware('permission:sales.modify');
        Route::delete('/sales/{sale}', [SaleController::class, 'destroy'])->middleware('permission:sales.modify');
        Route::post('/sales/{sale}/payments', [SaleController::class, 'addPayment'])->middleware('permission:sales.modify');
        Route::get('/inventory-alerts', [ProductController::class, 'alerts'])->middleware('permission:inventory.read');
        Route::apiResource('/expenses', ExpenseController::class)->middleware('permission:expenses.manage');
        Route::apiResource('/shifts', ShiftController::class)->middleware('permission:shifts.own');
        Route::post('/shifts/{shift}/close', [ShiftController::class, 'close'])->middleware('permission:shifts.own');
        Route::post('/waste', [WasteController::class, 'store'])->middleware('permission:waste.manage');

        Route::middleware('permission:reports.operational')->group(function () {
            Route::get('/reports/daily', [ReportController::class, 'daily']);
            Route::get('/reports/monthly', [ReportController::class, 'monthly']);
            Route::get('/reports/range', [ReportController::class, 'range']);
            Route::get('/reports/range.csv', [ReportController::class, 'csv']);
            Route::get('/reports/sales.xlsx', [ReportController::class, 'salesExcel']);
        });
        Route::middleware('permission:reports.financial')->group(function () {
            Route::get('/reports/financial', [ReportController::class, 'financial']);
            Route::get('/reports/financial.xlsx', [ReportController::class, 'financialExcel']);
        });
        Route::post('/reports/pdf-download', [\App\Http\Controllers\Api\PdfExportController::class, 'store'])->middleware(['throttle:10,1', 'permission:reports.financial']);
        Route::middleware('permission:financing')->group(function () {
            Route::get('/financing/overview', [FinancingController::class, 'overview']);
            Route::get('/financing/products', [FinancingController::class, 'products']);
            Route::put('/financing/profile', [FinancingController::class, 'saveProfile']);
            Route::put('/financing/business', [FinancingController::class, 'updateBusiness']);
            Route::put('/financing/documents/{documentKey}', [FinancingController::class, 'updateDocument']);
            Route::get('/financing/packs', [FinancingController::class, 'packs']);
            Route::post('/financing/packs', [FinancingController::class, 'createPack']);
        });
    });
});
