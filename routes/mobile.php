<?php

use App\Http\Controllers\Api\Mobile\V1\AiController;
use App\Http\Controllers\Api\Mobile\V1\AiEmployeeController;
use App\Http\Controllers\Api\Mobile\V1\AiSettingsController;
use App\Http\Controllers\Api\Mobile\V1\AttachmentController;
use App\Http\Controllers\Api\Mobile\V1\AuthController;
use App\Http\Controllers\Api\Mobile\V1\BrandingController;
use App\Http\Controllers\Api\Mobile\V1\ChannelConnectController;
use App\Http\Controllers\Api\Mobile\V1\ChannelController;
use App\Http\Controllers\Api\Mobile\V1\ChatCatalogController;
use App\Http\Controllers\Api\Mobile\V1\CommerceCartController;
use App\Http\Controllers\Api\Mobile\V1\CommerceIntelligenceController;
use App\Http\Controllers\Api\Mobile\V1\CommerceOrderController;
use App\Http\Controllers\Api\Mobile\V1\Company\WorkerDiscoveryController;
use App\Http\Controllers\Api\Mobile\V1\Company\WorkerEngagementCapabilityController;
use App\Http\Controllers\Api\Mobile\V1\ConversationController;
use App\Http\Controllers\Api\Mobile\V1\ConversationHandoffController;
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
use App\Http\Controllers\Api\Mobile\V1\Worker\WorkerAccountController;
use App\Http\Controllers\Api\Mobile\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:mobile-login');
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

    Route::prefix('worker')->name('worker.')->group(function (): void {
        Route::get('/profile', [WorkerAccountController::class, 'profile'])->name('profile');
        Route::patch('/profile', [WorkerAccountController::class, 'updateProfile'])->name('profile.update');
        // POST keeps multipart profile-photo uploads available on PHP runtimes
        // that do not populate $_FILES for PATCH requests.
        Route::post('/profile', [WorkerAccountController::class, 'updateProfile'])->name('profile.upload');
        Route::get('/skills', [WorkerAccountController::class, 'skillCatalog'])->name('skills.catalog');
        Route::put('/skills', [WorkerAccountController::class, 'syncSkills'])->name('skills.sync');
        Route::get('/languages', [WorkerAccountController::class, 'languageCatalog'])->name('languages.catalog');
        Route::put('/languages', [WorkerAccountController::class, 'syncLanguages'])->name('languages.sync');
        Route::post('/experiences', [WorkerAccountController::class, 'storeExperience'])->name('experiences.store');
        Route::patch('/experiences/{experience}', [WorkerAccountController::class, 'updateExperience'])
            ->whereNumber('experience')
            ->name('experiences.update');
        Route::delete('/experiences/{experience}', [WorkerAccountController::class, 'destroyExperience'])
            ->whereNumber('experience')
            ->name('experiences.destroy');
        Route::get('/identity', [WorkerAccountController::class, 'identity'])->name('identity.show');
        Route::put('/identity', [WorkerAccountController::class, 'updateIdentity'])->name('identity.update');
        Route::get('/documents', [WorkerAccountController::class, 'documents'])->name('documents.index');
        Route::post('/documents', [WorkerAccountController::class, 'uploadDocument'])
            ->middleware('throttle:mobile-write')
            ->name('documents.store');
        Route::post('/verification', [WorkerAccountController::class, 'submitVerification'])
            ->middleware('throttle:mobile-write')
            ->name('verification.submit');
        Route::get('/engagements', [WorkerAccountController::class, 'engagements'])->name('engagements.index');
        Route::get('/engagements/{engagement}', [WorkerAccountController::class, 'engagement'])
            ->whereNumber('engagement')
            ->name('engagements.show');
        Route::get('/engagements/{engagement}/products', [WorkerAccountController::class, 'products'])
            ->whereNumber('engagement')
            ->name('engagements.products');
        Route::post('/engagements/{engagement}/accept', [WorkerAccountController::class, 'acceptEngagement'])
            ->whereNumber('engagement')
            ->middleware('throttle:mobile-write')
            ->name('engagements.accept');
        Route::post('/engagements/{engagement}/reject', [WorkerAccountController::class, 'rejectEngagement'])
            ->whereNumber('engagement')
            ->middleware('throttle:mobile-write')
            ->name('engagements.reject');
        Route::get('/engagements/{engagement}/session', [WorkerAccountController::class, 'session'])
            ->whereNumber('engagement')
            ->name('engagements.session');
        Route::post('/engagements/{engagement}/session/start', [WorkerAccountController::class, 'startSession'])
            ->whereNumber('engagement')
            ->middleware('throttle:mobile-write')
            ->name('engagements.session.start');
        Route::post('/engagements/{engagement}/session/break', [WorkerAccountController::class, 'breakSession'])
            ->whereNumber('engagement')
            ->middleware('throttle:mobile-write')
            ->name('engagements.session.break');
        Route::post('/engagements/{engagement}/session/resume', [WorkerAccountController::class, 'resumeSession'])
            ->whereNumber('engagement')
            ->middleware('throttle:mobile-write')
            ->name('engagements.session.resume');
        Route::post('/engagements/{engagement}/session/end', [WorkerAccountController::class, 'endSession'])
            ->whereNumber('engagement')
            ->middleware('throttle:mobile-write')
            ->name('engagements.session.end');
        Route::get('/engagements/{engagement}/earnings', [WorkerAccountController::class, 'earnings'])
            ->whereNumber('engagement')
            ->name('engagements.earnings');
        Route::post('/engagements/{engagement}/earnings/{period}/dispute', [WorkerAccountController::class, 'disputePeriod'])
            ->whereNumber('engagement')
            ->whereNumber('period')
            ->middleware('throttle:mobile-write')
            ->name('engagements.earnings.dispute');
        Route::post('/engagements/{engagement}/disputes', [WorkerAccountController::class, 'storeDispute'])
            ->whereNumber('engagement')
            ->middleware('throttle:mobile-write')
            ->name('engagements.disputes.store');
        Route::get('/payouts', [WorkerAccountController::class, 'payouts'])->name('payouts.index');
        Route::get('/disputes', [WorkerAccountController::class, 'disputes'])->name('disputes.index');
        Route::get('/disputes/{dispute}', [WorkerAccountController::class, 'dispute'])
            ->whereNumber('dispute')
            ->name('disputes.show');
        Route::get('/workspaces/{workspace}/inbox', [WorkerAccountController::class, 'inbox'])
            ->whereNumber('workspace')
            ->name('inbox.index');
        Route::get('/workspaces/{workspace}/inbox/{conversation}', [WorkerAccountController::class, 'conversation'])
            ->whereNumber('workspace')
            ->whereNumber('conversation')
            ->name('inbox.show');
        Route::post('/workspaces/{workspace}/inbox/{conversation}/messages', [WorkerAccountController::class, 'sendMessage'])
            ->whereNumber('workspace')
            ->whereNumber('conversation')
            ->middleware('throttle:mobile-write')
            ->name('inbox.messages.store');
    });
});

