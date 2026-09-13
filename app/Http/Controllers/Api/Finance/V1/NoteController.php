<?php

namespace App\Http\Controllers\Api\Finance\V1;

use App\Exceptions\Api\InvoiceCannotBeIssuedException;
use App\Http\Controllers\Api\Finance\Concerns\AuthorizesFinanceApi;
use App\Http\Controllers\Api\Finance\FinanceApiController;
use App\Http\Resources\Api\Finance\InvoiceDetailsResource;
use App\Http\Resources\Api\Finance\InvoiceQrResource;
use App\Http\Resources\Api\Finance\InvoiceSummaryResource;
use App\Http\Resources\Api\Finance\InvoiceXmlResource;
use App\Models\Finance\FinanceCreditNote;
use App\Services\Finance\Api\InvoiceDocumentReadService;
use App\Services\Finance\CreditNoteService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class NoteController extends FinanceApiController
{
    use AuthorizesFinanceApi;

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly InvoiceDocumentReadService $readService,
        private readonly CreditNoteService $creditNoteService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeFinanceApi($request, $workspace, 'invoices.view');

        $page = $this->readService->listNotes($workspace);

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

    public function show(Request $request, FinanceCreditNote $note): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeFinanceApi($request, $workspace, 'invoices.view');

        return $this->ok(
            (new InvoiceDetailsResource($this->readService->noteDetails($note)))->resolve(),
        );
    }

    public function issue(Request $request, FinanceCreditNote $note): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $user = $this->authorizeFinanceApi($request, $workspace, 'invoices.credit');

        try {
            $issued = $this->creditNoteService->issue($note, (int) $user->id);
        } catch (RuntimeException $exception) {
            throw new InvoiceCannotBeIssuedException($exception->getMessage(), $exception);
        }

        return $this->ok(
            (new InvoiceDetailsResource($this->readService->noteDetails($issued)))->resolve(),
        );
    }

    public function xml(Request $request, FinanceCreditNote $note): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeFinanceApi($request, $workspace, 'invoices.view');

        return $this->ok(
            (new InvoiceXmlResource($this->readService->xmlForNote($note)))->resolve(),
        );
    }

    public function qr(Request $request, FinanceCreditNote $note): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeFinanceApi($request, $workspace, 'invoices.view');

        return $this->ok(
            (new InvoiceQrResource($this->readService->qrForNote($note)))->resolve(),
        );
    }

    public function cryptographicStamp(Request $request, FinanceCreditNote $note): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeFinanceApi($request, $workspace, 'invoices.credit');
        unset($note);

        $this->readService->requestProductionStamp();
    }
}
