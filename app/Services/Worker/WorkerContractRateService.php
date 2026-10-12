<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerEngagementDurationUnit;
use App\Models\PlatformAdmin;
use App\Models\Worker\WorkerContractRate;
use App\Models\Worker\WorkerProfile;
use App\Support\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class WorkerContractRateService
{
    public function resolveForOffer(
        WorkerProfile $workerProfile,
        int $durationValue,
        string $durationUnit,
        ?CarbonInterface $at = null,
    ): WorkerContractRate {
        $unit = WorkerEngagementDurationUnit::tryFrom($durationUnit);
        if ($durationValue < 1 || $unit === null) {
            throw WorkerEngagementException::contractRateUnavailable();
        }

        $at ??= now();

        $rate = WorkerContractRate::query()
            ->where('worker_profile_id', $workerProfile->id)
            ->where('duration_value', $durationValue)
            ->where('duration_unit', $unit->value)
            ->where('is_active', true)
            ->where(function ($query) use ($at): void {
                $query
                    ->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $at);
            })
            ->where(function ($query) use ($at): void {
                $query
                    ->whereNull('effective_until')
                    ->orWhere('effective_until', '>', $at);
            })
            ->latest('effective_from')
            ->latest('id')
            ->first();

        if ($rate === null) {
            throw WorkerEngagementException::contractRateUnavailable();
        }

        return $rate;
    }

    public function createVersion(
        PlatformAdmin $admin,
        WorkerProfile $workerProfile,
        int $durationValue,
        string $durationUnit,
        string|int|float $amount,
        string $currency,
        ?CarbonInterface $effectiveFrom = null,
    ): WorkerContractRate {
        $unit = WorkerEngagementDurationUnit::tryFrom($durationUnit);
        if ($durationValue < 1 || $unit === null) {
            throw new InvalidArgumentException('مدة سعر التعاقد غير صالحة.');
        }

        $amountMinor = Money::minor($amount);
        if ($amountMinor < 0) {
            throw new InvalidArgumentException('سعر التعاقد لا يمكن أن يكون سالباً.');
        }

        $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('عملة سعر التعاقد غير صالحة.');
        }

        $effectiveFrom ??= now();

        return DB::transaction(function () use (
            $admin,
            $workerProfile,
            $durationValue,
            $unit,
            $amountMinor,
            $currency,
            $effectiveFrom,
        ): WorkerContractRate {
            WorkerContractRate::query()
                ->where('worker_profile_id', $workerProfile->id)
                ->where('duration_value', $durationValue)
                ->where('duration_unit', $unit->value)
                ->where('is_active', true)
                ->where(function ($query) use ($effectiveFrom): void {
                    $query
                        ->whereNull('effective_until')
                        ->orWhere('effective_until', '>', $effectiveFrom);
                })
                ->update([
                    'is_active' => false,
                    'effective_until' => $effectiveFrom,
                ]);

            return WorkerContractRate::query()->create([
                'worker_profile_id' => $workerProfile->id,
                'duration_value' => $durationValue,
                'duration_unit' => $unit->value,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'effective_from' => $effectiveFrom,
                'created_by_platform_admin_id' => $admin->id,
                'is_active' => true,
            ]);
        });
    }

    public function disable(PlatformAdmin $admin, WorkerContractRate $rate): WorkerContractRate
    {
        $rate->forceFill([
            'is_active' => false,
            'effective_until' => now(),
        ])->save();

        return $rate->refresh();
    }
}
