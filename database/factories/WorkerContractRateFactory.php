<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Worker\WorkerContractRate;
use App\Models\Worker\WorkerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkerContractRate>
 */
class WorkerContractRateFactory extends Factory
{
    protected $model = WorkerContractRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'worker_profile_id' => function (): int {
                $user = User::factory()->create();

                return WorkerProfile::query()->create([
                    'user_id' => $user->id,
                    'display_name' => $user->name,
                    'country_code' => WorkerProfile::COUNTRY_JORDAN,
                    'nationality' => 'Jordanian',
                    'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
                    'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
                    'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
                    'professional_summary' => 'Independent sales specialist.',
                    'sales_experience_level' => 'senior',
                ])->id;
            },
            'duration_value' => 1,
            'duration_unit' => 'day',
            'amount_minor' => 10000,
            'currency' => 'SAR',
            'effective_from' => now(),
            'effective_until' => null,
            'created_by_platform_admin_id' => null,
            'is_active' => true,
        ];
    }
}
