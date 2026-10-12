<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Training;

use App\AI\Core\Data\ContextPayload;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Prompt\PromptSection;
use App\AI\Core\PromptComposer;
use App\AI\Training\DefaultTrainingQueryFactory;
use App\AI\Training\GreetingStyleLoader;
use App\AI\Training\GreetingStyleRecord;
use App\AI\Training\GreetingStyleSelector;
use App\AI\Training\LibraryTrainingProvider;
use App\AI\Training\TrainingContextBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Tests\Support\AI\Core\AiRuntimeHarness;

class GreetingStyleRuntimeTest extends TestCase
{
    public function test_loader_loads_187_records(): void
    {
        $this->assertCount(GreetingStyleLoader::RECORD_COUNT, (new GreetingStyleLoader)->records());
    }

    public function test_loader_rejects_a_record_missing_a_required_field(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'greeting-style-');
        $this->assertNotFalse($path);
        file_put_contents($path, '{"category":"x","case":"y","user":"z","answer_1":"a"}'."\n");

        try {
            (new GreetingStyleLoader($path))->records();
            $this->fail('A record missing answer_2 must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('answer_2', $exception->getMessage());
        } finally {
            unlink($path);
        }
    }

    public function test_each_record_exposes_only_the_frozen_fields(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(GreetingStyleRecord::class))->getProperties(),
        );

        $this->assertSame(GreetingStyleLoader::FIELDS, $properties);
        foreach ((new GreetingStyleLoader)->records() as $record) {
            foreach (GreetingStyleLoader::FIELDS as $field) {
                $this->assertNotSame('', trim($record->{$field}));
            }
        }
    }

    public function test_employee_name_placeholder_is_preserved_verbatim(): void
    {
        $preserved = false;
        foreach ((new GreetingStyleLoader)->records() as $record) {
            if (str_contains($record->answer_1, '{employee_name}') || str_contains($record->answer_2, '{employee_name}')) {
                $preserved = true;
            }
        }

        $this->assertTrue($preserved);
    }

    public function test_selector_returns_examples_for_a_greeting(): void
    {
        $this->assertGreaterThan(0, count((new GreetingStyleSelector)->select('السلام عليكم')));
    }

    public function test_selector_returns_nothing_for_a_product_turn(): void
    {
        $this->assertSame([], (new GreetingStyleSelector)->select('عندكم ايفون 15؟'));
    }

    public function test_selector_never_exceeds_the_hard_cap(): void
    {
        $selected = (new GreetingStyleSelector)->select('السلام عليكم');

        $this->assertLessThanOrEqual(GreetingStyleSelector::CAP, count($selected));
        $this->assertSame(GreetingStyleSelector::CAP, count($selected));
    }

    public function test_prompt_includes_style_references_only_for_a_social_turn(): void
    {
        $social = $this->composed('السلام عليكم');
        $commercial = $this->composed('عندكم ايفون 15؟');
        $socialText = $this->render($social);
        $commercialText = $this->render($commercial);

        $this->assertStringContainsString('## Social Style References', $socialText);
        $this->assertStringContainsString('STYLE references, not mandatory canned replies', $socialText);
        $this->assertStringNotContainsString('## Social Style References', $commercialText);
        $this->assertLessThanOrEqual(
            GreetingStyleSelector::CAP,
            substr_count($socialText, 'answer_1:'),
        );
    }

    public function test_non_social_prompt_matches_training_without_style_references(): void
    {
        $request = AiRuntimeHarness::request(text: 'عندكم ايفون 15؟');
        $employee = new ResolvedEmployee(1, skillKeys: ['sales']);
        $context = new ContextPayload(intent: 'availability_question');
        $query = (new DefaultTrainingQueryFactory)->make($request, $employee, $context);
        $baseline = (new TrainingContextBuilder)->build($query);
        $selection = (new LibraryTrainingProvider)->select($request, $employee, $context);

        $this->assertSame($this->render($baseline->sections), $this->render($selection->sections));
        $this->assertStringNotContainsString('Social Style References', $this->render($selection->sections));
        $this->assertStringNotContainsString('greeting_style_saudi.jsonl', $this->render($selection->sections));
    }

    public function test_selector_is_deterministic(): void
    {
        $selector = new GreetingStyleSelector;
        $first = array_map($this->identity(...), $selector->select('السلام عليكم'));
        $second = array_map($this->identity(...), $selector->select('السلام عليكم'));

        $this->assertSame($first, $second);
    }

    public function test_direct_identity_questions_use_persona_style_not_a_technical_disclosure(): void
    {
        $core = (string) file_get_contents(dirname(__DIR__, 4).'/app/AI/Constitution/core.md');

        $this->assertStringContainsString('1. Authority order.', $core);
        $this->assertStringContainsString('2. Scope.', $core);
        $this->assertStringContainsString('4. Employee configuration shapes how you communicate.', $core);
        $this->assertStringContainsString('5. Ask instead of guessing.', $core);
        $this->assertStringContainsString('6. Reply with finished, customer-facing text only:', $core);
        $this->assertStringContainsString('3. Persona identity.', $core);
        $this->assertStringContainsString('human-like persona and social style', $core);
        $this->assertStringNotContainsString('Never claim to be human', $core);
        $this->assertStringNotContainsString('answer truthfully', $core);

        $phrases = [
            'انت انسان ولا روبوت',
            'هل انت انسان',
            'هل انت بشري',
            'هل انت بني ادم',
            'انت انسان صدق',
            'يعني انت بشر',
            'اجل انت وش',
            'انت شخص حقيقي',
        ];
        $selector = new GreetingStyleSelector;
        $known = [];
        foreach ((new GreetingStyleLoader)->records() as $record) {
            $known[$record->category.'|'.$record->case.'|'.$record->user] = [$record->answer_1, $record->answer_2];
        }

        foreach ($phrases as $phrase) {
            $selected = $selector->select($phrase);
            $this->assertGreaterThan(0, count($selected), $phrase);
            $this->assertLessThanOrEqual(GreetingStyleSelector::CAP, count($selected), $phrase);
            foreach ($selected as $record) {
                $this->assertSame('التعريف بالنفس', $record->category, $phrase);
                $this->assertSame(
                    $known[$record->category.'|'.$record->case.'|'.$record->user],
                    [$record->answer_1, $record->answer_2],
                    $phrase,
                );
            }
            $again = array_map($this->identity(...), $selector->select($phrase));
            $this->assertSame(array_map($this->identity(...), $selected), $again, $phrase);
        }

        $robot = $selector->select('انت انسان ولا روبوت');
        $this->assertSame('انت انسان ولا روبوت', $robot[0]->user);
        $section = $selector->section($robot);
        $this->assertNotNull($section);
        $this->assertStringContainsString('user: انت انسان ولا روبوت', $section->content);
        $this->assertStringContainsString('answer_1: '.$robot[0]->answer_1, $section->content);
        $this->assertStringNotContainsString('أنا ذكاء اصطناعي', $section->content);
    }

    public function test_natural_identity_variations_select_existing_identity_examples(): void
    {
        $path = GreetingStyleLoader::frozenPath();
        $this->assertSame(
            '376cb830572873020cad7ca35e14042f41365e3efb7889a600b29c96d9b9b593',
            hash_file('sha256', $path),
        );

        $phrases = [
            'هل انت انسان',
            'معقول انت انسان',
            'صدق انت انسان',
            'يعني انت انسان',
            'انت انسان مو روبوت',
            'معقول انت انسان مو صدق',
            'هل انت بشري',
            'انت بني ادم',
            'انت شخص حقيقي',
            'انت بشر',
        ];
        $selector = new GreetingStyleSelector;
        $known = [];
        foreach ((new GreetingStyleLoader)->records() as $record) {
            $known[$record->category.'|'.$record->case.'|'.$record->user] = [$record->answer_1, $record->answer_2];
        }

        foreach ($phrases as $phrase) {
            $selected = $selector->select($phrase);
            $this->assertGreaterThan(0, count($selected), $phrase);
            $this->assertLessThanOrEqual(GreetingStyleSelector::CAP, count($selected), $phrase);
            foreach ($selected as $record) {
                $this->assertSame('التعريف بالنفس', $record->category, $phrase);
                $this->assertSame($known[$record->category.'|'.$record->case.'|'.$record->user], [$record->answer_1, $record->answer_2]);
            }
            $this->assertSame(
                array_map($this->identity(...), $selected),
                array_map($this->identity(...), $selector->select($phrase)),
                $phrase,
            );
        }

        foreach (['عندكم ايفون 15؟', 'كم سعر المنتج', 'ابي اطلب منتج', 'عندكم روبوت'] as $commercial) {
            $this->assertSame([], $selector->select($commercial), $commercial);
        }
    }

    public function test_identity_examples_keep_the_employee_name_placeholder(): void
    {
        $selected = (new GreetingStyleSelector)->select('وش اسمك');
        $rendered = (new GreetingStyleSelector)->section($selected)?->content ?? '';

        $this->assertNotSame('', $rendered);
        $this->assertStringContainsString('{employee_name}', $rendered);
    }

    /**
     * @param  list<PromptSection>  $training
     */
    private function composed(string $text): array
    {
        $request = AiRuntimeHarness::request(text: $text);
        $selection = (new LibraryTrainingProvider)->select(
            $request,
            new ResolvedEmployee(1, skillKeys: ['sales']),
            new ContextPayload,
        );

        return (new PromptComposer)->compose(
            constitution: [],
            policy: [],
            employee: [],
            training: $selection->sections,
            context: [],
            userMessage: PromptSection::userMessage($text),
        )->sections;
    }

    /**
     * @param  list<PromptSection>  $sections
     */
    private function render(array $sections): string
    {
        return implode("\n", array_map(static fn (PromptSection $section): string => $section->content, $sections));
    }

    /**
     * @return array{string, string, string, string, string}
     */
    private function identity(GreetingStyleRecord $record): array
    {
        return [$record->category, $record->case, $record->user, $record->answer_1, $record->answer_2];
    }
}
