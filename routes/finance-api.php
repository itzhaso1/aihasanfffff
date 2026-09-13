<?php

use App\Http\Controllers\Api\Finance\V1\BootstrapController;
use App\Http\Controllers\Api\Finance\V1\CatalogController;
use App\Http\Controllers\Api\Finance\V1\ContractClientController;
use App\Http\Controllers\Api\Finance\V1\CopilotClientController;
use App\Http\Controllers\Api\Finance\V1\CreditNoteClientController;
use App\Http\Controllers\Api\Finance\V1\CustomerController;
use App\Http\Controllers\Api\Finance\V1\DashboardController;
use App\Http\Controllers\Api\Finance\V1\EmployeeClientController;
use App\Http\Controllers\Api\Finance\V1\ExpenseController;
use App\Http\Controllers\Api\Finance\V1\ExportController;
use App\Http\Controllers\Api\Finance\V1\FiscalYearClientController;
use App\Http\Controllers\Api\Finance\V1\HubController;
use App\Http\Controllers\Api\Finance\V1\InvoiceController;
use App\Http\Controllers\Api\Finance\V1\LeadClientController;
use App\Http\Controllers\Api\Finance\V1\NoteController;
use App\Http\Controllers\Api\Finance\V1\PaymentController;
use App\Http\Controllers\Api\Finance\V1\PayrollAdjustmentClientController;
use App\Http\Controllers\Api\Finance\V1\PosInvoiceController;
use App\Http\Controllers\Api\Finance\V1\PriceListClientController;
use App\Http\Controllers\Api\Finance\V1\ProjectClientController;
use App\Http\Controllers\Api\Finance\V1\PurchaseController;
use App\Http\Controllers\Api\Finance\V1\PurchaseOrderClientController;
use App\Http\Controllers\Api\Finance\V1\QuoteController;
use App\Http\Controllers\Api\Finance\V1\ReceiptController;
use App\Http\Controllers\Api\Finance\V1\ReportController;
use App\Http\Controllers\Api\Finance\V1\SalaryAdvanceClientController;
use App\Http\Controllers\Api\Finance\V1\SalesInvoiceController;
use App\Http\Controllers\Api\Finance\V1\SearchController;
use App\Http\Controllers\Api\Finance\V1\SettingsController;
use App\Http\Controllers\Api\Finance\V1\StatementController;
use App\Http\Controllers\Api\Finance\V1\TreasuryClientController;
use App\Http\Controllers\Api\Finance\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/workspaces/current', [WorkspaceController::class, 'current']);
Route::post('/workspaces/switch', [WorkspaceController::class, 'switch'])->middleware('throttle:mobile-write');

Route::get('/bootstrap', BootstrapController::class);
Route::get('/dashboard', DashboardController::class);
Route::get('/search', SearchController::class);

Route::get('/customers', [CustomerController::class, 'index']);
Route::post('/customers', [CustomerController::class, 'store'])->middleware('throttle:mobile-write');
Route::get('/customers/{customer}', [CustomerController::class, 'show']);
Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware('throttle:mobile-write');

