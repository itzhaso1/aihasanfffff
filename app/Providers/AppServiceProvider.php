<?php

namespace App\Providers;

use App\EInvoicing\QR\Harness\QrTag9Provider;
use App\EInvoicing\QR\Harness\UnresolvedProductionQrTag9Provider;
use App\EInvoicing\Security\CryptographicStampSigner;
use App\EInvoicing\Security\Harness\CryptographicProfile;
use App\EInvoicing\Security\Harness\UnresolvedProductionZatcaCryptographicProfile;
use App\Models\Category;
use App\Models\ChatCatalog;
use App\Models\ChatCatalogCategory;
use App\Models\ChatCatalogCommunicationProfile;
use App\Models\ChatCatalogDigitalInventoryPool;
use App\Models\ChatCatalogDigitalInventoryUnit;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemDigitalProfile;
use App\Models\ChatCatalogItemMedia;
use App\Models\Commerce\CommerceCart;
use App\Models\Commerce\CommerceIntelligenceProfile;
use App\Models\Commerce\Seller;
use App\Models\Commerce\SellerAllocation;
use App\Models\Commerce\SellerPaymentAccount;
use App\Models\Contract\Contract;
use App\Models\Contract\ContractAttachment;
use App\Models\Contract\ContractItem;
use App\Models\Conversation;
use App\Models\Crm\CrmLead;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\EmailAccount;
use App\Models\EmailBranding;
use App\Models\EmailContact;
use App\Models\EmailMessage;
use App\Models\EmailTemplatePreference;
use App\Models\EmployeeInvitation;
use App\Models\Finance\FinanceBankStatement;
use App\Models\Finance\FinanceBillingSchedule;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceCreditNoteItem;
use App\Models\Finance\FinanceEmployee;
use App\Models\Finance\FinanceEmployeePayrollRecord;
use App\Models\Finance\FinanceEmployeeProfile;
use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceFiscalYear;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoiceAttachment;
use App\Models\Finance\FinanceInvoiceItem;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceJournalEntryLine;
use App\Models\Finance\FinancePayrollAdjustment;
use App\Models\Finance\FinancePriceList;
use App\Models\Finance\FinancePurchaseOrder;
use App\Models\Finance\FinanceQuote;
use App\Models\Finance\FinanceQuoteItem;
use App\Models\Finance\FinanceReceipt;
use App\Models\Finance\FinanceSalaryAdvance;
use App\Models\Finance\FinanceSalaryAdvanceRepayment;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\FinanceSupplier;
use App\Models\Finance\FinanceTaxRate;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Models\Finance\FinanceTreasuryTransfer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\PosCashierInvoice;
use App\Models\PosCashierInvoiceItem;
use App\Models\PosItemCategory;
use App\Models\PosMenuItem;
use App\Models\Product;
use App\Models\Projects\FinanceProject;
use App\Models\Subscription;
use App\Models\TableSession;
use App\Models\WhatsAppAccount;
use App\Models\Workspace;
use App\Notifications\Channels\CentralMailChannel;
use App\Observers\ChatCatalogAuditObserver;
use App\Observers\ChatCatalogSensitiveAuditObserver;
use App\Observers\CommerceSensitiveAuditObserver;
use App\Observers\FinanceInvoicePaymentObserver;
use App\Observers\PosSyncChangeObserver;
use App\Observers\WorkspaceAuditObserver;
use App\Policies\CategoryPolicy;
use App\Policies\ChatCatalogCategoryPolicy;
use App\Policies\ChatCatalogDigitalInventoryPoolPolicy;
use App\Policies\ChatCatalogDigitalInventoryUnitPolicy;
use App\Policies\ChatCatalogItemPolicy;
use App\Policies\ChatCatalogPolicy;
use App\Policies\Commerce\CommerceCartPolicy;
use App\Policies\Commerce\CommerceIntelligencePolicy;
use App\Policies\Commerce\SellerPaymentAccountPolicy;
use App\Policies\Commerce\SellerPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\DiningTablePolicy;
use App\Policies\EmailBrandingPolicy;
use App\Policies\EmailTemplatePreferencePolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PosCashierInvoicePolicy;
use App\Policies\PosItemCategoryPolicy;
use App\Policies\PosMenuItemPolicy;
use App\Policies\ProductPolicy;
use App\Policies\SubscriptionPolicy;
use App\Policies\WorkspacePolicy;
use App\Services\AI\Contracts\GeminiInteractionsClient;
use App\Services\AI\Gemini\GeminiInteractionsHttpClient;
use App\Services\AI\Inbox\Memory\Contracts\InboxAgentMemoryExtractor;
use App\Services\AI\Inbox\Memory\LlmInboxAgentMemoryExtractor;
use App\Services\AI\Inbox\Tools\EvaluateProductSuitabilityTool;
use App\Services\AI\Inbox\Tools\GetProductIntelligenceTool;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;
use App\Services\AI\Inbox\Tools\SaveCustomerCommerceContextTool;
use App\Services\AI\Inbox\Tools\SearchProductsTool;
use App\EInvoicing\Security\Csr\CsrGenerator;
use App\EInvoicing\Security\Csr\DevTestCsrGenerator;
use App\EInvoicing\Security\PrivateKeyStore;
use App\Services\EInvoicing\Security\DeferredCryptographicStampSigner;
use App\Services\EInvoicing\Security\DevMemoryPrivateKeyStore;
use App\Services\Payment\Contracts\BillableCheckoutPort;
use App\Services\Payment\Contracts\MerchantSettlementProviderInterface;
use App\Services\Payment\Providers\HyperPayMerchantSettlementProvider;
use App\Services\Payment\SharedBillableCheckout;
use App\Services\Subscription\Contracts\SubscriptionBillingProviderInterface;
use App\Support\Email\EmailLibraryBrandingComposer;
use App\Support\Site\PublicSite;
use App\Support\Subscription\SubscriptionBillingGuard;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(InboxAgentToolRegistry::class, static fn (): InboxAgentToolRegistry => new InboxAgentToolRegistry);
        $this->app->bind(InboxAgentMemoryExtractor::class, LlmInboxAgentMemoryExtractor::class);
        $this->app->singleton(WorkspaceContext::class);
        $this->app->bind(
            SubscriptionBillingProviderInterface::class,
            SubscriptionBillingGuard::billingProviderClass($this->app->environment())
        );
        $this->app->bind(BillableCheckoutPort::class, SharedBillableCheckout::class);
        $this->app->bind(
            MerchantSettlementProviderInterface::class,
            HyperPayMerchantSettlementProvider::class
        );
        $this->app->bind(CryptographicStampSigner::class, DeferredCryptographicStampSigner::class);
        $this->app->singleton(PrivateKeyStore::class, DevMemoryPrivateKeyStore::class);
        $this->app->bind(CsrGenerator::class, DevTestCsrGenerator::class);
        $this->app->bind(CryptographicProfile::class, UnresolvedProductionZatcaCryptographicProfile::class);
        $this->app->bind(QrTag9Provider::class, UnresolvedProductionQrTag9Provider::class);
        $this->app->bind(
            GeminiInteractionsClient::class,
            GeminiInteractionsHttpClient::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Notification::extend('central_mail', fn ($app) => $app->make(CentralMailChannel::class));
        View::composer('emails.library.*', EmailLibraryBrandingComposer::class);
        View::composer('*', function ($view): void {
            if (! $view->offsetExists('publicSite')) {
                $view->with('publicSite', PublicSite::current());
            }
        });

        Queue::failing(function (JobFailed $event): void {
            Log::error('queue.job.failed', [
                'connection' => $event->connectionName,
                'job' => $event->job->resolveName(),
                'exception' => $event->exception->getMessage(),
            ]);
        });

        $this->configureMobileRateLimiting();
        $this->configureLegacyRateLimiting();
        $this->registerInboxAgentTools();
        $this->configureAuthRateLimiting();
        $this->configureSubscriptionRateLimiting();

        Gate::policy(Workspace::class, WorkspacePolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(ChatCatalogCategory::class, ChatCatalogCategoryPolicy::class);
        Gate::policy(ChatCatalog::class, ChatCatalogPolicy::class);
        Gate::policy(ChatCatalogDigitalInventoryPool::class, ChatCatalogDigitalInventoryPoolPolicy::class);
        Gate::policy(ChatCatalogDigitalInventoryUnit::class, ChatCatalogDigitalInventoryUnitPolicy::class);
        Gate::policy(ChatCatalogItem::class, ChatCatalogItemPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(DiningTable::class, DiningTablePolicy::class);
        Gate::policy(PosItemCategory::class, PosItemCategoryPolicy::class);
        Gate::policy(PosMenuItem::class, PosMenuItemPolicy::class);
        Gate::policy(PosCashierInvoice::class, PosCashierInvoicePolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Subscription::class, SubscriptionPolicy::class);
        Gate::policy(EmailBranding::class, EmailBrandingPolicy::class);
        Gate::policy(EmailTemplatePreference::class, EmailTemplatePreferencePolicy::class);
        Gate::policy(Seller::class, SellerPolicy::class);
        Gate::policy(SellerPaymentAccount::class, SellerPaymentAccountPolicy::class);
        Gate::policy(CommerceCart::class, CommerceCartPolicy::class);
        Gate::policy(CommerceIntelligenceProfile::class, CommerceIntelligencePolicy::class);

        Seller::observe(CommerceSensitiveAuditObserver::class);
        SellerPaymentAccount::observe(CommerceSensitiveAuditObserver::class);
        SellerAllocation::observe(CommerceSensitiveAuditObserver::class);

        ChatCatalogCategory::observe(ChatCatalogAuditObserver::class);
        ChatCatalog::observe(ChatCatalogAuditObserver::class);
        ChatCatalogCommunicationProfile::observe(ChatCatalogAuditObserver::class);
        ChatCatalogItem::observe(ChatCatalogAuditObserver::class);
        ChatCatalogItemMedia::observe(ChatCatalogAuditObserver::class);
        ChatCatalogDigitalInventoryPool::observe(ChatCatalogSensitiveAuditObserver::class);
        ChatCatalogDigitalInventoryUnit::observe(ChatCatalogSensitiveAuditObserver::class);
        ChatCatalogItemDigitalProfile::observe(ChatCatalogSensitiveAuditObserver::class);
        Product::observe(WorkspaceAuditObserver::class);
        Category::observe(WorkspaceAuditObserver::class);
        Customer::observe(WorkspaceAuditObserver::class);
        Customer::observe(PosSyncChangeObserver::class);
        Order::observe(WorkspaceAuditObserver::class);
        Order::observe(PosSyncChangeObserver::class);
        OrderItem::observe(PosSyncChangeObserver::class);
        DiningTable::observe(WorkspaceAuditObserver::class);
        DiningTable::observe(PosSyncChangeObserver::class);
        TableSession::observe(WorkspaceAuditObserver::class);
        TableSession::observe(PosSyncChangeObserver::class);
        PosItemCategory::observe(WorkspaceAuditObserver::class);
        PosItemCategory::observe(PosSyncChangeObserver::class);
        PosMenuItem::observe(WorkspaceAuditObserver::class);
        PosMenuItem::observe(PosSyncChangeObserver::class);
        PosCashierInvoice::observe(WorkspaceAuditObserver::class);
        PosCashierInvoiceItem::observe(WorkspaceAuditObserver::class);
        Payment::observe(WorkspaceAuditObserver::class);
        PaymentGateway::observe(WorkspaceAuditObserver::class);
        InventoryMovement::observe(WorkspaceAuditObserver::class);
        InventoryMovement::observe(PosSyncChangeObserver::class);
        Conversation::observe(WorkspaceAuditObserver::class);
        EmailAccount::observe(WorkspaceAuditObserver::class);
        EmailBranding::observe(WorkspaceAuditObserver::class);
        EmailTemplatePreference::observe(WorkspaceAuditObserver::class);
        EmailContact::observe(WorkspaceAuditObserver::class);
        EmailMessage::observe(WorkspaceAuditObserver::class);
        FinanceSetting::observe(WorkspaceAuditObserver::class);
        FinanceTaxRate::observe(WorkspaceAuditObserver::class);
        FinanceSupplier::observe(WorkspaceAuditObserver::class);
        FinanceInvoice::observe(WorkspaceAuditObserver::class);
        FinanceInvoiceItem::observe(WorkspaceAuditObserver::class);
        FinanceInvoicePayment::observe(WorkspaceAuditObserver::class);
        FinanceInvoicePayment::observe(FinanceInvoicePaymentObserver::class);
        FinanceReceipt::observe(WorkspaceAuditObserver::class);
        FinanceInvoiceAttachment::observe(WorkspaceAuditObserver::class);
        FinanceQuote::observe(WorkspaceAuditObserver::class);
        FinanceQuoteItem::observe(WorkspaceAuditObserver::class);
        FinanceCreditNote::observe(WorkspaceAuditObserver::class);
        FinanceCreditNoteItem::observe(WorkspaceAuditObserver::class);
        FinanceBillingSchedule::observe(WorkspaceAuditObserver::class);
        FinanceExpense::observe(WorkspaceAuditObserver::class);
        FinanceEmployee::observe(WorkspaceAuditObserver::class);
        FinanceEmployeeProfile::observe(WorkspaceAuditObserver::class);
        FinanceEmployeePayrollRecord::observe(WorkspaceAuditObserver::class);
        FinanceTreasuryAccount::observe(WorkspaceAuditObserver::class);
        FinanceJournalEntry::observe(WorkspaceAuditObserver::class);
        FinanceJournalEntryLine::observe(WorkspaceAuditObserver::class);
        FinanceTreasuryTransfer::observe(WorkspaceAuditObserver::class);
        FinanceBankStatement::observe(WorkspaceAuditObserver::class);
        FinancePurchaseOrder::observe(WorkspaceAuditObserver::class);
        CrmLead::observe(WorkspaceAuditObserver::class);
        FinanceProject::observe(WorkspaceAuditObserver::class);
        FinanceFiscalYear::observe(WorkspaceAuditObserver::class);
        FinancePriceList::observe(WorkspaceAuditObserver::class);
        FinancePayrollAdjustment::observe(WorkspaceAuditObserver::class);
        FinanceSalaryAdvance::observe(WorkspaceAuditObserver::class);
        FinanceSalaryAdvanceRepayment::observe(WorkspaceAuditObserver::class);
        Contract::observe(WorkspaceAuditObserver::class);
        ContractItem::observe(WorkspaceAuditObserver::class);
        ContractAttachment::observe(WorkspaceAuditObserver::class);
        Subscription::observe(WorkspaceAuditObserver::class);
        EmployeeInvitation::observe(WorkspaceAuditObserver::class);
        WhatsAppAccount::observe(WorkspaceAuditObserver::class);
    }

    private function configureMobileRateLimiting(): void
    {
        RateLimiter::for('mobile-api', function (Request $request) {
            return Limit::perMinute(120)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        // Cashier POS polls tables/menu/kitchen — give more headroom than chat API.
        RateLimiter::for('cashier-api', function (Request $request) {
            return Limit::perMinute(300)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('mobile-login', function (Request $request) {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        RateLimiter::for('mobile-messages', function (Request $request) {
            return Limit::perMinute(60)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('mobile-email', function (Request $request) {
            return Limit::perMinute(20)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('mobile-ai', function (Request $request) {
            return Limit::perMinute(10)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('mobile-attachments', function (Request $request) {
            return Limit::perMinute(30)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('mobile-write', function (Request $request) {
            return Limit::perMinute(40)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('mobile-search', function (Request $request) {
            return Limit::perMinute(30)->by((string) ($request->user()?->id ?: $request->ip()));
        });
    }

    private function configureLegacyRateLimiting(): void
    {
        RateLimiter::for('legacy-api', function (Request $request) {
            return Limit::perMinute(120)->by((string) ($request->user()?->id ?: $request->ip()));
        });
    }

    private function configureAuthRateLimiting(): void
    {
        RateLimiter::for('otp-request', function (Request $request) {
            $phone = strtolower(trim((string) $request->input('phone', '')));

            return [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perMinute(5)->by($phone !== '' ? 'otp-phone:'.$phone : $request->ip()),
            ];
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            $phone = strtolower(trim((string) ($request->input('phone') ?: $request->session()->get('otp_phone', ''))));

            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perMinute(8)->by($phone !== '' ? 'otp-verify:'.$phone : $request->ip()),
            ];
        });
    }

    private function configureSubscriptionRateLimiting(): void
    {
        RateLimiter::for('subscription-billing', function (Request $request) {
            $userKey = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(20)->by('subscription-billing:'.$userKey);
        });
    }

    private function registerInboxAgentTools(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        if (! (bool) config('ai.inbox_agent.tools_enabled', true)) {
            return;
        }

        $registry = $this->app->make(InboxAgentToolRegistry::class);
        $registry->register($this->app->make(SearchProductsTool::class));
        $registry->register($this->app->make(EvaluateProductSuitabilityTool::class));
        $registry->register($this->app->make(GetProductIntelligenceTool::class));
        $registry->register($this->app->make(SaveCustomerCommerceContextTool::class));
    }
}
