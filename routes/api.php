<?php

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\PaymentController;
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