Route::get('/quotes', [QuoteController::class, 'index']);
Route::post('/quotes', [QuoteController::class, 'store'])->middleware('throttle:mobile-write');
Route::get('/quotes/{quote}', [QuoteController::class, 'show']);
Route::put('/quotes/{quote}', [QuoteController::class, 'update'])->middleware('throttle:mobile-write');
Route::delete('/quotes/{quote}', [QuoteController::class, 'destroy'])->middleware('throttle:mobile-write');
Route::post('/quotes/{quote}/issue', [QuoteController::class, 'issue'])->middleware('throttle:mobile-write');
Route::post('/quotes/{quote}/cancel', [QuoteController::class, 'cancel'])->middleware('throttle:mobile-write');
Route::post('/quotes/{quote}/accept', [QuoteController::class, 'accept'])->middleware('throttle:mobile-write');
Route::post('/quotes/{quote}/reject', [QuoteController::class, 'reject'])->middleware('throttle:mobile-write');
Route::post('/quotes/{quote}/convert', [QuoteController::class, 'convert'])->middleware('throttle:mobile-write');
Route::post('/quotes/{quote}/send', [QuoteController::class, 'send'])->middleware('throttle:mobile-write');
Route::get('/quotes/{quote}/pdf', [QuoteController::class, 'pdf']);
Route::post('/quotes/{quote}/attachments', [QuoteController::class, 'storeAttachment'])->middleware('throttle:mobile-write');
Route::get('/quotes/{quote}/attachments/{attachment}', [QuoteController::class, 'downloadAttachment']);
Route::delete('/quotes/{quote}/attachments/{attachment}', [QuoteController::class, 'destroyAttachment'])->middleware('throttle:mobile-write');

Route::get('/sales-invoices', [SalesInvoiceController::class, 'index']);
Route::post('/sales-invoices', [SalesInvoiceController::class, 'store'])->middleware('throttle:mobile-write');
Route::get('/sales-invoices/{invoice}', [SalesInvoiceController::class, 'show']);
Route::put('/sales-invoices/{invoice}', [SalesInvoiceController::class, 'update'])->middleware('throttle:mobile-write');
Route::delete('/sales-invoices/{invoice}', [SalesInvoiceController::class, 'destroy'])->middleware('throttle:mobile-write');
Route::post('/sales-invoices/{invoice}/issue', [SalesInvoiceController::class, 'issue'])->middleware('throttle:mobile-write');
Route::post('/sales-invoices/{invoice}/cancel', [SalesInvoiceController::class, 'cancel'])->middleware('throttle:mobile-write');
Route::post('/sales-invoices/{invoice}/send', [SalesInvoiceController::class, 'send'])->middleware('throttle:mobile-write');
Route::post('/sales-invoices/{invoice}/remind', [SalesInvoiceController::class, 'remind'])->middleware('throttle:mobile-write');
Route::get('/sales-invoices/{invoice}/checkout', [SalesInvoiceController::class, 'checkoutAvailability']);
Route::post('/sales-invoices/{invoice}/checkout', [SalesInvoiceController::class, 'checkout'])->middleware('throttle:mobile-write');
Route::post('/sales-invoices/{invoice}/payments', [SalesInvoiceController::class, 'storePayment'])->middleware('throttle:mobile-write');
Route::post('/sales-invoices/{invoice}/payments/{payment}/reverse', [SalesInvoiceController::class, 'reversePayment'])->middleware('throttle:mobile-write');
Route::post('/sales-invoices/{invoice}/attachments', [SalesInvoiceController::class, 'storeAttachment'])->middleware('throttle:mobile-write');
Route::get('/sales-invoices/{invoice}/attachments/{attachment}', [SalesInvoiceController::class, 'downloadAttachment']);
Route::delete('/sales-invoices/{invoice}/attachments/{attachment}', [SalesInvoiceController::class, 'destroyAttachment'])->middleware('throttle:mobile-write');
Route::get('/sales-invoices/{invoice}/pdf', [SalesInvoiceController::class, 'pdf']);
Route::get('/sales-invoices/{invoice}/xml', [SalesInvoiceController::class, 'xml']);
Route::get('/sales-invoices/{invoice}/qr', [SalesInvoiceController::class, 'qr']);

Route::get('/payments', [PaymentController::class, 'index']);
Route::get('/payments/{payment}', [PaymentController::class, 'show']);
Route::post('/payments/{payment}/reverse', [PaymentController::class, 'reverse'])->middleware('throttle:mobile-write');

