<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Skills;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class SkillArchitectureTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../../app/AI/Skills';

    private const FORBIDDEN = [
        'مشعل', 'محمد', 'نورة', 'سارة', 'طارق', 'mishaal', 'mohammad', 'noura', 'tareq',
        'gemini', 'openai', 'anthropic', 'claude', 'gpt-', 'google ai',
        'whatsapp', 'instagram', 'telegram', 'tiktok', 'facebook', 'messenger',
    ];

    public function test_skill_registry_has_no_employee_provider_or_channel_hardcoding(): void
    {
        foreach ($this->phpFiles() as $path => $source) {
            foreach (self::FORBIDDEN as $term) {
                $this->assertStringNotContainsStringIgnoringCase($term, $source, $path.' mentions '.$term);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function phpFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }
        $this->assertNotEmpty($files);

        return $files;
    }
}
