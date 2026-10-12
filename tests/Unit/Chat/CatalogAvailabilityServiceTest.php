<?php

namespace Tests\Unit\Chat;

use App\Enums\ChatCatalog\ChatCatalogAvailabilityMode;
use App\Models\Workspace;
use App\Services\Chat\CatalogAvailabilityService;
use App\Services\Chat\ChatCatalogRootService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_catalog_honors_timezone_blackouts_and_overnight_rules(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $roots = app(ChatCatalogRootService::class);
        $catalog = $roots->updateSettings($roots->forWorkspace($workspace), [
            'availability_mode' => 'scheduled',
            'timezone' => 'Asia/Riyadh',
            'availability_schedule' => [
                'enabled' => true,
                'weekly' => [
                    ['day' => 'monday', 'start' => '22:00', 'end' => '02:00', 'closed' => false],
                ],
                'blackout_dates' => [],
                'holidays' => [],
            ],
        ]);
        $catalog = $roots->publish($catalog);
        $availability = app(CatalogAvailabilityService::class);

        $this->assertTrue($availability->isCatalogOpenForCustomers(
            $catalog,
            CarbonImmutable::parse('2026-09-21 23:00:00', 'Asia/Riyadh'),
        ));
        $this->assertTrue($availability->isCatalogOpenForCustomers(
            $catalog,
            CarbonImmutable::parse('2026-09-22 01:00:00', 'Asia/Riyadh'),
        ));
        $this->assertFalse($availability->isCatalogOpenForCustomers(
            $catalog,
            CarbonImmutable::parse('2026-09-22 03:00:00', 'Asia/Riyadh'),
        ));
        $this->assertTrue($availability->customerDiscoveryAllowed(
            $catalog,
            CarbonImmutable::parse('2026-09-22 03:00:00', 'Asia/Riyadh'),
        ));

        $catalog = $roots->updateSettings($catalog, [
            'availability_schedule' => [
                'blackout_dates' => ['2026-09-21'],
            ],
        ]);
        $this->assertFalse($availability->isCatalogOpenForCustomers(
            $catalog,
            CarbonImmutable::parse('2026-09-21 23:00:00', 'Asia/Riyadh'),
        ));
    }

    public function test_disabled_business_hours_keep_a_scheduled_catalog_open(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $roots = app(ChatCatalogRootService::class);
        $catalog = $roots->publish($roots->updateSettings($roots->forWorkspace($workspace), [
            'availability_mode' => 'scheduled',
            'availability_schedule' => [
                'enabled' => false,
                'weekly' => [['day' => 'monday', 'closed' => true]],
            ],
        ]));

        $this->assertTrue(app(CatalogAvailabilityService::class)->isCatalogOpenForCustomers(
            $catalog,
            CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Riyadh'),
        ));
    }

    public function test_scheduled_catalog_obeys_weekday_hours_blackouts_and_timezone(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $catalog = app(ChatCatalogRootService::class)->forWorkspace($workspace);
        app(ChatCatalogRootService::class)->publish($catalog);
        $catalog = app(ChatCatalogRootService::class)->updateSettings($catalog, [
            'timezone' => 'Asia/Riyadh',
            'availability_mode' => ChatCatalogAvailabilityMode::Scheduled,
            'availability_schedule' => [
                'weekly' => [
                    ['day' => 'monday', 'start' => '09:00', 'end' => '17:00', 'closed' => false],
                ],
                'holidays' => [],
                'blackout_dates' => ['2026-09-21'],
            ],
        ]);

        $service = app(CatalogAvailabilityService::class);
        $this->assertTrue($service->isCatalogOpenForCustomers(
            $catalog,
            CarbonImmutable::parse('2026-09-14 12:00:00', 'Asia/Riyadh'),
        ));
        $this->assertFalse($service->isCatalogOpenForCustomers(
            $catalog,
            CarbonImmutable::parse('2026-09-14 18:00:00', 'Asia/Riyadh'),
        ));
        $this->assertFalse($service->isCatalogOpenForCustomers(
            $catalog,
            CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Riyadh'),
        ));
    }

    public function test_paused_catalog_can_only_be_discovered_as_unavailable_when_configured(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $roots = app(ChatCatalogRootService::class);
        $catalog = $roots->publish($roots->forWorkspace($workspace));
        $roots->pause($catalog);

        $service = app(CatalogAvailabilityService::class);
        $this->assertFalse($service->customerDiscoveryAllowed($catalog));

        $catalog = $roots->updateSettings($catalog, [
            'customer_service_policy' => [
                'paused_discovery_behavior' => 'show_unavailable',
            ],
        ]);
        $this->assertTrue($service->customerDiscoveryAllowed($catalog));
        $this->assertFalse($service->isCatalogOpenForCustomers($catalog));
    }
}
