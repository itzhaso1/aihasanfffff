<?php

namespace Tests\Unit\Chat;

use App\AI\Context\AiContextProvider;
use App\AI\Context\ContextMessage;
use App\AI\Context\ConversationState;
use App\AI\Context\ReferenceResolver;
use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\GuardInput;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Guard\HasimResponseGuard;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogRetrievalService;
use App\Services\Chat\ChatCatalogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogVariantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_question_returns_the_product_and_its_variants(): void
    {
        [$workspace] = $this->excelBook();

        $lookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, 'هل عندكم كتاب Excel؟');

        $this->assertSame('listed', $lookup['status']);
        $this->assertSame('كتاب Excel', $lookup['items'][0]['name']);
        $this->assertEqualsCanonicalizing(
            ['النسخة العربية', 'النسخة المتقدمة'],
            array_column($lookup['items'][0]['variants'], 'name'),
        );
    }

    public function test_arabic_copy_question_selects_that_variant_and_its_price(): void
    {
        [$workspace] = $this->excelBook();

        $lookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, 'كم سعر النسخة العربية؟');
        $arabic = $this->variantNamed($lookup['items'][0]['variants'], 'النسخة العربية');
        $advanced = $this->variantNamed($lookup['items'][0]['variants'], 'النسخة المتقدمة');

        $this->assertSame('listed', $lookup['status']);
        $this->assertTrue($arabic['matches_question']);
        $this->assertSame('10.00', $arabic['price']);
        $this->assertSame('SAR', $arabic['currency']);
        $this->assertNotSame($arabic['price'], $advanced['price']);
    }

    public function test_advanced_copy_question_selects_the_advanced_variant(): void
    {
        [$workspace] = $this->excelBook();

        $lookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, 'هل توجد نسخة متقدمة؟');
        $advanced = $this->variantNamed($lookup['items'][0]['variants'], 'النسخة المتقدمة');

        $this->assertTrue($advanced['matches_question']);
        $this->assertSame('34.00', $advanced['price']);
    }

    public function test_an_arabic_pdf_copy_is_found_even_when_stock_is_zero(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $this->createBook($workspace, 'تعلم Excel للمبتدئين', '10.00', [[
            'name' => 'عربي PDF',
            'price' => '10.00',
            'currency' => 'SAR',
            'inventory_policy' => 'finite_stock',
            'stock' => 0,
        ]]);

        $asked = app(ChatCatalogRetrievalService::class)->lookupForAssistant(
            $workspace,
            'عندك نسخه عربي من كتاب اكسل؟',
        );
        $arabic = $this->variantNamed($asked['items'][0]['variants'], 'عربي PDF');
        $followUp = app(ChatCatalogRetrievalService::class)->lookupForAssistant(
            $workspace,
            'نعم هو بس احتاج الي نسخه عربيه ؟',
        );

        $this->assertSame('listed', $asked['status']);
        $this->assertSame('تعلم Excel للمبتدئين', $asked['items'][0]['name']);
        $this->assertTrue($arabic['matches_question']);
        $this->assertSame('out_of_stock', $arabic['availability']);
        $this->assertSame(0, $arabic['stock']);
        $this->assertTrue(
            $this->variantNamed($followUp['items'][0]['variants'], 'عربي PDF')['matches_question'],
        );
    }

    public function test_availability_question_uses_the_variant_stock_state(): void
    {
        [$workspace] = $this->excelBook();

        $lookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, 'هل النسخة المتقدمة متوفرة حاليًا؟');
        $advanced = $this->variantNamed($lookup['items'][0]['variants'], 'النسخة المتقدمة');
        $arabic = $this->variantNamed($lookup['items'][0]['variants'], 'النسخة العربية');

        $this->assertSame('out_of_stock', $advanced['availability']);
        $this->assertSame('in_stock', $arabic['availability']);
    }

    public function test_listing_variants_is_partial_when_more_copies_exist_than_the_page(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $variants = [];
        for ($index = 1; $index <= 9; $index++) {
            $variants[] = [
                'name' => 'نسخة '.$index,
                'price' => $index,
                'currency' => 'SAR',
                'inventory_policy' => 'not_tracked',
            ];
        }
        $this->createBook($workspace, 'كتاب عام', '99.00', $variants);

        $lookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, 'وش النسخ المتوفرة من الكتاب العام؟');

        $this->assertSame('listed', $lookup['status']);
        $this->assertCount(8, $lookup['items'][0]['variants']);
        $this->assertTrue($lookup['items'][0]['has_more_variants']);
    }

    public function test_an_unregistered_copy_is_not_added_to_the_variant_list(): void
    {
        [$workspace] = $this->excelBook();

        $lookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, 'عندكم نسخة إنجليزية من كتاب Excel؟');
        $names = array_column($lookup['items'][0]['variants'], 'name');

        $this->assertNotContains('النسخة الإنجليزية', $names);
        $this->assertEqualsCanonicalizing(['النسخة العربية', 'النسخة المتقدمة'], $names);
    }

    public function test_follow_up_price_question_keeps_the_product_mentioned_earlier(): void
    {
        [$workspace] = $this->excelBook();
        $this->createBook($workspace, 'قلم حبر', '5.00', [[
            'name' => 'قلم أزرق',
            'price' => '5.00',
            'currency' => 'SAR',
            'inventory_policy' => 'not_tracked',
        ]]);

        $resolution = app(ReferenceResolver::class)->resolve(
            workspace: $workspace,
            conversationMetadata: [],
            state: new ConversationState,
            currentMessage: 'كم سعرها؟',
            recentMessages: [
                new ContextMessage('customer', 'هل عندكم كتاب Excel؟'),
                new ContextMessage('customer', 'كم سعرها؟'),
            ],
        );

        $this->assertSame('listed', $resolution->retrievalStatus);
        $this->assertSame('كتاب Excel', $resolution->catalogItems[0]['name']);
        $this->assertEqualsCanonicalizing(
            ['10.00', '34.00'],
            array_column($resolution->catalogItems[0]['variants'], 'price'),
        );
    }

    public function test_an_arabic_programming_book_is_not_replaced_by_another_arabic_product(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $this->createBook($workspace, 'كتاب تعلم Excel للمبتدئين', '99.00', [[
            'name' => 'النسخة العربية',
            'price' => '10.00',
            'currency' => 'SAR',
            'inventory_policy' => 'not_tracked',
        ]]);
        $this->createBook($workspace, 'تعلم البرمجة', '40.00', [[
            'name' => 'النسخة العربية',
            'price' => '25.00',
            'currency' => 'SAR',
            'inventory_policy' => 'not_tracked',
        ]]);

        $lookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant(
            $workspace,
            'نعم احتاج كتاب برمجه عربي ؟',
        );
        $programming = $this->itemNamed($lookup['items'], 'تعلم البرمجة');
        $arabic = $this->variantNamed($programming['variants'], 'النسخة العربية');

        $this->assertSame('listed', $lookup['status']);
        $this->assertNotContains('كتاب تعلم Excel للمبتدئين', array_column($lookup['items'], 'name'));
        $this->assertTrue($arabic['matches_question']);
        $this->assertSame('25.00', $arabic['price']);
    }

    public function test_similar_product_names_do_not_mix_their_variants(): void
    {
        [$workspace] = $this->excelBook();
        $this->createBook($workspace, 'كتاب Word', '15.00', [[
            'name' => 'النسخة العادية',
            'price' => '15.00',
            'currency' => 'SAR',
            'inventory_policy' => 'not_tracked',
        ]]);

        $excel = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, 'هل عندكم كتاب Excel؟');
        $arabic = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, 'كم سعر النسخة العربية؟');

        $this->assertSame(['كتاب Excel'], array_column($excel['items'], 'name'));
        $this->assertSame(['كتاب Excel'], array_column($arabic['items'], 'name'));
        $this->assertNotContains('النسخة العادية', array_column($arabic['items'][0]['variants'], 'name'));
    }

    public function test_variants_do_not_cross_workspaces(): void
    {
        [$workspace] = $this->excelBook();
        $other = Workspace::factory()->create(['type' => 'company']);
        $this->createBook($other, 'كتاب Excel', '1.00', [[
            'name' => 'نسخة مساحة أخرى',
            'price' => '1.00',
            'currency' => 'SAR',
            'inventory_policy' => 'not_tracked',
        ]]);

        $lookup = app(ChatCatalogRetrievalService::class)->lookupForAssistant($workspace, 'هل عندكم كتاب Excel؟');

        $this->assertSame(['كتاب Excel'], array_column($lookup['items'], 'name'));
        $this->assertNotContains('نسخة مساحة أخرى', array_column($lookup['items'][0]['variants'], 'name'));
    }

    public function test_follow_up_arabic_copy_question_keeps_the_excel_book(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $this->createBook($workspace, 'تعلم Excel للمبتدئين', '10.00', [[
            'name' => 'عربي PDF',
            'price' => '10.00',
            'currency' => 'SAR',
            'inventory_policy' => 'finite_stock',
            'stock' => 0,
        ]]);

        $resolution = app(ReferenceResolver::class)->resolve(
            workspace: $workspace,
            conversationMetadata: [],
            state: new ConversationState,
            currentMessage: 'نعم هو بس احتاج الي نسخه عربيه ؟',
            recentMessages: [
                new ContextMessage('customer', 'عندك نسخه عربي من كتاب اكسل؟'),
                new ContextMessage('ai', 'الكتاب اللي عندنا تعلم Excel للمبتدئين'),
                new ContextMessage('customer', 'نعم هو بس احتاج الي نسخه عربيه ؟'),
            ],
        );
        $arabic = $this->variantNamed($resolution->catalogItems[0]['variants'], 'عربي PDF');

        $this->assertSame('listed', $resolution->retrievalStatus);
        $this->assertSame('تعلم Excel للمبتدئين', $resolution->catalogItems[0]['name']);
        $this->assertTrue($arabic['matches_question']);
    }

    public function test_listed_catalog_instruction_names_the_matching_copy_even_when_out_of_stock(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $this->createBook($workspace, 'تعلم Excel للمبتدئين', '10.00', [[
            'name' => 'عربي PDF',
            'price' => '10.00',
            'currency' => 'SAR',
            'inventory_policy' => 'finite_stock',
            'stock' => 0,
        ]]);
        $request = $this->request($workspace, 'عندك نسخه عربي من كتاب اكسل؟');
        $catalog = '';
        foreach (app(AiContextProvider::class)->build($request, new ResolvedEmployee(1))->sections as $section) {
            if ($section->key === 'context.catalog') {
                $catalog = $section->content;
            }
        }

        $this->assertStringContainsString('عربي PDF', $catalog);
        $this->assertStringContainsString('"matches_question":true', $catalog);
        $this->assertStringContainsString('Listed copies exist even when availability is out_of_stock', $catalog);
        $this->assertStringContainsString('Do not say the copy is missing, do not say you will check later', $catalog);
    }

    public function test_a_correct_variant_price_passes_the_guard_and_another_variants_price_is_not_required(): void
    {
        [$workspace] = $this->excelBook();
        $request = $this->request($workspace, 'كم سعر النسخة العربية؟');
        $context = app(AiContextProvider::class)->build($request, new ResolvedEmployee(1));
        $guard = new HasimResponseGuard;
        $arabic = $guard->review(new GuardInput(
            request: $request,
            text: 'النسخة العربية سعرها 10 ريال',
            facts: $context->facts,
        ));
        $invented = $guard->review(new GuardInput(
            request: $request,
            text: 'النسخة الإنجليزية سعرها 50 ريال',
            facts: $context->facts,
        ));

        $this->assertSame(GuardVerdict::Pass, $arabic->verdict);
        $this->assertSame(GuardVerdict::Rejected, $invented->verdict);
    }

    private function request(Workspace $workspace, string $text): AiRequest
    {
        $customer = Customer::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::factory()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'web',
            'status' => 'open',
            'ai_enabled' => true,
        ]);
        $message = Message::factory()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'content' => $text,
        ]);

        return new AiRequest(
            workspaceId: (int) $workspace->id,
            conversationId: (int) $conversation->id,
            employeeId: 1,
            message: new AiInboundMessage($text, (int) $message->id),
            runMode: AiRunMode::Live,
        );
    }

    /**
     * @return array{0: Workspace, 1: ChatCatalogItem}
     */
    private function excelBook(): array
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $item = $this->createBook($workspace, 'كتاب Excel', '99.00', [
            [
                'name' => 'النسخة العربية',
                'price' => '10.00',
                'currency' => 'SAR',
                'inventory_policy' => 'not_tracked',
                'attributes' => ['language' => 'العربية'],
            ],
            [
                'name' => 'النسخة المتقدمة',
                'price' => '34.00',
                'currency' => 'SAR',
                'inventory_policy' => 'finite_stock',
                'stock' => 0,
                'attributes' => ['level' => 'متقدم'],
            ],
        ]);

        return [$workspace, $item];
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     */
    private function createBook(Workspace $workspace, string $name, string $price, array $variants): ChatCatalogItem
    {
        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $name, $price, $variants): ChatCatalogItem {
            return app(ChatCatalogService::class)->createItem($workspace, [
                'name' => $name,
                'description' => $name,
                'price' => $price,
                'currency' => 'SAR',
                'inventory_policy' => 'not_tracked',
                'variants' => $variants,
            ]);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function itemNamed(array $items, string $name): array
    {
        foreach ($items as $item) {
            if (($item['name'] ?? null) === $name) {
                return $item;
            }
        }

        $this->fail('Product ['.$name.'] was not retrieved.');
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @return array<string, mixed>
     */
    private function variantNamed(array $variants, string $name): array
    {
        foreach ($variants as $variant) {
            if (($variant['name'] ?? null) === $name) {
                return $variant;
            }
        }

        $this->fail('Variant ['.$name.'] was not retrieved with its parent product.');
    }
}
