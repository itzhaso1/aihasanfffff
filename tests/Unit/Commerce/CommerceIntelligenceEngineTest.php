<?php

namespace Tests\Unit\Commerce;

use App\Enums\Commerce\CommerceAttributeDataType;
use App\Enums\Commerce\CommerceEvaluationStatus;
use App\Enums\Commerce\CommerceProvenanceSource;
use App\Models\ChatCatalogCategory;
use App\Models\ChatCatalogItem;
use App\Models\Commerce\CommerceAttributeDefinition;
use App\Models\Workspace;
use App\Services\Commerce\Intelligence\CommerceAttributeService;
use App\Services\Commerce\Intelligence\CommerceCompatibilityService;
use App\Services\Commerce\Intelligence\CommerceCustomerContextService;
use App\Services\Commerce\Intelligence\CommerceIntelligenceProfileService;
use App\Services\Commerce\Intelligence\CommerceProductIntelligenceService;
use App\Services\Commerce\Intelligence\CommerceRecommendationEvaluator;
use App\Services\Commerce\Intelligence\CommerceRequirementService;
use App\Services\Commerce\Intelligence\CommerceRuleService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CommerceIntelligenceEngineTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Workspace $otherWorkspace;

    private WorkspaceContext $workspaceContext;

    private CommerceIntelligenceProfileService $profileService;

    private CommerceAttributeService $attributeService;

    private CommerceRequirementService $requirementService;

    private CommerceCompatibilityService $compatibilityService;

    private CommerceRuleService $ruleService;

    private CommerceCustomerContextService $customerContextService;

    private CommerceProductIntelligenceService $productIntelligenceService;

    private CommerceRecommendationEvaluator $recommendationEvaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->otherWorkspace = Workspace::factory()->create();
        $this->workspaceContext = app(WorkspaceContext::class);

        $this->profileService = app(CommerceIntelligenceProfileService::class);
        $this->attributeService = app(CommerceAttributeService::class);
        $this->requirementService = app(CommerceRequirementService::class);
        $this->compatibilityService = app(CommerceCompatibilityService::class);
        $this->ruleService = app(CommerceRuleService::class);
        $this->customerContextService = app(CommerceCustomerContextService::class);
        $this->productIntelligenceService = app(CommerceProductIntelligenceService::class);
        $this->recommendationEvaluator = app(CommerceRecommendationEvaluator::class);
    }

    public function test_workspace_isolation_prevents_cross_tenant_intelligence_access(): void
    {
        $this->workspaceContext->runFor($this->workspace, function (): void {
            $this->attributeService->createDefinition($this->workspace, [
                'key' => 'waist_cm',
                'display_name' => 'محيط الخصر',
                'data_type' => 'measurement',
                'unit' => 'cm',
            ]);
        });

        $otherDefs = $this->workspaceContext->runFor($this->otherWorkspace, function () {
            return CommerceAttributeDefinition::query()->where('workspace_id', $this->otherWorkspace->id)->get();
        });

        $this->assertCount(0, $otherDefs);
    }

    public function test_attribute_definition_validation_and_typing(): void
    {
        $def = $this->workspaceContext->runFor($this->workspace, function () {
            return $this->attributeService->createDefinition($this->workspace, [
                'key' => 'ram_gb',
                'display_name' => 'ذاكرة الرام',
                'data_type' => 'integer',
                'unit' => 'GB',
                'validation_constraints' => ['min' => 4, 'max' => 64],
            ]);
        });

        $this->assertEquals(CommerceAttributeDataType::Integer, $def->data_type);

        $item = ChatCatalogItem::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Laptop Gamer',
            'slug' => 'laptop-gamer',
            'status' => 'active',
            'publication_status' => 'published',
        ]);

        $this->workspaceContext->runFor($this->workspace, function () use ($item, $def): void {
            $this->attributeService->setEntityValue(
                workspace: $this->workspace,
                entityType: 'chat_catalog_item',
                entityId: (int) $item->id,
                definition: $def,
                rawInput: 16,
            );
        });

        $values = $this->attributeService->getEntityAttributeValues($this->workspace, 'chat_catalog_item', (int) $item->id);
        $this->assertCount(1, $values);
        $this->assertEquals(16.0, $values->first()->rawTypedValue());

        // Test numeric out-of-bounds validation failure
        $this->expectException(InvalidArgumentException::class);
        $this->workspaceContext->runFor($this->workspace, function () use ($item, $def): void {
            $this->attributeService->setEntityValue(
                workspace: $this->workspace,
                entityType: 'chat_catalog_item',
                entityId: (int) $item->id,
                definition: $def,
                rawInput: 128,
            );
        });
    }

    public function test_non_authoritative_source_cannot_overwrite_merchant_authoritative_attribute(): void
    {
        $def = $this->workspaceContext->runFor($this->workspace, function () {
            return $this->attributeService->createDefinition($this->workspace, [
                'key' => 'color',
                'display_name' => 'اللون',
                'data_type' => 'string',
            ]);
        });

        $item = ChatCatalogItem::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Shirt',
            'slug' => 'shirt',
            'status' => 'active',
            'publication_status' => 'published',
        ]);

        // Merchant sets authoritative value
        $this->workspaceContext->runFor($this->workspace, function () use ($item, $def): void {
            $this->attributeService->setEntityValue(
                workspace: $this->workspace,
                entityType: 'chat_catalog_item',
                entityId: (int) $item->id,
                definition: $def,
                rawInput: 'Blue',
                source: CommerceProvenanceSource::MerchantProvided,
            );
        });

        // AI / Customer attempt to overwrite authoritative value should fail
        $this->expectException(InvalidArgumentException::class);
        $this->workspaceContext->runFor($this->workspace, function () use ($item, $def): void {
            $this->attributeService->setEntityValue(
                workspace: $this->workspace,
                entityType: 'chat_catalog_item',
                entityId: (int) $item->id,
                definition: $def,
                rawInput: 'Red',
                source: CommerceProvenanceSource::AiInferred,
            );
        });
    }

    public function test_recommendation_evaluator_returns_insufficient_information_when_required_context_missing(): void
    {
        $category = ChatCatalogCategory::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Fashion Apparel',
        ]);

        $item = ChatCatalogItem::query()->create([
            'workspace_id' => $this->workspace->id,
            'chat_catalog_category_id' => $category->id,
            'name' => 'Silk Dress',
            'slug' => 'silk-dress',
            'status' => 'active',
            'publication_status' => 'published',
        ]);

        $this->workspaceContext->runFor($this->workspace, function () use ($category): void {
            $this->requirementService->createDefinition($this->workspace, [
                'key' => 'bust_cm',
                'label' => 'محيط الصدر',
                'data_type' => 'measurement',
                'unit' => 'cm',
                'is_required' => true,
                'applicability' => ['category_id' => (int) $category->id],
            ]);
        });

        // Evaluate with empty customer context
        $eval = $this->recommendationEvaluator->evaluate($this->workspace, $item, null, []);

        $this->assertEquals(CommerceEvaluationStatus::InsufficientInformation, $eval->status);
        $this->assertFalse($eval->isSuitable());
        $this->assertNotEmpty($eval->missingRequirements);
    }

    public function test_recommendation_evaluator_returns_compatible_when_context_and_compatibility_match(): void
    {
        $item = ChatCatalogItem::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'iPhone 15 Case',
            'slug' => 'iphone-15-case',
            'status' => 'active',
            'publication_status' => 'published',
        ]);

        $this->workspaceContext->runFor($this->workspace, function () use ($item): void {
            $this->compatibilityService->createCompatibility($this->workspace, [
                'source_entity_type' => 'chat_catalog_item',
                'source_entity_id' => (int) $item->id,
                'compatibility_type' => 'compatible',
                'target_reference' => ['make' => 'Apple', 'model' => 'iPhone 15 Pro'],
            ]);
        });

        $eval = $this->recommendationEvaluator->evaluate($this->workspace, $item, null, [
            'make' => 'Apple',
            'model' => 'iPhone 15 Pro',
        ]);

        $this->assertEquals(CommerceEvaluationStatus::Compatible, $eval->status);
        $this->assertTrue($eval->isSuitable());
        $this->assertEmpty($eval->missingRequirements);
        $this->assertEmpty($eval->incompatibilityReasons);
    }

    public function test_structured_rules_evaluate_deterministically(): void
    {
        $item = ChatCatalogItem::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Compact Desk',
            'slug' => 'compact-desk',
            'status' => 'active',
            'publication_status' => 'published',
        ]);

        $this->workspaceContext->runFor($this->workspace, function () use ($item): void {
            $this->ruleService->createRule($this->workspace, [
                'name' => 'Minimum Room Width Constraint',
                'rule_type' => 'incompatibility',
                'target_entity_type' => 'chat_catalog_item',
                'target_entity_id' => (int) $item->id,
                'conditions' => [
                    ['field' => 'room_width_cm', 'operator' => '<', 'value' => 150],
                ],
                'actions' => [
                    'message' => 'عرض الغرفة يجب أن لا يقل عن 150 سم لهذا المكتب.',
                ],
            ]);
        });

        $eval = $this->recommendationEvaluator->evaluate($this->workspace, $item, null, [
            'room_width_cm' => 120,
        ]);

        $this->assertEquals(CommerceEvaluationStatus::Incompatible, $eval->status);
        $this->assertFalse($eval->isSuitable());
        $this->assertContains('عرض الغرفة يجب أن لا يقل عن 150 سم لهذا المكتب.', $eval->incompatibilityReasons);
    }
}
