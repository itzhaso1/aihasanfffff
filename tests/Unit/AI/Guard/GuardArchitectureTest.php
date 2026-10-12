<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Guard;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class GuardArchitectureTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../../app/AI/Guard';

    public function test_guard_layer_is_side_effect_free_and_isolated(): void
    {
        foreach ($this->phpFiles() as $path => $source) {
            $this->assertStringNotContainsString('Http::', $source, $path);
            $this->assertStringNotContainsString('DB::', $source, $path);
            $this->assertStringNotContainsString('Log::', $source, $path);
            $this->assertStringNotContainsString('InboxAgentOrchestrator', $source, $path);
            $this->assertStringNotContainsString('AIService', $source, $path);
            $this->assertStringNotContainsString('ProcessAIResponse', $source, $path);
            $this->assertStringNotContainsString('App\\Services\\Finance', $source, $path);
            $this->assertStringNotContainsString('App\\Services\\Pos', $source, $path);
            $this->assertStringNotContainsString('App\\Services\\Cashier', $source, $path);
            $this->assertStringNotContainsString('HasimModelGateway', $source, $path);
            $this->assertStringNotContainsString('EmployeePreviewRunner', $source, $path);
        }
    }

    public function test_preview_guard_applies_production_safety_without_passthrough(): void
    {
        $preview = (string) file_get_contents(__DIR__.'/../../../../app/AI/Employees/Preview/EmployeePreviewRunner.php');
        $guard = (string) file_get_contents(__DIR__.'/../../../../app/AI/Employees/Preview/PreviewResponseGuard.php');

        $this->assertStringContainsString('PreviewResponseGuard', $preview);
        $this->assertStringNotContainsString('new HasimResponseGuard', $preview);
        $this->assertStringContainsString('HasimResponseGuard', $guard);
        $this->assertStringNotContainsString('guard_not_implemented', $guard);
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
