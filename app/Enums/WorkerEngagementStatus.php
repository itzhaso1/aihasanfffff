<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkerEngagementStatus: string
{
    case Draft = 'draft';

    case Offered = 'offered';

    case Accepted = 'accepted';

    case Rejected = 'rejected';

    case Active = 'active';

    case Paused = 'paused';

    case Completed = 'completed';

    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Offered => 'عرض مقدّم',
            self::Accepted => 'مقبول',
            self::Rejected => 'مرفوض',
            self::Active => 'نشط',
            self::Paused => 'متوقف مؤقتاً',
            self::Completed => 'مكتمل',
            self::Terminated => 'منهي',
        };
    }

    public function sessionStartBlockedMessage(): string
    {
        return match ($this) {
            self::Paused => 'الارتباط متوقف مؤقتاً، لذلك لا يمكن بدء جلسة عمل جديدة حتى يُستأنف.',
            self::Completed => 'اكتمل هذا الارتباط، لذلك لا يمكن بدء جلسة عمل جديدة.',
            self::Terminated => 'أُنهي هذا الارتباط، لذلك لا يمكن بدء جلسة عمل جديدة.',
            default => 'لا يمكن بدء جلسة جديدة قبل تفعيل الارتباط.',
        };
    }

    public function allowsCapabilityManagement(): bool
    {
        return in_array($this, [self::Accepted, self::Active, self::Paused], true);
    }

    public function allowsGuideManagement(): bool
    {
        return in_array($this, [self::Offered, self::Accepted, self::Active, self::Paused], true);
    }

    public function allowsWorkerGuideVisibility(): bool
    {
        return $this->allowsGuideManagement();
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Offered],
            self::Offered => [self::Accepted, self::Rejected],
            self::Accepted => [self::Active],
            self::Active => [self::Paused, self::Completed, self::Terminated],
            self::Paused => [self::Active, self::Completed, self::Terminated],
            self::Rejected, self::Completed, self::Terminated => [],
        }, true);
    }
}
