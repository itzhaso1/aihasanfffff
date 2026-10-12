<?php

declare(strict_types=1);

namespace App\AI\Employees;

use RuntimeException;

final class EmployeeOperationException extends RuntimeException
{
    public static function defaultCannotBeDeleted(): self
    {
        return new self('The default employee cannot be deleted. Set another default employee first.');
    }

    public static function inactiveCannotBeDefault(): self
    {
        return new self('An inactive employee cannot be the default employee.');
    }

    public static function unknownTrainingProfile(string $profile): self
    {
        return new self('Training profile "'.$profile.'" does not exist.');
    }

    public static function unknownPreset(string $key): self
    {
        return new self('Employee preset "'.$key.'" does not exist.');
    }
}
