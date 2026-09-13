<?php

use App\Http\Controllers\Api\Cashier\V1\AuthController;
use App\Http\Controllers\Api\Cashier\V1\BootstrapController;
use App\Http\Controllers\Api\Cashier\V1\CatalogController;
use App\Http\Controllers\Api\Cashier\V1\CustomerController;
use App\Http\Controllers\Api\Cashier\V1\DeviceController;
use App\Http\Controllers\Api\Cashier\V1\InvoiceController;
use App\Http\Controllers\Api\Cashier\V1\KitchenController;
use App\Http\Controllers\Api\Cashier\V1\OrderController;
use App\Http\Controllers\Api\Cashier\V1\PlanController;
use App\Http\Controllers\Api\Cashier\V1\ReportController;
use App\Http\Controllers\Api\Cashier\V1\ReturnController;
use App\Http\Controllers\Api\Cashier\V1\SettingsController;
use App\Http\Controllers\Api\Cashier\V1\SyncController;
use App\Http\Controllers\Api\Cashier\V1\TableController;
use App\Http\Controllers\Api\Cashier\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Hasim Cashier API v1 — كاشير حاسم
|--------------------------------------------------------------------------
| Paths: /api/cashier/v1/...
| Independent from Hasim Chat Mobile API (/api/mobile/v1).
| Reuses Sanctum auth + PosOrderService business logic.
*/

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:mobile-login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:mobile-login');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:mobile-login');
    Route::post('/social', [AuthController::class, 'social'])->middleware('throttle:mobile-login');
    Route::post('/google/start', [AuthController::class, 'googleStart'])->middleware('throttle:mobile-login');
    Route::get('/google/status', [AuthController::class, 'googleStatus'])->middleware('throttle:cashier-api');
});

Route::middleware(['auth:sanctum', 'throttle:cashier-api'])->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/workspaces', [WorkspaceController::class, 'index']);
});

Route::middleware([
    'auth:sanctum',
    'workspace.resolve',
    'workspace.member',
    'mobile.idempotency',
    'throttle:cashier-api',
])->group(function (): void {
    Route::get('/bootstrap', BootstrapController::class);
    Route::get('/workspaces/current', [WorkspaceController::class, 'current']);
    Route::post('/workspaces/switch', [WorkspaceController::class, 'switch'])->middleware('throttle:mobile-write');

    Route::post('/devices/register', [DeviceController::class, 'register'])->middleware('throttle:mobile-write');
    Route::get('/sync/changes', [SyncController::class, 'changes']);
    Route::post('/sync/push', [SyncController::class, 'push'])->middleware('throttle:mobile-write');
    Route::post('/sync/pull', [SyncController::class, 'pull']);

    Route::get('/plan', [PlanController::class, 'current']);
    Route::get('/plans', [PlanController::class, 'index']);

    Route::get('/catalog/categories', [CatalogController::class, 'categories']);
    Route::post('/catalog/categories', [CatalogController::class, 'storeCategory'])->middleware('throttle:mobile-write');
    Route::put('/catalog/categories/{category}', [CatalogController::class, 'updateCategory'])->middleware('throttle:mobile-write');
    Route::delete('/catalog/categories/{category}', [CatalogController::class, 'destroyCategory'])->middleware('throttle:mobile-write');

    Route::get('/catalog/items', [CatalogController::class, 'items']);
    Route::post('/catalog/items', [CatalogController::class, 'storeItem'])->middleware('throttle:mobile-write');
    Route::get('/catalog/items/{item}', [CatalogController::class, 'show']);
    Route::put('/catalog/items/{item}', [CatalogController::class, 'updateItem'])->middleware('throttle:mobile-write');
    Route::delete('/catalog/items/{item}', [CatalogController::class, 'destroyItem'])->middleware('throttle:mobile-write');

    Route::patch('/settings/pos', [SettingsController::class, 'updatePos'])->middleware('throttle:mobile-write');
    Route::post('/settings/menu-slider', [SettingsController::class, 'updateMenuSlider'])->middleware('throttle:mobile-write');

    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store'])->middleware('throttle:mobile-write');

    Route::get('/kitchen/orders', [KitchenController::class, 'index']);
    Route::get('/reports/daily', [ReportController::class, 'daily']);

    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/recent-menu', [OrderController::class, 'recentMenu'])->middleware('throttle:mobile-api');
    Route::get('/orders/channel-stats', [OrderController::class, 'channelStats']);
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:mobile-write');
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/status', [OrderController::class, 'updateStatus'])->middleware('throttle:mobile-write');
    Route::post('/orders/{order}/items', [OrderController::class, 'updateItems'])->middleware('throttle:mobile-write');
    Route::delete('/orders/{order}', [OrderController::class, 'destroy'])->middleware('throttle:mobile-write');
    Route::post('/orders/{order}/invoice', [OrderController::class, 'createInvoice'])->middleware('throttle:mobile-write');
    Route::post('/orders/{order}/payment-link', [OrderController::class, 'createPaymentLink'])->middleware('throttle:mobile-write');
    Route::post('/orders/{order}/returns', [ReturnController::class, 'store'])->middleware('throttle:mobile-write');
    Route::post('/returns/{return}/refund', [ReturnController::class, 'markRefunded'])->middleware('throttle:mobile-write');

    Route::get('/tables', [TableController::class, 'index']);
    Route::post('/tables', [TableController::class, 'store'])->middleware('throttle:mobile-write');
    Route::get('/tables/{table}', [TableController::class, 'show']);
    Route::get('/tables/{table}/sessions', [TableController::class, 'sessions']);
    Route::put('/tables/{table}', [TableController::class, 'update'])->middleware('throttle:mobile-write');
    Route::post('/tables/{table}/qr/regenerate', [TableController::class, 'regenerateQr'])->middleware('throttle:mobile-write');
    Route::post('/tables/{table}/sessions/open', [TableController::class, 'openSession'])->middleware('throttle:mobile-write');
    Route::post('/tables/{table}/sessions/{session}/close', [TableController::class, 'closeSession'])->middleware('throttle:mobile-write');
    Route::post('/tables/{table}/sessions/{session}/cancel', [TableController::class, 'cancelSession'])->middleware('throttle:mobile-write');
    Route::post('/tables/{table}/sessions/{session}/transfer', [TableController::class, 'transfer'])->middleware('throttle:mobile-write');
    Route::post('/tables/{table}/sessions/{session}/merge', [TableController::class, 'merge'])->middleware('throttle:mobile-write');
    Route::post('/tables/{table}/sessions/{session}/split', [TableController::class, 'split'])->middleware('throttle:mobile-write');
    Route::post('/tables/{table}/sessions/{session}/discount', [TableController::class, 'applyDiscount'])->middleware('throttle:mobile-write');
    Route::post('/tables/{table}/sessions/{session}/note', [TableController::class, 'updateNote'])->middleware('throttle:mobile-write');

    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('throttle:mobile-write');
});
