<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Constitution;

use App\AI\Constitution\ConstitutionManifest;
use PHPUnit\Framework\TestCase;
use Tests\Support\AI\Constitution\ConstitutionFixture;

/**
 * Keeps laws in Markdown and loading logic in PHP, and keeps both free of employee,
 * provider, channel, persona and persistence concerns.
 */
class ConstitutionArchitectureTest extends TestCase
{
    private const EMPLOYEE_NAMES = ['مشعل', 'محمد', 'نورة', 'سارة', 'طارق', 'mishaal', 'mohammad', 'noura', 'tareq'];

    private const PROVIDERS = ['gemini', 'openai', 'anthropic', 'google', 'claude', 'gpt'];

    private const CHANNELS = ['whatsapp', 'instagram', 'telegram', 'tiktok', 'facebook', 'messenger'];

    private const PERSONA_MARKERS = ['saudi', 'gulf', 'dialect', 'سعودي', 'لهجة', 'ابشر', 'ارحب', 'حياك'];

    public function test_every_law_file_is_in_the_manifest_and_every_manifest_file_exists(): void
    {
        $onDisk = array_map('basename', glob(ConstitutionFixture::realDirectory().DIRECTORY_SEPARATOR.'*.md') ?: []);
        $laws = array_values(array_diff($onDisk, ['README.md']));
        $manifest = ConstitutionManifest::standard()->filenames();

        sort($laws);
        $sortedManifest = $manifest;
        sort($sortedManifest);

        $this->assertSame($sortedManifest, $laws);
        $this->assertSame(['core.md', 'truth.md', 'security.md', 'privacy.md', 'safety.md', 'handoff.md', 'actions.md'], $manifest);
    }

    public function test_code_has_no_employee_provider_or_channel_names(): void
    {
        foreach ($this->phpSources() as $file => $source) {
            foreach ([...self::EMPLOYEE_NAMES, ...self::PROVIDERS, ...self::CHANNELS] as $term) {
                $this->assertStringNotContainsStringIgnoringCase($term, $source, $file.' mentions '.$term);
            }
        }
    }

    public function test_code_has_no_database_framework_or_domain_dependencies(): void
    {
        foreach ($this->phpSources() as $file => $source) {
            preg_match_all('/^use\s+([^;]+);/m', $source, $matches);
            foreach ($matches[1] as $import) {
                $this->assertTrue(
                    str_starts_with($import, 'App\\AI\\Constitution\\')
                        || str_starts_with($import, 'App\\AI\\Core\\')
                        || ! str_contains($import, '\\'),
                    $file.' imports '.$import,
                );
            }
            $this->assertStringNotContainsString('Illuminate\\', $source, $file);
            $this->assertDoesNotMatchRegularExpression('/\b(DB|Http|Cache|Storage|Log|Config)::/', $source, $file);
            $this->assertDoesNotMatchRegularExpression('/(?<![>\w:$])(app|config|env|include|require|eval)\s*\(/', $source, $file);
            $this->assertStringContainsString('declare(strict_types=1);', $source, $file);
        }
    }

    public function test_php_holds_no_law_text(): void
    {
        foreach ($this->phpSources() as $file => $source) {
            if ($file === 'ConstitutionException.php') {
                continue;
            }
            foreach (token_get_all($source) as $token) {
                if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $literal = trim($token[1], '\'"');
                $this->assertLessThanOrEqual(60, mb_strlen($literal), $file.' holds a long literal: '.$literal);
                $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $literal, $file);
                $this->assertDoesNotMatchRegularExpression('/\b(never|must|always|do not)\b/i', $literal, $file.' holds rule wording: '.$literal);
            }
        }
    }

    public function test_law_files_hold_laws_only(): void
    {
        foreach ($this->lawSources() as $file => $source) {
            foreach ([...self::EMPLOYEE_NAMES, ...self::PROVIDERS, ...self::CHANNELS, ...self::PERSONA_MARKERS] as $term) {
                $this->assertStringNotContainsStringIgnoringCase($term, $source, $file.' mentions '.$term);
            }
            $this->assertStringStartsWith('# ', $source, $file.' needs a title heading');
            $this->assertStringNotContainsString('```', $source, $file.' contains a code block');
            $this->assertMatchesRegularExpression('/^1\. /m', $source, $file.' has no numbered laws');
            $this->assertLessThan(4096, strlen($source), $file.' is longer than a concise law file');
        }
    }

    public function test_no_law_is_repeated_verbatim_across_files(): void
    {
        $seen = [];
        foreach ($this->lawSources() as $file => $source) {
            foreach (preg_split('/\R/', $source) ?: [] as $line) {
                $law = trim((string) preg_replace('/^\d+\.\s*/', '', $line));
                if (mb_strlen($law) < 30) {
                    continue;
                }
                $this->assertArrayNotHasKey($law, $seen, $file.' repeats a law from '.($seen[$law] ?? ''));
                $seen[$law] = $file;
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function phpSources(): array
    {
        $sources = [];
        foreach (glob(ConstitutionFixture::realDirectory().DIRECTORY_SEPARATOR.'*.php') ?: [] as $path) {
            $sources[basename($path)] = (string) file_get_contents($path);
        }
        $this->assertNotEmpty($sources);

        return $sources;
    }

    /**
     * @return array<string, string>
     */
    private function lawSources(): array
    {
        $sources = [];
        foreach (ConstitutionManifest::standard()->filenames() as $filename) {
            $sources[$filename] = (string) file_get_contents(ConstitutionFixture::realDirectory().DIRECTORY_SEPARATOR.$filename);
        }

        return $sources;
    }
}
