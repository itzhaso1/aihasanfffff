<?php

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Cashier\V1\SyncController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RolePermissionController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\WhatsAppController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Webhook\PaymentWebhookController;
use App\Http\Controllers\Webhook\SubscriptionWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:5,1');
    Route::post('/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
    Route::post('/social/token', [SocialAuthController::class, 'exchangeToken'])->middleware('throttle:10,1');
});

Route::middleware(['auth:sanctum', 'throttle:legacy-api'])->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/employee-invitations/{token}/accept', [EmployeeController::class, 'acceptInvitation']);

    Route::get('/workspaces', [WorkspaceController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'workspace.resolve', 'workspace.member', 'throttle:legacy-api'])
    ->group(function (): void {
        Route::get('/workspace/{workspace}/current', [WorkspaceController::class, 'current']);

        Route::apiResource('/categories', CategoryController::class);
        Route::apiResource('/customers', CustomerController::class);
        Route::apiResource('/conversations', ConversationController::class);
        Route::get('/messages', [MessageController::class, 'index']);
        Route::post('/messages', [MessageController::class, 'store'])->middleware('throttle:mobile-messages');
        Route::get('/messages/{message}', [MessageController::class, 'show']);
        Route::apiResource('/payments', PaymentController::class)->only(['index', 'store', 'show']);
        Route::get('/subscriptions', [SubscriptionController::class, 'index']);
        Route::get('/subscriptions/plans', [SubscriptionController::class, 'plans']);
        Route::get('/subscriptions/current', [SubscriptionController::class, 'current']);
        Route::post('/subscriptions/checkout', [SubscriptionController::class, 'checkout'])
            ->middleware('throttle:subscription-billing');
        Route::get('/subscriptions/checkout/{checkoutSession}', [SubscriptionController::class, 'showCheckout']);
        Route::post('/subscriptions/change-plan', [SubscriptionController::class, 'changePlan'])
            ->middleware('throttle:subscription-billing');
        Route::post('/subscriptions/cancel', [SubscriptionController::class, 'cancel'])
            ->middleware('throttle:subscription-billing');
        Route::post('/subscriptions', [SubscriptionController::class, 'store'])
            ->middleware('throttle:subscription-billing');

        Route::get('/inventory/movements', [InventoryController::class, 'index']);
        Route::post('/inventory/adjust', [InventoryController::class, 'adjust']);

        Route::middleware('workspace.feature:products')->group(function (): void {
            Route::apiResource('/products', ProductController::class);
        });

        Route::middleware('workspace.feature:orders')->group(function (): void {
            Route::apiResource('/orders', OrderController::class);
        });

        Route::middleware('workspace.feature:ai')->group(function (): void {
            Route::get('/ai/settings', [AiController::class, 'settings']);
            Route::put('/ai/settings', [AiController::class, 'updateSettings']);
            Route::post('/ai/reply', [AiController::class, 'generateReply'])
                ->middleware('throttle:mobile-ai');
        });

        Route::middleware('workspace.feature:whatsapp')->group(function (): void {
            Route::get('/whatsapp/accounts', [WhatsAppController::class, 'index']);
            Route::post('/whatsapp/accounts', [WhatsAppController::class, 'storeAccount']);
            Route::post('/whatsapp/phone-numbers', [WhatsAppController::class, 'storePhoneNumber']);
        });

        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::post('/employees/invite', [EmployeeController::class, 'invite']);
        Route::patch('/employees/{membership}', [EmployeeController::class, 'update']);
        Route::delete('/employees/{membership}', [EmployeeController::class, 'destroy']);

        Route::get('/roles-permissions', [RolePermissionController::class, 'index']);
        Route::post('/roles-permissions/assign-role', [RolePermissionController::class, 'assignRole']);
        Route::post('/roles-permissions/sync-permissions', [RolePermissionController::class, 'syncPermissions']);

    });

