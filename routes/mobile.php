<?php

use App\Http\Controllers\Api\Mobile\V1\AiController;
use App\Http\Controllers\Api\Mobile\V1\AiSettingsController;
use App\Http\Controllers\Api\Mobile\V1\AttachmentController;
use App\Http\Controllers\Api\Mobile\V1\AuthController;
use App\Http\Controllers\Api\Mobile\V1\BrandingController;
use App\Http\Controllers\Api\Mobile\V1\ChannelController;
use App\Http\Controllers\Api\Mobile\V1\ChatCatalogController;
use App\Http\Controllers\Api\Mobile\V1\ChatCatalogDigitalInventoryController;
use App\Http\Controllers\Api\Mobile\V1\ChatCatalogSettingsController;
use App\Http\Controllers\Api\Mobile\V1\CommerceCartController;
use App\Http\Controllers\Api\Mobile\V1\CommerceIntelligenceController;
use App\Http\Controllers\Api\Mobile\V1\CommerceOrderController;
use App\Http\Controllers\Api\Mobile\V1\ConversationController;
use App\Http\Controllers\Api\Mobile\V1\CustomerController;
use App\Http\Controllers\Api\Mobile\V1\DeviceController;
use App\Http\Controllers\Api\Mobile\V1\DeviceSessionController;
use App\Http\Controllers\Api\Mobile\V1\EmailCampaignController;
use App\Http\Controllers\Api\Mobile\V1\EmailContactController;
use App\Http\Controllers\Api\Mobile\V1\EmailContactGroupController;
use App\Http\Controllers\Api\Mobile\V1\EmailController;
use App\Http\Controllers\Api\Mobile\V1\HomeController;
use App\Http\Controllers\Api\Mobile\V1\NotificationController;
use App\Http\Controllers\Api\Mobile\V1\PlanController;
use App\Http\Controllers\Api\Mobile\V1\RealtimeController;
use App\Http\Controllers\Api\Mobile\V1\SearchController;
use App\Http\Controllers\Api\Mobile\V1\StoryController;
use App\Http\Controllers\Api\Mobile\V1\UnreadController;
use App\Http\Controllers\Api\Mobile\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:mobile-login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:mobile-login');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:mobile-login');
    Route::post('/social', [AuthController::class, 'social'])->middleware('throttle:mobile-login');
});

Route::middleware(['auth:sanctum', 'throttle:mobile-api'])->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('/auth/password', [AuthController::class, 'updatePassword']);
    Route::post('/auth/avatar', [AuthController::class, 'uploadAvatar'])->middleware('throttle:mobile-write');

    Route::get('/sessions', [DeviceSessionController::class, 'index']);
    Route::delete('/sessions/{tokenId}', [DeviceSessionController::class, 'destroy']);
    Route::delete('/sessions', [DeviceSessionController::class, 'destroyAll']);

    Route::get('/workspaces', [WorkspaceController::class, 'index']);
});

