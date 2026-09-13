<?php

namespace App\Http\Controllers\Workspace\Finance;

use App\Enums\Finance\QuoteStatus;
use App\Models\Customer;
use App\Models\Finance\FinanceQuote;
use App\Models\Finance\FinanceQuoteAttachment;
use App\Models\Finance\FinanceSetting;
use App\Models\Finance\FinanceTaxRate;
use App\Models\Product;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\PdfQuoteService;
use App\Services\Finance\PriceListService;
use App\Services\Finance\QuoteEmailService;
use App\Services\Finance\QuoteService;
use App\Services\Finance\Tax\TaxCalculationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class QuoteController extends FinanceBaseController
{
    public function __construct(
        private readonly QuoteService $quoteService,
        private readonly QuoteEmailService $quoteEmailService,
        private readonly FinanceBootstrapService $financeBootstrapService,
        private readonly PdfQuoteService $pdfQuoteService,
        private readonly PriceListService $priceListService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeFinance($request, 'quotes.view');
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        $status = $request->string('status')->toString();
        $query = FinanceQuote::query()->with('customer');
        if (in_array($status, [QuoteStatus::Draft->value, QuoteStatus::Issued->value, QuoteStatus::Cancelled->value], true)) {
            $query->where('status', $status);
        }
        if ($search = trim($request->string('search')->toString())) {
            $query->where(function ($inner) use ($search): void {
                $inner->where('quote_number', 'like', '%'.$search.'%')
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', '%'.$search.'%'));
            });
        }

        $quotes = $query->latest('id')->paginate(15)->withQueryString();

        return view('workspace.finance.quotes.index', [
            'quotes' => $quotes,
            'pipeline' => [
                'all' => FinanceQuote::query()->count(),
                QuoteStatus::Draft->value => FinanceQuote::query()->where('status', QuoteStatus::Draft->value)->count(),
                QuoteStatus::Issued->value => FinanceQuote::query()->where('status', QuoteStatus::Issued->value)->count(),
                QuoteStatus::Cancelled->value => FinanceQuote::query()->where('status', QuoteStatus::Cancelled->value)->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeFinance($request, 'quotes.create');
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);

        return view('workspace.finance.quotes.create', $this->formCatalog() + [
            'quote' => new FinanceQuote([
                'currency' => 'SAR',
                'status' => QuoteStatus::Draft->value,
                'issue_date' => now()->toDateString(),
                'expiry_date' => now()->addDays(30)->toDateString(),
                'customer_id' => $request->integer('customer_id') ?: null,
            ]),
            'formAction' => route('workspace.finance.quotes.store'),
            'formMethod' => 'POST',
            'pageTitle' => 'إنشاء عرض سعر',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeFinance($request, 'quotes.create');
        $workspace = $this->currentWorkspace();
        $this->financeBootstrapService->ensureWorkspaceFinanceSetup($workspace);
        $validated = $this->validatedQuotePayload($request, (int) $workspace->id);
        $validated['attachments'] = $request->file('attachments', []) ?: [];
        if (((string) ($validated['status'] ?? 'draft')) === QuoteStatus::Issued->value) {
            $this->authorizeFinance($request, 'quotes.issue');
        }

        try {
            $quote = $this->quoteService->create($workspace, $validated, (int) $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('workspace.finance.quotes.show', $quote)->with('success', 'تم حفظ عرض السعر.');
    }

    public function show(Request $request, FinanceQuote $quote): View
    {
        $this->authorizeFinance($request, 'quotes.view');
        $this->assertSameWorkspace($quote->workspace_id);

        return view('workspace.finance.quotes.show', [
            'quote' => $quote->load([
                'customer',
                'items',
                'creator',
                'issuer',
                'deliveries.sender',
                'convertedInvoice',
                'acceptedByUser',
                'rejectedByUser',
                'convertedByUser',
                'attachments',
            ]),
        ]);
    }

    public function edit(Request $request, FinanceQuote $quote): View|RedirectResponse
    {
        $this->authorizeFinance($request, 'quotes.edit');
        $this->assertSameWorkspace($quote->workspace_id);
        if (! $quote->isDraft()) {
            return redirect()->route('workspace.finance.quotes.show', $quote)
                ->with('error', 'يمكن تعديل المسودات فقط.');
        }

        return view('workspace.finance.quotes.create', $this->formCatalog() + [
            'quote' => $quote->load('items'),
            'formAction' => route('workspace.finance.quotes.update', $quote),
            'formMethod' => 'PUT',
            'pageTitle' => 'تعديل المسودة '.$quote->quote_number,
        ]);
    }

    public function update(Request $request, FinanceQuote $quote): RedirectResponse
    {
        $this->authorizeFinance($request, 'quotes.edit');
        $this->assertSameWorkspace($quote->workspace_id);
        $workspace = $this->currentWorkspace();
        $validated = $this->validatedQuotePayload($request, (int) $workspace->id);
        $validated['attachments'] = $request->file('attachments', []) ?: [];

        try {
            $updated = $this->quoteService->updateDraft($quote, $validated, (int) $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('workspace.finance.quotes.show', $updated)->with('success', 'تم تحديث مسودة عرض السعر.');
    }

    public function destroy(Request $request, FinanceQuote $quote): RedirectResponse
    {
        $this->authorizeFinance($request, 'quotes.delete');
        $this->assertSameWorkspace($quote->workspace_id);

        try {
            $this->quoteService->deleteDraft($quote);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('workspace.finance.quotes.index')->with('success', 'تم حذف مسودة عرض السعر.');
    }

    public function issue(Request $request, FinanceQuote $quote): RedirectResponse
    {
        $this->authorizeFinance($request, 'quotes.issue');
        $this->assertSameWorkspace($quote->workspace_id);

        try {
            $issued = $this->quoteService->issue($quote, (int) $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('workspace.finance.quotes.show', $issued)->with('success', 'تم إصدار عرض السعر. هذا المستند ليس فاتورة ولا يُرحّل محاسبيًا.');
    }

    public function cancel(Request $request, FinanceQuote $quote): RedirectResponse
    {
        $this->authorizeFinance($request, 'quotes.cancel');
        $this->assertSameWorkspace($quote->workspace_id);

        try {
            $this->quoteService->cancel($quote);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('workspace.finance.quotes.show', $quote)->with('success', 'تم إلغاء عرض السعر.');
    }

    public function accept(Request $request, FinanceQuote $quote): RedirectResponse
    {
        $this->authorizeFinance($request, 'quotes.accept');
        $this->assertSameWorkspace($quote->workspace_id);

        try {
            $accepted = $this->quoteService->accept($quote, (int) $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('workspace.finance.quotes.show', $accepted)
            ->with('success', 'تم قبول عرض السعر. القبول لا ينشئ فاتورة تلقائياً.');
    }

    public function reject(Request $request, FinanceQuote $quote): RedirectResponse
    {
        $this->authorizeFinance($request, 'quotes.reject');
        $this->assertSameWorkspace($quote->workspace_id);

        $validated = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $rejected = $this->quoteService->reject(
                $quote,
                (int) $request->user()?->id,
                $validated['rejection_reason'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('workspace.finance.quotes.show', $rejected)
            ->with('success', 'تم رفض عرض السعر.');
    }

    public function convert(Request $request, FinanceQuote $quote): RedirectResponse
    {
        $this->authorizeFinance($request, 'quotes.convert');
        $this->assertSameWorkspace($quote->workspace_id);

        try {
            $converted = $this->quoteService->convert($quote, (int) $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $invoice = $converted->convertedInvoice;
        $message = $invoice
            ? 'تم تحويل عرض السعر إلى فاتورة مسودة '.$invoice->invoice_number.' دون إصدارها.'
            : 'تم تحويل عرض السعر.';

        return redirect()
            ->route('workspace.finance.quotes.show', $converted)
            ->with('success', $message);
    }

    public function send(Request $request, FinanceQuote $quote): RedirectResponse
    {
        $this->authorizeFinance($request, 'quotes.send');
        $this->assertSameWorkspace($quote->workspace_id);

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
            $delivery = $this->quoteEmailService->send(
                $quote,
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
            ->route('workspace.finance.quotes.show', $quote)
            ->with('success', 'تم إرسال عرض السعر إلى '.$delivery->recipient);
    }

    public function downloadPdf(Request $request, FinanceQuote $quote)
    {
        $this->authorizeFinance($request, 'quotes.view');
        $this->assertSameWorkspace($quote->workspace_id);

        try {
            return $this->pdfQuoteService->download($quote);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function storeAttachment(Request $request, FinanceQuote $quote): RedirectResponse
    {
        $this->authorizeFinance($request, 'quotes.edit');
        $this->assertSameWorkspace($quote->workspace_id);
        $request->validate([
            'attachments' => ['required', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);

        try {
            $this->quoteService->storeAttachments($quote, $request->file('attachments', []) ?: [], (int) $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('workspace.finance.quotes.show', $quote)->with('success', 'تم رفع المرفق.');
    }

    public function downloadAttachment(Request $request, FinanceQuote $quote, FinanceQuoteAttachment $attachment)
    {
        $this->authorizeFinance($request, 'quotes.view');
        $this->assertSameWorkspace($quote->workspace_id);
        abort_unless((int) $attachment->quote_id === (int) $quote->id, 404);

        return Storage::disk('public')->download(
            $attachment->file_path,
            $attachment->file_name ?: ('quote-attachment-'.$attachment->id)
        );
    }

    public function destroyAttachment(Request $request, FinanceQuote $quote, FinanceQuoteAttachment $attachment): RedirectResponse
    {
        $this->authorizeFinance($request, 'quotes.edit');
        $this->assertSameWorkspace($quote->workspace_id);
        abort_unless((int) $attachment->quote_id === (int) $quote->id, 404);

        try {
            $this->quoteService->deleteAttachment($quote, $attachment);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('workspace.finance.quotes.show', $quote)->with('success', 'تم حذف المرفق.');
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
            'products' => Product::query()->orderBy('name')->get(['id', 'name', 'price', 'currency', 'sku']),
            'taxRates' => FinanceTaxRate::query()->where('is_active', true)->orderByDesc('is_default')->get(['id', 'name', 'type', 'rate', 'code']),
            'defaultTaxRate' => (float) ($setting?->default_vat_rate ?? TaxCalculationService::FALLBACK_STANDARD_RATE),
            'listPrices' => $this->priceListService->effectivePricesByProductId((int) $workspace->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedQuotePayload(Request $request, int $workspaceId): array
    {
        $validated = $request->validate([
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspaceId)
                ),
            ],
            'issue_date' => ['required', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['nullable', 'in:draft,issued'],
            'notes' => ['nullable', 'string'],
            'terms' => ['nullable', 'string'],
            'tax_profile_type' => ['nullable', 'in:standard,zero_rated,exempt,out_of_scope'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_price_mode' => ['nullable', 'in:exclusive,inclusive'],
            'items_json' => ['required', 'string'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);

        $items = json_decode($validated['items_json'], true);
        if (! is_array($items) || $items === []) {
            throw ValidationException::withMessages([
                'items_json' => 'يجب إدخال بند واحد على الأقل في عرض السعر.',
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
        $payload['items'] = $items;

        return $payload;
    }
}