Route::post('/webhooks/payments/{provider}', [PaymentWebhookController::class, 'handle'])
    ->middleware('throttle:120,1');

Route::post('/webhooks/subscriptions/{provider}', [SubscriptionWebhookController::class, 'handle'])
    ->middleware('throttle:subscription-billing');

/*
|--------------------------------------------------------------------------
| Hasim Mobile API v1
|--------------------------------------------------------------------------
| Final paths: /api/mobile/v1/...
| (Laravel already prefixes this file with /api + api middleware.)
*/
Route::prefix('mobile/v1')
    ->name('mobile.v1.')
    ->group(base_path('routes/mobile.php'));

/*
|--------------------------------------------------------------------------
| Hasim Cashier API v1 — كاشير حاسم
|--------------------------------------------------------------------------
| Final paths: /api/cashier/v1/...
| Separate product surface from Hasim Chat mobile API.
*/
Route::prefix('cashier/v1')
    ->name('cashier.v1.')
    ->group(base_path('routes/cashier.php'));

/*
|--------------------------------------------------------------------------
| Finance API v1 — Hasim Finance Flutter + e-invoice clients
|--------------------------------------------------------------------------
| Final paths: /api/finance/v1/...
| Laravel remains the source of truth for identity, tax, snapshot, XML,
| compliance, payments, and security state.
*/
Route::prefix('finance/v1')
    ->name('finance.v1.')
    ->group(function (): void {
        Route::post('/auth/login', [App\Http\Controllers\Api\Finance\V1\AuthController::class, 'login'])
            ->middleware('throttle:mobile-login');
        Route::post('/auth/google', [App\Http\Controllers\Api\Finance\V1\AuthController::class, 'google'])
            ->middleware('throttle:mobile-login');
        Route::post('/auth/social', [App\Http\Controllers\Api\Finance\V1\AuthController::class, 'social'])
            ->middleware('throttle:mobile-login');
        Route::post('/auth/google/start', [App\Http\Controllers\Api\Finance\V1\AuthController::class, 'googleStart'])
            ->middleware('throttle:mobile-login');
        Route::get('/auth/google/status', [App\Http\Controllers\Api\Finance\V1\AuthController::class, 'googleStatus'])
            ->middleware('throttle:cashier-api');
        Route::post('/auth/forgot-password', [App\Http\Controllers\Api\Finance\V1\AuthController::class, 'forgotPassword'])
            ->middleware('throttle:mobile-login');
        Route::post('/auth/reset-password', [App\Http\Controllers\Api\Finance\V1\AuthController::class, 'resetPassword'])
            ->middleware('throttle:mobile-login');

        Route::middleware(['auth:sanctum', 'throttle:cashier-api'])->group(function (): void {
            Route::post('/auth/logout', [App\Http\Controllers\Api\Finance\V1\AuthController::class, 'logout']);
            Route::get('/auth/me', [App\Http\Controllers\Api\Finance\V1\AuthController::class, 'me']);
            Route::get('/workspaces', [App\Http\Controllers\Api\Finance\V1\WorkspaceController::class, 'index']);
        });

        Route::middleware([
            'auth:sanctum',
            'workspace.resolve',
            'workspace.member',
            'workspace.feature:finance',
            'throttle:cashier-api',
        ])->group(base_path('routes/finance-api.php'));
    });

/*
|--------------------------------------------------------------------------
| POS sync aliases — same handlers as /api/cashier/v1/sync/*
|--------------------------------------------------------------------------
*/
Route::prefix('pos/sync')
    ->name('pos.sync.')
    ->middleware([
        'auth:sanctum',
        'workspace.resolve',
        'workspace.member',
        'mobile.idempotency',
        'throttle:cashier-api',
    ])
    ->group(function (): void {
        Route::post('/push', [SyncController::class, 'push'])->middleware('throttle:mobile-write');
        Route::post('/pull', [SyncController::class, 'pull']);
    });
