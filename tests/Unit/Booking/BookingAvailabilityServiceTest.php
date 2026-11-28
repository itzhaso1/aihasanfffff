<?php

declare(strict_types=1);

namespace Tests\Unit\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Booking\BookingAvailabilityService;
use App\Services\Booking\BookingSettingsService;
use App\Services\Booking\Exceptions\BookingUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private BookingSettingsService $settingsService;

    private BookingAvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_user_id' => $owner->id, 'type' => 'company']);
        $this->settingsService = app(BookingSettingsService::class);
        $this->availability = app(BookingAvailabilityService::class);
    }

    public function test_respects_working_hours_breaks_holidays_and_blocked_slots(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-01 07:00:00', 'Asia/Riyadh'));

        $this->settingsService->updateSettings($this->workspace, [
            'enabled' => true,
            'timezone' => 'Asia/Riyadh',
            'slot_interval_minutes' => 60,
            'default_duration_minutes' => 60,
            'default_capacity_per_slot' => 1,
            'min_advance_minutes' => 30,
            'max_advance_days' => 30,
            'working_hours' => [
                'tuesday' => [
                    'enabled' => true,
                    'start' => '09:00',
                    'end' => '14:00',
                    'breaks' => [['start' => '11:00', 'end' => '12:00', 'label' => 'غداء']],
                ],
                'friday' => ['enabled' => false, 'start' => '09:00', 'end' => '17:00', 'breaks' => []],
            ],
            'holidays' => [
                ['date' => '2026-12-02', 'title' => 'إجازة خاصة', 'recurring_yearly' => false],
            ],
        ]);

        // Manual block on 2026-12-01 from 12:00 to 13:00
        $this->settingsService->blockSlot($this->workspace, [
            'date' => '2026-12-01',
            'start_time' => '12:00',
            'end_time' => '13:00',
            'reason' => 'صيانة',
        ]);

        $slots = $this->availability->availableSlotsForDate($this->workspace, '2026-12-01');
        $times = array_column($slots, 'start_time');

        // 09:00, 10:00 open; 11:00 is break; 12:00 is blocked; 13:00 open.
        $this->assertSame(['09:00', '10:00', '13:00'], $times);

        // Holiday returns empty
        $this->assertSame([], $this->availability->availableSlotsForDate($this->workspace, '2026-12-02'));
        $holCheck = $this->availability->checkSlot($this->workspace, '2026-12-02', '10:00');
        $this->assertFalse($holCheck['available']);
        $this->assertSame('holiday', $holCheck['reason_code']);

        // Closed Friday returns empty
        $fridayCheck = $this->availability->checkSlot($this->workspace, '2026-12-04', '10:00');
        $this->assertFalse($fridayCheck['available']);
        $this->assertSame('day_closed', $fridayCheck['reason_code']);

        // Outside working hours
        $lateCheck = $this->availability->checkSlot($this->workspace, '2026-12-01', '20:00');
        $this->assertFalse($lateCheck['available']);
        $this->assertSame('outside_working_hours', $lateCheck['reason_code']);
    }

    public function test_enforces_capacity_providers_and_returns_nearest_alternatives(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-06 07:00:00', 'Asia/Riyadh')); // Sunday

        $this->settingsService->updateSettings($this->workspace, [
            'enabled' => true,
            'timezone' => 'Asia/Riyadh',
            'slot_interval_minutes' => 60,
            'default_duration_minutes' => 60,
            'default_capacity_per_slot' => 2,
            'min_advance_minutes' => 0,
            'working_hours' => [
                'sunday' => ['enabled' => true, 'start' => '10:00', 'end' => '13:00', 'breaks' => []],
            ],
        ]);

        $service = $this->settingsService->saveService($this->workspace, [
            'name' => 'ملعب بادل',
            'duration_minutes' => 60,
            'capacity_per_slot' => 2,
            'price' => '150.00',
            'requires_party_size' => true,
            'max_party_size' => 4,
        ]);

        $this->assertSame('150.00', $service->formattedPrice());

        $branch = $this->settingsService->saveBranch($this->workspace, ['name' => 'فرع العليا']);
        $providerA = $this->settingsService->saveProvider($this->workspace, [
            'name' => 'ملعب 1',
            'branch_id' => $branch->id,
            'capacity_per_slot' => 1,
        ]);
        $providerB = $this->settingsService->saveProvider($this->workspace, [
            'name' => 'ملعب 2',
            'branch_id' => $branch->id,
            'capacity_per_slot' => 1,
        ]);

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'فهد',
            'phone' => '+966501112233',
        ]);

        // Fill both providers at 10:00
        foreach ([$providerA, $providerB] as $idx => $prov) {
            Booking::withoutGlobalScopes()->create([
                'workspace_id' => $this->workspace->id,
                'booking_reference' => 'BK-00000'.($idx + 1),
                'customer_id' => $customer->id,
                'service_id' => $service->id,
                'branch_id' => $branch->id,
                'provider_id' => $prov->id,
                'service_name' => $service->name,
                'customer_name' => 'فهد',
                'booking_date' => '2026-12-06',
                'start_time' => '10:00',
                'end_time' => '11:00',
                'starts_at' => CarbonImmutable::parse('2026-12-06 10:00', 'Asia/Riyadh')->utc(),
                'ends_at' => CarbonImmutable::parse('2026-12-06 11:00', 'Asia/Riyadh')->utc(),
                'duration_minutes' => 60,
                'status' => BookingStatus::Confirmed->value,
            ]);
        }

        // 10:00 is now full on 2026-12-06 and must throw BookingUnavailableException with 11:00 as a real alternative
        try {
            $this->availability->assertSlotAvailable($this->workspace, '2026-12-06', '10:00', $service, $branch, partySize: 4);
            $this->fail('Expected BookingUnavailableException.');
        } catch (BookingUnavailableException $e) {
            $this->assertSame('capacity_full', $e->reasonCode);
            $sundayTimes = array_column(
                array_values(array_filter($e->alternatives, static fn (array $slot): bool => ($slot['date'] ?? '') === '2026-12-06')),
                'start_time',
            );
            $this->assertContains('11:00', $sundayTimes);
            $this->assertNotContains('10:00', $sundayTimes);
        }

        // Cancelled bookings must NOT consume capacity
        Booking::withoutGlobalScopes()->where('provider_id', $providerA->id)->update(['status' => BookingStatus::Cancelled->value]);
        $reopened = $this->availability->checkSlot($this->workspace, '2026-12-06', '10:00', $service, $branch);
        $this->assertTrue($reopened['available']);
        $this->assertSame($providerA->id, $reopened['resolved_provider_id']);
    }
}
