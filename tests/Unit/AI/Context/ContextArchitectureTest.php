<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Context;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ContextArchitectureTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../../app/AI/Context';

    public function test_context_layer_does_not_load_markdown_or_training_constitution_implementations(): void
    {
        foreach ($this->phpFiles() as $path => $source) {
            $this->assertStringNotContainsString('.md', $source, $path);
            $this->assertStringNotContainsString('ConstitutionLoader', $source, $path);
            $this->assertStringNotContainsString('TrainingLoader', $source, $path);
            $this->assertDoesNotMatchRegularExpression('/مشعل|طارق|محمد/', $source, $path);
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
