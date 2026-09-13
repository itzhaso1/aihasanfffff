<?php

use App\Http\Controllers\AssistantController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\Auth\PhoneOtpController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\Download\DigitalDownloadController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Webhook\ResendWebhookController;
use App\Http\Controllers\Webhook\WhatsAppWebhookController;
use App\Http\Controllers\Workspace\AiSettingController;
use App\Http\Controllers\Workspace\AnalyticsController as WorkspaceAnalyticsController;
use App\Http\Controllers\Workspace\ApiKeyController as WorkspaceApiKeyController;
use App\Http\Controllers\Workspace\CategoryController;
use App\Http\Controllers\Workspace\ChannelController;
use App\Http\Controllers\Workspace\ContractController as WorkspaceContractController;
use App\Http\Controllers\Workspace\ConversationController;
use App\Http\Controllers\Workspace\CustomerController;
use App\Http\Controllers\Workspace\DashboardController as WorkspaceDashboardController;
use App\Http\Controllers\Workspace\EmailController;
use App\Http\Controllers\Workspace\EmployeeInvitationController;
use App\Http\Controllers\Workspace\Finance\AccountingController as FinanceAccountingController;
use App\Http\Controllers\Workspace\Finance\BillingDashboardController as FinanceBillingDashboardController;
use App\Http\Controllers\Workspace\Finance\BillingScheduleController as FinanceBillingScheduleController;
use App\Http\Controllers\Workspace\Finance\CreditNoteController as FinanceCreditNoteController;
use App\Http\Controllers\Workspace\Finance\CustomerStatementController as FinanceCustomerStatementController;
use App\Http\Controllers\Workspace\Finance\DashboardController as FinanceDashboardController;
use App\Http\Controllers\Workspace\Finance\ExpenseController as FinanceExpenseController;
use App\Http\Controllers\Workspace\Finance\ExportController as FinanceExportController;
use App\Http\Controllers\Workspace\Finance\FinanceEmployeeController;
use App\Http\Controllers\Workspace\Finance\FiscalYearController as FinanceFiscalYearController;
use App\Http\Controllers\Workspace\Finance\IntelligenceController as FinanceIntelligenceController;
use App\Http\Controllers\Workspace\Finance\InvoiceController as FinanceInvoiceController;
use App\Http\Controllers\Workspace\Finance\LeadController as FinanceLeadController;
use App\Http\Controllers\Workspace\Finance\ModulePageController as FinanceModulePageController;
use App\Http\Controllers\Workspace\Finance\PaymentController as FinancePaymentController;
use App\Http\Controllers\Workspace\Finance\PayrollAdjustmentController as FinancePayrollAdjustmentController;
use App\Http\Controllers\Workspace\Finance\PriceListController as FinancePriceListController;
use App\Http\Controllers\Workspace\Finance\ProjectController as FinanceProjectController;
use App\Http\Controllers\Workspace\Finance\PurchaseOrderController as FinancePurchaseOrderController;
use App\Http\Controllers\Workspace\Finance\QuoteController as FinanceQuoteController;
use App\Http\Controllers\Workspace\Finance\ReceiptController as FinanceReceiptController;
use App\Http\Controllers\Workspace\Finance\ReportController as FinanceReportController;
use App\Http\Controllers\Workspace\Finance\SalaryAdvanceController as FinanceSalaryAdvanceController;
use App\Http\Controllers\Workspace\Finance\SalesController as FinanceSalesController;
use App\Http\Controllers\Workspace\Finance\SettingsController as FinanceSettingsController;
use App\Http\Controllers\Workspace\Finance\SupplierController as FinanceSupplierController;
use App\Http\Controllers\Workspace\Finance\TreasuryController as FinanceTreasuryController;
use App\Http\Controllers\Workspace\InventoryController;
use App\Http\Controllers\Workspace\MerchantPaymentController;
use App\Http\Controllers\Workspace\OrderController;
use App\Http\Controllers\Workspace\PaymentController;
use App\Http\Controllers\Workspace\PaymentGatewayController;
use App\Http\Controllers\Workspace\Pos\CashierController as PosCashierController;
use App\Http\Controllers\Workspace\Pos\CustomerMenuController as PosCustomerMenuController;
use App\Http\Controllers\Workspace\Pos\PosCartController;
use App\Http\Controllers\Workspace\Pos\PosCashierInvoiceController;
use App\Http\Controllers\Workspace\Pos\PosItemCategoryController;
use App\Http\Controllers\Workspace\Pos\PosKitchenController;
use App\Http\Controllers\Workspace\Pos\PosMenuItemController;
use App\Http\Controllers\Workspace\Pos\PosMenuPageController;
use App\Http\Controllers\Workspace\Pos\PosOrderController;
use App\Http\Controllers\Workspace\Pos\PosReportController;
use App\Http\Controllers\Workspace\Pos\PosReturnController;
use App\Http\Controllers\Workspace\Pos\PosSettingsController;
use App\Http\Controllers\Workspace\Pos\TableController as PosTableController;
use App\Http\Controllers\Workspace\ProductController;
use App\Http\Controllers\Workspace\SubscriptionController;
use App\Http\Controllers\Workspace\WhatsAppAccountController;
use App\Http\Controllers\WorkspaceSelectionController;
use App\Models\Plan;
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

