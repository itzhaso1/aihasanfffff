<?php

use App\Http\Controllers\AssistantController;
use App\Http\Controllers\Auth\PhoneOtpController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\DownloadsController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\Mobile\WhatsAppMobileSignupController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Webhook\MetaWebhookController;
use App\Http\Controllers\Webhook\ResendWebhookController;
use App\Http\Controllers\Webhook\TelegramWebhookController;
use App\Http\Controllers\Webhook\TikTokWebhookController;
use App\Http\Controllers\Webhook\WhatsAppWebhookController;
use App\Http\Controllers\Worker\WorkerDisputeController as WorkerDisputeWebController;
use App\Http\Controllers\Worker\WorkerEarningsController as WorkerEarningsWebController;
use App\Http\Controllers\Worker\WorkerEngagementController as WorkerEngagementWebController;
use App\Http\Controllers\Worker\WorkerInboxController;
use App\Http\Controllers\Worker\WorkerProfileController;
use App\Http\Controllers\Worker\WorkerRegistrationController;
use App\Http\Controllers\Worker\WorkerTaxonomyController;
use App\Http\Controllers\Worker\WorkerWorkSessionController as WorkerWorkSessionWebController;
use App\Http\Controllers\Workspace\AiSettingController;
use App\Http\Controllers\Workspace\AnalyticsController as WorkspaceAnalyticsController;
use App\Http\Controllers\Workspace\ApiKeyController as WorkspaceApiKeyController;
use App\Http\Controllers\Workspace\ChannelController;
use App\Http\Controllers\Workspace\Chat\ChatCatalogController;
use App\Http\Controllers\Workspace\Chat\ChatCatalogDigitalInventoryController;
use App\Http\Controllers\Workspace\Chat\ChatCatalogProductTemplateController;
use App\Http\Controllers\Workspace\Chat\ChatCatalogSettingsController;
use App\Http\Controllers\Workspace\Commerce\CommerceCartController as WorkspaceCommerceCartController;
use App\Http\Controllers\Workspace\Commerce\CommerceIntelligenceController as WorkspaceCommerceIntelligenceController;
use App\Http\Controllers\Workspace\Commerce\CommerceOrderController as WorkspaceCommerceOrderController;
use App\Http\Controllers\Workspace\Commerce\CommerceTaxSettingController as WorkspaceCommerceTaxSettingController;
use App\Http\Controllers\Workspace\ConversationController;
use App\Http\Controllers\Workspace\ConversationHandoffController;
use App\Http\Controllers\Workspace\CustomerController;
use App\Http\Controllers\Workspace\DashboardController as WorkspaceDashboardController;
use App\Http\Controllers\Workspace\Email\EmailBrandingController;
use App\Http\Controllers\Workspace\Email\EmailTemplateLibraryController;
use App\Http\Controllers\Workspace\EmailController;
use App\Http\Controllers\Workspace\EmployeeInvitationController;
use App\Http\Controllers\Workspace\FacebookChannelConnectController;
use App\Http\Controllers\Workspace\InstagramChannelConnectController;
use App\Http\Controllers\Workspace\MerchantPaymentController;
use App\Http\Controllers\Workspace\MessageAttachmentController;
use App\Http\Controllers\Workspace\PaymentController;
use App\Http\Controllers\Workspace\PaymentGatewayController;
use App\Http\Controllers\Workspace\SubscriptionController;
use App\Http\Controllers\Workspace\TelegramChannelConnectController;
use App\Http\Controllers\Workspace\TikTokChannelConnectController;
use App\Http\Controllers\Workspace\WhatsAppAccountController;
use App\Http\Controllers\Workspace\WorkerDiscoveryController;
use App\Http\Controllers\Workspace\WorkerDisputeController as WorkspaceWorkerDisputeController;
use App\Http\Controllers\Workspace\WorkerEarningsController;
use App\Http\Controllers\Workspace\WorkerEngagementCapabilityController;
use App\Http\Controllers\Workspace\WorkerEngagementController;
use App\Http\Controllers\Workspace\WorkerWorkSessionController as WorkspaceWorkerWorkSessionController;
use App\Http\Controllers\WorkspaceSelectionController;
use App\Http\Middleware\EnsureWorkspaceMembership;
use App\Http\Middleware\EnsureWorkspaceSelected;
use App\Models\Plan;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Route::get('/', function () {
    $plans = collect();
    if (Schema::hasTable('plans')) {
        $plansQuery = Plan::query()->where('is_active', true);

        if (Schema::hasColumn('plans', 'is_public')) {
            $plansQuery->where('is_public', true);
        }

        if (Schema::hasColumn('plans', 'is_official')) {
            $plansQuery->where('is_official', true);
        } else {
            $plansQuery->whereIn('code', ['starter', 'pro', 'business', 'enterprise']);
        }

        $order = ['starter' => 1, 'pro' => 2, 'business' => 3, 'enterprise' => 4];
        $plans = $plansQuery->get()->sortBy(fn ($plan) => $order[$plan->code] ?? 99)->values();
    }

    return view('landing', [
        'officialPlans' => $plans,
    ]);
});

