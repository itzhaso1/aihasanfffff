<?php

declare(strict_types=1);

namespace App\AI\Employees;

use InvalidArgumentException;

final class InvalidEmployeeData extends InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function field(string $field, string $message): self
    {
        return new self($field, $message);
    }
}