Route::get('/receipts', [ReceiptController::class, 'index']);
Route::get('/receipts/{receipt}', [ReceiptController::class, 'show']);
Route::post('/receipts/{receipt}/send', [ReceiptController::class, 'send'])->middleware('throttle:mobile-write');
Route::get('/receipts/{receipt}/pdf', [ReceiptController::class, 'pdf']);

Route::get('/statements', [StatementController::class, 'show']);

Route::get('/credit-notes', [CreditNoteClientController::class, 'index']);
Route::post('/credit-notes', [CreditNoteClientController::class, 'store'])->middleware('throttle:mobile-write');
Route::get('/credit-notes/{creditNote}', [CreditNoteClientController::class, 'show']);
Route::post('/credit-notes/{creditNote}/issue', [CreditNoteClientController::class, 'issue'])->middleware('throttle:mobile-write');
Route::post('/credit-notes/{creditNote}/cancel', [CreditNoteClientController::class, 'cancel'])->middleware('throttle:mobile-write');
Route::get('/credit-notes/{creditNote}/pdf', [CreditNoteClientController::class, 'pdf']);

Route::get('/contracts', [ContractClientController::class, 'index']);
Route::post('/contracts', [ContractClientController::class, 'store'])->middleware('throttle:mobile-write');
Route::get('/contracts/{contract}', [ContractClientController::class, 'show']);
Route::put('/contracts/{contract}', [ContractClientController::class, 'update'])->middleware('throttle:mobile-write');
Route::post('/contracts/{contract}/activate', [ContractClientController::class, 'activate'])->middleware('throttle:mobile-write');
Route::post('/contracts/{contract}/close', [ContractClientController::class, 'close'])->middleware('throttle:mobile-write');
Route::post('/contracts/{contract}/cancel', [ContractClientController::class, 'cancel'])->middleware('throttle:mobile-write');
Route::get('/contracts/{contract}/pdf', [ContractClientController::class, 'pdf']);
Route::post('/contracts/{contract}/attachments', [ContractClientController::class, 'storeAttachment'])->middleware('throttle:mobile-write');
Route::get('/contracts/{contract}/attachments/{attachment}', [ContractClientController::class, 'downloadAttachment']);
Route::delete('/contracts/{contract}/attachments/{attachment}', [ContractClientController::class, 'destroyAttachment'])->middleware('throttle:mobile-write');
Route::post('/contracts/{contract}/billing-schedules', [ContractClientController::class, 'storeSchedule'])->middleware('throttle:mobile-write');
Route::post('/contracts/{contract}/billing-schedules/{schedule}/activate', [ContractClientController::class, 'activateSchedule'])->middleware('throttle:mobile-write');
Route::post('/contracts/{contract}/billing-schedules/{schedule}/pause', [ContractClientController::class, 'pauseSchedule'])->middleware('throttle:mobile-write');
Route::post('/contracts/{contract}/billing-schedules/{schedule}/cancel', [ContractClientController::class, 'cancelSchedule'])->middleware('throttle:mobile-write');
Route::post('/contracts/{contract}/billing-schedules/{schedule}/generate', [ContractClientController::class, 'generateInvoice'])->middleware('throttle:mobile-write');

Route::get('/expenses', [ExpenseController::class, 'index']);
Route::post('/expenses', [ExpenseController::class, 'store'])->middleware('throttle:mobile-write');
Route::get('/expenses/{expense}', [ExpenseController::class, 'show']);
Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->middleware('throttle:mobile-write');
Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->middleware('throttle:mobile-write');
Route::get('/expenses/{expense}/attachment', [ExpenseController::class, 'attachment']);