Route::get('/downloads', [DownloadsController::class, 'index'])->name('downloads');
Route::get('/downloads/{app}', [DownloadsController::class, 'show'])
    ->whereIn('app', ['chat'])
    ->name('downloads.app');
Route::get('/privacy', [LegalPageController::class, 'privacy'])->name('legal.privacy');
Route::get('/terms', [LegalPageController::class, 'terms'])->name('legal.terms');
Route::get('/cookies', [LegalPageController::class, 'cookies'])->name('legal.cookies');
Route::get('/acceptable-use', [LegalPageController::class, 'acceptableUse'])->name('legal.acceptable-use');
Route::get('/refund', [LegalPageController::class, 'refund'])->name('legal.refund');

Route::post('/assistant/chat', [AssistantController::class, 'chat'])
    ->middleware('throttle:20,1')
    ->name('assistant.chat');

Route::post('/webhooks/resend', [ResendWebhookController::class, 'handle'])
    ->middleware('throttle:120,1')
    ->name('webhooks.resend');

Route::get('/meta-webhook', [MetaWebhookController::class, 'verify'])
    ->middleware(['throttle:120,1', 'assign.correlation'])
    ->name('webhooks.meta.verify');
Route::post('/meta-webhook', [MetaWebhookController::class, 'handle'])
    ->middleware(['throttle:120,1', 'assign.correlation'])
    ->name('webhooks.meta.handle');

Route::get('/whatsapp-webhook', [WhatsAppWebhookController::class, 'verify'])
    ->middleware('throttle:120,1')
    ->name('webhooks.whatsapp.verify');
Route::post('/whatsapp-webhook', [WhatsAppWebhookController::class, 'handle'])
    ->middleware(['throttle:120,1', 'assign.correlation'])
    ->name('webhooks.whatsapp.handle');
Route::post('/tiktok-webhook', [TikTokWebhookController::class, 'handle'])
    ->middleware(['throttle:120,1', 'assign.correlation'])
    ->name('webhooks.tiktok.handle');
Route::post('/telegram-webhook/{publicWebhookKey}', [TelegramWebhookController::class, 'handle'])
    ->middleware(['throttle:120,1', 'assign.correlation'])
    ->where('publicWebhookKey', '[A-Za-z0-9_-]+')
    ->name('webhooks.telegram.handle');

Route::get('/mobile/channel-connect/whatsapp/{token}', [WhatsAppMobileSignupController::class, 'show'])
    ->middleware('throttle:20,1')
    ->where('token', '[A-Za-z0-9]+')
    ->name('mobile.channels.whatsapp.signup');
Route::post('/mobile/channel-connect/whatsapp/{token}', [WhatsAppMobileSignupController::class, 'store'])
    ->middleware('throttle:20,1')
    ->where('token', '[A-Za-z0-9]+')
    ->name('mobile.channels.whatsapp.complete');

