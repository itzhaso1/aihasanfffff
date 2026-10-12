<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Tools;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ToolArchitectureTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../../app/AI/Tools';

    public function test_tool_layer_does_not_reach_live_inbox_or_money_surfaces(): void
    {
        foreach ($this->phpFiles() as $path => $source) {
            $this->assertStringNotContainsString('AIService', $source, $path);
            $this->assertStringNotContainsString('ProcessAIResponse', $source, $path);
            $this->assertStringNotContainsString('InboxAgentOrchestrator', $source, $path);
            $this->assertStringNotContainsString('InboxAgentLoop', $source, $path);
            $this->assertStringNotContainsString('App\\Services\\Finance', $source, $path);
            $this->assertStringNotContainsString('App\\Services\\Pos', $source, $path);
            $this->assertStringNotContainsString('App\\Services\\Cashier', $source, $path);
            $this->assertStringNotContainsString('EmployeePreviewRunner', $source, $path);
        }
    }

    public function test_preview_runner_is_still_not_wired_to_the_canonical_layer(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../../app/AI/Employees/Preview/PreviewToolRunner.php');

        $this->assertStringNotContainsString('HasimToolRunner', $source);
        $this->assertStringNotContainsString('App\\AI\\Tools', $source);
        $this->assertStringContainsString('return [];', $source);
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
