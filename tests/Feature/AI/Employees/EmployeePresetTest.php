<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Employees;

use App\AI\Employees\Drafts\EmployeeDraftMapper;
use App\AI\Employees\EmployeeFactory;
use App\AI\Employees\EmployeeOperationException;
use App\AI\Employees\EmployeeRepository;
use App\AI\Employees\EmployeeService;
use App\AI\Employees\Presets\InvalidPresetException;
use App\AI\Employees\Presets\PresetRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\AI\Employees\CreatesEmployees;
use Tests\TestCase;

class EmployeePresetTest extends TestCase
{
    use CreatesEmployees;
    use RefreshDatabase;

    public function test_bundled_presets_load_and_validate(): void
    {
        $presets = app(PresetRepository::class)->all();

        $this->assertSame(['general_neutral', 'sales_saudi', 'support_jordanian'], array_map(static fn ($preset): string => $preset->key, $presets));
        foreach ($presets as $preset) {
            $this->assertGreaterThanOrEqual(1, $preset->version);
            $this->assertNotSame('', $preset->displayName);
            $this->assertArrayNotHasKey('key', $preset->employee);
        }
    }

    public function test_creating_from_a_preset_makes_independent_employee_records(): void
    {
        $workspace = $this->workspace();
        $service = app(EmployeeService::class);
        $factory = app(EmployeeFactory::class);

        $first = $service->create($workspace, $factory->fromPreset('sales_saudi'));
        $second = $service->create($workspace, $factory->fromPreset('sales_saudi', ['name' => 'Fahad']));
        $service->update($first, ['persona' => ['tone' => 'calm'], 'skills' => ['sales']]);

        $second = app(EmployeeRepository::class)->reload($second);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->persona?->id, $second->persona?->id);
        $this->assertSame('Fahad', $second->name);
        $this->assertNotSame('calm', $second->persona?->tone);
        $this->assertGreaterThan(1, count($second->skillKeys()));
        $this->assertSame('sales_saudi', $second->preset_key);
        $this->assertSame(1, $second->preset_version);
        $this->assertSame('saudi', $second->persona?->dialect);
    }

    public function test_changing_a_preset_file_never_changes_existing_employees(): void
    {
        $directory = storage_path('framework/testing/presets_'.uniqid());
        File::ensureDirectoryExists($directory);
        File::put($directory.'/sample.json', json_encode($this->preset('Sample', 'warm'), JSON_THROW_ON_ERROR));
        $factory = new EmployeeFactory(new PresetRepository(new EmployeeDraftMapper, $directory), new EmployeeDraftMapper);

        try {
            $employee = app(EmployeeService::class)->create($this->workspace(), $factory->fromPreset('sample'));
            File::put($directory.'/sample.json', json_encode([...$this->preset('Changed', 'calm'), 'version' => 2], JSON_THROW_ON_ERROR));

            $fresh = app(EmployeeRepository::class)->reload($employee);
            $this->assertSame('Sample', $fresh->name);
            $this->assertSame('warm', $fresh->persona?->tone);
            $this->assertSame(1, $fresh->preset_version);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_invalid_or_unknown_presets_are_rejected(): void
    {
        $directory = storage_path('framework/testing/presets_'.uniqid());
        File::ensureDirectoryExists($directory);
        File::put($directory.'/broken.json', json_encode([...$this->preset('Broken', 'warm'), 'role' => 'wizard'], JSON_THROW_ON_ERROR));

        try {
            (new PresetRepository(new EmployeeDraftMapper, $directory))->all();
            $this->fail('Invalid preset was accepted.');
        } catch (InvalidPresetException $exception) {
            $this->assertSame('broken', $exception->preset);
        } finally {
            File::deleteDirectory($directory);
        }

        $this->expectException(EmployeeOperationException::class);
        app(EmployeeFactory::class)->fromPreset('does_not_exist');
    }

    /**
     * @return array<string, mixed>
     */
    private function preset(string $name, string $tone): array
    {
        return [
            'key' => 'sample',
            'version' => 1,
            'display_name' => $name,
            'name' => $name,
            'role' => 'sales_agent',
            'persona' => ['language' => 'ar', 'tone' => $tone],
        ];
    }
}
