<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Training;

use App\AI\Training\TrainingDocument;
use App\AI\Training\TrainingException;
use App\AI\Training\TrainingIssueCode;
use App\AI\Training\TrainingLoader;
use App\AI\Training\TrainingRepository;
use App\AI\Training\TrainingType;
use PHPUnit\Framework\TestCase;
use Tests\Support\AI\Training\TrainingFixture;

class TrainingRepositoryTest extends TestCase
{
    private TrainingFixture $fixture;

    private TrainingRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = TrainingFixture::empty();
        $this->fixture->valid('rules/zeta.md', 'rule.zeta', 'rule', 'Zeta guidance.', ['applies_to' => "\n  skills: [sales]\n  dialects: [saudi]"]);
        $this->fixture->valid('rules/alpha.md', 'rule.alpha', 'rule', 'Alpha guidance.', ['applies_to' => "\n  skills: [sales, customer_support]\n  roles: [sales_agent]"]);
        $this->fixture->valid('rules/always.md', 'rule.always', 'rule', 'Always guidance.', ['always' => 'true']);
        $this->fixture->valid('playbooks/p.md', 'playbook.p', 'playbook', 'Playbook steps.', ['applies_to' => "\n  domains: [fashion]\n  languages: [ar]"]);
        $this->fixture->valid('examples/prices/kam.md', 'example.kam', 'example', $this->exampleBody(), ['applies_to' => "\n  intents: [short_message, price_question]"]);
        $this->repository = new TrainingRepository(TrainingLoader::forDirectory($this->fixture->directory));
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function test_find_and_get_by_id(): void
    {
        $this->assertSame('rules/alpha.md', $this->repository->findById('rule.alpha')?->source);
        $this->assertNull($this->repository->findById('rule.missing'));
        $this->assertSame('rule.zeta', $this->repository->get('rule.zeta')->id);

        try {
            $this->repository->get('rule.missing');
            $this->fail('Missing document was returned.');
        } catch (TrainingException $exception) {
            $this->assertSame(TrainingIssueCode::UnknownDocument, $exception->issue);
        }
    }

    public function test_lists_are_typed_and_ordered_by_id(): void
    {
        $this->assertSame(['example.kam', 'playbook.p', 'rule.alpha', 'rule.always', 'rule.zeta'], $this->ids($this->repository->list()));
        $this->assertSame(['rule.alpha', 'rule.always', 'rule.zeta'], $this->ids($this->repository->listByType(TrainingType::Rule)));
        $this->assertSame(['rule.alpha', 'rule.zeta'], $this->ids($this->repository->listBySkill('sales')));
        $this->assertSame(['rule.alpha'], $this->ids($this->repository->listBySkill('customer_support')));
        $this->assertSame(['example.kam'], $this->ids($this->repository->listByIntent('short_message')));
        $this->assertSame(['rule.zeta'], $this->ids($this->repository->listByDialect('saudi')));
        $this->assertSame(['playbook.p'], $this->ids($this->repository->listByLanguage('ar')));
        $this->assertSame(['rule.alpha'], $this->ids($this->repository->listByRole('sales_agent')));
        $this->assertSame(['playbook.p'], $this->ids($this->repository->listByDomain('fashion')));
        $this->assertSame([], $this->repository->listBySkill('unknown_skill'));
    }

    public function test_documents_never_expose_absolute_paths(): void
    {
        foreach ($this->repository->list() as $document) {
            $this->assertStringNotContainsString($this->fixture->directory, $document->source);
            $this->assertStringNotContainsString(DIRECTORY_SEPARATOR === '\\' ? ':\\' : '/tmp', $document->source);
        }
    }

    public function test_index_is_reused_until_any_byte_changes(): void
    {
        $first = $this->repository->index();
        $this->assertSame($first, $this->repository->index());

        $original = $this->fixture->read('rules/alpha.md');
        $sameLength = str_replace('Alpha guidance.', 'Alpha Guidance.', $original);
        $mtime = filemtime($this->fixture->path('rules/alpha.md'));
        $this->fixture->write('rules/alpha.md', $sameLength);
        touch($this->fixture->path('rules/alpha.md'), (int) $mtime);

        $second = $this->repository->index();

        $this->assertNotSame($first, $second);
        $this->assertNotSame($first->version(), $second->version());
        $this->assertSame('Alpha Guidance.', $this->repository->get('rule.alpha')->content);
    }

    public function test_index_order_and_version_do_not_depend_on_creation_order(): void
    {
        $other = TrainingFixture::empty();
        try {
            foreach (array_reverse(['rules/alpha.md', 'rules/always.md', 'rules/zeta.md', 'playbooks/p.md', 'examples/prices/kam.md']) as $file) {
                $other->write($file, $this->fixture->read($file));
            }
            $reversed = TrainingLoader::forDirectory($other->directory)->load();

            $this->assertSame($this->ids($this->repository->list()), $this->ids($reversed->all()));
            $this->assertSame($this->repository->libraryVersion(), $reversed->version());
        } finally {
            $other->remove();
        }
    }

    /**
     * @param  list<TrainingDocument>  $documents
     * @return list<string>
     */
    private function ids(array $documents): array
    {
        return array_map(static fn (TrainingDocument $document): string => $document->id, $documents);
    }

    private function exampleBody(): string
    {
        return "## Customer\nكم\n\n## Expected behavior\nUse context.\n\n## Good response\nسعره 10 ريال\n\n## Bad response\nوش تقصد؟\n\n## Why\nContext is enough.";
    }
}
