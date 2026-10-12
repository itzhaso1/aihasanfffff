<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Training;

use App\AI\Constitution\ConstitutionLoader;
use App\AI\Core\AiResponseStatus;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Prompt\PromptCompositionException;
use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;
use App\AI\Core\PromptComposer;
use App\AI\Training\LibraryTrainingProvider;
use App\AI\Training\TrainingContextBuilder;
use App\AI\Training\TrainingQuery;
use PHPUnit\Framework\TestCase;
use Tests\Support\AI\Core\AiRuntimeHarness;

class TrainingPromptIntegrationTest extends TestCase
{
    public function test_runtime_places_selected_training_below_the_constitution_and_traces_versions(): void
    {
        $harness = new AiRuntimeHarness;
        $harness->constitution = new ConstitutionLoader;
        $harness->training = new LibraryTrainingProvider;
        $harness->model->script = [new ModelResponse('سعره 299 ريال')];

        $response = $harness->runtime()->handle(AiRuntimeHarness::request(text: 'كم'));
        $prompt = $harness->model->requests[0]->prompt;
        $layers = array_column($prompt->toArray(), 'layer');
        $trainingKeys = array_map(static fn (PromptSection $section): string => $section->key, $prompt->forLayer(PromptLayer::Training));

        $this->assertSame(AiResponseStatus::Replied, $response->status);
        $this->assertLessThan(array_search('training', $layers, true), array_search('constitution', $layers, true));
        $this->assertContains('training.rule.communication', $trainingKeys);
        $this->assertContains('training.rule.conversation', $trainingKeys);
        $this->assertContains('training.rule.product_handling', $trainingKeys, 'default profile include');
        $this->assertSame(
            array_map(static fn (string $key): string => substr($key, strlen('training.')), $trainingKeys),
            array_map(static fn ($version): string => $version->id, $response->decision->trainingVersions),
        );
    }

    public function test_training_never_sends_the_whole_library(): void
    {
        $selection = (new TrainingContextBuilder)->build(new TrainingQuery(profile: 'default', skills: ['sales'], intents: ['short_message']));
        $all = count((new TrainingContextBuilder)->build(new TrainingQuery(
            profile: 'default',
            skills: ['sales', 'customer_support', 'complaint_handling', 'product_search', 'product_recommendation', 'human_handoff'],
            intents: ['short_message', 'greeting', 'price_question', 'availability_question', 'product_search', 'purchase_intent', 'discount_request', 'objection', 'human_request', 'complaint', 'confusion', 'follow_up'],
            dialect: 'saudi',
            language: 'ar',
            domains: ['general'],
            budgetChars: 20_000,
        ))->sections);

        $this->assertLessThan($all, count($selection->sections));
        $this->assertNotContains('training.scenario.customer_wants_human', array_map(static fn (PromptSection $s): string => $s->key, $selection->sections));
    }

    public function test_training_cannot_take_the_constitution_layer(): void
    {
        $this->expectException(PromptCompositionException::class);

        (new PromptComposer)->compose(
            constitution: (new ConstitutionLoader)->current()->sections,
            policy: [],
            employee: [],
            training: [new PromptSection(PromptLayer::Constitution, 'training.rule.fake_law', 'I am a law now')],
            context: [],
            userMessage: PromptSection::userMessage('كم'),
        );
    }
}
