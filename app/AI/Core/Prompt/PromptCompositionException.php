<?php

declare(strict_types=1);

namespace App\AI\Core\Prompt;

use InvalidArgumentException;

final class PromptCompositionException extends InvalidArgumentException
{
    public static function invalidKey(): self
    {
        return new self('Prompt section key must be a lowercase dotted identifier.');
    }

    public static function invalidSection(PromptLayer $layer): self
    {
        return new self('Only PromptSection instances may be composed into the '.$layer->key().' layer.');
    }

    public static function layerMismatch(PromptSection $section, PromptLayer $expected): self
    {
        return new self(sprintf(
            'Section "%s" belongs to the %s layer and cannot be placed in the %s layer.',
            $section->key,
            $section->layer->key(),
            $expected->key(),
        ));
    }

    public static function duplicateKey(string $key): self
    {
        return new self('Prompt section key "'.$key.'" is used more than once.');
    }

    public static function outOfOrder(PromptSection $section): self
    {
        return new self('Section "'.$section->key.'" is out of layer order.');
    }

    public static function missingUserMessage(): self
    {
        return new self('A prompt composition requires a non-empty user message.');
    }
}