Route::view('/downloads', 'downloads')->name('downloads');

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

Route::get('/whatsapp-webhook', [WhatsAppWebhookController::class, 'verify'])
    ->middleware('throttle:120,1')
    ->name('webhooks.whatsapp.verify');
Route::post('/whatsapp-webhook', [WhatsAppWebhookController::class, 'handle'])
    ->middleware('throttle:120,1')
    ->name('webhooks.whatsapp.handle');

Route::middleware('throttle:30,1')->group(function (): void {
    Route::get('/menu/{workspace:slug}', [PosCustomerMenuController::class, 'generalMenu'])->name('menu.general');
    Route::post('/menu/{workspace:slug}/order', [PosCustomerMenuController::class, 'placeGeneralOrder'])->name('menu.general.order');
    Route::post('/menu/{workspace:slug}/ai-chat', [PosCustomerMenuController::class, 'askAi'])->name('menu.general.ai');
    Route::get('/menu/{workspace:slug}/table/{token}', [PosCustomerMenuController::class, 'tableMenu'])->name('menu.table');
    Route::post('/menu/{workspace:slug}/table/{token}/order', [PosCustomerMenuController::class, 'placeTableOrder'])->name('menu.table.order');
    Route::post('/menu/{workspace:slug}/table/{token}/ai-chat', [PosCustomerMenuController::class, 'askAi'])->name('menu.table.ai');
});

