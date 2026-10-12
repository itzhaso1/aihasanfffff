<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Core;

use App\AI\Core\Data\ResolvedEmployee;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionProperty;

/**
 * Guards the core boundary: no employee identities, providers, channels, domains,
 * framework service location or persistence inside app/AI/Core.
 */
class CoreArchitectureBoundaryTest extends TestCase
{
    public function test_core_contains_no_hardcoded_employee_names(): void
    {
        foreach ($this->coreSources() as $path => $source) {
            foreach (['مشعل', 'محمد', 'نورة', 'سارة', 'طارق', 'mishaal', 'mohammad', 'noura', 'tareq'] as $name) {
                $this->assertStringNotContainsStringIgnoringCase($name, $source, $path.' mentions '.$name);
            }
        }
    }

    public function test_core_never_branches_on_employee_display_data(): void
    {
        foreach ($this->coreSources() as $path => $source) {
            $this->assertDoesNotMatchRegularExpression(
                '/(identityName|displayName|employeeName|employee\w*->name\b|\[\'name\'\])/i',
                $source,
                $path,
            );
        }

        $properties = array_map(
            static fn (ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(ResolvedEmployee::class))->getProperties(),
        );
        $this->assertEqualsCanonicalizing(['employeeId', 'configVersion', 'skillKeys', 'sections'], $properties);
    }

    public function test_core_has_no_provider_channel_or_domain_code(): void
    {
        $forbidden = [
            'gemini', 'openai', 'anthropic', 'google', 'claude', 'gpt-',
            'whatsapp', 'instagram', 'telegram', 'tiktok', 'facebook',
            'finance', 'cashier', 'App\\Services\\Pos', 'ChatCatalog', 'Flutter',
        ];

        foreach ($this->coreSources() as $path => $source) {
            foreach ($forbidden as $term) {
                $this->assertStringNotContainsStringIgnoringCase($term, $source, $path.' mentions '.$term);
            }
        }
    }

    public function test_core_depends_only_on_itself_and_php(): void
    {
        foreach ($this->coreSources() as $path => $source) {
            preg_match_all('/^use\s+([^;]+);/m', $source, $matches);
            foreach ($matches[1] as $import) {
                $this->assertTrue(
                    str_starts_with($import, 'App\\AI\\Core\\') || ! str_contains($import, '\\'),
                    $path.' imports '.$import,
                );
            }
            $this->assertDoesNotMatchRegularExpression('/(?<!function )(?<![>\w:$\\\\])(app|resolve|config|event|dispatch)\(/', $source, $path.' uses a global helper');
            $this->assertDoesNotMatchRegularExpression('/\b(DB|Http|Log|Cache|Queue|Bus|Event|Mail|Storage)::/', $source, $path.' uses a facade');
            $this->assertStringNotContainsString('Illuminate\\', $source, $path);
        }
    }

    public function test_every_core_file_declares_strict_types(): void
    {
        $sources = $this->coreSources();

        $this->assertNotEmpty($sources);
        foreach ($sources as $path => $source) {
            $this->assertStringContainsString('declare(strict_types=1);', $source, $path);
        }
    }

    /**
     * @return array<string, string>
     */
    private function coreSources(): array
    {
        $root = dirname(__DIR__, 4).'/app/AI/Core';
        $sources = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $sources[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        return $sources;
    }
}
