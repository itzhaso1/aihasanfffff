<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Exceptions\Api\InvoiceCannotBeIssuedException;
use App\Exceptions\Finance\ZatcaRequiredIssueBlockedException;
use App\Http\Controllers\Api\Finance\Concerns\AuthorizesFinanceApi;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Http\Resources\Api\Finance\InvoiceDetailsResource;
use App\Http\Resources\Api\Finance\InvoiceQrResource;
use App\Http\Resources\Api\Finance\InvoiceSummaryResource;
use App\Http\Resources\Api\Finance\InvoiceXmlResource;
use App\Models\Finance\FinanceInvoice;
use App\Services\Finance\Api\InvoiceDocumentReadService;
use App\Services\Finance\InvoicePdfAccessService;
use App\Services\Finance\InvoiceService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InvoiceController extends FinanceApiController
{
    use AuthorizesFinanceApi;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly InvoiceDocumentReadService $readService,
        private readonly InvoiceService $invoiceService,
        private readonly InvoicePdfAccessService $invoicePdfAccessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeFinanceApi($request, $workspace, 'invoices.view');

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->readService->listFinanceInvoices($workspace, (int) ($validated['per_page'] ?? 25));

        return $this->ok(
            InvoiceSummaryResource::collection($page->getCollection())->resolve(),
            meta: [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    public function show(Request $request, FinanceInvoice $invoice): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeFinanceApi($request, $workspace, 'invoices.view');

        $payload = (new InvoiceDetailsResource($this->readService->financeDetails($invoice)))->resolve();
        $payload['pdf_download'] = $this->invoicePdfAccessService->issue($invoice, $user);

        return $this->ok($payload);
    }

    public function issue(Request $request, FinanceInvoice $invoice): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeFinanceApi($request, $workspace, 'invoices.issue');

        try {
            $issued = $this->invoiceService->issue($invoice, (int) $user->id);
        } catch (ZatcaRequiredIssueBlockedException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw new InvoiceCannotBeIssuedException($exception->getMessage(), $exception);
        }

        return $this->ok(
            (new InvoiceDetailsResource($this->readService->financeDetails($issued)))->resolve(),
        );
    }

    public function xml(Request $request, FinanceInvoice $invoice): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeFinanceApi($request, $workspace, 'invoices.view');

        return $this->ok(
            (new InvoiceXmlResource($this->readService->xmlForFinance($invoice)))->resolve(),
        );
    }

    public function qr(Request $request, FinanceInvoice $invoice): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeFinanceApi($request, $workspace, 'invoices.view');

        return $this->ok(
            (new InvoiceQrResource($this->readService->qrForFinance($invoice)))->resolve(),
        );
    }

    public function pdf(Request $request, FinanceInvoice $invoice): mixed
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeFinanceApi($request, $workspace, 'invoices.view');
        $this->invoicePdfAccessService->assertValid($request, $invoice, $user);

        return $this->readService->pdfForFinance($invoice);
    }

    public function cryptographicStamp(Request $request, FinanceInvoice $invoice): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeFinanceApi($request, $workspace, 'invoices.view');
        unset($invoice);

        $this->readService->requestProductionStamp();
    }
}
