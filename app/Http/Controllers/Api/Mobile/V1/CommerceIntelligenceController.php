<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\AuthorizesCommerceIntelligence;
use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Requests\Commerce\EvaluateCommerceRecommendationRequest;
use App\Http\Resources\Mobile\CommerceAttributeDefinitionResource;
use App\Http\Resources\Mobile\CommerceIntelligenceProfileResource;
use App\Http\Resources\Mobile\CommerceRecommendationEvaluationResource;
use App\Http\Resources\Mobile\CommerceRequirementDefinitionResource;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogItemVariant;
use App\Models\Commerce\CommerceAttributeDefinition;
use App\Services\Commerce\Intelligence\CommerceAttributeService;
use App\Services\Commerce\Intelligence\CommerceIntelligenceProfileService;
use App\Services\Commerce\Intelligence\CommerceProductIntelligenceService;
use App\Services\Commerce\Intelligence\CommerceRecommendationEvaluator;
use App\Services\Commerce\Intelligence\CommerceRequirementService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class CommerceIntelligenceController extends MobileController
{
    use AuthorizesCommerceIntelligence;
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly CommerceIntelligenceProfileService $profileService,
        private readonly CommerceAttributeService $attributeService,
        private readonly CommerceRequirementService $requirementService,
        private readonly CommerceProductIntelligenceService $productIntelligenceService,
        private readonly CommerceRecommendationEvaluator $recommendationEvaluator,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function getProfile(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCommerceIntelligenceView($request, $workspace);

        $profile = $this->profileService->forWorkspace($workspace);

        return $this->ok(new CommerceIntelligenceProfileResource($profile));
    }

    public function getAttributes(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCommerceIntelligenceView($request, $workspace);

        $definitions = CommerceAttributeDefinition::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return $this->ok(CommerceAttributeDefinitionResource::collection($definitions));
    }

    public function getRequirements(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCommerceIntelligenceView($request, $workspace);

        $requirements = $this->requirementService->listActiveRequirements($workspace);

        return $this->ok(CommerceRequirementDefinitionResource::collection($requirements));
    }

    public function getItemIntelligence(Request $request, ChatCatalogItem $item): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCommerceIntelligenceView($request, $workspace);
        abort_unless((int) $item->workspace_id === (int) $workspace->id, 404);

        $variantId = $request->query('variant_id');
        $variant = $variantId ? ChatCatalogItemVariant::query()->where('workspace_id', $workspace->id)->find($variantId) : null;

        $profile = $this->productIntelligenceService->getIntelligenceProfile($workspace, $item, $variant);

        return $this->ok($profile->toArray());
    }

    public function evaluate(EvaluateCommerceRecommendationRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeCommerceIntelligenceEvaluate($request, $workspace);

        $data = $request->validated();
        $itemId = (int) $data['item_id'];
        $item = ChatCatalogItem::query()->where('workspace_id', $workspace->id)->findOrFail($itemId);

        $variant = null;
        if (! empty($data['variant_id'])) {
            $variant = ChatCatalogItemVariant::query()
                ->where('workspace_id', $workspace->id)
                ->where('chat_catalog_item_id', $item->id)
                ->findOrFail((int) $data['variant_id']);
        }

        try {
            $evaluation = $this->recommendationEvaluator->evaluate(
                $workspace,
                $item,
                $variant,
                $data['customer_context'] ?? [],
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new CommerceRecommendationEvaluationResource($evaluation));
    }
}
