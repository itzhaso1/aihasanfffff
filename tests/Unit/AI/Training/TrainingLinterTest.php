<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Training;

use App\AI\Training\TrainingIssueCode;
use App\AI\Training\TrainingLimits;
use App\AI\Training\TrainingLinter;
use App\AI\Training\TrainingLintReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AI\Training\TrainingFixture;

class TrainingLinterTest extends TestCase
{
    private ?TrainingFixture $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->remove();
        parent::tearDown();
    }

    public function test_real_library_passes_lint(): void
    {
        $report = (new TrainingLinter)->lint();

        $this->assertTrue($report->passed(), json_encode(array_map(static fn ($issue) => $issue->toArray(), $report->issues), JSON_UNESCAPED_UNICODE));
        $this->assertGreaterThanOrEqual(20, $report->documentCount);
    }

    public function test_linter_reports_every_problem_instead_of_stopping_at_the_first(): void
    {
        $this->fixture = TrainingFixture::empty();
        $this->fixture->valid('rules/a.md', 'rule.same', 'rule', 'Body one.');
        $this->fixture->valid('rules/b.md', 'rule.same', 'rule', 'Body two.');
        $this->fixture->valid('rules/c.md', 'rule.c', 'rule', 'Body one.');
        $this->fixture->valid('rules/d.md', 'rule.d', 'rule', 'Body.', ['priority' => 'urgent']);

        $report = $this->lint();

        $this->assertTrue($report->has(TrainingIssueCode::DuplicateId));
        $this->assertTrue($report->has(TrainingIssueCode::DuplicateContent));
        $this->assertTrue($report->has(TrainingIssueCode::InvalidPriority));
    }

    public function test_restating_a_constitution_law_is_detected(): void
    {
        $this->fixture = TrainingFixture::empty();
        $this->fixture->valid('rules/truth_copy.md', 'rule.truth_copy', 'rule', "# Facts\n\n- Never invent, estimate, round or embellish a business fact; state it as the trusted source gives it.");
        $this->fixture->valid('rules/behavior.md', 'rule.behavior', 'rule', "# Prices\n\n- For a price question, give the price from the product data first, then one useful detail at most.");

        $report = $this->lint();

        $overlaps = array_filter($report->issues, static fn ($issue): bool => $issue->code === TrainingIssueCode::ConstitutionOverlap);
        $this->assertSame(['rules/truth_copy.md'], array_values(array_unique(array_map(static fn ($issue) => $issue->source, $overlaps))));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function liveKnowledgeFacts(): array
    {
        return [
            'current price' => ['The black dress costs 250 ريال this week.'],
            'price with currency first' => ['Standard plan is SAR 99 per month.'],
            'stock count' => ['Only 3 pieces left of the blue model.'],
            'stock wording' => ['المخزون الحالي 12 حبة'],
            'order number' => ['Customer asked about order #48213.'],
            'arabic order reference' => ['طلب رقم 5521 تم شحنه'],
        ];
    }

    #[DataProvider('liveKnowledgeFacts')]
    public function test_live_business_facts_are_rejected_in_knowledge(string $sentence): void
    {
        $this->fixture = TrainingFixture::empty();
        $this->fixture->valid('knowledge/terms.md', 'knowledge.terms', 'knowledge', "# Terms\n\n".$sentence, ['applies_to' => "\n  domains: [general]"]);

        $this->assertTrue($this->lint()->has(TrainingIssueCode::LiveBusinessFact));
    }

    public function test_illustrative_prices_in_examples_and_concepts_in_knowledge_are_allowed(): void
    {
        $this->fixture = TrainingFixture::empty();
        $this->fixture->valid('examples/pricing/kam.md', 'example.kam', 'example', "## Customer\nكم\n\n## Expected behavior\nUse context.\n\n## Good response\nسعره 299 ريال\n\n## Bad response\nوش تقصد؟\n\n## Why\nContext is enough.", ['applies_to' => "\n  intents: [short_message]"]);
        $this->fixture->valid('knowledge/terms.md', 'knowledge.terms', 'knowledge', "# Terms\n\n- Price and stock always come from the product data of the run.\n- Order status is provided by order tools.", ['applies_to' => "\n  domains: [general]"]);

        $report = $this->lint();

        $this->assertTrue($report->passed(), json_encode(array_map(static fn ($issue) => $issue->toArray(), $report->issues), JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sensitiveData(): array
    {
        return [
            'card number' => ['Test card 4111 1111 1111 1111 works.'],
            'one-time code' => ['رمز التحقق 482913'],
            'api key' => ['Use key AIzaSyA1234567890abcdefghijklmnop'],
            'bearer token' => ['Authorization: Bearer abcdefghijklmnop'],
            'saudi mobile' => ['Call the customer on 0501234567.'],
            'email' => ['Write to real.person@company.sa for help.'],
        ];
    }

    #[DataProvider('sensitiveData')]
    public function test_sensitive_data_is_rejected_in_any_document(string $sentence): void
    {
        $this->fixture = TrainingFixture::empty();
        $this->fixture->valid('rules/leak.md', 'rule.leak', 'rule', "# Leak\n\n".$sentence);

        $this->assertTrue($this->lint()->has(TrainingIssueCode::SensitiveData));
    }

    public function test_layout_selector_and_include_problems_are_reported(): void
    {
        $this->fixture = TrainingFixture::empty();
        rmdir($this->fixture->path('knowledge'));
        $this->fixture->write('rules/notes.txt', 'not markdown');
        $this->fixture->write('misc/orphan.md', 'orphan');
        $this->fixture->write('rules/Bad Name.md', 'bad name');
        $this->fixture->valid('rules/typo.md', 'rule.typo', 'rule', 'Typo selector.', ['applies_to' => "\n  skills: [salez]"]);
        $this->fixture->valid('profiles/p.md', 'profile.p', 'profile', 'Profile.', ['includes' => '[rule.not_there]']);

        $report = $this->lint();

        $this->assertTrue($report->has(TrainingIssueCode::MissingFolder));
        $this->assertTrue($report->has(TrainingIssueCode::UnexpectedFile));
        $this->assertTrue($report->has(TrainingIssueCode::UnknownSelector));
        $this->assertTrue($report->has(TrainingIssueCode::UnknownInclude));
        $sources = array_map(static fn ($issue) => $issue->source, $report->issues);
        foreach (['rules/notes.txt', 'misc', 'rules/Bad Name.md', 'knowledge/'] as $expected) {
            $this->assertContains($expected, $sources);
        }
    }

    public function test_oversized_files_and_folders_are_reported(): void
    {
        $this->fixture = TrainingFixture::empty();
        $this->fixture->valid('rules/a.md', 'rule.a', 'rule', str_repeat('a', 400));
        $this->fixture->valid('rules/b.md', 'rule.b', 'rule', str_repeat('b', 400));

        $this->assertTrue($this->lint(new TrainingLimits(maxFileBytes: 300, maxTypeBytes: 5000, maxLibraryBytes: 5000))->has(TrainingIssueCode::FileTooLarge));
        $this->assertTrue($this->lint(new TrainingLimits(maxFileBytes: 600, maxTypeBytes: 700, maxLibraryBytes: 5000))->has(TrainingIssueCode::TypeTooLarge));
    }

    public function test_linter_never_modifies_source_files(): void
    {
        $this->fixture = TrainingFixture::copyOfRealLibrary();
        $before = $this->snapshot();

        $this->lint();

        $this->assertSame($before, $this->snapshot());
    }

    private function lint(?TrainingLimits $limits = null): TrainingLintReport
    {
        return (new TrainingLinter($limits ?? new TrainingLimits, $this->fixture?->directory))->lint();
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator((string) $this->fixture?->directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files[$file->getPathname()] = hash_file('sha256', $file->getPathname()).'|'.$file->getMTime();
        }
        ksort($files);

        return $files;
    }
}