Route::middleware(['guest'])->group(function (): void {
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

// Cashier desktop Google login reuses this callback with state=ticket.
// Keep it outside `guest` so an existing web session in the same browser cannot
// swallow the OAuth code before the cashier ticket is marked ready.
Route::get('/auth/{provider}/callback', [SocialLoginController::class, 'callback'])
    ->whereIn('provider', ['google', 'facebook'])
    ->name('social.callback');

Route::middleware(['auth'])->group(function (): void {
    Route::get('/dashboard', fn () => redirect()->route('workspace.subscriptions.index'))->name('dashboard');

    Route::get('/workspaces/choose', [WorkspaceSelectionController::class, 'choose'])->name('workspace.choose');
    Route::post('/workspaces/{workspace}/switch', [WorkspaceSelectionController::class, 'switch'])->name('workspace.switch');
    Route::redirect('/subscription', '/workspace/subscriptions')->name('subscription.page');
    Route::redirect('/billing', '/workspace/payments/create')->name('billing.page');
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

        Route::resource('categories', CategoryController::class)->except(['show']);
        Route::middleware('workspace.feature:products')->group(function (): void {
            Route::resource('products', ProductController::class)->except(['show']);
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
            Route::resource('orders', OrderController::class)->except(['show']);
        });
        Route::get('contracts', [WorkspaceContractController::class, 'index'])->name('contracts.index');
        Route::get('contracts/create', [WorkspaceContractController::class, 'create'])->name('contracts.create');
        Route::post('contracts', [WorkspaceContractController::class, 'store'])->name('contracts.store');
        Route::get('contracts/{contract}', [WorkspaceContractController::class, 'show'])->name('contracts.show');
        Route::get('contracts/{contract}/edit', [WorkspaceContractController::class, 'edit'])->name('contracts.edit');
        Route::put('contracts/{contract}', [WorkspaceContractController::class, 'update'])->name('contracts.update');
        Route::post('contracts/{contract}/activate', [WorkspaceContractController::class, 'activate'])->name('contracts.activate');
        Route::post('contracts/{contract}/close', [WorkspaceContractController::class, 'close'])->name('contracts.close');
        Route::post('contracts/{contract}/cancel', [WorkspaceContractController::class, 'cancel'])->name('contracts.cancel');
        Route::get('contracts/{contract}/pdf', [WorkspaceContractController::class, 'downloadPdf'])->name('contracts.pdf');
        Route::get('contracts/{contract}/attachments/{attachment}', [WorkspaceContractController::class, 'downloadAttachment'])->name('contracts.attachments.download');
        Route::delete('contracts/{contract}/attachments/{attachment}', [WorkspaceContractController::class, 'destroyAttachment'])->name('contracts.attachments.destroy');
        Route::resource('conversations', ConversationController::class)->except(['show']);
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'storeMessage'])->name('conversations.messages.store');

        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('inventory/create', [InventoryController::class, 'create'])->name('inventory.create');
        Route::post('inventory', [InventoryController::class, 'store'])->name('inventory.store');

        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/create', [PaymentController::class, 'create'])->name('payments.create');
        Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::get('payments/merchant', [MerchantPaymentController::class, 'show'])->name('payments.merchant.show');
        Route::post('payments/merchant/request', [MerchantPaymentController::class, 'requestVerification'])->name('payments.merchant.request');
        Route::post('payments/merchant/upload', [MerchantPaymentController::class, 'uploadDocument'])->name('payments.merchant.upload');
        Route::post('payments/merchant/submit', [MerchantPaymentController::class, 'submit'])->name('payments.merchant.submit');
        Route::get('payments/merchant/documents/{document}', [MerchantPaymentController::class, 'downloadDocument'])->name('payments.merchant.documents.download');

        Route::resource('payment-gateways', PaymentGatewayController::class)->except(['show']);

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
        });

        Route::middleware('workspace.feature:whatsapp')->group(function (): void {
            Route::resource('whatsapp-accounts', WhatsAppAccountController::class)->except(['show']);
            Route::post('channels/whatsapp/connect', [ChannelController::class, 'connectWhatsApp'])->name('channels.whatsapp.connect');
        });
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
        });

        Route::middleware('workspace.feature:finance')->prefix('finance')->as('finance.')->group(function (): void {
            Route::get('/', [FinanceDashboardController::class, 'index'])->name('dashboard');
            Route::get('search', [FinanceIntelligenceController::class, 'search'])->name('search');
            Route::get('alerts', [FinanceIntelligenceController::class, 'alerts'])->name('alerts.index');
            Route::get('copilot', [FinanceIntelligenceController::class, 'copilot'])->name('copilot.index');
            Route::post('copilot', [FinanceIntelligenceController::class, 'ask'])->name('copilot.ask');
            Route::get('billing', [FinanceBillingDashboardController::class, 'index'])->name('billing.dashboard');
            Route::get('statements', [FinanceCustomerStatementController::class, 'index'])->name('statements.index');
            Route::get('statements/show', [FinanceCustomerStatementController::class, 'show'])->name('statements.show');
            Route::get('contracts', [WorkspaceContractController::class, 'index'])->name('contracts.index');
            Route::get('contracts/create', [WorkspaceContractController::class, 'create'])->name('contracts.create');
            Route::post('contracts', [WorkspaceContractController::class, 'store'])->name('contracts.store');
            Route::get('contracts/{contract}', [WorkspaceContractController::class, 'show'])->name('contracts.show');
            Route::get('contracts/{contract}/edit', [WorkspaceContractController::class, 'edit'])->name('contracts.edit');
            Route::put('contracts/{contract}', [WorkspaceContractController::class, 'update'])->name('contracts.update');
            Route::post('contracts/{contract}/activate', [WorkspaceContractController::class, 'activate'])->name('contracts.activate');
            Route::post('contracts/{contract}/close', [WorkspaceContractController::class, 'close'])->name('contracts.close');
            Route::post('contracts/{contract}/cancel', [WorkspaceContractController::class, 'cancel'])->name('contracts.cancel');
            Route::get('contracts/{contract}/pdf', [WorkspaceContractController::class, 'downloadPdf'])->name('contracts.pdf');
            Route::get('contracts/{contract}/attachments/{attachment}', [WorkspaceContractController::class, 'downloadAttachment'])->name('contracts.attachments.download');
            Route::delete('contracts/{contract}/attachments/{attachment}', [WorkspaceContractController::class, 'destroyAttachment'])->name('contracts.attachments.destroy');

            Route::get('invoices', [FinanceInvoiceController::class, 'index'])->name('invoices.index');
            Route::get('invoices/create', [FinanceInvoiceController::class, 'create'])->name('invoices.create');
            Route::post('invoices', [FinanceInvoiceController::class, 'store'])->name('invoices.store');
            Route::get('invoices/{invoice}', [FinanceInvoiceController::class, 'show'])->name('invoices.show');
            Route::get('invoices/{invoice}/edit', [FinanceInvoiceController::class, 'edit'])->name('invoices.edit');
            Route::put('invoices/{invoice}', [FinanceInvoiceController::class, 'update'])->name('invoices.update');
            Route::delete('invoices/{invoice}', [FinanceInvoiceController::class, 'destroy'])->name('invoices.destroy');
            Route::get('invoices/{invoice}/pdf', [FinanceInvoiceController::class, 'downloadPdf'])->name('invoices.pdf');
            Route::get('invoices/{invoice}/xml', [FinanceInvoiceController::class, 'downloadXml'])->name('invoices.xml');
            Route::get('invoices/{invoice}/qr', [FinanceInvoiceController::class, 'showQr'])->name('invoices.qr');
            Route::post('invoices/{invoice}/issue', [FinanceInvoiceController::class, 'issue'])->name('invoices.issue');
            Route::post('invoices/{invoice}/send', [FinanceInvoiceController::class, 'send'])->name('invoices.send');
            Route::post('invoices/{invoice}/remind', [FinanceInvoiceController::class, 'remind'])->name('invoices.remind');
            Route::post('invoices/{invoice}/checkout', [FinanceInvoiceController::class, 'createCheckout'])->name('invoices.checkout');
            Route::post('invoices/{invoice}/cancel', [FinanceInvoiceController::class, 'cancel'])->name('invoices.cancel');
            Route::post('invoices/{invoice}/payments', [FinanceInvoiceController::class, 'storePayment'])->name('invoices.payments.store');
            Route::post('invoices/{invoice}/payments/{payment}/reverse', [FinanceInvoiceController::class, 'reversePayment'])->name('invoices.payments.reverse');
            Route::post('invoices/{invoice}/attachments', [FinanceInvoiceController::class, 'storeAttachment'])->name('invoices.attachments.store');
            Route::get('invoices/{invoice}/attachments/{attachment}', [FinanceInvoiceController::class, 'downloadAttachment'])->name('invoices.attachments.download');
            Route::delete('invoices/{invoice}/attachments/{attachment}', [FinanceInvoiceController::class, 'destroyAttachment'])->name('invoices.attachments.destroy');
            Route::get('invoices/{invoice}/credit-notes/create', [FinanceCreditNoteController::class, 'create'])->name('invoices.credit-notes.create');
            Route::post('invoices/{invoice}/credit-notes', [FinanceCreditNoteController::class, 'store'])->name('invoices.credit-notes.store');
            Route::get('invoices/{invoice}/credit-notes/{creditNote}/pdf', [FinanceCreditNoteController::class, 'downloadPdf'])->name('invoices.credit-notes.pdf');
            Route::post('invoices/{invoice}/credit-notes/{creditNote}/issue', [FinanceCreditNoteController::class, 'issue'])->name('invoices.credit-notes.issue');
            Route::post('invoices/{invoice}/credit-notes/{creditNote}/cancel', [FinanceCreditNoteController::class, 'cancel'])->name('invoices.credit-notes.cancel');

            Route::get('payments', [FinancePaymentController::class, 'index'])->name('payments.index');
            Route::get('receipts', [FinanceReceiptController::class, 'index'])->name('receipts.index');
            Route::get('receipts/{receipt}', [FinanceReceiptController::class, 'show'])->name('receipts.show');
            Route::get('receipts/{receipt}/pdf', [FinanceReceiptController::class, 'downloadPdf'])->name('receipts.pdf');
            Route::post('receipts/{receipt}/send', [FinanceReceiptController::class, 'send'])->name('receipts.send');
            Route::get('exports', [FinanceExportController::class, 'index'])->name('exports.index');
            Route::get('exports/{dataset}', [FinanceExportController::class, 'download'])->name('exports.download');

            Route::get('quotes', [FinanceQuoteController::class, 'index'])->name('quotes.index');
            Route::get('quotes/create', [FinanceQuoteController::class, 'create'])->name('quotes.create');
            Route::post('quotes', [FinanceQuoteController::class, 'store'])->name('quotes.store');
            Route::get('quotes/{quote}', [FinanceQuoteController::class, 'show'])->name('quotes.show');
            Route::get('quotes/{quote}/edit', [FinanceQuoteController::class, 'edit'])->name('quotes.edit');
            Route::put('quotes/{quote}', [FinanceQuoteController::class, 'update'])->name('quotes.update');
            Route::delete('quotes/{quote}', [FinanceQuoteController::class, 'destroy'])->name('quotes.destroy');
            Route::get('quotes/{quote}/pdf', [FinanceQuoteController::class, 'downloadPdf'])->name('quotes.pdf');
            Route::post('quotes/{quote}/issue', [FinanceQuoteController::class, 'issue'])->name('quotes.issue');
            Route::post('quotes/{quote}/cancel', [FinanceQuoteController::class, 'cancel'])->name('quotes.cancel');
            Route::post('quotes/{quote}/send', [FinanceQuoteController::class, 'send'])->name('quotes.send');
            Route::post('quotes/{quote}/accept', [FinanceQuoteController::class, 'accept'])->name('quotes.accept');
            Route::post('quotes/{quote}/reject', [FinanceQuoteController::class, 'reject'])->name('quotes.reject');
            Route::post('quotes/{quote}/convert', [FinanceQuoteController::class, 'convert'])->name('quotes.convert');
            Route::post('quotes/{quote}/attachments', [FinanceQuoteController::class, 'storeAttachment'])->name('quotes.attachments.store');
            Route::get('quotes/{quote}/attachments/{attachment}', [FinanceQuoteController::class, 'downloadAttachment'])->name('quotes.attachments.download');
            Route::delete('quotes/{quote}/attachments/{attachment}', [FinanceQuoteController::class, 'destroyAttachment'])->name('quotes.attachments.destroy');

            Route::post('contracts/{contract}/billing-schedules', [FinanceBillingScheduleController::class, 'store'])->name('contracts.billing-schedules.store');
            Route::post('contracts/{contract}/billing-schedules/{schedule}/activate', [FinanceBillingScheduleController::class, 'activate'])->name('contracts.billing-schedules.activate');
            Route::post('contracts/{contract}/billing-schedules/{schedule}/pause', [FinanceBillingScheduleController::class, 'pause'])->name('contracts.billing-schedules.pause');
            Route::post('contracts/{contract}/billing-schedules/{schedule}/cancel', [FinanceBillingScheduleController::class, 'cancel'])->name('contracts.billing-schedules.cancel');
            Route::post('contracts/{contract}/billing-schedules/{schedule}/generate', [FinanceBillingScheduleController::class, 'generate'])->name('contracts.billing-schedules.generate');

            Route::get('suppliers', [FinanceSupplierController::class, 'index'])->name('suppliers.index');
            Route::post('suppliers', [FinanceSupplierController::class, 'store'])->name('suppliers.store');
            Route::put('suppliers/{supplier}', [FinanceSupplierController::class, 'update'])->name('suppliers.update');

            Route::get('expenses', [FinanceExpenseController::class, 'index'])->name('expenses.index');
            Route::post('expenses', [FinanceExpenseController::class, 'store'])->name('expenses.store');
            Route::get('expenses/{expense}/edit', [FinanceExpenseController::class, 'edit'])->name('expenses.edit');
            Route::put('expenses/{expense}', [FinanceExpenseController::class, 'update'])->name('expenses.update');
            Route::get('expenses/{expense}/attachment', [FinanceExpenseController::class, 'downloadAttachment'])->name('expenses.attachment');
            Route::delete('expenses/{expense}', [FinanceExpenseController::class, 'destroy'])->name('expenses.destroy');

            Route::get('accounting', [FinanceAccountingController::class, 'dashboard'])->name('accounting.dashboard');
            Route::get('reports', [FinanceReportController::class, 'index'])->name('reports.index');
            Route::get('reports/{report}', [FinanceReportController::class, 'show'])->name('reports.show');

            Route::get('settings', [FinanceSettingsController::class, 'index'])->name('settings.index');
            Route::put('settings/company', [FinanceSettingsController::class, 'updateCompany'])->name('settings.company.update');
            Route::post('settings/tax-rates', [FinanceSettingsController::class, 'storeTaxRate'])->name('settings.tax-rates.store');
            Route::post('settings/treasury-accounts', [FinanceSettingsController::class, 'storeTreasuryAccount'])->name('settings.treasury-accounts.store');

            Route::get('customers', [FinanceModulePageController::class, 'customers'])->name('customers.index');
            Route::get('products', [FinanceModulePageController::class, 'products'])->name('products.index');
            Route::get('inventory', [FinanceModulePageController::class, 'inventory'])->name('inventory.index');
            Route::get('employees', [FinanceEmployeeController::class, 'index'])->name('employees.index');
            Route::post('employees', [FinanceEmployeeController::class, 'store'])->name('employees.store');
            Route::get('employees/{employee}', [FinanceEmployeeController::class, 'show'])->name('employees.show');
            Route::put('employees/{employee}', [FinanceEmployeeController::class, 'update'])->name('employees.update');
            Route::delete('employees/{employee}', [FinanceEmployeeController::class, 'destroy'])->name('employees.destroy');
            Route::post('employees/{employee}/payroll-records', [FinanceEmployeeController::class, 'storePayrollRecord'])->name('employees.payroll-records.store');
            Route::get('payroll', [FinanceModulePageController::class, 'payroll'])->name('payroll.index');
            Route::get('vat', [FinanceModulePageController::class, 'vat'])->name('vat.index');
            Route::get('banks', [FinanceModulePageController::class, 'banks'])->name('banks.index');
            Route::get('sales', [FinanceSalesController::class, 'index'])->name('sales.index');
            Route::get('price-lists', [FinancePriceListController::class, 'index'])->name('price-lists.index');
            Route::post('price-lists', [FinancePriceListController::class, 'store'])->name('price-lists.store');
            Route::put('price-lists/{priceList}', [FinancePriceListController::class, 'update'])->name('price-lists.update');
            Route::post('price-lists/{priceList}/items', [FinancePriceListController::class, 'addItem'])->name('price-lists.items.store');
            Route::put('price-lists/items/{item}', [FinancePriceListController::class, 'updateItem'])->name('price-lists.items.update');
            Route::delete('price-lists/items/{item}', [FinancePriceListController::class, 'deleteItem'])->name('price-lists.items.destroy');
            Route::post('price-lists/{priceList}/approve', [FinancePriceListController::class, 'approve'])->name('price-lists.approve');
            Route::post('price-lists/{priceList}/mark-draft', [FinancePriceListController::class, 'markDraft'])->name('price-lists.mark-draft');
            Route::post('price-lists/{priceList}/cancel', [FinancePriceListController::class, 'cancel'])->name('price-lists.cancel');

            Route::get('allowances', [FinancePayrollAdjustmentController::class, 'allowances'])->name('allowances.index');
            Route::get('bonuses', [FinancePayrollAdjustmentController::class, 'bonuses'])->name('bonuses.index');
            Route::get('deductions', [FinancePayrollAdjustmentController::class, 'deductions'])->name('deductions.index');
            Route::post('payroll-adjustments', [FinancePayrollAdjustmentController::class, 'store'])->name('payroll-adjustments.store');
            Route::post('payroll-adjustments/{adjustment}/approve', [FinancePayrollAdjustmentController::class, 'approve'])->name('payroll-adjustments.approve');
            Route::post('payroll-adjustments/{adjustment}/post', [FinancePayrollAdjustmentController::class, 'post'])->name('payroll-adjustments.post');
            Route::post('payroll-adjustments/{adjustment}/cancel', [FinancePayrollAdjustmentController::class, 'cancel'])->name('payroll-adjustments.cancel');

            Route::get('salary-advances', [FinanceSalaryAdvanceController::class, 'index'])->name('salary-advances.index');
            Route::post('salary-advances', [FinanceSalaryAdvanceController::class, 'store'])->name('salary-advances.store');
            Route::post('salary-advances/{advance}/repay', [FinanceSalaryAdvanceController::class, 'repay'])->name('salary-advances.repay');

            Route::get('fiscal-years', [FinanceFiscalYearController::class, 'index'])->name('fiscal-years.index');
            Route::post('fiscal-years', [FinanceFiscalYearController::class, 'store'])->name('fiscal-years.store');
            Route::put('fiscal-years/{fiscalYear}', [FinanceFiscalYearController::class, 'update'])->name('fiscal-years.update');
            Route::post('fiscal-years/{fiscalYear}/close', [FinanceFiscalYearController::class, 'close'])->name('fiscal-years.close');
            Route::post('fiscal-years/{fiscalYear}/open', [FinanceFiscalYearController::class, 'open'])->name('fiscal-years.open');
            Route::post('fiscal-years/{fiscalYear}/generate-monthly-periods', [FinanceFiscalYearController::class, 'generateMonthlyPeriods'])->name('fiscal-years.generate-monthly-periods');
            Route::post('fiscal-years/{fiscalYear}/periods', [FinanceFiscalYearController::class, 'storePeriod'])->name('fiscal-years.periods.store');
            Route::post('fiscal-years/periods/{period}/status', [FinanceFiscalYearController::class, 'setPeriodStatus'])->name('fiscal-years.periods.set-status');

            Route::get('modules/{key}', [FinanceModulePageController::class, 'placeholder'])->name('modules.show');
            Route::get('purchases', [FinanceModulePageController::class, 'purchases'])->name('purchases.index');
            Route::get('purchase-orders', [FinancePurchaseOrderController::class, 'index'])->name('purchase-orders.index');
            Route::post('purchase-orders', [FinancePurchaseOrderController::class, 'store'])->name('purchase-orders.store');
            Route::post('purchase-orders/{purchaseOrder}/submit', [FinancePurchaseOrderController::class, 'submit'])->name('purchase-orders.submit');
            Route::post('purchase-orders/{purchaseOrder}/receive', [FinancePurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
            Route::post('purchase-orders/{purchaseOrder}/bill', [FinancePurchaseOrderController::class, 'bill'])->name('purchase-orders.bill');
            Route::get('leads', [FinanceLeadController::class, 'index'])->name('leads.index');
            Route::post('leads', [FinanceLeadController::class, 'store'])->name('leads.store');
            Route::post('leads/{lead}/convert', [FinanceLeadController::class, 'convert'])->name('leads.convert');
            Route::post('leads/{lead}/lost', [FinanceLeadController::class, 'markLost'])->name('leads.lost');
            Route::get('projects', [FinanceProjectController::class, 'index'])->name('projects.index');
            Route::post('projects', [FinanceProjectController::class, 'store'])->name('projects.store');
            Route::get('treasury', [FinanceTreasuryController::class, 'index'])->name('treasury.index');
            Route::post('treasury/transfers', [FinanceTreasuryController::class, 'transfer'])->name('treasury.transfers.store');
            Route::post('treasury/statements', [FinanceTreasuryController::class, 'storeStatement'])->name('treasury.statements.store');
            Route::get('treasury/statements/{statement}', [FinanceTreasuryController::class, 'showStatement'])->name('treasury.statements.show');
            Route::post('treasury/statements/{statement}/lines', [FinanceTreasuryController::class, 'storeLines'])->name('treasury.statements.lines.store');
            Route::post('treasury/statements/{statement}/suggest', [FinanceTreasuryController::class, 'suggest'])->name('treasury.statements.suggest');
            Route::post('treasury/statements/{statement}/lines/{line}/match', [FinanceTreasuryController::class, 'matchLine'])->name('treasury.statements.lines.match');
            Route::post('treasury/statements/{statement}/complete', [FinanceTreasuryController::class, 'complete'])->name('treasury.statements.complete');
            Route::get('cashbox', [FinanceModulePageController::class, 'cashbox'])->name('cashbox.index');
        });

        Route::middleware('workspace.feature:pos')->prefix('pos')->as('pos.')->group(function (): void {
            Route::get('/', [PosTableController::class, 'index'])->name('dashboard');
            Route::get('cashier', [PosCashierController::class, 'index'])->name('cashier.index');
            Route::get('menu', [PosMenuPageController::class, 'index'])->name('menu.index');

            Route::get('qr-orders', [PosOrderController::class, 'qrOrders'])->name('qr-orders.index');
            Route::get('orders/recent-menu', [PosCashierController::class, 'recentMenuOrders'])->name('orders.recent-menu');
            Route::get('orders/channel-stats', [PosCashierController::class, 'channelStats'])->name('orders.channel-stats');
            Route::post('orders', [PosCashierController::class, 'storeOrder'])->name('orders.store');
            Route::get('orders/running', [PosOrderController::class, 'running'])->name('orders.running');
            Route::get('kitchen', [PosKitchenController::class, 'index'])->name('kitchen.index');
            Route::post('orders/{order}/status', [PosOrderController::class, 'updateStatus'])->name('orders.status');
            Route::post('orders/{order}/invoice', [PosOrderController::class, 'createInvoice'])->name('orders.invoice');
            Route::post('orders/{order}/payment-link', [PosOrderController::class, 'createPaymentLink'])->name('orders.payment-link');
            Route::post('orders/{order}/items', [PosOrderController::class, 'updateItems'])->name('orders.update-items');
            Route::get('orders/{order}/print', [PosOrderController::class, 'printOrder'])->name('orders.print');
            Route::get('orders/{order}/returns/create', [PosReturnController::class, 'create'])->name('orders.returns.create');
            Route::post('orders/{order}/returns', [PosReturnController::class, 'store'])->name('orders.returns.store');
            Route::post('returns/{return}/refund', [PosReturnController::class, 'markRefunded'])->name('returns.refund');

            Route::get('cart', [PosCartController::class, 'show'])->name('cart.show');
            Route::post('cart/items', [PosCartController::class, 'addItem'])->name('cart.items.store');
            Route::patch('cart/items/{key}', [PosCartController::class, 'updateItem'])->name('cart.items.update');
            Route::delete('cart/items/{key}', [PosCartController::class, 'removeItem'])->name('cart.items.destroy');
            Route::post('cart/meta', [PosCartController::class, 'updateMeta'])->name('cart.meta');
            Route::post('cart/clear', [PosCartController::class, 'clear'])->name('cart.clear');
            Route::post('cart/checkout', [PosCartController::class, 'checkout'])->name('cart.checkout');

            Route::get('invoices', [PosCashierInvoiceController::class, 'index'])->name('invoices.index');
            Route::get('invoices/{invoice}', [PosCashierInvoiceController::class, 'show'])->name('invoices.show');
            Route::get('invoices/{invoice}/edit', [PosCashierInvoiceController::class, 'edit'])->name('invoices.edit');
            Route::put('invoices/{invoice}', [PosCashierInvoiceController::class, 'update'])->name('invoices.update');
            Route::get('invoices/{invoice}/print', [PosCashierInvoiceController::class, 'print'])->name('invoices.print');
            Route::get('reports/daily', [PosReportController::class, 'daily'])->name('reports.daily');

            Route::get('tables', [PosTableController::class, 'index'])->name('tables.index');
            Route::get('tables/live', [PosTableController::class, 'liveBoard'])->name('tables.live');
            Route::post('tables', [PosTableController::class, 'store'])->name('tables.store');
            Route::get('tables/{table}', [PosTableController::class, 'show'])->name('tables.show');
            Route::put('tables/{table}', [PosTableController::class, 'update'])->name('tables.update');
            Route::post('tables/{table}/open-session', [PosTableController::class, 'openSession'])->name('tables.sessions.open');
            Route::post('tables/{table}/orders', [PosTableController::class, 'addOrder'])->name('tables.orders.store');
            Route::post('tables/{table}/sessions/{session}/close', [PosTableController::class, 'closeSession'])->name('tables.sessions.close');
            Route::post('tables/{table}/sessions/{session}/cancel', [PosTableController::class, 'cancelSession'])->name('tables.sessions.cancel');
            Route::post('tables/{table}/sessions/{session}/discount', [PosTableController::class, 'applyDiscount'])->name('tables.sessions.discount');
            Route::post('tables/{table}/sessions/{session}/transfer', [PosTableController::class, 'transferSession'])->name('tables.sessions.transfer');
            Route::post('tables/{table}/sessions/{session}/merge', [PosTableController::class, 'mergeSession'])->name('tables.sessions.merge');
            Route::post('tables/{table}/sessions/{session}/split', [PosTableController::class, 'splitSession'])->name('tables.sessions.split');
            Route::post('tables/{table}/sessions/{session}/note', [PosTableController::class, 'updateSessionNote'])->name('tables.sessions.note');
            Route::post('tables/{table}/qr/regenerate', [PosTableController::class, 'regenerateQr'])->name('tables.qr.regenerate');

            Route::get('items', [PosMenuItemController::class, 'index'])->name('items.index');
            Route::post('items', [PosMenuItemController::class, 'store'])->name('items.store');
            Route::put('items/{item}', [PosMenuItemController::class, 'update'])->name('items.update');
            Route::delete('items/{item}', [PosMenuItemController::class, 'destroy'])->name('items.destroy');
            Route::post('categories', [PosItemCategoryController::class, 'store'])->name('categories.store');
            Route::put('categories/{category}', [PosItemCategoryController::class, 'update'])->name('categories.update');
            Route::delete('categories/{category}', [PosItemCategoryController::class, 'destroy'])->name('categories.destroy');
            Route::post('settings/menu-slider', [PosSettingsController::class, 'updateMenuSlider'])->name('settings.menu-slider');
            Route::post('settings/pos', [PosSettingsController::class, 'updatePosSettings'])->name('settings.pos');
        });

        Route::get('employees', [EmployeeInvitationController::class, 'index'])->name('employees.index');
        Route::get('employees/invite', [EmployeeInvitationController::class, 'create'])->name('employees.create');
        Route::post('employees/invite', [EmployeeInvitationController::class, 'store'])->name('employees.store');
        Route::delete('employees/invitations/{employee}', [EmployeeInvitationController::class, 'destroy'])->name('employees.destroy');
    });

require __DIR__.'/auth.php';

Route::get('/downloads/digital/{orderItem}', DigitalDownloadController::class)
    ->middleware('signed')
    ->name('downloads.digital');
