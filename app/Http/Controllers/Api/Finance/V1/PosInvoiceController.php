<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Http\Controllers\Api\Finance\Concerns\AuthorizesFinanceApi;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Http\Resources\Api\Finance\InvoiceDetailsResource;
use App\Http\Resources\Api\Finance\InvoiceQrResource;
use App\Http\Resources\Api\Finance\InvoiceSummaryResource;
use App\Http\Resources\Api\Finance\InvoiceXmlResource;
use App\Models\PosCashierInvoice;
use App\Services\Finance\Api\InvoiceDocumentReadService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosInvoiceController extends FinanceApiController
{
    use AuthorizesFinanceApi;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly InvoiceDocumentReadService $readService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeFinanceApi($request, $workspace, 'invoices.view');

        $page = $this->readService->listPosInvoices($workspace);

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

    public function show(Request $request, PosCashierInvoice $posInvoice): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeFinanceApi($request, $workspace, 'invoices.view');

        return $this->ok(
            (new InvoiceDetailsResource($this->readService->posDetails($posInvoice)))->resolve(),
        );
    }

    public function xml(Request $request, PosCashierInvoice $posInvoice): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeFinanceApi($request, $workspace, 'invoices.view');

        return $this->ok(
            (new InvoiceXmlResource($this->readService->xmlForPos($posInvoice)))->resolve(),
        );
    }

    public function qr(Request $request, PosCashierInvoice $posInvoice): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeFinanceApi($request, $workspace, 'invoices.view');

        return $this->ok(
            (new InvoiceQrResource($this->readService->qrForPos($posInvoice)))->resolve(),
        );
    }

    public function cryptographicStamp(Request $request, PosCashierInvoice $posInvoice): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeFinanceApi($request, $workspace, 'invoices.view');
        unset($posInvoice);

        $this->readService->requestProductionStamp();
    }
}