Route::get('/purchases', [PurchaseController::class, 'index']);
Route::post('/purchases', [PurchaseController::class, 'store'])->middleware('throttle:mobile-write');
Route::get('/purchases/aging', [PurchaseController::class, 'aging']);
Route::get('/purchases/{invoice}', [PurchaseController::class, 'show']);
Route::put('/purchases/{invoice}', [PurchaseController::class, 'update'])->middleware('throttle:mobile-write');
Route::post('/purchases/{invoice}/issue', [PurchaseController::class, 'issue'])->middleware('throttle:mobile-write');
Route::post('/purchases/{invoice}/cancel', [PurchaseController::class, 'cancel'])->middleware('throttle:mobile-write');
Route::post('/purchases/{invoice}/payments', [PurchaseController::class, 'storePayment'])->middleware('throttle:mobile-write');
Route::post('/purchases/{invoice}/attachments', [PurchaseController::class, 'storeAttachment'])->middleware('throttle:mobile-write');
Route::get('/purchases/{invoice}/attachments/{attachment}', [PurchaseController::class, 'downloadAttachment']);
Route::delete('/purchases/{invoice}/attachments/{attachment}', [PurchaseController::class, 'destroyAttachment'])->middleware('throttle:mobile-write');
Route::get('/purchases/{invoice}/pdf', [PurchaseController::class, 'pdf']);
Route::get('/suppliers', [PurchaseController::class, 'suppliers']);
Route::post('/suppliers', [PurchaseController::class, 'storeSupplier'])->middleware('throttle:mobile-write');
Route::get('/suppliers/{supplier}', [PurchaseController::class, 'showSupplier']);
Route::put('/suppliers/{supplier}', [PurchaseController::class, 'updateSupplier'])->middleware('throttle:mobile-write');

Route::get('/sales', [HubController::class, 'sales']);
Route::get('/billing', [HubController::class, 'billing']);
Route::get('/vat', [HubController::class, 'vat']);
Route::get('/alerts', [HubController::class, 'alerts']);
Route::get('/accounting', [HubController::class, 'accounting']);
Route::get('/banks', [HubController::class, 'banks']);
Route::get('/exports', [ExportController::class, 'index']);

Route::get('/products', [CatalogController::class, 'products']);
Route::get('/products/{product}', [CatalogController::class, 'showProduct']);
Route::get('/inventory', [CatalogController::class, 'inventory']);

Route::get('/projects', [ProjectClientController::class, 'index']);
Route::post('/projects', [ProjectClientController::class, 'store'])->middleware('throttle:mobile-write');
Route::get('/projects/{project}', [ProjectClientController::class, 'show']);

Route::get('/price-lists', [PriceListClientController::class, 'index']);
Route::post('/price-lists', [PriceListClientController::class, 'store'])->middleware('throttle:mobile-write');
Route::put('/price-lists/items/{item}', [PriceListClientController::class, 'updateItem'])->middleware('throttle:mobile-write');
Route::delete('/price-lists/items/{item}', [PriceListClientController::class, 'deleteItem'])->middleware('throttle:mobile-write');
Route::get('/price-lists/{priceList}', [PriceListClientController::class, 'show']);
Route::put('/price-lists/{priceList}', [PriceListClientController::class, 'update'])->middleware('throttle:mobile-write');
Route::post('/price-lists/{priceList}/items', [PriceListClientController::class, 'addItem'])->middleware('throttle:mobile-write');
Route::post('/price-lists/{priceList}/approve', [PriceListClientController::class, 'approve'])->middleware('throttle:mobile-write');
Route::post('/price-lists/{priceList}/mark-draft', [PriceListClientController::class, 'markDraft'])->middleware('throttle:mobile-write');
Route::post('/price-lists/{priceList}/cancel', [PriceListClientController::class, 'cancel'])->middleware('throttle:mobile-write');

