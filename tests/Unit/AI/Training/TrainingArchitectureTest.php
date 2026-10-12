<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Training;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\AI\Training\TrainingFixture;

/**
 * Keeps training generic and behavior in Markdown, and keeps the PHP free of
 * employees, providers, channels, persistence and dataset tooling.
 */
class TrainingArchitectureTest extends TestCase
{
    private const EMPLOYEE_NAMES = ['مشعل', 'محمد', 'نورة', 'سارة', 'طارق', 'mishaal', 'mohammad', 'noura', 'tareq'];

    private const GENERIC_IDENTITY = [
        'AI Assistant',
        'virtual assistant',
        'أنا المساعد الذكي',
        'أنا مساعد ذكي',
        'مساعد افتراضي',
        'my name is',
        'اسمي ',
    ];

    private const PROVIDERS = ['gemini', 'openai', 'anthropic', 'claude', 'gpt-', 'google ai'];

    private const CHANNELS = ['whatsapp', 'instagram', 'telegram', 'tiktok', 'facebook', 'messenger'];

    /** Orchestration classes hold no behavioral text; messages and patterns live elsewhere. */
    private const ORCHESTRATION_FILES = [
        'TrainingContextBuilder.php', 'TrainingRepository.php', 'TrainingIndex.php', 'TrainingLoader.php',
        'TrainingPlan.php', 'TrainingSelectionVersion.php', 'LibraryTrainingProvider.php', 'DefaultTrainingQueryFactory.php',
    ];

    public function test_training_content_has_no_employee_provider_or_channel_names(): void
    {
        foreach ($this->files('md') as $path => $source) {
            foreach ([...self::EMPLOYEE_NAMES, ...self::GENERIC_IDENTITY, ...self::PROVIDERS, ...self::CHANNELS] as $term) {
                $this->assertStringNotContainsStringIgnoringCase($term, $source, $path.' mentions '.$term);
            }
        }
    }

    public function test_training_code_has_no_employee_provider_channel_or_persistence_dependencies(): void
    {
        foreach ($this->files('php') as $path => $source) {
            foreach ([...self::EMPLOYEE_NAMES, ...self::PROVIDERS, ...self::CHANNELS] as $term) {
                $this->assertStringNotContainsStringIgnoringCase($term, $source, $path.' mentions '.$term);
            }
            preg_match_all('/^use\s+([^;]+);/m', $source, $matches);
            foreach ($matches[1] as $import) {
                $this->assertTrue(
                    str_starts_with($import, 'App\\AI\\') || ! str_contains($import, '\\'),
                    $path.' imports '.$import,
                );
            }
            $this->assertStringNotContainsString('Illuminate\\', $source, $path);
            $this->assertDoesNotMatchRegularExpression('/\b(DB|Http|Cache|Storage|Log|Config|Schema)::/', $source, $path);
            $this->assertDoesNotMatchRegularExpression('/(?<![>\w:$])(app|config|env|include|require|eval)\s*\(/', $source, $path);
            $this->assertStringContainsString('declare(strict_types=1);', $source, $path);
        }
    }

    public function test_orchestration_code_holds_no_behavioral_text(): void
    {
        foreach (self::ORCHESTRATION_FILES as $file) {
            $source = (string) file_get_contents(TrainingFixture::realDirectory().DIRECTORY_SEPARATOR.$file);
            foreach (token_get_all($source) as $token) {
                if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $literal = trim($token[1], '\'"');
                $this->assertLessThanOrEqual(40, mb_strlen($literal), $file.' holds a long literal: '.$literal);
                $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $literal, $file);
            }
        }
    }

    public function test_no_dataset_importer_or_fine_tuning_tooling_exists(): void
    {
        $roots = [TrainingFixture::realDirectory(), dirname(TrainingFixture::realDirectory(), 2).DIRECTORY_SEPARATOR.'Console'];
        foreach ($roots as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $this->assertDoesNotMatchRegularExpression('/(dataset|7000|fine[_-]?tun|finetun)/i', $file->getFilename(), $file->getPathname());
            }
        }
    }

    public function test_training_is_not_stored_in_the_database(): void
    {
        $migrations = glob(dirname(TrainingFixture::realDirectory(), 3).DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.'*.php') ?: [];
        foreach ($migrations as $migration) {
            $this->assertDoesNotMatchRegularExpression('/create\(\s*[\'"]ai_training/i', (string) file_get_contents($migration), basename($migration));
        }
    }

    /**
     * @return array<string, string>
     */
    private function files(string $extension): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(TrainingFixture::realDirectory(), FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === $extension) {
                $files[substr($file->getPathname(), strlen(TrainingFixture::realDirectory()) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
        $this->assertNotEmpty($files);

        return $files;
    }
}
