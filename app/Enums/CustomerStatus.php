<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerStatus: string
{
    case NeedsReview = 'needs_review';
    case Completed = 'completed';
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case InProgress = 'in_progress';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::NeedsReview => 'يحتاج إلى مراجعة',
            self::Completed => 'عميل مكتمل',
            self::AwaitingPayment => 'بانتظار الدفع',
            self::Paid => 'تم الدفع',
            self::InProgress => 'قيد التنفيذ',
            self::Delivered => 'مكتمل التسليم',
            self::Cancelled => 'ملغي',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::NeedsReview => 'bg-amber-100 text-amber-800 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-800',
            self::Completed => 'bg-emerald-100 text-emerald-800 border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:border-emerald-800',
            self::AwaitingPayment => 'bg-blue-100 text-blue-800 border-blue-200 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-800',
            self::Paid => 'bg-teal-100 text-teal-800 border-teal-200 dark:bg-teal-900/30 dark:text-teal-300 dark:border-teal-800',
            self::InProgress => 'bg-indigo-100 text-indigo-800 border-indigo-200 dark:bg-indigo-900/30 dark:text-indigo-300 dark:border-indigo-800',
            self::Delivered => 'bg-green-100 text-green-800 border-green-200 dark:bg-green-900/30 dark:text-green-300 dark:border-green-800',
            self::Cancelled => 'bg-rose-100 text-rose-800 border-rose-200 dark:bg-rose-900/30 dark:text-rose-300 dark:border-rose-800',
        };
    }

    public function dotClass(): string
    {
        return match ($this) {
            self::NeedsReview => 'bg-amber-500',
            self::Completed => 'bg-emerald-500',
            self::AwaitingPayment => 'bg-blue-500',
            self::Paid => 'bg-teal-500',
            self::InProgress => 'bg-indigo-500',
            self::Delivered => 'bg-green-500',
            self::Cancelled => 'bg-rose-500',
        };
    }
}
