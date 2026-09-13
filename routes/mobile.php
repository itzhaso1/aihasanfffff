<?php

use App\Http\Controllers\Api\Mobile\V1\AiController;
use App\Http\Controllers\Api\Mobile\V1\AttachmentController;
use App\Http\Controllers\Api\Mobile\V1\AuthController;
use App\Http\Controllers\Api\Mobile\V1\BrandingController;
use App\Http\Controllers\Api\Mobile\V1\ChannelController;
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

Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
    ->name('attachments.download')
    ->middleware('signed');

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

    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::get('/customers/{customer}/conversations', [CustomerController::class, 'conversations']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::get('/notification-preferences', [NotificationController::class, 'preferences']);
    Route::put('/notification-preferences', [NotificationController::class, 'updatePreferences']);

    Route::post('/ai/suggest-reply', [AiController::class, 'suggestReply'])->middleware('throttle:mobile-ai');
    Route::post('/ai/summarize-conversation', [AiController::class, 'summarizeConversation'])
        ->middleware('throttle:mobile-ai');
});
