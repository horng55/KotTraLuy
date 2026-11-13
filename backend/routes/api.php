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
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', [TelegramBotController::class, 'webhook'])->middleware('throttle:120,1');
Route::post('/telegram/mini-app/auth', [TelegramLinkController::class, 'authenticateMiniApp'])->middleware('throttle:10,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [UserManagementController::class, 'changePassword']);

    Route::middleware('password.changed')->group(function () {
        Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [\App\Http\Controllers\Api\NotificationController::class, 'count']);
        Route::post('/notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'readAll']);
        Route::patch('/notifications/{notification}/read', [\App\Http\Controllers\Api\NotificationController::class, 'read']);
        Route::patch('/notifications/{notification}/unread', [\App\Http\Controllers\Api\NotificationController::class, 'unread']);
        Route::get('/users', [UserManagementController::class, 'index']);
        Route::post('/users', [UserManagementController::class, 'store']);
        Route::patch('/users/{user}', [UserManagementController::class, 'update']);
        Route::post('/users/{user}/reset-password', [UserManagementController::class, 'resetPassword']);
        Route::post('/users/{user}/status', [UserManagementController::class, 'setStatus']);
        Route::post('/telegram/link-code', [TelegramLinkController::class, 'createCode'])->middleware('throttle:5,1');
        Route::get('/telegram/status', [TelegramLinkController::class, 'status'])->middleware('throttle:30,1');
        Route::delete('/telegram/link', [TelegramLinkController::class, 'disconnect'])->middleware('throttle:5,1');
        Route::get('/telegram/notification-settings', [TelegramLinkController::class, 'notificationSettings']);
        Route::put('/telegram/notification-settings', [TelegramLinkController::class, 'updateNotificationSettings']);
        Route::post('/telegram/test-notification', [TelegramLinkController::class, 'sendTestNotification'])->middleware('throttle:3,1');

        Route::get('/dashboard', [DashboardController::class, 'index']);

        Route::get('/inventory-expiration', [\App\Http\Controllers\Api\ExpirationController::class, 'index']);
        Route::put('/inventory-expiration/settings', [\App\Http\Controllers\Api\ExpirationController::class, 'settings']);
        Route::patch('/stock-batches/{batch}', [\App\Http\Controllers\Api\ExpirationController::class, 'update']);
        Route::post('/stock-batches/{batch}/dispose', [\App\Http\Controllers\Api\ExpirationController::class, 'dispose']);
        Route::apiResource('/products', ProductController::class);
        Route::get('/products/{product}/image', [ProductController::class, 'image']);
        Route::apiResource('/purchases', PurchaseController::class);
        Route::apiResource('/sales', SaleController::class);
        Route::post('/sales/{sale}/payments', [SaleController::class, 'addPayment']);
        Route::get('/inventory-alerts', [ProductController::class, 'alerts']);
        Route::apiResource('/expenses', ExpenseController::class);
        Route::apiResource('/shifts', ShiftController::class);
        Route::post('/shifts/{shift}/close', [ShiftController::class, 'close']);
        Route::post('/waste', [WasteController::class, 'store']);

        Route::get('/reports/daily', [ReportController::class, 'daily']);
        Route::get('/reports/monthly', [ReportController::class, 'monthly']);
        Route::get('/reports/range', [ReportController::class, 'range']);
        Route::get('/reports/range.csv', [ReportController::class, 'csv']);
        Route::get('/reports/financial', [ReportController::class, 'financial']);
        Route::get('/reports/financial.xlsx', [ReportController::class, 'financialExcel']);
        Route::get('/financing/overview', [FinancingController::class, 'overview']);
        Route::get('/financing/products', [FinancingController::class, 'products']);
        Route::put('/financing/profile', [FinancingController::class, 'saveProfile']);
        Route::put('/financing/business', [FinancingController::class, 'updateBusiness']);
        Route::put('/financing/documents/{documentKey}', [FinancingController::class, 'updateDocument']);
        Route::get('/financing/packs', [FinancingController::class, 'packs']);
        Route::post('/financing/packs', [FinancingController::class, 'createPack']);
    });
});
