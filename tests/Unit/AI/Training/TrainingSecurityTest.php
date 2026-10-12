<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Training;

use App\AI\Training\TrainingContextBuilder;
use App\AI\Training\TrainingException;
use App\AI\Training\TrainingIssueCode;
use App\AI\Training\TrainingLinter;
use App\AI\Training\TrainingLoader;
use App\AI\Training\TrainingQuery;
use App\AI\Training\TrainingRepository;
use App\AI\Training\TrainingSource;
use App\AI\Training\TrainingSourceFile;
use App\AI\Training\TrainingType;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AI\Training\TrainingFixture;
use Tests\TestCase;

class TrainingSecurityTest extends TestCase
{
    private ?TrainingFixture $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->remove();
        parent::tearDown();
    }

    public function test_a_file_handle_pointing_outside_the_library_cannot_be_read(): void
    {
        $this->fixture = TrainingFixture::empty();
        $outside = tempnam(sys_get_temp_dir(), 'hasim-outside-');
        file_put_contents((string) $outside, "---\nid: rule.x\n---\nsecret");

        try {
            (new TrainingSource(directory: $this->fixture->directory))->read(
                new TrainingSourceFile('rules/../../outside.md', TrainingType::Rule, (string) $outside),
            );
            $this->fail('A file outside the library was read.');
        } catch (TrainingException $exception) {
            $this->assertSame(TrainingIssueCode::OutsideRoot, $exception->issue);
        } finally {
            @unlink((string) $outside);
        }
    }

    public function test_discovery_only_accepts_plain_markdown_names_inside_known_folders(): void
    {
        $this->fixture = TrainingFixture::empty();
        foreach (['rules/..hidden.md', 'rules/UPPER.md', 'rules/space name.md', 'rules/x.md.php', 'examples/a/b/deep.md', 'secrets/key.md'] as $path) {
            $this->fixture->write($path, 'content');
        }

        $scan = (new TrainingSource(directory: $this->fixture->directory))->scan();

        $this->assertSame([], $scan->files);
        $this->assertCount(6, $scan->problems);
        foreach ($scan->problems as $problem) {
            $this->assertSame(TrainingIssueCode::UnexpectedFile, $problem->issue);
        }
    }

    public function test_symlink_escaping_the_library_is_rejected(): void
    {
        $this->fixture = TrainingFixture::empty();
        $outside = tempnam(sys_get_temp_dir(), 'hasim-outside-');
        file_put_contents((string) $outside, 'outside');

        try {
            if (! @symlink((string) $outside, $this->fixture->path('rules/linked.md'))) {
                $this->markTestSkipped('Symlinks are not available on this system.');
            }
            $scan = (new TrainingSource(directory: $this->fixture->directory))->scan();

            $this->assertSame([], $scan->files);
            $this->assertSame(TrainingIssueCode::OutsideRoot, $scan->problems[0]->issue);
        } finally {
            @unlink((string) $outside);
        }
    }

    public function test_loading_selecting_and_linting_have_no_database_or_outbound_side_effects(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        Http::fake();
        Queue::fake();
        Bus::fake();
        Event::fake();
        Mail::fake();
        Notification::fake();

        $repository = new TrainingRepository(TrainingLoader::forDirectory());
        $repository->list();
        (new TrainingContextBuilder($repository))->build(new TrainingQuery(profile: 'default', skills: ['sales'], intents: ['short_message'], dialect: 'saudi', language: 'ar'));
        (new TrainingLinter)->lint();

        $this->assertSame(0, $queries);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Event::assertNothingDispatched();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }
}
