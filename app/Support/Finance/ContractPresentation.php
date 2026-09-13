<?php

namespace App\Support\Finance;

use App\Models\Contract\Contract;
use Illuminate\Support\Carbon;

final class ContractPresentation
{
    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            'draft' => 'مسودة',
            'open' => 'ساري',
            'closed' => 'مغلق',
            'cancelled' => 'ملغي',
        ];
    }

    public static function statusLabel(string $status): string
    {
        return self::statusLabels()[$status] ?? $status;
    }

    public static function statusBadgeClass(string $status): string
    {
        return match ($status) {
            'draft' => 'bg-slate-100 text-slate-600',
            'open' => 'bg-emerald-50 text-emerald-700',
            'closed' => 'bg-slate-200 text-slate-600',
            'cancelled' => 'bg-rose-50 text-rose-700',
            default => 'bg-slate-100 text-slate-700',
        };
    }

    /**
     * @return array{days:int|null,label:string,severity:string}
     */
    public static function expiry(Contract $contract): array
    {
        if (! $contract->end_date) {
            return ['days' => null, 'label' => 'بدون تاريخ نهاية', 'severity' => 'none'];
        }

        if (in_array($contract->status, ['closed', 'cancelled'], true)) {
            return ['days' => null, 'label' => self::statusLabel($contract->status), 'severity' => 'none'];
        }

        $days = (int) Carbon::today()->diffInDays($contract->end_date, false);

        if ($days < 0) {
            return ['days' => $days, 'label' => 'انتهى منذ '.abs($days).' يوم', 'severity' => 'critical'];
        }

        if ($days === 0) {
            return ['days' => 0, 'label' => 'ينتهي اليوم', 'severity' => 'critical'];
        }

        if ($days <= 14) {
            return ['days' => $days, 'label' => 'ينتهي خلال '.$days.' يوم', 'severity' => 'high'];
        }

        if ($days <= 30) {
            return ['days' => $days, 'label' => 'ينتهي خلال '.$days.' يوم', 'severity' => 'medium'];
        }

        return ['days' => $days, 'label' => 'متبقٍ '.$days.' يوم', 'severity' => 'ok'];
    }

    public static function expiryBadgeClass(string $severity): string
    {
        return match ($severity) {
            'critical' => 'bg-rose-50 text-rose-700',
            'high' => 'bg-orange-50 text-orange-700',
            'medium' => 'bg-amber-50 text-amber-700',
            'ok' => 'bg-emerald-50 text-emerald-700',
            default => 'bg-slate-100 text-slate-500',
        };
    }
}