Route::middleware(['guest'])->group(function (): void {
    Route::get('/workers/register', [WorkerRegistrationController::class, 'create'])->name('worker.register');
    Route::post('/workers/register', [WorkerRegistrationController::class, 'store'])->name('worker.register.store');

    Route::get('/otp/login', [PhoneOtpController::class, 'create'])->name('otp.login');
    Route::post('/otp/request', [PhoneOtpController::class, 'requestOtp'])
        ->middleware('throttle:otp-request')
        ->name('otp.request');
    Route::get('/otp/verify', [PhoneOtpController::class, 'verifyForm'])->name('otp.verify.form');
    Route::post('/otp/verify', [PhoneOtpController::class, 'verify'])
        ->middleware('throttle:otp-verify')
        ->name('otp.verify');

    Route::get('/auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])
        ->whereIn('provider', ['google', 'facebook'])
        ->name('social.redirect');
});

Route::middleware(['auth'])->prefix('workers')->as('worker.')->group(function (): void {
    Route::get('/profile', [WorkerProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [WorkerProfileController::class, 'update'])->name('profile.update');
    Route::put('/skills', [WorkerTaxonomyController::class, 'syncSkills'])->name('skills.sync');
    Route::put('/languages', [WorkerTaxonomyController::class, 'syncLanguages'])->name('languages.sync');
    Route::post('/experiences', [WorkerTaxonomyController::class, 'storeExperience'])->name('experiences.store');
    Route::patch('/experiences/{experience}', [WorkerTaxonomyController::class, 'updateExperience'])
        ->whereNumber('experience')
        ->name('experiences.update');
    Route::delete('/experiences/{experience}', [WorkerTaxonomyController::class, 'destroyExperience'])
        ->whereNumber('experience')
        ->name('experiences.destroy');
    Route::get('/engagements', [WorkerEngagementWebController::class, 'index'])->name('engagements.index');
    Route::get('/engagements/{engagement}', [WorkerEngagementWebController::class, 'show'])
        ->whereNumber('engagement')
        ->name('engagements.show');
    Route::get('/engagements/{engagement}/sessions', [WorkerWorkSessionWebController::class, 'show'])
        ->whereNumber('engagement')
        ->name('engagements.sessions.show');
    Route::get('/engagements/{engagement}/earnings', [WorkerEarningsWebController::class, 'show'])
        ->whereNumber('engagement')
        ->name('engagements.earnings.show');
    Route::get('/disputes', [WorkerDisputeWebController::class, 'index'])->name('disputes.index');
    Route::get('/disputes/{dispute}', [WorkerDisputeWebController::class, 'show'])
        ->whereNumber('dispute')
        ->name('disputes.show');
    Route::get('/inbox', [WorkerInboxController::class, 'home'])->name('inbox.home');

    Route::middleware('verified')->group(function (): void {
        Route::post('/documents', [WorkerProfileController::class, 'uploadDocument'])->name('documents.store');
        Route::post('/submit', [WorkerProfileController::class, 'submit'])->name('verification.submit');
        Route::post('/engagements/{engagement}/accept', [WorkerEngagementWebController::class, 'accept'])
            ->whereNumber('engagement')
            ->name('engagements.accept');
        Route::post('/engagements/{engagement}/reject', [WorkerEngagementWebController::class, 'reject'])
            ->whereNumber('engagement')
            ->name('engagements.reject');
        Route::post('/engagements/{engagement}/session/start', [WorkerWorkSessionWebController::class, 'start'])
            ->whereNumber('engagement')
            ->name('engagements.sessions.start');
        Route::post('/engagements/{engagement}/session/end', [WorkerWorkSessionWebController::class, 'end'])
            ->whereNumber('engagement')
            ->name('engagements.sessions.end');
        Route::post('/engagements/{engagement}/session/break', [WorkerWorkSessionWebController::class, 'beginBreak'])
            ->whereNumber('engagement')
            ->name('engagements.sessions.break');
        Route::post('/engagements/{engagement}/session/resume', [WorkerWorkSessionWebController::class, 'resume'])
            ->whereNumber('engagement')
            ->name('engagements.sessions.resume');
        Route::post('/engagements/{engagement}/earnings/{period}/dispute', [WorkerEarningsWebController::class, 'dispute'])
            ->whereNumber('engagement')
            ->whereNumber('period')
            ->name('engagements.earnings.dispute');
        Route::post('/engagements/{engagement}/disputes', [WorkerDisputeWebController::class, 'store'])
            ->whereNumber('engagement')
            ->name('engagements.disputes.store');
    });

    Route::get('/{workspace}/inbox', [WorkerInboxController::class, 'index'])
        ->whereNumber('workspace')
        ->name('inbox.index');
    Route::get('/{workspace}/inbox/{conversation}', [WorkerInboxController::class, 'show'])
        ->whereNumber('workspace')
        ->whereNumber('conversation')
        ->name('inbox.show');
    Route::post('/{workspace}/inbox/{conversation}/messages', [WorkerInboxController::class, 'storeMessage'])
        ->whereNumber('workspace')
        ->whereNumber('conversation')
        ->middleware('throttle:mobile-messages')
        ->name('inbox.messages.store');
});

Route::get('/auth/{provider}/callback', [SocialLoginController::class, 'callback'])
    ->whereIn('provider', ['google', 'facebook'])
    ->name('social.callback');

Route::middleware(['auth'])->group(function (): void {
    Route::get('/dashboard', fn () => redirect()->route('workspace.dashboard'))->name('dashboard');

    Route::get('/workspaces/choose', [WorkspaceSelectionController::class, 'choose'])->name('workspace.choose');
    Route::post('/workspaces/{workspace}/switch', [WorkspaceSelectionController::class, 'switch'])->name('workspace.switch');
    Route::redirect('/subscription', '/workspace/subscriptions')->name('subscription.page');
    Route::redirect('/billing', '/workspace/subscriptions')->name('billing.page');
    Route::redirect('/settings', '/profile')->name('settings.page');
    Route::redirect('/security', '/profile')->name('security.page');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
});

Route::middleware(['web', 'auth'])->group(function (): void {
    Broadcast::routes();
});

Route::middleware(['auth', 'workspace.selected', 'workspace.member'])
    ->prefix('workspace')
    ->as('workspace.')
    ->group(function (): void {
        Route::get('/', [WorkspaceDashboardController::class, 'index'])->name('dashboard');
        Route::post('logo', [WorkspaceDashboardController::class, 'updateLogo'])->name('logo.update');
        Route::delete('logo', [WorkspaceDashboardController::class, 'destroyLogo'])->name('logo.destroy');

        Route::middleware('workspace.feature:analytics')->group(function (): void {
            Route::get('analytics', [WorkspaceAnalyticsController::class, 'index'])->name('analytics.index');
        });

        Route::middleware('workspace.feature:api')->prefix('api-keys')->as('api-keys.')->group(function (): void {
            Route::get('/', [WorkspaceApiKeyController::class, 'index'])->name('index');
            Route::post('/', [WorkspaceApiKeyController::class, 'store'])->name('store');
            Route::post('{apiKey}/revoke', [WorkspaceApiKeyController::class, 'revoke'])->name('revoke');
            Route::post('{apiKey}/regenerate', [WorkspaceApiKeyController::class, 'regenerate'])->name('regenerate');
        });

        Route::resource('customers', CustomerController::class)->except(['show']);
        Route::post('customers/{customer}/notes', [CustomerController::class, 'storeNote'])->name('customers.notes.store');
        Route::middleware('workspace.feature:advanced_customers')->group(function (): void {
            Route::post('customers/{customer}/tags', [CustomerController::class, 'attachTag'])->name('customers.tags.attach');
            Route::delete('customers/{customer}/tags/{tag}', [CustomerController::class, 'detachTag'])->name('customers.tags.detach');
            Route::post('customers/{customer}/groups', [CustomerController::class, 'attachGroup'])->name('customers.groups.attach');
            Route::delete('customers/{customer}/groups/{group}', [CustomerController::class, 'detachGroup'])->name('customers.groups.detach');
        });
        Route::middleware('workspace.feature:orders')->group(function (): void {
            Route::get('commerce/carts', [WorkspaceCommerceCartController::class, 'index'])->name('commerce.carts.index');
            Route::get('commerce/carts/{cart}', [WorkspaceCommerceCartController::class, 'show'])->name('commerce.carts.show');
            Route::get('commerce/orders', [WorkspaceCommerceOrderController::class, 'index'])->name('commerce.orders.index');
            Route::get('commerce/orders/{order}', [WorkspaceCommerceOrderController::class, 'show'])->name('commerce.orders.show');
            Route::put('commerce/tax-setting', [WorkspaceCommerceTaxSettingController::class, 'update'])->name('commerce.tax-setting.update');

            Route::get('commerce/intelligence', [WorkspaceCommerceIntelligenceController::class, 'index'])->name('commerce.intelligence.index');
            Route::put('commerce/intelligence/profile', [WorkspaceCommerceIntelligenceController::class, 'updateProfile'])->name('commerce.intelligence.profile.update');
            Route::get('commerce/intelligence/attributes', [WorkspaceCommerceIntelligenceController::class, 'attributes'])->name('commerce.intelligence.attributes');
            Route::post('commerce/intelligence/attributes', [WorkspaceCommerceIntelligenceController::class, 'storeAttribute'])->name('commerce.intelligence.attributes.store');
            Route::delete('commerce/intelligence/attributes/{attribute}', [WorkspaceCommerceIntelligenceController::class, 'destroyAttribute'])->name('commerce.intelligence.attributes.destroy');
            Route::get('commerce/intelligence/requirements', [WorkspaceCommerceIntelligenceController::class, 'requirements'])->name('commerce.intelligence.requirements');
            Route::post('commerce/intelligence/requirements', [WorkspaceCommerceIntelligenceController::class, 'storeRequirement'])->name('commerce.intelligence.requirements.store');
            Route::delete('commerce/intelligence/requirements/{requirement}', [WorkspaceCommerceIntelligenceController::class, 'destroyRequirement'])->name('commerce.intelligence.requirements.destroy');
            Route::get('commerce/intelligence/rules', [WorkspaceCommerceIntelligenceController::class, 'rules'])->name('commerce.intelligence.rules');
            Route::post('commerce/intelligence/rules', [WorkspaceCommerceIntelligenceController::class, 'storeRule'])->name('commerce.intelligence.rules.store');
            Route::delete('commerce/intelligence/rules/{rule}', [WorkspaceCommerceIntelligenceController::class, 'destroyRule'])->name('commerce.intelligence.rules.destroy');
            Route::get('commerce/intelligence/compatibilities', [WorkspaceCommerceIntelligenceController::class, 'compatibilities'])->name('commerce.intelligence.compatibilities');
            Route::post('commerce/intelligence/compatibilities', [WorkspaceCommerceIntelligenceController::class, 'storeCompatibility'])->name('commerce.intelligence.compatibilities.store');
            Route::delete('commerce/intelligence/compatibilities/{compatibility}', [WorkspaceCommerceIntelligenceController::class, 'destroyCompatibility'])->name('commerce.intelligence.compatibilities.destroy');
            Route::get('commerce/intelligence/items/{item}', [WorkspaceCommerceIntelligenceController::class, 'item'])->name('commerce.intelligence.items.show');
            Route::post('commerce/intelligence/items/{item}/attributes', [WorkspaceCommerceIntelligenceController::class, 'storeItemAttributes'])->name('commerce.intelligence.items.attributes.store');
        });
        Route::resource('conversations', ConversationController::class)->except(['show']);
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'storeMessage'])->name('conversations.messages.store');
        Route::post('conversations/{conversation}/handoff', [ConversationHandoffController::class, 'requestHandoff'])->name('conversations.handoff.request');
        Route::post('conversations/{conversation}/assign', [ConversationHandoffController::class, 'assign'])->name('conversations.handoff.assign');
        Route::post('conversations/{conversation}/takeover', [ConversationHandoffController::class, 'takeover'])->name('conversations.handoff.takeover');
        Route::post('conversations/{conversation}/resolve-handoff', [ConversationHandoffController::class, 'resolve'])->name('conversations.handoff.resolve');
        Route::post('conversations/{conversation}/resume-ai', [ConversationHandoffController::class, 'resumeAi'])->name('conversations.handoff.resume-ai');
        Route::get('message-attachments/{attachment}/download', [MessageAttachmentController::class, 'download'])
            ->name('message-attachments.download');

        Route::get('chat-catalog/product-templates', [ChatCatalogProductTemplateController::class, 'index'])->name('chat-catalog.templates.index');
        Route::get('chat-catalog/product-templates/{template}', [ChatCatalogProductTemplateController::class, 'show'])->name('chat-catalog.templates.show');
        Route::post('chat-catalog/product-templates', [ChatCatalogProductTemplateController::class, 'store'])->name('chat-catalog.templates.store');
        Route::post('chat-catalog/product-templates/{template}/clone', [ChatCatalogProductTemplateController::class, 'clone'])->name('chat-catalog.templates.clone');
        Route::get('chat-catalog', [ChatCatalogController::class, 'index'])->name('chat-catalog.index');
        Route::get('chat-catalog/settings', [ChatCatalogSettingsController::class, 'edit'])->name('chat-catalog.settings.edit');
        Route::put('chat-catalog/settings', [ChatCatalogSettingsController::class, 'update'])->name('chat-catalog.settings.update');
        Route::put('chat-catalog/settings/communication', [ChatCatalogSettingsController::class, 'updateCommunication'])->name('chat-catalog.settings.communication.update');
        Route::post('chat-catalog/settings/publish', [ChatCatalogSettingsController::class, 'publish'])->name('chat-catalog.settings.publish');
        Route::post('chat-catalog/settings/draft', [ChatCatalogSettingsController::class, 'draft'])->name('chat-catalog.settings.draft');
        Route::post('chat-catalog/settings/pause', [ChatCatalogSettingsController::class, 'pause'])->name('chat-catalog.settings.pause');
        Route::post('chat-catalog/settings/archive', [ChatCatalogSettingsController::class, 'archive'])->name('chat-catalog.settings.archive');
        Route::middleware('retired.digital-products')->group(function (): void {
            Route::get('chat-catalog/digital-inventory', [ChatCatalogDigitalInventoryController::class, 'index'])->name('chat-catalog.digital-inventory.index');
            Route::post('chat-catalog/digital-inventory/pools', [ChatCatalogDigitalInventoryController::class, 'storePool'])->name('chat-catalog.digital-inventory.pools.store');
            Route::post('chat-catalog/digital-inventory/pools/{pool}/import', [ChatCatalogDigitalInventoryController::class, 'import'])->name('chat-catalog.digital-inventory.pools.import');
            Route::delete('chat-catalog/digital-inventory/pools/{pool}', [ChatCatalogDigitalInventoryController::class, 'destroyPool'])->name('chat-catalog.digital-inventory.pools.destroy');
        });
        Route::get('chat-catalog/create', [ChatCatalogController::class, 'create'])->name('chat-catalog.create');
        Route::post('chat-catalog', [ChatCatalogController::class, 'store'])->name('chat-catalog.store');
        Route::get('chat-catalog/{item}/edit', [ChatCatalogController::class, 'edit'])->name('chat-catalog.edit');
        Route::put('chat-catalog/{item}', [ChatCatalogController::class, 'update'])->name('chat-catalog.update');
        Route::post('chat-catalog/{item}/activate', [ChatCatalogController::class, 'activate'])->name('chat-catalog.activate');
        Route::post('chat-catalog/{item}/deactivate', [ChatCatalogController::class, 'deactivate'])->name('chat-catalog.deactivate');
        Route::delete('chat-catalog/{item}', [ChatCatalogController::class, 'destroy'])->name('chat-catalog.destroy');
        Route::post('chat-catalog/categories', [ChatCatalogController::class, 'storeCategory'])->name('chat-catalog.categories.store');
        Route::put('chat-catalog/categories/{category}', [ChatCatalogController::class, 'updateCategory'])->name('chat-catalog.categories.update');
        Route::delete('chat-catalog/categories/{category}', [ChatCatalogController::class, 'destroyCategory'])->name('chat-catalog.categories.destroy');

        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::middleware('retired.payment-links')->group(function (): void {
            Route::get('payments/create', [PaymentController::class, 'create'])->name('payments.create');
            Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
        });
        Route::get('payments/merchant', [MerchantPaymentController::class, 'show'])->name('payments.merchant.show');
        Route::post('payments/merchant/request', [MerchantPaymentController::class, 'requestVerification'])->name('payments.merchant.request');
        Route::post('payments/merchant/upload', [MerchantPaymentController::class, 'uploadDocument'])->name('payments.merchant.upload');
        Route::post('payments/merchant/submit', [MerchantPaymentController::class, 'submit'])->name('payments.merchant.submit');
        Route::get('payments/merchant/documents/{document}', [MerchantPaymentController::class, 'downloadDocument'])->name('payments.merchant.documents.download');

        Route::middleware('retired.payment-links')->group(function (): void {
            Route::resource('payment-gateways', PaymentGatewayController::class)->except(['show']);
        });

        Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::get('subscriptions/compare', [SubscriptionController::class, 'compare'])->name('subscriptions.compare');
        Route::post('subscriptions', [SubscriptionController::class, 'store'])
            ->middleware('throttle:subscription-billing')
            ->name('subscriptions.store');
        Route::get('subscriptions/checkout/{checkoutSession}', [SubscriptionController::class, 'showCheckout'])->name('subscriptions.checkout.show');
        Route::get('subscriptions/checkout/{checkoutSession}/return', [SubscriptionController::class, 'returnFromProvider'])
            ->name('subscriptions.checkout.return');
        Route::post('subscriptions/checkout/{checkoutSession}/confirm-payment', [SubscriptionController::class, 'confirmCheckoutPayment'])
            ->middleware('throttle:subscription-billing')
            ->name('subscriptions.checkout.confirm-payment');
        Route::delete('subscriptions/{subscription}', [SubscriptionController::class, 'destroy'])
            ->middleware('throttle:subscription-billing')
            ->name('subscriptions.destroy');

        Route::middleware('workspace.feature:ai')->group(function (): void {
            Route::get('ai-settings', [AiSettingController::class, 'edit'])->name('ai-settings.edit');
            Route::put('ai-settings', [AiSettingController::class, 'update'])->name('ai-settings.update');
            Route::post('ai-settings/employees', [AiSettingController::class, 'storeEmployee'])->name('ai-settings.employees.store');
            Route::put('ai-settings/employees/{employee}', [AiSettingController::class, 'updateEmployee'])->whereNumber('employee')->name('ai-settings.employees.update');
            Route::post('ai-settings/employees/{employee}/default', [AiSettingController::class, 'setDefaultEmployee'])->whereNumber('employee')->name('ai-settings.employees.default');
            Route::post('ai-settings/employees/{employee}/activate', [AiSettingController::class, 'activateEmployee'])->whereNumber('employee')->name('ai-settings.employees.activate');
            Route::post('ai-settings/employees/{employee}/deactivate', [AiSettingController::class, 'deactivateEmployee'])->whereNumber('employee')->name('ai-settings.employees.deactivate');
            Route::delete('ai-settings/employees/{employee}', [AiSettingController::class, 'destroyEmployee'])->whereNumber('employee')->name('ai-settings.employees.destroy');
        });

        Route::middleware('workspace.feature:whatsapp')->group(function (): void {
            Route::resource('whatsapp-accounts', WhatsAppAccountController::class)->except(['show']);
            Route::post('channels/whatsapp/connect', [ChannelController::class, 'connectWhatsApp'])->name('channels.whatsapp.connect');
        });
        Route::middleware('workspace.feature:whatsapp')->group(function (): void {
            Route::get('channels/instagram/connect', [InstagramChannelConnectController::class, 'redirect'])
                ->name('channels.instagram.connect');
            Route::get('channels/facebook/connect', [FacebookChannelConnectController::class, 'redirect'])
                ->name('channels.facebook.connect');
            Route::post('channels/facebook/disconnect', [FacebookChannelConnectController::class, 'disconnect'])
                ->name('channels.facebook.disconnect');
            Route::get('channels/tiktok/connect', [TikTokChannelConnectController::class, 'redirect'])
                ->name('channels.tiktok.connect');
            Route::post('channels/telegram/connect', [TelegramChannelConnectController::class, 'store'])
                ->name('channels.telegram.connect');
        });
        Route::get('channels/instagram/callback', [InstagramChannelConnectController::class, 'callback'])
            ->withoutMiddleware([
                Authenticate::class,
                EnsureWorkspaceSelected::class,
                EnsureWorkspaceMembership::class,
            ])
            ->name('channels.instagram.callback');
        Route::get('channels/facebook/callback', [FacebookChannelConnectController::class, 'callback'])
            ->withoutMiddleware([
                Authenticate::class,
                EnsureWorkspaceSelected::class,
                EnsureWorkspaceMembership::class,
            ])
            ->name('channels.facebook.callback');
        Route::get('channels/tiktok/callback', [TikTokChannelConnectController::class, 'callback'])
            ->withoutMiddleware([
                Authenticate::class,
                EnsureWorkspaceSelected::class,
                EnsureWorkspaceMembership::class,
            ])
            ->name('channels.tiktok.callback');
        Route::get('channels', [ChannelController::class, 'index'])->name('channels.index');

        Route::middleware('workspace.feature:email')->prefix('emails')->group(function (): void {
            Route::get('/', [EmailController::class, 'index'])->name('emails.index');
            Route::get('/inbox', [EmailController::class, 'inbox'])->name('emails.inbox');
            Route::get('/sent', [EmailController::class, 'sent'])->name('emails.sent');
            Route::get('/messages/{emailMessage}', [EmailController::class, 'showMessage'])->name('emails.messages.show');
            Route::delete('/messages/{emailMessage}', [EmailController::class, 'destroyMessage'])->name('emails.messages.destroy');
            Route::get('/logs/{emailLog}', [EmailController::class, 'showLog'])->name('emails.logs.show');
            Route::delete('/logs/{emailLog}', [EmailController::class, 'destroyLog'])->name('emails.logs.destroy');
            Route::get('/attachments/{emailAttachment}/download', [EmailController::class, 'downloadAttachment'])->name('emails.attachments.download');
            Route::get('/attachments/{emailAttachment}/preview', [EmailController::class, 'previewAttachment'])->name('emails.attachments.preview');
            Route::delete('/attachments/{emailAttachment}', [EmailController::class, 'destroyAttachment'])->name('emails.attachments.destroy');

            Route::get('/compose', [EmailController::class, 'compose'])->name('emails.compose');
            Route::post('/compose/clear', [EmailController::class, 'clearComposeDraft'])->name('emails.compose.clear');
            Route::post('/messages/send', [EmailController::class, 'sendMessage'])->name('emails.messages.send');

            Route::get('/contacts', [EmailController::class, 'contacts'])->name('emails.contacts.index');
            Route::post('/contacts', [EmailController::class, 'storeContact'])->name('emails.contacts.store');
            Route::put('/contacts/{emailContact}', [EmailController::class, 'updateContact'])->name('emails.contacts.update');
            Route::delete('/contacts/{emailContact}', [EmailController::class, 'destroyContact'])->name('emails.contacts.destroy');
            Route::get('/contacts/search', [EmailController::class, 'searchContacts'])->name('emails.contacts.search');
            Route::get('/contacts/lookup', [EmailController::class, 'lookupContact'])->name('emails.contacts.lookup');

            Route::get('/accounts', [EmailController::class, 'accounts'])->name('emails.accounts.index');
            Route::post('/accounts', [EmailController::class, 'storeAccount'])->name('emails.accounts.store');
            Route::put('/accounts/{emailAccount}', [EmailController::class, 'updateAccount'])->name('emails.accounts.update');
            Route::delete('/accounts/{emailAccount}', [EmailController::class, 'destroyAccount'])->name('emails.accounts.destroy');
            Route::post('/accounts/{emailAccount}/sync', [EmailController::class, 'syncAccount'])->name('emails.accounts.sync');
            Route::post('/accounts/{emailAccount}/test-smtp', [EmailController::class, 'testAccountSmtp'])->name('emails.accounts.test-smtp');

            Route::get('/branding', [EmailBrandingController::class, 'edit'])->name('emails.branding.edit');
            Route::put('/branding', [EmailBrandingController::class, 'update'])->name('emails.branding.update');
            Route::post('/branding/preview', [EmailBrandingController::class, 'preview'])->name('emails.branding.preview');

            Route::get('/templates', [EmailTemplateLibraryController::class, 'index'])->name('emails.templates.index');
            Route::get('/templates/selected', [EmailTemplateLibraryController::class, 'edit'])->name('emails.templates.edit');
            Route::put('/templates/selected', [EmailTemplateLibraryController::class, 'update'])->name('emails.templates.update');
            Route::post('/templates/selected/preview', [EmailTemplateLibraryController::class, 'livePreview'])->name('emails.templates.live-preview');
            Route::post('/templates/{template}/use', [EmailTemplateLibraryController::class, 'use'])->name('emails.templates.use');
            Route::get('/templates/{category}/{template}/preview', [EmailTemplateLibraryController::class, 'preview'])->name('emails.templates.preview');
            Route::get('/templates/{category}', [EmailTemplateLibraryController::class, 'category'])->name('emails.templates.category');
        });

        Route::get('employees', [EmployeeInvitationController::class, 'index'])->name('employees.index');
        Route::get('employees/invite', [EmployeeInvitationController::class, 'create'])->name('employees.create');
        Route::post('employees/invite', [EmployeeInvitationController::class, 'store'])->name('employees.store');
        Route::delete('employees/invitations/{employee}', [EmployeeInvitationController::class, 'destroy'])->name('employees.destroy');

        Route::get('worker-engagements', [WorkerEngagementController::class, 'index'])->name('worker-engagements.index');
        Route::get('worker-disputes', [WorkspaceWorkerDisputeController::class, 'index'])->name('worker-disputes.index');
        Route::get('worker-disputes/{dispute}', [WorkspaceWorkerDisputeController::class, 'show'])
            ->whereNumber('dispute')
            ->name('worker-disputes.show');
        Route::get('worker-discovery', [WorkerDiscoveryController::class, 'index'])->name('worker-discovery.index');
        Route::get('worker-discovery/{workerProfile}', [WorkerDiscoveryController::class, 'show'])
            ->whereNumber('workerProfile')
            ->name('worker-discovery.show');
        Route::get('worker-discovery/{workerProfile}/contract-price', [WorkerDiscoveryController::class, 'contractPrice'])
            ->whereNumber('workerProfile')
            ->name('worker-discovery.contract-price');
        Route::post('worker-discovery/{workerProfile}/engagements', [WorkerDiscoveryController::class, 'storeOffer'])
            ->whereNumber('workerProfile')
            ->name('worker-discovery.engagements.store');
        Route::get('worker-engagements/create', [WorkerEngagementController::class, 'create'])->name('worker-engagements.create');
        Route::post('worker-engagements', [WorkerEngagementController::class, 'store'])->name('worker-engagements.store');
        Route::get('worker-engagements/{engagement}', [WorkerEngagementController::class, 'show'])
            ->whereNumber('engagement')
            ->name('worker-engagements.show');
        Route::put('worker-engagements/{engagement}/guide', [WorkerEngagementController::class, 'updateGuide'])
            ->whereNumber('engagement')
            ->name('worker-engagements.guide.update');
        Route::post('worker-engagements/{engagement}/disputes', [WorkspaceWorkerDisputeController::class, 'store'])
            ->whereNumber('engagement')
            ->name('worker-engagements.disputes.store');
        Route::get('worker-engagements/{engagement}/sessions', [WorkspaceWorkerWorkSessionController::class, 'index'])
            ->whereNumber('engagement')
            ->name('worker-engagements.sessions.index');
        Route::get('worker-engagements/{engagement}/earnings', [WorkerEarningsController::class, 'index'])
            ->whereNumber('engagement')
            ->name('worker-engagements.earnings.index');
        Route::post('worker-engagements/{engagement}/earning-periods', [WorkerEarningsController::class, 'storePeriod'])
            ->whereNumber('engagement')
            ->name('worker-engagements.earning-periods.store');
        Route::post('worker-engagements/{engagement}/earning-periods/{period}/lines', [WorkerEarningsController::class, 'storeLine'])
            ->whereNumber('engagement')
            ->whereNumber('period')
            ->name('worker-engagements.earning-periods.lines.store');
        Route::post('worker-engagements/{engagement}/earning-periods/{period}/submit', [WorkerEarningsController::class, 'submit'])
            ->whereNumber('engagement')
            ->whereNumber('period')
            ->name('worker-engagements.earning-periods.submit');
        Route::post('worker-engagements/{engagement}/earning-periods/{period}/approve', [WorkerEarningsController::class, 'approve'])
            ->whereNumber('engagement')
            ->whereNumber('period')
            ->name('worker-engagements.earning-periods.approve');
        Route::get('worker-engagements/{engagement}/capabilities', [WorkerEngagementCapabilityController::class, 'index'])
            ->whereNumber('engagement')
            ->name('worker-engagements.capabilities.index');
        Route::post('worker-engagements/{engagement}/capabilities', [WorkerEngagementCapabilityController::class, 'store'])
            ->whereNumber('engagement')
            ->name('worker-engagements.capabilities.store');
        Route::delete('worker-engagements/{engagement}/capabilities/{capability}', [WorkerEngagementCapabilityController::class, 'destroy'])
            ->whereNumber('engagement')
            ->name('worker-engagements.capabilities.destroy');
        Route::post('worker-engagements/{engagement}/activate', [WorkerEngagementController::class, 'activate'])
            ->whereNumber('engagement')
            ->name('worker-engagements.activate');
        Route::post('worker-engagements/{engagement}/pause', [WorkerEngagementController::class, 'pause'])
            ->whereNumber('engagement')
            ->name('worker-engagements.pause');
        Route::post('worker-engagements/{engagement}/resume', [WorkerEngagementController::class, 'resume'])
            ->whereNumber('engagement')
            ->name('worker-engagements.resume');
        Route::post('worker-engagements/{engagement}/complete', [WorkerEngagementController::class, 'complete'])
            ->whereNumber('engagement')
            ->name('worker-engagements.complete');
        Route::post('worker-engagements/{engagement}/terminate', [WorkerEngagementController::class, 'terminate'])
            ->whereNumber('engagement')
            ->name('worker-engagements.terminate');
    });

require __DIR__.'/auth.php';
