<?php

declare(strict_types=1);

namespace App\AI\Skills;

use InvalidArgumentException;

final class SkillRegistryException extends InvalidArgumentException
{
    public static function duplicateKey(string $key): self
    {
        return new self('Duplicate skill key "'.$key.'".');
    }

    public static function invalidDefinition(string $message): self
    {
        return new self($message);
    }

    public static function unknownSkill(string $key): self
    {
        return new self('Unknown skill "'.$key.'".');
    }

    public static function disabledSkill(string $key): self
    {
        return new self('Skill "'.$key.'" is disabled.');
    }
}