Route::middleware([
    'auth:sanctum',
    'workspace.resolve',
    'inbox.actor',
    'mobile.idempotency',
    'throttle:mobile-api',
])->group(function (): void {
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages']);
    Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'storeMessage'])
        ->middleware('throttle:mobile-messages');
    Route::post('/conversations/{conversation}/read', [ConversationController::class, 'read']);
});

Route::middleware([
    'auth:sanctum',
    'workspace.resolve',
    'workspace.capability:email.view',
    'workspace.feature:email',
    'mobile.idempotency',
    'throttle:mobile-api',
])->group(function (): void {
    Route::get('/emails/accounts', [EmailController::class, 'accounts']);
    Route::get('/emails/inbox', [EmailController::class, 'inbox']);
    Route::get('/emails/sent', [EmailController::class, 'sent']);
    Route::get('/emails/drafts', [EmailController::class, 'drafts']);
    Route::get('/emails/attachments/{emailAttachment}/download', [EmailController::class, 'downloadAttachment']);
    Route::get('/emails/{emailMessage}', [EmailController::class, 'show']);
    Route::post('/emails/{emailMessage}/read', [EmailController::class, 'read']);
    Route::post('/emails/{emailMessage}/star', [EmailController::class, 'star']);

    Route::get('/email/campaigns/{campaign}', [EmailCampaignController::class, 'show']);

    Route::get('/contacts/recent-recipients', [EmailContactController::class, 'recentRecipients']);
    Route::get('/contacts', [EmailContactController::class, 'index']);
    Route::get('/contacts/{contact}', [EmailContactController::class, 'show']);

    Route::get('/contact-groups', [EmailContactGroupController::class, 'index']);
});

