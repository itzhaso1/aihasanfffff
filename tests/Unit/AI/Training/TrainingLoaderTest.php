<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Training;

use App\AI\Training\TrainingException;
use App\AI\Training\TrainingIndex;
use App\AI\Training\TrainingIssueCode;
use App\AI\Training\TrainingLimits;
use App\AI\Training\TrainingLoader;
use App\AI\Training\TrainingPriority;
use App\AI\Training\TrainingType;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AI\Training\TrainingFixture;

class TrainingLoaderTest extends TestCase
{
    private ?TrainingFixture $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->remove();
        parent::tearDown();
    }

    public function test_real_library_loads(): void
    {
        $index = TrainingLoader::forDirectory()->load();

        $this->assertGreaterThanOrEqual(20, count($index->all()));
        $this->assertNotNull($index->find('rule.communication'));
        $this->assertTrue($index->find('rule.communication')?->always);
        $this->assertSame(TrainingType::Profile, $index->find('profile.default')?->type);
    }

    public function test_valid_file_becomes_an_immutable_typed_document(): void
    {
        $this->fixture = TrainingFixture::empty();
        $this->fixture->valid('rules/short.md', 'rule.short', 'rule', "# Short\n\nKeep it short.", [
            'priority' => 'high',
            'title' => 'Short replies',
            'applies_to' => "\n  skills: [sales, customer_support]\n  intents:\n    - short_message",
        ]);

        $document = $this->load()->find('rule.short');

        $this->assertNotNull($document);
        $this->assertSame(TrainingType::Rule, $document->type);
        $this->assertSame(1, $document->version);
        $this->assertSame(TrainingPriority::High, $document->priority);
        $this->assertFalse($document->always);
        $this->assertSame(['customer_support', 'sales'], $document->appliesTo->toArray()['skills']);
        $this->assertSame(['short_message'], $document->appliesTo->toArray()['intents']);
        $this->assertSame(2000, $document->maxChars);
        $this->assertSame("# Short\n\nKeep it short.", $document->content);
        $this->assertSame('rules/short.md', $document->source);
        $this->assertSame('Short replies', $document->title);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $document->contentHash);

        $this->expectException(Error::class);
        /** @phpstan-ignore-next-line */
        $document->content = 'tampered';
    }

    /**
     * @return array<string, array{0: string, 1: TrainingIssueCode}>
     */
    public static function invalidFiles(): array
    {
        $front = "---\nid: rule.x\ntype: rule\nversion: 1\npriority: normal\nalways: false\nmax_chars: 500\n";

        return [
            'missing front matter' => ["# Just text\nbody", TrainingIssueCode::MissingFrontMatter],
            'unclosed front matter' => [$front.'body', TrainingIssueCode::InvalidFrontMatter],
            'bad indentation' => [$front."applies_to:\n skills: [sales]\n---\nbody", TrainingIssueCode::InvalidFrontMatter],
            'tab in front matter' => [$front."title:\tTabbed\n---\nbody", TrainingIssueCode::InvalidFrontMatter],
            'duplicate key' => [$front."version: 2\n---\nbody", TrainingIssueCode::InvalidFrontMatter],
            'unknown field' => [$front."owner: someone\n---\nbody", TrainingIssueCode::UnknownField],
            'missing field' => ["---\nid: rule.x\ntype: rule\n---\nbody", TrainingIssueCode::MissingField],
            'empty body' => [$front."---\n   \n", TrainingIssueCode::EmptyContent],
            'invalid utf-8' => [$front."---\nbody \xC3\x28", TrainingIssueCode::InvalidEncoding],
            'php code' => [$front."---\n<?php echo 1;", TrainingIssueCode::ExecutableContent],
        ];
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_files_fail_with_a_specific_issue(string $content, TrainingIssueCode $issue): void
    {
        $this->fixture = TrainingFixture::empty();
        $this->fixture->write('rules/x.md', $content);

        $this->assertLoadFails($issue);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: TrainingIssueCode}>
     */
    public static function invalidMetadata(): array
    {
        return [
            'unsupported type' => [['type' => 'lesson'], TrainingIssueCode::InvalidType],
            'type in wrong folder' => [['type' => 'playbook', 'id' => 'playbook.x'], TrainingIssueCode::TypeFolderMismatch],
            'id without type prefix' => [['id' => 'x'], TrainingIssueCode::InvalidId],
            'id with other type prefix' => [['id' => 'example.x'], TrainingIssueCode::InvalidId],
            'version zero' => [['version' => '0'], TrainingIssueCode::InvalidVersion],
            'version text' => [['version' => 'one'], TrainingIssueCode::InvalidVersion],
            'unknown priority' => [['priority' => 'urgent'], TrainingIssueCode::InvalidPriority],
            'always not boolean' => [['always' => 'yes'], TrainingIssueCode::InvalidAlways],
            'always with selectors' => [['always' => 'true', 'applies_to' => "\n  skills: [sales]"], TrainingIssueCode::InvalidAlways],
            'unknown dimension' => [['applies_to' => "\n  moods: [happy]"], TrainingIssueCode::InvalidAppliesTo],
            'empty selector list' => [['applies_to' => "\n  skills: []"], TrainingIssueCode::InvalidAppliesTo],
            'bad selector value' => [['applies_to' => "\n  skills: [Sales Team]"], TrainingIssueCode::InvalidAppliesTo],
            'max chars too small' => [['max_chars' => '10'], TrainingIssueCode::InvalidMaxChars],
            'max chars too large' => [['max_chars' => '999999'], TrainingIssueCode::InvalidMaxChars],
            'includes on a rule' => [['includes' => '[rule.y]'], TrainingIssueCode::InvalidIncludes],
            'body over max chars' => [['max_chars' => '50'], TrainingIssueCode::ContentTooLong],
        ];
    }

    /**
     * @param  array<string, string>  $overrides
     */
    #[DataProvider('invalidMetadata')]
    public function test_invalid_metadata_is_rejected(array $overrides, TrainingIssueCode $issue): void
    {
        $this->fixture = TrainingFixture::empty();
        $this->fixture->valid('rules/x.md', 'rule.x', 'rule', str_repeat('Behavior guidance. ', 5), $overrides);

        $this->assertLoadFails($issue);
    }

    public function test_examples_and_scenarios_need_intents_and_their_sections(): void
    {
        $this->fixture = TrainingFixture::empty();
        $this->fixture->valid('examples/a/no_intent.md', 'example.no_intent', 'example', $this->exampleBody());
        $this->assertLoadFails(TrainingIssueCode::InvalidAppliesTo);

        $this->fixture->valid('examples/a/no_intent.md', 'example.no_intent', 'example', "## Customer\nكم", [
            'applies_to' => "\n  intents: [short_message]",
        ]);
        $this->assertLoadFails(TrainingIssueCode::MissingHeading);
    }

    public function test_profiles_need_existing_content_includes(): void
    {
        $this->fixture = TrainingFixture::empty();
        $this->fixture->valid('profiles/p.md', 'profile.p', 'profile', 'Profile text.', ['includes' => '[rule.missing]']);

        $this->assertLoadFails(TrainingIssueCode::UnknownInclude);
    }

    public function test_duplicate_ids_and_duplicate_bodies_are_rejected(): void
    {
        $this->fixture = TrainingFixture::empty();
        $this->fixture->valid('rules/a.md', 'rule.same', 'rule', 'First body.');
        $this->fixture->valid('rules/b.md', 'rule.same', 'rule', 'Second body.');
        $this->assertLoadFails(TrainingIssueCode::DuplicateId);

        $this->fixture->valid('rules/b.md', 'rule.other', 'rule', 'First body.');
        $this->assertLoadFails(TrainingIssueCode::DuplicateContent);
    }

    public function test_size_limits_are_enforced(): void
    {
        $this->fixture = TrainingFixture::empty();
        $this->fixture->valid('rules/a.md', 'rule.a', 'rule', str_repeat('a', 400));
        $this->fixture->valid('rules/b.md', 'rule.b', 'rule', str_repeat('b', 400));

        $this->assertLoadFails(TrainingIssueCode::FileTooLarge, new TrainingLimits(maxFileBytes: 300, maxTypeBytes: 5000, maxLibraryBytes: 5000));
        $this->assertLoadFails(TrainingIssueCode::TypeTooLarge, new TrainingLimits(maxFileBytes: 600, maxTypeBytes: 700, maxLibraryBytes: 5000));
    }

    public function test_library_version_is_stable_and_content_sensitive(): void
    {
        $this->fixture = TrainingFixture::copyOfRealLibrary();
        $real = TrainingLoader::forDirectory()->load()->version();
        $copy = $this->load()->version();

        $crlf = str_replace("\n", "\r\n", $this->fixture->read('rules/sales.md'));
        $this->fixture->write('rules/sales.md', "\u{FEFF}".$crlf);
        $normalized = $this->load()->version();

        $this->fixture->write('rules/sales.md', $crlf.'!');
        $changed = $this->load()->version();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $real);
        $this->assertSame($real, $copy);
        $this->assertSame($real, $normalized);
        $this->assertNotSame($real, $changed);
    }

    public function test_unexpected_files_make_the_load_fail_closed(): void
    {
        $this->fixture = TrainingFixture::empty();
        $this->fixture->write('rules/notes.txt', 'not markdown');

        $this->assertLoadFails(TrainingIssueCode::UnexpectedFile);
    }

    private function assertLoadFails(TrainingIssueCode $issue, ?TrainingLimits $limits = null): void
    {
        try {
            $this->load($limits);
            $this->fail('Expected a '.$issue->value.' failure.');
        } catch (TrainingException $exception) {
            $this->assertSame($issue, $exception->issue, $exception->getMessage());
        }
    }

    private function load(?TrainingLimits $limits = null): TrainingIndex
    {
        return TrainingLoader::forDirectory($this->fixture?->directory, $limits)->load();
    }

    private function exampleBody(): string
    {
        return "## Customer\nكم\n\n## Expected behavior\nUse context.\n\n## Good response\nسعره 10 ريال\n\n## Bad response\nوش تقصد؟\n\n## Why\nContext is enough.";
    }
}
