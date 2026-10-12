<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Constitution;

use App\AI\Constitution\ConstitutionException;
use App\AI\Constitution\ConstitutionLimits;
use App\AI\Constitution\ConstitutionLoader;
use App\AI\Constitution\ConstitutionManifest;
use App\AI\Constitution\ConstitutionManifestEntry;
use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AI\Constitution\ConstitutionFixture;

class ConstitutionLoaderTest extends TestCase
{
    private const ORDER = ['core', 'truth', 'security', 'privacy', 'safety', 'handoff', 'actions'];

    private ?ConstitutionFixture $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->remove();
        parent::tearDown();
    }

    public function test_all_required_files_load_as_constitution_sections(): void
    {
        $constitution = (new ConstitutionLoader)->current();

        $this->assertCount(7, $constitution->sections);
        foreach ($constitution->sections as $index => $section) {
            $id = self::ORDER[$index];
            $this->assertSame(PromptLayer::Constitution, $section->layer);
            $this->assertSame('constitution.'.$id, $section->key);
            $this->assertSame('constitution/'.$id.'.md', $section->source);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $section->version);
            $this->assertNotSame('', trim($section->content));
        }
    }

    public function test_order_is_the_explicit_manifest_order_not_the_filesystem_order(): void
    {
        $keys = array_map(
            static fn (PromptSection $section): string => $section->key,
            (new ConstitutionLoader)->current()->sections,
        );
        $alphabetical = $keys;
        sort($alphabetical);

        $this->assertSame(array_map(static fn (string $id): string => 'constitution.'.$id, self::ORDER), $keys);
        $this->assertNotSame($alphabetical, $keys);
    }

    public function test_version_is_a_stable_content_hash(): void
    {
        $first = (new ConstitutionLoader)->current()->version;
        $second = (new ConstitutionLoader)->current()->version;

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $first);
        $this->assertSame($first, $second);
    }

    public function test_same_content_keeps_the_version_regardless_of_location_or_line_endings(): void
    {
        $this->fixture = new ConstitutionFixture;
        $real = (new ConstitutionLoader)->current()->version;
        $copied = $this->loader()->current()->version;

        $this->fixture->write('truth.md', str_replace("\n", "\r\n", $this->fixture->read('truth.md')));
        $crlf = $this->loader()->current()->version;

        $this->assertSame($real, $copied);
        $this->assertSame($real, $crlf);
    }

    public function test_changing_one_character_changes_the_version(): void
    {
        $this->fixture = new ConstitutionFixture;
        $before = $this->loader()->current();

        $this->fixture->write('truth.md', $this->fixture->read('truth.md').'!');
        $after = $this->loader()->current();

        $this->assertNotSame($before->version, $after->version);
        $this->assertNotSame($before->sections[1]->version, $after->sections[1]->version);
        $this->assertSame($before->sections[0]->version, $after->sections[0]->version);
    }

    public function test_same_loader_reuses_the_result_and_reloads_after_a_change_on_disk(): void
    {
        $this->fixture = new ConstitutionFixture;
        $loader = $this->loader();
        $first = $loader->current();

        $this->assertSame($first, $loader->current());

        $this->fixture->write('core.md', $this->fixture->read('core.md')."\n7. Added law.");
        touch($this->fixture->path('core.md'), time() + 5);
        $changed = $loader->current();

        $this->assertNotSame($first->version, $changed->version);
        $this->assertStringContainsString('7. Added law.', $changed->sections[0]->content);
    }

    public function test_missing_file_fails(): void
    {
        $this->fixture = new ConstitutionFixture;
        unlink($this->fixture->path('privacy.md'));

        $this->expectExceptionObject(ConstitutionException::missingFile(new ConstitutionManifestEntry('privacy', 'privacy.md')));

        $this->loader()->current();
    }

    public function test_missing_directory_fails(): void
    {
        $this->expectException(ConstitutionException::class);

        (new ConstitutionLoader(directory: sys_get_temp_dir().DIRECTORY_SEPARATOR.'hasim-no-such-dir-'.bin2hex(random_bytes(4))))->current();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableContent(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ["  \n\r\n\t "],
            'invalid utf-8' => ["# Truth\n\xC3\x28 broken"],
            'php open tag' => ["# Truth\n<?php echo 'x';"],
            'php short echo' => ["# Truth\n<?= 'x' ?>"],
        ];
    }

    #[DataProvider('unusableContent')]
    public function test_unusable_content_fails_instead_of_loading_a_partial_constitution(string $content): void
    {
        $this->fixture = new ConstitutionFixture;
        $this->fixture->write('truth.md', $content);

        $this->expectException(ConstitutionException::class);

        $this->loader()->current();
    }

    public function test_file_and_total_size_limits_are_enforced(): void
    {
        $this->fixture = new ConstitutionFixture;
        $largest = max(array_map(fn (string $file): int => (int) filesize($this->fixture->path($file)), ConstitutionManifest::standard()->filenames()));

        try {
            $this->loader(new ConstitutionLimits(maxFileBytes: $largest - 1, maxTotalBytes: 1_000_000))->current();
            $this->fail('Per-file limit was not enforced.');
        } catch (ConstitutionException $exception) {
            $this->assertStringContainsString('byte limit', $exception->getMessage());
        }

        $this->expectExceptionMessage('byte total limit');
        $this->loader(new ConstitutionLimits(maxFileBytes: $largest, maxTotalBytes: $largest + 1))->current();
    }

    public function test_duplicate_sections_are_impossible(): void
    {
        try {
            new ConstitutionManifest([
                new ConstitutionManifestEntry('core', 'core.md'),
                new ConstitutionManifestEntry('core', 'truth.md'),
            ]);
            $this->fail('Duplicate section id was accepted.');
        } catch (ConstitutionException) {
            $this->addToAssertionCount(1);
        }

        try {
            new ConstitutionManifest([
                new ConstitutionManifestEntry('core', 'core.md'),
                new ConstitutionManifestEntry('core_again', 'core.md'),
            ]);
            $this->fail('Duplicate section file was accepted.');
        } catch (ConstitutionException) {
            $this->addToAssertionCount(1);
        }

        $this->fixture = new ConstitutionFixture;
        $this->fixture->write('actions.md', $this->fixture->read('handoff.md'));
        $this->expectExceptionMessage('same content');
        $this->loader()->current();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function traversalFilenames(): array
    {
        return [
            'parent directory' => ['../secrets.md'],
            'nested path' => ['nested/core.md'],
            'windows separator' => ['..\\core.md'],
            'absolute unix' => ['/etc/passwd'],
            'absolute windows' => ['C:\\core.md'],
            'php file' => ['core.php'],
            'hidden file' => ['.md'],
            'uppercase' => ['CORE.md'],
            'double extension' => ['core.md.php'],
        ];
    }

    #[DataProvider('traversalFilenames')]
    public function test_path_traversal_cannot_be_expressed_in_the_manifest(string $filename): void
    {
        $this->expectException(ConstitutionException::class);

        new ConstitutionManifestEntry('core', $filename);
    }

    public function test_symlinked_file_outside_the_directory_cannot_load(): void
    {
        $this->fixture = new ConstitutionFixture;
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hasim-outside-'.bin2hex(random_bytes(4)).'.md';
        file_put_contents($outside, "# Outside\nNot a law.");
        unlink($this->fixture->path('core.md'));

        try {
            if (! @symlink($outside, $this->fixture->path('core.md'))) {
                $this->markTestSkipped('Symlinks are not available on this system.');
            }

            $this->expectExceptionMessage('outside the constitution directory');
            $this->loader()->current();
        } finally {
            @unlink($outside);
        }
    }

    public function test_loaded_constitution_is_immutable(): void
    {
        $constitution = (new ConstitutionLoader)->current();

        try {
            /** @phpstan-ignore-next-line */
            $constitution->version = 'tampered';
            $this->fail('Version was writable.');
        } catch (Error) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(Error::class);
        /** @phpstan-ignore-next-line */
        $constitution->sections[0]->content = 'tampered';
    }

    private function loader(?ConstitutionLimits $limits = null): ConstitutionLoader
    {
        return new ConstitutionLoader(limits: $limits, directory: $this->fixture?->directory);
    }
}