Route::middleware([
    'auth:sanctum',
    'workspace.resolve',
    'workspace.capability:stories.view',
    'mobile.idempotency',
    'throttle:mobile-api',
])->group(function (): void {
    Route::get('/stories', [StoryController::class, 'index']);
    Route::get('/stories/{story}', [StoryController::class, 'show']);
    Route::post('/stories/{story}/view', [StoryController::class, 'view'])
        ->middleware('throttle:mobile-write');
    Route::get('/stories/{story}/viewers', [StoryController::class, 'viewers']);
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

    Route::post('/conversations/{conversation}/archive', [ConversationController::class, 'archive']);
    Route::post('/conversations/{conversation}/mute', [ConversationController::class, 'mute']);
    Route::patch('/conversations/{conversation}/classification', [ConversationController::class, 'updateClassification']);
    Route::post('/conversations/{conversation}/handoff', [ConversationHandoffController::class, 'requestHandoff']);
    Route::post('/conversations/{conversation}/assign', [ConversationHandoffController::class, 'assign']);
    Route::post('/conversations/{conversation}/takeover', [ConversationHandoffController::class, 'takeover']);
    Route::post('/conversations/{conversation}/resolve-handoff', [ConversationHandoffController::class, 'resolve']);
    Route::post('/conversations/{conversation}/resume-ai', [ConversationHandoffController::class, 'resumeAi']);

    Route::post('/messages/{message}/attachments', [AttachmentController::class, 'store'])
        ->middleware('throttle:mobile-attachments');
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])
        ->name('attachments.download')
        ->middleware(['signed', 'throttle:mobile-attachments']);

    Route::middleware('workspace.feature:email')->group(function (): void {
        Route::post('/emails', [EmailController::class, 'send'])->middleware('throttle:mobile-email');

        Route::post('/email/campaigns', [EmailCampaignController::class, 'store'])
            ->middleware('throttle:mobile-email');
        Route::post('/email/campaigns/{campaign}/cancel', [EmailCampaignController::class, 'cancel'])
            ->middleware('throttle:mobile-write');

        Route::post('/contacts', [EmailContactController::class, 'store'])->middleware('throttle:mobile-write');
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

    Route::post('/stories', [StoryController::class, 'store'])->middleware('throttle:mobile-attachments');
    Route::delete('/stories/{story}', [StoryController::class, 'destroy'])->middleware('throttle:mobile-write');

    Route::get('/channels', [ChannelController::class, 'index']);
    Route::get('/channels/facebook_messenger/pages', [ChannelConnectController::class, 'facebookPages']);
    Route::post('/channels/facebook_messenger/pages', [ChannelConnectController::class, 'selectFacebookPage'])
        ->middleware('throttle:mobile-write');
    Route::post('/channels/{channel}/connect', [ChannelConnectController::class, 'start'])
        ->middleware('throttle:mobile-write')
        ->where('channel', 'whatsapp|facebook_messenger|instagram|tiktok|telegram');
    Route::post('/channels/{channel}/disconnect', [ChannelConnectController::class, 'disconnect'])
        ->middleware('throttle:mobile-write')
        ->where('channel', 'whatsapp|facebook_messenger|instagram|tiktok|telegram');
    Route::get('/plan', [PlanController::class, 'current']);
    Route::get('/plans', [PlanController::class, 'index']);
    Route::get('/branding', [BrandingController::class, 'show']);

    Route::middleware('workspace.feature:products')->group(function (): void {
        Route::get('/catalog', [ChatCatalogController::class, 'index']);
        Route::get('/catalog/{item}', [ChatCatalogController::class, 'show']);
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

    Route::prefix('company')->name('company.')->group(function (): void {
        Route::get('/worker-discovery', [WorkerDiscoveryController::class, 'index']);
        Route::get('/worker-engagements/open', [WorkerDiscoveryController::class, 'openEngagements']);
        Route::get('/worker-engagements/{engagement}/capabilities', [WorkerEngagementCapabilityController::class, 'index'])
            ->whereNumber('engagement')
            ->name('worker-engagements.capabilities.index');
        Route::post('/worker-engagements/{engagement}/capabilities', [WorkerEngagementCapabilityController::class, 'store'])
            ->whereNumber('engagement')
            ->middleware('throttle:mobile-write')
            ->name('worker-engagements.capabilities.store');
        Route::delete('/worker-engagements/{engagement}/capabilities/{capability}', [WorkerEngagementCapabilityController::class, 'destroy'])
            ->whereNumber('engagement')
            ->where('capability', '[a-z0-9]+(?:[._-][a-z0-9]+)*')
            ->name('worker-engagements.capabilities.destroy');
        Route::get('/worker-discovery/{workerProfile}', [WorkerDiscoveryController::class, 'show'])
            ->whereNumber('workerProfile');
        Route::get('/worker-discovery/{workerProfile}/contract-price', [WorkerDiscoveryController::class, 'contractPrice'])
            ->whereNumber('workerProfile');
        Route::post('/worker-discovery/{workerProfile}/engagements', [WorkerDiscoveryController::class, 'storeOffer'])
            ->whereNumber('workerProfile')
            ->middleware('throttle:mobile-write');
    });

    Route::middleware('workspace.feature:ai')->group(function (): void {
        Route::get('/ai/settings', [AiSettingsController::class, 'show']);
        Route::put('/ai/settings', [AiSettingsController::class, 'update'])->middleware('throttle:mobile-write');
        Route::post('/ai/probe', [AiSettingsController::class, 'probe'])->middleware('throttle:mobile-ai');
        Route::post('/ai/suggest-reply', [AiController::class, 'suggestReply'])->middleware('throttle:mobile-ai');
        Route::post('/ai/summarize-conversation', [AiController::class, 'summarizeConversation'])
            ->middleware('throttle:mobile-ai');

        Route::get('/ai/employee-presets', [AiEmployeeController::class, 'presets']);
        Route::get('/ai/skills', [AiEmployeeController::class, 'skills']);
        Route::get('/ai/employees', [AiEmployeeController::class, 'index']);
        Route::post('/ai/employees', [AiEmployeeController::class, 'store'])->middleware('throttle:mobile-write');
        Route::get('/ai/employees/{employee}', [AiEmployeeController::class, 'show'])->whereNumber('employee');
        Route::patch('/ai/employees/{employee}', [AiEmployeeController::class, 'update'])->whereNumber('employee')->middleware('throttle:mobile-write');
        Route::delete('/ai/employees/{employee}', [AiEmployeeController::class, 'destroy'])->whereNumber('employee')->middleware('throttle:mobile-write');
        Route::post('/ai/employees/{employee}/default', [AiEmployeeController::class, 'setDefault'])->whereNumber('employee')->middleware('throttle:mobile-write');
        Route::post('/ai/employees/{employee}/activate', [AiEmployeeController::class, 'activate'])->whereNumber('employee')->middleware('throttle:mobile-write');
        Route::post('/ai/employees/{employee}/deactivate', [AiEmployeeController::class, 'deactivate'])->whereNumber('employee')->middleware('throttle:mobile-write');
        Route::post('/ai/employees/{employee}/test', [AiEmployeeController::class, 'test'])->whereNumber('employee')->middleware('throttle:mobile-ai');
    });
});