Route::get('/purchase-orders', [PurchaseOrderClientController::class, 'index']);
Route::post('/purchase-orders', [PurchaseOrderClientController::class, 'store'])->middleware('throttle:mobile-write');
Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderClientController::class, 'show']);
Route::post('/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderClientController::class, 'submit'])->middleware('throttle:mobile-write');
Route::post('/purchase-orders/{purchaseOrder}/receive', [PurchaseOrderClientController::class, 'receive'])->middleware('throttle:mobile-write');
Route::post('/purchase-orders/{purchaseOrder}/bill', [PurchaseOrderClientController::class, 'bill'])->middleware('throttle:mobile-write');

Route::get('/leads', [LeadClientController::class, 'index']);
Route::post('/leads', [LeadClientController::class, 'store'])->middleware('throttle:mobile-write');
Route::get('/leads/{lead}', [LeadClientController::class, 'show']);
Route::post('/leads/{lead}/convert', [LeadClientController::class, 'convert'])->middleware('throttle:mobile-write');
Route::post('/leads/{lead}/lost', [LeadClientController::class, 'markLost'])->middleware('throttle:mobile-write');

Route::get('/treasury', [TreasuryClientController::class, 'index']);
Route::post('/treasury/transfers', [TreasuryClientController::class, 'transfer'])->middleware('throttle:mobile-write');
Route::post('/treasury/statements', [TreasuryClientController::class, 'storeStatement'])->middleware('throttle:mobile-write');
Route::get('/treasury/statements/{statement}', [TreasuryClientController::class, 'showStatement']);
Route::post('/treasury/statements/{statement}/lines', [TreasuryClientController::class, 'storeLines'])->middleware('throttle:mobile-write');
Route::post('/treasury/statements/{statement}/suggest', [TreasuryClientController::class, 'suggest'])->middleware('throttle:mobile-write');
Route::post('/treasury/statements/{statement}/lines/{line}/match', [TreasuryClientController::class, 'matchLine'])->middleware('throttle:mobile-write');
Route::post('/treasury/statements/{statement}/lines/{line}/ignore', [TreasuryClientController::class, 'ignoreLine'])->middleware('throttle:mobile-write');
Route::post('/treasury/statements/{statement}/complete', [TreasuryClientController::class, 'complete'])->middleware('throttle:mobile-write');

Route::post('/copilot/ask', [CopilotClientController::class, 'ask'])->middleware('throttle:mobile-write');

Route::get('/fiscal-years', [FiscalYearClientController::class, 'index']);
Route::post('/fiscal-years', [FiscalYearClientController::class, 'store'])->middleware('throttle:mobile-write');
Route::post('/fiscal-years/periods/{period}/status', [FiscalYearClientController::class, 'setPeriodStatus'])->middleware('throttle:mobile-write');
Route::get('/fiscal-years/{fiscalYear}', [FiscalYearClientController::class, 'show']);
Route::put('/fiscal-years/{fiscalYear}', [FiscalYearClientController::class, 'update'])->middleware('throttle:mobile-write');
Route::post('/fiscal-years/{fiscalYear}/close', [FiscalYearClientController::class, 'close'])->middleware('throttle:mobile-write');
Route::post('/fiscal-years/{fiscalYear}/open', [FiscalYearClientController::class, 'open'])->middleware('throttle:mobile-write');
Route::post('/fiscal-years/{fiscalYear}/generate-monthly-periods', [FiscalYearClientController::class, 'generateMonthlyPeriods'])->middleware('throttle:mobile-write');
Route::post('/fiscal-years/{fiscalYear}/periods', [FiscalYearClientController::class, 'storePeriod'])->middleware('throttle:mobile-write');

Route::get('/reports/{report}', [ReportController::class, 'show']);
Route::get('/exports/{dataset}', [ExportController::class, 'download']);
Route::get('/settings', [SettingsController::class, 'show']);
Route::put('/settings', [SettingsController::class, 'update'])->middleware('throttle:mobile-write');
Route::get('/settings/logo', [SettingsController::class, 'logo']);
Route::post('/settings/logo', [SettingsController::class, 'uploadLogo'])->middleware('throttle:mobile-write');
Route::delete('/settings/logo', [SettingsController::class, 'removeLogo'])->middleware('throttle:mobile-write');
Route::post('/settings/tax-rates', [SettingsController::class, 'storeTaxRate'])->middleware('throttle:mobile-write');
Route::post('/settings/treasury-accounts', [SettingsController::class, 'storeTreasuryAccount'])->middleware('throttle:mobile-write');

Route::get('/employees', [EmployeeClientController::class, 'index']);
Route::post('/employees', [EmployeeClientController::class, 'store'])->middleware('throttle:mobile-write');
Route::get('/employees/{employee}', [EmployeeClientController::class, 'show']);
Route::put('/employees/{employee}', [EmployeeClientController::class, 'update'])->middleware('throttle:mobile-write');
Route::delete('/employees/{employee}', [EmployeeClientController::class, 'destroy'])->middleware('throttle:mobile-write');
Route::post('/employees/{employee}/payroll-records', [EmployeeClientController::class, 'storePayrollRecord'])->middleware('throttle:mobile-write');
Route::get('/payroll', [EmployeeClientController::class, 'overview']);

Route::get('/salary-advances', [SalaryAdvanceClientController::class, 'index']);
Route::post('/salary-advances', [SalaryAdvanceClientController::class, 'store'])->middleware('throttle:mobile-write');
Route::get('/salary-advances/{advance}', [SalaryAdvanceClientController::class, 'show']);
Route::post('/salary-advances/{advance}/repay', [SalaryAdvanceClientController::class, 'repay'])->middleware('throttle:mobile-write');

Route::get('/payroll-adjustments', [PayrollAdjustmentClientController::class, 'index']);
Route::post('/payroll-adjustments', [PayrollAdjustmentClientController::class, 'store'])->middleware('throttle:mobile-write');
Route::post('/payroll-adjustments/{adjustment}/approve', [PayrollAdjustmentClientController::class, 'approve'])->middleware('throttle:mobile-write');
Route::post('/payroll-adjustments/{adjustment}/post', [PayrollAdjustmentClientController::class, 'post'])->middleware('throttle:mobile-write');
Route::post('/payroll-adjustments/{adjustment}/cancel', [PayrollAdjustmentClientController::class, 'cancel'])->middleware('throttle:mobile-write');

/*
|--------------------------------------------------------------------------
| Existing e-invoice / compliance surface (Phase 10 contract — do not break)
|--------------------------------------------------------------------------
*/
Route::get('/invoices', [InvoiceController::class, 'index']);
Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->middleware('throttle:mobile-write');
Route::get('/invoices/{invoice}/xml', [InvoiceController::class, 'xml']);
Route::get('/invoices/{invoice}/qr', [InvoiceController::class, 'qr']);
Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);
Route::post('/invoices/{invoice}/cryptographic-stamp', [InvoiceController::class, 'cryptographicStamp'])
    ->middleware('throttle:mobile-write');

Route::get('/notes', [NoteController::class, 'index']);
Route::get('/notes/{note}', [NoteController::class, 'show']);
Route::post('/notes/{note}/issue', [NoteController::class, 'issue'])->middleware('throttle:mobile-write');
Route::get('/notes/{note}/xml', [NoteController::class, 'xml']);
Route::get('/notes/{note}/qr', [NoteController::class, 'qr']);
Route::post('/notes/{note}/cryptographic-stamp', [NoteController::class, 'cryptographicStamp'])
    ->middleware('throttle:mobile-write');

Route::get('/pos-invoices', [PosInvoiceController::class, 'index']);
Route::get('/pos-invoices/{posInvoice}', [PosInvoiceController::class, 'show']);
Route::get('/pos-invoices/{posInvoice}/xml', [PosInvoiceController::class, 'xml']);
Route::get('/pos-invoices/{posInvoice}/qr', [PosInvoiceController::class, 'qr']);
Route::post('/pos-invoices/{posInvoice}/cryptographic-stamp', [PosInvoiceController::class, 'cryptographicStamp'])
    ->middleware('throttle:mobile-write');
