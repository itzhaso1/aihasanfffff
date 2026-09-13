<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Exceptions\Api\ApiApplicationException;
use App\Models\AuditLog;
use App\Models\Contract\Contract;
use App\Models\Customer;
use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceInvoiceAttachment;
use App\Models\Finance\FinanceInvoicePayment;
use App\Models\Finance\FinanceJournalEntry;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\FinanceSupplier;
use App\Models\Finance\FinanceTaxRate;
use App\Models\Finance\FinanceTreasuryAccount;
use App\Models\Product;
use App\Models\Projects\FinanceProject;
use App\Services\Finance\EInvoiceArtifactService;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceCheckoutService;
use App\Services\Finance\InvoiceEmailService;
use App\Services\Finance\InvoiceInboxService;
use App\Services\Finance\InvoicePaymentService;
use App\Services\Finance\InvoiceReminderEmailService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\Api\InvoiceDocumentReadService;
use App\Services\Finance\PdfInvoiceService;
use App\Services\Finance\PriceListService;
use App\Services\Finance\Tax\TaxCalculationService;
use App\Services\Notification\DomainNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class InvoiceController extends FinanceBaseController
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly InvoicePaymentService $invoicePaymentService,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly PdfInvoiceService $pdfInvoiceService,
        private readonly DomainNotificationService $domainNotificationService,
        private readonly InvoiceInboxService $invoiceInboxService,
        private readonly InvoiceEmailService $invoiceEmailService,
        private readonly InvoiceReminderEmailService $invoiceReminderEmailService,
        private readonly InvoiceCheckoutService $invoiceCheckoutService,
        private readonly PriceListService $priceListService,
        private readonly InvoiceDocumentReadService $invoiceDocumentReadService,
        private readonly EInvoiceArtifactService $eInvoiceArtifactService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'invoices.view');
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $sort = $request->string('sort')->toString();
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';
        $sortable = [
            'invoice_number' => 'invoice_number',
            'issue_date' => 'issue_date',
            'due_date' => 'due_date',
            'total' => 'total',
            'amount_due' => 'amount_due',
            'id' => 'id',
        ];
        $sortColumn = $sortable[$sort] ?? 'id';

        $filtered = $this->invoiceInboxService->applyRequestFilters(
            FinanceInvoice::query()->with(['customer', 'supplier', 'contract']),
            $request
        );

        $invoices = (clone $filtered)
            ->orderBy($sortColumn, $direction)
            ->paginate(15)
            ->withQueryString();

        return view('workspace.finance.invoices.index', [
            'invoices' => $invoices,
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'projects' => Schema::hasTable('finance_projects')
                ? FinanceProject::query()->orderBy('name')->get(['id', 'name'])
                : collect(),
            'contracts' => FinanceInvoice::hasContractColumn()
                ? Contract::query()->orderByDesc('id')->limit(100)->get(['id', 'contract_number', 'title'])
                : collect(),
            'pipeline' => $this->invoiceInboxService->pipelineCounts((int) $workspace->id, $request->string('type')->toString()),
            'totals' => $this->invoiceInboxService->filteredTotals($filtered),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeFinance($request, 'invoices.create');
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        return view('workspace.finance.invoices.create', $this->formCatalog() + [
            'invoice' => new FinanceInvoice([
                'type' => in_array($request->string('type')->toString(), ['sales', 'purchase'], true)
                    ? $request->string('type')->toString()
                    : 'sales',
                'currency' => 'SAR',
                'invoice_status' => 'draft',
                'issue_date' => now()->toDateString(),
                'customer_id' => $request->integer('customer_id') ?: null,
                'contract_id' => $request->integer('contract_id') ?: null,
                'project_id' => $request->integer('project_id') ?: null,
            ]),
            'formAction' => route('workspace.finance.invoices.store'),
            'formMethod' => 'POST',
            'pageTitle' => 'إنشاء فاتورة',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, 'invoices.create');
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $validated = $this->validatedInvoicePayload($request, $workspace->id);
        if (((string) ($validated['invoice_status'] ?? 'issued')) === 'issued') {
            $this->authorizeFinance($request, 'invoices.issue');
        }

        try {
            $invoice = $this->invoiceService->create($workspace, $validated, (int) $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        if (($invoice->invoice_status ?? '') === 'issued') {
            $this->domainNotificationService->notifyFinanceInvoiceEvent(
                $invoice,
                'تم إصدار فاتورة '.$invoice->invoice_number,
                'تم إصدار فاتورة بمبلغ '.number_format((float) $invoice->total, 2).' '.$invoice->currency.'.',
                'invoice_issued'
            );
        }

        return redirect()->route('workspace.finance.invoices.show', $invoice)->with('success', 'تم إنشاء الفاتورة بنجاح.');
    }

    public function edit(Request $request, FinanceInvoice $invoice): View|RedirectResponse
    {
        $this->authorizeFinance($request, 'invoices.edit');
        $this->assertSameWorkspace($invoice->workspace_id);
        if (($invoice->invoice_status ?? $invoice->status) !== 'draft') {
            return redirect()->route('workspace.finance.invoices.show', $invoice)
                ->with('error', 'يمكن تعديل المسودات فقط.');
        }

        return view('workspace.finance.invoices.create', $this->formCatalog() + [
            'invoice' => $invoice->load('items'),
            'formAction' => route('workspace.finance.invoices.update', $invoice),
            'formMethod' => 'PUT',
            'pageTitle' => 'تعديل المسودة '.$invoice->invoice_number,
        ]);
    }

    public function update(Request $request, FinanceInvoice $invoice): RedirectResponse
    {
        $this->authorizeFinance($request, 'invoices.edit');
        $this->assertSameWorkspace($invoice->workspace_id);
        $workspace = $this->currentWorkspace();
        $validated = $this->validatedInvoicePayload($request, $workspace->id);

        try {
            $updated = $this->invoiceService->updateDraft($invoice, $validated, (int) $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('workspace.finance.invoices.show', $updated)->with('success', 'تم تحديث مسودة الفاتورة.');
    }

    public function destroy(Request $request, FinanceInvoice $invoice): RedirectResponse
    {
        $this->authorizeFinance($request, 'invoices.delete');
        $this->assertSameWorkspace($invoice->workspace_id);

        try {
            $this->invoiceService->deleteDraft($invoice);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('workspace.finance.invoices.index')->with('success', 'تم حذف مسودة الفاتورة.');
    }

    public function show(Request $request, FinanceInvoice $invoice): View
    {
        $this->authorizeFinance($request, 'invoices.view');
        $this->assertSameWorkspace($invoice->workspace_id);
        $invoice = $this->invoiceService->syncPaymentStatus($invoice);
        $invoice->load(['payments', 'creditNotes', 'contract', 'customer', 'supplier', 'items', 'attachments']);
        if (Schema::hasColumn('finance_invoices', 'project_id')) {
            $invoice->load('project');
        }

        $paymentIds = $invoice->payments->pluck('id')->all();
        $creditNoteIds = $invoice->creditNotes->pluck('id')->all();

        $journalEntries = FinanceJournalEntry::query()
            ->with(['lines.account', 'poster'])
            ->where(function ($query) use ($invoice, $paymentIds, $creditNoteIds): void {
                $query->where(function ($inner) use ($invoice): void {
                    $inner->where('reference_type', FinanceInvoice::class)
                        ->where('reference_id', $invoice->id);
                });

                if ($paymentIds !== []) {
                    $query->orWhere(function ($inner) use ($paymentIds): void {
                        $inner->where('reference_type', FinanceInvoicePayment::class)
                            ->whereIn('reference_id', $paymentIds);
                    });
                }

                if ($creditNoteIds !== []) {
                    $query->orWhere(function ($inner) use ($creditNoteIds): void {
                        $inner->where('reference_type', FinanceCreditNote::class)
                            ->whereIn('reference_id', $creditNoteIds);
                    });
                }
            })
            ->latest('id')
            ->get();

        $auditLogs = AuditLog::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where(function ($query) use ($invoice, $paymentIds, $creditNoteIds): void {
                $query->where(function ($inner) use ($invoice): void {
                    $inner->where('entity_type', FinanceInvoice::class)
                        ->where('entity_id', $invoice->id);
                });
                if ($paymentIds !== []) {
                    $query->orWhere(function ($inner) use ($paymentIds): void {
                        $inner->where('entity_type', FinanceInvoicePayment::class)
                            ->whereIn('entity_id', $paymentIds);
                    });
                }
                if ($creditNoteIds !== []) {
                    $query->orWhere(function ($inner) use ($creditNoteIds): void {
                        $inner->where('entity_type', FinanceCreditNote::class)
                            ->whereIn('entity_id', $creditNoteIds);
                    });
                }
            })
            ->latest('id')
            ->limit(50)
            ->get();

        return view('workspace.finance.invoices.show', [
            'invoice' => $invoice->load([
                'customer',
                'supplier',
                'items.product',
                'payments.treasuryAccount',
                'payments.creator',
                'payments.reversedBy',
                'payments.receipt',
                'attachments',
                'creditNotes.items',
                'contract',
                'creator',
                'issuer',
                'deliveries.sender',
            ]),
            'treasuryAccounts' => FinanceTreasuryAccount::query()->where('is_active', true)->orderBy('type')->get(),
            'journalEntries' => $journalEntries,
            'auditLogs' => $auditLogs,
            'canViewAccounting' => $request->user()?->can('accounting.view') ?? false,
            'checkout' => $this->invoiceCheckoutService->availability($invoice),
            'zatcaArtifacts' => $this->eInvoiceArtifactService->availabilityForInvoice($invoice),
        ]);
    }

    public function issue(Request $request, FinanceInvoice $invoice): RedirectResponse
    {
        $this->authorizeFinance($request, 'invoices.issue');
        $this->assertSameWorkspace($invoice->workspace_id);

        try {
            $issued = $this->invoiceService->issue($invoice, (int) $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->domainNotificationService->notifyFinanceInvoiceEvent(
            $issued,
            'تم إصدار فاتورة '.$issued->invoice_number,
            'تم اعتماد الفاتورة وأصبحت مستحقة للتحصيل.',
            'invoice_issued'
        );

        return redirect()->route('workspace.finance.invoices.show', $issued)->with('success', 'تم إصدار الفاتورة وترحيل القيد المحاسبي.');
    }

    public function send(Request $request, FinanceInvoice $invoice): RedirectResponse
    {
        $this->authorizeFinance($request, 'invoices.send');
        $this->assertSameWorkspace($invoice->workspace_id);

        $validated = $request->validate([
            'email' => ['required', 'email:filter', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:15000'],
            'attach_pdf' => ['nullable', 'boolean'],
        ], [
            'email.required' => 'لا يوجد بريد إلكتروني للعميل.',
            'email.email' => 'البريد الإلكتروني غير صالح.',
        ]);

        try {
            $delivery = $this->invoiceEmailService->send(
                $invoice,
                [
                    'email' => $validated['email'],
                    'phone' => $validated['phone'] ?? null,
                    'subject' => $validated['subject'] ?? null,
                    'message' => $validated['message'] ?? null,
                    'attach_pdf' => $request->boolean('attach_pdf', true),
                ],
                (int) $request->user()?->id,
            );
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('workspace.finance.invoices.show', $invoice)
            ->with('success', 'تم إرسال الفاتورة إلى '.$delivery->recipient);
    }

    public function remind(Request $request, FinanceInvoice $invoice): RedirectResponse
    {
        $this->authorizeFinance($request, 'invoices.remind');
        $this->assertSameWorkspace($invoice->workspace_id);

        $validated = $request->validate([
            'email' => ['required', 'email:filter', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:15000'],
            'attach_pdf' => ['nullable', 'boolean'],
        ], [
            'email.required' => 'لا يوجد بريد إلكتروني للعميل.',
            'email.email' => 'البريد الإلكتروني غير صالح.',
        ]);

        try {
            $delivery = $this->invoiceReminderEmailService->send(
                $invoice,
                [
                    'email' => $validated['email'],
                    'phone' => $validated['phone'] ?? null,
                    'subject' => $validated['subject'] ?? null,
                    'message' => $validated['message'] ?? null,
                    'attach_pdf' => $request->boolean('attach_pdf', true),
                    'source' => 'manual',
                ],
                (int) $request->user()?->id,
            );
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('workspace.finance.invoices.show', $invoice)
            ->with('success', 'تم إرسال تذكير إلى '.$delivery->recipient);
    }

    public function createCheckout(Request $request, FinanceInvoice $invoice): RedirectResponse
    {
        $this->authorizeFinance($request, 'payments.manage');
        $this->assertSameWorkspace($invoice->workspace_id);

        $result = $this->invoiceCheckoutService->createCheckout($invoice);

        if (! $result->hasCheckoutUrl()) {
            return back()->with('error', $result->message !== '' ? $result->message : 'تعذر إنشاء رابط الدفع الإلكتروني.');
        }

        $invoice->refresh();
        if ((float) $invoice->amount_due <= 0.009 || (string) $invoice->payment_status === 'paid') {
            return back()->with('error', 'إنشاء رابط الدفع لا يجوز أن يغيّر حالة الفاتورة إلى مدفوعة.');
        }

        return redirect()
            ->route('workspace.finance.invoices.show', $invoice)
            ->with('success', 'تم إنشاء رابط الدفع الإلكتروني. التأكيد يتم عبر بوابة الدفع المشتركة وليس من إنشاء الرابط.');
    }

    public function cancel(Request $request, FinanceInvoice $invoice): RedirectResponse
    {
        $this->authorizeFinance($request, 'invoices.cancel');
        $this->assertSameWorkspace($invoice->workspace_id);

        try {
            $this->invoiceService->cancel($invoice, (int) $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('workspace.finance.invoices.show', $invoice)->with('success', 'تم إلغاء الفاتورة وعكس الأثر المحاسبي إن وُجد.');
    }

    public function storePayment(Request $request, FinanceInvoice $invoice): RedirectResponse
    {
        $this->authorizeFinance($request, 'payments.manage');
        $this->assertSameWorkspace($invoice->workspace_id);
        $validated = $request->validate([
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'in:cash,bank_transfer,card,other'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'treasury_account_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_treasury_accounts', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $invoice->workspace_id)
                ),
            ],
        ]);

        try {
            $this->invoicePaymentService->recordPayment($invoice, $validated, (int) $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $fresh = $invoice->fresh();
        $this->domainNotificationService->notifyFinanceInvoiceEvent(
            $fresh,
            'تم استلام دفعة على الفاتورة '.$fresh->invoice_number,
            'تم تسجيل دفعة بقيمة '.number_format((float) $validated['amount'], 2).' '.$fresh->currency.'.',
            'invoice_payment'
        );

        return redirect()->route('workspace.finance.invoices.show', $invoice)->with('success', 'تم تسجيل الدفعة وتحديث الفاتورة.');
    }

    public function reversePayment(Request $request, FinanceInvoice $invoice, FinanceInvoicePayment $payment): RedirectResponse
    {
        $this->authorizeFinance($request, 'invoices.reverse_payment');
        $this->assertSameWorkspace($invoice->workspace_id);
        abort_unless((int) $payment->invoice_id === (int) $invoice->id, 404);

        $validated = $request->validate([
            'reversal_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->invoicePaymentService->reversePayment($payment, (int) $request->user()?->id, $validated['reversal_reason'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('workspace.finance.invoices.show', $invoice)->with('success', 'تم عكس الدفعة وقيدها المحاسبي.');
    }

    public function storeAttachment(Request $request, FinanceInvoice $invoice): RedirectResponse
    {
        $this->authorizeFinance($request, 'invoices.edit');
        $this->assertSameWorkspace($invoice->workspace_id);
        $request->validate([
            'attachments' => ['required', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);

        try {
            $this->invoiceService->storeAttachments($invoice, $request->file('attachments', []), (int) $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('workspace.finance.invoices.show', $invoice)->with('success', 'تم رفع المرفق.');
    }

    public function downloadAttachment(Request $request, FinanceInvoice $invoice, FinanceInvoiceAttachment $attachment)
    {
        $this->authorizeFinance($request, 'invoices.view');
        $this->assertSameWorkspace($invoice->workspace_id);
        abort_unless((int) $attachment->invoice_id === (int) $invoice->id, 404);

        return Storage::disk('public')->download(
            $attachment->file_path,
            $attachment->file_name ?: ('invoice-attachment-'.$attachment->id)
        );
    }

    public function destroyAttachment(Request $request, FinanceInvoice $invoice, FinanceInvoiceAttachment $attachment): RedirectResponse
    {
        $this->authorizeFinance($request, 'invoices.edit');
        $this->assertSameWorkspace($invoice->workspace_id);
        abort_unless((int) $attachment->invoice_id === (int) $invoice->id, 404);

        try {
            $this->invoiceService->deleteAttachment($attachment);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('workspace.finance.invoices.show', $invoice)->with('success', 'تم حذف المرفق.');
    }

    public function downloadPdf(Request $request, FinanceInvoice $invoice)
    {
        $this->authorizeFinance($request, 'invoices.view');
        $this->assertSameWorkspace($invoice->workspace_id);
        $invoice = $this->invoiceService->syncPaymentStatus($invoice);

        try {
            return $this->pdfInvoiceService->download($invoice);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function downloadXml(Request $request, FinanceInvoice $invoice)
    {
        $this->authorizeFinance($request, 'invoices.view');
        $this->assertSameWorkspace($invoice->workspace_id);

        try {
            $dto = $this->invoiceDocumentReadService->xmlForFinance($invoice);
        } catch (ApiApplicationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $filename = ($invoice->invoice_number ?: 'invoice-'.$invoice->id).'.xml';

        return response($dto->xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function showQr(Request $request, FinanceInvoice $invoice): View|RedirectResponse
    {
        $this->authorizeFinance($request, 'invoices.view');
        $this->assertSameWorkspace($invoice->workspace_id);

        try {
            $dto = $this->invoiceDocumentReadService->qrForFinance($invoice);
        } catch (ApiApplicationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return view('workspace.finance.invoices.qr', [
            'invoice' => $invoice,
            'qr' => $dto->toArray(),
            'zatcaArtifacts' => $this->eInvoiceArtifactService->availabilityForInvoice($invoice),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formCatalog(): array
    {
        $workspace = $this->currentWorkspace();
        $setting = FinanceSetting::forWorkspaceId((int) $workspace->id);

        return [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'phone']),
            'suppliers' => FinanceSupplier::query()->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->orderBy('name')->get(['id', 'name', 'price', 'currency', 'sku']),
            'taxRates' => FinanceTaxRate::query()->where('is_active', true)->orderByDesc('is_default')->get(['id', 'name', 'type', 'rate', 'code']),
            'contracts' => FinanceInvoice::hasContractColumn()
                ? Contract::query()->orderByDesc('id')->limit(200)->get(['id', 'contract_number', 'title', 'customer_id'])
                : collect(),
            'projects' => Schema::hasTable('finance_projects')
                ? FinanceProject::query()->orderBy('name')->get(['id', 'name'])
                : collect(),
            'allowManualInvoiceNumbers' => $setting?->allowsManualInvoiceNumbers() ?? false,
            'defaultTaxRate' => (float) ($setting?->default_vat_rate ?? TaxCalculationService::FALLBACK_STANDARD_RATE),
            'listPrices' => $this->priceListService->effectivePricesByProductId((int) $workspace->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedInvoicePayload(Request $request, int $workspaceId): array
    {
        $validated = $request->validate([
            'type' => ['required', 'in:sales,purchase'],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspaceId)
                ),
            ],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_suppliers', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspaceId)
                ),
            ],
            'invoice_number' => ['nullable', 'string', 'max:255'],
            'tax_document_subtype' => ['nullable', 'in:standard,simplified'],
            'zatca_requirement' => ['nullable', 'in:not_required,required'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'supply_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'invoice_status' => ['nullable', 'in:draft,issued'],
            'status' => ['nullable', 'in:draft,sent,unpaid,partial,paid,overdue,cancelled'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'contract_id' => [
                'nullable',
                'integer',
                Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_projects', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'tax_profile_type' => ['nullable', 'in:standard,zero_rated,exempt,out_of_scope'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_price_mode' => ['nullable', 'in:exclusive,inclusive'],
            'items_json' => ['required', 'string'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);

        if (
            $validated['type'] === 'sales'
            && empty($validated['customer_id'])
            && trim((string) ($validated['customer_name'] ?? '')) === ''
        ) {
            throw ValidationException::withMessages([
                'customer_name' => 'يرجى اختيار عميل مسجل أو إدخال اسم عميل نقدي.',
            ]);
        }

        if ($validated['type'] === 'purchase' && empty($validated['supplier_id'])) {
            throw ValidationException::withMessages([
                'supplier_id' => 'يرجى اختيار المورد لفاتورة الشراء.',
            ]);
        }

        $items = json_decode($validated['items_json'], true);
        if (! is_array($items) || $items === []) {
            throw ValidationException::withMessages([
                'items_json' => 'يجب إدخال عنصر واحد على الأقل في الفاتورة.',
            ]);
        }

        $items = array_map(function ($item) {
            if (! is_array($item)) {
                return $item;
            }

            unset($item['total'], $item['tax_amount'], $item['taxable_amount'], $item['subtotal']);

            $productId = (int) ($item['product_id'] ?? 0);
            $item['product_id'] = $productId > 0 ? $productId : null;
            $item['unit'] = mb_substr(trim((string) ($item['unit'] ?? '')), 0, 32);

            return $item;
        }, $items);

        $payload = Arr::except($validated, ['items_json', 'attachments']);
        if (! isset($payload['invoice_status']) && isset($payload['status'])) {
            $payload['invoice_status'] = $payload['status'];
        }
        $payload['items'] = $items;
        $payload['attachments'] = $request->file('attachments', []) ?: [];

        return $payload;
    }
}
