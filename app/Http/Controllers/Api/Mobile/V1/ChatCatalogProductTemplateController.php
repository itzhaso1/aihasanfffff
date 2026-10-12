<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Api\Mobile\Concerns\AuthorizesChatCatalog;
use App\Http\Controllers\Api\Mobile\Concerns\ResolvesMobileWorkspace;
use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Requests\Chat\AssignChatCatalogProductTemplateRequest;
use App\Http\Requests\Chat\CloneChatCatalogProductTemplateRequest;
use App\Http\Requests\Chat\StoreChatCatalogProductTemplateRequest;
use App\Http\Resources\Mobile\ChatCatalogItemResource;
use App\Http\Resources\Mobile\ChatCatalogProductTemplateResource;
use App\Models\ChatCatalogItem;
use App\Models\ChatCatalogProductTemplate;
use App\Services\Chat\ProductTemplateService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatCatalogProductTemplateController extends MobileController
{
    use AuthorizesChatCatalog;
    use ResolvesMobileWorkspace;

    public function __construct(
        private readonly ProductTemplateService $productTemplates,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogView($request, $workspace);

        $templates = $this->productTemplates->listAvailableForWorkspace($workspace);

        return $this->ok(ChatCatalogProductTemplateResource::collection($templates));
    }

    public function show(Request $request, int $template): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogView($request, $workspace);

        $model = $this->productTemplates->resolveForWorkspace($template, $workspace);

        return $this->ok(new ChatCatalogProductTemplateResource($model));
    }

    public function store(StoreChatCatalogProductTemplateRequest $request): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorize('create', ChatCatalogProductTemplate::class);

        $created = $this->productTemplates->createWorkspaceTemplate($workspace, $request->validated());

        return $this->ok(new ChatCatalogProductTemplateResource($created), message: 'تم إنشاء القالب.', status: 201);
    }

    public function clone(CloneChatCatalogProductTemplateRequest $request, int $template): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $source = $this->productTemplates->resolveForWorkspace($template, $workspace);
        $this->authorize('clone', $source);

        $cloned = $this->productTemplates->cloneSystemTemplate(
            $workspace,
            $source,
            (string) $request->validated('name'),
        );

        return $this->ok(new ChatCatalogProductTemplateResource($cloned), message: 'تم نسخ القالب.', status: 201);
    }

    public function showForItem(Request $request, ChatCatalogItem $item): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorizeChatCatalogView($request, $workspace);
        $this->authorize('view', $item);

        if ($item->product_template_id === null) {
            return $this->ok(null, message: 'No product template assigned.');
        }

        $template = $this->productTemplates->resolveForWorkspace((int) $item->product_template_id, $workspace);

        return $this->ok(new ChatCatalogProductTemplateResource($template));
    }

    public function assign(AssignChatCatalogProductTemplateRequest $request, ChatCatalogItem $item): JsonResponse
    {
        $workspace = $this->requireWorkspace($this->workspaceContext);
        $this->authorize('update', $item);

        $template = $this->productTemplates->resolveForWorkspace(
            (int) $request->validated('product_template_id'),
            $workspace,
        );
        $this->productTemplates->assignToItem($item, $template);

        $item->load(['category', 'variants.structuredAttributes', 'media', 'structuredAttributes', 'productTemplate.fields']);

        return $this->ok(new ChatCatalogItemResource($item), message: 'تم تعيين قالب المنتج.');
    }
}
