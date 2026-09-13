<?php

namespace App\Providers;

use App\EInvoicing\QR\Harness\QrTag9Provider;
use App\EInvoicing\QR\Harness\UnresolvedProductionQrTag9Provider;
use App\EInvoicing\Security\CryptographicStampSigner;
use App\EInvoicing\Security\Harness\CryptographicProfile;
use App\EInvoicing\Security\Harness\UnresolvedProductionZatcaCryptographicProfile;
use App\Models\Category;
use App\Models\Contract\Contract;
use App\Models\Contract\ContractAttachment;
use App\Models\Contract\ContractItem;
use App\Models\Conversation;
use App\Models\Crm\CrmLead;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\EmailAccount;
use App\Models\EmailContact;
use App\Models\EmailMessage;
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
use App\Observers\FinanceInvoicePaymentObserver;
use App\Observers\PosSyncChangeObserver;
use App\Observers\WorkspaceAuditObserver;
use App\Policies\CategoryPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\DiningTablePolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PosCashierInvoicePolicy;
use App\Policies\PosItemCategoryPolicy;
use App\Policies\PosMenuItemPolicy;
use App\Policies\ProductPolicy;
use App\Policies\SubscriptionPolicy;
use App\Policies\WorkspacePolicy;
use App\Services\EInvoicing\Security\DeferredCryptographicStampSigner;
use App\Services\Payment\Contracts\BillableCheckoutPort;
use App\Services\Payment\Contracts\MerchantSettlementProviderInterface;
use App\Services\Payment\Providers\HyperPayMerchantSettlementProvider;
use App\Services\Payment\SharedBillableCheckout;
use App\Services\Subscription\Contracts\SubscriptionBillingProviderInterface;
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
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
        $this->app->bind(CryptographicProfile::class, UnresolvedProductionZatcaCryptographicProfile::class);
        $this->app->bind(QrTag9Provider::class, UnresolvedProductionQrTag9Provider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Notification::extend('central_mail', fn ($app) => $app->make(CentralMailChannel::class));

        Queue::failing(function (JobFailed $event): void {
            Log::error('queue.job.failed', [
                'connection' => $event->connectionName,
                'job' => $event->job->resolveName(),
                'exception' => $event->exception->getMessage(),
            ]);
        });

        $this->configureMobileRateLimiting();
        $this->configureAuthRateLimiting();
        $this->configureSubscriptionRateLimiting();

        Gate::policy(Workspace::class, WorkspacePolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
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
}
