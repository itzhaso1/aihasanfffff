<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Employees;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Employees are data: display names live only in preset JSON, PHP never branches on a
 * name, the employee layer never writes ai_settings and never reaches the live Inbox.
 */
class EmployeeArchitectureTest extends TestCase
{
    private const ROOT = __DIR__.'/../../../../app/AI/Employees';

    /** @var list<string> */
    private const PRESET_NAMES = ['مشعل', 'طارق', 'محمد'];

    public function test_php_code_contains_no_employee_display_names(): void
    {
        foreach ($this->phpFiles() as $path => $source) {
            foreach (self::PRESET_NAMES as $name) {
                $this->assertStringNotContainsString($name, $source, $path);
            }
        }
    }

    public function test_display_names_exist_only_as_preset_data(): void
    {
        $found = [];
        foreach (glob(self::ROOT.'/Presets/*.json') ?: [] as $path) {
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $found[] = $data['display_name'];
        }
        sort($found);
        $expected = self::PRESET_NAMES;
        sort($expected);

        $this->assertSame($expected, $found);
    }

    public function test_no_code_branches_on_an_employee_name(): void
    {
        foreach ($this->phpFiles() as $path => $source) {
            $this->assertDoesNotMatchRegularExpression('/(->name|\[\'name\'\])\s*===?\s*[\'"]/u', $source, $path);
            $this->assertDoesNotMatchRegularExpression('/match\s*\(\s*\$\w+->name\b/u', $source, $path);
        }
    }

    public function test_employee_layer_never_writes_ai_settings(): void
    {
        foreach ($this->phpFiles() as $path => $source) {
            $this->assertDoesNotMatchRegularExpression('/AiSetting::[^;]*->(update|create|save|delete|forceFill|updateOrCreate)\(/s', $source, $path);
            $this->assertDoesNotMatchRegularExpression('/\$setting->(update|save|delete|fill|forceFill)\(/', $source, $path);
            $this->assertStringNotContainsString("table('ai_settings')", $source, $path);
        }
    }

    public function test_employee_layer_is_not_wired_into_the_live_inbox(): void
    {
        $inbox = [
            'app/Services/AI/AIService.php',
            'app/Jobs/ProcessAIResponse.php',
        ];
        foreach ($inbox as $relative) {
            $path = __DIR__.'/../../../../'.$relative;
            if (! is_file($path)) {
                continue;
            }
            $this->assertStringNotContainsString('App\\AI\\Employees', (string) file_get_contents($path), $relative);
        }
    }

    public function test_employee_layer_contains_no_provider_http_calls(): void
    {
        foreach ($this->phpFiles() as $path => $source) {
            $this->assertStringNotContainsString('Http::', $source, $path);
            $this->assertStringNotContainsString('GoogleAiStudioProvider', $source, $path);
            $this->assertStringNotContainsString('OpenAiProvider', $source, $path);
        }
    }

    public function test_preview_uses_canonical_context_not_an_empty_preview_provider(): void
    {
        $preview = (string) file_get_contents(self::ROOT.'/Preview/EmployeePreviewRunner.php');

        $this->assertStringContainsString('AiContextProvider', $preview);
        $this->assertStringContainsString('employeeId: (int) $employee->id', $preview);
        $this->assertStringNotContainsString('PreviewContextProvider', $preview);
        $this->assertStringNotContainsString('UnavailableModelGateway', $preview);
        $this->assertFileDoesNotExist(self::ROOT.'/Preview/UnavailableModelGateway.php');
        $this->assertFileDoesNotExist(self::ROOT.'/Preview/PreviewContextProvider.php');
        $this->assertDoesNotMatchRegularExpression('/name:\s*[\'"]مشعل[\'"]/', $preview);
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
        $this->assertNotSame([], $files);

        return $files;
    }
}
