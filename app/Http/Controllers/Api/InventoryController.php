<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustInventoryRequest;
use App\Models\InventoryMovement;
use App\Services\Inventory\InventoryService;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly WorkspaceAccess $workspaceAccess,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $movements = InventoryMovement::query()
            ->with(['product', 'variant', 'user'])
            ->when($request->filled('product_id'), fn ($query) => $query->where('product_id', $request->integer('product_id')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->toString()))
            ->latest('id')
            ->paginate((int) $request->input('per_page', 20));

        return response()->json($movements);
    }

    public function adjust(AdjustInventoryRequest $request): JsonResponse
    {
        $movement = $this->inventoryService->adjustStock(
            productId: $request->integer('product_id'),
            variantId: $request->filled('product_variant_id') ? $request->integer('product_variant_id') : null,
            type: $request->string('type')->toString(),
            quantity: $request->integer('quantity'),
            actor: $request->user(),
            referenceType: $request->string('reference_type')->toString() ?: null,
            referenceId: $request->filled('reference_id') ? $request->integer('reference_id') : null,
            notes: $request->string('notes')->toString() ?: null,
        );

        return response()->json(['data' => $movement], 201);
    }

    private function authorizeView(Request $request): void
    {
        $user = $request->user();
        $workspace = $this->workspaceContext->workspace();
        abort_unless($user && $workspace, 403);
        abort_unless($this->workspaceAccess->canViewInventory($user, $workspace), 403);
    }
}
