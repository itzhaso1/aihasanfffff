<?php

declare(strict_types=1);

namespace App\Services\Location\Exceptions;

use App\Models\CustomerLocation;
use RuntimeException;

final class LocationCaptureException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $statusCode = 400,
        public readonly ?CustomerLocation $existingLocation = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('رابط تحديد الموقع غير صالح أو غير موجود.', 'session_not_found', 404);
    }

    public static function expired(): self
    {
        return new self('انتهت صلاحية رابط تحديد الموقع. يرجى طلب رابط جديد في المحادثة.', 'session_expired', 410);
    }

    public static function revoked(): self
    {
        return new self('تم إلغاء هذا الرابط لأن رابطاً أحدث تم إصداره.', 'session_revoked', 410);
    }

    public static function alreadyConfirmed(?CustomerLocation $location = null): self
    {
        return new self('تم تأكيد هذا الموقع مسبقاً وإقفال الرابط.', 'session_locked', 409, $location);
    }

    public static function rateLimited(): self
    {
        return new self('تم تجاوز الحد المسموح لتحديثات الموقع. يرجى المحاولة لاحقاً.', 'rate_limited', 429);
    }
}
