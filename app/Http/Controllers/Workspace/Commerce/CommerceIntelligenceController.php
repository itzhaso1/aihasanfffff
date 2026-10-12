<?php

namespace App\Http\Controllers\Workspace\Commerce;

use App\Enums\Commerce\CommerceDomain;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Commerce\StoreCommerceAttributeDefinitionRequest;
use App\Http\Requests\Commerce\StoreCommerceCompatibilityRequest;
use App\Http\Requests\Commerce\StoreCommerceEntityAttributeValuesRequest;
use App\Http\Requests\Commerce\StoreCommerceIntelligenceRuleRequest;
use App\Http\Requests\Commerce\StoreCommerceRequirementDefinitionRequest;
use App\Models\ChatCatalogItem;
use App\Models\Commerce\CommerceAttributeDefinition;
use App\Models\Commerce\CommerceCompatibility;
use App\Models\Commerce\CommerceIntelligenceRule;
use App\Models\Commerce\CommerceRequirementDefinition;
use App\Services\Commerce\Intelligence\CommerceAttributeService;
use App\Services\Commerce\Intelligence\CommerceCompatibilityService;
use App\Services\Commerce\Intelligence\CommerceIntelligenceProfileService;
use App\Services\Commerce\Intelligence\CommerceProductIntelligenceService;
use App\Services\Commerce\Intelligence\CommerceRequirementService;
use App\Services\Commerce\Intelligence\CommerceRuleService;
use App\Support\Commerce\CommerceIntelligenceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CommerceIntelligenceController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly CommerceIntelligenceAccess $access,
        private readonly CommerceIntelligenceProfileService $profileService,
        private readonly CommerceAttributeService $attributeService,
        private readonly CommerceRequirementService $requirementService,
        private readonly CommerceCompatibilityService $compatibilityService,
        private readonly CommerceRuleService $ruleService,
        private readonly CommerceProductIntelligenceService $productIntelligenceService,
    ) {}

    public function index(Request $request): View
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canViewIntelligence($request->user(), $workspace), 403);

        $profile = $this->profileService->forWorkspace($workspace);
        $attributeCount = CommerceAttributeDefinition::query()->where('workspace_id', $workspace->id)->count();
        $requirementCount = CommerceRequirementDefinition::query()->where('workspace_id', $workspace->id)->count();
        $ruleCount = CommerceIntelligenceRule::query()->where('workspace_id', $workspace->id)->count();
        $compatibilityCount = CommerceCompatibility::query()->where('workspace_id', $workspace->id)->count();

        $domains = CommerceDomain::cases();

        return view('workspace.commerce.intelligence.index', compact(
            'profile',
            'attributeCount',
            'requirementCount',
            'ruleCount',
            'compatibilityCount',
            'domains',
        ));
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canManageIntelligence($request->user(), $workspace), 403);

        $validated = $request->validate([
            'primary_domain' => ['required', 'string'],
            'secondary_domains' => ['nullable', 'array'],
            'enabled_capabilities' => ['nullable', 'array'],
        ]);

        $this->profileService->updateProfile(
            workspace: $workspace,
            primaryDomain: $validated['primary_domain'],
            secondaryDomains: $validated['secondary_domains'] ?? [],
            enabledCapabilities: $validated['enabled_capabilities'] ?? [],
            actor: $request->user(),
        );

        return redirect()->route('workspace.commerce.intelligence.index')
            ->with('status', 'تم تحديث الملف التجاري بنجاح.');
    }

    public function attributes(Request $request): View
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canViewIntelligence($request->user(), $workspace), 403);

        $definitions = CommerceAttributeDefinition::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('sort_order')
            ->get();

        return view('workspace.commerce.intelligence.attributes', compact('definitions'));
    }

    public function storeAttribute(StoreCommerceAttributeDefinitionRequest $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canManageIntelligence($request->user(), $workspace), 403);

        $this->attributeService->createDefinition($workspace, $request->validated(), $request->user());

        return redirect()->route('workspace.commerce.intelligence.attributes')
            ->with('status', 'تم إضافة تعريف الخاصية بنجاح.');
    }

    public function destroyAttribute(Request $request, CommerceAttributeDefinition $attribute): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canManageIntelligence($request->user(), $workspace), 403);
        $this->assertSameWorkspace($attribute->workspace_id);

        $this->attributeService->deleteDefinition($workspace, $attribute, $request->user());

        return redirect()->route('workspace.commerce.intelligence.attributes')
            ->with('status', 'تم حذف الخاصية.');
    }

    public function requirements(Request $request): View
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canViewIntelligence($request->user(), $workspace), 403);

        $requirements = CommerceRequirementDefinition::query()
            ->where('workspace_id', $workspace->id)
            ->get();

        return view('workspace.commerce.intelligence.requirements', compact('requirements'));
    }

    public function storeRequirement(StoreCommerceRequirementDefinitionRequest $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canManageIntelligence($request->user(), $workspace), 403);

        $this->requirementService->createDefinition($workspace, $request->validated(), $request->user());

        return redirect()->route('workspace.commerce.intelligence.requirements')
            ->with('status', 'تم إضافة المتطلب بنجاح.');
    }

    public function destroyRequirement(Request $request, CommerceRequirementDefinition $requirement): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canManageIntelligence($request->user(), $workspace), 403);
        $this->assertSameWorkspace($requirement->workspace_id);

        $this->requirementService->deleteDefinition($workspace, $requirement, $request->user());

        return redirect()->route('workspace.commerce.intelligence.requirements')
            ->with('status', 'تم حذف المتطلب.');
    }

    public function rules(Request $request): View
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canViewIntelligence($request->user(), $workspace), 403);

        $rules = CommerceIntelligenceRule::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('priority')
            ->get();

        return view('workspace.commerce.intelligence.rules', compact('rules'));
    }

    public function storeRule(StoreCommerceIntelligenceRuleRequest $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canManageIntelligence($request->user(), $workspace), 403);

        $this->ruleService->createRule($workspace, $request->validated(), $request->user());

        return redirect()->route('workspace.commerce.intelligence.rules')
            ->with('status', 'تم إضافة القاعدة بنجاح.');
    }

    public function destroyRule(Request $request, CommerceIntelligenceRule $rule): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canManageIntelligence($request->user(), $workspace), 403);
        $this->assertSameWorkspace($rule->workspace_id);

        $this->ruleService->deleteRule($workspace, $rule, $request->user());

        return redirect()->route('workspace.commerce.intelligence.rules')
            ->with('status', 'تم حذف القاعدة.');
    }

    public function compatibilities(Request $request): View
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canViewIntelligence($request->user(), $workspace), 403);

        $compatibilities = CommerceCompatibility::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('id')
            ->paginate(30);

        return view('workspace.commerce.intelligence.compatibilities', compact('compatibilities'));
    }

    public function storeCompatibility(StoreCommerceCompatibilityRequest $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canManageIntelligence($request->user(), $workspace), 403);

        $this->compatibilityService->createCompatibility($workspace, $request->validated(), $request->user());

        return redirect()->route('workspace.commerce.intelligence.compatibilities')
            ->with('status', 'تم إضافة التوافق بنجاح.');
    }

    public function destroyCompatibility(Request $request, CommerceCompatibility $compatibility): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canManageIntelligence($request->user(), $workspace), 403);
        $this->assertSameWorkspace($compatibility->workspace_id);

        $this->compatibilityService->deleteCompatibility($workspace, $compatibility, $request->user());

        return redirect()->route('workspace.commerce.intelligence.compatibilities')
            ->with('status', 'تم حذف علاقة التوافق.');
    }

    public function item(Request $request, ChatCatalogItem $item): View
    {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canViewIntelligence($request->user(), $workspace), 403);
        $this->assertSameWorkspace($item->workspace_id);

        $intelligenceProfile = $this->productIntelligenceService->getIntelligenceProfile($workspace, $item);
        $definitions = CommerceAttributeDefinition::query()->where('workspace_id', $workspace->id)->where('is_active', true)->get();

        return view('workspace.commerce.intelligence.item', compact('item', 'intelligenceProfile', 'definitions'));
    }

    public function storeItemAttributes(
        StoreCommerceEntityAttributeValuesRequest $request,
        ChatCatalogItem $item,
    ): RedirectResponse {
        $workspace = $this->currentWorkspace();
        abort_unless($request->user() && $this->access->canManageIntelligence($request->user(), $workspace), 403);
        $this->assertSameWorkspace($item->workspace_id);

        $validated = $request->validated();
        foreach ($validated['attributes'] as $attrData) {
            $definition = CommerceAttributeDefinition::query()
                ->where('workspace_id', $workspace->id)
                ->findOrFail((int) $attrData['definition_id']);

            $this->attributeService->setEntityValue(
                workspace: $workspace,
                entityType: 'chat_catalog_item',
                entityId: (int) $item->id,
                definition: $definition,
                rawInput: $attrData['value'] ?? null,
                unit: $attrData['unit'] ?? null,
                source: $attrData['source'] ?? 'merchant_provided',
                actor: $request->user(),
            );
        }

        return redirect()->route('workspace.commerce.intelligence.items.show', $item)
            ->with('status', 'تم تحديث خصائص الذكاء للكتالوج بنجاح.');
    }
}
