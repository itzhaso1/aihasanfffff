<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Models;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ModelArchitectureTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../../app/AI/Models';

    public function test_model_layer_is_isolated_from_legacy_provider_and_inbox_stacks(): void
    {
        foreach ($this->phpFiles() as $path => $source) {
            $this->assertStringNotContainsString('GoogleAiStudioProvider', $source, $path);
            $this->assertStringNotContainsString('OpenAiProvider', $source, $path);
            $this->assertStringNotContainsString('AiProviderManager', $source, $path);
            $this->assertStringNotContainsString('InboxAgentOrchestrator', $source, $path);
            $this->assertStringNotContainsString('InboxAgentLoop', $source, $path);
            $this->assertStringNotContainsString('AIService', $source, $path);
            $this->assertStringNotContainsString('ProcessAIResponse', $source, $path);
            $this->assertStringNotContainsString('App\\Services\\Finance', $source, $path);
            $this->assertStringNotContainsString('App\\Services\\Pos', $source, $path);
            $this->assertStringNotContainsString('App\\Services\\Cashier', $source, $path);
            $this->assertStringNotContainsString('EmployeePreviewRunner', $source, $path);
        }
    }

    public function test_preview_uses_the_canonical_model_gateway_contract(): void
    {
        $preview = (string) file_get_contents(__DIR__.'/../../../../app/AI/Employees/Preview/EmployeePreviewRunner.php');
        $provider = (string) file_get_contents(__DIR__.'/../../../../app/Providers/AppServiceProvider.php');

        $this->assertStringContainsString('ModelGateway', $preview);
        $this->assertStringNotContainsString('UnavailableModelGateway', $preview);
        $this->assertStringNotContainsString('google_ai_studio', $preview);
        $this->assertStringContainsString('ModelGateway::class, HasimModelGateway::class', $provider);
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