Route::middleware([
    'auth:sanctum',
    'workspace.resolve',
    'workspace.member',
    'mobile.idempotency',
    'throttle:mobile-api',
])->group(function (): void {
    Route::get('/workspaces/current', [WorkspaceController::class, 'current']);
    Route::post('/workspaces/switch', [WorkspaceController::class, 'switch'])->middleware('throttle:mobile-write');

    Route::post('/devices', [DeviceController::class, 'store'])->middleware('throttle:mobile-write');
    Route::delete('/devices/{device}', [DeviceController::class, 'destroy']);

    Route::get('/realtime/config', [RealtimeController::class, 'config']);

    Route::get('/home', [HomeController::class, 'index']);
    Route::get('/unread', [UnreadController::class, 'index']);
    Route::get('/search', [SearchController::class, 'search'])->middleware('throttle:mobile-search');

    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
    Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'storeMessage'])
        ->middleware('throttle:mobile-messages');
    Route::post('/conversations/{conversation}/read', [ConversationController::class, 'read']);
    Route::post('/conversations/{conversation}/archive', [ConversationController::class, 'archive']);
    Route::post('/conversations/{conversation}/mute', [ConversationController::class, 'mute']);

    Route::post('/messages/{message}/attachments', [AttachmentController::class, 'store'])
        ->middleware('throttle:mobile-attachments');
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->name('attachments.download')
        ->middleware(['signed', 'throttle:mobile-attachments']);

    Route::middleware('workspace.feature:email')->group(function (): void {
        Route::get('/emails/accounts', [EmailController::class, 'accounts']);
        Route::get('/emails/inbox', [EmailController::class, 'inbox']);
        Route::get('/emails/sent', [EmailController::class, 'sent']);
        Route::get('/emails/drafts', [EmailController::class, 'drafts']);
        Route::get('/emails/attachments/{emailAttachment}/download', [EmailController::class, 'downloadAttachment']);
        Route::get('/emails/{emailMessage}', [EmailController::class, 'show']);
        Route::post('/emails', [EmailController::class, 'send'])->middleware('throttle:mobile-email');
        Route::post('/emails/{emailMessage}/read', [EmailController::class, 'read']);
        Route::post('/emails/{emailMessage}/star', [EmailController::class, 'star']);

        Route::post('/email/campaigns', [EmailCampaignController::class, 'store'])
            ->middleware('throttle:mobile-email');
        Route::get('/email/campaigns/{campaign}', [EmailCampaignController::class, 'show']);
        Route::post('/email/campaigns/{campaign}/cancel', [EmailCampaignController::class, 'cancel'])
            ->middleware('throttle:mobile-write');

        Route::get('/contacts/recent-recipients', [EmailContactController::class, 'recentRecipients']);
        Route::get('/contacts', [EmailContactController::class, 'index']);
        Route::post('/contacts', [EmailContactController::class, 'store'])->middleware('throttle:mobile-write');
        Route::get('/contacts/{contact}', [EmailContactController::class, 'show']);
        Route::patch('/contacts/{contact}', [EmailContactController::class, 'update'])->middleware('throttle:mobile-write');
        Route::delete('/contacts/{contact}', [EmailContactController::class, 'destroy'])->middleware('throttle:mobile-write');
        Route::post('/contacts/{contact}/favorite', [EmailContactController::class, 'favorite'])
            ->middleware('throttle:mobile-write');

        Route::get('/contact-groups', [EmailContactGroupController::class, 'index']);
        Route::post('/contact-groups', [EmailContactGroupController::class, 'store'])->middleware('throttle:mobile-write');
        Route::patch('/contact-groups/{group}', [EmailContactGroupController::class, 'update'])
            ->middleware('throttle:mobile-write');
        Route::delete('/contact-groups/{group}', [EmailContactGroupController::class, 'destroy'])
            ->middleware('throttle:mobile-write');
        Route::post('/contact-groups/{group}/members', [EmailContactGroupController::class, 'syncMembers'])
            ->middleware('throttle:mobile-write');
    });

    Route::get('/stories', [StoryController::class, 'index']);
    Route::post('/stories', [StoryController::class, 'store'])->middleware('throttle:mobile-attachments');
    Route::get('/stories/{story}', [StoryController::class, 'show']);
    Route::post('/stories/{story}/view', [StoryController::class, 'view'])->middleware('throttle:mobile-write');
    Route::delete('/stories/{story}', [StoryController::class, 'destroy'])->middleware('throttle:mobile-write');
    Route::get('/stories/{story}/viewers', [StoryController::class, 'viewers']);

    Route::get('/channels', [ChannelController::class, 'index']);
    Route::get('/plan', [PlanController::class, 'current']);
    Route::get('/plans', [PlanController::class, 'index']);
    Route::get('/branding', [BrandingController::class, 'show']);

    Route::middleware('workspace.feature:products')->group(function (): void {
        Route::get('/catalog/settings', [ChatCatalogSettingsController::class, 'show']);
        Route::patch('/catalog/settings', [ChatCatalogSettingsController::class, 'update'])->middleware('throttle:mobile-write');
        Route::patch('/catalog/settings/communication', [ChatCatalogSettingsController::class, 'updateCommunication'])->middleware('throttle:mobile-write');
        Route::post('/catalog/settings/publish', [ChatCatalogSettingsController::class, 'publish'])->middleware('throttle:mobile-write');
        Route::post('/catalog/settings/draft', [ChatCatalogSettingsController::class, 'draft'])->middleware('throttle:mobile-write');
        Route::post('/catalog/settings/pause', [ChatCatalogSettingsController::class, 'pause'])->middleware('throttle:mobile-write');
        Route::post('/catalog/settings/archive', [ChatCatalogSettingsController::class, 'archive'])->middleware('throttle:mobile-write');
        Route::get('/catalog/digital-pools', [ChatCatalogDigitalInventoryController::class, 'pools']);
        Route::post('/catalog/digital-pools', [ChatCatalogDigitalInventoryController::class, 'storePool'])->middleware('throttle:mobile-write');
        Route::get('/catalog/digital-pools/{pool}/units', [ChatCatalogDigitalInventoryController::class, 'units']);
        Route::post('/catalog/digital-pools/{pool}/units/import', [ChatCatalogDigitalInventoryController::class, 'importUnits'])->middleware('throttle:mobile-write');
        Route::post('/catalog/digital-pools/{pool}/reserve', [ChatCatalogDigitalInventoryController::class, 'reserve'])->middleware('throttle:mobile-write');
        Route::post('/catalog/digital-units/{unit}/release', [ChatCatalogDigitalInventoryController::class, 'release'])->middleware('throttle:mobile-write');
        Route::post('/catalog/digital-units/{unit}/consume', [ChatCatalogDigitalInventoryController::class, 'consume'])->middleware('throttle:mobile-write');
        Route::patch('/catalog/digital-units/{unit}/status', [ChatCatalogDigitalInventoryController::class, 'transition'])->middleware('throttle:mobile-write');
        Route::patch('/catalog/digital-units/{unit}/secret', [ChatCatalogDigitalInventoryController::class, 'replaceSecret'])->middleware('throttle:mobile-write');
        Route::get('/catalog/search', [ChatCatalogController::class, 'search'])->middleware('throttle:mobile-search');
        Route::get('/catalog/categories', [ChatCatalogController::class, 'categories']);
        Route::post('/catalog/categories', [ChatCatalogController::class, 'storeCategory'])->middleware('throttle:mobile-write');
        Route::patch('/catalog/categories/{category}', [ChatCatalogController::class, 'updateCategory'])->middleware('throttle:mobile-write');
        Route::delete('/catalog/categories/{category}', [ChatCatalogController::class, 'destroyCategory'])->middleware('throttle:mobile-write');
        Route::get('/catalog', [ChatCatalogController::class, 'index']);
        Route::post('/catalog', [ChatCatalogController::class, 'store'])->middleware('throttle:mobile-write');
        Route::get('/catalog/{item}', [ChatCatalogController::class, 'show']);
        Route::patch('/catalog/{item}', [ChatCatalogController::class, 'update'])->middleware('throttle:mobile-write');
        Route::patch('/catalog/{item}/inventory', [ChatCatalogController::class, 'updateInventory'])->middleware('throttle:mobile-write');
        Route::post('/catalog/{item}/activate', [ChatCatalogController::class, 'activate'])->middleware('throttle:mobile-write');
        Route::post('/catalog/{item}/deactivate', [ChatCatalogController::class, 'deactivate'])->middleware('throttle:mobile-write');
        Route::delete('/catalog/{item}', [ChatCatalogController::class, 'destroy'])->middleware('throttle:mobile-write');
    });

    Route::middleware(['workspace.feature:products', 'workspace.feature:orders'])->group(function (): void {
        Route::post('/commerce/carts', [CommerceCartController::class, 'store'])->middleware('throttle:mobile-write');
        Route::get('/commerce/carts/{cart}', [CommerceCartController::class, 'show']);
        Route::post('/commerce/carts/{cart}/items', [CommerceCartController::class, 'addItem'])->middleware('throttle:mobile-write');
        Route::patch('/commerce/carts/{cart}/items/{cartItem}', [CommerceCartController::class, 'updateItem'])->middleware('throttle:mobile-write');
        Route::delete('/commerce/carts/{cart}/items/{cartItem}', [CommerceCartController::class, 'removeItem'])->middleware('throttle:mobile-write');
        Route::post('/commerce/carts/{cart}/clear', [CommerceCartController::class, 'clear'])->middleware('throttle:mobile-write');
        Route::post('/commerce/carts/{cart}/validate', [CommerceCartController::class, 'validateCart'])->middleware('throttle:mobile-write');
        Route::post('/commerce/carts/{cart}/cancel', [CommerceCartController::class, 'cancel'])->middleware('throttle:mobile-write');

        Route::post('/commerce/carts/{cart}/order-preview', [CommerceOrderController::class, 'preview'])->middleware('throttle:mobile-write');
        Route::post('/commerce/carts/{cart}/convert', [CommerceOrderController::class, 'convert'])->middleware('throttle:mobile-write');
        Route::get('/commerce/orders', [CommerceOrderController::class, 'index']);
        Route::get('/commerce/orders/{order}', [CommerceOrderController::class, 'show']);
        Route::post('/commerce/orders/{order}/cancel', [CommerceOrderController::class, 'cancel'])->middleware('throttle:mobile-write');

        Route::get('/commerce/intelligence/profile', [CommerceIntelligenceController::class, 'getProfile']);
        Route::get('/commerce/intelligence/attributes', [CommerceIntelligenceController::class, 'getAttributes']);
        Route::get('/commerce/intelligence/requirements', [CommerceIntelligenceController::class, 'getRequirements']);
        Route::get('/commerce/intelligence/items/{item}', [CommerceIntelligenceController::class, 'getItemIntelligence']);
        Route::post('/commerce/intelligence/evaluate', [CommerceIntelligenceController::class, 'evaluate'])->middleware('throttle:mobile-write');
    });

    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::get('/customers/{customer}/conversations', [CustomerController::class, 'conversations']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::get('/notification-preferences', [NotificationController::class, 'preferences']);
    Route::put('/notification-preferences', [NotificationController::class, 'updatePreferences']);

    Route::middleware('workspace.feature:ai')->group(function (): void {
        Route::get('/ai/settings', [AiSettingsController::class, 'show']);
        Route::put('/ai/settings', [AiSettingsController::class, 'update'])->middleware('throttle:mobile-write');
        Route::post('/ai/suggest-reply', [AiController::class, 'suggestReply'])->middleware('throttle:mobile-ai');
        Route::post('/ai/summarize-conversation', [AiController::class, 'summarizeConversation'])
            ->middleware('throttle:mobile-ai');
    });
});
