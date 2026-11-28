<?php

declare(strict_types=1);

namespace App\Services\Customer\Exceptions;

use RuntimeException;

final class AmbiguousOrderSelectionException extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    public function __construct(
        public readonly array $candidates,
        string $message = 'يوجد أكثر من طلب نشط لهذا العميل. يرجى تحديد رقم الطلب المقصود بدقة.',
    ) {
        parent::__construct($message);
    }
}
